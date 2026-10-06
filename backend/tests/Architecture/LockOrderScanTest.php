<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **The lock-order guard, derived from `src/`** — G-29 / G-30 part 3, C2 (design §7 test 13, L-5; a C2 blocker).
 *
 * The house takes ONE order between its two sales row locks: **`SalesOrderRowLock`, then `InvoiceRowLock`**
 * (`InvoicePayment` since sales step 7 C1; `CreditNoteWriter::adjust()` since part 3 C2, because its pending bound reads the
 * returns `CreateSalesReturn` writes under the order lock alone). **No writer may take the order lock AFTER the invoice
 * lock** — two writers taking them in opposite orders deadlock. This scan fails on any `src/` method that calls
 * `SalesOrderRowLock::holdInside()` after `InvoiceRowLock::holdInside()` in its own text, and on a method that holds the
 * invoice lock and then calls a same-class method that takes the order lock.
 *
 * It is not an extension of `PurchaseOrderRowLockTest::theInvoiceRowLockIsTheFirstStatementToTouchTheInvoice` (that proves
 * the invoice lock is the first statement touching the INVOICE row, per HTTP path); this proves the order BETWEEN the two.
 *
 * **Disclosed limit (J-12):** a per-method scan cannot see a lock taken in a callee of ANOTHER class. The house already
 * does that: `Infrastructure/OrmSalesOrderFulfilmentStatus.php:26` takes the order lock inside `FulfilmentWriter`'s
 * transaction. The same-class callee check below narrows the blind spot and does not close it. **The dynamic evidence is
 * `PurchaseOrderRowLockTest`'s races** (`aReturnAndAnAdjustmentOfOneInvoiceSerialiseOnTheOrderLock`, both orders; decision ∥
 * credit; decision ∥ adjust). A scan from each processor's entry method through every callee is the stronger form.
 *
 * **Controls, same test:** a planted source with the locks swapped is found; one with them in the house order is not; a
 * swap inside a closure is found; a same-class callee that takes the order lock after the invoice lock is found; and the
 * derived set of methods holding both locks contains `InvoicePayment::payInside` and `CreditNoteWriter::writeInside`, so an
 * empty result cannot be a blind scan.
 */
final class LockOrderScanTest extends TestCase
{
    private const ORDER_LOCK = 'SalesOrderRowLock';

    private const INVOICE_LOCK = 'InvoiceRowLock';

    #[Test]
    public function noMethodTakesTheOrderLockAfterTheInvoiceLock(): void
    {
        $swapped = self::inversions(['Planted' => <<<'PHP'
            <?php
            final class PlantedSwap
            {
                public function swapped(object $em): void
                {
                    InvoiceRowLock::holdInside($em, 'i');
                    SalesOrderRowLock::holdInside($em, 'o');
                }

                public function inAClosure(object $em): void
                {
                    $f = static function () use ($em): void {
                        \App\Modules\Sales\Application\InvoiceRowLock::holdInside($em, 'i');
                        \App\Modules\Sales\Application\SalesOrderRowLock::holdInside($em, 'o');
                    };
                }

                public function viaACallee(object $em): void
                {
                    InvoiceRowLock::holdInside($em, 'i');
                    $this->takesTheOrder($em);
                }

                private function takesTheOrder(object $em): void
                {
                    SalesOrderRowLock::holdInside($em, 'o');
                }

                public function houseOrder(object $em): void
                {
                    SalesOrderRowLock::holdInside($em, 'o');
                    InvoiceRowLock::holdInside($em, 'i');
                }

                public function invoiceOnly(object $em): void
                {
                    InvoiceRowLock::holdInside($em, 'i');
                }
            }
            PHP]);
        self::assertSame(['PlantedSwap::inAClosure', 'PlantedSwap::swapped', 'PlantedSwap::viaACallee'], array_keys($swapped),
            'The scan does not find exactly the planted inversions (a swap, a swap inside a closure, an order lock taken in a '
            .'same-class callee after the invoice lock) and none of the house-order or invoice-only methods.');

        $sources = [];
        foreach (PhpSourceScan::phpFilesUnder(\dirname(__DIR__, 2).'/src') as $path) {
            $sources[basename($path, '.php')] = (string) file_get_contents($path);
        }

        $both = [];
        foreach (self::lockSequences($sources) as $method => $sequence) {
            if (\in_array(self::ORDER_LOCK, $sequence, true) && \in_array(self::INVOICE_LOCK, $sequence, true)) {
                $both[] = $method;
            }
        }
        sort($both);
        foreach (['CreditNoteWriter::writeInside', 'InvoicePayment::payInside'] as $expected) {
            self::assertContains($expected, $both, 'Control: the scan no longer sees a method known to hold both locks ('.$expected.'), so it may be blind.');
        }

        self::assertSame([], self::inversions($sources),
            "A method takes SalesOrderRowLock AFTER InvoiceRowLock. The house order is the order lock first, then the invoice\n"
            .'lock (InvoicePayment, CreditNoteWriter::adjust). Two writers taking them in opposite orders deadlock; take the '
            .'order lock first, or take the invoice lock alone.');
    }

