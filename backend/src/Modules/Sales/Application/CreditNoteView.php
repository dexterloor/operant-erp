<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/**
 * One credit note, as `CreditNoteReader` reads it (sales step 7 C1; design §4.5): `credit_note_number`, `reason`,
 * `invoice = {id, invoice_number}`, `sales_return = {id, sales_return_number}|null` (PC-15, `SEC` S-4), `currency_code`,
 * `total_amount`, `issued_at`, `issued_by = {id, display_name}` (PC-11 (4a)'s seventh application, `SEC` S-8 — SoD-5's
 * evidence, not enforcement) and its lines. Under `invoice.view` (Q-B) — and, for its writers, PC-17.
 */
final readonly class CreditNoteView
{
    /**
     * @param list<CreditNoteLineView> $lines
     */
    public function __construct(
        public string $id,
        public string $creditNoteNumber,
        public string $reason,
        public InvoiceNumberRef $invoice,
        public ?SalesReturnNumberRef $salesReturn,
        public string $currencyCode,
        public string $totalAmount,
        public string $issuedAt,
        public SalesActorRef $issuedBy,
        public array $lines,
    ) {
    }
}
