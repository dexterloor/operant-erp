<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Infrastructure\Entity\Invoice;

/**
 * **`ListInvoices`' views, derived from `IA.md` §5.6's `/invoices` rows** (sales step 6, C4).
 *
 * The segments partition the STORED status (TB-4), and they are bound from `Invoice::STATUS_*`, never spelled as
 * literals (API-15). **Unpaid holds `issued` and `overdue` as stored**, and a stored `overdue` never occurs (owner
 * ruling Q2: overdue is worked out when shown; `markOverdue()` has no caller, `RESIDUAL_GAPS.md` G-18) — the value stays
 * in the segment because it stays in `invoice_status_values` and in `invoice_tenant_due_idx`'s predicate, which the
 * segment's predicate must keep implying (TB-1a). So Unpaid's membership changes only by pay or void, never because
 * time passed, and its keyset `(due_at, id)` is over immutable columns (UX §2). **No `overdue` segment and no
 * overdue filter** (IA-O-11).
 *
 * The orders, per row of the manifest:
 *   - filtered to a fulfilment → `invoice_number`, **newest first** (the live invoice, always the latest, leads);
 *   - filtered to a customer   → `issued_at` by default, `invoice_number` as the alternate;
 *   - Unpaid                   → `due_at` by default (ascending: the overdue invoices are the leading run), `invoice_number`;
 *   - Paid, Void, no segment   → `invoice_number`.
 * **Never `paid_at`** (design §19 C4): no manifest row offers it.
 */
final class InvoiceViews
{
    /** @var array<string, list<string>> segment name => the stored statuses it holds */
    public const SEGMENTS = [
        'unpaid' => [Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE],
        'paid' => [Invoice::STATUS_PAID],
        'void' => [Invoice::STATUS_VOID],
    ];

    /** The same names, as a literal list, for the parameter's schema enum (an attribute cannot call `array_keys`). */
    public const SEGMENT_NAMES = ['unpaid', 'paid', 'void'];

    public const SORT_INVOICE_NUMBER = 'invoice_number';
    public const SORT_DUE_AT = 'due_at';
    public const SORT_ISSUED_AT = 'issued_at';

    /**
     * The orders this view offers, default first.
     *
     * @param list<string> $statuses the segment's stored statuses, or [] for none
     *
     * @return list<string>
     */
    public static function legalSorts(array $statuses, bool $filteredToACustomer, bool $filteredToAFulfilment): array
    {
        if ($filteredToAFulfilment) {
            return [self::SORT_INVOICE_NUMBER];
        }
        if ($filteredToACustomer) {
            return [self::SORT_ISSUED_AT, self::SORT_INVOICE_NUMBER];
        }
        if (self::SEGMENTS['unpaid'] === $statuses) {
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
