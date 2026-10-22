<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use App\Modules\Purchasing\ApiResource\GoodsReceiptCollection;
use App\Modules\Purchasing\ApiResource\GoodsReceiptResource;
use App\Modules\Purchasing\Application\GoodsReceiptReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `goods_receipt` source: `ListGoodsReceipts`' current view (G6-C1).
 *
 * The parse is the list's own ({@see GoodsReceiptCollectionProvider::criteriaFrom()}) with the cap as the page size;
 * the read is the list's reader, one first page of `cap` rows whose `next_cursor` says whether the view holds more (the
 * `LIMIT cap + 1` row, design §6.4). `purchase_order_id` and `q`, and the order's view by receipt time, are the list's.
 */
final readonly class GoodsReceiptExportSource implements ExportSource
{
    public function __construct(private GoodsReceiptReader $reader)
    {
    }

    public function resource(): string
    {
        return 'goods_receipt';
    }

    public function listResourceClass(): string
    {
        return GoodsReceiptCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListGoodsReceipts';
    }

    public function rowClass(): string
    {
        return GoodsReceiptResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        $criteria = GoodsReceiptCollectionProvider::criteriaFrom($filters, $cap);

        return function () use ($criteria): ExportedRows {
            $page = $this->reader->list($criteria);

            return new ExportedRows(array_values(array_map(GoodsReceiptResource::fromView(...), $page->items)), null !== $page->nextCursor);
        };
    }
}
