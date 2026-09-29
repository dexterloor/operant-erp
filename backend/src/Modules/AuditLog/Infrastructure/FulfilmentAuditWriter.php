<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The `fulfilment.*` audit row** — B-4a (roles slice 2, C1, 2026-09-29): `fulfilment.created`, written by
 * `FulfilmentWriter` in the shipping transaction. Actor the shipper (= `fulfilment.shipped_by_user_id`, owner ruling
 * R6). With `sales_order.confirmed` it makes SoD-4 answerable from the trail — evidence, not enforcement.
 *
 * One class per subject table, `SalesOrderAuditWriter`'s shape and reasoning: one method per event and no parameter
 * naming an event, so no caller can choose one it was not written for. **Synchronous and inside the caller's
 * transaction** (`API.md` §9.2): `TenantContext::run()` is re-entrant for the same tenant, so the document and the
 * row naming its actor commit together or not at all. **No grant:** `erp_app` already holds INSERT on `audit_log`.
 *
 * **The parameter list is the control** (`SEC`, `docs/session13/roles-s2-sec.md` §3): a business event, so no
 * `$ipAddress` (`audit_log_ip_address_scope` refuses one), and `metadata` is `{}` with no parameter that could fill
 * it — the number, order and warehouse are PC-15 display data (condition 6), and a copy adds no detection (G-10).
 */
final readonly class FulfilmentAuditWriter
{
    public function __construct(private TenantContext $tenants)
    {
    }

    /**
     * `fulfilment.created`. `$actorUserId` is the authenticated caller; `$actorLabel` their display name, captured by the caller.
     * The caller invokes this on its **insert** branch only — a key replay writes no second row.
     */
    public function recordCreated(string $tenantId, string $actorUserId, string $actorLabel, string $fulfilmentId): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $fulfilmentId): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL, '{}'::jsonb)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::FULFILMENT_CREATED,
                    $actorUserId,
                    $actorLabel,
                    // Read off the catalogue: `audit_log_subject_pair` requires the pair to arrive together.
                    AuditEvent::SUBJECT_TYPES[AuditEvent::FULFILMENT_CREATED],
                    $fulfilmentId,
                ]);
        });
    }
}
