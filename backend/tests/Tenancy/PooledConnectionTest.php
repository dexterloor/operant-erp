<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use PHPUnit\Framework\Attributes\Test;

/**
 * Extension from RED via LEAD, test 3 -- ADR-001 open item O-1, measured.
 *
 * default_pool_size=1 is what makes the R-28 leak test meaningful, but it also
 * means one open transaction owns the only backend. The architecture question
 * downstream is whether a tenant transaction may span an entire HTTP request or
 * has to close before serialization. This produces the number that answers it.
 *
 * The pool of one is a test fixture, not a production setting -- the point is
 * the *shape* of the contention, which is identical at any pool size once
 * concurrency exceeds it.
 */
final class PooledConnectionTest extends TenancyTestCase
{
    private const HOLD_SECONDS = 1.5;

    #[Test]
    public function aSecondClientBlocksForTheDurationOfAnOpenTransactionWhenThePoolHoldsOneBackend(): void
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_hold_');
        file_put_contents($readyFile, '');

        $proc = proc_open(
            [PHP_BINARY, __DIR__.'/support/hold_transaction.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'APP_DATABASE_URL' => $_ENV['APP_DATABASE_URL'],
                'HOLD_SECONDS' => (string) self::HOLD_SECONDS,
                'HOLD_TENANT' => self::TENANT_B,
                'HOLD_READY_FILE' => $readyFile,
                'PATH' => getenv('PATH'),
            ],
        );
        self::assertIsResource($proc);

        $deadline = microtime(true) + 10.0;
        while ('' === trim((string) file_get_contents($readyFile))) {
            if (microtime(true) > $deadline) {
                self::fail('Holder never opened its transaction: '.stream_get_contents($pipes[2]));
            }
            usleep(1000);
        }
        [$holderPid, $holderSeconds] = explode(' ', trim(file_get_contents($readyFile)));

        // Ground truth from Postgres itself: the holder's backend really is
        // sitting inside a transaction right now, not merely believed to be.
        $state = $this->owner()->fetchOne(
            'SELECT state FROM pg_stat_activity WHERE pid = ?', [(int) $holderPid]
        );

        $started = microtime(true);
        $pid = (int) $this->app()->fetchOne('SELECT pg_backend_pid()');
        $waitedMs = (microtime(true) - $started) * 1000;

        foreach ($pipes as $p) {
            fclose($p);
        }
        proc_close($proc);
        @unlink($readyFile);

        fwrite(STDERR, sprintf(
            "\n  [O-1 measurement] holder held a transaction for %s s on backend pid %s (observed state: %s)"
            ."\n                    second client waited %.0f ms and landed on backend pid %d\n",
            $holderSeconds, $holderPid, $state, $waitedMs, $pid,
        ));

        self::assertContains($state, ['active', 'idle in transaction'],
            'The holder was not actually inside a transaction, so nothing below is a measurement.');
        self::assertSame((int) $holderPid, $pid, 'Both clients must land on the same backend.');
        self::assertGreaterThan(
            self::HOLD_SECONDS * 1000 * 0.5,
            $waitedMs,
            'The second client did not block, so the pool is not actually shared and the leak test proves nothing.',
        );
    }
}
