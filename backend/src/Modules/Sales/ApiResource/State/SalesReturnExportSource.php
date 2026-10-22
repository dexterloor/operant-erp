<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use App\Modules\Sales\ApiResource\SalesReturnCollection;
use App\Modules\Sales\ApiResource\SalesReturnResource;
use App\Modules\Sales\Application\SalesReturnReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `sales_return` source: `ListSalesReturns`' current view (G6-C1).
 *
 * The parse is the list's own ({@see SalesReturnCollectionProvider::criteriaFrom()}, the provider's own instance,
 * because it reads the caller) with the cap as the page size; the read is the list's reader, one first page of `cap`
 * rows whose `next_cursor` says whether the view holds more. The invoice-only filters (`credit_blocked_by_payment`, the
 * decision queue) are refused to a caller without `invoice.view` exactly as the list refuses them, and the invoice-only
 * columns are ABSENT for such a caller exactly as the list leaves them out (the reader decides by the request's caller,
 * which is the exporter) — so the CSV shows those cells empty (final RED M-5).
 */
final readonly class SalesReturnExportSource implements ExportSource
{
    public function __construct(private SalesReturnReader $reader, private SalesReturnCollectionProvider $parse)
    {
    }

    public function resource(): string
    {
        return 'sales_return';
    }

    public function listResourceClass(): string
    {
        return SalesReturnCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListSalesReturns';
    }

    public function rowClass(): string
    {
        return SalesReturnResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        $criteria = $this->parse->criteriaFrom($filters, $cap);

        return function () use ($criteria): ExportedRows {
            $page = $this->reader->list($criteria);

            return new ExportedRows(array_values(array_map(SalesReturnResource::fromView(...), $page->items)), null !== $page->nextCursor);
        };
    }
}
