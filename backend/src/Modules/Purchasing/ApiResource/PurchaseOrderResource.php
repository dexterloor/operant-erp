<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Purchasing\ApiResource\State\ApprovePurchaseOrderProcessor;
use App\Modules\Purchasing\ApiResource\State\CancelPurchaseOrderProcessor;
use App\Modules\Purchasing\ApiResource\State\CreatePurchaseOrderProcessor;
use App\Modules\Purchasing\ApiResource\State\DeletePurchaseOrderProcessor;
use App\Modules\Purchasing\ApiResource\State\PurchaseOrderProvider;
use App\Modules\Purchasing\ApiResource\State\RestorePurchaseOrderProcessor;
use App\Modules\Purchasing\ApiResource\State\UpdatePurchaseOrderProcessor;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `purchase_order`'s read shape and the four row-level operations this package
 * ships.
 *
 * ---------------------------------------------------------------------------
 * The field list is derived, not chosen (API-23)
 * ---------------------------------------------------------------------------
 *   1. every field in `CreatePurchaseOrder`'s `Accepted request fields` cell,
 *      minus any `_id` naming a parent implied by the path — `/purchase-orders`
 *      implies no parent, so `supplier_id` and `warehouse_id` both survive and
 *      are expanded by API-23b; `currency_code` is not an `_id` and stays a
 *      scalar; `expected_at` stays;
 *   2. plus `id`;
 *   3. plus `archived_at`, because
 *      `SchemaManifest::TABLES['purchase_order']['deletion']` is `soft`;
 *   4. plus §4.4.3's additions row for **PurchaseOrder** — `order_number`,
 *      `status`, `total_amount`, `ordered_at`, `created_by = {id, display_name}`
 *      — and nothing else.
 *
 * **`lines` is not a member of this shape, and API-23 step 1 says so** (its
 * sub-list exception, owner ruling Q1(a), 2026-09-15). `CreatePurchaseOrder`
 * accepts `lines[]` (D-C, step 8a) — `lines[].inventory_item_id`,
 * `lines[].quantity` and `lines[].unit_cost` — so an order and its lines are
 * raised in one call. But §4.2 declares `ListPurchaseOrderLines` at this
 * resource's `Get` path + `/lines`, marked *(bounded)*, and
 * `CreatePurchaseOrderLine` at that same path, and it accepts every `lines[].f`
 * the parent's cell names. So step 1 excepts `lines`: those entries are that
 * sub-list's rows, read there — API.md's *"the four bounded sub-lists are
 * operations, not an implied part of the parent"*. **The 201 to
 * `CreatePurchaseOrder` therefore carries no lines**, with `total_amount`
 * reflecting them; `ListPurchaseOrderLines` reads them back.
 * `ResponseShapeTest::everyDeclaredFieldIsDerivedOrDeclaredAsAnAddition` checks
 * this shape against that derivation.
 *
 * ---------------------------------------------------------------------------
 * Why this is a DTO and not the `PurchaseOrder` entity (API-8, P-7)
 * ---------------------------------------------------------------------------
 * The same reason `ItemResource` is: the tenancy transaction closes before
 * serialization, and after it closes a lazy load raises — RLS returns zero rows
 * to the proxy initializer. This entity has **three** tenant-scoped associations
 * (supplier, warehouse, creator) and a currency, so an entity-graph serializer
 * would have four ways to turn a correct request into a 500 at serialization
 * time. Every property below is a scalar or a fully-built value object.
 *
 * ---------------------------------------------------------------------------
 * What is NOT here, and why each is out of this package rather than forgotten
 * ---------------------------------------------------------------------------
 *   Nothing since order restores C3 (2026-10-22). `RestorePurchaseOrder` was
 *   listed here — E9-3 / API-O-11: `/purchase-orders` Archived was `⛔ D-a`, so
 *   no path yielded an archived order's id — and it shipped with
 *   `ListPurchaseOrders?archived` (below).
 */
