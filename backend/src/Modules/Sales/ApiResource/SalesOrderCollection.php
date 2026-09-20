<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\SalesOrderCollectionProvider;
use App\Modules\Sales\Application\SalesOrderViews;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * API-22 — the list envelope, and `ListSalesOrders`.
 *
 * ---------------------------------------------------------------------------
 * Why `Get` on `/sales-orders` rather than `GetCollection`
 * ---------------------------------------------------------------------------
 * `ItemCollection`'s reason, unchanged: `GetCollection` normalises through API
 * Platform's collection pipeline, whose job is to wrap a page in Hydra's
 * `hydra:view`/`hydra:totalItems` or a bare array. API-22 declares a *named
 * object* with `next_cursor`, `previous_cursor` and `tenant_has_rows`, and TB-2b
 * forbids the total that pipeline exists to compute.
 *
 * ---------------------------------------------------------------------------
 * The parameter set is §4.2's cell, and `archived` is absent by rule
 * ---------------------------------------------------------------------------
 * §4.2's `Query parameters` cell for this row reads `status`; `customer_id`; `q`;
 * `sort`; `limit`; `after`│`before`, and the column is exhaustive (API-19b).
 *
 * **`archived` is not offered and a green 200 on one would be red**, because
 * API-19c decides it against two sources outside this file and **both limbs
 * fail**: `IA.md` §5.6's `/sales-orders` Archived row is `⛔ D-a` (limb 1 needs a
 * row that is *not* `⛔`), and this list is not a `(bounded)` sub-list (limb 2
 * needs one). **`ListSalesOrderLines` is the row in this package that must offer
 * it** — the two are the two sides of API-19c and a copy-paste between them
 * satisfies neither.
 *
 * ---------------------------------------------------------------------------
 * `status` is a **segment**, not a raw status value (§10.3 derivation step 2)
 * ---------------------------------------------------------------------------
 * *"a client that had to know which two `Open` covers would be carrying the state
 * machine."* The four names are §4.2's own — `open`, `draft`, `fulfilled`,
 * `cancelled` — and `SalesOrderViews::SEGMENTS` is the one place they expand into
 * `sales_order.status` values.
 *
 * ---------------------------------------------------------------------------
 * `sort` is offered, and which values are legal depends on the view (API-19)
 * ---------------------------------------------------------------------------
 * `SalesOrderViews::legalSorts()` is the derivation, with **one deviation from
 * the manifest written out and argued there** — `ordered_at` is refused on the
 * Draft and Cancelled views even where the manifest's customer-filtered row
 * offers it, because on this table nothing forbids a NULL `ordered_at` on either
 * and a keyset cursor over a nullable key drops rows and can emit a cursor that
 * raises. That is the shipped defect `6fe8b7d` repaired in purchasing the day
 * before this shipped.
 */
#[ApiResource(
    shortName: 'SalesOrderCollection',
    operations: [
        new Get(
            uriTemplate: '/sales-orders',
            name: 'ListSalesOrders',
            openapi: new OpenApiOperation(operationId: 'ListSalesOrders'),
            provider: SalesOrderCollectionProvider::class,
            parameters: [
                'status' => new QueryParameter(
                    description: 'The list segment. §10.3 derivation step 2: the manifest\'s own segment names for this route, never the entity\'s raw STATUS_* values. `open` covers `confirmed` and `partially_fulfilled`.',
                    schema: ['type' => 'string', 'enum' => SalesOrderViews::SEGMENT_NAMES],
                ),
                'customer_id' => new QueryParameter(
                    description: 'Restrict to one customer. The sort manifest\'s "/sales-orders filtered to a customer" predicate, served by sales_order_tenant_customer_idx.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'q' => new QueryParameter(
                    description: 'Free-text search. API-19d/TB-5 name one searchable column on this table, sales_order.order_number, and it is the only column searched.',
                    schema: ['type' => 'string'],
                ),
                'sort' => new QueryParameter(
                    description: 'The order. Legal values depend on the view (API-19): order_number always, ordered_at only where IA.md §5.6 offers it and every row in the view carries one.',
                    schema: ['type' => 'string', 'enum' => [
                        SalesOrderViews::SORT_ORDER_NUMBER,
                        SalesOrderViews::SORT_ORDERED_AT,
                    ]],
                ),
                'limit' => new QueryParameter(
                    description: 'Page size. API-17: default 50, maximum 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => SalesOrderCollectionProvider::MAXIMUM_LIMIT,
                        'default' => SalesOrderCollectionProvider::DEFAULT_LIMIT,
                    ],
                ),
                'after' => new QueryParameter(
                    description: 'An opaque cursor from a previous response\'s next_cursor. Mutually exclusive with before.',
                    schema: ['type' => 'string'],
                ),
                'before' => new QueryParameter(
                    description: 'An opaque cursor from a previous response\'s previous_cursor. Mutually exclusive with after.',
                    schema: ['type' => 'string'],
                ),
            ],
            // §4.2's `Perm` cell, enforced before this provider runs, so a caller
            // without the code never learns whether the list would have been empty
            // (E-1, ST-4b).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'sales_order.view'],
        ),
    ],
)]
final class SalesOrderCollection
{
    /**
     * @param list<SalesOrderResource> $items
     */
    public function __construct(
        // `phpstan/phpdoc-parser` is not installed, so nothing reads the generic in
        // the docblock above and the generated schema would type the members
        // `string|null`. The reference is written out instead, and
        // `OpenApiContractTest::everyReferenceInTheSpecResolves` fails if the
        // component it names ever stops existing. **`SalesOrder` has one** because
        // `GetSalesOrder` and `CreateSalesOrder` are built and name it — which is
        // exactly what `SalesOrderLineCollection` could not rely on.
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SalesOrder']])]
        public array $items,
        /** Null on the last page and only there (API-22). Passed back as `after`. */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        /** Passed back as `before`. */
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /**
         * ST-2's discriminator, computed only when `items` is empty. §4.4.2: *"every
         * document list opens on a status segment … so a filter is always set and
         * every empty list would read as filtered"* — which is why it cannot be
         * decided from `items` alone.
         */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}
