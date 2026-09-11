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
use App\Modules\Purchasing\ApiResource\State\DeletePurchaseOrderProcessor;
use App\Modules\Purchasing\ApiResource\State\PurchaseOrderProvider;
use App\Modules\Purchasing\ApiResource\State\UpdatePurchaseOrderProcessor;
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
 * **`lines` is deliberately NOT a member of this shape, and the reason is a
 * reading of §4.4.3 rather than a convenience.** `CreatePurchaseOrder`'s accepted
 * cell names `lines[].inventory_item_id`, `lines[].quantity`, `lines[].unit_cost`
 * and `lines[].expected_at`. Those are fields of **PurchaseOrderLine**, which
 * §4.4.3 gives an additions row of its own — so it is a resource with its own
 * derived shape, reachable through `ListPurchaseOrderLines`, and the `lines[]`
 * entries in the parent's create cell are that resource's *nested create* rather
 * than fields of this one. Returning every line on every row of
 * `ListPurchaseOrders` would also put an unbounded child set inside a keyset page,
 * which is the shape TB-1c takes bounded sub-lists **out** of the parent for.
 * **This is a judgement about a derivation whose wording admits both readings and
 * it is reported as one** — a mechanical reading of step 1 produces a `lines`
 * member, and if that is what §4.4.3 means then `ResponseShapeTest` will say so
 * when it is built.
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
 *   `CreatePurchaseOrder`  — blocked on §6.2's byte-for-byte replay promise
 *                            against §6.1's columns. `LEAD` owns the ruling.
 *   `CancelPurchaseOrder`  — `PurchaseOrder::cancel()` does not exist. §8.3's
 *                            *(cancel — no method exists)* row and **X9-1** are
 *                            what find that, and adding the method here would
 *                            turn clause (b) green with the operation still
 *                            unbuilt.
 *   `RestorePurchaseOrder` — **E9-3 / API-O-11**: `/purchase-orders` Archived is
 *                            `⛔ D-a` and this list therefore offers no
 *                            `archived` parameter (API-19c, both limbs fail), so
 *                            no path yields an archived order's id to a caller
 *                            who does not already hold it. A restore nobody can
 *                            reach is not a shipped operation.
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
