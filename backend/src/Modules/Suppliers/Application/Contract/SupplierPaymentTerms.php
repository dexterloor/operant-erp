<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application\Contract;

/**
 * **A supplier's payment-terms bound** (suppliers slice 1 C2; `docs/session13/suppliers-s1-design.md` §4.1).
 *
 * The number **was set equal to the customer's bound by analogy** (`Customers\Application\Contract\PaymentTerms`, held
 * equal to the invoice due window); **the customer bound has since moved to 365** (G-35 (f), C0b,
 * `docs/session13/audit-reads-owner-q-ruling.md` §2.3); **this one did not, because supplier terms are credit the tenant
 * receives** — a long supplier term favours the tenant, and no payable reads `supplier.payment_terms_days` today, so
 * nothing can disagree with it (`SupplierWriteTest`'s 3650/3651 literals pin that it did not move). It
 * is a copy on purpose rather than a shared constant — a supplier rule owned by the customer module (a deptrac widening)
 * or put in `SharedKernel` was rejected (design §4.1). **The first payables build must reconcile this bound with whatever
 * due-date CHECK it adds**, and may move it.
 *
 * **No CHECK on `supplier.payment_terms_days`**, matching `customer`: the bound is the writer's refusal.
 */
final class SupplierPaymentTerms
{
    public const MINIMUM_DAYS = 0;

    public const MAXIMUM_DAYS = 3650;

    private function __construct()
    {
    }
}
