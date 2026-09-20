<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `sales_order_line`'s read shape. **Read only today** — §4.2 declares four
 * writes on this resource (`Create`, `Update`, `Delete`, `Restore`) and none of
 * them is built, so this class carries no operation of its own and exists as the
 * schema `SalesOrderLineCollection.items` refers to.
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
final class SalesOrderLineResource
{
    /**
     * **The shape `SalesOrderLineCollection.items` publishes, written here rather
     * than as a `$ref` — and the difference from the purchase twin is a fact about
     * API Platform, not a preference.**
     *
     * `PurchaseOrderLineCollection` refers to `#/components/schemas/PurchaseOrderLine`,
     * and that component exists **because `PurchaseOrderLine` has three built write
     * operations** whose input and output API Platform walks. This resource has
     * **no built operation at all** — §4.2 declares `Create`, `Update`, `Delete` and
     * `Restore` on it and every one is `unbuilt` — so API Platform mints no
     * component for it, and a `$ref` naming one would dangle.
     * **Measured, not assumed:** with `#[ApiResource(shortName: 'SalesOrderLine')]`
     * on this class, `operations: []` and again with an explicit `NotExposed`, the
     * generated spec carried **no `SalesOrderLine` schema** and
     * `OpenApiContractTest::everyReferenceInTheSpecResolves` failed with
     * *"#/components/schemas/SalesOrderLine names no component"*. The alternative —
     * leaving `items` untyped — publishes `string|null` members, which is the defect
     * `PurchaseOrderCollection`'s own comment records `phpstan/phpdoc-parser`'s
     * absence causing.
     *
     * **This is a second spelling of the constructor below, so it is held to it by a
     * test** — `SalesOrderLineReadTest::theWireSchemaIsTheResourcesOwnMembers`
     * compares both this map's keys and the nested reference's against the classes
     * by reflection. Two spellings of one list with nothing comparing them is the
     * drift this project logs; two with a comparison is `SEGMENT_NAMES`' pattern.
     *
     * **When the first `SalesOrderLine` write ships, delete this and use a `$ref`,**
     * because the component will exist from that commit on.
     *
     * @var array<string, mixed>
     */
    public const WIRE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string'],
            'line_number' => ['type' => 'integer'],
            'inventory_item' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'sku' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                ],
            ],
            // Decimal strings, never numbers (S-12): scale 6 for the quantities,
            // scale 4 for the money. `archived_at` is nullable, which OpenAPI 3.1
            // spells as a type union -- the form API Platform itself emits for
            // `PurchaseOrderLine.archived_at` (measured).
            'quantity' => ['type' => 'string'],
            'unit_price' => ['type' => 'string'],
            'quantity_fulfilled' => ['type' => 'string'],
            'quantity_outstanding' => ['type' => 'string'],
            'archived_at' => ['type' => ['string', 'null']],
        ],
    ];

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
