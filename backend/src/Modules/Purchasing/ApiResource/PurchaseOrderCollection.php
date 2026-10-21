<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Purchasing\ApiResource\State\PurchaseOrderCollectionProvider;
use App\Modules\Purchasing\Application\PurchaseOrderViews;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * API-22 — the list envelope, and `ListPurchaseOrders`.
 *
 * ---------------------------------------------------------------------------
 * Why `Get` on `/purchase-orders` rather than `GetCollection`
 * ---------------------------------------------------------------------------
 * The reason `ItemCollection` gives: `GetCollection` normalises through API
 * Platform's collection pipeline, whose job is to wrap a page in Hydra's
 * `hydra:view`/`hydra:totalItems` or a bare array. API-22 declares a *named
 * object* with `next_cursor`, `previous_cursor` and `tenant_has_rows`, and TB-2b
 * forbids the total that pipeline exists to compute.
 *
 * ---------------------------------------------------------------------------
 * The parameter set is §4.2's cell, and `archived` is offered since order restores C3
 * ---------------------------------------------------------------------------
 * §4.2's `Query parameters` cell for this row reads `status`; `supplier_id`; `archived`; `q`; `sort`; `limit`;
 * `after`│`before`, and the column is exhaustive (API-19b).
 *
 * **`archived`** (order restores C3, 2026-10-21): API-19c limb 1 offers it because `IA.md` §5.6's `/purchase-orders`
 * Archived row is no longer `⛔ D-a` — `purchase_order_tenant_archived_idx` serves it. Until then it was absent by rule
 * (both limbs failed). `RestorePurchaseOrder` ships in the same commit (E9-3's twin,
 * `PurchaseOrderOperationContractTest::theArchivedIdProblemIsWhyNoRestoreShips`, is bidirectional). The Archived view is
 * the archived drafts instead of the live orders, newest first, never combined with `status` or `supplier_id`.
 *
 * ---------------------------------------------------------------------------
 * `status` is a **segment**, not a raw status value (§10.3 derivation step 2)
 * ---------------------------------------------------------------------------
 * *"a client that had to know which two `Open` covers would be carrying the state
 * machine."* The four names are §4.2's own — `open`, `draft`, `received`,
 * `cancelled` — and `PurchaseOrderViews::SEGMENTS` is the one place
 * they expand into `purchase_order.status` values.
 *
 * ---------------------------------------------------------------------------
 * `sort` is offered, and which values are legal depends on the view (API-19)
 * ---------------------------------------------------------------------------
 * TB-1's whole content is that sortability is a property of a **(list, view)
 * pair**, because the ordering indexes are partial. The manifest offers
 * `order_number` on every live `/purchase-orders` row and `ordered_at` on two of
 * them. `PurchaseOrderViews::legalSorts()` is the derivation, with
 * the one deviation from the manifest written out and argued there.
 */
#[ApiResource(
    shortName: 'PurchaseOrderCollection',
    operations: [
        new Get(
            uriTemplate: '/purchase-orders',
            name: 'ListPurchaseOrders',
            openapi: new OpenApiOperation(operationId: 'ListPurchaseOrders'),
            provider: PurchaseOrderCollectionProvider::class,
            parameters: [
                'status' => new QueryParameter(
                    description: 'The list segment. §10.3 derivation step 2: the manifest\'s own segment names for this route, never the entity\'s raw STATUS_* values. `open` covers `ordered` and `partially_received`.',
                    schema: ['type' => 'string', 'enum' => PurchaseOrderViews::SEGMENT_NAMES],
                ),
                'supplier_id' => new QueryParameter(
                    description: 'Restrict to one supplier. The sort manifest\'s "/purchase-orders filtered to a supplier" predicate, served by purchase_order_tenant_supplier_idx.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'archived' => new QueryParameter(
                    description: 'Return the archived (deleted) orders instead of the live ones (TB-3), most recently archived first. API-19c limb 1: IA §5.6 has a /purchase-orders Archived view (order restores C3). Not combinable with status or supplier_id.',
                    schema: ['type' => 'boolean', 'default' => false],
                ),
                'q' => new QueryParameter(
                    description: 'Free-text search. API-19d/TB-5 name one searchable column on this table, purchase_order.order_number, and it is the only column searched.',
                    schema: ['type' => 'string'],
                ),
                'sort' => new QueryParameter(
                    description: 'The order. Legal values depend on the view (API-19): on the live views order_number always, ordered_at only where IA.md §5.6 offers it and every row in the view carries one; on the Archived view archived_at, newest first, and nothing else.',
                    schema: ['type' => 'string', 'enum' => [
                        PurchaseOrderViews::SORT_ORDER_NUMBER,
                        PurchaseOrderViews::SORT_ORDERED_AT,
                        PurchaseOrderViews::SORT_ARCHIVED_AT,
                    ]],
                ),
                'limit' => new QueryParameter(
                    description: 'Page size. API-17: default 50, maximum 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => PurchaseOrderCollectionProvider::MAXIMUM_LIMIT,
                        'default' => PurchaseOrderCollectionProvider::DEFAULT_LIMIT,
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
            // §4.2's `Perm` cell, enforced before this provider runs, so a
            // caller without the code never learns whether the list would have
            // been empty (E-1, ST-4b).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'purchase_order.view'],
        ),
    ],
)]
final class PurchaseOrderCollection
{
    /**
     * @param list<PurchaseOrderResource> $items
     */
    public function __construct(
        // `phpstan/phpdoc-parser` is not installed, so nothing reads the generic
        // in the docblock above and the generated schema would type the members
        // `string|null`. The reference is written out instead, and
        // `OpenApiContractTest::everyReferenceInTheSpecResolves` fails if the
        // component it names ever stops existing.
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PurchaseOrder']])]
        public array $items,
        /** Null on the last page and only there (API-22). Passed back as `after`. */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        /** Passed back as `before`. */
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /**
         * ST-2's discriminator, and this list is the one §4.4.2 uses to explain
         * why it cannot be decided from `items` alone: *"every document list
         * opens on a status segment — `/purchase-orders` opens on Open — so a
         * filter is always set and every empty list would read as filtered."*
         * Computed only when `items` is empty.
         */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}