    /** `CreditNoteWriter::writeInside` takes the order lock first on the adjust path and never on the issue path. */
    #[Test]
    public function theAdjustPathTakesTheOrderLockThenTheInvoiceLock(): void
    {
        $sources = [];
        foreach (['CreditNoteWriter', 'InvoicePayment', 'SalesReturnWriter', 'InvoiceVoiding', 'InvoicePaymentReversal', 'AdjustmentDecisionWriter'] as $class) {
            $sources[$class] = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Modules/Sales/Application/'.$class.'.php');
        }
        $sequences = self::lockSequences($sources);

        self::assertSame([self::ORDER_LOCK, self::INVOICE_LOCK], $sequences['CreditNoteWriter::writeInside'] ?? null,
            'The adjust path\'s first two locking statements must be the order lock, then the invoice lock.');
        self::assertSame([self::ORDER_LOCK, self::INVOICE_LOCK], $sequences['InvoicePayment::payInside'] ?? null, 'The precedent changed.');
        self::assertSame([self::INVOICE_LOCK], $sequences['AdjustmentDecisionWriter::decideInside'] ?? null,
            'Decisions hold the invoice lock alone (design §4.2); a decision writer that takes the order lock after it deadlocks the adjust path.');
        self::assertSame([self::INVOICE_LOCK], $sequences['InvoiceVoiding::voidInside'] ?? $sequences['InvoiceVoiding::void'] ?? null);
    }

