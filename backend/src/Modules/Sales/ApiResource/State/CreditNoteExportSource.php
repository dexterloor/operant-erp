<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use App\Modules\Sales\ApiResource\CreditNoteCollection;
use App\Modules\Sales\ApiResource\CreditNoteResource;
use App\Modules\Sales\Application\CreditNoteReader;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;

/**
 * `CreateExport`'s `credit_note` source: `ListCreditNotes`' current view (G6-C1).
 *
 * The parse is the list's own ({@see CreditNoteCollectionProvider::criteriaFrom()}) with the cap as the page size; the
 * read is the list's reader, one first page of `cap` rows whose `next_cursor` says whether the view holds more (the
 * `LIMIT cap + 1` row, design §6.4). The export is gated on `invoice.view`, the code `ListCreditNotes` is declared with
 * (never `credit_note.view`: D-20). `invoice_id`, `customer_id`, `sales_return_id` and `q` are the list's.
 */
final readonly class CreditNoteExportSource implements ExportSource
{
    public function __construct(private CreditNoteReader $reader)
    {
    }

    public function resource(): string
    {
        return 'credit_note';
    }

    public function listResourceClass(): string
    {
        return CreditNoteCollection::class;
    }

    public function listOperationName(): string
    {
        return 'ListCreditNotes';
    }

    public function rowClass(): string
    {
        return CreditNoteResource::class;
    }

    public function prepare(array $filters, int $cap): \Closure
    {
        $criteria = CreditNoteCollectionProvider::criteriaFrom($filters, $cap);

        return function () use ($criteria): ExportedRows {
            $page = $this->reader->list($criteria);

            return new ExportedRows(array_values(array_map(CreditNoteResource::fromView(...), $page->items)), null !== $page->nextCursor);
        };
    }
}
