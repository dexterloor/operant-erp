<?php

declare(strict_types=1);

namespace App\Tests\Runtime;

use App\Platform\Runtime\ServingCredentialGuard;
use App\Platform\Tenancy\DatabaseRoles;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The wiring: `public/index.php` refuses to build a kernel, and `bin/console` refuses every non-migration command, while
 * a non-application database credential is present (V3-1(i); V4 batch B2b-2, draft rev 3 §4.2).
 *
 * ---------------------------------------------------------------------------
 * How the children are run, and the traps that shaped it
 * ---------------------------------------------------------------------------
 * Each case is a PHP child started with `proc_open` (precedent: `PooledConnectionTest`, `PurchaseOrderRowLockTest`) and an
 * **explicit** environment — never the parent's, which holds the owner DSN. Case B is the control that proves it: the same
 * base environment boots a real prod kernel and answers a 404, so every 78 elsewhere is the guard and not a broken boot
 * or an inherited variable. The child keeps the developer's `php.ini` (extensions are needed); where a case passes
 * `-d auto_prepend_file=<probe>`, that overrides any ini `auto_prepend_file`.
 *
 * - Base environment: `APP_ENV=prod`, `APP_DEBUG=0`, `APP_RUNTIME_OPTIONS={"disable_dotenv":true}`, dummy application
 *   secrets, `DEFAULT_URI`, and the five application DSNs at `127.0.0.1:1` under the real role names, so nothing connects.
 * - Owner user names are derived, never typed: `SELECT current_user` on the owner connection, and the bootstrap superuser
 *   from `pg_roles WHERE rolsuper`.
 * - Every refusal asserts the exit code, the MARKER **and** the offending name, so a refusal for the wrong reason is red.
 * - `var/cache/prod` is deleted before and after the class. Planted dotenv files live under `var/guard-probe*` and are removed.
 * - E-12: with no database or no writable `var/`, this test FAILS; it never skips.
 * - No real credential reaches a child: every password here is the literal `x`. Failure messages name variables only.
 *
 * ---------------------------------------------------------------------------
 * Corrections to the draft's case list, made at build (logged in `docs/session10/progress-B2.md`)
 * ---------------------------------------------------------------------------
 * - **F** plants the request keys into `$_SERVER` through the prepend probe. The draft put them in the child's
 *   environment, but under CLI an environment variable is in `getenv()` too, which the guard reads unfiltered on purpose
 *   (E-3: filtered names are still read from `$_ENV` and `getenv()`); under FPM a request header is in `$_SERVER` only.
 * - **H3** passes `-e prod`. The draft's `-e doctrine:migrations:migrate about` never reaches a listener: the runtime makes
 *   `-e`'s value the kernel environment, and `Kernel::getContainerClass()` rejects a `:` in it before any command resolves.
 *   That literal case is kept as `h3LiteralDraftArgvIsRejectedByTheKernelBeforeAnyCommand`; `-e prod about` is the case that
 *   tells the resolved command (`about`) from an unbound first-argument parser (`prod`).
 *
 * ---------------------------------------------------------------------------
 * Mutations (run at build)
 * ---------------------------------------------------------------------------
 * - delete the guard call in `public/index.php` → A, C, D, E, E2, G, G2, E-8 and W red; B and F stay green;
 * - remove the CLI listener in `bin/console` → H1 and H3 red;
 * - replace the listener with rev 2's unbound `getFirstArgument()` check → H3 red.
 */
final class ServingCredentialGuardProcessTest extends KernelTestCase
{
    private const PROBE_DIRS = ['var/guard-probe', 'var/guard-probe-e8', 'var/guard-probe-prepend'];

    private static ?string $owner = null;

    private static ?string $superuser = null;

    public static function setUpBeforeClass(): void
    {
        self::removeProdCacheAndProbes();
    }

    public static function tearDownAfterClass(): void
    {
        self::removeProdCacheAndProbes();
    }

    #[Test]
    public function aOwnerShapedDsnUnderANonStandardNameIsRefusedForTheOwnerAndTheSuperuser(): void
    {
        foreach ([self::owner(), self::superuser()] as $login) {
            $run = $this->web(['LEGACY_REPORTING_DSN' => 'postgresql://'.$login.':x@127.0.0.1:1/erp']);

            $this->assertRefused($run, 'LEGACY_REPORTING_DSN', 'case A');
            self::assertSame('', $run['stdout'], 'case A: a refused request writes nothing to stdout');
        }
    }

