<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\CreateSalesOrderProcessor;
use App\Modules\Sales\ApiResource\State\SalesOrderProvider;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `sales_order`'s read and write shape, and the two operations built so far.
 *
 * ---------------------------------------------------------------------------
 * The field list is derived, not chosen (API-23)
 * ---------------------------------------------------------------------------
 *   1. every field in `CreateSalesOrder`'s `Accepted request fields` cell, minus any `_id` naming a parent
 *      implied by the path — `/sales-orders` implies no parent, so `customer_id` and `warehouse_id` both
 *      survive and are expanded by API-23b; `currency_code` is not an `_id` and stays a scalar;
 *   2. plus `id`;
 *   3. plus `archived_at`, because `SchemaManifest::TABLES['sales_order']['deletion']` is `soft`;
 *   4. plus §4.4.3's additions row for **SalesOrder** — `order_number`, `status`, `total_amount`,
 *      `ordered_at`, `created_by = {id, display_name}`, and the four snapshotted shipping fields.
 *
 * **`lines` is not a member of this shape**, for the reason `PurchaseOrderResource` states: §4.2 declares
 * `ListSalesOrderLines` at this resource's row URI + `/lines`, marked *(bounded)*, and API-23 step 1's
 * sub-list exception (owner ruling Q1(a)) puts those entries there. **The 201 therefore carries no lines**,
 * with `total_amount` reflecting them.
 *
 * **`created_by` and `ship_to_address` are both settled, and neither is absent by default.**
 *
 *   - **`created_by` — RULED, and it ships.** B1N-2 was ruled on 2026-09-19 on **PC-11 (4a)** ground,
 *     with **O-4** (PC-15 amendment 1 — `PurchaseOrder.created_by`) controlling and **API-O-33** the
 *     ground it applies: who raised an order is *"the subject row's own business state"*, not *"a
 *     principal's credentials, sessions or privileges"*. It is gated on the document's own
 *     `sales_order.view` and on nothing else. **The decisive reason is the one specific to this
 *     resource:** `AuditEvent::ALL` has no `sales_order.*` member at all, so with the field withheld
 *     *"who raised this order"* would be answerable only from the database — which is API-O-33's own
 *     decisive sentence, and it does **not** apply to purchase orders, where `purchase_order.placed` is
 *     a catalogue member. ⚠ **It serves no separation-of-duty control here**, and the justifications the
 *     purchasing and receipt fields carry must not be copied across: `SEC-O-9` verified this column
 *     records *"the creator, not the confirmer"*, ADR-008 names no DSoD rule on any sales document, and
 *     `IA.md` IA-O-8 states that F-5 *"has no mirror of this gap … there is no seam"*.
 *   - **`ship_to_address` — O-1 IS RULED, KEEP-AND-EXCEPT**, by the owner on 2026-09-19
 *     (`docs/session11/output9-sales-1-ruling.md`): `ship_to_address_id` stays in §4.2, this resource's
 *     input accepts it, and §4.4.3 step 1 carries a third stated exception — *a create field that is a
 *     snapshot source (an `x_id` the row copies from and does not store) derives the snapshot fields the
 *     additions row declares, not `x`*. **That exception is written as of this commit** (`API.md`
 *     §4.4.3, taught to `ResponseShapeTest`), so the four snapshot fields below are what the derivation
 *     now yields, not a holding position. The column really is absent — `sales_order` has 19 columns and
 *     none of them is `ship_to_address_id` — which is what makes the exception decidable rather than
 *     declared.
 *
 * **What reconciles this shape, now that a `GET` exists.** `ResponseShapeTest`'s two methods anchor on
 * `GET` operations, so until this commit the **field set** was checked by nothing — ruling R-A's stated
 * cost, one commit wide. `GetSalesOrder` closes it: the derived field list and this DTO are now compared
 * in both directions. The decimal **scales** were pinned throughout by `WireScaleTest`.
 *
 * ---------------------------------------------------------------------------
 * Why this is a DTO and not the `SalesOrder` entity (API-8, P-7)
 * ---------------------------------------------------------------------------
 * `PurchaseOrderResource`'s reason: the tenancy transaction closes before serialization, and after it
 * closes a lazy load raises — RLS returns zero rows to the proxy initializer. This entity has **three**
 * tenant-scoped associations and two reference-data ones, so an entity-graph serializer would have five
 * ways to turn a correct request into a 500 at serialization time.
 */
