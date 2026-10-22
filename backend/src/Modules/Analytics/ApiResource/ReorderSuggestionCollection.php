<?php

declare(strict_types=1);

namespace App\Modules\Analytics\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Analytics\ApiResource\State\ReorderSuggestionCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListReorderSuggestions` — `GET /api/v1/forecast/reorder`, ALL SIX codes, *(bounded)*** (remaining-ops G3-C1,
 * 2026-10-22; `API.md` §4.2's reorder ruling and F-4 step 2; design Revision 1 FINAL; RED H-2).
 *
 * **The Perm cell is a conjunction** (P-0's ALL_OF with ≥ 2 codes, byte order — final RED F-L1): F-4 step 2's four codes
 * plus `forecast.view`, **plus `purchase_order.view`** (RED H-2: the netting discloses open-PO supply through
 * `qty_on_order` and `suggested_quantity`, which none of the five entitles). The gate refuses a caller lacking any of them
 * with a 403 naming the FIRST missing one in byte order (F-L7: a caller short of three learns them one at a time). Default
 * reach: `owner`, `admin`, `buyer`, `purchasing_manager` — none of the three `invoice.view`-only holders, so PC-12's margin
 * pair is never assembled on a default role.
 *
 * **Loaded whole** (a new Low gap, the supplier catalogue's G-42 twin): no `limit`, `after`, `before` or `sort`; both
 * cursors always null (§4.4.2). Rows come back by item `sku`, then id. No query parameter is declared, so any is refused
 * before the provider runs (`UndeclaredQueryParameterRefusal`).
 */
#[ApiResource(
    shortName: 'ReorderSuggestionCollection',
    operations: [
        new Get(
            uriTemplate: '/forecast/reorder',
            name: 'ListReorderSuggestions',
            openapi: new OpenApiOperation(operationId: 'ListReorderSuggestions'),
            provider: ReorderSuggestionCollectionProvider::class,
            // §4.2's `Perm` cell, every code required, byte-ascending (P-0's declaration form).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => [
                'forecast.view',
                'inventory_item.view',
                'purchase_order.view',
                'stock_level.view',
                'supplier.view',
                'supplier_product.view',
            ]],
        ),
    ],
)]
final class ReorderSuggestionCollection
{
    /**
     * @param list<ReorderSuggestionResource> $items
     */
    public function __construct(
        // INLINE, not a `$ref`: no operation outputs a single suggestion, so no component exists to point at.
        #[ApiProperty(schema: ['type' => 'array', 'items' => ReorderSuggestionResource::WIRE_SCHEMA])]
        public array $items,
        /** Always null on a bounded list (§4.4.2). */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator (API-22): does this workspace hold any item at all — probed on an empty list. */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}
