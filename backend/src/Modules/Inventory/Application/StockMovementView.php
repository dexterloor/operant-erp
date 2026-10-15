<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

/**
 * One `stock_movement` row as `GetStockMovement` and `ListStockMovements` serve it (stock reads C2; `API.md` §4.4.3's
 * StockMovement row): every column the table has. `source_type` / `source_id` are the table's OWN columns, served bare
 * under `stock_movement.view` and never expanded to the document (`SEC`, 2026-10-14, Low). No actor: the table has none.
 */
final readonly class StockMovementView
{
    public function __construct(
        public string $id,
        public string $quantity,
        public string $occurredAt,
        public string $reason,
        public ?string $sourceType,
        public ?string $sourceId,
        public StockItemRef $inventoryItem,
        public StockLocationRef $storageLocation,
    ) {
    }
}
