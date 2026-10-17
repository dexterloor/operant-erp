<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Modules\Inventory\Application\StockAdjuster;
use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **V1-C1c, discharged at the stock count's C1** (2026-10-16; `API.md` §15.1 `CreateStockAdjustment`'s carry, owner
 * `BE-LEAD`): an optimistic-lock translator added for this build must not make `CreateGoodsReceipt` — or anything else —
 * raise `stale-record`. `stale-record` is §5.2's answer to a lost `stock_level.version` and nothing else.
 *
 * So the set of `src/` CLASSES that construct it (`ApiProblem::staleRecord(`) is pinned to exactly
 * `{StockAdjuster}` (design R1.3, first RED L-11: one class, not one call site — the writer answers both the version
 * mismatch and the insert race), and no `src/` file names `Doctrine\ORM\OptimisticLockException` outside a comment: a
 * listener translating it would be the generic arm V1-C1c fears, reaching every operation at once. `StockLevel`'s
 * `#[ORM\Version]` stays (the design's §13 item 6), and nothing writes the level through the ORM.
 *
 * **Positive controls, same test:** the scan finds the real caller (a scan that reads nothing reports `{}` and would pass
 * a "no other caller" check), and a planted listener source naming both is reported.
 */
final class StaleRecordCallerTest extends TestCase
{
    private const EXPECTED_CALLERS = [StockAdjuster::class];

    #[Test]
    public function onlyTheStockCountRaisesStaleRecordAndNothingTranslatesTheOrmsOptimisticLock(): void
    {
        $src = \dirname(__DIR__, 2).'/src/';
        $callers = [];
        $namers = [];
        foreach (PhpSourceScan::phpFilesUnder(rtrim($src, '/')) as $path) {
            $class = 'App\\'.str_replace('/', '\\', substr($path, \strlen($src), -\strlen('.php')));
            $code = self::withoutComments((string) file_get_contents($path));
            [$calls, $names] = self::findings($code);
            if ($calls && !str_ends_with($path, 'Platform/Http/Problem/ApiProblem.php')) {
                $callers[] = $class;
            }
            if ($names) {
                $namers[] = $class;
            }
        }
        sort($callers);

        self::assertContains(StockAdjuster::class, $callers, 'Positive control: the scan did not find StockAdjuster\'s call, so it reads nothing.');
        self::assertSame([true, true], self::findings(self::withoutComments(<<<'PHP'
            <?php
            use Doctrine\ORM\OptimisticLockException;
            final class PlantedListener { public function on(OptimisticLockException $e): never { throw \App\Platform\Http\Problem\ApiProblem::staleRecord([]); } }
            PHP)), 'Planted control: a listener translating the ORM\'s optimistic lock into stale-record was not seen.');

        self::assertSame(self::EXPECTED_CALLERS, $callers,
            "stale-record is raised by a class other than the stock count's writer (V1-C1c). It is §5.2's answer to a lost "
            ."stock_level.version, and CreateGoodsReceipt's Errors cell does not list it. A new caller is a contract change: its "
            ."operation's §4.2 Errors cell must list stale-record, and V1-C1c's owner (BE-LEAD) and PO decide it.");
        self::assertSame([], $namers,
            'A src/ class names Doctrine\ORM\OptimisticLockException: a translator for it would make every ORM write path raise '
            .'stale-record (V1-C1c). Nothing writes stock_level through the ORM; keep it that way or bring it to BE-LEAD.');
    }

    /** @return array{0: bool, 1: bool} [constructs stale-record, names OptimisticLockException] */
    private static function findings(string $code): array
    {
        return [
            1 === preg_match('/\bstaleRecord\s*\(/', $code),
            1 === preg_match('/\bOptimisticLockException\b/', $code),
        ];
    }

    private static function withoutComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= \is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
