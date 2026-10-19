<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Inventory\ApiResource\State\CreateStockAdjustmentProcessor;
use App\Modules\Inventory\ApiResource\State\CreateStockTransferProcessor;
use App\Modules\Inventory\ApiResource\State\StockMovementProvider;
use App\Modules\Inventory\Application\StockItemRef;
use App\Modules\Inventory\Application\StockLocationRef;
use App\Modules\Inventory\Application\StockMovementView;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
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
 * `source_type` ∈ {`goods_receipt`, `fulfilment`, `sales_return`, `stock_movement`} today — **or null, with `source_id`,
 * for an adjustment** (the stock count has no originating document; `SEC` re-rated Low unchanged, `stock-adjustment-sec.md`
 * §2). **`stock_movement` names the partner leg of a transfer** (each of a `transfer_out` / `transfer_in` pair names the
 * other; `SEC` re-rated Low unchanged 2026-10-20, `stock-transfer-sec.md` §2), and C0's `stock_movement_transfer_source`
 * CHECK ties that value to the two transfer reasons;
 * `source_id` is **bare**, never expanded (`SEC` CONFIRMED 2026-10-14, Low). No actor (the table has none) and no `warehouse` (V2-C-12 is `StockLevel`'s). It does
 * not show an adjustment's written reason (L-7 → `ListAuditRecords`, unbuilt).
 *
 * The gate refuses before the provider loads anything (E-1). An unknown, malformed or another workspace's id is the same
 * 404 — including `GET /stock-movements/adjustments`, the path of the `POST`-only `CreateStockAdjustment`.
 *
 * **`CreateStockAdjustment` — `POST /api/v1/stock-movements/adjustments`, `stock_movement.adjust`** (stock count C1,
 * 2026-10-16). Its read shape IS this resource (API-23a: `Create / StockAdjustment` answers a `StockMovement`), so it is
 * declared here and `GetStockMovement` above is the item operation its `Location` names. The processor and the writer
 * (`StockAdjuster`, through `AdjustStock`) carry the rules.
 *
 * **`CreateStockTransfer` — `POST /api/v1/stock-movements/transfers`, `stock_movement.adjust`** (stock transfer C1,
 * 2026-10-20). Same read shape (API-23a: `Create / StockTransfer` answers the OUT leg as a `StockMovement`, its
 * `source_id` naming the in leg); the writer (`StockTransferrer`, through `TransferStock`) carries the rules.
 * `GET /stock-movements/transfers` is the same 404 as `…/adjustments`.
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
        new Post(
            uriTemplate: '/stock-movements/adjustments',
            name: 'CreateStockAdjustment',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateStockAdjustment'),
            input: CreateStockAdjustmentInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateStockAdjustmentProcessor::class,
            // §4.2: `unique:stock_movement_idem_unique` (stock count C0). The header's hash IS the indexed value, so API-14
            // demands it (428 without one); a same-body retry replays the first movement.
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'stock_movement.adjust',
            ],
        ),
        new Post(
            uriTemplate: '/stock-movements/transfers',
            name: 'CreateStockTransfer',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateStockTransfer'),
            input: CreateStockTransferInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateStockTransferProcessor::class,
            // §4.2: `unique:stock_movement_idem_unique` — the out leg carries the key (the in leg NULL/NULL), so API-14
            // demands the header (428 without one); a same-body retry replays the first transfer's out leg.
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'stock_movement.adjust',
            ],
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
