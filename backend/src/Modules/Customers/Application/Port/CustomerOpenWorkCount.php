<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Port;

/**
 * The four counts {@see CustomerOpenWork} answers with (C5 C4b; design R1.2), in the refusal order `DeleteCustomer`
 * applies — the first non-zero one is the refusal (`c5-design.md` R1.2: orders → unbilled → owed by the customer →
 * owed to the customer):
 *  - `openSalesOrders` — live sales orders in `draft`, `confirmed` or `partially_fulfilled`;
 *  - `unbilledShipments` — shipments with no live (non-`void`) invoice and at least one line with something to bill
 *    (`SalesReturnReader::billed('fl') > 0`, the issuer's own expression — RED round 2 F-1);
 *  - `unpaidInvoices` — invoices `issued`/`overdue` and not credited in full (`InvoiceCredit::creditedInFull()`);
 *  - `owedCredits` — returns that owe a credit (`SalesReturnReader::owesACredit()`), whatever the payment state.
 */
final readonly class CustomerOpenWorkCount
{
    public function __construct(
        public int $openSalesOrders,
        public int $unbilledShipments,
        public int $unpaidInvoices,
        public int $owedCredits,
    ) {
    }
}
