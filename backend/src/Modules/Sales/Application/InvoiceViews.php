<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Infrastructure\Entity\Invoice;

/**
 * **`ListInvoices`' views, derived from `IA.md` §5.6's `/invoices` rows** (sales step 6, C4; the Credited segment,
 * sales step 7 C0).
 *
 * The segments partition the stored status, with Invoices' one credit-derived split (TB-4 ³), and they are bound from
 * `Invoice::STATUS_*`, never spelled as literals (API-15). **Unpaid holds `issued` and `overdue` as stored, not
 * credited in full**, and a stored `overdue` never occurs (owner ruling Q2: overdue is worked out when shown;
 * `markOverdue()` has no caller, `RESIDUAL_GAPS.md` G-18) — the value stays in the segment because it stays in
 * `invoice_status_values` and in `invoice_tenant_due_idx`'s predicate, which the segment's predicate must keep implying
 * (TB-1a). **Credited holds the same two statuses, credited in full** ({@see InvoiceCredit::creditedInFull()}, `IA.md`
 * §5.4 ³): a fully credited invoice keeps its stored `issued` (Q-D2: no new status), owes nothing and cannot be voided
 * (V2-R-17), so no stored-status segment tells the truth about it. So Unpaid's membership changes only by pay, void,
 * payment reversal or the final credit, each under the invoice lock, never because time passed, and its keyset
 * `(due_at, id)` is over immutable columns (UX §2). **No `overdue` segment and no overdue filter** (IA-O-11).
 *
 * The orders, per row of the manifest:
 *   - filtered to a fulfilment → `invoice_number`, **newest first** (the live invoice, always the latest, leads);
 *   - filtered to a customer   → `issued_at` by default, `invoice_number` as the alternate;
 *   - Unpaid                   → `due_at` by default (ascending: the overdue invoices are the leading run), `invoice_number`;
 *   - Paid, Credited, Void, no segment → `invoice_number`.
 * **Never `paid_at`** (design §19 C4): no manifest row offers it.
 */
final class InvoiceViews
{
    /** The credit condition of a segment: none, NOT credited in full, or credited in full (TB-4 ³). */
    public const CREDIT_NOT_IN_FULL = 'not_in_full';
    public const CREDIT_IN_FULL = 'in_full';

    /**
     * segment name => its stored statuses and its credit condition. **Never `'credited' => [ISSUED, OVERDUE]` alone**:
     * that would return Unpaid's rows and offer `sort=due_at` (mutation M-5, `InvoiceCreditedSegmentTest`).
     *
     * @var array<string, array{statuses: list<string>, credit: ?string}>
     */
    public const SEGMENTS = [
        'unpaid' => ['statuses' => [Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE], 'credit' => self::CREDIT_NOT_IN_FULL],
        'paid' => ['statuses' => [Invoice::STATUS_PAID], 'credit' => null],
        'credited' => ['statuses' => [Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE], 'credit' => self::CREDIT_IN_FULL],
        'void' => ['statuses' => [Invoice::STATUS_VOID], 'credit' => null],
    ];

    /** The same names, as a literal list, for the parameter's schema enum (an attribute cannot call `array_keys`). */
    public const SEGMENT_NAMES = ['unpaid', 'paid', 'credited', 'void'];

    public const SORT_INVOICE_NUMBER = 'invoice_number';
    public const SORT_DUE_AT = 'due_at';
    public const SORT_ISSUED_AT = 'issued_at';

    /**
     * The orders this view offers, default first.
     *
     * @param ?string $segment the segment name, or null for none
     *
     * @return list<string>
     */
    public static function legalSorts(?string $segment, bool $filteredToACustomer, bool $filteredToAFulfilment): array
    {
        if ($filteredToAFulfilment) {
            return [self::SORT_INVOICE_NUMBER];
        }
        if ($filteredToACustomer) {
            return [self::SORT_ISSUED_AT, self::SORT_INVOICE_NUMBER];
        }
        if ('unpaid' === $segment) {
            return [self::SORT_DUE_AT, self::SORT_INVOICE_NUMBER];
        }

        return [self::SORT_INVOICE_NUMBER];
    }

    /** IA §5.6: only the fulfilment-filtered row reads newest first. */
    public static function isNewestFirst(bool $filteredToAFulfilment): bool
    {
        return $filteredToAFulfilment;
    }

    /** The sort keys that are `timestamptz`, whose cursor keys must be timestamps. */
    public static function isTimestampSort(string $sort): bool
    {
        return \in_array($sort, [self::SORT_DUE_AT, self::SORT_ISSUED_AT], true);
    }
}
