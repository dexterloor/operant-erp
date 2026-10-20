<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Warehouses\Application\Port\Inventory\HeldStock;
use App\Modules\Warehouses\Application\Port\Inventory\WarehouseHeldStock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **Inventory's answer to {@see WarehouseHeldStock}** (warehouse/location delete-restore C3, 2026-10-20;
 * `docs/session13/wh-loc-delete-design.md` §5, Revision 1 G1/G2; `wh-loc-delete-roles.md` D-5): does a storage location
 * hold stock, and is any of it an archived item's. **ONE statement**, inside the caller's transaction, reading only
 * Inventory's own tables (`stock_level`, joined to `inventory_item` by its key — A-2); RLS bounds it to the workspace and
 * `tenant_id` is joined as well. `stock_level_tenant_location_idx (tenant_id, storage_location_id, inventory_item_id)`
 * serves it (`DATA` D-5: no new index). **It locks nothing** (W-5 warehouse row locks, rule 4: a lock here would be a lock
 * after the caller's location lock, invisible to any scan of the caller) and writes nothing.
 *
 * The predicate is the owner's words, `qty_on_hand <> 0 OR qty_reserved <> 0` (design Revision 1 G1, RED L-1). The second
 * disjunct cannot fire alone: `stock_level_reserved_sane` (`0 <= qty_reserved <= qty_on_hand`) — mutation M17 cannot go red,
 * disclosed. A row at zero is not stock (zero rows stay, G-47).
 */
final readonly class InventoryHeldStock implements WarehouseHeldStock
{
    /** Public so a test reads the statement it pins, never a transcription. */
    public const LOCATION = <<<'SQL'
        SELECT count(*) AS stocked_rows,
               count(*) FILTER (WHERE i.deleted_at IS NOT NULL) AS archived_item_rows
          FROM stock_level sl
          JOIN inventory_item i ON i.tenant_id = sl.tenant_id AND i.id = sl.inventory_item_id
         WHERE sl.storage_location_id = ?::uuid
           AND (sl.qty_on_hand <> 0 OR sl.qty_reserved <> 0)
        SQL;

    /**
     * C4: over every location of the warehouse, live **or archived** (no `deleted_at` predicate on `storage_location`, design
     * §2.1 step 3), counted in DISTINCT locations. `DATA` D-5: the workspace's locations through `(tenant_id, id)` with
     * `warehouse_id` as a filter (bounded, TB-1c), then `stock_level_tenant_location_idx`; no new index.
     */
    public const WAREHOUSE = <<<'SQL'
        SELECT count(DISTINCT sl.storage_location_id) AS stocked_rows,
               count(DISTINCT sl.storage_location_id) FILTER (WHERE i.deleted_at IS NOT NULL) AS archived_item_rows
          FROM storage_location l
          JOIN stock_level sl ON sl.tenant_id = l.tenant_id AND sl.storage_location_id = l.id
          JOIN inventory_item i ON i.tenant_id = sl.tenant_id AND i.id = sl.inventory_item_id
         WHERE l.warehouse_id = ?::uuid
           AND (sl.qty_on_hand <> 0 OR sl.qty_reserved <> 0)
        SQL;

    /**
     * The port refuses to answer outside a tenancy transaction (`SupplierOpenPurchaseOrders::TENANT_GUC`'s reason: the answer
     * FAILS OPEN — with no tenant set RLS hides every row, the count is 0, and the archive goes through). Public so the
     * refusal tests name it.
     */
    public const TENANT_GUC = "SELECT NULLIF(current_setting('app.tenant_id', true), '')";

    public function stockedLocationCountInside(EntityManagerInterface $em, string $locationId): HeldStock
    {
        self::refuseOutsideATenancyTransaction($em, 'WarehouseHeldStock');

        $row = $em->getConnection()->fetchAssociative(self::LOCATION, [$locationId]);
        if (false === $row) {
            throw new \LogicException('A SELECT of two counts returned no row.');
        }

        return new HeldStock(
            stocked: (int) $row['stocked_rows'] > 0 ? 1 : 0,
            archivedItemStocked: (int) $row['archived_item_rows'] > 0 ? 1 : 0,
        );
    }

    public function stockedLocationCountInWarehouseInside(EntityManagerInterface $em, string $warehouseId): HeldStock
    {
        self::refuseOutsideATenancyTransaction($em, 'WarehouseHeldStock');

        $row = $em->getConnection()->fetchAssociative(self::WAREHOUSE, [$warehouseId]);
        if (false === $row) {
            throw new \LogicException('A SELECT of two counts returned no row.');
        }

        return new HeldStock(stocked: (int) $row['stocked_rows'], archivedItemStocked: (int) $row['archived_item_rows']);
    }

    /**
     * Nesting level 0 (`SalesOrderRowLock`'s shape), then an empty tenant setting (the read `TenantContext` makes) — the two
     * states `PartyPortTenancyGuardTest` drives. A copy of the party ports' guard (the third, in a third module): each
     * module answers for itself, and no module may import another's Application class (A-2).
     */
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
