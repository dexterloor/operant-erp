<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource\State;

use App\Modules\Inventory\ApiResource\ItemCollection;
use App\Modules\Inventory\ApiResource\ItemResource;
use App\Modules\Inventory\Application\ItemReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `inventory_item` source: `ListItems`' current view (G6-C0, Inventory family).
 *
 * The parse is `ListItems`' own ({@see ItemCollectionProvider::criteriaFrom()}), with the cap as the page size; the read is
 * `ListItems`' own reader and statement, one page of `cap` rows whose `next_cursor` says whether the view holds more — the
 * `LIMIT cap + 1` row (design §6.4). So `archived`, `q` and `supplier_id`, and the refusal of `archived` with
 * `supplier_id`, are the list's.
 */
final readonly class ItemExportSource implements ExportSource
{
    public function __construct(private ItemReader $reader)
    {
    }

    public function resource(): string
    {
        return 'inventory_item';
    }

    public function listResourceClass(): string
    {
        return ItemCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListItems';
    }

    public function rowClass(): string
    {
        return ItemResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        $criteria = ItemCollectionProvider::criteriaFrom($filters, $cap);

        return function () use ($criteria): ExportedRows {
            $page = $this->reader->list($criteria);

            return new ExportedRows(array_values($page->items), null !== $page->nextCursor);
        };
    }
}
