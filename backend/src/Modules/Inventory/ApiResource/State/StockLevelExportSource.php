<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource\State;

use App\Modules\Inventory\ApiResource\StockLevelCollection;
use App\Modules\Inventory\ApiResource\StockLevelResource;
use App\Modules\Inventory\Application\StockLevelReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `stock_level` source: `ListStockLevels`' current view — one bin's or one item's stock (G6-C0).
 *
 * A bounded list (TB-1c): the parse is the list's own ({@see StockLevelCollectionProvider::scopeFrom()} — exactly one of
 * `storage_location_id` / `inventory_item_id`), and the read is the list's statement with `LIMIT cap + 1` (design M-7), so
 * "over the cap" comes from the same statement as the rows.
 */
final readonly class StockLevelExportSource implements ExportSource
{
    public function __construct(private StockLevelReader $reader)
    {
    }

    public function resource(): string
    {
        return 'stock_level';
    }

    public function listResourceClass(): string
    {
        return StockLevelCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListStockLevels';
    }

    public function rowClass(): string
    {
        return StockLevelResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        [$scope, $id] = StockLevelCollectionProvider::scopeFrom($filters);

        return function () use ($scope, $id, $cap): ExportedRows {
            $read = $this->reader->list($scope, $id, $cap);

            return ExportedRows::capped(array_map(StockLevelResource::fromView(...), $read['items']), $cap);
        };
    }
}
