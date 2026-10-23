<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

use App\Modules\UserManagement\Domain\SealCheck;
use App\Modules\UserManagement\Domain\SealsCredentials;
use Symfony\Component\Uid\Uuid;

/**
 * **`password_mac` — the keyed seal over a stored credential** (Cred-C1, "Seal every password, and refuse one that was
 * copied"; `docs/session13/cred-mail-user-design.md` §3.1 + Revision 1 FINAL R1.3; final RED M-1, L-8, L-11).
 *
 * `password_mac = "v1." . kid8 . "." . hex(HMAC-SHA256(k_cred, "v1.cred\0" . tenant_id . "\0" . user_id . "\0" . password_hash))`
 *
 * - **One env secret**, `AUTH_CREDENTIAL_MAC_KEY`: base64 of at least 32 random bytes; the constructor refuses a shorter
 *   or non-base64 value and never repeats it in the message (Standing Rule 10). Bound once, by name, in `services.yaml`.
 * - **`k_cred = HKDF-SHA256(root, 32, "erp.credential.v1")`.** The identity and reset-token subkeys the design names
 *   (`erp.identity.v1`, `erp.reset-token.v1`) are derived by the commits that first use them (Mail-C1/C2), not here.
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
final readonly class CredentialSeal implements SealsCredentials
{
    public const VERSION = 'v1';

    public const MINIMUM_KEY_BYTES = 32;

    private const CREDENTIAL_INFO = 'erp.credential.v1';

    private const KEY_ID_INFO = 'erp.kid.v1';

    private const SHAPE = '/^v1\.([0-9a-f]{8})\.[0-9a-f]{64}$/';

    private string $credentialKey;

    private string $keyId;

    public function __construct(string $key)
    {
        $root = base64_decode($key, true);
        if (false === $root || \strlen($root) < self::MINIMUM_KEY_BYTES) {
            throw new \InvalidArgumentException(\sprintf(
                'AUTH_CREDENTIAL_MAC_KEY must be base64 of at least %d bytes. Below that the seal over every stored '
                .'password is weaker than the hashes it protects.',
                self::MINIMUM_KEY_BYTES,
            ));
        }

        $this->credentialKey = hash_hkdf('sha256', $root, 32, self::CREDENTIAL_INFO);
        $this->keyId = substr(hash_hmac('sha256', self::KEY_ID_INFO, $root), 0, 8);
    }

    public function seal(string $tenantId, string $userId, string $passwordHash): string
    {
        return self::VERSION.'.'.$this->keyId.'.'.$this->mac($tenantId, $userId, $passwordHash);
    }

    public function check(string $tenantId, string $userId, string $passwordHash, ?string $stored): SealCheck
    {
        // Computed before anything about $stored is inspected: the cost is the same on every path.
        $expected = $this->seal($tenantId, $userId, $passwordHash);

        if (hash_equals($expected, $stored ?? '')) {
            return SealCheck::Verified;
        }

        if (null !== $stored && 1 === preg_match(self::SHAPE, $stored, $m) && $m[1] !== $this->keyId) {
            return SealCheck::UnknownKey;
        }

        return SealCheck::Mismatch;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    private function mac(string $tenantId, string $userId, string $passwordHash): string
    {
        $message = self::VERSION.".cred\0".Uuid::fromString($tenantId)->toRfc4122()."\0".Uuid::fromString($userId)->toRfc4122()."\0".$passwordHash;

        return hash_hmac('sha256', $message, $this->credentialKey);
    }
}
