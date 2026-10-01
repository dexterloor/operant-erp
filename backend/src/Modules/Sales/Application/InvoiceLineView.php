<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/**
 * One line of an invoice as `ListInvoiceLines` derives it (sales step 7 C0; design §4.5; §14 item 25's InvoiceLine
 * row). **There is no `invoice_line` table** (design F-2): an invoice bills its fulfilment, one line per shipped line, so
 * the line's `id` is the shipped line's own id, and every figure is derived:
 *   - `quantityBilled` = shipped − returned before issue (the DEDUCTING returns, Q-L (a′)) — `IssueInvoice`'s formula;
 *   - `amountBilled` = `round(quantityBilled × unitPrice, 4)` — the issuer's per-line rounding (D-A);
 *   - `quantityCredited` / `amountCredited` = the sums of this invoice's credit-note lines on that shipped line.
 * Money-bearing, so only under `invoice.view` (Q-B; V2-S-8 forbids `fulfilment.view` for amounts).
 */
final readonly class InvoiceLineView
{
    public function __construct(
        public string $id,
        public BilledLineRef $fulfilmentLine,
        public SalesItemRef $inventoryItem,
        public string $quantityBilled,
        public string $unitPrice,
        public string $amountBilled,
        public string $quantityCredited,
        public string $amountCredited,
    ) {
    }
}
