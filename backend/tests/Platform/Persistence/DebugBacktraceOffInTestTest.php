<?php

declare(strict_types=1);

namespace App\Tests\Platform\Persistence;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Under test, Doctrine's debug middleware records no backtrace for any connection (`config/packages/doctrine.yaml`,
 * `app.doctrine.collect_backtrace`).
 *
 * The middleware is on whenever `%kernel.debug%` is, and the test kernel is a debug kernel with reboot disabled, so a
 * backtrace per statement accumulates for a whole test. Measured 2026-10-20: `ResponseShapeTest::everyReferenceIsExpanded`
 * ran ~36,000 owner statements whose backtraces held 313 MB of a 473 MB peak, and a full gate died at the 512M limit.
 * Nothing in the project reads them. This test runs one statement on every configured connection and fails if the
 * holder kept a backtrace for any of them -- so a revert of the parameter, or a new connection copied with
 * `'%kernel.debug%'`, goes red here instead of as an out-of-memory fatal two thousand tests into a gate.
 *
 * Shown red at build by setting the `when@test` value back to `'%kernel.debug%'`.
 */
final class DebugBacktraceOffInTestTest extends KernelTestCase
{
    #[Test]
    public function noConnectionCollectsABacktracePerStatementUnderTest(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $holder = $container->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder, 'the debug middleware is gone, so this pin no longer has a subject; remove it deliberately');
        $holder->reset();

        /** @var array<string, string> $connections */
        $connections = $container->getParameter('doctrine.connections');
        self::assertCount(7, $connections, 'the seven connections of doctrine.yaml (Mail-C1 added worker)');

        foreach ($connections as $name => $serviceId) {
            $connection = $container->get($serviceId);
            self::assertInstanceOf(Connection::class, $connection);
            self::assertSame(1, (int) $connection->fetchOne('SELECT 1'), $name);
        }

        $data = $holder->getData();
        foreach (array_keys($connections) as $name) {
            self::assertNotEmpty($data[$name] ?? [], \sprintf('the debug holder recorded nothing on `%s`, so the absence of a backtrace proves nothing', $name));
            foreach ($data[$name] as $record) {
                self::assertArrayNotHasKey('backtrace', $record, \sprintf('connection `%s` collects a backtrace per statement under test', $name));
            }
        }
    }
}
