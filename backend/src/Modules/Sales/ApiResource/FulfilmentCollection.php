<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\FulfilmentCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * API-22 — the list envelope, and **`ListFulfilments`** (sales step 6, commit C1).
 *
 * **`Get` on `/fulfilments`, not `GetCollection` on `FulfilmentResource`**, for `ItemCollection`'s and
 * `SalesOrderCollection`'s reason: `GetCollection` normalises through API Platform's collection pipeline, whose
 * output is Hydra's view or a bare array, and API-22 declares a named object with `next_cursor`,
 * `previous_cursor` and `tenant_has_rows` and TB-2b forbids the total that pipeline computes. *(The design's
 * §19 C1 wrote "a `GetCollection`"; the house shape is this one, and the operation, path, permission and row
 * shape are the design's.)*
 *
 * **The parameter set is §4.2's cell** — `sales_order_id`; `q`; `limit`; `after`│`before` — and the column is
 * exhaustive (API-19b). **No `sort`:** `IA.md` §5.6 gives each of this route's two views exactly one order, and
 * `/fulfilments ordered or filtered by shipped_at` is `⛔ D-d`. **No `archived`:** `fulfilment` is deletion class
 * `none`.
 *
 * **Each row is `GetFulfilment`'s document**, lines embedded (§4.4.3 step 1: `fulfilment` has no line sub-list).
 */
#[ApiResource(
    shortName: 'FulfilmentCollection',
    operations: [
        new Get(
            uriTemplate: '/fulfilments',
            name: 'ListFulfilments',
            openapi: new OpenApiOperation(operationId: 'ListFulfilments'),
            provider: FulfilmentCollectionProvider::class,
            parameters: [
                'sales_order_id' => new QueryParameter(
                    description: 'Only one order\'s shipments, newest shipment first. An order this workspace cannot see matches nothing: the answer is an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'q' => new QueryParameter(
                    description: 'Search: a prefix of the `fulfilment_number`. Nothing else is searched.',
                    schema: ['type' => 'string'],
                ),
                'limit' => new QueryParameter(
                    description: 'How many rows to return: 50 if not sent, at most 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => FulfilmentCollectionProvider::MAXIMUM_LIMIT,
                        'default' => FulfilmentCollectionProvider::DEFAULT_LIMIT,
                    ],
                ),
                'after' => new QueryParameter(
                    description: 'The `next_cursor` of the previous page, to fetch the next one. Not with `before`.',
                    schema: ['type' => 'string'],
                ),
                'before' => new QueryParameter(
                    description: 'The `previous_cursor` of a later page, to fetch the one before it. Not with `after`.',
                    schema: ['type' => 'string'],
                ),
            ],
            // §4.2's `Perm` cell, enforced before the provider runs (E-1): a caller without it learns nothing about
            // whichever order the filter names.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'fulfilment.view'],
        ),
    ],
)]
final class FulfilmentCollection
{
    /**
     * @param list<FulfilmentResource> $items
     */
    public function __construct(
        // Written out for `SalesOrderCollection`'s reason (no phpdoc parser installed). `Fulfilment` exists as a
        // component because `GetFulfilment` is built and names it.
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Fulfilment']])]
        public array $items,
        /** Null on the last page and only there (API-22). Passed back as `after`. */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        /** Passed back as `before`. */
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator, computed only when `items` is empty. */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}
