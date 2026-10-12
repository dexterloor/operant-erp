<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The writer of `supplier.created` and `supplier.updated`** — suppliers slice 1 C2, 2026-10-12 (owner Q-S5,
 * `docs/session13/suppliers-s1-ruling.md`; design §4.4; `CustomerAuditWriter`'s twin, for its reasons, which are not
 * restated here).
 *
 * The rows are what names the person who changed a supplier's master data: `buyer` holds `supplier.edit` **and**
 * `purchase_order.create`, `purchasing_manager` holds `supplier.edit` **and** `purchase_order.approve` — vendor-master
 * changes by the person who orders from that vendor are the classic vendor-fraud shape, even with no payables to cash it
 * yet. **Detection, not prevention** — and evidence against an in-app actor only, since SQL as `erp_app` can change
 * `supplier` with no row (A-3's shape).
 *
 * **The parameter lists are the control.** Both events are **business** under PC-11 (4a) (the `customer.*` filing's
 * terms; `SEC` to confirm the supplier block), so there is **no `$ipAddress`** and no `permission_code`.
 * `recordCreated()` takes no metadata (`{}`). `recordUpdated()` takes only the **names** of the changed fields, builds
 * `{"fields": [...]}` itself, sorted and de-duplicated, and refuses an empty list and any name outside the five accepted
 * fields. **Never values.** `supplier.deleted` / `.restored` are **not** written here: they are minted with their writers
 * in the later archived-view slice (final RED M-A).
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::SUPPLIER_CREATED
 * @see \App\SharedKernel\ValueObject\AuditEvent::SUPPLIER_UPDATED
 */
final readonly class SupplierAuditWriter
{
    /** The only names `supplier.updated` may carry: `UpdateSupplier`'s five accepted wire fields (§4.2). */
    private const FIELDS = ['code', 'country_code', 'currency_code', 'name', 'payment_terms_days'];

    public function __construct(private TenantContext $tenants)
    {
    }

    /** `supplier.created` — `CreateSupplier`, after its INSERT, in its transaction. `metadata` `{}`. */
    public function recordCreated(string $tenantId, string $actorUserId, string $actorLabel, string $supplierId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::SUPPLIER_CREATED, $supplierId, '{}');
    }

    /**
     * `supplier.updated` — `UpdateSupplier`, after its UPDATE, in its transaction: `{"fields": [...]}`, the wire names of
     * the fields whose submitted value differs from the locked row, sorted ascending, never values.
     *
     * @param list<string> $fields
     */
    public function recordUpdated(string $tenantId, string $actorUserId, string $actorLabel, string $supplierId, array $fields): void
    {
        if ([] === $fields) {
            throw new \LogicException('supplier.updated names the fields that changed; an update that changed nothing writes no row.');
        }
        foreach ($fields as $field) {
            if (!\in_array($field, self::FIELDS, true)) {
                throw new \LogicException(\sprintf('"%s" is not a field UpdateSupplier accepts, so supplier.updated cannot name it.', $field));
            }
        }
        $fields = array_values(array_unique($fields));
        sort($fields);

        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::SUPPLIER_UPDATED, $supplierId,
            json_encode(['fields' => $fields], \JSON_THROW_ON_ERROR));
    }

    private function record(string $tenantId, string $actorUserId, string $actorLabel, string $event, string $supplierId, string $metadata): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $event, $supplierId, $metadata): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL, ?::jsonb)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    $event,
                    $actorUserId,
                    $actorLabel,
                    // Read off the catalogue: `audit_log_subject_pair` requires the pair to arrive together.
                    AuditEvent::SUBJECT_TYPES[$event],
                    $supplierId,
                    $metadata,
                ]);
        });
    }
}
