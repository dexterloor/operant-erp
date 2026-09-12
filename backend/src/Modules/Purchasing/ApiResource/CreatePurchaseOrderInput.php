<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreatePurchaseOrder`'s accepted request fields.
 *
 * ---------------------------------------------------------------------------
 * There is no `id`, and there is no `idempotency_key` either
 * ---------------------------------------------------------------------------
 * **No `id`** is B-3 / D-12, exactly as `CreateItemInput` states it: the row
 * identifier is minted server-side by `Uuid::v4()`.
 *
 * **No `idempotency_key`** is narrower and is the whole of why this operation's
 * retry key works. Its SHA-256 (D-B) lands in `purchase_order.idempotency_key` and
 * collides on `purchase_order_tenant_idem_live`, but it arrives in the
 * `Idempotency-Key` **header** — never in this body. One value, one source: a
 * caller able to send it in both could send two different ones, and nothing
 * would decide which identifies the retry.
 * `IdempotencyContractTest::everyHeaderSourcedKeyIsAbsentFromTheAcceptedFieldsAndDemandedByTheContract`
 * is the clause that fails if this class ever grows the field.
 *
 * ---------------------------------------------------------------------------
 * `lines[]` — the nested create, built at step 8a (D-C)
 * ---------------------------------------------------------------------------
 * §4.2 names `lines[].inventory_item_id`, `lines[].quantity`,
 * `lines[].unit_cost` and `lines[].expected_at` on this row. **Three of the four
 * are accepted; `expected_at` is not, and it is the same subtraction
 * `CreatePurchaseOrderLine` already makes** — `purchase_order_line` has no such
 * column, and
 * `PurchaseOrderOperationContractTest::lineExpectedAtIsAcceptedIfAndOnlyIfItHasAColumn`
 * is the tie that makes the day the column arrives a red suite. It now covers
 * this operation's nested set as well as the two line writes.
 *
 * **This section used to record the shortfall and its cost.** Until 2026-09-12
 * the field was refused loudly and an order and its lines were therefore not
 * atomic: a create that succeeded followed by a line create that failed left a
 * headless draft. D-C ruled the nested create rather than the deferral, and
 * `PurchaseOrderWriter::create()` now writes both in one transaction — all of it
 * or none of it.
 *
 * **`lines` stays optional**, because F-2 steps 2–6 remain legal: a draft may be
 * raised empty and filled through `CreatePurchaseOrderLine`. What is refused is
 * an **empty list** — `lines: []` says nothing that omitting the field does not
 * say, and admitting it would put an empty list into the request digest, where
 * canonical JSON cannot tell `[]` from `{}`. 6a's approval refusal is what stops
 * an empty order being placed.
 *
 * **The rules are `PurchaseOrderWriter::createLine()`'s and there is no second
 * copy of them:** both paths mint `line_number` from the position in the list and
 * snapshot `lead_time_days` from the supplier's catalogue through one shared
 * method. A second copy of a snapshot rule is how the two drift.
 */
final class CreatePurchaseOrderInput
{
    #[SerializedName('supplier_id')]
    public ?string $supplierId = null;

    #[SerializedName('warehouse_id')]
    public ?string $warehouseId = null;

    /**
     * §4.2 accepts it, and `purchase_order.currency_code` is `NOT NULL` with no
     * default — `Version20260906000100` dropped the defaults on this column
     * family precisely so a row could not be created *"with no stated currency
     * and be silently priced in euros"*.
     *
     * Absent, it falls back to the **workspace's** functional currency from
     * `tenant_settings`, read inside the tenant transaction, which is E4-3's
     * ruling and `ItemWriter`'s existing route to the same value. There is no
     * `?? 'EUR'` anywhere on that path, so a workspace with no settings row
     * cannot create an order — which is the refusal E4-3 exists to produce.
     */
    #[SerializedName('currency_code')]
    public ?string $currencyCode = null;

    /** RFC 3339. Nullable in the schema: an order may be raised with no expected date. */
    #[SerializedName('expected_at')]
    public ?string $expectedAt = null;

    /**
     * The order's lines, as the decoded JSON array, checked entry by entry by
     * `PurchaseOrderWriter`.
     *
     * Typed `?array` rather than a list of a nested input class for
     * `CreateGoodsReceiptInput`'s reason: the writer refuses an unknown key on a
     * line as API-9 refuses one at the top level, which keeps every pointer
     * (`/lines/0/quantity`) in the one place that also knows which supplier
     * catalogue a refusal is about.
     *
     * **An absent field and an empty list are not the same thing.** Absent is
     * F-2's empty draft and is accepted; `[]` is refused. See the class docblock.
     *
     * ⚠ **An explicit `"lines": null` IS the same thing as absent, by design.**
     * This property cannot tell the two apart — that is what `?array $lines =
     * null` means — so `{…}` and `{…,"lines":null}` are one request and one
     * digest, and a retry that switches between them replays rather than
     * conflicting. That is `RequestDigest`'s own stated rule (*"nulls are
     * explicit: absent and null digest alike exactly when the input object
     * cannot tell them apart"*) applied rather than an accident of typing, and
     * it is the right answer: both spellings ask for an order with no lines.
     * `PurchaseOrderCreateTest::anExplicitNullLinesIsTheSameRequestAsOmittingIt`
     * pins it, so a later change that starts distinguishing them has to say so.
     *
     * @var array<mixed>|null
     */
    public ?array $lines = null;
}
