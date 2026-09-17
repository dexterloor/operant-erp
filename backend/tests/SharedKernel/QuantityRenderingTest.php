<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel;

use App\SharedKernel\Display\QuantityRendering;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * QR-2 (`docs/ux/STATES.md` §QR) — **the one implementation** of how a quantity
 * the system holds is rendered to a person, and the check that it trims rather
 * than rounds.
 *
 * ---------------------------------------------------------------------------
 * The defect this exists for (CD-1)
 * ---------------------------------------------------------------------------
 * The shipped over-receipt refusal read *"would reach 11.000000 received of
 * 10.000000 ordered"*. Nothing was wrong with the string as code; what was
 * missing was a **rule** saying what a quantity looks like, so the next writer
 * that printed one would have printed it the same way. `UX` wrote that rule on
 * 2026-09-18; this class is it in executable form, and QR-1 requires that copy
 * and table cells share it rather than each re-deriving a formatting.
 *
 * ---------------------------------------------------------------------------
 * Two decisions taken here, because QR-2 states a rule and not a signature
 * ---------------------------------------------------------------------------
 * 1. **A string this rule has no domain over is returned unchanged, never
 *    thrown on.** Every call site is copy — a refusal, a cell, a confirmation —
 *    and a formatter that throws turns a 409 a person can act on into a 500
 *    nobody can. `''`, `'abc'`, `"5\n"` and `'1.0000001'` come back as they went
 *    in. The refusal still says something true; it simply does not claim to have
 *    formatted a number that is not one.
 * 2. **Only the fractional part is trimmed.** `100` is a hundred and stays
 *    `100`; trimming a trailing zero of an *integer* part would change the
 *    value, which is the one thing QR-2 forbids outright. Leading zeros are
 *    likewise untouched: QR-2 says nothing about them, Postgres emits none, and
 *    inventing a second normalisation inside a rule about trailing zeros is how
 *    a formatter grows a behaviour nobody ruled.
 */
final class QuantityRenderingTest extends TestCase
{
    /**
     * QR-2's own table, verbatim from `STATES.md`, so the check is the rule and
     * not a second reading of it.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function qr2Table(): iterable
    {
        yield 'ten of something is written 10' => ['10.000000', '10'];
        yield 'one trailing-zero group removed, the meaningful digit stays' => ['1.500000', '1.5'];
        yield 'two meaningful decimals, both kept' => ['0.250000', '0.25'];
        yield 'a real stock level from ReceiveStockTest' => ['8.250000', '8.25'];
        yield 'QR-4: zero is 0, never blank' => ['0.000000', '0'];
        yield 'six decimals are kept when six decimals are held' => ['1.000001', '1.000001'];
    }

    #[Test]
    #[DataProvider('qr2Table')]
    public function itRendersQr2sOwnTable(string $stored, string $rendered): void
    {
        self::assertSame($rendered, QuantityRendering::render($stored));
    }

    /**
     * The cases QR-2 implies and does not tabulate.
     *
     * `stock_movement.quantity` is the signed one (`Version20260906000100.php:436`,
     * retyped from `integer`): a depletion is negative, so a rule applied to it
     * must keep the sign and must not invent `-0` for a zero delta.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function impliedCases(): iterable
    {
        yield 'no decimal point at all is already rendered' => ['10', '10'];
        yield 'an integer trailing zero is a digit, not scale' => ['100.000000', '100'];
        yield 'only the fractional part is trimmed' => ['1000', '1000'];
        yield 'a partial trim' => ['1.230000', '1.23'];
        yield 'nothing to trim' => ['1.234567', '1.234567'];
        yield 'the largest value numeric(19,6) holds' => ['9999999999999.999999', '9999999999999.999999'];
        yield 'the smallest positive one' => ['0.000001', '0.000001'];
        yield 'a signed movement keeps its sign' => ['-5.000000', '-5'];
        yield 'a signed movement keeps its fraction' => ['-2.500000', '-2.5'];
        yield 'a signed zero delta is 0 and never -0' => ['-0.000000', '0'];
        yield 'a bare signed integer' => ['-7', '-7'];
        yield 'zero with no point' => ['0', '0'];
        yield 'zero at a different scale' => ['0.0000', '0'];
    }

    #[Test]
    #[DataProvider('impliedCases')]
    public function itRendersTheCasesQr2Implies(string $stored, string $rendered): void
    {
        self::assertSame($rendered, QuantityRendering::render($stored));
    }

    /**
     * Hostile input. Each of these is returned **unchanged**, by decision 1 in
     * the class docblock.
     *
     * `"5\n"` is here for the reason `StockReceipt::QUANTITY` gives for spelling
     * its anchors `\A…\z` and never `^…$`: `$` also matches before a final
     * newline, so a pattern written the usual way would call `"5\n"` a decimal.
     *
     * @return iterable<string, array{string}>
     */
    public static function hostileInput(): iterable
    {
        yield 'empty' => [''];
        yield 'not a number' => ['abc'];
        yield 'a seventh decimal numeric(19,6) cannot hold' => ['1.0000001'];
        yield 'a trailing newline' => ["5\n"];
        yield 'a leading newline' => ["\n5"];
        yield 'a trailing point' => ['10.'];
        yield 'a leading point' => ['.5'];
        yield 'exponential notation' => ['1.0e6'];
        yield 'a thousands separator' => ['1,000.000000'];
        yield 'whitespace around a decimal' => [' 10.000000 '];
        yield 'two points' => ['1.2.3'];
        yield 'a lone sign' => ['-'];
        yield 'a plus sign, which Postgres never emits' => ['+10.000000'];
    }

    #[Test]
    #[DataProvider('hostileInput')]
    public function itReturnsAnythingItHasNoDomainOverUnchanged(string $given): void
    {
        self::assertSame($given, QuantityRendering::render($given));
    }

    /**
     * **The property QR-2 is actually about: trimming changes no digit.** The
     * table above is six rows a reader can check by eye; this is the statement
     * that makes rounding fail rather than merely being absent from the table.
     *
     * Every rendered value compares numerically **equal** to the value it came
     * from, at scale 6 — the column's scale — using `bccomp`, which is the same
     * comparison the writers use. A rounding implementation
     * (`number_format($q, 2)`) is red here on `1.000001` and on
     * `9999999999999.999999` even though it would be green on four of QR-2's own
     * six rows.
     */
    #[Test]
    public function trimmingChangesNoDigit(): void
    {
        $values = [];
        foreach ([...self::qr2Table(), ...self::impliedCases()] as [$stored, $rendered]) {
            $values[] = $stored;
            self::assertSame(0, bccomp($stored, $rendered, 6),
                \sprintf('QR-2 rendered "%s" as "%s", which is a different number. Trimming a trailing zero changes no digit; rounding does.', $stored, $rendered));
        }

        // Control — the domain is not empty and does reach the precision that
        // separates trimming from rounding.
        self::assertContains('1.000001', $values, 'The property ran over no value needing more decimals than a rounding formatter would keep.');
    }

    /**
     * **QR-2 is idempotent**, which is what lets QR-1 hold: copy and a table cell
     * may apply it at different points in one request without disagreeing.
     */
    #[Test]
    public function renderingAnAlreadyRenderedQuantityChangesNothing(): void
    {
        foreach ([...self::qr2Table(), ...self::impliedCases()] as [, $rendered]) {
            self::assertSame($rendered, QuantityRendering::render($rendered));
        }
    }
}
