<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Warehouses\Application\Port\Purchasing\WarehouseOpenPurchaseOrders;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **Purchasing's answer to {@see WarehouseOpenPurchaseOrders}** (warehouse/location delete-restore C4, 2026-10-20;
 * `docs/session13/wh-loc-delete-design.md` §2.1, §5; API-O-34): how many LIVE `purchase_order` rows of a warehouse are still
 * open, bound from the entity's own constants (API-15), never literals. One statement, inside the caller's transaction; it
 * reads only Purchasing's table (A-2), and RLS bounds it to the workspace. A deleted draft does not count (`deleted_at IS
 * NULL`). **`purchase_order_tenant_warehouse_open_idx`** (`(tenant_id, warehouse_id, status) WHERE deleted_at IS NULL`, `DATA`
 * `wh-loc-delete-roles.md` D-3) serves it: `status = ANY(?::text[])` is an Index Cond, and the open set lives here once,
 * never in DDL (`SchemaQueryPlanTest` pins the plan). **It locks nothing** (W-5 warehouse row locks, rule 4).
 */
final readonly class WarehouseOpenPurchaseOrdersCount implements WarehouseOpenPurchaseOrders
{
    /** The statuses a warehouse may not be archived under (API-O-34). */
    public const OPEN_STATUSES = [
        PurchaseOrder::STATUS_DRAFT,
        PurchaseOrder::STATUS_ORDERED,
        PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
    ];

    /** Public so a test reads the statement it pins, never a transcription. */
    public const COUNT = 'SELECT count(*) FROM purchase_order WHERE warehouse_id = ?::uuid AND deleted_at IS NULL AND status = ANY(?::text[])';

    /** `SupplierOpenPurchaseOrders::TENANT_GUC`'s reason: the answer FAILS OPEN. Public so the refusal tests name it. */
    public const TENANT_GUC = "SELECT NULLIF(current_setting('app.tenant_id', true), '')";

    public function openPurchaseOrderCountInside(EntityManagerInterface $em, string $warehouseId): int
    {
        self::refuseOutsideATenancyTransaction($em, 'WarehouseOpenPurchaseOrders');

        return (int) $em->getConnection()->fetchOne(self::COUNT, [$warehouseId, '{'.implode(',', self::OPEN_STATUSES).'}']);
    }

    /** The party ports' guard, copied (each module answers for itself; A-2): nesting level 0, then an empty tenant setting. */
    private static function refuseOutsideATenancyTransaction(EntityManagerInterface $em, string $port): void
    {
        // Through the EntityManager, never a typed `Connection` (A-10; the caller's own connection, S-7(b)).
        $connection = $em->getConnection();
        if (0 === $connection->getTransactionNestingLevel()) {
            throw new \LogicException($port.' needs the caller\'s open tenancy transaction: outside one, RLS hides every row and the answer is 0, which lets the archive through.');
        }
        $tenant = $connection->fetchOne(self::TENANT_GUC);
        if (null === $tenant || false === $tenant) {
            throw new \LogicException($port.' was called in a transaction with no tenant set: RLS would hide every row and the answer would be 0, which lets the archive through.');
        }
    }
}
