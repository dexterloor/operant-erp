<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use App\Modules\Purchasing\ApiResource\PurchaseOrderCollection;
use App\Modules\Purchasing\ApiResource\PurchaseOrderResource;
use App\Modules\Purchasing\Application\PurchaseOrderReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `purchase_order` source: `ListPurchaseOrders`' current view (G6-C1).
 *
 * The parse is the list's own ({@see PurchaseOrderCollectionProvider::criteriaFrom()}) with the cap as the page size;
 * the read is the list's reader, one first page of `cap` rows whose `next_cursor` says whether the view holds more (the
 * `LIMIT cap + 1` row, design §6.4). `status`, `supplier_id`, `archived`, `q` and `sort`, the per-view legal sorts and
 * the refusal of `archived` with `status`/`supplier_id` are the list's.
 */
final readonly class PurchaseOrderExportSource implements ExportSource
{
    public function __construct(private PurchaseOrderReader $reader)
    {
    }

    public function resource(): string
    {
        return 'purchase_order';
    }

    public function listResourceClass(): string
    {
        return PurchaseOrderCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListPurchaseOrders';
    }

    public function rowClass(): string
    {
        return PurchaseOrderResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        $criteria = PurchaseOrderCollectionProvider::criteriaFrom($filters, $cap);

        return function () use ($criteria): ExportedRows {
            $page = $this->reader->list($criteria);

            return new ExportedRows(array_values($page->items), null !== $page->nextCursor);
        };
    }
}
