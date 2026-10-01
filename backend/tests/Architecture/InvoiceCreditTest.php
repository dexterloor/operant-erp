<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Modules\Sales\Application\InvoiceCredit;
use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **Test 8 — "credited in full" lives in one class** (sales step 7 C0; `output9-sales-8-c0-amendments.md` A-5 test 8,
 * scope sharpened after amendments RED-3 L-4). Behaviour tests cannot catch a private copy of the predicate that is
 * *correct*: it passes every one of them, then drifts. So the **sum of `credit_note.total_amount`** — the one term the
 * predicate is built on — may appear in exactly one file under `src/`: `InvoiceCredit`.
 *
 * **Keyed to that column, and to nothing broader.** These are different shapes, allowed, and must not be caught:
 *   - quantity sums over `credit_note_line.quantity` (`SalesReturnReader`'s two credited terms; C1's bounds);
 *   - line-amount sums over `credit_note_line.amount` (`ListInvoiceLines`);
 *   - V2-R-17's `EXISTS (… credit_note …)` in `VoidInvoice` (C1).
 * Plain file text, so a heredoc, nowdoc or concatenation fragment is seen as well as a quoted literal (a docblock that
 * spells the SQL would be seen too — that is intended: prose that copies the SQL is the first step of a copy).
 * **Positive control, same test:** a planted copy in a string this test builds must be matched; and the allowed shapes,
 * planted, must not be. Mutation M-3 (an inlined `= 0` copy at one site) is a DRIFT check behaviour tests make;
 * this is the SINGLENESS check (M-6: a correct private copy in another class → red here only).
 */
final class InvoiceCreditTest extends TestCase
{
    private const SUM_OF_CREDIT_TOTALS = '/SUM\(\s*(?:[a-z_]+\.)?total_amount\s*\)\s*FROM\s+credit_note\b(?!_)/i';

    #[Test]
    public function theCreditedSumIsWrittenInOneClassOnly(): void
    {
        $holders = [];
        foreach (PhpSourceScan::phpFilesUnder(\dirname(__DIR__, 2).'/src') as $file) {
            if (1 === preg_match(self::SUM_OF_CREDIT_TOTALS, (string) file_get_contents($file))) {
                $holders[] = basename($file);
            }
        }

        self::assertSame([basename((string) (new \ReflectionClass(InvoiceCredit::class))->getFileName())], $holders,
            "The sum of credit_note.total_amount is written outside InvoiceCredit. \"Credited in full\" is decided ONCE\n"
            .'(Unpaid, Credited, the overdue derivation, PayInvoice, credited_in_full all call InvoiceCredit); a second copy '
            .'passes every behaviour test the day it is written and drifts after. Call InvoiceCredit::credited() instead.');

        // Controls: the regex catches a planted copy, under any alias, and none of the allowed shapes.
        foreach (['SELECT SUM(c.total_amount) FROM credit_note c', 'sum( total_amount ) from credit_note'] as $plant) {
            self::assertSame(1, preg_match(self::SUM_OF_CREDIT_TOTALS, $plant), 'A planted copy was not matched: '.$plant);
        }
        foreach ([
            'SELECT SUM(cnl.quantity) FROM credit_note_line cnl',
            'SELECT SUM(cl.amount) FROM credit_note_line cl JOIN credit_note c ON',
            'EXISTS (SELECT 1 FROM credit_note WHERE invoice_id = ?)',
            'SELECT SUM(x.total_amount) FROM credit_note_line x',
        ] as $allowed) {
            self::assertSame(0, preg_match(self::SUM_OF_CREDIT_TOTALS, $allowed), 'An allowed shape was matched: '.$allowed);
        }
        self::assertStringContainsString('SUM(cn.total_amount) FROM credit_note', InvoiceCredit::credited(),
            'Control: InvoiceCredit no longer spells the sum the way this scan keys on, so the scan may be blind.');
    }
}