    /**
     * @param array<string, string> $sources class short name => file source
     *
     * @return array<string, list<string>> `Class::method` => the sequence of `*RowLock::holdInside()` calls in its text
     */
    private static function lockSequences(array $sources): array
    {
        $found = [];
        foreach ($sources as $fallback => $source) {
            // Keyed by the class the file DECLARES (the way `PhpSourceScan` keys its methods), not by the file's name.
            $tokens = token_get_all($source);
            $count = \count($tokens);
            $class = (string) $fallback;
            for ($c = 0; $c < $count; ++$c) {
                if (\is_array($tokens[$c]) && \in_array($tokens[$c][0], [\T_CLASS, \T_TRAIT, \T_ENUM], true)) {
                    for ($n = $c + 1; $n < $count && \is_array($tokens[$n]) && \T_WHITESPACE === $tokens[$n][0]; ++$n) {
                    }
                    if (isset($tokens[$n]) && \is_array($tokens[$n]) && \T_STRING === $tokens[$n][0]) {
                        $class = $tokens[$n][1];
                        break;
                    }
                }
            }
            for ($i = 0; $i < $count; ++$i) {
                if (!\is_array($tokens[$i]) || \T_FUNCTION !== $tokens[$i][0]) {
                    continue;
                }
                $j = $i + 1;
                while ($j < $count && (\T_WHITESPACE === ($tokens[$j][0] ?? null) || '&' === $tokens[$j])) {
                    ++$j;
                }
                if (!isset($tokens[$j]) || !\is_array($tokens[$j]) || \T_STRING !== $tokens[$j][0]) {
                    continue; // a closure or arrow function: it belongs to its enclosing method
                }
                $name = $tokens[$j][1];

                // The parameter list, then the body's opening brace (or `;` for an abstract method).
                $paren = 0;
                $k = $j + 1;
                for (; $k < $count; ++$k) {
                    if ('(' === $tokens[$k]) {
                        ++$paren;
                    } elseif (')' === $tokens[$k]) {
                        --$paren;
                        if (0 === $paren) {
                            break;
                        }
                    }
                }
                for (++$k; $k < $count && '{' !== $tokens[$k] && ';' !== $tokens[$k]; ++$k) {
                }
                if (!isset($tokens[$k]) || '{' !== $tokens[$k]) {
                    continue;
                }

                $depth = 0;
                $sequence = [];
                for (; $k < $count; ++$k) {
                    $token = $tokens[$k];
                    if ('{' === $token || (\is_array($token) && \in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                        ++$depth;
                    } elseif ('}' === $token) {
                        --$depth;
                        if (0 === $depth) {
                            break;
                        }
                    }
                    if (!\is_array($token) || !str_ends_with($token[1], self::ORDER_LOCK) && !str_ends_with($token[1], self::INVOICE_LOCK)) {
                        continue;
                    }
                    if (!\in_array($token[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                        continue;
                    }
                    $m = $k + 1;
                    while (isset($tokens[$m]) && \is_array($tokens[$m]) && \T_WHITESPACE === $tokens[$m][0]) {
                        ++$m;
                    }
                    if (!isset($tokens[$m]) || !\is_array($tokens[$m]) || \T_DOUBLE_COLON !== $tokens[$m][0]) {
                        continue;
                    }
                    $m++;
                    while (isset($tokens[$m]) && \is_array($tokens[$m]) && \T_WHITESPACE === $tokens[$m][0]) {
                        ++$m;
                    }
                    if (isset($tokens[$m]) && \is_array($tokens[$m]) && 'holdInside' === $tokens[$m][1]) {
                        $sequence[] = str_ends_with($token[1], self::ORDER_LOCK) ? self::ORDER_LOCK : self::INVOICE_LOCK;
                    }
                }
                $found[$class.'::'.$name] = $sequence;
            }
        }

        return $found;
    }

    /**
     * @param array<string, string> $sources
     *
     * @return array<string, string> the offending `Class::method` => why
     */
    private static function inversions(array $sources): array
    {
        $sequences = self::lockSequences($sources);
        $offenders = [];
        foreach ($sequences as $method => $sequence) {
            $firstInvoice = array_search(self::INVOICE_LOCK, $sequence, true);
            if (false !== $firstInvoice && \in_array(self::ORDER_LOCK, \array_slice($sequence, (int) $firstInvoice), true)) {
                $offenders[$method] = 'takes the order lock after the invoice lock';
            }
        }

        // The same-class callee check: a method that holds the invoice lock and then calls `$this->x()` / `self::x()` where
        // x takes the order lock. Calls come from `PhpSourceScan` (names only).
        foreach ($sources as $class => $source) {
            $scan = PhpSourceScan::scan($source);
            foreach ($scan['methods'] as $key => $info) {
                if (!\in_array(self::INVOICE_LOCK, $sequences[$key] ?? [], true)) {
                    continue;
                }
                foreach ($info['calls'] as $callee) {
                    $calleeKey = $info['class'].'::'.$callee;
                    if ($calleeKey !== $key && \in_array(self::ORDER_LOCK, $sequences[$calleeKey] ?? [], true)) {
                        $offenders[$key] ??= 'calls '.$calleeKey.', which takes the order lock, while holding the invoice lock';
                    }
                }
            }
            unset($class);
        }
        ksort($offenders);

        return $offenders;
    }
}