#[ApiResource(
    shortName: 'SalesOrder',
    operations: [
        new Get(
            uriTemplate: '/sales-orders/{id}',
            name: 'GetSalesOrder',
            openapi: new OpenApiOperation(operationId: 'GetSalesOrder'),
            provider: SalesOrderProvider::class,
            // §4.2's `Perm` cell. Enforced by `PermissionGate` on `kernel.controller`, which is before
            // this provider runs — E-1.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'sales_order.view'],
        ),
        // API-21 branch 4: §4.2 declares `GetSalesOrder` at `/sales-orders/{id}`, which is the row-level
        // URI this POST creates, so **201** with a `Location` naming it and the created resource as the
        // body.
        //
        // ⚠ **`CreatedRowLocation::OPERATION_URI_TEMPLATE` was declared here from 2026-09-19 until this
        // commit and is now REMOVED, deliberately and not as tidying.** API Platform's own `Location`
        // resolves to a resource's *first item operation*; with no item `GET` the vendor minted one at
        // the pluralised default path and the header named `/sales_orders/<uuid>`, a route that answers
        // 404 by construction. `GetSalesOrder` above **is** that first item operation now, so the vendor
        // header is already §4.2's own path and the listener has nothing to replace. Leaving the
        // declaration behind would not be harmless: `ResponseStatusTest`'s reconciliation of
        // `DECLARED_ROW_URIS` against §4.2 — built in this commit — holds the declaring set to exactly
        // those operations whose declared row URI the registry does **not** already serve, and this one
        // now does. `CreatedRowLocation` keeps its other user, `CreatePurchaseOrderLine`.
        new Post(
            uriTemplate: '/sales-orders',
            name: 'CreateSalesOrder',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateSalesOrder'),
            input: CreateSalesOrderInput::class,
            denormalizationContext: [
                // API-9: unknown fields are refused, never ignored. What the deserializer cannot reach is
                // the entries *inside* `lines`: a decoded array has no declared properties, so API-9 is
                // applied to each line by `SalesOrderWriter::nestedLines()`.
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateSalesOrderProcessor::class,
            extraProperties: [
                // §4.2's cell is `unique:sales_order_tenant_idem_live`, so API-14 as amended **demands**
                // the header — and here that is not a formality, because the header's value IS the indexed
                // value. `sales_order.idempotency_key` is `NOT NULL`, so a request without the header
                // cannot produce a row at all.
                // `IdempotencyContractTest::theHeaderSourcedKeysAreExactlyTheHeaderColumnAndDemandTheHeader`
                // is the check that goes red if this flag is ever dropped.
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'sales_order.create',
            ],
        ),
    ],
)]
final class SalesOrderResource
{
    /**
     * @param string $totalAmount numeric(19,4) — money, carried as a decimal string
     */
    public function __construct(
        public string $id,
        public SalesNamedReference $customer,
        public SalesNamedReference $warehouse,
        #[SerializedName('currency_code')]
        public string $currencyCode,
        #[SerializedName('order_number')]
        public string $orderNumber,
        /** One of `SalesOrder::STATUS_*`. */
        public string $status,
        #[SerializedName('total_amount')]
        public string $totalAmount,
        /** Null exactly while the order is `draft` — nothing confirms one yet, so it is null on every row. */
        #[SerializedName('ordered_at')]
        public ?string $orderedAt,
        /**
         * B1N-2 (2026-09-19), on PC-11 (4a) ground with O-4 controlling. **Null is a real value and not a
         * gap:** `sales_order.created_by_user_id` is nullable, so an order raised by anything that is not
         * a person renders as unknown rather than as a guess. **It is not an SoD input** — see the class
         * docblock and `SalesActorReference`; the purchasing field's DSoD-1 justification does not
         * transfer and asserting it would publish a falsehood.
         */
        #[SerializedName('created_by')]
        public ?SalesActorReference $createdBy,
        /**
         * The shipping snapshot, copied from `customer_address` at create (O-2). Never an expansion of a
         * live address row: `customer_address` is deletion class `hard` precisely so these four can be a
         * record of what happened rather than a view of what is currently true.
         */
        #[SerializedName('ship_to_line')]
        public string $shipToLine,
        #[SerializedName('ship_to_city')]
        public string $shipToCity,
        #[SerializedName('ship_to_postal_code')]
        public string $shipToPostalCode,
        #[SerializedName('ship_to_country_code')]
        public string $shipToCountryCode,
        /** Non-null iff the row is archived (ST-9). */
        #[SerializedName('archived_at')]
        public ?string $archivedAt,
    ) {
    }
}
