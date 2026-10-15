<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Inventory\ApiResource\State\StockMovementCollectionProvider;
use App\Modules\Inventory\Application\StockMovementListCriteria;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * API-22 — the list envelope, and **`ListStockMovements`** (stock reads C2; `docs/session13/stock-reads-design.md` §2.3).
 * `Get` on `/stock-movements`, not `GetCollection`, for `FulfilmentCollection`'s reason (a named envelope, no total).
 *
 * **The parameter set is §4.2's cell** — `inventory_item_id`; `limit`; `after`│`before`. **No `sort`:** the cell declared
 * it and was corrected in this commit (`API.md` §14.2 item 29): `IA.md` §5.6 gives both `/stock-movements` rows one order
 * and no alternate, §10.3 step 4 adds `sort` only where one exists, so `?sort` answers `undeclared_parameter`. **No
 * `archived`:** `stock_movement` is deletion class `none`.
 *
 * **Newest first by business time** (`UX` confirmed 2026-10-14): `occurred_at DESC, id DESC`, in both views — a back-dated
 * receipt lands at its date, not at the top. Each row is `GetStockMovement`'s document.
 */
#[ApiResource(
    shortName: 'StockMovementCollection',
    operations: [
        new Get(
            uriTemplate: '/stock-movements',
            name: 'ListStockMovements',
            openapi: new OpenApiOperation(operationId: 'ListStockMovements'),
            provider: StockMovementCollectionProvider::class,
            parameters: [
                'inventory_item_id' => new QueryParameter(
                    description: 'Restrict to one item\'s movements, newest first. The sort manifest\'s "/stock-movements filtered to an item" predicate, served by stock_movement_tenant_item_occurred_id_idx. An item this workspace cannot see matches nothing: the answer is an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'limit' => new QueryParameter(
                    description: 'Page size. API-17: default 50, maximum 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => StockMovementListCriteria::MAXIMUM_LIMIT,
                        'default' => StockMovementListCriteria::DEFAULT_LIMIT,
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
            // §4.2's `Perm` cell, enforced before the provider runs (E-1).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'stock_movement.view'],
        ),
    ],
)]
final class StockMovementCollection
{
    /**
     * @param list<StockMovementResource> $items
     */
    public function __construct(
        // A `$ref`: `GetStockMovement` is built in the same commit and mints the `StockMovement` component.
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/StockMovement']])]
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