    #[Test]
    public function bTheSameBaseEnvironmentBootsAProdKernelAndAnswers404(): void
    {
        $this->assertServedA404($this->web([]), 'case B (control)');
    }

    #[Test]
    public function cAPasswordNamedVariableIsRefused(): void
    {
        $this->assertRefused($this->web(['DB_OWNER_PASSWORD' => 'x']), 'DB_OWNER_PASSWORD', 'case C');
    }

    #[Test]
    public function dAnOwnerDsnSuppliedByADotenvFileIsRefused(): void
    {
        $dir = self::backend().'/var/guard-probe';
        self::plant($dir.'/.env', 'DATABASE_URL="postgresql://'.self::owner().':x@127.0.0.1:1/erp?serverVersion=17&charset=utf8"'."\n");

        $run = $this->web(['APP_RUNTIME_OPTIONS' => '{"dotenv_path":"var/guard-probe/.env"}']);

        $this->assertRefused($run, 'DATABASE_URL', 'case D');
    }

    #[Test]
    public function eAPlantInEnvAloneAndInGetenvAloneIsRefused(): void
    {
        $dsn = 'postgresql://'.self::owner().':x@127.0.0.1:1/erp';

        $this->assertRefused(
            $this->web([], '$_ENV[\'LEGACY_REPORTING_DSN\'] = '.var_export($dsn, true).';'),
            'LEGACY_REPORTING_DSN',
            'case E ($_ENV only)',
        );
        $this->assertRefused(
            $this->web([], 'putenv('.var_export('LEGACY_REPORTING_DSN='.$dsn, true).');'),
            'LEGACY_REPORTING_DSN',
            'case E (getenv only)',
        );
    }

    #[Test]
    public function e2TheSameKeyHoldingAnApplicationDsnInServerAndAnOwnerDsnInEnvIsRefused(): void
    {
        $this->assertRefused(
            $this->web([], '$_ENV[\'APP_DATABASE_URL\'] = '.var_export('postgresql://'.self::owner().':x@127.0.0.1:1/erp', true).';'),
            'APP_DATABASE_URL',
            'case E2',
        );
    }

    #[Test]
    public function fRequestDerivedServerKeysAreNotTheEnvironment(): void
    {
        $this->assertServedA404(
            $this->web([], '$_SERVER[\'HTTP_X_DB_PASSWORD\'] = \'1\'; $_SERVER[\'QUERY_STRING\'] = \'postgresql://x@h\';'),
            'case F',
        );
    }

    #[Test]
    public function gAQueryUserOverridingAnApplicationLoginIsRefused(): void
    {
        $run = $this->web(['APP_DATABASE_URL' => 'postgresql://'.DatabaseRoles::APP.':x@127.0.0.1:1/erp?user='.self::owner()]);

        $this->assertRefused($run, 'APP_DATABASE_URL', 'case G');
    }

    #[Test]
    public function g2ADoubleEncodedPrimaryReplicaWrapperWithAnOwnerPrimaryIsRefused(): void
    {
        $value = 'postgresql://'.DatabaseRoles::APP.':x@127.0.0.1:1/erp?%2577rapperClass=Doctrine%255CDBAL%255CConnections%255CPrimaryReadReplicaConnection'
            .'&primary%255Buser%255D='.self::owner().'&primary%255Bhost%255D=127.0.0.1&replica%255B0%255D%255Buser%255D='.DatabaseRoles::APP;

        $this->assertRefused($this->web(['APP_DATABASE_URL' => $value]), 'APP_DATABASE_URL', 'case G2');
    }

    #[Test]
    public function h1TheConsoleRefusesANonMigrationCommand(): void
    {
        $run = $this->console(['about']);

        $this->assertRefused($run, 'DATABASE_URL', 'case H1');
        self::assertStringContainsString('about may not run', $run['stderr'], 'case H1: the refusal names the command Application resolved');
    }

