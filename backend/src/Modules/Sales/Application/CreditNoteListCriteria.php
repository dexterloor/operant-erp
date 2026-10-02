<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Platform\Http\Pagination\Cursor;

/**
 * `ListCreditNotes`' parsed query (sales step 7 C2; design §4.5). One order, `credit_note_number` newest first over
 * `credit_note_number_unique` (the six-digit `CN-` number is a faithful proxy for creation order, IA R-1's reading for
 * every document number). Each filter is `null` when absent; `customerId` reaches the credit note THROUGH its invoice.
 */
final readonly class CreditNoteListCriteria
{
    public function __construct(
        public int $limit,
        public ?string $invoiceId = null,
        public ?string $customerId = null,
        public ?string $salesReturnId = null,
        public ?string $search = null,
        public ?Cursor $after = null,
        public ?Cursor $before = null,
    ) {
    }

    public function isBackwards(): bool
    {
        return null !== $this->before;
    }
}
