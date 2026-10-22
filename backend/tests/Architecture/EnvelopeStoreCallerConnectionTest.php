<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Platform\Http\Idempotency\IdempotencyEnvelopeStore;
use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **Every envelope-store call passes the tenancy run's own connection** (owed "with the first caller" by G5-C0,
 * `IdempotencyEnvelopeStore`'s docblock and `ConnectionBindingTest`; built at G5-C2, the first caller — RED `g5-c2-red.md`
 * M-2). The store takes a `Connection` it cannot identify: an owner connection inside a transaction with the tenant setting
 * in place passes all of its own guards, and on Neon the owner bypasses RLS. So the CALLER is held: in every `src/` file
 * naming the store, each `->lookup(` / `->record(` / `->replayTheWinner(` call's first argument is a variable assigned, in
 * that file, from `$em->getConnection()`, where `$em` is the parameter of a closure handed to `->run(` (the
 * `TenantContext` run's manager, which is `erp_app`'s).
 *
 * **Limits, stated:** textual, per file — it cannot tell two closures apart, nor follow a connection passed through a
 * method; a caller needing either is red here and must be reviewed. *Plant control:*
 * {@see self::anOwnerConnectionPassedToTheStoreIsFound}.
 */
final class EnvelopeStoreCallerConnectionTest extends TestCase
{
    #[Test]
    public function everyStoreCallPassesTheRunsOwnConnection(): void
    {
        $root = \dirname(__DIR__, 2);
        $calls = 0;
        $violations = [];
        foreach (PhpSourceScan::phpFilesUnder($root.'/src') as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, 'IdempotencyEnvelopeStore') || str_ends_with($file, '/IdempotencyEnvelopeStore.php')) {
                continue;
            }
            [$found, $bad] = self::check($source);
            $calls += $found;
            foreach ($bad as $b) {
                $violations[] = substr($file, \strlen($root) + 1).': '.$b;
            }
        }

        self::assertGreaterThanOrEqual(3, $calls, 'Anti-vacuity: the store\'s three calls in BulkArchival were not found.');
        self::assertSame([], $violations, 'An envelope-store call is handed a connection that is not the tenancy run\'s own.');
    }

    #[Test]
    public function anOwnerConnectionPassedToTheStoreIsFound(): void
    {
        [$found, $bad] = self::check(<<<'PHP'
            <?php
            $this->tenants->run($t, function (EntityManagerInterface $em) {
                $connection = $em->getConnection();
                $this->store->lookup($connection, 'CreateBulkArchival', $k, $d);
                $this->store->record($this->ownerConnection, $t, 'CreateBulkArchival', $k, $d, 200, '{}', []);
            });
            PHP);

        self::assertSame(2, $found);
        self::assertCount(1, $bad);
        self::assertStringContainsString('record', $bad[0]);
    }

    /** @return array{0: int, 1: list<string>} calls found, and the ones whose connection is not the run's */
    private static function check(string $source): array
    {
        $runsAnEm = 1 === preg_match('/->run\([^;]*?function\s*\(\s*EntityManagerInterface\s+\$em\s*\)/s', $source);
        preg_match_all('/->(lookup|record|replayTheWinner)\(\s*([^,\s]+)\s*,/', $source, $m, \PREG_SET_ORDER);
        $bad = [];
        foreach ($m as [, $method, $argument]) {
            $variable = ltrim($argument, '$');
            $fromTheRun = 1 === preg_match('/^\w+$/', $variable)
                && 1 === preg_match('/\$'.$variable.'\s*=\s*\$em->getConnection\(\)\s*;/', $source);
            if (!$runsAnEm || !$fromTheRun) {
                $bad[] = $method.'('.$argument.', …)';
            }
        }

        return [\count($m), $bad];
    }

    /** Keeps the `use` honest: the class this test is about exists under the name the scan matches. */
    #[Test]
    public function theStoreIsTheClassTheScanNames(): void
    {
        self::assertSame('IdempotencyEnvelopeStore', (new \ReflectionClass(IdempotencyEnvelopeStore::class))->getShortName());
    }
}
