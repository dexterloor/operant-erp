<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Purchasing\ApiResource\State\PurchaseOrderLineCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `ListPurchaseOrderLines` — and §4.2 marks it **`*(bounded)*`**, which decides
 * both its parameter list and its envelope.
 *
 * ---------------------------------------------------------------------------
 * Bounded (TB-1c), and what that changes
 * ---------------------------------------------------------------------------
 * §4.4.2: *"A `(bounded)` list carries `next_cursor: null` and
 * `previous_cursor: null` always, because the whole set is in `items`. It is the
 * **same envelope**, not a second shape: a client that pages correctly needs no
 * special case, and the day a bounded list outgrows TB-1c's assumption it starts
 * paging without a client change."*
 *
 * So there is **no `limit`, no `after`, no `before` and no `sort`**:
 *   - §10.3 derivation step 4 adds `limit`/`after`/`before` *"on every list that
 *     is not `(bounded)`"*, and this one is;
 *   - §10.2's exclusion 2 takes a `(bounded, TB-1c)` row out of `sort`'s domain
 *     on **both** sides of `SortManifestTest`'s comparison — *"the set is loaded
 *     whole and ordered in the client; TB-1 does not apply to it"* — and
 *     `IA.md` §5.6 has no row for this route at all, which is why limb 1 of
 *     API-19c could not decide `archived` here either.
 *
 * The rows come back in `line_number` order, which is the order
 * `purchase_order_line_unique (tenant_id, purchase_order_id, line_number)`
 * already maintains and the order the supplier's paperwork is written in. That is
 * not a `sort` control; it is the one order a bounded set is loaded in.
 *
 * ---------------------------------------------------------------------------
 * `archived` IS offered here, on API-19c's second limb
 * ---------------------------------------------------------------------------
 * *"`ListStorageLocations`, `ListPurchaseOrderLines` and `ListSalesOrderLines`
 * are bounded sub-lists... their tables are all `SchemaManifest::SOFT`, the whole
 * set is loaded in one request, and the client filters it — so `archived` here
 * selects *which* whole set is returned rather than paging a partial index, which
 * is why TB-3's ordering-index objection does not reach it."*
 *
 * Both halves of the limb are checked rather than remembered:
 * `PurchaseOrderOperationContractTest` reads the deletion class off
 * `SchemaManifest` and the `(bounded)` marking off §4.2 where the document is
 * present. **This is the one list in this package that offers `archived`, and
 * `ListPurchaseOrders` is the one that must not** — the two rows are the two
 * sides of API-19c, and a copy-paste between them would satisfy neither.
 */
#[ApiResource(
    shortName: 'PurchaseOrderLineCollection',
    operations: [
        new Get(
            uriTemplate: '/purchase-orders/{id}/lines',
            uriVariables: [
                'id' => new Link(fromClass: PurchaseOrderResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'ListPurchaseOrderLines',
            openapi: new OpenApiOperation(operationId: 'ListPurchaseOrderLines'),
            provider: PurchaseOrderLineCollectionProvider::class,
            parameters: [
                'archived' => new QueryParameter(
                    description: 'Return the archived lines instead of the live ones. API-19c limb 2: a bounded sub-list of a SchemaManifest::SOFT table.',
                    schema: ['type' => 'boolean', 'default' => false],
                ),
            ],
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'purchase_order.view'],
        ),
    ],
)]
final class PurchaseOrderLineCollection
{
    /**
     * @param list<PurchaseOrderLineResource> $items
     */
    public function __construct(
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PurchaseOrderLine']])]
        public array $items,
        /** Always null on a bounded list (§4.4.2), and it is the same member a paged list carries. */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /**
         * ST-2's discriminator. API-22 fixes the probe as an `EXISTS` against
         * **the resource's table** under RLS with no filter predicate and no
         * ordering, so it answers *"does this workspace have any purchase order
         * lines at all"* and not *"does this order have any"* — the second
         * question is already answered by `items` being empty, which is what
         * makes it the wrong discriminator.
         */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}
