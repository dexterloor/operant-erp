<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

/**
 * `CancelPurchaseOrder`'s accepted request fields (§4.2): one field, `reason`.
 *
 * ---------------------------------------------------------------------------
 * ⚠ The field §4.2 accepts, the column that does not exist, and where it goes
 * ---------------------------------------------------------------------------
 * `purchase_order` has **no free-text column at all**: fourteen columns, of
 * which `status` is a five-value `CHECK`. So a cancellation's written reason has
 * nowhere on the row to live, and this slice was chartered to close that rather
 * than to discover it — which is the difference between this field and
 * `expected_at` on a purchase order line, where the same shape was found with no
 * destination ruled and the field is therefore **refused**.
 *
 * Here the destination is ruled, in advance, by `AuditEvent::PURCHASE_ORDER_CANCELLED`'s
 * own docblock: *"The cancellation's written reason lands in
 * `audit_log.metadata`, and this is recorded here so the operation's author
 * inherits it rather than deciding it."* The precedent it cites is
 * `STOCK_MOVEMENT_ADJUSTED`, where `stock_movement` has nine columns and none is
 * free text, so F-6a's operator reason lives in `metadata` under the key `note`
 * and surfaces through L-7's History section.
 *
 * **So the field is accepted, and accepting it is the whole point.** Dropping it
 * would be the silent shape this project has now recorded finding nineteen
 * times: a documented field the server takes and discards.
 *
 * ---------------------------------------------------------------------------
 * Why it is a plain nullable property and not `SubmittedFields`
 * ---------------------------------------------------------------------------
 * The three-way `PATCH` distinction has nothing to distinguish here. `metadata`
 * is written once, at the moment of cancellation, on an append-only table no
 * role may `UPDATE` — so *"absent"* and *"sent as null"* have the same meaning
 * (**no reason was given**) and neither can later be told from the other by
 * anything that reads the row. `ApprovePurchaseOrderInput` needs the
 * distinction because `place()` assigns `expectedAt` unconditionally and the two
 * cases differ by whether a stored date survives; nothing here is overwritten.
 *
 * **It is operator-supplied free text**, which is the one value on the audit
 * trail that is not server-generated, so it is stored as a jsonb **string
 * value** and never interpolated into anything — §9.2's own rule for
 * `CreateStockAdjustment.note`.
 */
final class CancelPurchaseOrderInput
{
    /**
     * Why the order is being withdrawn, in the operator's words. Null or absent
     * means none was given, and the audit row then carries an empty `metadata`
     * rather than a key with a null in it.
     */
    public ?string $reason = null;
}
