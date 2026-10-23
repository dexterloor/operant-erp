<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

use App\Modules\UserManagement\Domain\SealCheck;
use App\Modules\UserManagement\Domain\SealsCredentials;
use App\Modules\UserManagement\Domain\SealsIdentities;
use Symfony\Component\Uid\Uuid;

/**
 * **`password_mac` — the keyed seal over a stored credential** (Cred-C1, "Seal every password, and refuse one that was
 * copied"; `docs/session13/cred-mail-user-design.md` §3.1 + Revision 1 FINAL R1.3; final RED M-1, L-8, L-11).
 *
 * `password_mac = "v1." . kid8 . "." . hex(HMAC-SHA256(k_cred, "v1.cred\0" . tenant_id . "\0" . user_id . "\0" . password_hash))`
 *
 * - **One env secret**, `AUTH_CREDENTIAL_MAC_KEY`: base64 of at least 32 random bytes; the constructor refuses a shorter
 *   or non-base64 value and never repeats it in the message (Standing Rule 10). Bound once, by name, in `services.yaml`.
 * - **`k_cred = HKDF-SHA256(root, 32, "erp.credential.v1")`.** Mail-C1 (D-3, owed from Cred-C1) adds
 *   **`k_ident = HKDF(root, 32, "erp.identity.v1")`** (`identity_mac` over `(tenant, user, email)`) and
 *   **`k_tok = HKDF(root, 32, "erp.reset-token.v1")`** (`verifier_mac` over a reset token), both {@see SealsIdentities}.
 *   Three purposes, one root, and a value under one never verifies under another (the cross-purpose vector).
 * - **The key id is enforced (G-75, T-G75-2; Mail-C1):** given `$declaredKeyId` (`AUTH_CREDENTIAL_MAC_KEY_ID`), the
 *   constructor refuses a key whose id differs; with `$requireDeclaredKeyId` (production, {@see self::configured()}) it
 *   refuses an absent id too. One-argument construction stays valid — the shipped Cred-C1 backfill
 *   (`Version20261023000600::sealAll()`) builds it that way and is not edited (owner Q-A, default (a)).
 * - **`kid8`** = the first 8 hex characters of `HMAC-SHA256(root, "erp.kid.v1")`: which key sealed a row, so a wrong key on
 *   a server is visible (`app:credentials:check-key`) and a re-seal is resumable. Not a secret.
 * - **The uuids are canonical lowercase**, and `\0` cannot occur in a uuid or in a PHC hash string, so the encoding is
 *   unambiguous (`CredentialSealTest` pins fixed vectors).
 * - **The input is the stored hash, not the password**, so a new key can re-seal every row without any plaintext. The
 *   command that would do it is not built (`RESIDUAL_GAPS.md`, key custody); when it is, it takes
 *   `AUTH_CREDENTIAL_MAC_KEY_PREVIOUS` as well, one more `ServingCredentialGuard::ALLOWED_NAMES` row then (final RED L-11).
 *
 * **What it closes and what it leaves (G-12):** a copied or transplanted `password_hash` no longer signs in, because SQL as
 * `erp_app` cannot compute a seal. It leaves a rollback to a saved `(hash, mac)` pair whose old password the attacker
 * knows, offline cracking of a hash read through `erp_app`, and lockout (writing a wrong seal refuses the user, as writing
 * a wrong hash already could).
 */
