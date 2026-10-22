<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use App\Modules\Customers\ApiResource\CustomerCollection;
use App\Modules\Customers\ApiResource\CustomerResource;
use App\Modules\Customers\Application\CustomerReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `customer` source: `ListCustomers`' current view (G6-C1).
 *
 * The parse is the list's own ({@see CustomerCollectionProvider::criteriaFrom()}) with the cap as the page size; the
 * read is the list's reader, one first page of `cap` rows whose `next_cursor` says whether the view holds more (the
 * `LIMIT cap + 1` row, design §6.4). `archived`, `q` and `sort`, and the Archived view's own order, are the list's.
 */
final readonly class CustomerExportSource implements ExportSource
{
    public function __construct(private CustomerReader $reader)
    {
    }

    public function resource(): string
    {
        return 'customer';
    }

    public function listResourceClass(): string
    {
        return CustomerCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListCustomers';
    }

    public function rowClass(): string
    {
        return CustomerResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        $criteria = CustomerCollectionProvider::criteriaFrom($filters, $cap);

        return function () use ($criteria): ExportedRows {
            $page = $this->reader->list($criteria);

            return new ExportedRows(array_values(array_map(CustomerResource::fromView(...), $page->items)), null !== $page->nextCursor);
        };
    }
}
