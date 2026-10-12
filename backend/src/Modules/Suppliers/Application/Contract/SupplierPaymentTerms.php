<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application\Contract;

/**
 * **A supplier's payment-terms bound** (suppliers slice 1 C2; `docs/session13/suppliers-s1-design.md` §4.1).
 *
 * The number **mirrors** the customer's (`Customers\Application\Contract\PaymentTerms`, held equal to the invoice due
 * window) **by analogy only**: no payable reads `supplier.payment_terms_days` today, so nothing can disagree with it. It
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
