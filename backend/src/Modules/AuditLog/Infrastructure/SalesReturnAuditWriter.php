<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `sales_return.created` — sales step 7 R2. `FulfilmentAuditWriter`'s shape: one method per event, the row written in
 * the caller's transaction, so the return and the row naming its recorder commit together or not at all. Filed
 * **business** by `SEC` (S-5; final position item 5): subject the return, no IP, `metadata` `{"reason", "deducts"}` —
 * `deducts` marks a deduction in the trail (it does not single out a fake one under (a′), G-24). **No grant:** `erp_app`
 * already holds INSERT on `audit_log`.
 */
final readonly class SalesReturnAuditWriter
{
    public function __construct(private TenantContext $tenants)
    {
    }

    /** The caller invokes this on its **insert** branch only — a key replay writes no second row. */
    public function recordCreated(string $tenantId, string $actorUserId, string $actorLabel, string $salesReturnId, string $reason, bool $deducts): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $salesReturnId, $reason, $deducts): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL,
                     jsonb_build_object('reason', ?::text, 'deducts', ?::boolean))
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::SALES_RETURN_CREATED,
                    $actorUserId,
                    $actorLabel,
                    AuditEvent::SUBJECT_TYPES[AuditEvent::SALES_RETURN_CREATED],
                    $salesReturnId,
                    $reason,
                    $deducts ? 'true' : 'false',
                ]);
        });
    }
}
