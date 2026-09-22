<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

/**
 * Take stock out on behalf of another module — ARCHITECTURE §5.5's
 * `Sales → Inventory::DepleteStock` edge, the second of the exactly two
 * cross-module write edges W-3 permits (the other is `ReceiveStock`). Built in
 * sales step 5, commit 1 (2026-09-22), with no caller; its one caller,
 * `Sales\Application\FulfilmentWriter` (`CreateFulfilment`), landed in that
 * step's last commit, and
 * `WriteOwnershipTest::everyStockWriteContractIsCalledOnlyByItsRegisteredCaller`
 * pins it as the only one.
 *
 * ---------------------------------------------------------------------------
 * What it writes
 * ---------------------------------------------------------------------------
 * One `stock_movement` per line — `reason = 'fulfilment'`, `source_type =
 * 'fulfilment'`, `source_id = fulfilmentId`, `occurred_at = occurredAt`, and a
 * **negative** quantity (stock leaving; the line itself carries the unsigned
 * amount) — and, per distinct (item, bin), a **conditional** decrement of
 * `stock_level`: `qty_on_hand -=` the summed quantity, `version + 1`, only
 * where the level holds at least that much. Inventory executes its own write
 * and the caller never touches either table (W-1, A-13).
 *
 * `reason` and `source_type` are not parameters, for `ReceiveStock`'s reason:
 * a caller-chosen type could only make the posted-once lookup miss, and a
 * caller-chosen reason is exactly what `SECURITY_MODEL.md` E-26 forbids — the
 * literal in the statement IS the binding of reason to writer.
 *
 * ---------------------------------------------------------------------------
 * Never negative stock, and never a raw 23514
 * ---------------------------------------------------------------------------
 * `stock_level` has no `CHECK (qty_on_hand >= 0)`. With `qty_reserved` pinned
 * at 0 (model A, `RESIDUAL_GAPS.md` G-7), `stock_level_reserved_sane` is the
 * only rule in the schema refusing negative on-hand, and it refuses with a
 * `23514` that aborts the caller's transaction and names reservations. So the
 * decrement is `… WHERE … AND qty_on_hand >= ?`: a level too small, or no
 * level at all, matches no row, nothing is raised by the database, and this
 * command refuses in domain terms (`stock_level.on_hand_covers_the_depletion`)
 * with the caller's transaction still alive for a clean rollback.
 *
 * Under READ COMMITTED (the server default; nothing in `config/` changes it) a
 * second depletion of the same row blocks on the first's row lock and, when
 * the first commits, Postgres re-evaluates the `WHERE` against the committed
 * row (EvalPlanQual): it decrements from the new value or matches nothing. No
 * lost update, no 23514. `DepleteStockTest` runs that race on two connections.
 *
 * ⚠ **The predicate assumes `qty_reserved = 0` and deliberately does not name
 * the column** — naming it would redden
 * `WriteOwnershipTest::noWriteStatementUnderSrcNamesQtyReserved` (G-7's
 * tripwire) without writing it. Whoever first writes `qty_reserved` trips that
 * test, whose message says to change this predicate. Until then the CHECK turns
 * any mistake here back into a loud 23514, never into silent negative stock.
 *
 * ---------------------------------------------------------------------------
 * Pickable stock (owner ruling R2, 2026-09-22)
 * ---------------------------------------------------------------------------
 * Only a **live, non-quarantine** location of the fulfilling warehouse is
 * pickable. A bin of another warehouse (or one this workspace cannot see) is
 * `storage_location.belongs_to_fulfilling_warehouse`; a bin of this warehouse
 * that is archived or of `kind = 'quarantine'` is `storage_location.is_pickable`.
 * What counts as *promisable* (a future ATP) is not ruled here.
 *
 * ---------------------------------------------------------------------------
 * The caller's side of the contract — `ReceiveStock`'s, verbatim in substance
 * ---------------------------------------------------------------------------
 * **W-2 — call it inside your own tenancy transaction.** It opens none of its
 * own; your rollback undoes it. With no transaction open it throws
 * `NoCallerTransaction`; inside one `TenantContext` did not open it throws
 * `TenantContextViolation`.
 *
 * **W-5 — take every contended lock before calling, and issue your own
 * document rows before calling.** Inside, `stock_level` is written after every
 * movement and in ascending (inventory_item_id, storage_location_id) order —
 * the order `ReceiveStock` writes in, so a fulfilment and a receipt over the
 * same two levels queue on the first rather than each holding the row the
 * other wants next. Lock the sales order row FIRST.
 *
 * **W-4 — depth 1.** It calls no other module's command.
 *
 * ---------------------------------------------------------------------------
 * Refusals
 * ---------------------------------------------------------------------------
 * `StockDepletionRefused`, which the caller translates with its own pointers:
 *
 *   stock_movement.depletion_has_a_line               no lines (at construction)
 *   stock_movement.quantity_is_positive               a line's quantity (at construction)
 *   stock_movement.fulfilment_posted_once             this fulfilment already posted
 *   warehouse.is_live                                 `warehouseId` is archived
 *   storage_location.belongs_to_fulfilling_warehouse  a bin that is not a location of `warehouseId`
 *   storage_location.is_pickable                      a bin of `warehouseId` that is archived or quarantine
 *   stock_level.on_hand_covers_the_depletion          one (item, bin)'s summed lines exceed what it holds
 *
 * The last one names **every** line index that contributed to the (item, bin)
 * it refuses (`lineIndexes()`), because two lines for one item into one bin are
 * one decrement, and the figure the bin held **when this was checked**
 * (`onHand()`): it is read after the refused `UPDATE`, without a lock, so a
 * receipt committed in between can make it larger than the request — accepted
 * as a message-level skew (design §5.2, RED R-4(a)), not fixed with a lock.
 * A bin with no level row at all reports `0.000000`.
 *
 * An id that is not an RFC 4122 UUID is refused at construction with
 * `\InvalidArgumentException` — a bug in the caller, a 500.
 */
interface DepleteStock
{
    /**
     * @throws StockDepletionRefused when the stock cannot be taken out, for a reason the caller translates
     * @throws NoCallerTransaction   when called with no transaction open (W-2) — a bug in the caller, a 500
     */
    public function deplete(StockDepletion $depletion): void;
}
