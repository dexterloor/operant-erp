<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

/**
 * `CancelSalesOrder`'s accepted request fields (§4.2): one field, `reason`.
 *
 * ---------------------------------------------------------------------------
 * ⚠ The field §4.2 accepts, the column that does not exist, and where it goes
 * ---------------------------------------------------------------------------
 * `sales_order` has **no free-text column at all** — measured against the live
 * catalogue rather than inferred from the purchase analogue: nineteen columns, of
 * which `status` is a five-value `CHECK` and the only other text columns are
 * `order_number`, the four `ship_to_*` snapshot fields, and two sha256-shaped
 * idempotency columns under regex `CHECK`s. So a cancellation's written reason has
 * nowhere on the row to live.
 *
 * It lands in **`audit_log.metadata`**, on `PURCHASE_ORDER_CANCELLED`'s ruled
 * destination and `STOCK_MOVEMENT_ADJUSTED`'s precedent behind it —
 * `SalesOrderAuditWriter::recordCancelled()` writes it. **This is the same
 * position `CancelPurchaseOrderInput` documents for `purchase_order`, and the
 * same answer.**
 *
 * **So the field is accepted, and accepting it is part of why this operation
 * writes an audit row at all.** Dropping it would be the silent shape this
 * project keeps finding — a documented field the server takes and discards — and
 * `CancelPurchaseOrderInput` records that shape having been found nineteen times.
 * Unlike the purchase side, there is a second and stronger reason the row exists:
 * `ADR-008` §2 makes `audit_log` the **named compensating control** for the duty
 * split it declines on sales cancellation.
 *
 * ---------------------------------------------------------------------------
 * Why it is a plain nullable property and not `SubmittedFields`
 * ---------------------------------------------------------------------------
 * `CancelPurchaseOrderInput`'s reasoning, unchanged and for the same mechanism:
 * the three-way `PATCH` distinction has nothing to distinguish. `metadata` is
 * written once, at the moment of cancellation, on an append-only table no role
 * may `UPDATE` — so *"absent"* and *"sent as null"* have the same meaning (**no
 * reason was given**) and neither can later be told from the other by anything
 * that reads the row. Nothing here is overwritten.
 *
 * **It is operator-supplied free text**, which is the one value on the audit trail
 * that is not server-generated, so it is stored as a jsonb **string value** and
 * never interpolated into anything — §9.2's own rule for
 * `CreateStockAdjustment.note`.
 */
final class CancelSalesOrderInput
{
    /**
     * Why the order is being cancelled, in the operator's words. Null or absent
     * means none was given, and the audit row then carries an empty `metadata`
     * rather than a key with a null in it.
     */
    public ?string $reason = null;
}
