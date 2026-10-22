<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource\State;

use App\Modules\Inventory\ApiResource\StockMovementCollection;
use App\Modules\Inventory\ApiResource\StockMovementResource;
use App\Modules\Inventory\Application\StockMovementReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `stock_movement` source: `ListStockMovements`' current view, newest first (G6-C0, Inventory family).
 *
 * The parse is the list's own ({@see StockMovementCollectionProvider::criteriaFrom()}) with the cap as the page size; the
 * read is the list's reader, one first page of `cap` rows whose `next_cursor` says whether the view holds more.
 */
final readonly class StockMovementExportSource implements ExportSource
{
    public function __construct(private StockMovementReader $reader)
    {
    }

    public function resource(): string
    {
        return 'stock_movement';
    }

    public function listResourceClass(): string
    {
        return StockMovementCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListStockMovements';
    }

    public function rowClass(): string
    {
        return StockMovementResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        $criteria = StockMovementCollectionProvider::criteriaFrom($filters, $cap);

        return function () use ($criteria): ExportedRows {
            $page = $this->reader->list($criteria);

            return new ExportedRows(array_values(array_map(StockMovementResource::fromView(...), $page->items)), null !== $page->nextCursor);
        };
    }
}
