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
 * retry key works. The value lands in `purchase_order.idempotency_key` and
 * collides on `purchase_order_tenant_idem_live`, but it arrives in the
 * `Idempotency-Key` **header** — never in this body. One value, one source: a
 * caller able to send it in both could send two different ones, and nothing
 * would decide which identifies the retry.
 * `IdempotencyContractTest::everyHeaderSourcedKeyIsAbsentFromTheAcceptedFieldsAndDemandedByTheContract`
 * is the clause that fails if this class ever grows the field.
 *
 * ---------------------------------------------------------------------------
 * ⚠ `lines[]` is in §4.2's accepted cell and is NOT accepted here
 * ---------------------------------------------------------------------------
 * §4.2 names `lines[].inventory_item_id`, `lines[].quantity`,
 * `lines[].unit_cost` and `lines[].expected_at` on this row. They are not
 * implemented, and the field is **refused loudly** rather than accepted and
 * discarded — a request carrying `lines` gets API-9's `validation-failed`
 * naming it as undeclared, which is the same treatment `expected_at` gets on a
 * purchase order line for the same reason.
 *
 * **This is a reported shortfall, not a derivation.** Three things make it the
 * defensible half of the choice and none of them makes it complete:
 *
 *   - F-2 steps 2–6 are a draft created first and lines added to it afterwards,
 *     through `CreatePurchaseOrderLine`, which ships and is tested;
 *   - a nested create would have to mint `line_number` and snapshot
 *     `lead_time_days` per line inside this operation, duplicating the logic
 *     `PurchaseOrderWriter::createLine()` already owns — and a second copy of a
 *     snapshot rule is how the two drift;
 *   - `PurchaseOrderResource`'s own docblock already argues that the `lines[]`
 *     entries are **`PurchaseOrderLine`'s** nested create rather than fields of
 *     this resource, and records that the derivation admits both readings.
 *
 * What it costs is real and is stated: a client cannot raise an order and its
 * lines in one request, so the two are not atomic, and a create that succeeds
 * followed by a line create that fails leaves an empty draft. **`expected_at`,
 * which §4.2 accepts at both levels, is accepted here** — the order carries the
 * column; the line does not.
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
}
