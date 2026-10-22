<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The writer of `inventory_item.deleted` and `inventory_item.restored`** — G5-C2a, 2026-10-22
 * (`docs/session13/g5-g6-bulk-export-design.md` §5.5). Until this class `DeleteItem` and `RestoreItem` wrote no audit row:
 * who archived an item was recorded nowhere (`updated_at` records when, not who), and the bulk archival's per-member
 * parity (G-50's closing work: *"one audit row per member"*) had nothing to be parity with.
 *
 * `CustomerAuditWriter::recordDeleted()` / `recordRestored()`'s shape, for their reasons, which are not restated here: one
 * method per event and no parameter selecting one; scalars, not an `Identity`; the connection is the one the caller's
 * `TenantContext::run()` hands in (`erp_app`, never an unbound autowired `Connection` — A-10), and `run()` is re-entrant for
 * the same tenant, so the row **joins** the write's transaction and neither can commit without the other. Nothing is
 * caught.
 *
 * **The parameter lists are the control.** Both events are **business** under PC-11 (4a) — filed by the builder on the
 * customer pair's terms, `SEC` to confirm — so there is **no `$ipAddress`** (`audit_log_ip_address_scope` refuses one on a
 * business event), no permission code, and no metadata (`{}`): an archive or a restore changes only `deleted_at`, which the
 * row itself shows as `archived_at`. The subject is read off `AuditEvent::SUBJECT_TYPES`, never written as a literal.
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::INVENTORY_ITEM_DELETED
 * @see \App\SharedKernel\ValueObject\AuditEvent::INVENTORY_ITEM_RESTORED
 */
final readonly class ItemAuditWriter
{
    public function __construct(private TenantContext $tenants)
    {
    }

    /** `inventory_item.deleted` — `DeleteItem`, after its one-row UPDATE, in its transaction. `metadata` `{}`. */
    public function recordDeleted(string $tenantId, string $actorUserId, string $actorLabel, string $itemId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::INVENTORY_ITEM_DELETED, $itemId);
    }

    /** `inventory_item.restored` — `RestoreItem`, after its one-row UPDATE, in its transaction. `metadata` `{}`. */
    public function recordRestored(string $tenantId, string $actorUserId, string $actorLabel, string $itemId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::INVENTORY_ITEM_RESTORED, $itemId);
    }

    private function record(string $tenantId, string $actorUserId, string $actorLabel, string $event, string $itemId): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $event, $itemId): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL, '{}'::jsonb)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    $event,
                    $actorUserId,
                    $actorLabel,
                    // Read off the catalogue: `audit_log_subject_pair` requires the pair to arrive together.
                    AuditEvent::SUBJECT_TYPES[$event],
                    $itemId,
                ]);
        });
    }
}
