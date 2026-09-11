<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use Symfony\Component\Uid\Uuid;

/**
 * Fixtures for the `/purchase-orders` slice.
 *
 * ---------------------------------------------------------------------------
 * Why these are here and not on `TenancyTestCase`
 * ---------------------------------------------------------------------------
 * `seedSupplier()` and `seedItem()` live on the shared tenancy rig because the
 * isolation suite needs them. Nothing outside these five files needs a purchase
 * order, and a fixture on the shared rig is a fixture every test in the project
 * has to keep working.
 *
 * ---------------------------------------------------------------------------
 * ⚠ `seedOrder()` writes `created_by_user_id` DIRECTLY, and that is the whole
 * reason this class exists
 * ---------------------------------------------------------------------------
 * DSoD-1 is a comparison against `purchase_order.created_by_user_id`, so testing
 * it needs orders with a **known** creator — and `CreatePurchaseOrder`, the
 * operation §8.1 says *"sets it server-side from the JWT subject on every
 * user-initiated create"*, is not in this package. The fixture therefore does
 * what that operation will do, and takes the creator as an argument so a test
 * can name all four of E-19's cases:
 *
 *   creator ≠ caller            → approval allowed
 *   creator = caller            → refused, `business-rule-violated`
 *   creator = caller, `owner`   → allowed, exempt **by name**
 *   creator NULL                → allowed (R-6, and it is the rule returning
 *                                 true rather than an exemption)
 *
 * **This is a fixture standing in for an unbuilt operation and it is labelled as
 * one.** The day `CreatePurchaseOrder` ships, §8.1's fifth check —
 * `::everyHttpCreatedOrderRecordsItsCreator` — is what proves the real path sets
 * the column, and these fixtures stop being the only writer of it.
 */
