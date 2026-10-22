<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use App\Modules\Sales\ApiResource\FulfilmentCollection;
use App\Modules\Sales\ApiResource\FulfilmentResource;
use App\Modules\Sales\Application\FulfilmentReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `fulfilment` source: `ListFulfilments`' current view (G6-C1).
 *
 * The parse is the list's own ({@see FulfilmentCollectionProvider::criteriaFrom()}) with the cap as the page size; the
 * read is the list's reader, one first page of `cap` rows whose `next_cursor` says whether the view holds more (the
 * `LIMIT cap + 1` row, design §6.4). `sales_order_id` and `q`, and the order's view by shipment time, are the list's.
 */
final readonly class FulfilmentExportSource implements ExportSource
{
    public function __construct(private FulfilmentReader $reader)
    {
    }

    public function resource(): string
    {
        return 'fulfilment';
    }

    public function listResourceClass(): string
    {
        return FulfilmentCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListFulfilments';
    }

    public function rowClass(): string
    {
        return FulfilmentResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        $criteria = FulfilmentCollectionProvider::criteriaFrom($filters, $cap);

        return function () use ($criteria): ExportedRows {
            $page = $this->reader->list($criteria);

            return new ExportedRows(array_values(array_map(FulfilmentResource::fromView(...), $page->items)), null !== $page->nextCursor);
        };
    }
}
