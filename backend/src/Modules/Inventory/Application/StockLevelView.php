<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

/**
 * One `stock_level` row as `ListStockLevels` serves it (stock reads C1; owner ruling Q1 of 2026-10-14): on-hand in one
 * bin, its item, its bin, the bin's warehouse, and the row's `version`. **No reserved or available figure** — Q1, and
 * `RESIDUAL_GAPS.md` G-7: `qty_reserved` is written by nothing, so an available figure would print on-hand as promisable.
 */
final readonly class StockLevelView
{
    public function __construct(
        public string $id,
        public StockItemRef $inventoryItem,
        public StockLocationRef $storageLocation,
        public StockWarehouseRef $warehouse,
        public string $qtyOnHand,
        public int $version,
    ) {
    }
}
