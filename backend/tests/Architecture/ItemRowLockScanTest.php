<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Modules\Inventory\Application\ItemWriter;
use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **Only `ItemWriter` takes the item row at strength 2 or 3** (items batch C3, 2026-11-10; `ARCHITECTURE.md` W-5, the
 * item-row amendment; `docs/session13/items-batch-sec-arch.md` "RED — on SEC/ARCH calls" L-3, built by the implementer,
 * `ARCH` may strike it). Strength 1 (`KEY SHARE`) is mostly implied by FKs and no source scan can see it; strengths 2 and
 * 3 (`FOR NO KEY UPDATE`, `FOR UPDATE`, an `UPDATE inventory_item`) are all explicit SQL. The W-5 amendment's argument
 * about which cycles can form through the item row rests on there being no other such taker, so a string literal under
 * `src/` outside `ItemWriter` that takes one is a red here — an `ARCH` decision, and a re-run of the item races.
 *
 * **Limits, stated:** the unit is one string literal, so a statement assembled from pieces (`'… FROM inventory_item'` .
 * `' FOR UPDATE'`) is not seen, and a multi-table `… JOIN inventory_item … FOR UPDATE OF other` would be a false red
 * (none exists today; it would be reworded, not exempted). Positive control: {@see ItemWriter::EDIT_LOCK} and
 * {@see ItemWriter::UNIT_LOCK} match the pattern, so a broken pattern cannot pass vacuously.
 */
final class ItemRowLockScanTest extends TestCase
{
    private const STRENGTH_2_OR_3 = '/\binventory_item\b[^;]*\bFOR\s+(NO\s+KEY\s+)?UPDATE\b|\bUPDATE\s+inventory_item\b/i';

    #[Test]
    public function noClassOutsideItemWriterTakesTheItemRowAtStrengthTwoOrThree(): void
    {
        foreach ([ItemWriter::EDIT_LOCK, ItemWriter::UNIT_LOCK, ItemWriter::DELETE_LOCK] as $control) {
            self::assertSame(1, preg_match(self::STRENGTH_2_OR_3, $control), 'Positive control: the pattern no longer matches '.$control);
        }
        self::assertSame(0, preg_match(self::STRENGTH_2_OR_3, 'SELECT 1 FROM inventory_item WHERE id = ?::uuid FOR KEY SHARE'),
            'Negative control: the pattern matches a KEY SHARE read, so it would forbid every stock and order writer.');
        self::assertSame(0, preg_match(self::STRENGTH_2_OR_3, 'SELECT 1 FROM stock_level WHERE inventory_item_id = ? FOR UPDATE'),
            'Negative control: the pattern matches inventory_item_id, a column, not the table.');

        $src = \dirname(__DIR__, 2).'/src';
        $writer = $src.'/Modules/Inventory/Application/ItemWriter.php';
        $seen = 0;
        $found = [];
        foreach (PhpSourceScan::phpFilesUnder($src) as $path) {
            $scan = PhpSourceScan::scan((string) file_get_contents($path));
            $literals = array_column($scan['outside'], 'value');
            foreach ([...$scan['methods'], ...$scan['constants'], ...$scan['properties']] as $holder) {
                $literals = [...$literals, ...array_column($holder['literals'], 'value')];
            }
            foreach ($literals as $literal) {
                ++$seen;
                if (1 === preg_match(self::STRENGTH_2_OR_3, $literal)) {
                    if ($path === $writer) {
                        continue;
                    }
                    $found[] = '  '.substr($path, \strlen($src) + 1).': '.$literal;
                }
            }
        }

        self::assertGreaterThan(0, $seen, 'The scan read no string literal under src/, so the absence below is vacuous.');
        self::assertSame([], $found, "A class outside ItemWriter takes the item row at strength 2 or 3:\n".implode("\n", $found)."\n\n"
            ."ARCHITECTURE.md W-5's item-row amendment rests on ItemWriter being the only such taker: which lock cycles can form\n"
            ."through the item row (and so which answers a unit change, a delete or a bulk archival can get) was reasoned from\n"
            ."it. Changing that is an ARCH decision: amend W-5 and re-run ItemUnitInUseTest's races and BulkArchivalLockOrderTest.");
    }
}
