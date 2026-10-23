<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Modules\UserManagement\Infrastructure\CredentialSeal;
use App\Platform\Tenancy\DatabaseRoles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PROD container compiles and warms with no owner DSN, and only the owner connection's own factory reads it
 * (V3-1(i) item (3); V4 batch B2b-3).
 *
 * `ConnectionBindingTest` and every other in-process check measure the TEST container. This compiles `prod` in a child
 * process into a temporary cache directory (`APP_CACHE_DIR`), with an explicit environment that holds the five
 * application DSNs (dummy passwords, port 1) and no `DATABASE_URL`, and then reads the generated PHP:
 *
 * - `cache:warmup` exits 0 — the prod kernel needs no owner credential to build (M-1 step 1, committed);
 * - `'resolve:DATABASE_URL'` occurs in exactly one generated file, the owner connection's factory (M-1 step 1b, committed);
 * - `'doctrine.dbal.owner_connection'` occurs only in that factory and, in the main container file, only as the three
 *   bookkeeping entries a compiled container always carries for a public service: its file map entry, the
 *   `database_connection` alias, and the registry's connection-name map. Any service that injects the owner connection
 *   adds a reference somewhere else, and this test goes red.
 *
 * **Scope, stated (F-5a):** it proves "the prod kernel builds without the owner DSN and no compiled service is wired to
 * the owner connection". It does not prove the credential is absent from a serving process — the front-controller guard
 * and its subprocess test carry that claim — and it cannot see a connection a class builds for itself (B2, B15).
 *
 * E-12: a missing writable temp directory or a failed compile FAILS this test; it never skips.
 *
 * Plants shown red at build (Rule 17 around each): `CurrentUserReader`'s `$app` bound to `@doctrine.dbal.owner_connection`;
 * a service argument reading `%env(resolve:DATABASE_URL)%`.
 */
final class ProdContainerCredentialTest extends TestCase
{
    private const OWNER_FACTORY = 'getDoctrine_Dbal_OwnerConnectionService.php';

    /** The three bookkeeping lines of the main container file, in any order. */
    private const MAIN_CONTAINER_OWNER_LINES = [
        "#^\\s*'doctrine\\.dbal\\.owner_connection' => 'getDoctrine_Dbal_OwnerConnectionService',$#",
        "#^\\s*'database_connection' => 'doctrine\\.dbal\\.owner_connection',$#",
        "#^\\s*'owner' => 'doctrine\\.dbal\\.owner_connection',$#",
    ];

    private static ?string $cacheDir = null;

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$cacheDir && is_dir(self::$cacheDir)) {
            exec('rm -rf '.escapeshellarg(self::$cacheDir));
        }
    }

    #[Test]
    public function theProdContainerWarmsWithoutTheOwnerDsnAndOnlyTheOwnerFactoryReadsIt(): void
    {
        $files = self::compiledProdContainer();

        $readers = array_keys(array_filter($files, static fn (string $code): bool => str_contains($code, "'resolve:DATABASE_URL'")));
        self::assertSame([self::OWNER_FACTORY], array_map('basename', $readers), 'resolve:DATABASE_URL must be read by the owner connection factory and nothing else');
    }

    #[Test]
    public function noCompiledServiceIsWiredToTheOwnerConnection(): void
    {
        $files = self::compiledProdContainer();

        $referencing = [];
        foreach ($files as $path => $code) {
            if (str_contains($code, "'doctrine.dbal.owner_connection'")) {
                $referencing[] = basename($path);
            }
        }
        sort($referencing);
        self::assertSame(['App_KernelProdContainer.php', self::OWNER_FACTORY], $referencing, 'a generated file other than the owner factory and the main container references the owner connection');

        $main = (string) current(array_filter($files, static fn (string $code, string $path): bool => 'App_KernelProdContainer.php' === basename($path) && str_contains($path, '/Container'), \ARRAY_FILTER_USE_BOTH));
        $lines = array_values(array_filter(explode("\n", $main), static fn (string $line): bool => str_contains($line, 'owner_connection\'') || str_contains($line, "'database_connection'")));
        self::assertCount(\count(self::MAIN_CONTAINER_OWNER_LINES), $lines, 'the main container references the owner connection more often than its three bookkeeping entries: '.implode(' | ', array_map('trim', $lines)));
        foreach (self::MAIN_CONTAINER_OWNER_LINES as $pattern) {
            self::assertCount(1, preg_grep($pattern, $lines) ?: [], 'missing or repeated bookkeeping line '.$pattern.' among: '.implode(' | ', array_map('trim', $lines)));
        }
    }

    /** @return array<string, string> absolute path => generated PHP, for every file of the compiled prod container */
    private static function compiledProdContainer(): array
    {
        static $files = null;
        if (null !== $files) {
            return $files;
        }

        self::$cacheDir = sys_get_temp_dir().'/erp-prod-container-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir(self::$cacheDir, 0700, true), 'the temporary cache directory could not be created');

        $dsn = static fn (string $role): string => 'postgresql://'.$role.':x@127.0.0.1:1/erp?serverVersion=17&charset=utf8';
        $env = [
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'APP_RUNTIME_OPTIONS' => '{"disable_dotenv":true}',
            'APP_CACHE_DIR' => self::$cacheDir,
            'APP_SECRET' => str_repeat('s', 32),
            'AUTH_TOKEN_SIGNING_KEY' => str_repeat('k', 32),
            'AUTH_EMAIL_HASH_KEY' => str_repeat('e', 32),
            'AUTH_CREDENTIAL_MAC_KEY' => base64_encode(str_repeat('c', 32)),
            // Mail-C1: supplied although the warm-up builds no seal, so a later boot path that does cannot fail here
            // mysteriously (production now requires the matching id: CredentialKeyBootCheck).
            'AUTH_CREDENTIAL_MAC_KEY_ID' => (new CredentialSeal(base64_encode(str_repeat('c', 32))))->keyId(),
            'MESSENGER_TRANSPORT_DSN' => 'redis://:x@127.0.0.1:1',
            'DEFAULT_URI' => 'http://localhost',
            'APP_DATABASE_URL' => $dsn(DatabaseRoles::APP),
            'AI_DATABASE_URL' => $dsn(DatabaseRoles::AI),
            'AUTH_DATABASE_URL' => $dsn(DatabaseRoles::AUTH),
            'AUTHLOG_DATABASE_URL' => $dsn(DatabaseRoles::AUTH_LOG),
            'RATELIMIT_DATABASE_URL' => $dsn(DatabaseRoles::RATE_LIMIT),
            'PATH' => (string) getenv('PATH'),
        ];
        $process = proc_open([\PHP_BINARY, 'bin/console', 'cache:warmup'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, \dirname(__DIR__, 2), $env);
        self::assertIsResource($process, 'the warm-up child could not be started');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), 'the prod container did not warm without the owner DSN: '.substr(trim($stdout."\n".$stderr), -600));

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$cacheDir.'/prod', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }
        self::assertNotEmpty(array_filter(array_keys($files), static fn (string $path): bool => self::OWNER_FACTORY === basename($path)), 'the compiled prod container has no owner connection factory, so this test would read nothing');

        return $files;
    }
}
