<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\Application\Port\Inventory;

use Doctrine\ORM\EntityManagerInterface;

/**
 * **What stops a storage location (C3) — and a warehouse (C4) — being archived: the stock it holds.** Warehouses'
 * inbound port for ONE named consumer (warehouse/location delete-restore C3, 2026-10-20;
 * `docs/session13/wh-loc-delete-design.md` §5, Revision 1 G2, Revision 2 G4; `docs/session13/wh-loc-delete-arch.md` §1;
 * owner Q-1 adopted under delegation, `wh-loc-delete-q-ruling.md`). `DeleteStorageLocation` (`StorageLocationWriter::delete()`)
 * asks it; **Inventory answers it** (`Inventory\Application\InventoryHeldStock`, bound in `services.yaml`), because only
 * Inventory may read `stock_level` and `inventory_item` (A-2).
 *
 * **Not a Contract** (`CustomerOpenWork`'s reason): it lives in its own deptrac layer, `WarehousesInventoryPort` — the
 * split form of A-2 (Warehouses asks three modules, so its ports are cut by answerer: `Port\Inventory\` here,
 * `Port\Purchasing\` and `Port\Sales\` at C4). Only `WarehousesApplication` and `InventoryApplication` may use it — never
 * `Ai*` or `Analytics*` (`DeptracRulesetTest`). Its answer type lives beside it ({@see HeldStock}), never in `Port\` itself.
 *
 * Call it inside your own tenancy transaction (the implementation refuses at nesting level 0 or with no tenant set,
 * `PartyPortTenancyGuardTest`), AFTER taking the location `FOR UPDATE`: the lock waits for every in-flight stock writer's
 * `FOR KEY SHARE` on the location, and this, a later statement under READ COMMITTED, then sees what they committed. **The
 * answerer locks nothing** (W-5 warehouse row locks, rule 4): one plain statement, no write.
 */
interface WarehouseHeldStock
{
    /**
     * Whether the location holds stock (`qty_on_hand <> 0 OR qty_reserved <> 0` on any of its `stock_level` rows — the
     * owner's words; `stock_level_reserved_sane` makes the second disjunct unable to fire alone), and whether any of that
     * stock is of an archived item. A location with only zero rows holds none (G-47: zero rows stay).
     *
     * @param string $locationId canonical lower-case RFC 4122
     */
    public function stockedLocationCountInside(EntityManagerInterface $em, string $locationId): HeldStock;

    /**
     * C4 (`DeleteWarehouse`, API-O-34): the same question over **every** location of the warehouse, live **or archived** — a
     * location archived by SQL with stock must not slip through (design §2.1 step 3). Counted in locations (`SEC` S-3).
     *
     * @param string $warehouseId canonical lower-case RFC 4122
     */
    public function stockedLocationCountInWarehouseInside(EntityManagerInterface $em, string $warehouseId): HeldStock;
}
