<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **W-5's party-lock rule 3, derived from `src/`** (`ARCHITECTURE.md` W-5 amendment, "the party row locks", 2026-10-14;
 * `docs/session13/owed-arch-c5.md` F-8 and follow-up item C; RED M-4).
 *
 * Archiving a party (`DeleteSupplier`, `DeleteCustomer`, and since 2026-10-20 `DeleteWarehouse` — the warehouse row locks
 * amendment, `docs/session13/wh-loc-delete-arch.md` §3–§4) takes the party row `FOR UPDATE` as its FIRST row lock, and then
 * **no further document, party or child row lock**: only the same-row `UPDATE`, a plain read (the open-work count, through
 * the A-2 Port) and the audit insert. Every other path takes a party lock AFTER its document lock and BEFORE any child lock,
 * so the moment an archiver took a document or child lock after its `FOR UPDATE`, two writers could take the same two rows
 * in opposite orders and deadlock. Until this test that was `[judgement]`.
 *
 * **What it derives.** A *party lock* is any SQL text in `src/` — a class constant's value, or a string literal in a method
 * body — that reads `FROM supplier`, `FROM customer` or `FROM warehouse` and ends `FOR UPDATE` (never `FOR NO KEY UPDATE`, which Update and
 * Restore take and which conflicts with nothing a document path holds). A method *holds* it when its body names such a
 * constant (`self::X`, `static::X`, `Class::X`) or contains such a literal; closures belong to their enclosing method. After
 * the first party lock in a method's text, the scan fails on:
 *   - any `*RowLock::holdInside()` / `*Lock::holdInside()` call (the house's document and role locks);
 *   - any locking statement (`FOR UPDATE`, `FOR NO KEY UPDATE`, `FOR SHARE`, `FOR KEY SHARE`) on any table
 *     (2026-10-20: the table list was removed — rule 3 forbids every row lock after the party lock, and the list form
 *     checked only the tables someone remembered: it omitted `stock_level`, the likeliest wrong turn in a `DeleteWarehouse`;
 *     `wh-loc-delete-arch.md` §4), or a party table again. A string literal that merely mentions a lock clause after the
 *     party lock is red too — loud; fix the wording.
 * Keyed on the statement's TEXT, not on the constant's name, so renaming `DELETE_LOCK` cannot blind it.
 *
 * **Anti-vacuity, same test:** the derived set of party-lock holders is exactly the three Deletes (`CustomerWriter::delete`,
 * `SupplierWriter::delete`, `WarehouseWriter::delete`); a planted inversion of each kind is found, and the house shape is not
 * — including `PlantedWarehouseWriter`'s bin `FOR KEY SHARE` and `stock_level` `FOR SHARE` after the warehouse lock (the second
 * green under the old table list: ARCH §4's evidence).
 *
 * **Disclosed limits** (the `LockOrderScanTest` precedent): concatenated or `sprintf`-built SQL is invisible, and so is a
 * lock taken in a callee of ANOTHER class (`$this->openWork->…` is exactly such a call today — it locks nothing, which is
 * reasoned, not scanned). The dynamic evidence is the two-connection races in `SupplierDeleteTest` / `CustomerDeleteTest`.
 * `warehouse` joined `PARTY_TABLES` with `DeleteWarehouse` (C4, 2026-10-20). **Not scanned, disclosed:** `DeleteStorageLocation`
 * (a child writer under rule 2: warehouse `SHARE` → bin `FOR UPDATE`, then nothing) and warehouse-before-bin (W-5 warehouse
 * rule 2) — judgement plus their races (`StorageLocationDeleteTest`, `WarehouseDeleteTest`, M0-3/M0-4b).
 *
 * **Mutations:** plant `SalesOrderRowLock::holdInside($em, $id);` after `CustomerWriter::DELETE_LOCK`'s fetch → red,
 * naming `CustomerWriter::delete`; change `SupplierWriter::DELETE_LOCK` to `FOR NO KEY UPDATE` → the anti-vacuity
 * assertion is red (and M12's races are red too).
 */
final class PartyLockOrderScanTest extends TestCase
{
    // `warehouse` joined 2026-10-20 (wh-loc-delete C4). `\b` falls between no two word characters, so `customer` never
    // matches `customer_contact` and `warehouse` never matches inside another identifier.
    private const PARTY_TABLES = 'supplier|customer|warehouse';

    #[Test]
    public function noPartyArchiverTakesARowLockAfterThePartyLock(): void
    {
        $planted = self::scan(['Planted' => <<<'PHP'
            <?php
            final class PlantedPartyWriter
            {
                public const DELETE_LOCK = 'SELECT 1 FROM customer WHERE id = ?::uuid FOR UPDATE';
                private const ADDRESS_LOCK = <<<'SQL'
                    SELECT 1 FROM customer_address WHERE id = ?::uuid FOR NO KEY UPDATE
                    SQL;

                public function documentLockAfter(object $em): void
                {
                    $em->fetch(self::DELETE_LOCK);
                    SalesOrderRowLock::holdInside($em, 'o');
                }

                public function childLockAfter(object $em): void
                {
                    $f = function () use ($em): void {
                        $em->fetch(self::DELETE_LOCK);
                        $em->fetch(self::ADDRESS_LOCK);
                    };
                }

                public function literalLockAfter(object $em): void
                {
                    $em->fetch('SELECT 1 FROM supplier WHERE id = ? FOR UPDATE');
                    $em->fetch('SELECT 1 FROM invoice WHERE id = ? FOR KEY SHARE');
                }

                public function houseShape(object $em): void
                {
                    $em->fetch(self::DELETE_LOCK);
                    $em->execute('UPDATE customer SET deleted_at = now() WHERE id = ?');
                    $em->fetch('SELECT count(*) FROM sales_order WHERE customer_id = ?');
                }

                public function updateIsNotAPartyLock(object $em): void
                {
                    $em->fetch('SELECT 1 FROM customer WHERE id = ? FOR NO KEY UPDATE');
                    SalesOrderRowLock::holdInside($em, 'o');
                }
            }
            PHP, 'PlantedWarehouse' => <<<'PHP'
            <?php
            final class PlantedWarehouseWriter
            {
                public const DELETE_LOCK = 'SELECT 1 FROM warehouse WHERE id = ?::uuid FOR UPDATE';

                public function binLockAfter(object $em): void
                {
                    $em->fetch(self::DELETE_LOCK);
                    $em->fetch('SELECT 1 FROM storage_location WHERE warehouse_id = ? FOR KEY SHARE');
                }

                public function levelLockAfter(object $em): void
                {
                    $em->fetch(self::DELETE_LOCK);
                    $em->fetch('SELECT 1 FROM stock_level WHERE storage_location_id = ? FOR SHARE');
                }

                public function warehouseHouseShape(object $em): void
                {
                    $em->fetch(self::DELETE_LOCK);
                    $em->execute('UPDATE warehouse SET deleted_at = now() WHERE id = ?');
                }

                public function restoreIsNotAPartyLock(object $em): void
                {
                    $em->fetch('SELECT 1 FROM warehouse WHERE id = ? FOR NO KEY UPDATE');
                    $em->fetch('SELECT 1 FROM storage_location WHERE id = ? FOR UPDATE');
                }
            }
            PHP]);
        self::assertSame(['PlantedPartyWriter::childLockAfter', 'PlantedPartyWriter::documentLockAfter',
            'PlantedPartyWriter::houseShape', 'PlantedPartyWriter::literalLockAfter', 'PlantedWarehouseWriter::binLockAfter',
            'PlantedWarehouseWriter::levelLockAfter', 'PlantedWarehouseWriter::warehouseHouseShape'], array_keys($planted['holders']),
            'Control: the scan does not derive exactly the planted party-lock holders (constant, constant in a closure, literal, '
            .'house shape) and nothing that takes only FOR NO KEY UPDATE.');
        self::assertSame(['PlantedPartyWriter::childLockAfter', 'PlantedPartyWriter::documentLockAfter', 'PlantedPartyWriter::literalLockAfter',
            'PlantedWarehouseWriter::binLockAfter', 'PlantedWarehouseWriter::levelLockAfter'],
            array_keys($planted['offenders']),
            'Control: the scan does not find exactly the planted inversions (a document row lock, a child lock in a closure, a '
            .'literal document lock) and passes the house shape.');

        $sources = [];
        foreach (PhpSourceScan::phpFilesUnder(\dirname(__DIR__, 2).'/src') as $path) {
            $sources[$path] = (string) file_get_contents($path);
        }
        $real = self::scan($sources);

        self::assertSame(['CustomerWriter::delete', 'SupplierWriter::delete', 'WarehouseWriter::delete'], array_keys($real['holders']),
            'Anti-vacuity: the methods taking a party row FOR UPDATE are no longer exactly the three Deletes. A new archiver must '
            .'be read against W-5 rule 3 (a new party goes into PARTY_TABLES); a Delete that no longer takes FOR UPDATE lets '
            .'document or stock writers past its archive (M12, M10).');

        self::assertSame([], $real['offenders'],
            "A method takes a row lock AFTER taking a party row FOR UPDATE (W-5 amendment, rule 3). The three Deletes take the party\n"
            .'row first and nothing after it but the same-row UPDATE, a plain read and the audit insert; every other path takes '
            .'the party lock after its document lock and before any child lock, so a lock here can deadlock against them.');
    }

    /**
     * @param array<string, string> $sources any key => file source
     *
     * @return array{holders: array<string, true>, offenders: array<string, string>}
     */
    private static function scan(array $sources): array
    {
        $partyLock = '/\bFROM\s+(?:'.self::PARTY_TABLES.')\b[\s\S]*\bFOR\s+UPDATE\b/i';
        $anyLock = '/\bFOR\s+(?:UPDATE|NO\s+KEY\s+UPDATE|SHARE|KEY\s+SHARE)\b/i';

        // Pass 1: every class constant's string value, keyed `Class::NAME` by the class the file declares.
        $constants = [];
        $classOf = [];
        foreach ($sources as $key => $source) {
            $class = self::declaredClass($source) ?? (string) $key;
            $classOf[$key] = $class;
            preg_match_all('/\bconst\s+(\w+)\s*=\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|<<<\'?(\w+)\'?\n([\s\S]*?)\n\s*\4\b)/', $source, $all, \PREG_SET_ORDER);
            foreach ($all as $c) {
                $constants[$class.'::'.$c[1]] = ($c[2] ?? '').($c[3] ?? '').($c[5] ?? '');
            }
        }

        $holders = [];
        $offenders = [];
        foreach ($sources as $key => $source) {
            $class = $classOf[$key];
            foreach (self::methodBodies($source) as $method => $tokens) {
                $seenPartyLock = false;
                $count = \count($tokens);
                for ($k = 0; $k < $count; ++$k) {
                    $token = $tokens[$k];
                    if (!\is_array($token)) {
                        continue;
                    }
                    $sql = null;
                    $lockCall = null;
                    if (\in_array($token[0], [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)) {
                        $sql = $token[1];
                    } elseif (\in_array($token[0], [\T_STRING, \T_STATIC, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                        $m = $k + 1;
                        while (isset($tokens[$m]) && \is_array($tokens[$m]) && \T_WHITESPACE === $tokens[$m][0]) {
                            ++$m;
                        }
                        if (!isset($tokens[$m]) || !\is_array($tokens[$m]) || \T_DOUBLE_COLON !== $tokens[$m][0]) {
                            continue;
                        }
                        ++$m;
                        while (isset($tokens[$m]) && \is_array($tokens[$m]) && \T_WHITESPACE === $tokens[$m][0]) {
                            ++$m;
                        }
                        if (!isset($tokens[$m]) || !\is_array($tokens[$m]) || \T_STRING !== $tokens[$m][0]) {
                            continue;
                        }
                        $owner = strtolower($token[1]);
                        $short = \in_array($owner, ['self', 'static'], true) ? $class : substr((string) strrchr('\\'.$token[1], '\\'), 1);
                        if ('holdInside' === $tokens[$m][1] && str_ends_with($short, 'Lock')) {
                            $lockCall = $short.'::holdInside()';
                        } else {
                            $sql = $constants[$short.'::'.$tokens[$m][1]] ?? null;
                        }
                    }

                    if (null !== $sql && 1 === preg_match($partyLock, $sql)) {
                        if ($seenPartyLock) {
                            $offenders[$class.'::'.$method] ??= 'takes a second party row lock';
                        }
                        $seenPartyLock = true;
                        $holders[$class.'::'.$method] = true;
                        continue;
                    }
                    if (!$seenPartyLock) {
                        continue;
                    }
                    if (null !== $lockCall) {
                        $offenders[$class.'::'.$method] ??= 'calls '.$lockCall.' after the party lock';
                    } elseif (null !== $sql && 1 === preg_match($anyLock, $sql)) {
                        $offenders[$class.'::'.$method] ??= 'runs a locking statement after the party lock: '.trim(preg_replace('/\s+/', ' ', $sql) ?? '');
                    }
                }
            }
        }
        ksort($holders);
        ksort($offenders);

        return ['holders' => $holders, 'offenders' => $offenders];
    }

    /** The class (or trait, enum, interface) the file declares, read from tokens so a docblock's "the class X" is not it. */
    private static function declaredClass(string $source): ?string
    {
        $tokens = token_get_all($source);
        $count = \count($tokens);
        for ($c = 0; $c < $count; ++$c) {
            if (\is_array($tokens[$c]) && \in_array($tokens[$c][0], [\T_CLASS, \T_TRAIT, \T_ENUM, \T_INTERFACE], true)) {
                for ($n = $c + 1; $n < $count && \is_array($tokens[$n]) && \T_WHITESPACE === $tokens[$n][0]; ++$n) {
                }
                if (isset($tokens[$n]) && \is_array($tokens[$n]) && \T_STRING === $tokens[$n][0]) {
                    return $tokens[$n][1];
                }
            }
        }

        return null;
    }

    /**
     * Each named method's body as tokens (closures and arrow functions stay inside their enclosing method).
     *
     * @return array<string, list<mixed>>
     */
    private static function methodBodies(string $source): array
    {
        $tokens = token_get_all($source);
        $count = \count($tokens);
        $bodies = [];
        for ($i = 0; $i < $count; ++$i) {
            if (!\is_array($tokens[$i]) || \T_FUNCTION !== $tokens[$i][0]) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && (\T_WHITESPACE === ($tokens[$j][0] ?? null) || '&' === $tokens[$j])) {
                ++$j;
            }
            if (!isset($tokens[$j]) || !\is_array($tokens[$j]) || \T_STRING !== $tokens[$j][0]) {
                continue;
            }
            $name = $tokens[$j][1];
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
            $body = [];
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
                $body[] = $token;
            }
            $bodies[$name] = $body;
            $i = $k;
        }

        return $bodies;
    }
}
