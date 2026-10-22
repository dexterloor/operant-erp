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
 * fields. **Never values.** `recordRestored()` (C5 C2a) and `recordDeleted()` (C5 C2b, `DeleteSupplier`) take no metadata
 * (`{}`).
 *
 * **The catalogue trail** (G3-C0, 2026-10-22; `RESIDUAL_GAPS.md` G-41's closing work): `recordCatalogueCreated()`,
 * `recordCatalogueUpdated()` and `recordCatalogueDeleted()` write `supplier_product.*`, subject the catalogue entry (never
 * the supplier, design D-C0-1), business, no IP. Created/deleted carry only the two bare ids `{supplier_id,
 * inventory_item_id}` (the entry is hard-deleted, so the ids keep its chain findable); updated carries only the **names** of
 * the changed fields, from {@see self::CATALOGUE_FIELDS}. **Never a value: `unit_cost` is money (PC-12).** Who changes a
 * catalogue cost is the vendor-fraud shape again — `buyer` holds `supplier_product.edit` and `purchase_order.create`.
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::SUPPLIER_CREATED
 * @see \App\SharedKernel\ValueObject\AuditEvent::SUPPLIER_UPDATED
 * @see \App\SharedKernel\ValueObject\AuditEvent::SUPPLIER_RESTORED
 * @see \App\SharedKernel\ValueObject\AuditEvent::SUPPLIER_DELETED
 * @see \App\SharedKernel\ValueObject\AuditEvent::SUPPLIER_PRODUCT_CREATED
 * @see \App\SharedKernel\ValueObject\AuditEvent::SUPPLIER_PRODUCT_UPDATED
 * @see \App\SharedKernel\ValueObject\AuditEvent::SUPPLIER_PRODUCT_DELETED
 */
final readonly class SupplierAuditWriter
{
    /** The only names `supplier.updated` may carry: `UpdateSupplier`'s five accepted wire fields (§4.2). */
    private const FIELDS = ['code', 'country_code', 'currency_code', 'name', 'payment_terms_days'];

    /** The only names `supplier_product.updated` may carry: `UpdateSupplierProduct`'s four accepted wire fields (§4.2). */
    private const CATALOGUE_FIELDS = ['lead_time_days', 'minimum_order_quantity', 'supplier_sku', 'unit_cost'];

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

    /** `supplier.restored` — `RestoreSupplier` (C5 C2a), after its UPDATE, in its transaction. `metadata` `{}`. */
    public function recordRestored(string $tenantId, string $actorUserId, string $actorLabel, string $supplierId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::SUPPLIER_RESTORED, $supplierId, '{}');
    }

    /** `supplier.deleted` — `DeleteSupplier` (C5 C2b), after its UPDATE, in its transaction. `metadata` `{}`. */
    public function recordDeleted(string $tenantId, string $actorUserId, string $actorLabel, string $supplierId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::SUPPLIER_DELETED, $supplierId, '{}');
    }

    /**
     * `supplier_product.created` — `CreateSupplierProduct` (G3-C0), after its INSERT, in its transaction:
     * `{"supplier_id", "inventory_item_id"}`, two bare ids, never a value.
     */
    public function recordCatalogueCreated(string $tenantId, string $actorUserId, string $actorLabel, string $supplierProductId, string $supplierId, string $inventoryItemId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::SUPPLIER_PRODUCT_CREATED, $supplierProductId,
            json_encode(['supplier_id' => $supplierId, 'inventory_item_id' => $inventoryItemId], \JSON_THROW_ON_ERROR));
    }

    /**
     * `supplier_product.updated` — `UpdateSupplierProduct` (G3-C0), after its UPDATE, in its transaction: `{"fields": [...]}`,
     * the wire names whose submitted value differs from the locked entry (numerically at column scale), sorted, never values.
     *
     * @param list<string> $fields
     */
    public function recordCatalogueUpdated(string $tenantId, string $actorUserId, string $actorLabel, string $supplierProductId, array $fields): void
    {
        if ([] === $fields) {
            throw new \LogicException('supplier_product.updated names the fields that changed; an update that changed nothing writes no row.');
        }
        foreach ($fields as $field) {
            if (!\in_array($field, self::CATALOGUE_FIELDS, true)) {
                throw new \LogicException(\sprintf('"%s" is not a field UpdateSupplierProduct accepts, so supplier_product.updated cannot name it.', $field));
            }
        }
        $fields = array_values(array_unique($fields));
        sort($fields);

        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::SUPPLIER_PRODUCT_UPDATED, $supplierProductId,
            json_encode(['fields' => $fields], \JSON_THROW_ON_ERROR));
    }

    /**
     * `supplier_product.deleted` — `DeleteSupplierProduct` (G3-C0), after a one-row DELETE, in its transaction:
     * `{"supplier_id", "inventory_item_id"}` — the row is gone, so these keep its chain findable.
     */
    public function recordCatalogueDeleted(string $tenantId, string $actorUserId, string $actorLabel, string $supplierProductId, string $supplierId, string $inventoryItemId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::SUPPLIER_PRODUCT_DELETED, $supplierProductId,
            json_encode(['supplier_id' => $supplierId, 'inventory_item_id' => $inventoryItemId], \JSON_THROW_ON_ERROR));
    }

    private function record(string $tenantId, string $actorUserId, string $actorLabel, string $event, string $subjectId, string $metadata): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $event, $subjectId, $metadata): void {
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
                    $subjectId,
                    $metadata,
                ]);
        });
    }
}
