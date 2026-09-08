<?php

declare(strict_types=1);

namespace App\Inventory\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Inventory\ApiResource\State\ItemCollectionProvider;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * API-22 — the list envelope, and `ListItems`.
 *
 * ---------------------------------------------------------------------------
 * Why `Get` on `/items` rather than `GetCollection`
 * ---------------------------------------------------------------------------
 * `GetCollection` normalises through API Platform's collection pipeline, whose
 * job is to wrap a page in the vocabulary of whichever format is active --
 * Hydra's `hydra:view`/`hydra:totalItems`, or a bare JSON array. API-22 declares
 * a *named object* with `next_cursor`, `previous_cursor` and `tenant_has_rows`,
 * and TB-2b forbids the total that pipeline exists to compute. Declaring the
 * envelope as its own resource with one `Get` makes the response shape the
 * schema, so the generated spec documents the members a client actually has to
 * read rather than a collection wrapper this contract does not use.
 *
 * The `Query parameters` cell of §4.2's `ListItems` row is exhaustive, and the
 * six parameters below are that cell. `archived` is deliberately absent:
 * API-19c offers it only where the sort manifest gives the route an archived row
 * that is not `⛔`, or where the list is a bounded sub-list of a `soft` table.
 * `/items` Archived is `⛔ D-a` and `/items` is not bounded, so neither limb is
 * satisfied.
 */
#[ApiResource(
    shortName: 'ItemCollection',
    operations: [
        new Get(
            uriTemplate: '/items',
            name: 'ListItems',
            openapi: new OpenApiOperation(operationId: 'ListItems'),
            provider: ItemCollectionProvider::class,
            parameters: [
                'supplier_id' => new QueryParameter(
                    description: 'Restrict to one supplier. The sort manifest\'s "/items filtered to a supplier" predicate.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'q' => new QueryParameter(
                    description: 'Free-text search. TB-5 names exactly one searchable column on this table, inventory_item.sku; inventory_item.name is not searched (D-b).',
                    schema: ['type' => 'string'],
                ),
                'sort' => new QueryParameter(
                    description: 'The sort manifest offers one order for /items.',
                    schema: ['type' => 'string', 'enum' => [ItemCollectionProvider::SORT_SKU]],
                ),
                'limit' => new QueryParameter(
                    description: 'Page size. API-17: default 50, maximum 200.',
                    // The bounds are the provider's own constants, so API
                    // Platform's parameter validator and the provider's
                    // fallback check cannot disagree about what 200 means.
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => ItemCollectionProvider::MAXIMUM_LIMIT,
                        'default' => ItemCollectionProvider::DEFAULT_LIMIT,
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
        ),
    ],
)]
final class ItemCollection
{
    /**
     * @param list<ItemResource> $items
     */
    public function __construct(
        // `phpstan/phpdoc-parser` is not installed, so nothing reads the
        // generic in the docblock above and the generated schema would type the
        // members `string|null` -- a list of the wrong thing, in the artifact
        // B-3's phase-2 check reads. The reference is written out instead, and
        // `OpenApiContractTest::everyReferenceInTheSpecResolves` fails if the
        // component it names ever stops existing.
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Item']])]
        public array $items,
        /** Null on the last page and only there (API-22). Passed back as `after`. */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        /** Passed back as `before`. */
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /**
         * ST-2's discriminator, and it is not decidable from `items` alone:
         * every document list opens on a segment, so "empty" would always read
         * as "filtered" and a brand-new tenant would never be offered the
         * create action. Computed only when `items` is empty (API-22).
         */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}
