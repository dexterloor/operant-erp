<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **One copy of each adjustment-decision term** — G-29 / G-30 part 3 (design §7 test 20; RED §4's recommended scan; J-13).
 *
 * Behaviour tests cannot catch a private copy of `undecidedPair` / `sameOnReturnLine` / `billed(l)` that is CORRECT: it
 * passes every one of them, then drifts the day a term changes. So the table is read and written in exactly the classes
 * that own it, and the billed quantity is formed in exactly one place:
 *
 *  - SQL that reads or writes `adjustment_decision` (`FROM|JOIN|INTO|UPDATE adjustment_decision`) appears in `src/` only in
 *    `SalesReturnReader` (the named terms, the served members), `AdjustmentDecisionWriter` (the decision operation) and
 *    `CreditNoteWriter` (the adjust path's step-12 `distinct` INSERT, C2). Everything else goes through the reader's terms.
 *  - `returnedBeforeIssue(` is called **only inside `SalesReturnReader`** (since C5 C4b, 2026-10-14; `c5-red.md` RED round
 *    2 F-1, mutation M22): every consumer forms the billed quantity through `SalesReturnReader::billed()` —
 *    `CreditNoteWriter`'s inlined copy was replaced by it in C2 (J-13), and C4b rewrote the last two inline spellings
 *    (`InvoiceIssuer`'s `billable` count and total, `InvoiceReader`'s `quantity_billed`/`amount_billed`, both
 *    `fl.quantity - returnedBeforeIssue(…)`) so that the issuer, the credit and decision writers, the invoice read-back
 *    and `DeleteCustomer`'s unbilled-shipment count (`CustomerOpenSalesWork`) read ONE expression.
 *
 * **Controls, same test:** the patterns match planted copies in a string this test builds, under any alias, and none of the
 * allowed shapes; and the owning classes ARE found, so an empty result cannot be a blind scan.
 */
final class AdjustmentDecisionTermsTest extends TestCase
{
    private const TABLE_SQL = '/\b(?:FROM|JOIN|INTO|UPDATE)\s+adjustment_decision\b(?!_)/i';

    private const BILLED_CALL = '/\breturnedBeforeIssue\(/';

    private const OWNERS_OF_THE_TABLE = ['AdjustmentDecisionWriter.php', 'CreditNoteWriter.php', 'SalesReturnReader.php'];

    private const CALLERS_OF_THE_FORMULA = ['SalesReturnReader.php'];

    #[Test]
    public function theDecisionTableAndTheBilledQuantityHaveOneCopyEach(): void
    {
        foreach (['SELECT 1 FROM adjustment_decision x', 'JOIN adjustment_decision d ON', 'INSERT INTO adjustment_decision (id)', "update adjustment_decision SET kind = 'x'"] as $plant) {
            self::assertSame(1, preg_match(self::TABLE_SQL, $plant), 'A planted read or write was not matched: '.$plant);
        }
        foreach (['FROM adjustment_decisions', 'JOIN adjustment_decision_owed', '/adjustment-decisions', "'adjustment_decision_owed'", 'adjustment_decision.within_returned'] as $allowed) {
            self::assertSame(0, preg_match(self::TABLE_SQL, $allowed), 'An allowed shape was matched: '.$allowed);
        }
        self::assertSame(1, preg_match(self::BILLED_CALL, 'SalesReturnReader::returnedBeforeIssue(\'fl\')'));

        $tableHolders = [];
        $formulaHolders = [];
        foreach (PhpSourceScan::phpFilesUnder(\dirname(__DIR__, 2).'/src') as $path) {
            $source = (string) file_get_contents($path);
            if (1 === preg_match(self::TABLE_SQL, $source)) {
                $tableHolders[] = basename($path);
            }
            if (1 === preg_match(self::BILLED_CALL, $source)) {
                $formulaHolders[] = basename($path);
            }
        }
        sort($tableHolders);
        sort($formulaHolders);

        self::assertSame(self::OWNERS_OF_THE_TABLE, $tableHolders,
            'SQL on adjustment_decision is written outside the classes that own it. The named terms live in SalesReturnReader; a '
            .'second copy (undecidedPair, sameOnReturnLine, openOnReturnLine…) passes every behaviour test the day it is written '
            .'and drifts after. Use the reader\'s terms; a new WRITER of the table needs a grant review (ADR-002 A4.1) and a place here.');
        self::assertSame(self::CALLERS_OF_THE_FORMULA, $formulaHolders,
            'returnedBeforeIssue( is called outside SalesReturnReader: a second spelling of the billed quantity (`fl.quantity - '
            .'returnedBeforeIssue(…)`). Every consumer — the issuer, its read-back, the credit and decision writers, the customer '
            .'open-work count — forms it through SalesReturnReader::billed() (J-13; C5 RED round 2 F-1).');
    }
}
