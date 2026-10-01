<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

/**
 * Take stock back in when a customer returns it — ARCHITECTURE §5.5 W-3's THIRD cross-module write edge,
 * `Sales → Inventory::ReturnStock` (`ARCH` amendment 2026-09-30, owner ruling Q-J; V2-R-15's gate met). Its one caller
 * is `Sales\Application\SalesReturnWriter` (`CreateSalesReturn`, `sales_return.create`) — PC-10 names it, and a new
 * caller is a `SEC` decision (`WriteOwnershipTest::STOCK_WRITE_CONTRACTS`).
 *
 * ---------------------------------------------------------------------------
 * What it writes
 * ---------------------------------------------------------------------------
 * One `stock_movement` per line — `reason = 'return'`, `source_type = 'sales_return'`, `source_id = salesReturnId`,
 * a POSITIVE quantity, `occurred_at = occurredAt` — and, per distinct (item, bin), an upsert of `stock_level`:
 * `qty_on_hand +=` the summed quantity, `version + 1`. `ReceiveStock`'s write, with its own reason literal: E-26 binds
 * `'return'` to this writer alone, and E-27's refusal lifted in the commit that built it.
 *
 * **No status recomputation on the parent document** (PC-10, owner ruling Q-C): a return does not reopen the order.
 *
 * ---------------------------------------------------------------------------
 * The caller's side of the contract — `ReceiveStock`'s, word for word in substance
 * ---------------------------------------------------------------------------
 * **W-2:** call it inside your own tenancy transaction; with none open it throws `NoCallerTransaction`.
 * **W-5:** take the sales order's row lock FIRST and insert your return rows before calling; inside, `stock_level`
 * is written after every movement and in ascending (inventory_item_id, storage_location_id) order — `CreateFulfilment`'s
 * shape, so return ∥ fulfilment and return ∥ receipt share the levels-ascending order and no first lock (O-10, redone
 * by inspection by `ARCH`, plus `PurchaseOrderRowLockTest`'s run).
 * **W-4:** depth 1.
 *
 * ---------------------------------------------------------------------------
 * Refusals (`StockReturnRefused`, which the caller translates with its own pointers)
 * ---------------------------------------------------------------------------
 *   stock_movement.return_has_a_line                   no lines (at construction)
 *   stock_movement.quantity_is_positive                a line's quantity (at construction)
 *   stock_movement.sales_return_posted_once            this return already posted
 *   warehouse.is_live                                  `warehouseId` (the SHIPPING warehouse) is archived
 *   storage_location.belongs_to_returning_warehouse    a bin that is not a live bin of `warehouseId`
 *   stock_level.returned_quantity_fits                 one (item, bin)'s summed lines exceed numeric(19,6)
 *
 * Quarantine bins are accepted: a return is not a pick (owner ruling Q-M). The item is the caller's to derive from
 * its own rows (the shipped line's order line's item), never a request field.
 */
interface ReturnStock
{
    public function returnStock(StockReturn $return): void;
}
