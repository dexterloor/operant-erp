<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/**
 * One invoice, as `InvoiceReader` reads it. `InvoiceResource` serializes it.
 *
 * `status` is the **derived** status (`overdue` once `due_at` has passed on an `issued` invoice, by the database's
 * clock — owner ruling Q2, *"Worked out when shown"*; `issued` for an invoice credited in full), never the stored
 * column alone. **No `issued_by`** (`SEC` §2: the issuer is recorded, not exposed). Since sales step 7 C0 it carries
 * `amountCredited` and `amountDue` (scale 4, V2-C-10's second half) and `creditedInFull` — the server states the
 * Credited label so no client keeps a copy of the rule (`InvoiceCredit`).
 */
final readonly class InvoiceView
{
    public function __construct(
        public string $id,
        public string $invoiceNumber,
        public string $status,
        public string $totalAmount,
        public string $currencyCode,
        public string $issuedAt,
        public string $dueAt,
        public ?string $paidAt,
        public ?SalesActorRef $paidBy,
        public FulfilmentRef $fulfilment,
        public SalesNamedRef $customer,
        public string $amountCredited,
        public string $amountDue,
        public bool $creditedInFull,
    ) {
    }
}
