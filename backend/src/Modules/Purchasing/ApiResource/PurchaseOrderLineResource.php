<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Purchasing\ApiResource\State\CreatePurchaseOrderLineProcessor;
use App\Modules\Purchasing\ApiResource\State\DeletePurchaseOrderLineProcessor;
use App\Modules\Purchasing\ApiResource\State\PurchaseOrderLineProvider;
use App\Modules\Purchasing\ApiResource\State\UpdatePurchaseOrderLineProcessor;
use App\Modules\Purchasing\Application\ItemReference;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Location\CreatedRowLocation;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `purchase_order_line`'s read shape and its three write operations.
 *
 * ---------------------------------------------------------------------------
 * The field list is derived, not chosen (API-23)
 * ---------------------------------------------------------------------------
 *   1. every field in `CreatePurchaseOrderLine`'s `Accepted request fields` cell
 *      — `inventory_item_id`, `quantity`, `unit_cost` — minus any
 *      `_id` naming a parent implied by the path. The path is
 *      `/purchase-orders/{id}/lines`, so the parent it implies is the **order**;
 *      `inventory_item_id` is not that parent, survives, and is expanded by
 *      API-23b;
 *   2. plus `id`;
 *   3. plus `archived_at`, because
 *      `SchemaManifest::TABLES['purchase_order_line']['deletion']` is `soft` —
 *      cascaded from the order by trigger, never written by this module;
 *   4. plus §4.4.3's additions row for **PurchaseOrderLine** — `line_number`,
 *      `quantity_received` and `quantity_outstanding` — and nothing else.
 *
 * `inventory_item` is `{id, sku, name}` — API-23b clause (ii), owner ruling Q-A
 * (2026-09-15) — through `ItemReference`.
 *
 * **`line_number` is a field since 2026-09-15 (owner ruling Q1(b)).** Until then
 * it was a column and not a field, recorded here as a finding. It is minted
 * server-side by `PurchaseOrderWriter` as the next number after the order's live
 * lines, and is unique among an order's **live** lines
 * (`purchase_order_line_unique`), so a discarded number is reused. API-23b clause
 * (i) makes it what every reference to a line displays.
 *
 * ---------------------------------------------------------------------------
 * A line has no expected date (owner ruling Q2, 2026-09-15)
 * ---------------------------------------------------------------------------
 * Until 2026-09-15 §4.2 accepted `expected_at` on both line writes, and
 * `purchase_order_line` never had the column; the field was refused as
 * undeclared on every path. Owner ruling Q2 ("Withdraw it") took it out of the
 * contract: the order's own `expected_at` is the one date, set by the buyer. A
 * request carrying it on a line still gets API-9's `validation-failed` naming it
 * as undeclared.
 *
 * **What the schema does have is `lead_time_days`**, snapshotted per line from
 * the supplier catalogue. It is stored, not on the wire, and read by no
 * production code. A per-line date is feature work that needs a ruling first.
 *
 * ---------------------------------------------------------------------------
 * Every write is refused unless the order is `draft` (L-5)
 * ---------------------------------------------------------------------------
 * *"For documents with a state machine the detail page is the editor only while
 * `status = 'draft'`. After the first transition the page is read-only except for
 * the transition actions themselves. The state machine, not a permission, is what
 * closes editing."* F-2 step 8 says the same from the flow's side. The refusal is
 * therefore `business-rule-violated` and not `permission-denied` — E-2's second
 * case, a rule about *this row* — and it is `PurchaseOrderWriter`'s, in one place,
 * for all three operations.
 */
