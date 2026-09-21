<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\SalesOrderLineCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `ListSalesOrderLines` — and §4.2 marks it **`*(bounded)*`**, which decides both
 * its parameter list and its envelope.
 *
 * ---------------------------------------------------------------------------
 * Bounded (TB-1c), and what that changes
 * ---------------------------------------------------------------------------
 * §4.4.2: *"A `(bounded)` list carries `next_cursor: null` and
 * `previous_cursor: null` always, because the whole set is in `items`. It is the
 * **same envelope**, not a second shape."*
 *
 * So there is **no `limit`, no `after`, no `before` and no `sort`**: §10.3
 * derivation step 4 adds the paging parameters *"on every list that is not
 * `(bounded)`"*, and §10.2's exclusion 2 takes a `(bounded, TB-1c)` row out of
 * `sort`'s domain on **both** sides of `SortManifestTest`'s comparison —
 * `IA.md` §5.6 has no row for this route at all, which is also why limb 1 of
 * API-19c could not decide `archived` here.
 *
 * Rows come back in `line_number` order, which is the order
 * `sales_order_line_unique (tenant_id, sales_order_id, line_number) WHERE
 * deleted_at IS NULL` already maintains. That is not a `sort` control; it is the
 * one order a bounded set is loaded in.
 *
 * ---------------------------------------------------------------------------
 * `archived` IS offered here, on API-19c's second limb
 * ---------------------------------------------------------------------------
 * API-19c names this operation: *"`ListStorageLocations`, `ListPurchaseOrderLines`
 * and `ListSalesOrderLines` are bounded sub-lists … their tables are all
 * `SchemaManifest::SOFT`"*. Both halves are facts, not recollections:
 * `SchemaManifest::TABLES['sales_order_line']['deletion']` is `self::SOFT`, and
 * `sales_order_line.deleted_at` exists on the live table with
 * `sales_order_line_unique` **partial** on it.
 *
 * ⚠ *(The `SalesOrderLine` entity's class docblock asserted the opposite —
 * *"No `deleted_at` … `sales_order_line_unique` stays total"* — from the day it
 * was written until 2026-09-21, while the attribute nine lines below it declared
 * the index partial. It was corrected in the commit that built this operation,
 * because it is the one sentence a reader would use to decide this very
 * question.)*
 *
 * **The one list in this package that must NOT offer `archived` is
 * `ListSalesOrders`** — the two rows are the two sides of API-19c and a
 * copy-paste between them satisfies neither.
 */
#[ApiResource(
    shortName: 'SalesOrderLineCollection',
    operations: [
        new Get(
            uriTemplate: '/sales-orders/{id}/lines',
            uriVariables: [
                'id' => new Link(fromClass: SalesOrderResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'ListSalesOrderLines',
            openapi: new OpenApiOperation(operationId: 'ListSalesOrderLines'),
            provider: SalesOrderLineCollectionProvider::class,
            parameters: [
                'archived' => new QueryParameter(
                    description: 'Return the archived lines instead of the live ones. API-19c limb 2: a bounded sub-list of a SchemaManifest::SOFT table.',
                    schema: ['type' => 'boolean', 'default' => false],
                ),
            ],
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'sales_order.view'],
        ),
    ],
)]
final class SalesOrderLineCollection
{
    /**
     * @param list<SalesOrderLineResource> $items
     */
    public function __construct(
        // `phpstan/phpdoc-parser` is not installed, so nothing reads the generic in
        // the docblock above and the generated schema would type the members
        // `string|null`. A `$ref` is written instead.
        //
        // ⚠ **This was an inline shape until 2026-09-21, and the swap is the point.**
        // API Platform mints a component from an operation's input or output, and
        // every §4.2 write on `SalesOrderLine` was unbuilt — so a `$ref` dangled and
        // `SalesOrderLineResource::WIRE_SCHEMA` carried the shape by hand, with a
        // reflection test holding the two spellings equal. `CreateSalesOrderLine`
        // ships in that same commit, the component exists from it on, and the
        // constant is deleted. `OpenApiContractTest::everyReferenceInTheSpecResolves`
        // is what fails if this reference ever dangles again.
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SalesOrderLine']])]
        public array $items,
        /** Always null on a bounded list (§4.4.2), and it is the same member a paged list carries. */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /**
         * ST-2's discriminator. API-22 fixes the probe as an `EXISTS` against
         * **the resource's table** under RLS with no filter predicate and no
         * ordering, so it answers *"does this workspace have any sales order lines
         * at all"* and not *"does this order have any"* — the second question is
         * already answered by `items` being empty, which is what makes it the
         * wrong discriminator.
         */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}
