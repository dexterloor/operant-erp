<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/** One `ListInvoices` page, as `InvoiceReader` reads it (API-22's envelope, before serialization). */
final readonly class InvoicePage
{
    /**
     * @param list<InvoiceView> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?string $previousCursor,
        public bool $tenantHasRows,
    ) {
    }
}