abstract class PurchaseOrderTestCase extends ApiTestCase
{
    /** @return string the new row's id */
    protected function seedWarehouse(string $tenantId, string $name = 'Main'): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO warehouse (id, tenant_id, code, name, country_code)
            VALUES (?::uuid, ?::uuid, ?, ?, 'DE')
            SQL, [$id, $tenantId, strtoupper(substr(md5($name.$id), 0, 8)), $name]);

        return $id;
    }

    /**
     * A purchase order in whatever state a test needs.
     *
     * `ordered_at` follows `purchase_order_ordered_at_set` — as of
     * `Version20260911000200`, `CHECK (status IN ('draft', 'cancelled') OR
     * ordered_at IS NOT NULL)` — rather than being passed in, so a fixture cannot
     * construct a row the database would refuse and then have the test fail
     * somewhere else entirely. **A `cancelled` fixture therefore keeps whatever
     * `ordered_at` it is given, including none**, which is the case that
     * constraint was widened to admit: an order cancelled while still a draft was
     * never placed.
     *
     * ⚠ **`idempotency_key` is written on every seeded row, and that is not
     * tidiness.** `Version20260911000200` installs
     * `purchase_order_idempotency_key_required`, a `BEFORE INSERT` trigger that
     * refuses an insert carrying no key — so this fixture and
     * `CreatePurchaseOrder` are now under the same rule, which is the whole
     * content of the trigger. A test wanting the **historical** shape (a row
     * raised before step 5, with a NULL key) cannot get one from here and should
     * not: those rows predate the migration and no insert creates them any more.
     *
     * The default is a fresh UUID rather than a fixed string because the column
     * is on a live-row unique per tenant, and two fixtures sharing a literal
     * would collide in any test that seeds two orders.
     *
     * @return string the new row's id
     */
    protected function seedOrder(
        string $tenantId,
        string $orderNumber,
        string $supplierId,
        string $warehouseId,
        ?string $createdByUserId = null,
        string $status = PurchaseOrder::STATUS_DRAFT,
        ?string $orderedAt = null,
        ?string $expectedAt = null,
        bool $archived = false,
        ?string $idempotencyKey = null,
    ): string {
        $id = Uuid::v4()->toRfc4122();

        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO purchase_order
                (id, tenant_id, order_number, supplier_id, warehouse_id, created_by_user_id,
                 status, currency_code, ordered_at, expected_at, deleted_at, idempotency_key)
            VALUES
                (?::uuid, ?::uuid, ?, ?::uuid, ?::uuid, ?::uuid,
                 ?, 'EUR', ?::timestamptz, ?::timestamptz, ?::timestamptz, ?)
            SQL, [
                $id,
                $tenantId,
                $orderNumber,
                $supplierId,
                $warehouseId,
                $createdByUserId,
                $status,
                \in_array($status, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_CANCELLED], true)
                    ? $orderedAt
                    : ($orderedAt ?? '2026-01-01T00:00:00+00:00'),
                $expectedAt,
                $archived ? '2026-01-02T00:00:00+00:00' : null,
                $idempotencyKey ?? Uuid::v4()->toRfc4122(),
            ]);

        return $id;
    }

    /**
     * A line, with the `line_number` and `lead_time_days` the write path mints
     * and snapshots. Both are `NOT NULL` and neither is an accepted field.
     *
     * @return string the new row's id
     */
    protected function seedLine(
        string $tenantId,
        string $orderId,
        string $itemId,
        int $lineNumber = 1,
        string $quantity = '10.000000',
        string $unitCost = '2.5000',
        string $quantityReceived = '0.000000',
        int $leadTimeDays = 7,
    ): string {
        $id = Uuid::v4()->toRfc4122();

        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO purchase_order_line
                (id, tenant_id, purchase_order_id, inventory_item_id, line_number,
                 quantity_ordered, quantity_received, unit_cost, lead_time_days)
            VALUES
                (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::numeric, ?::numeric, ?::numeric, ?)
            SQL, [$id, $tenantId, $orderId, $itemId, $lineNumber, $quantity, $quantityReceived, $unitCost, $leadTimeDays]);

        return $id;
    }

    /**
     * The supplier's catalogue entry for an item — F-2 step 3's source for the
     * lead time `CreatePurchaseOrderLine` snapshots. Without one the operation
     * refuses the line, which is a case the write test drives on purpose.
     */
    protected function seedSupplierProduct(string $tenantId, string $supplierId, string $itemId, int $leadTimeDays = 7): void
    {
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO supplier_product
                (id, tenant_id, supplier_id, inventory_item_id, supplier_sku, lead_time_days, unit_cost, currency_code)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?, 1.0000, 'EUR')
            SQL, [Uuid::v4()->toRfc4122(), $tenantId, $supplierId, $itemId, 'SP-'.substr(md5($itemId), 0, 8), $leadTimeDays]);
    }

    /** Grants the acting principal a role of this name, so DSoD-1's `owner` exemption has a subject. */
    protected function grantRoleNamed(string $tenantId, string $userId, string $roleName): void
    {
        $roleId = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId,
            'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
            [$roleId, $tenantId, $roleName]);
        $this->insertAsOwnerForTenant($tenantId,
            'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid)',
            [Uuid::v4()->toRfc4122(), $tenantId, $userId, $roleId]);
    }

    /**
     * A complete draft order with one supplier, one warehouse and one item, and
     * the ids a test needs to address any of them.
     *
     * @return array{order: string, supplier: string, warehouse: string, item: string}
     */
    protected function seedDraft(string $tenantId, string $orderNumber = 'PO-1', ?string $createdByUserId = null): array
    {
        $supplier = $this->seedSupplier($tenantId, 'Acme '.$orderNumber);
        $warehouse = $this->seedWarehouse($tenantId, 'WH '.$orderNumber);
        $item = $this->seedItem($tenantId, 'SKU-'.$orderNumber);

        return [
            'order' => $this->seedOrder($tenantId, $orderNumber, $supplier, $warehouse, $createdByUserId),
            'supplier' => $supplier,
            'warehouse' => $warehouse,
            'item' => $item,
        ];
    }
}
