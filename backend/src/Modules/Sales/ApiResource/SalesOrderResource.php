<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\CreateSalesOrderProcessor;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Location\CreatedRowLocation;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `sales_order`'s write shape, and the **one** operation this commit ships.
 *
 * ---------------------------------------------------------------------------
 * The field list is derived, not chosen (API-23) — with two members held back
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
 * ⚠ **Two members the derivation yields are deliberately ABSENT. Neither absence may be read as a
 * decision taken here, and they are absent for two DIFFERENT reasons.**
 *
 *   - **`created_by`** — **unruled.** `SECURITY_MODEL.md` B1N-2 (owner `SEC`): *"`SalesOrder.created_by =
 *     {id, display_name}`, declared in `API.md` §4.4.3, has no PC-11 (4a) ruling, so under condition 2 it
 *     may not ship."* Exposing it in this commit would be `BE-LEAD` making `SEC`'s ruling, and avoiding
 *     exactly that is why ruling R-A put the writer before the read. The column **is** written —
 *     `created_by_user_id` comes from the JWT subject on every create, as §8.1 demands for the purchasing
 *     mirror — it is only not returned.
 *   - **`ship_to_address`** — **O-1 IS RULED.** The owner ruled **KEEP-AND-EXCEPT** on 2026-09-19
 *     (`docs/session11/output9-sales-1-ruling.md`, section *"⚖ OWNER RULING — O-1 and F-10,
 *     2026-09-19"*): `ship_to_address_id` stays in §4.2, this input accepts it, and §4.4.3 step 1 gains a
 *     third stated exception — *a create field that is a snapshot source derives the snapshot fields the
 *     additions row declares, not `x`*. So the four snapshot fields below are the ruled answer, not a
 *     holding position. **What is deferred is the WRITING of that exception**, which the ruling scopes to
 *     `GetSalesOrder`'s commit in terms, because §4.4.3 governs the read shape and `ResponseShapeTest`'s
 *     domain is GET operations. **Commit 2 must not write it.**
 *
 * **What reconciles this shape today, stated exactly rather than hand-waved.** `ResponseShapeTest`'s two
 * methods anchor on GET operations, and this resource declares none, so the **field set** is checked by
 * nothing until `GetSalesOrder` ships. Its decimal **scales** are pinned: `WireScaleTest` demands a recipe
 * for every registry operation, so `$.total_amount` lands in the committed `wire-scale.json` at its
 * column's scale. That gap is one commit wide and is ruling R-A's stated cost, not an oversight.
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
        // API-21 branch 4: §4.2 declares `GetSalesOrder` at `/sales-orders/{id}`, which is the row-level
        // URI this POST creates, so **201** with a `Location` naming it and the created resource as the
        // body.
        //
        // ⚠ **`GetSalesOrder` is not built yet, and that is exactly why the template is declared.** API
        // Platform's own `Location` resolves to a resource's *first item operation*; this resource has
        // none, so `NotExposedOperationResourceMetadataCollectionFactory` mints one at the pluralised
        // default path and the vendor header would name `/sales_orders/<uuid>`, which answers 404 by
        // construction. `CreatedRowLocation` replaces it with §4.2's own path. This is the shape
        // `CreatePurchaseOrderLine` already ships in (`PurchaseOrderLineResource:115`), and
        // `CreatedRowLocation`'s docblock names `CreateSalesOrderLine`, `CreateFulfilment`, `AssignRole`
        // and `IssueInvoice` as its other future users. When `GetSalesOrder` ships, this declaration
        // becomes redundant rather than wrong — the two mechanisms produce the same string — and the
        // commit that ships it decides whether to drop it.
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
                CreatedRowLocation::OPERATION_URI_TEMPLATE => '/sales-orders/{id}',
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