#[ApiResource(
    shortName: 'PurchaseOrder',
    operations: [
        new Get(
            uriTemplate: '/purchase-orders/{id}',
            name: 'GetPurchaseOrder',
            openapi: new OpenApiOperation(operationId: 'GetPurchaseOrder'),
            provider: PurchaseOrderProvider::class,
            // §4.2's `Perm` cell. Enforced by `PermissionGate` on
            // `kernel.controller`, which is before this provider runs -- E-1.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'purchase_order.view'],
        ),
        // API-21 branch 4: §4.2 declares `GetPurchaseOrder` at
        // `/purchase-orders/{id}`, which is the row-level URI this POST creates,
        // so **201** with a `Location` naming it and the created resource as the
        // body. No `CreatedRowLocation` template is declared: that listener
        // exists because API Platform's own `Location` resolves to a resource's
        // *first item operation*, and here that operation is `GetPurchaseOrder`
        // — the vendor header is already the right one.
        new Post(
            uriTemplate: '/purchase-orders',
            name: 'CreatePurchaseOrder',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreatePurchaseOrder'),
            input: CreatePurchaseOrderInput::class,
            denormalizationContext: [
                // API-9: unknown fields are refused, never ignored. ⚠ **It no
                // longer refuses `lines`** — step 8a built the nested create
                // (D-C) and `CreatePurchaseOrderInput` declares the field. What
                // the deserializer cannot reach is the entries *inside* it: a
                // decoded array has no declared properties, so API-9 is applied
                // to each line by `PurchaseOrderWriter::nestedLines()`, which is
                // also what refuses an undeclared entry field such as
                // `lines[].expected_at`.
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreatePurchaseOrderProcessor::class,
            // §4.2's cell is `unique:purchase_order_tenant_idem_live`, so API-14
            // as amended **demands** the header — and here that is not a
            // formality, because the header's value IS the indexed value. The
            // column it collides on would be NULL on every request without it,
            // and `Version20260911000200`'s BEFORE INSERT trigger refuses a NULL
            // outright. `IdempotencyContractTest::theHeaderSourcedKeysAreExactlyTheHeaderColumnAndDemandTheHeader`
            // is the check that goes red if this flag is ever dropped.
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'purchase_order.create',
            ],
        ),
        new Patch(
            uriTemplate: '/purchase-orders/{id}',
            name: 'UpdatePurchaseOrder',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'UpdatePurchaseOrder'),
            input: UpdatePurchaseOrderInput::class,
            denormalizationContext: [
                // API-9: unknown fields are refused, never ignored.
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                // Without this an `id` in the body sends API Platform off to
                // resolve an IRI and the answer is a bare 400. The row is named
                // by the path and by nothing else (§3.1 case 2).
                'api_allow_update' => false,
            ],
            // Read first, so a PATCH at an id no row answers is the same
            // `not-found` `GetPurchaseOrder` returns (§5.3 clause 2).
            provider: PurchaseOrderProvider::class,
            processor: UpdatePurchaseOrderProcessor::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'purchase_order.edit'],
        ),
        new Delete(
            uriTemplate: '/purchase-orders/{id}',
            name: 'DeletePurchaseOrder',
            status: 204,
            openapi: new OpenApiOperation(operationId: 'DeletePurchaseOrder'),
            provider: PurchaseOrderProvider::class,
            processor: DeletePurchaseOrderProcessor::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'purchase_order.delete'],
        ),
        // **`RestorePurchaseOrder`** (order restores C3): API-21 branch 5, 200 with `GetPurchaseOrder`'s body
        // (`RestoreSalesOrder`'s declaration). `read: false`: the row is read under the writer's lock. §4.2's `Idem` is
        // `guard:purchase_order.deleted_at` — a replay hits a live row and is refused `purchase_order.is_not_archived`, so no
        // `IdempotencyKeyGuard::OPERATION_FLAG`.
        new Post(
            uriTemplate: '/purchase-orders/{id}/restoration',
            name: 'RestorePurchaseOrder',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'RestorePurchaseOrder'),
            input: false,
            deserialize: false,
            read: false,
            processor: RestorePurchaseOrderProcessor::class,
            // §4.2: `purchase_order.delete` — undoing an archive is the same authority as making one (`RestoreItem`'s reason).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'purchase_order.delete'],
        ),
        // API-21 branch 5: a POST that creates no row this contract addresses.
        // §4.4.1 names this operation in terms -- *"`ApprovePurchaseOrder`
        // creates no row, has no row-level URI to name beyond the one the caller
        // is already on, and lands in branch 5"* -- so 200 with the resource in
        // its **new** state, and no `Location`. That is what lets F-2 step 8
        // render ST-11's confirmation from the response rather than by
        // refetching.
        new Post(
            uriTemplate: '/purchase-orders/{id}/approval',
            name: 'ApprovePurchaseOrder',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'ApprovePurchaseOrder'),
            input: ApprovePurchaseOrderInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                'api_allow_update' => false,
            ],
            // `read: false` on purpose, and it is not an E-1 shortcut. The row
            // this operation acts on has to be loaded **inside** the transaction
            // that writes it -- §8.1 steps 2 through 5 are one atomic sequence,
            // and a row read by a provider before the transaction opens is a row
            // whose status and creator may both have moved by the time `place()`
            // runs. The permission check has already happened either way: it is
            // on `kernel.controller`, ahead of every state class.
            read: false,
            processor: ApprovePurchaseOrderProcessor::class,
            // §4.2's cell is `guard:purchase_order.status`, and that mechanism is
            // what makes this operation shippable without the key store: a replay
            // hits an order that is no longer `draft`, `place()`'s guard refuses
            // it, and the caller is told `business-rule-violated` -- which is how
            // it learns the first attempt committed. **E9-2 does not bite here**:
            // the operation has a mechanism, which is the property that gate is
            // actually about.
            //
            // ⚠ **No `IdempotencyKeyGuard::OPERATION_FLAG`.** API-14 as amended
            // demands the header for `store` and `unique:` only. A `guard:`
            // operation refuses its own repeat whether or not a header arrives,
            // so requiring one bought nothing and 428-ing without one was a
            // refusal with no purpose. `IdempotencyContractTest` reconciles this
            // flag against the cell.
            extraProperties: [
                PermissionGate::OPERATION_PERMISSION => 'purchase_order.approve',
            ],
        ),
        // API-21 branch 5, for `ApprovePurchaseOrder`'s reason: a cancellation
        // creates no row and has no row-level URI to name beyond the one the
        // caller is already on. 200 with the resource in its **new** state.
        new Post(
            uriTemplate: '/purchase-orders/{id}/cancellation',
            name: 'CancelPurchaseOrder',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'CancelPurchaseOrder'),
            input: CancelPurchaseOrderInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                'api_allow_update' => false,
            ],
            // `read: false` for the reason `ApprovePurchaseOrder` declares it:
            // E-19 fixes an order whose steps 2 to 4 are one atomic sequence, and
            // a row read by a provider before the transaction opens is a row
            // whose status and creator may both have moved by the time `cancel()`
            // runs. The permission check has already happened either way — it is
            // on `kernel.controller`, ahead of every state class.
            read: false,
            processor: CancelPurchaseOrderProcessor::class,
            // §4.2's cell is `guard:purchase_order.status`, and **no
            // `IdempotencyKeyGuard::OPERATION_FLAG`**: API-14 as amended demands
            // the header for `store` and `unique:` only. A replayed cancellation
            // hits an order that is already `cancelled`, `cancel()`'s guard
            // refuses it, and `business-rule-violated` is how the caller learns
            // the first attempt committed — so a 428 would refuse a correct
            // request and protect nothing.
            extraProperties: [
                PermissionGate::OPERATION_PERMISSION => 'purchase_order.cancel',
            ],
        ),
    ],
)]
final class PurchaseOrderResource
{
    /**
     * @param string $totalAmount numeric(19,4) — money, carried as a decimal string
     */
    public function __construct(
        public string $id,
        public NamedReference $supplier,
        public NamedReference $warehouse,
        #[SerializedName('currency_code')]
        public string $currencyCode,
        /** RFC 3339, or null while the order carries no expected date. */
        #[SerializedName('expected_at')]
        public ?string $expectedAt,
        #[SerializedName('order_number')]
        public string $orderNumber,
        /** One of `PurchaseOrder::STATUS_*`. */
        public string $status,
        #[SerializedName('total_amount')]
        public string $totalAmount,
        /** Null exactly while the order is `draft` (`purchase_order_ordered_at_set`). */
        #[SerializedName('ordered_at')]
        public ?string $orderedAt,
        /**
         * DSoD-1's input, and F-2 step 8's *"recorded against this manager by
         * name"*. **Null is a real value and not a gap:** a purchase order
         * raised by the reorder agent (F-4) has no human author, and E-19 rules
         * that a NULL creator does not refuse approval.
         */
        #[SerializedName('created_by')]
        public ?ActorReference $createdBy,
        /** Non-null iff the row is archived (ST-9). */
        #[SerializedName('archived_at')]
        public ?string $archivedAt,
    ) {
    }
}