#[ApiResource(
    shortName: 'PurchaseOrderLine',
    operations: [
        // API-21 branch 4: §4.2 declares operations at
        // `/purchase-orders/{id}/lines/{lineId}`, which is the row-level URI this
        // POST creates, so the answer is **201** with a `Location` naming it.
        // §4.4.1's `AssignRole` paragraph is the precedent for a `Location` whose
        // URI answers `PATCH`/`DELETE` and not `GET`: RFC 9110 makes `Location`
        // *identify* the created resource and does not promise a representation,
        // and the body carries the created line so no client has to follow it.
        new Post(
            uriTemplate: '/purchase-orders/{id}/lines',
            uriVariables: [
                'id' => new Link(fromClass: PurchaseOrderResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'CreatePurchaseOrderLine',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreatePurchaseOrderLine'),
            input: CreatePurchaseOrderLineInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreatePurchaseOrderLineProcessor::class,
            // §4.2's `Idem` cell is `unique:purchase_order_line_tenant_idem_live`
            // since V2-R-12 (2026-09-16): until then it read `no`, and a retry
            // wrote a second line and raised the order's total. The header's
            // value IS the indexed value, so API-14 as amended demands it (428
            // without one). `IdempotencyContractTest` reconciles this flag
            // against the cell.
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'purchase_order.edit',
                // The URI §4.2 declares this row at. Without it API Platform
                // derives `Location` from its own `NotExposed` fallback and
                // names `/purchase_order_lines/{id}`, which is a path that
                // answers 404 -- measured, and the reason `CreatedRowLocation`
                // exists.
                CreatedRowLocation::OPERATION_URI_TEMPLATE => '/purchase-orders/{id}/lines/{lineId}',
            ],
        ),
        new Patch(
            uriTemplate: '/purchase-orders/{id}/lines/{lineId}',
            uriVariables: [
                'id' => new Link(fromClass: PurchaseOrderResource::class, identifiers: ['id'], parameterName: 'id'),
                'lineId' => new Link(fromClass: PurchaseOrderLineResource::class, identifiers: ['id'], parameterName: 'lineId'),
            ],
            name: 'UpdatePurchaseOrderLine',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'UpdatePurchaseOrderLine'),
            input: UpdatePurchaseOrderLineInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                'api_allow_update' => false,
            ],
            provider: PurchaseOrderLineProvider::class,
            processor: UpdatePurchaseOrderLineProcessor::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'purchase_order.edit'],
        ),
        new Delete(
            uriTemplate: '/purchase-orders/{id}/lines/{lineId}',
            uriVariables: [
                'id' => new Link(fromClass: PurchaseOrderResource::class, identifiers: ['id'], parameterName: 'id'),
                'lineId' => new Link(fromClass: PurchaseOrderLineResource::class, identifiers: ['id'], parameterName: 'lineId'),
            ],
            name: 'DeletePurchaseOrderLine',
            status: 204,
            openapi: new OpenApiOperation(operationId: 'DeletePurchaseOrderLine'),
            provider: PurchaseOrderLineProvider::class,
            processor: DeletePurchaseOrderLineProcessor::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'purchase_order.edit'],
        ),
    ],
)]
final class PurchaseOrderLineResource
{
    /**
     * @param string $quantity            numeric(19,6) — carried as a decimal string
     * @param string $unitCost            numeric(19,4) — money, never a float
     * @param string $quantityReceived    numeric(19,6)
     * @param string $quantityOutstanding numeric(19,6), derived — see below
     */
    public function __construct(
        public string $id,
        #[SerializedName('line_number')]
        public int $lineNumber,
        #[SerializedName('inventory_item')]
        public ItemReference $inventoryItem,
        /**
         * The column is `purchase_order_line.quantity_ordered`; the field is
         * `quantity`, because that is what `CreatePurchaseOrderLine`'s accepted
         * cell names. §4.4.3's `SalesOrderLine` row makes the same point from the
         * other side — *"the purchase side calls the same thing
         * `quantity_ordered`, and the response uses each table's own"* — for a
         * table whose column happens to match. Here it does not, and the
         * **contract's** name wins on the wire.
         */
        public string $quantity,
        #[SerializedName('unit_cost')]
        public string $unitCost,
        #[SerializedName('quantity_received')]
        public string $quantityReceived,
        /**
         * §4.4.3: *"F-3 step 2 pre-fills every outstanding line with its
         * **outstanding** quantity, not its ordered quantity, and a client that
         * has to subtract two numbers it was given will eventually subtract the
         * wrong two."*
         *
         * **Never negative, and since 2026-09-12 that is the database's doing
         * rather than the reader's.** This said over-receipt was *"a real event
         * the database deliberately does not forbid"*; step 8-ii's
         * `Version20260911000500` added
         * `purchase_order_line_not_over_received` and made it false in the same
         * commit that left the sentence here. The read no longer floors the
         * subtraction at zero — an unreachable floor that no test could
         * distinguish — and `PurchaseOrderLineRow`'s docblock records what must
         * come back if that constraint is ever dropped.
         */
        #[SerializedName('quantity_outstanding')]
        public string $quantityOutstanding,
        /**
         * Non-null iff the row is archived (ST-9). Written by `DeletePurchaseOrderLine`
         * (`PurchaseOrderWriter`) and by `purchase_order_cascade_deletion` — the order's
         * trigger — which since `Version20260921000200` archives only LIVE lines, so a
         * line discarded on its own keeps its own instant instead of the order's.
         */
        #[SerializedName('archived_at')]
        public ?string $archivedAt,
    ) {
    }
}
