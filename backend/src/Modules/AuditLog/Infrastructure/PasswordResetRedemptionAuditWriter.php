<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * **Mail-C2 step 7c — `auth.password.reset`, one event and no other** (`docs/session13/mail-c2-build-plan.md` §6, R1.7).
 * The `PasswordChangeAuditWriter` rule: the parameter list is the control, so no event type and no metadata can be passed.
 * On `erp_app`, joining the redeem's transaction by `TenantContext` re-entrancy, so a failed INSERT rolls back the password
 * it records (§9.2; T-R14b) and a rolled-back redeem leaves no row.
 *
 * `actor_label` is the **stored** email, passed from the redeem's step-3 read and never re-read: it matches the label of the
 * worker's `auth.password_reset.issued` row and the pre-authentication `auth.login.*` rows, so one person's reset can be
 * followed end to end by one label (`SEC` TO CONFIRM). `metadata` is the literal `{"purpose":"reset"}` — never anything
 * derived from the token or the password (E-23). `occurred_at now()`, the `erp_app` writer shape (the audit-order trigger
 * clamps `erp_app`). Uncapped (E-23); bounded upstream by the worker's 3 reset links per person per 24 h and one invitation per account.
 *
 * U-C1a: an `invitation` redeem writes `{"purpose":"invitation"}` — one of two literals keyed by the row's purpose, never a
 * value interpolated from input.
 */
final readonly class PasswordResetRedemptionAuditWriter
{
    public function __construct(private TenantContext $tenants, private Connection $app)
    {
    }

    /** `$purpose` (the redeemed row's) selects one of two literals; anything else is refused (Mail-C2 carry item 2). */
    private const METADATA = ['reset' => '{"purpose":"reset"}', 'invitation' => '{"purpose":"invitation"}'];

    public function record(string $tenantId, string $actorUserId, string $storedEmail, ?string $ipAddress, string $purpose): void
    {
        $metadata = self::METADATA[$purpose] ?? throw new \LogicException('A redeemed link has a purpose this writer does not record.');
        $this->tenants->run($tenantId, function () use ($tenantId, $actorUserId, $storedEmail, $ipAddress, $metadata): void {
            $this->app->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, NULL, NULL, ?::inet, ?::jsonb)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::AUTH_PASSWORD_RESET,
                    $actorUserId,
                    $storedEmail,
                    $ipAddress,
                    $metadata,
                ]);
        });
    }
}