    #[Test]
    public function h2TheConsoleRunsTheMigrationCommandAndOnlyFailsToConnect(): void
    {
        $run = $this->console(['doctrine:migrations:migrate', '--no-interaction']);

        self::assertStringNotContainsString(ServingCredentialGuard::MARKER, $run['stdout'].$run['stderr'], 'case H2: an allowed command is not refused');
        self::assertNotSame(ServingCredentialGuard::EXIT_CODE, $run['exit'], 'case H2: exit code');
        self::assertNotSame(0, $run['exit'], 'case H2: the command ran and could not connect');
        self::assertMatchesRegularExpression('/SQLSTATE\[08006\]|[Cc]onnection refused/', $run['stdout'].$run['stderr'], 'case H2: the only failure is the connection');
    }

    #[Test]
    public function h3AnEnvironmentOptionBeforeTheCommandCannotDisguiseTheCommandThatRuns(): void
    {
        $run = $this->console(['-e', 'prod', 'about']);

        $this->assertRefused($run, 'DATABASE_URL', 'case H3');
        self::assertStringContainsString('about may not run', $run['stderr'], 'case H3: the refusal names the command Application resolved, not the option value');
        self::assertStringNotContainsString('Environment', $run['stdout'], 'case H3: about did not run');

        $allowed = $this->console(['-e', 'prod', 'doctrine:migrations:migrate', '--no-interaction']);
        self::assertStringNotContainsString(ServingCredentialGuard::MARKER, $allowed['stdout'].$allowed['stderr'], 'case H3: the allowed command behind the same option is not refused');
    }

    #[Test]
    public function h3LiteralDraftArgvIsRejectedByTheKernelBeforeAnyCommand(): void
    {
        $run = $this->console(['-e', 'doctrine:migrations:migrate', 'about']);

        self::assertNotSame(0, $run['exit'], 'case H3 (draft argv): exit code');
        self::assertStringContainsString('contains invalid characters', $run['stdout'].$run['stderr'], 'case H3 (draft argv): the kernel rejects the environment name');
        self::assertStringNotContainsString('Environment', $run['stdout'], 'case H3 (draft argv): about did not run');
    }

    #[Test]
    public function e8TheTestEnvironmentsOwnerFileIsRefusedIfAWebProcessLoadsIt(): void
    {
        $dir = self::backend().'/var/guard-probe-e8';
        self::plant($dir.'/.env', "PROBE_APP_SETTING=1\n");
        self::plant($dir.'/.env.test', 'DATABASE_URL="postgresql://'.self::owner().':x@127.0.0.1:1/erp?serverVersion=17&charset=utf8"'."\n");

        $run = $this->web(['APP_ENV' => 'test', 'APP_RUNTIME_OPTIONS' => '{"dotenv_path":"var/guard-probe-e8/.env"}']);

        $this->assertRefused($run, 'DATABASE_URL', 'case E-8');
    }

    #[Test]
    public function wTheFrankenPhpWorkerModeIsRefused(): void
    {
        $run = $this->web(['FRANKENPHP_WORKER' => '1']);

        $this->assertRefused($run, 'FRANKENPHP_WORKER', 'case W');
        self::assertStringContainsString(ServingCredentialGuard::WORKER_MODE_UNDESIGNED, $run['stderr'], 'case W: reason');
    }

    /**
     * @param array{exit: int, stdout: string, stderr: string} $run
     */
    private function assertRefused(array $run, string $name, string $case): void
    {
        self::assertSame(ServingCredentialGuard::EXIT_CODE, $run['exit'], $case.': exit code (stderr tail: '.self::tail($run['stderr']).')');
        self::assertStringContainsString(ServingCredentialGuard::MARKER, $run['stderr'], $case.': the guard\'s marker');
        self::assertStringContainsString($name, $run['stderr'], $case.': the offending name');
    }

