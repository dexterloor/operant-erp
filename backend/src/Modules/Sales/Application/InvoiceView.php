<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/**
 * One invoice, as `InvoiceReader` reads it. `InvoiceResource` serializes it.
 *
 * `status` is the **derived** status (`overdue` once `due_at` has passed on an `issued` invoice, by the database's
 * clock — owner ruling Q2, *"Worked out when shown"*), never the stored column alone. **No `issued_by`** (`SEC` §2:
 * the issuer is recorded, not exposed) and **no `amount_credited` / `amount_due`** (V2-C-10, owed to
 * `IssueCreditNote`).
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
        public FulfilmentRef $fulfilment,
        public SalesNamedRef $customer,
    ) {
    }
}
