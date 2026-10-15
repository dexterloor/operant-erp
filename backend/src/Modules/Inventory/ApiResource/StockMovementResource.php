<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Inventory\ApiResource\State\StockMovementProvider;
use App\Modules\Inventory\Application\StockItemRef;
use App\Modules\Inventory\Application\StockLocationRef;
use App\Modules\Inventory\Application\StockMovementView;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`GetStockMovement` — `GET /api/v1/stock-movements/{id}`, `stock_movement.view`** (stock reads C2;
 * `docs/session13/stock-reads-design.md` §2.2). A DTO, never the entity. This operation mints the `StockMovement`
 * component, which `ListStockMovements`' `items` `$ref` (shipping the two together avoids an inline schema deleted one
 * commit later — the `StorageLocation` C1→C3 churn).
 *
 * The shape is §4.4.3's StockMovement row exactly: `quantity` (signed delta, numeric(19,6) as a string), `occurred_at`,
 * `reason`, `source_type`, `source_id`, `inventory_item = {id, sku, name}`, `storage_location = {id, code}`, plus `id`.
 * `source_type` ∈ {`goods_receipt`, `fulfilment`, `sales_return`} today, no CHECK; `source_id` is **bare**, never expanded
 * (`SEC` CONFIRMED 2026-10-14, Low). No actor (the table has none) and no `warehouse` (V2-C-12 is `StockLevel`'s). It does
 * not show an adjustment's written reason (L-7 → `ListAuditRecords`, unbuilt).
 *
 * The gate refuses before the provider loads anything (E-1). An unknown, malformed or another workspace's id is the same
 * 404 — including `/stock-movements/adjustments`, a `POST`-only path segment of the unbuilt `CreateStockAdjustment`.
 */
#[ApiResource(
    shortName: 'StockMovement',
    operations: [
        new Get(
            uriTemplate: '/stock-movements/{id}',
            name: 'GetStockMovement',
            openapi: new OpenApiOperation(operationId: 'GetStockMovement'),
            provider: StockMovementProvider::class,
            // §4.2's `Perm` cell. Enforced by `PermissionGate` on `kernel.controller`, before the provider loads anything.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'stock_movement.view'],
        ),
    ],
)]
final class StockMovementResource
{
    public function __construct(
        public string $id,
        public string $quantity,
        #[SerializedName('occurred_at')]
        public string $occurredAt,
        public string $reason,
        #[SerializedName('source_type')]
        public ?string $sourceType,
        #[SerializedName('source_id')]
        public ?string $sourceId,
        #[SerializedName('inventory_item')]
        public StockItemRef $inventoryItem,
        #[SerializedName('storage_location')]
        public StockLocationRef $storageLocation,
    ) {
    }

    public static function fromView(StockMovementView $view): self
    {
        return new self($view->id, $view->quantity, $view->occurredAt, $view->reason, $view->sourceType, $view->sourceId,
            $view->inventoryItem, $view->storageLocation);
    }
}
