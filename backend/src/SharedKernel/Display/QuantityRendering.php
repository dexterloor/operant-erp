<?php

declare(strict_types=1);

namespace App\SharedKernel\Display;

/**
 * QR-2 (`docs/ux/STATES.md` §QR, written by `UX` 2026-09-18) — **the one place a
 * quantity the system holds becomes a string a person reads.**
 *
 * ---------------------------------------------------------------------------
 * Why this is one implementation and not a `sprintf` per site
 * ---------------------------------------------------------------------------
 * QR-1: *"copy and table cells render the same number"* — a refusal, a
 * confirmation, a detail field and a data-table cell are the same string, and
 * *"any surface that re-derives its own formatting rather than using the one
 * implementation"* is the violation the rule names. It lives in `SharedKernel`
 * because both the modules that hold quantities today (`Purchasing`,
 * `Inventory`) render them, and a rule owned by one of them would be imported by
 * the other across a boundary `deptrac.yaml` forbids.
 *
 * ---------------------------------------------------------------------------
 * The rule, exactly
 * ---------------------------------------------------------------------------
 * Every quantity column in the schema is `numeric(19,6)` (S-12), so what arrives
 * here is a decimal string padded to the column's scale — `"10.000000"` — and
 * the six zeros are the scale, not information. **Trim every trailing zero of the
 * fractional part; if nothing is left, drop the point too.** `10.000000` → `10`,
 * `1.500000` → `1.5`, `0.000000` → `0` (QR-4: never blank), `1.000001` →
 * `1.000001`, because *"the maximum is a ceiling, not a target"*.
 *
 * **Never pad and never round.** Padding is the defect this exists to remove;
 * rounding is worse, because it destroys a digit the column actually holds.
 * Trimming a trailing zero changes no digit, which is the whole reason it is
 * safe, and `QuantityRenderingTest::trimmingChangesNoDigit()` asserts that
 * property with `bccomp` rather than trusting the table.
 *
 * ---------------------------------------------------------------------------
 * Two decisions this class takes, because QR-2 states a rule and not a signature
 * ---------------------------------------------------------------------------
 * 1. **A string outside the rule's domain is returned unchanged, not thrown on.**
 *    Every call site is copy. A formatter that throws turns a refusal a person
 *    can act on into a 500 nobody can, and the value it would have thrown on is
 *    by definition one this rule has nothing to say about. `''`, `'abc'`,
 *    `"5\n"` and `'1.0000001'` come back as they went in.
 * 2. **Only the fractional part is trimmed.** `100` stays `100` — a trailing
 *    zero of the *integer* part is a digit, and removing it would change the
 *    value, which is the one thing QR-2 forbids outright. Leading zeros are
 *    untouched for the same reason inverted: QR-2 says nothing about them and
 *    Postgres emits none, so normalising them here would be a behaviour nobody
 *    ruled.
 *
 * **Money is NOT in scope** and must not be routed through here: QR-6 records
 * that `currency.minor_unit` is seeded `JPY 0`, so a blanket decimal rule would
 * render ¥250 as `¥250.00`. The money rule is owed to `UX` + `PO` at the first
 * money-rendering surface.
 */
final class QuantityRendering
{
    /**
     * The domain: `numeric(19,6)` as the database spells it, plus the sign
     * `stock_movement.quantity` carries (`Version20260906000100.php:436`) — a
     * depletion is negative.
     *
     * `\A…\z`, never `^…$`, for the reason `StockReceipt::QUANTITY` gives: `$`
     * also matches before a final newline, so `"5\n"` would be taken for a
     * decimal and rendered as one.
     */
    private const QUANTITY = '/\A(-?)(\d{1,13})(?:\.(\d{1,6}))?\z/';

    /** Not instantiable: this is a rule, and it holds no state. */
    private function __construct()
    {
    }

    public static function render(string $quantity): string
    {
        if (1 !== preg_match(self::QUANTITY, $quantity, $parts)) {
            return $quantity;
        }

        [, $sign, $integer] = $parts;
        $fraction = rtrim($parts[3] ?? '', '0');

        // A zero delta is `0`, never `-0`: `numeric` has no signed zero, and a
        // minus sign in front of nothing reads as a fact about direction that
        // the number does not carry.
        if ('' === $fraction && '' === ltrim($integer, '0')) {
            $sign = '';
        }

        return $sign.$integer.('' === $fraction ? '' : '.'.$fraction);
    }
}
