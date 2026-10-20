<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\Application\Port\Sales;

use Doctrine\ORM\EntityManagerInterface;

/**
 * **What stops a warehouse being archived: its open sales orders** (API-O-34, the owner's note of 2026-09-13; warehouse/location
 * delete-restore C4, 2026-10-20; `docs/session13/wh-loc-delete-design.md` §2.1, §5, Revision 2 G4;
 * `docs/session13/wh-loc-delete-arch.md` §1). `DeleteWarehouse` (`WarehouseWriter::delete()`) asks it; **Sales answers
 * it**, because only Sales may spell its orders' statuses (API-15) and read its tables (A-2). The split-port form: its own
 * deptrac layer `WarehousesSalesPort`, listed only by `WarehousesApplication` and `SalesApplication` — never `Ai*` or
 * `Analytics*` (`DeptracRulesetTest`).
 *
 * Call it inside your own tenancy transaction (the implementation refuses at nesting level 0 or with no tenant set,
 * `PartyPortTenancyGuardTest`), AFTER taking the warehouse `FOR UPDATE`: every path that creates work in a warehouse
 * holds it `FOR KEY SHARE` (W-5 warehouse row locks, rule 1), so this later statement sees everything committed and
 * nothing new can arrive. **The answerer locks nothing** (rule 4): one plain statement, no write.
 */
interface WarehouseOpenSalesOrders
{
    /**
     * How many LIVE orders of this warehouse are still open — `draft`, `confirmed` or `partially_fulfilled`.
     *
     * @param string $warehouseId canonical lower-case RFC 4122
     */
    public function openSalesOrderCountInside(EntityManagerInterface $em, string $warehouseId): int;
}
