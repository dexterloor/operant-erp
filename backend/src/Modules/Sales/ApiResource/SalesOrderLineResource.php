<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\CreateSalesOrderLineProcessor;
use App\Modules\Sales\ApiResource\State\SalesOrderLineProvider;
use App\Modules\Sales\ApiResource\State\UpdateSalesOrderLineProcessor;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Location\CreatedRowLocation;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `sales_order_line`'s read shape and its write operations.
 *
 * ⚠ *(This class read **"Read only today — §4.2 declares four writes on this
 * resource and none of them is built, so this class carries no operation of its
 * own"** until 2026-09-21, when `CreateSalesOrderLine` shipped. **The consequence
 * was not confined to that sentence**, which is why the reversal is recorded here
 * rather than only in a commit message: a `WIRE_SCHEMA` constant published this
 * shape inline **because** no built operation meant API Platform minted no
 * `SalesOrderLine` component and a `$ref` naming one would dangle. That was
 * measured, not assumed, and it stopped being true in the same commit — so the
 * constant is deleted, `SalesOrderLineCollection.items` points at
 * `#/components/schemas/SalesOrderLine`, and
 * `SalesOrderLineReadTest::theWireSchemaIsTheResourcesOwnMembers` now holds the
 * **generated component** to this class's members instead of holding two
 * hand-written spellings to each other. Three places, one decision, reversed
 * together — which is what the constant's own docblock pre-announced.)*
 *
 * ---------------------------------------------------------------------------
 * The field list is derived, not chosen (API-23)
 * ---------------------------------------------------------------------------
 *   1. every field in `CreateSalesOrderLine`'s `Accepted request fields` cell —
 *      `inventory_item_id`, `quantity`, `unit_price` — minus any `_id` naming a
 *      parent the path already implies. The path is `/sales-orders/{id}/lines`,
 *      so the parent it implies is the **order**; `inventory_item_id` is not that
 *      parent, survives, and is expanded by API-23b;
 *   2. plus `id`;
 *   3. plus `archived_at`, because `SchemaManifest::TABLES['sales_order_line']['deletion']`
 *      is `soft` — cascaded from the order by trigger, never written by this module;
 *   4. plus §4.4.3's additions row for **SalesOrderLine** — `line_number`,
 *      `quantity_fulfilled` and `quantity_outstanding` — and nothing else.
 *
 * Eight members, and §4.4.3 answers the one name that looks like a mismatch with
 * the purchase twin in terms: *"step 1 already yields `quantity`, which is also
 * this table's column name … the purchase side calls the same thing
 * `quantity_ordered`, and the response uses each table's own."*
 *
 * ---------------------------------------------------------------------------
 * Scales (S-12, API-23c)
 * ---------------------------------------------------------------------------
 * `quantity`, `quantity_fulfilled` and `quantity_outstanding` are
 * `numeric(19,6)` and reach the wire as decimal **strings at scale 6**;
 * `unit_price` is `numeric(19,4)`, money, scale 4. Never floats — a float here is
 * a rounding error that reaches an invoice.
 */
#[ApiResource(
    shortName: 'SalesOrderLine',
    operations: [
        // API-21 branch 4: §4.2 declares operations at `/sales-orders/{id}/lines/{lineId}`, which is the
        // row-level URI this POST creates, so the answer is **201** with a `Location` naming it. §4.4.1's
        // `AssignRole` paragraph is the precedent for a `Location` whose URI answers `PATCH`/`DELETE` and
        // not `GET`: RFC 9110 makes `Location` *identify* the created resource and does not promise a
        // representation, and the body carries the created line so no client has to follow it.
        new Post(
            uriTemplate: '/sales-orders/{id}/lines',
            uriVariables: [
                'id' => new Link(fromClass: SalesOrderResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'CreateSalesOrderLine',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateSalesOrderLine'),
            input: CreateSalesOrderLineInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateSalesOrderLineProcessor::class,
            // §4.2's `Idem` cell is `unique:sales_order_line_tenant_idem_live` since
            // `Version20260921000100`: until then it read `⛔ none` and a retry wrote a second line and
            // raised the order's total. The header's value IS the indexed value, so API-14 as amended
            // demands it (428 without one). `IdempotencyContractTest` reconciles this flag against the cell.
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'sales_order.edit',
                // The URI §4.2 declares this row at. Without it API Platform derives `Location` from its
                // own `NotExposed` fallback and names `/sales_order_lines/{id}`, a path that answers 404 --
                // measured on the purchase side, and the reason `CreatedRowLocation` exists.
                CreatedRowLocation::OPERATION_URI_TEMPLATE => '/sales-orders/{id}/lines/{lineId}',
            ],
        ),
        new Patch(
            uriTemplate: '/sales-orders/{id}/lines/{lineId}',
            uriVariables: [
                'id' => new Link(fromClass: SalesOrderResource::class, identifiers: ['id'], parameterName: 'id'),
                'lineId' => new Link(fromClass: SalesOrderLineResource::class, identifiers: ['id'], parameterName: 'lineId'),
            ],
            name: 'UpdateSalesOrderLine',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'UpdateSalesOrderLine'),
            input: UpdateSalesOrderLineInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                'api_allow_update' => false,
            ],
            provider: SalesOrderLineProvider::class,
            processor: UpdateSalesOrderLineProcessor::class,
            // No `OPERATION_FLAG`: §4.2's `Idem` cell is `no`, and API-14's answer is that a PATCH sets
            // absolute values on a row that already exists, so a replay converges and there is no
            // duplicate for a key to prevent.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'sales_order.edit'],
        ),
    ],
)]
final class SalesOrderLineResource
{
    /**
     * @param string $quantity            numeric(19,6) — carried as a decimal string
     * @param string $unitPrice           numeric(19,4) — money, never a float
     * @param string $quantityFulfilled   numeric(19,6)
     * @param string $quantityOutstanding numeric(19,6), derived — see `SalesOrderLineRow`
     */
    public function __construct(
        public string $id,
        #[SerializedName('line_number')]
        public int $lineNumber,
        #[SerializedName('inventory_item')]
        public SalesItemReference $inventoryItem,
        public string $quantity,
        #[SerializedName('unit_price')]
        public string $unitPrice,
        /**
         * Written by nothing under `src/` today — `RESIDUAL_GAPS.md` **G-7**, whose
         * trigger list names `CreateFulfilment` first. It is a real column with a
         * real default of `0`, so the field is not a placeholder; what is missing
         * is a writer.
         */
        #[SerializedName('quantity_fulfilled')]
        public string $quantityFulfilled,
        /**
         * **0 on a cancelled order, derived — owner-carried decision V2-C-14(b),
         * taken 2026-09-19 when `CancelSalesOrder` shipped first.** The expression
         * and the two traps in it are in `SalesOrderLineRow`; read that docblock
         * before touching this field.
         *
         * §4.4.3: F-5 step 4 pre-fills every outstanding line with its
         * **outstanding** quantity, and a client that has to subtract two numbers
         * it was given will eventually subtract the wrong two.
         */
        #[SerializedName('quantity_outstanding')]
        public string $quantityOutstanding,
        /**
         * Non-null iff the row is archived (ST-9). On this table it is written by
         * the sales order's cascade trigger and by nothing in PHP.
         */
        #[SerializedName('archived_at')]
        public ?string $archivedAt,
    ) {
    }
}
