<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\PasswordResetRedemptionAuditWriter;
use App\Modules\UserManagement\Domain\PreAuthKeys;
use App\Modules\UserManagement\Domain\SealCheck;
use App\Modules\UserManagement\Domain\SealsCredentials;
use App\Modules\UserManagement\Domain\SealsIdentities;
use App\Modules\UserManagement\Domain\VerifiesPasswords;
use App\Modules\UserManagement\Infrastructure\EmailHasher;
use App\Modules\UserManagement\Infrastructure\LockoutStore;
use App\Modules\UserManagement\Infrastructure\PasswordResetRedemptionWriter;
use App\Modules\UserManagement\Infrastructure\PasswordResetTokenLookup;
use App\Modules\UserManagement\Infrastructure\PreAuthBudgets;
use App\Platform\Tenancy\Entity\Tenant;
use App\Platform\Tenancy\SchemaManifest;
use App\Platform\Tenancy\TenantContext;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **Mail-C2 `CreatePasswordResetRedemption` — a mailed link sets the password once and ends every older session**
 * (`docs/session13/mail-c2-build-plan.md` Revision 1 FINAL; final RED H-1, L-1; owner rulings `mail-c2-ruling.md`).
 *
 * ===========================================================================
 * The sequence, and which principal issues what
 * ===========================================================================
 *  1  `$now`; the IP budget (sign-in's, shared; D-M3)             — **erp_ratelimit**
 *  2  decode the token; anything malformed → the sentinel tenant  — no statement
 *  3  BEGIN · set_config · one SELECT · COMMIT                    — **erp_auth**, identical on every path
 *  4  the checks (a)–(h), all computed on every path              — no statement
 *  5  E-24 on the new password → 422 (only after a good link)     — no statement
 *  6  KDF + Cred-C1 seal, outside every transaction               — no statement
 *  7  one transaction: `$at`, 7a consume links, 7b credential +   — **erp_app**
 *     floor, 7c `auth.password.reset`
 *  8  clear the sign-in pause for the pair (D-M8), after COMMIT   — **erp_ratelimit**
 *  9  200 `{changed_at}` (API-21 branch 5)
 *
 * **Nothing touches `erp_app` before step 4 passes** (T-R3). Every refusal at steps 2–4 and every lost race at step 7 is
 * `Refused`, rendered as the one constant `authentication-failed`; the verifier MAC (a) and the identity seal (g) are
 * computed exactly once on every path, against a fixed dummy tuple on a miss (T-R16).
 *
 * ===========================================================================
 * The two instants (H-1, L-1, RC-5)
 * ===========================================================================
 * `$now` (step 1) serves only the IP budget and the expiry check (b). **`$at` is read as the first statement of the step-7
 * callback** — after `BEGIN` and `set_config`, so once the application's single pooled server connection is held — and
 * floored at `$now` (a host clock stepping back during the KDF must not lower the floor; `AppUser::raiseSessionStamp`'s
 * fail-closed rule). It is bound, as whole seconds, in 7a (`used_at`, `expires_at >`), 7b (the floor, `updated_at`) and
 * returned as `changed_at`. A sign-in token survives only if `iat > floor`, and `iat` is the sign-in's START; so every
 * sign-in that started before `$at`, including one made with the old password while the new one was being hashed or while
 * the save waited for the pooled connection, is refused on its next request. **Residual:** a sign-in that starts in a later
 * second than `$at` and reads the credential before 7b commits keeps its session; the window is 7a+7b+7c+COMMIT
 * (milliseconds) and contains neither the KDF nor the wait for the pooled connection. A frozen mock clock cannot tell
 * "inside the callback" from "just before `TenantContext::run`" (RC-5, a review check); T-R6b proves only "after the KDF".
 *
 * ===========================================================================
 * What is and is not a channel
 * ===========================================================================
 * The KDF runs only on a verified link, so the timing separates valid from invalid — which the status already does, and
 * only for someone holding the secret; no pad (the SignIn ruling). A strict base64url decode accepts non-zero trailing bits,
 * so several 86-character spellings decode to one token; harmless, the verifier must still match. One operator `warning`
 * is written only when the link verified and the identity seal names another key id (a key rotation); it carries no token
 * and no email. A known selector with a wrong verifier is NOT recorded (Q-4, `SEC`'s call; a residual).
 *
 * The lockout key is `EmailHasher::hash(PreAuthKeys::email($storedEmail))` with `PreAuthKeys::slug($tenantSlug)` — the same
 * PHP normalisation sign-in applies, so a Postgres `lower()` differing from `mb_strtolower()` cannot make the clear a no-op.
 */
final readonly class PasswordResetRedemption
{
    /** U-C1 adds `'invitation'` deliberately, with its own rule (F-9). */
    public const REDEEMABLE_PURPOSES = ['reset'];

    public const TOKEN_LENGTH = 86;

    private const DUMMY_VERIFIER_MAC = 'v1.00000000.0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private PreAuthBudgets $budgets,
        private PasswordResetTokenLookup $tokens,
        private SealsIdentities $identities,
        private SealsCredentials $seals,
        private VerifiesPasswords $passwords,
        private TenantContext $tenants,
        private PasswordResetRedemptionWriter $writer,
        private PasswordResetRedemptionAuditWriter $trail,
        private LockoutStore $lockouts,
        private EmailHasher $emailHasher,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function redeem(PasswordResetRedemptionCommand $command): PasswordResetRedemptionResult
    {
        // --- 1 ------------------------------------------------------------
        $now = $this->clock->now();
        if (!$this->budgets->consumeIpBudget(PreAuthKeys::ipPrefix($command->ipAddress), $now)) {
            return PasswordResetRedemptionResult::throttled();
        }

        // --- 2 ------------------------------------------------------------
        [$tenantId, $selector, $verifier] = self::decode($command->token);

        // --- 3 ------------------------------------------------------------
        $row = $this->tokens->find($tenantId, $selector);

        // --- 4 ------------------------------------------------------------
        $hit = null !== $row;
        $macOk = $this->identities->verifierMatches(
            $hit ? $row->tenantId : SealsCredentials::DUMMY_TENANT_ID,
            $hit ? $row->userId : SealsCredentials::DUMMY_USER_ID,
            $hit ? $row->purpose : self::REDEEMABLE_PURPOSES[0],
            $selector,
            $hit ? $row->expiresUnix : 0,
            $verifier,
            $hit ? $row->verifierMac : self::DUMMY_VERIFIER_MAC,
        );
        $identity = $this->identities->checkIdentity(
            $hit ? $row->tenantId : SealsCredentials::DUMMY_TENANT_ID,
            $hit ? $row->userId : SealsCredentials::DUMMY_USER_ID,
            $hit ? $row->storedEmail : '',
            $hit ? $row->identityMac : null,
        );

        $valid = $hit
            && $macOk
            && $row->expiresUnix > $now->getTimestamp()
            && !$row->used
            && \in_array($row->purpose, self::REDEEMABLE_PURPOSES, true)
            && $row->userIsActive
            && Tenant::statusPermitsLogin($row->tenantStatus)
            && SealCheck::Verified === $identity
            // Owner Q-3: a link minted at or before the person's latest session floor is dead.
            && (null === $row->sessionFloorEpoch || $row->createdUnix > $row->sessionFloorEpoch);

        if ($hit && $macOk && SealCheck::UnknownKey === $identity) {
            $this->logger->warning('A password reset link was refused: the account\'s identity seal names a key id that is not the configured one. Run app:credentials:check-key.', [
                'tenant_id' => $row->tenantId,
                'configured_key_id' => $this->identities->keyId(),
            ]);
        }

        if (!$valid) {
            return PasswordResetRedemptionResult::refused();
        }
        \assert(null !== $row);

        // --- 5 ------------------------------------------------------------
        $errors = PasswordRules::errors($command->password, '/password');
        if ([] !== $errors) {
            return PasswordResetRedemptionResult::invalid($errors);
        }

        // --- 6 ------------------------------------------------------------
        $hash = $this->passwords->hash($command->password);
        $mac = $this->seals->seal($row->tenantId, $row->userId, $hash);

        // --- 7 ------------------------------------------------------------
        try {
            $at = $this->tenants->run($row->tenantId, function () use ($row, $hash, $mac, $now, $command): \DateTimeImmutable {
                // H-1 / L-1: the first statement inside the held transaction, floored at $now, whole seconds.
                $clock = $this->clock->now();
                $at = new \DateTimeImmutable('@'.($clock < $now ? $now : $clock)->getTimestamp());

                // 7a — every unused, unexpired link of this person; the presented one must be among them.
                if (!\in_array($row->id, $this->writer->consumeLinks($row->tenantId, $row->userId, $at), true)) {
                    throw new PasswordResetRedemptionLost();
                }

                // 7b — exactly one live, active person whose floor is still older than the link.
                if (1 !== $this->writer->replaceCredential($row->tenantId, $row->userId, $hash, $mac, $at, $row->createdUnix)) {
                    throw new PasswordResetRedemptionLost();
                }

                // 7c — inside the transaction (§9.2): its failure rolls the change back.
                $this->trail->record($row->tenantId, $row->userId, $row->storedEmail, $command->ipAddress);

                return $at;
            });
        } catch (PasswordResetRedemptionLost) {
            return PasswordResetRedemptionResult::refused();
        }
        \assert($at instanceof \DateTimeImmutable);

        // --- 8 ------------------------------------------------------------
        // D-M8: a redeemed link lifts the sign-in pause for the pair. After COMMIT, and a failure here does not undo a
        // password that has moved: a 500 would send the person back to a now-used link.
        try {
            $this->lockouts->clear(PreAuthKeys::slug($row->tenantSlug), $this->emailHasher->hash(PreAuthKeys::email($row->storedEmail)));
        } catch (\Throwable $e) {
            $this->logger->warning('A redeemed password reset could not lift the sign-in pause; it expires on its own.', [
                'tenant_id' => $row->tenantId,
                'exception_class' => $e::class,
            ]);
        }

        // --- 9 ------------------------------------------------------------
        return PasswordResetRedemptionResult::changed($at);
    }

    /**
     * Step 2. `base64url(tenant uuid bytes(16) ‖ selector(16) ‖ verifier(32))`, 86 characters, no padding. Anything else —
     * and a tenant prefix that is not an RFC uuid, which `TenantContext::establishOn()` would refuse with a 500 (R1.8) —
     * becomes the sentinel tenant with zero selector and verifier, so step 3's statements are identical.
     *
     * @return array{0: string, 1: string, 2: string} tenant id, selector hex (32), verifier hex (64)
     */
    private static function decode(string $token): array
    {
        $sentinel = [SchemaManifest::SENTINEL_TENANT_ID, str_repeat('0', 32), str_repeat('0', 64)];

        if (self::TOKEN_LENGTH !== \strlen($token) || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $token)) {
            return $sentinel;
        }

        $raw = base64_decode(strtr($token, '-_', '+/').'==', true);
        if (!\is_string($raw) || 64 !== \strlen($raw)) {
            return $sentinel;
        }

        try {
            $tenantId = Uuid::fromBinary(substr($raw, 0, 16))->toRfc4122();
        } catch (\Throwable) {
            return $sentinel;
        }
        if (!Uuid::isValid($tenantId)) {
            return $sentinel;
        }

        return [$tenantId, bin2hex(substr($raw, 16, 16)), bin2hex(substr($raw, 32, 32))];
    }
}
