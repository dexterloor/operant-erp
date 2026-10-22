<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use App\Modules\Sales\ApiResource\SalesOrderCollection;
use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Modules\Sales\Application\SalesOrderReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `sales_order` source: `ListSalesOrders`' current view (G6-C1).
 *
 * The parse is the list's own ({@see SalesOrderCollectionProvider::criteriaFrom()}) with the cap as the page size; the
 * read is the list's reader, one first page of `cap` rows whose `next_cursor` says whether the view holds more (the
 * `LIMIT cap + 1` row, design §6.4). `status`, `customer_id`, `archived`, `q` and `sort`, the per-view legal sorts and
 * the refusal of `archived` with `status`/`customer_id` are the list's.
 */
final readonly class SalesOrderExportSource implements ExportSource
{
    public function __construct(private SalesOrderReader $reader)
    {
    }

    public function resource(): string
    {
        return 'sales_order';
    }

    public function listResourceClass(): string
    {
        return SalesOrderCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListSalesOrders';
    }

    public function rowClass(): string
    {
        return SalesOrderResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        $criteria = SalesOrderCollectionProvider::criteriaFrom($filters, $cap);

        return function () use ($criteria): ExportedRows {
            $page = $this->reader->list($criteria);

            return new ExportedRows(array_values($page->items), null !== $page->nextCursor);
        };
    }
}
