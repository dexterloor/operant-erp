<?php

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Modules\UserManagement\Domain\SealsIdentities;
use App\Modules\UserManagement\Infrastructure\CredentialSeal;
use App\Modules\UserManagement\Infrastructure\Messaging\WorkerKeyCheck;
use App\Platform\Tenancy\DatabaseRoles;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Worker;

/**
 * **T-S3 (Mail-C1, G-75 T-G75-2; RED L-3, L-4): the mail worker refuses to start on a wrong key id, and starts with a
 * 10-second socket timeout and exception arguments off.**
 *
 * The process half runs `bin/console messenger:consume async --limit=1 --time-limit=1` as a child with an explicit prod
 * environment (the `ServingCredentialGuardProcessTest` shape: dummy secrets, every DSN at `127.0.0.1:1`, the queue
 * `in-memory://default`, so nothing connects): a mismatching `AUTH_CREDENTIAL_MAC_KEY_ID` → non-zero exit before any
 * message; the matching id → exit 0. Mutation MU-17 (remove `WorkerKeyCheck`) → the mismatching case exits 0 → red.
 * The in-process half drives the subscriber and reads the two settings back (restored afterwards). Not reproducible
 * in-process: a setting `ini_set` cannot change (review check, T-S3b).
 */
final class WorkerKeyCheckTest extends KernelTestCase
{
    private const KEY = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    public static function tearDownAfterClass(): void
    {
        $dir = self::cacheDir();
        if (is_dir($dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
    }

    private static function cacheDir(): string
    {
        return sys_get_temp_dir().'/erp-worker-key-check-'.getmypid();
    }

    #[Test]
    public function theWorkerRefusesToStartOnAMismatchingKeyId(): void
    {
        $id = (new CredentialSeal(self::KEY))->keyId();

        $refused = self::consume('deadbeef' === $id ? 'feedface' : 'deadbeef');
        self::assertNotSame(0, $refused['exit'], 'The worker started with a key id that is not its key\'s.');
        self::assertStringContainsString('AUTH_CREDENTIAL_MAC_KEY_ID', $refused['out'], 'The refusal does not name the variable.');
        self::assertStringNotContainsString(self::KEY, $refused['out'], 'The refusal echoed the key.');

        $started = self::consume($id);
        self::assertSame(0, $started['exit'], 'Control: the worker did not start with the matching id: '.substr($started['out'], -500));
    }

    #[Test]
    public function theWorkerStartsWithATenSecondSocketTimeoutAndNoExceptionArguments(): void
    {
        $timeout = ini_get('default_socket_timeout');
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        $built = 0;
        try {
            ini_set('default_socket_timeout', '60');
            ini_set('zend.exception_ignore_args', '0');
            $check = new WorkerKeyCheck(static function () use (&$built): SealsIdentities {
                ++$built;

                return new CredentialSeal(self::KEY);
            });
            $check->onWorkerStarted(new WorkerStartedEvent((new \ReflectionClass(Worker::class))->newInstanceWithoutConstructor()));

            self::assertSame('10', ini_get('default_socket_timeout'));
            self::assertSame('1', ini_get('zend.exception_ignore_args'));
            self::assertSame(1, $built, 'The seal was not built at worker start, so a wrong key would wait for the first message.');
        } finally {
            ini_set('default_socket_timeout', (string) $timeout);
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }
        self::assertArrayHasKey(WorkerStartedEvent::class, WorkerKeyCheck::getSubscribedEvents());
    }

    /** @return array{exit: int, out: string} */
    private static function consume(string $keyId): array
    {
        $dsn = static fn (string $role): string => 'postgresql://'.$role.':x@127.0.0.1:1/erp?serverVersion=17&charset=utf8';
        $env = [
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'APP_RUNTIME_OPTIONS' => '{"disable_dotenv":true}',
            'APP_CACHE_DIR' => self::cacheDir(),
            'APP_SECRET' => str_repeat('s', 32),
            'AUTH_TOKEN_SIGNING_KEY' => str_repeat('k', 32),
            'AUTH_EMAIL_HASH_KEY' => str_repeat('e', 32),
            'AUTH_CREDENTIAL_MAC_KEY' => self::KEY,
            'AUTH_CREDENTIAL_MAC_KEY_ID' => $keyId,
            'MESSENGER_TRANSPORT_DSN' => 'in-memory://default',
            'MAILER_DSN' => 'null://null',
            'MAILER_FROM' => 'no-reply@erp.local',
            'APP_PUBLIC_URL' => 'http://127.0.0.1:5173',
            'DEFAULT_URI' => 'http://localhost',
            'APP_DATABASE_URL' => $dsn(DatabaseRoles::APP),
            'AI_DATABASE_URL' => $dsn(DatabaseRoles::AI),
            'AUTH_DATABASE_URL' => $dsn(DatabaseRoles::AUTH),
            'AUTHLOG_DATABASE_URL' => $dsn(DatabaseRoles::AUTH_LOG),
            'RATELIMIT_DATABASE_URL' => $dsn(DatabaseRoles::RATE_LIMIT),
            'WORKER_DATABASE_URL' => $dsn(DatabaseRoles::WORKER),
            'PATH' => (string) getenv('PATH'),
        ];
        $process = proc_open([\PHP_BINARY, 'bin/console', 'messenger:consume', 'async', '--limit=1', '--time-limit=1', '--no-interaction'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, \dirname(__DIR__, 2), $env);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'out' => $out];
    }
}
