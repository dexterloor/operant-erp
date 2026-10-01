<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Infrastructure\Entity\Invoice;

/**
 * **"Credited in full" — the one predicate, decided in one place** (sales step 7 C0; `docs/session13/
 * output9-sales-8-c0-amendments.md` "The one predicate"; `IA.md` §5.4 ³).
 *
 *     inv.status IN ('issued','overdue') AND credited > 0 AND inv.total_amount − credited <= 0
 *     where credited = Σ credit_note.total_amount of the invoice (0 when none)
 *
 * On STORED columns only. **`<= 0`, not `= 0`**: through the API the remainder cannot go below zero (each credit line is
 * telescoped, design §4.4 step 8b), so only a row written outside the application (G-15) can over-credit — and such a
 * row must still land in exactly one segment and must not be payable. **`credited > 0`**: a zero-total invoice with
 * no credit note is NOT credited in full; it stays in Unpaid, can be paid and reads overdue, as shipped.
 *
 * **Five sites use it and none may restate it** (`InvoiceCreditTest`'s source scan holds the credited SUM to this
 * file): the Unpaid segment (its negation), the Credited segment, the first branch of `InvoiceReader`'s `overdue`
 * derivation, `PayInvoice`'s 409 `invoice.has_an_amount_due`, and the wire boolean `credited_in_full`. `amount_due`
 * reuses {@see self::credited()}.
 *
 * The statuses are interpolated from `Invoice::STATUS_*` (API-15: bound from the entity's constants, never spelled
 * as SQL literals in source), as `SalesReturnReader` does, so the fragment binds no placeholder and composes with any
 * caller's positional parameters. Needs the invoice in scope under `$alias`.
 *
 * Named `creditedInFull()` (a method, not the amendment's example constant `CREDITED_IN_FULL`) because a constant
 * cannot interpolate the entity's status constants; recorded in the build log.
 */
final class InvoiceCredit
{
    /** Σ `credit_note.total_amount` of the invoice `$alias`, 0 when none, at the column's scale 4. */
    public static function credited(string $alias = 'inv'): string
    {
        return \sprintf(
            'COALESCE((SELECT SUM(cn.total_amount) FROM credit_note cn WHERE cn.tenant_id = %1$s.tenant_id AND cn.invoice_id = %1$s.id), 0)::numeric(19,4)',
            $alias,
        );
    }

    /** The predicate. Never NULL: `status` and `total_amount` are NOT NULL and the sum is COALESCEd. */
    public static function creditedInFull(string $alias = 'inv'): string
    {
        return \sprintf(
            "(%1\$s.status IN ('%2\$s', '%3\$s') AND %4\$s > 0 AND %1\$s.total_amount - %4\$s <= 0)",
            $alias,
            Invoice::STATUS_ISSUED,
            Invoice::STATUS_OVERDUE,
            self::credited($alias),
        );
    }

    /** `amount_due`: what is still owed — `total_amount − credited`, `0` once paid; never clamped (a G-15 over-credit reads negative). */
    public static function amountDue(string $alias = 'inv'): string
    {
        return \sprintf(
            "CASE WHEN %1\$s.status = '%2\$s' THEN 0::numeric(19,4) ELSE (%1\$s.total_amount - %3\$s)::numeric(19,4) END",
            $alias,
            Invoice::STATUS_PAID,
            self::credited($alias),
        );
    }
}
