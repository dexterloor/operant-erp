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
 * **W-5 — take every contended lock before calling, and flush your own
 * document rows before calling.** Inside, `stock_level` is written after every
 * movement and in ascending (inventory_item_id, storage_location_id) order, so
 * within this command the levels are always locked in one order. Across
 * transactions that is only half the rule; the other half is yours:
 *
 *   - Lock what two transactions contend for — the purchase order row — FIRST,
 *     so a second receipt against the same order waits there, holding nothing.
 *   - Flush the goods receipt and its lines FIRST. `wrapInTransaction()`
 *     flushes the unit of work AFTER your callback returns, which would put
 *     your own inserts after the level locks.
 *
 * Writes after `receive()` returns are allowed, and FLOWS F-3 step 5 has one:
 * it recomputes the order's status after the stock update. That is safe
 * because it rewrites a row this transaction already locked. An insert after
 * the call is safe too: a foreign-key check takes only `KEY SHARE` on the rows
 * it references, and `KEY SHARE` conflicts only with `FOR UPDATE` or an
 * `UPDATE` of a key column — not with the `FOR NO KEY UPDATE` lock this
 * command's upsert of non-key columns takes. What is NOT safe after the call
 * is taking a lock another receipt or fulfilment could hold while it waits for
 * a stock level. This is argued by inspection; no two-connection test exists.
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
 *   stock_movement.goods_receipt_posted_once       this goods receipt already posted
 *   warehouse.is_live                              `warehouseId` is archived
 *   storage_location.belongs_to_receiving_warehouse  a bin that is not a live bin of `warehouseId`
 *   stock_level.received_quantity_fits             one (item, bin)'s summed lines exceed numeric(19,6)
 *
 * An id that is not an RFC 4122 UUID (braces, no hyphens, not a UUID) is
 * refused at construction with `\InvalidArgumentException` — the ids come from
 * your own rows, so that is a bug in the caller, a 500. Valid ids in any case
 * are held in canonical lower-case form.
 *
 * Another workspace's item or bin needs no check and has none: the composite
 * foreign keys refuse the item (23503, which aborts your transaction) and RLS
 * hides the bin, which is then refused as not in the warehouse.
 *
 * ---------------------------------------------------------------------------
 * Not covered (named, not built)
 * ---------------------------------------------------------------------------
 *   - **The posted-once check catches a caller bug, not a retry.** It keys on
 *     `goodsReceiptId`, which the caller mints server-side, so it refuses
 *     `receive()` twice for one receipt — for instance, calling once per line.
 *     A RETRIED request mints a new goods receipt with a new id and posts
 *     again; nothing here can see that, and nothing here ever will — **a retry
 *     is the caller's to recognise, because only the caller holds the request
 *     it is a retry of.**
 *
 *     **⚠ Corrected 2026-09-12.** This said the caller had no protection
 *     either — that `goods_receipt` carried only its primary key and a unique
 *     on the server-minted `receipt_number`, that `CreateGoodsReceipt` was
 *     `⛔ none` in `API.md`'s idempotency column, and that retry protection was
 *     coming *"(step 8)"*. All of that was already false when it was written:
 *     `Version20260911000400` (step 8-i) gave `goods_receipt` a client-supplied
 *     `idempotency_key` and a `request_digest`, both `NOT NULL`, on the unique
 *     `goods_receipt_idem_unique (tenant_id, idempotency_key)`, so the cell
 *     reads `unique:goods_receipt_idem_unique`; and step 8-ii shipped
 *     `GoodsReceiptWriter`, which reads that key under the order's row lock and
 *     answers a repeat with the first receipt, or with
 *     `idempotency-key-conflict` when the digest differs. **So a retried
 *     `CreateGoodsReceipt` never reaches this method a second time.**
 *
 *     What the caller must do here is unchanged and is still a requirement:
 *     insert its `goods_receipt` row BEFORE calling. Its primary key then
 *     refuses a second receipt with the same id, and two transactions posting
 *     one id queue on that key instead of both passing this read.
 *   - A receipt that fits but pushes an EXISTING level past numeric(19,6)
 *     (`qty_on_hand + delta` over 13 integer digits) is not refused here: the
 *     upsert fails with 22003 and aborts your transaction. Loud, nothing
 *     half-written, and ten trillion units in one bin is not a stock level
 *     anyone holds; refusing it would need a read of every level first.
 *
 * ---------------------------------------------------------------------------
 * Deliberately accepted: an archived item. Refused: an archived warehouse.
 * ---------------------------------------------------------------------------
 * An archived `inventory_item` still takes stock in. SCHEMA §7 soft-deletes
 * items precisely because order lines and movements keep referencing them
 * after archival, and goods ordered before an item was archived still arrive
 * at the dock — refusing them would leave a physical delivery with no way to
 * be booked. An archived WAREHOUSE is refused (`warehouse.is_live`): archiving
 * a warehouse closes the site, stock cannot arrive at a place that no longer
 * operates, and its bins are not archived with it. An order still open
 * against a closed site has to be redirected or cancelled — a product
 * decision, not a stock one. (Decided at the step 7 review; one predicate to
 * reverse if the product rules otherwise.)
 */
interface ReceiveStock
{
    /**
     * @throws StockReceiptRefused when the receipt cannot be taken in, for a reason the caller translates
     * @throws NoCallerTransaction when called with no transaction open (W-2) — a bug in the caller, a 500
     *
     * (`\InvalidArgumentException` for a non-RFC-4122 id is thrown earlier, by
     * the `StockReceipt` / `StockReceiptLine` constructors.)
     */
    public function receive(StockReceipt $receipt): void;
}
