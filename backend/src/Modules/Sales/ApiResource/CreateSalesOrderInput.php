<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateSalesOrder`'s accepted request fields — §4.2's cell verbatim:
 * `customer_id`, `warehouse_id`, `currency_code`, `ship_to_address_id`,
 * `lines[].inventory_item_id`, `lines[].quantity`, `lines[].unit_price`.
 *
 * ---------------------------------------------------------------------------
 * There is no `id`, and there is no `idempotency_key` either
 * ---------------------------------------------------------------------------
 * `CreatePurchaseOrderInput`'s two rules, for its reasons. **No `id`** is B-3 / D-12: the row identifier is
 * minted server-side by `Uuid::v4()`. **No `idempotency_key`**: its SHA-256 (D-B) lands in
 * `sales_order.idempotency_key` and collides on `sales_order_tenant_idem_live`, but it arrives in the
 * `Idempotency-Key` **header** — never in this body. One value, one source.
 * `IdempotencyContractTest::everyHeaderSourcedKeyIsAbsentFromTheAcceptedFieldsAndDemandedByTheContract` is
 * the clause that fails if this class ever grows the field.
 *
 * ---------------------------------------------------------------------------
 * `ship_to_address_id` is a **snapshot source**, not a stored reference (O-1, O-2)
 * ---------------------------------------------------------------------------
 * The order stores no address id. The four `ship_to_*` columns are copied from the named
 * `customer_address` row **at create** (owner ruling O-2, 2026-09-19), which is why `customer_address` is
 * deletion class `hard`: *"an order pointing at a live address row would silently rewrite where a past
 * shipment went every time the customer moves"* (`SchemaManifest.php:484`, `SalesOrder.php:37-42`).
 * *(Both offsets corrected by the RED exit audit, F-7: `:473` is `customer`'s unrelated ADR-007 `why`.)*
 *
 * **It is required**, and the schema says so rather than a rule: `sales_order.ship_to_country_code` is
 * `NOT NULL` with **no default** and a foreign key onto `country`, so there is no legal row without a
 * country, and the address is the only thing in this request that carries one. The other three snapshot
 * columns default to `''`; the country does not. A draft raised with no address would be a row Postgres
 * refuses, so the refusal is a named field error here instead.
 *
 * **V2-S-9 — the address must belong to `customer_id`, and be a shipping address.** One
 * `SELECT … WHERE id = ? AND customer_id = ? AND kind = 'shipping'` inside the tenant transaction
 * ({@see \App\Modules\Sales\Application\SalesOrderWriter::shipToInside()}) resolves it, proves the
 * ownership and proves the kind in one statement. It is an **application check**: a composite foreign key
 * `(tenant_id, customer_id, ship_to_address_id)` was proposed and rejected on 2026-09-19 — this schema has
 * no three-column foreign key anywhere, `customer_address` carries no unique for one to reference, and a
 * `soft` row may not reference a `hard` one (`SchemaManifest.php:70-71`). Its residual is
 * `RESIDUAL_GAPS.md` F-10, **accepted by owner ruling on 2026-09-19** with both offered mitigations
 * refused: the check runs once and leaves no residue, so nothing can detect afterwards
 * that a snapshot came from another customer's address.
 *
 * ⚠ **What this field does NOT do is appear in the response.** The read shape carries the four snapshot
 * fields, not a `ship_to_address` expansion. **That is OWNER RULING O-1 of 2026-09-19 — KEEP-AND-EXCEPT**
 * (`docs/session11/output9-sales-1-ruling.md`, section *"⚖ OWNER RULING — O-1 and F-10, 2026-09-19"*): the
 * field stays, and §4.4.3 step 1 gains a third stated exception for a create field that is a snapshot
 * source. **Writing that exception is `GetSalesOrder`'s commit**, which the ruling scopes in terms,
 * because §4.4.3 governs the read shape and `ResponseShapeTest`'s domain is GET operations.
 *
 * ---------------------------------------------------------------------------
 * `lines[]` — the nested create, D-C's shape on the sell side
 * ---------------------------------------------------------------------------
 * `lines` stays **optional**: F-5's flow raises a draft and fills it, and `CreateSalesOrderLine` is a
 * declared §4.2 row. What is refused is an **empty list** — `lines: []` says nothing that omitting the
 * field does not say, and admitting it would put an empty list into the request digest, where canonical
 * JSON cannot tell `[]` from `{}`.
 *
 * **Zero-line refusal at confirm is a different rule and is not this slice's** (V2-R-22 stays open):
 * nothing here stops an empty draft being raised, because nothing here confirms one.
 */
final class CreateSalesOrderInput
{
    #[SerializedName('customer_id')]
    public ?string $customerId = null;

    #[SerializedName('warehouse_id')]
    public ?string $warehouseId = null;

    /**
     * §4.2 accepts it, and `sales_order.currency_code` is `NOT NULL` with no default —
     * `Version20260906000100` dropped the defaults on this column family precisely so a row could not be
     * created *"with no stated currency and be silently priced in euros"*.
     *
     * Absent, it falls back to the **workspace's** functional currency from `tenant_settings`, read inside
     * the tenant transaction, which is E4-3's ruling and `ItemWriter`'s existing route to the same value.
     * There is no `?? 'EUR'` anywhere on that path, so a workspace with no settings row cannot create an
     * order — which is the refusal E4-3 exists to produce.
     *
     * ⚠ It is **not** defaulted from `customer.currency_code`, although that column exists. Nothing in any
     * document says a sales order is priced in its customer's currency, and inventing that rule here would
     * be a second pricing policy with no owner. E4-3's workspace fallback is the one that is ruled.
     */
    #[SerializedName('currency_code')]
    public ?string $currencyCode = null;

    /** The `customer_address` row whose four address fields this order copies. See the class docblock. */
    #[SerializedName('ship_to_address_id')]
    public ?string $shipToAddressId = null;

    /**
     * The order's lines, as the decoded JSON array, checked entry by entry by `SalesOrderWriter`.
     *
     * Typed `?array` rather than a list of a nested input class for `CreatePurchaseOrderInput`'s reason:
     * the writer refuses an unknown key on a line as API-9 refuses one at the top level, which keeps every
     * pointer (`/lines/0/quantity`) in the one place that also knows what a refusal is about.
     *
     * **An absent field and an empty list are not the same thing.** Absent is the empty draft and is
     * accepted; `[]` is refused as `at_least_one_line`.
     *
     * ⚠ **An explicit `"lines": null` IS the same thing as absent, by design.** This property cannot tell
     * the two apart — that is what `?array $lines = null` means — so `{…}` and `{…,"lines":null}` are one
     * request and one digest, and a retry that switches between them replays rather than conflicting. That
     * is `RequestDigest`'s own stated rule applied rather than an accident of typing.
     * `SalesOrderCreateTest::anExplicitNullLinesIsTheSameRequestAsOmittingIt` pins it.
     *
     * @var array<mixed>|null
     */
    public ?array $lines = null;
}
