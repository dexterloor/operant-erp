<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Contract;

/**
 * **The one source of a customer's payment-terms bound** (customers slice 1 C2; design §4.1, RED M-5 obligation 1).
 *
 * A customer's terms decide an invoice's `due_at` (`InvoiceIssuer` reads them live at issue), and the database refuses
 * an invoice due more than **one year** after issue: `invoice_due_window`, `CHECK (due_at >= issued_at AND due_at <=
 * issued_at + 365 days)` (`Version20261020001000`; it was ten years, 3650, until G-35 (f) —
 * `docs/session13/audit-reads-owner-q-ruling.md` §2.2, judge 2026-10-20 under the owner's delegation: ten years was a
 * number big enough never to bind, and it let one person keep an invoice off the overdue view for a decade; 365 is the
 * smallest plain rule that admits seasonal and annual terms, for everyone, `owner` included). So `CreateCustomer`/`UpdateCustomer` refuse terms outside
 * `MINIMUM_DAYS..MAXIMUM_DAYS`, and `IssueInvoice` keeps its own refusal for a row stored outside them by SQL. Both read
 * THIS class; `PaymentTermsBoundTest` holds `MAXIMUM_DAYS` equal to the live CHECK's interval.
 *
 * The rule is the customer's, so it lives in `Customers\Application\Contract`, which `SalesApplication` may already
 * reach (deptrac ruleset) — not in `SharedKernel`. **No CHECK on `customer.payment_terms_days`**: it would make
 * `IssueInvoice`'s shipped step-4 refusal unseedable and unreachable, for no behavioural gain (design §4.1, E-4).
 */
final class PaymentTerms
{
    public const MINIMUM_DAYS = 0;

    public const MAXIMUM_DAYS = 365;

    private function __construct()
    {
    }
}