    /**
     * @param array{exit: int, stdout: string, stderr: string} $run
     */
    private function assertServedA404(array $run, string $case): void
    {
        self::assertSame(0, $run['exit'], $case.': exit code (stderr tail: '.self::tail($run['stderr']).')');
        $body = json_decode($run['stdout'], true);
        self::assertIsArray($body, $case.': the kernel answered with a JSON body');
        self::assertSame(404, $body['status'] ?? null, $case.': problem status');
        self::assertStringNotContainsString(ServingCredentialGuard::MARKER, $run['stderr'], $case.': not refused');
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function web(array $overrides, ?string $prepend = null): array
    {
        $argv = [\PHP_BINARY];
        if (null !== $prepend) {
            $probe = self::backend().'/var/guard-probe-prepend/'.hash('sha256', $prepend).'.php';
            self::plant($probe, "<?php\n".$prepend."\n");
            array_push($argv, '-d', 'auto_prepend_file='.$probe);
        }
        $argv[] = 'public/index.php';

        return self::runChild($argv, $overrides + ['REQUEST_URI' => '/api/v1/guard-probe-not-a-route', 'REQUEST_METHOD' => 'GET', 'HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function console(array $arguments): array
    {
        return self::runChild([\PHP_BINARY, 'bin/console', ...$arguments], [
            'DATABASE_URL' => 'postgresql://'.self::owner().':x@127.0.0.1:1/erp?serverVersion=17&charset=utf8',
        ]);
    }

    /**
     * @param list<string>          $argv
     * @param array<string, string> $overrides
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private static function runChild(array $argv, array $overrides): array
    {
        $dsn = static fn (string $role): string => 'postgresql://'.$role.':x@127.0.0.1:1/erp?serverVersion=17&charset=utf8';
        $env = $overrides + [
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'APP_RUNTIME_OPTIONS' => '{"disable_dotenv":true}',
            'APP_SECRET' => str_repeat('s', 32),
            'AUTH_TOKEN_SIGNING_KEY' => str_repeat('k', 32),
            'AUTH_EMAIL_HASH_KEY' => str_repeat('e', 32),
            'DEFAULT_URI' => 'http://localhost',
            'APP_DATABASE_URL' => $dsn(DatabaseRoles::APP),
            'AI_DATABASE_URL' => $dsn(DatabaseRoles::AI),
            'AUTH_DATABASE_URL' => $dsn(DatabaseRoles::AUTH),
            'AUTHLOG_DATABASE_URL' => $dsn(DatabaseRoles::AUTH_LOG),
            'RATELIMIT_DATABASE_URL' => $dsn(DatabaseRoles::RATE_LIMIT),
            'PATH' => (string) getenv('PATH'),
        ];

        $process = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::backend(), $env);
        self::assertIsResource($process, 'the child process could not be started');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private static function owner(): string
    {
        if (null === self::$owner) {
            self::bootKernel();
            $login = self::getContainer()->get('doctrine.dbal.owner_connection')->fetchOne('SELECT current_user');
            self::assertIsString($login, 'the owner login could not be derived from the owner connection');
            self::assertNotContains($login, DatabaseRoles::APPLICATION_ROLES, 'the owner connection is not the owner');
            self::$owner = $login;
        }

        return self::$owner;
    }

    private static function superuser(): string
    {
        if (null === self::$superuser) {
            self::bootKernel();
            $login = self::getContainer()->get('doctrine.dbal.owner_connection')->fetchOne('SELECT rolname FROM pg_roles WHERE rolsuper ORDER BY rolname LIMIT 1');
            self::assertIsString($login, 'no superuser login could be derived from pg_roles');
            self::$superuser = $login;
        }

        return self::$superuser;
    }

    private static function plant(string $path, string $content): void
    {
        if (!is_dir(\dirname($path))) {
            self::assertTrue(mkdir(\dirname($path), 0777, true), 'var/ is not writable');
        }
        self::assertNotFalse(file_put_contents($path, $content), 'var/ is not writable');
    }

    private static function removeProdCacheAndProbes(): void
    {
        foreach (['var/cache/prod', ...self::PROBE_DIRS] as $dir) {
            $path = self::backend().'/'.$dir;
            if (is_dir($path)) {
                exec('rm -rf '.escapeshellarg($path), $output, $code);
                self::assertSame(0, $code, $dir.' could not be removed');
            }
        }
    }

    private static function tail(string $text): string
    {
        return substr(str_replace("\n", ' / ', trim($text)), -300);
    }

    private static function backend(): string
    {
        return \dirname(__DIR__, 2);
    }
}
