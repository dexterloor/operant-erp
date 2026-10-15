<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Inventory\ApiResource\State\StockLevelCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListStockLevels` — `GET /api/v1/stock`, `stock_level.view`, *(bounded)*, exactly one of `storage_location_id`,
 * `inventory_item_id`** (stock reads C1; `docs/session13/stock-reads-design.md` §2.1; owner ruling Q1 of 2026-10-14).
 *
 * **Scoped, never flat** (API-19b, TB-1b): `/stock` has no flat list. Neither scope, or both, is 422 `validation-failed`
 * with `exactly_one_scope` on both pointers — refused in the provider, so the gate's 403 (E-1) and the generic
 * `undeclared_parameter` refusal both come first. An unknown or another workspace's id is the same empty page; an archived
 * item or bin is scoped like a live one and its rows are served.
 *
 * **Bounded (TB-1c)**: both cursors always null; no `limit`, `after`, `before` or `sort` (the manifest's orders are
 * client-side). The server returns a bin's rows by item `sku`, an item's rows by bin `code`, then the row id.
 *
 * Each row is `{id, inventory_item, storage_location, warehouse, qty_on_hand, version}` — **no reserved or available
 * figure** (Q1). `warehouse` is PC-9's fourth named exception (`SEC`, 2026-10-14). Quarantine and archived bins are not
 * marked (`RESIDUAL_GAPS.md` G-47).
 */
#[ApiResource(
    shortName: 'StockLevelCollection',
    operations: [
        new Get(
            uriTemplate: '/stock',
            name: 'ListStockLevels',
            openapi: new OpenApiOperation(operationId: 'ListStockLevels'),
            provider: StockLevelCollectionProvider::class,
            parameters: [
                'storage_location_id' => new QueryParameter(
                    description: 'One bin\'s stock, by item SKU. Exactly one of storage_location_id and inventory_item_id is required (API-19b). A bin this workspace cannot see matches nothing: the answer is an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'inventory_item_id' => new QueryParameter(
                    description: 'One item\'s stock, by bin code, with each bin\'s warehouse. Exactly one of storage_location_id and inventory_item_id is required (API-19b). An item this workspace cannot see matches nothing: the answer is an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
            ],
            // §4.2's `Perm` cell, enforced before the provider runs (E-1): a non-holder is 403 even with no scope.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'stock_level.view'],
        ),
    ],
)]
final class StockLevelCollection
{
    /**
     * @param list<StockLevelResource> $items
     */
    public function __construct(
        // INLINE, not a `$ref`: no operation outputs a single stock level, so no component exists to point at.
        #[ApiProperty(schema: ['type' => 'array', 'items' => StockLevelResource::WIRE_SCHEMA])]
        public array $items,
        /** Always null on a bounded list (§4.4.2). */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator (API-22): does this workspace hold any stock row at all — probed on an empty page. */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}
