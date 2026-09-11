<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

/**
 * Take stock in on behalf of another module — ARCHITECTURE §5.5's
 * `Purchasing → Inventory::ReceiveStock` edge, one of the exactly two
 * cross-module write edges W-3 permits (the other, `DepleteStock`, is not built).
 *
 * ---------------------------------------------------------------------------
 * What it writes
 * ---------------------------------------------------------------------------
 * One `stock_movement` per line — `reason = 'receipt'`, `source_type =
 * 'goods_receipt'`, `source_id = goodsReceiptId`, `occurred_at = occurredAt` —
 * and, per distinct (item, bin), an upsert of `stock_level`: `qty_on_hand +=`
 * the summed quantity, `version + 1` (S-12's optimistic lock; a new row starts
 * at 1). Inventory executes its own write and the caller never touches either
 * table (W-1, A-13).
 *
 * The source type is not a parameter. The reason is always `receipt`, so the
 * source is always a goods receipt; a caller-chosen type could only make the
 * "did this document already post?" lookup (O-6) miss.
 *
 * ---------------------------------------------------------------------------
 * The caller's side of the contract
 * ---------------------------------------------------------------------------
 * **W-2 — call it inside your own tenancy transaction.** It opens none of its
 * own; your rollback undoes it. With no transaction open it throws
 * `NoCallerTransaction`; inside a transaction `TenantContext` did not open it
 * throws `TenantContextViolation`; and the tenant it writes for is
 * `CurrentTenant`'s, which `TenantContext` refuses if it is not the tenant your
 * transaction is bound to.
 *
 * **W-5 — make it your LAST write.** It writes `stock_level` after every
 * movement and in ascending (inventory_item_id, storage_location_id) order, so
 * two receipts cannot deadlock each other on the levels. That holds across
 * flows only if every caller takes its own locks (the purchase order, the
 * receipt) BEFORE calling and none after: `stock_level` is locked last in any
 * transaction that touches it.
 *
 * **W-4 — depth 1.** It calls no other module's command.
 *
 * ---------------------------------------------------------------------------
 * Refusals
 * ---------------------------------------------------------------------------
 * `StockReceiptRefused`, which the caller translates with its own pointers:
 *
 *   stock_movement.receipt_has_a_line              no lines (at construction)
 *   stock_movement.quantity_is_positive            a line's quantity (at construction)
 *   storage_location.belongs_to_receiving_warehouse  a bin that is not a live bin of `warehouseId`
 *   stock_movement.goods_receipt_posted_once       this goods receipt already posted
 *
 * Another workspace's item or bin needs no check and has none: the composite
 * foreign keys refuse the item (23503, which aborts your transaction) and RLS
 * hides the bin, which is then refused as not in the warehouse.
 *
 * ---------------------------------------------------------------------------
 * Not covered (named, not built)
 * ---------------------------------------------------------------------------
 *   - The posted-once check is a read before the insert. Two transactions
 *     posting the SAME goods receipt at the same moment would both pass it;
 *     the backstop is the caller's own unique key on its goods receipt row.
 *   - Ids are not validated as UUIDs here: a malformed one is a 22P02 from
 *     Postgres that aborts your transaction. The ids come from your own rows.
 *   - An archived ITEM is not refused (an archived BIN is).
 */
interface ReceiveStock
{
    /**
     * @throws StockReceiptRefused when the receipt cannot be taken in, for a reason the caller translates
     * @throws NoCallerTransaction when called with no transaction open (W-2) — a bug in the caller, a 500
     */
    public function receive(StockReceipt $receipt): void;
}
