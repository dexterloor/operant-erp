<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource\State;

use App\Modules\Suppliers\ApiResource\SupplierCollection;
use App\Modules\Suppliers\ApiResource\SupplierResource;
use App\Modules\Suppliers\Application\SupplierReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `supplier` source: `ListSuppliers`' current view (G6-C1).
 *
 * The parse is the list's own ({@see SupplierCollectionProvider::criteriaFrom()}) with the cap as the page size; the
 * read is the list's reader, one first page of `cap` rows whose `next_cursor` says whether the view holds more (the
 * `LIMIT cap + 1` row, design §6.4). `archived`, `q` and `sort`, and the Archived view's own order, are the list's.
 */
final readonly class SupplierExportSource implements ExportSource
{
    public function __construct(private SupplierReader $reader)
    {
    }

    public function resource(): string
    {
        return 'supplier';
    }

    public function listResourceClass(): string
    {
        return SupplierCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListSuppliers';
    }

    public function rowClass(): string
    {
        return SupplierResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        $criteria = SupplierCollectionProvider::criteriaFrom($filters, $cap);

        return function () use ($criteria): ExportedRows {
            $page = $this->reader->list($criteria);

            return new ExportedRows(array_values(array_map(SupplierResource::fromView(...), $page->items)), null !== $page->nextCursor);
        };
    }
}
