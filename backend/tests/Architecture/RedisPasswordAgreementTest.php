<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **The queue's password is one value in two files, so they are compared** (Mail-C0, 2026-10-23; plan Revision 1 FINAL
 * §R5, RED L-3). `.env.infra`'s `REDIS_PASSWORD` starts Redis (compose only — rule 2 refuses the name in a PHP-loaded
 * file); `.env.worker`'s `MESSENGER_TRANSPORT_DSN` carries the same password to the worker. A drift is a worker that cannot
 * authenticate, found at the first send rather than here. From Mail-C1 the root `.env` carries the DSN too and joins the
 * comparison (plan §R10 item 6).
 *
 * Values are compared by `sha256` and never printed, returned or put in a failure message (Standing Rule 10). A missing
 * file FAILS, never skips (`ComposeCredentialExposureTest`'s F-6 rule): `make env` creates both. Each worktree's files are
 * compared with themselves.
 */
final class RedisPasswordAgreementTest extends TestCase
{
    #[Test]
    public function theQueuePasswordInTheWorkerDsnIsTheOneRedisStartsWith(): void
    {
        $root = \dirname(__DIR__, 3);
        $infra = self::value($root.'/.env.infra', 'REDIS_PASSWORD');
        $dsn = self::value($root.'/.env.worker', 'MESSENGER_TRANSPORT_DSN');
        $parts = parse_url($dsn);
        self::assertIsArray($parts, '.env.worker: MESSENGER_TRANSPORT_DSN does not parse as a URL.');
        self::assertSame('redis', $parts['scheme'] ?? null, '.env.worker: MESSENGER_TRANSPORT_DSN is not a redis:// DSN.');
        self::assertArrayNotHasKey('path', $parts, '.env.worker: MESSENGER_TRANSPORT_DSN carries a path, which merges async and failed into one stream (M-1).');
        $inDsn = rawurldecode((string) ($parts['pass'] ?? ''));

        self::assertNotSame('', $infra, '.env.infra: REDIS_PASSWORD is empty.');
        self::assertFalse(str_starts_with($infra, 'CHANGE_ME'), '.env.infra: REDIS_PASSWORD is still the template placeholder.');
        self::assertTrue(self::agree($infra, $inDsn),
            'The password inside .env.worker\'s MESSENGER_TRANSPORT_DSN is not .env.infra\'s REDIS_PASSWORD (compared by hash; neither is shown).');

        // Planted controls through the same decision.
        self::assertTrue(self::agree('a', 'a'));
        self::assertFalse(self::agree('a', 'b'), 'Two different passwords agreed.');
        self::assertFalse(self::agree('a', ''), 'A DSN with no password agreed.');
    }

    private static function agree(string $a, string $b): bool
    {
        return '' !== $b && hash_equals(hash('sha256', $a), hash('sha256', $b));
    }

    /** The value of `$name` in `$path`; fails (never skips, never prints the value) when absent. */
    private static function value(string $path, string $name): string
    {
        if (!is_file($path)) {
            self::fail(basename($path).' is missing at the repository root (make env). This check fails rather than skips.');
        }
        foreach ((array) file($path, \FILE_IGNORE_NEW_LINES) as $line) {
            if (1 === preg_match('/\A\s*(?:export\s+)?'.preg_quote($name, '/').'\s*=(.*)\z/', (string) $line, $m)) {
                return trim(trim($m[1]), '"\'');
            }
        }
        self::fail(basename($path)." does not declare $name.");
    }
}
