<?php

declare(strict_types=1);

namespace App\Tests\DemoSeed;

use ErpDemoSeed\Locality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__, 2).'/bin/demo-seed.php';

/**
 * **W-3: where `bin/demo-seed.php` refuses to run — the pure refusal functions, one case per check** (the primary
 * evidence; `DemoSeedProcessTest` adds process-level cases). Each check removed must redden its own case here; the
 * purpose check's `demo-test-` exception is pinned from both sides (gate + test + `demo-test-` passes; gate without the
 * prefix, or under `dev`, refuses).
 */
final class DemoSeedLocalityTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: bool, 3: ?string, 4: string, 5: ?string}> */
    public static function cases(): iterable
    {
        yield 'all pass: dev, loopback, NOBYPASSRLS, the demo database' => ['dev', '127.0.0.1', false, 'erp-local-demo', 'demo', null];
        yield 'all pass: localhost' => ['dev', 'localhost', false, 'erp-local-demo', 'demo', null];
        yield 'all pass: ::1' => ['test', '::1', false, 'erp-local-demo', 'demo', null];
        yield 'all pass: the suite\'s own process test on the gate database' => ['test', '127.0.0.1', false, 'erp-local-gate', 'demo-test-abc', null];
        yield 'APP_ENV prod' => ['prod', '127.0.0.1', false, 'erp-local-demo', 'demo', Locality::REFUSED_ENVIRONMENT];
        yield 'APP_ENV unset' => ['', '127.0.0.1', false, 'erp-local-demo', 'demo', Locality::REFUSED_ENVIRONMENT];
        yield 'a remote host' => ['dev', 'ep-cool-name.eu-central-1.aws.neon.tech', false, 'erp-local-demo', 'demo', Locality::REFUSED_HOST];
        yield 'a LAN address' => ['dev', '192.168.1.20', false, 'erp-local-demo', 'demo', Locality::REFUSED_HOST];
        yield 'no host (a socket)' => ['dev', '', false, 'erp-local-demo', 'demo', Locality::REFUSED_HOST];
        yield 'an owner that bypasses RLS' => ['dev', '127.0.0.1', true, 'erp-local-demo', 'demo', Locality::REFUSED_BYPASS];
        yield 'the gate database, a normal workspace' => ['test', '127.0.0.1', false, 'erp-local-gate', 'demo', Locality::REFUSED_PURPOSE];
        yield 'the gate database, demo-test- under dev' => ['dev', '127.0.0.1', false, 'erp-local-gate', 'demo-test-abc', Locality::REFUSED_PURPOSE];
        yield 'a database with no comment (an old volume)' => ['dev', '127.0.0.1', false, null, 'demo', Locality::REFUSED_PURPOSE];
        yield 'another purpose' => ['dev', '127.0.0.1', false, 'erp-local-live', 'demo', Locality::REFUSED_PURPOSE];
    }

    #[Test]
    #[DataProvider('cases')]
    public function eachCheckRefusesAloneAndAllPassTogether(string $appEnv, string $host, bool $bypass, ?string $comment, string $slug, ?string $expected): void
    {
        self::assertSame($expected, Locality::refusal($appEnv, $host, $bypass, $comment, $slug));
    }
}
