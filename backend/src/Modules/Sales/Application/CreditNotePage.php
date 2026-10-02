<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/** One keyset page of `ListCreditNotes` (API-17/API-18; sales step 7 C2). */
final readonly class CreditNotePage
{
    /**
     * @param list<CreditNoteView> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?string $previousCursor,
        public bool $tenantHasRows,
    ) {
    }
}