final readonly class CredentialSeal implements SealsCredentials, SealsIdentities
{
    public const VERSION = 'v1';

    public const MINIMUM_KEY_BYTES = 32;

    private const CREDENTIAL_INFO = 'erp.credential.v1';

    private const IDENTITY_INFO = 'erp.identity.v1';

    private const RESET_TOKEN_INFO = 'erp.reset-token.v1';

    private const KEY_ID_INFO = 'erp.kid.v1';

    /** The env variable `$declaredKeyId` is read from; named in refusals, never its value's neighbour, the key. */
    public const KEY_ID_NAME = 'AUTH_CREDENTIAL_MAC_KEY_ID';

    private const SHAPE = '/^v1\.([0-9a-f]{8})\.[0-9a-f]{64}$/';

    private string $credentialKey;

    private string $identityKey;

    private string $tokenKey;

    private string $keyId;

    public function __construct(string $key, ?string $declaredKeyId = null, bool $requireDeclaredKeyId = false)
    {
        $root = base64_decode($key, true);
        if (false === $root || \strlen($root) < self::MINIMUM_KEY_BYTES) {
            throw new \InvalidArgumentException(\sprintf(
                'AUTH_CREDENTIAL_MAC_KEY must be base64 of at least %d bytes. Below that the seal over every stored '
                .'password is weaker than the hashes it protects.',
                self::MINIMUM_KEY_BYTES,
            ));
        }

        // One derivation call site, three purposes (CredentialSealTest pins the purpose list and the single call site).
        [$this->credentialKey, $this->identityKey, $this->tokenKey] = array_map(
            static fn (string $info): string => hash_hkdf('sha256', $root, 32, $info),
            [self::CREDENTIAL_INFO, self::IDENTITY_INFO, self::RESET_TOKEN_INFO],
        );
        $this->keyId = substr(hash_hmac('sha256', self::KEY_ID_INFO, $root), 0, 8);

        if (null === $declaredKeyId || '' === $declaredKeyId) {
            if ($requireDeclaredKeyId) {
                throw new CredentialKeyIdRefused(\sprintf(
                    'Production must set %s to the id `app:credentials:check-key` prints for AUTH_CREDENTIAL_MAC_KEY: without '
                    .'it a wrong key would be found only by every sign-in failing.', self::KEY_ID_NAME));
            }
        } elseif (!hash_equals($this->keyId, $declaredKeyId)) {
            // The configured id may be printed (an HMAC of a constant, not a secret); the key never is.
            throw new CredentialKeyIdRefused(\sprintf(
                '%s is "%s", but AUTH_CREDENTIAL_MAC_KEY has a different id: this is not the key the stored seals were made with.',
                self::KEY_ID_NAME, preg_replace('/[^0-9a-f]/', '?', substr($declaredKeyId, 0, 8))));
        }
    }

    /**
     * The container's construction (Mail-C1, M-7): the declared id from `AUTH_CREDENTIAL_MAC_KEY_ID` (empty when unset),
     * required when the kernel environment is `prod`. Bound for THIS service only, never by a global `bind:`, so the
     * PREVIOUS seal (`PreviousSealFactory`) never inherits the production requirement.
     */
    public static function configured(string $key, ?string $declaredKeyId, string $environment): self
    {
        return new self($key, $declaredKeyId, 'prod' === $environment);
    }

    public function seal(string $tenantId, string $userId, string $passwordHash): string
    {
        return self::VERSION.'.'.$this->keyId.'.'.$this->mac($tenantId, $userId, $passwordHash);
    }

    public function check(string $tenantId, string $userId, string $passwordHash, ?string $stored): SealCheck
    {
        // Computed before anything about $stored is inspected: the cost is the same on every path.
        $expected = $this->seal($tenantId, $userId, $passwordHash);

        return $this->compare($expected, $stored);
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function sealIdentity(string $tenantId, string $userId, string $email): string
    {
        $message = self::VERSION.".ident\0".Uuid::fromString($tenantId)->toRfc4122()."\0".Uuid::fromString($userId)->toRfc4122()."\0".$email;

        return self::VERSION.'.'.$this->keyId.'.'.hash_hmac('sha256', $message, $this->identityKey);
    }

    public function checkIdentity(string $tenantId, string $userId, string $email, ?string $stored): SealCheck
    {
        $expected = $this->sealIdentity($tenantId, $userId, $email);

        return $this->compare($expected, $stored);
    }

    public function macVerifier(string $tenantId, string $userId, string $purpose, string $selector, int $expiresAtUnixSeconds, string $verifierHex): string
    {
        $message = self::VERSION.".tok\0".Uuid::fromString($tenantId)->toRfc4122()."\0".Uuid::fromString($userId)->toRfc4122()
            ."\0".$purpose."\0".$selector."\0".$expiresAtUnixSeconds."\0".$verifierHex;

        return self::VERSION.'.'.$this->keyId.'.'.hash_hmac('sha256', $message, $this->tokenKey);
    }

    public function verifierMatches(string $tenantId, string $userId, string $purpose, string $selector, int $expiresAtUnixSeconds, string $verifierHex, string $stored): bool
    {
        return hash_equals($this->macVerifier($tenantId, $userId, $purpose, $selector, $expiresAtUnixSeconds, $verifierHex), $stored);
    }

    /** Fail-closed comparison shared by both seals: only an exact match verifies; a well-formed foreign key id is told apart. */
    private function compare(string $expected, ?string $stored): SealCheck
    {
        if (hash_equals($expected, $stored ?? '')) {
            return SealCheck::Verified;
        }

        if (null !== $stored && 1 === preg_match(self::SHAPE, $stored, $m) && $m[1] !== $this->keyId) {
            return SealCheck::UnknownKey;
        }

        return SealCheck::Mismatch;
    }

    private function mac(string $tenantId, string $userId, string $passwordHash): string
    {
        $message = self::VERSION.".cred\0".Uuid::fromString($tenantId)->toRfc4122()."\0".Uuid::fromString($userId)->toRfc4122()."\0".$passwordHash;

        return hash_hmac('sha256', $message, $this->credentialKey);
    }
}
