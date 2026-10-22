<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use App\Modules\Sales\ApiResource\InvoiceCollection;
use App\Modules\Sales\ApiResource\InvoiceResource;
use App\Modules\Sales\Application\InvoiceReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `invoice` source: `ListInvoices`' current view (G6-C1).
 *
 * The parse is the list's own ({@see InvoiceCollectionProvider::criteriaFrom()}) with the cap as the page size; the
 * read is the list's reader, one first page of `cap` rows whose `next_cursor` says whether the view holds more (the
 * `LIMIT cap + 1` row, design §6.4). `status`, `customer_id`, `fulfilment_id`, `q` and `sort`, and the per-view legal
 * sorts, are the list's.
 */
final readonly class InvoiceExportSource implements ExportSource
{
    public function __construct(private InvoiceReader $reader)
    {
    }

    public function resource(): string
    {
        return 'invoice';
    }

    public function listResourceClass(): string
    {
        return InvoiceCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListInvoices';
    }

    public function rowClass(): string
    {
        return InvoiceResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        $criteria = InvoiceCollectionProvider::criteriaFrom($filters, $cap);

        return function () use ($criteria): ExportedRows {
            $page = $this->reader->list($criteria);

            return new ExportedRows(array_values(array_map(InvoiceResource::fromView(...), $page->items)), null !== $page->nextCursor);
        };
    }
}
