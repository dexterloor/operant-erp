<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource\State;

use App\Modules\Warehouses\ApiResource\WarehouseCollection;
use App\Modules\Warehouses\ApiResource\WarehouseResource;
use App\Modules\Warehouses\Application\WarehouseReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `warehouse` source: `ListWarehouses`' current view (G6-C1).
 *
 * The parse is the list's own ({@see WarehouseCollectionProvider::parse()}). A bounded list (TB-1c): the list's own
 * `archived`/`q` parse and statement, with `LIMIT cap + 1` (design M-7).
 */
final readonly class WarehouseExportSource implements ExportSource
{
    public function __construct(private WarehouseReader $reader)
    {
    }

    public function resource(): string
    {
        return 'warehouse';
    }

    public function listResourceClass(): string
    {
        return WarehouseCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListWarehouses';
    }

    public function rowClass(): string
    {
        return WarehouseResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        [$archived, $search] = WarehouseCollectionProvider::parse($filters);

        return function () use ($archived, $search, $cap): ExportedRows {
            $read = $this->reader->list($archived, $search, $cap);

            return ExportedRows::capped(array_map(WarehouseResource::fromView(...), $read['items']), $cap);
        };
    }
}
