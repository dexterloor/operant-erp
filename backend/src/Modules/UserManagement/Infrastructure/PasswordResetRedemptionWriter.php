<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

use Doctrine\DBAL\Connection;

/**
 * **Mail-C2 step 7a and 7b — the redeem's two writes, on `erp_app`, inside the caller's `TenantContext::run()`**
 * (`docs/session13/mail-c2-build-plan.md` §4.4, R1.1). Raw DBAL because `GREATEST` and the guard predicates cannot go through
 * an ORM flush. Every instant is the caller's `$at` (read inside the transaction), bound as whole seconds.
 *
 *  - **7a** marks used **every** unused, unexpired link of that person, either purpose, and returns their ids: one redeem
 *    ends every other link (T-R12). It takes the row locks first. A second redeem of the same (or another) link of that
 *    person waits on them and, under READ COMMITTED, re-evaluates `used_at IS NULL` on the committed row and gets an empty
 *    set (T-R5a/T-R5b).
 *  - **7b** sets the hash, its seal and the session floor, guarded so it can only act on a live, active person whose floor
 *    is still older than the link (owner Q-3), and the floor never moves backwards: `GREATEST` ignores NULL, so a NULL floor
 *    becomes `$at` and a later one is kept (the G4 amendment; `AppUser::raiseSessionStamp`'s rule in SQL). For an
 *    `invitation` link it also requires the person still to have no credential (`password_mac IS NULL`, bound from the
 *    row's purpose — U-C1a): a credential set while the invitation's KDF ran is never overwritten (T-U9, MU-6).
 *
 * The caller decides what an outcome means (membership of the presented id; exactly one row) and rolls back.
 */
final readonly class PasswordResetRedemptionWriter
{
    private const CONSUME = <<<'SQL'
        UPDATE password_reset_token SET used_at = ?::timestamptz
         WHERE tenant_id = ?::uuid AND user_id = ?::uuid AND used_at IS NULL AND expires_at > ?::timestamptz
        RETURNING id
        SQL;

    private const REPLACE = <<<'SQL'
        UPDATE app_user SET password_hash = ?, password_mac = ?,
               sessions_valid_after = GREATEST(sessions_valid_after, ?::timestamptz), updated_at = ?::timestamptz
         WHERE tenant_id = ?::uuid AND id = ?::uuid AND is_active AND deleted_at IS NULL
           AND (sessions_valid_after IS NULL OR sessions_valid_after < ?::timestamptz)
           AND (?::text <> 'invitation' OR password_mac IS NULL)
        SQL;

    public function __construct(private Connection $app)
    {
    }

    /** @return list<string> the ids of the links this statement marked used */
    public function consumeLinks(string $tenantId, string $userId, \DateTimeImmutable $at): array
    {
        $this->requireTransaction();
        $stamp = self::seconds($at);

        return array_map('strval', $this->app->fetchFirstColumn(self::CONSUME, [$stamp, $tenantId, $userId, $stamp]));
    }

    /** @return int the rows changed (the caller requires exactly 1) */
    public function replaceCredential(string $tenantId, string $userId, string $hash, string $mac, \DateTimeImmutable $at, int $linkCreatedUnix, string $purpose): int
    {
        $this->requireTransaction();
        $stamp = self::seconds($at);

        return (int) $this->app->executeStatement(self::REPLACE, [
            $hash, $mac, $stamp, $stamp, $tenantId, $userId, self::seconds(new \DateTimeImmutable('@'.$linkCreatedUnix)), $purpose,
        ]);
    }

    /** Whole seconds, truncating, never rounding up — the floor algebra of R1.1 depends on it (`AppUser`'s ORM writes match). */
    private static function seconds(\DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:sP');
    }

    private function requireTransaction(): void
    {
        if (0 === $this->app->getTransactionNestingLevel()) {
            throw new \LogicException('PasswordResetRedemptionWriter runs inside the redeem\'s one erp_app transaction.');
        }
    }
}
