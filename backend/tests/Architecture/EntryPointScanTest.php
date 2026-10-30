<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * There is no other way to start the application, and no other file opens a database connection by itself
 * (V3-1(i); V4 batch B2b-2, draft rev 3 §5, RED E-4 and E-11′).
 *
 * The front-controller guard only protects processes that pass through `public/index.php` (and, as hygiene,
 * `bin/console`). A second front controller, a worker script, or a kernel-less file that loads a dotenv file and opens PDO
 * would serve without it. This test makes the arrival of any of those loud.
 *
 * ---------------------------------------------------------------------------
 * Domain (derived, never listed)
 * ---------------------------------------------------------------------------
 * Every regular file under `backend/` whose content starts with `<?php` or a `#!…php` shebang — **extensionless files
 * included**, because `bin/console` has no extension and neither would a `bin/worker` — excluding only `vendor/`, `var/`
 * and `tools/<tool>/vendor/`. There is no `tests/` exclusion. `composer.json`'s `scripts` are scanned as text.
 *
 * Detection is by `PhpToken`, never by grepping text, and ignores comments. String literals ARE read (a `require` of the
 * runtime is a string). This file builds its own needles by concatenation so that it does not match itself.
 *
 * ---------------------------------------------------------------------------
 * The rules
 * ---------------------------------------------------------------------------
 * 1. `public/` holds exactly one PHP-bearing file, `index.php` (an include chain through any other file is refused by existing).
 * 2. `App\Kernel` / `Kernel::class` is referenced only by `public/index.php`, `bin/console` and `src/Kernel.php`.
 * 3. `extends Kernel` / `extends BaseKernel` appears only in `src/Kernel.php`.
 * 4. The runtime (`autoload_runtime`, `SymfonyRuntime`, `GenericRuntime`, `frankenphp_handle_request`) is referenced only by
 *    `public/index.php` and `bin/console`.
 * 5. **Direct connections.** `Symfony\Component\Dotenv\Dotenv`, `new PDO`, `pg_connect`/`pg_pconnect` and `DriverManager`
 *    appear only in DIRECT_CONNECTION_FILES, each with its reason. The one carve-out: a `DriverManager` reference followed by
 *    `::getAvailableDrivers` (the guard's driver-class test) opens nothing and is not a connection route; an unaliased
 *    `use` import of it is not a reference.
 *    The scan cannot tell WHICH credential a file reads. So the four test files in the list — which open application-role
 *    connections — must also carry no `'DATABASE_URL'` string literal, the owner's key.
 * 6. Every allowlist entry must still match its rule (a stale entry fails), and each rule must find its known files, so an
 *    empty domain or a detector that matches nothing is red rather than green.
 *
 * **Draft correction (logged in `docs/session10/progress-B2.md`):** the draft's direct-connection list named exactly
 * `tests/bootstrap.php` and `bin/assert-clean-database.php`. `DriverManager` was added to the token list by RED's re-attack
 * (E-11′) without the tree being re-read, and four existing test files open application-role connections with it or with
 * `new \PDO`. They are listed by path with their reasons; any new file still fails.
 *
 * Not seen (recorded): a file that builds a class name at run time (`new $class`), `bin/phpunit` (it matches no rule; it
 * starts PHPUnit, whose bootstrap is `tests/bootstrap.php`, listed here), and a credential delivered as a file.
 *
 * Plants shown red at build: an extensionless `bin/worker` requiring the runtime; `public/probe.php` including a `tests/`
 * file; `class X extends Kernel` in `src/`; a `composer.json` script building `new App\Kernel`; `DriverManager::getConnection()`
 * in a `src/` class.
 */
final class EntryPointScanTest extends TestCase
{
    private const KERNEL_REFERENCE_FILES = ['public/index.php', 'bin/console'];

    private const KERNEL_SUBCLASS_FILES = ['src/Kernel.php'];

    private const RUNTIME_FILES = ['public/index.php', 'bin/console'];

    /** @var array<string, string> path => reason */
    private const DIRECT_CONNECTION_FILES = [
        'bin/assert-clean-database.php' => '`make reset`\'s drift guard: loads `.env.test` and opens PDO as the owner to prove the destroyed volume is the one the suite reaches.',
        'bin/demo-seed.php' => '`make demo-seed` (W-3): loads `.env.test` and `.env` and opens a DBAL connection as the owner to write a local demo workspace; refuses anything but a loopback, NOBYPASSRLS database whose comment is `erp-local-demo` (or the gate database for the suite\'s own `demo-test-` process test).',
        'tests/bootstrap.php' => 'PHPUnit\'s bootstrap: `Dotenv::bootEnv` on the root `.env` (and `.env.test` under APP_ENV=test).',
        'tests/Tenancy/TenancyTestCase.php' => 'the isolation rig: second pooled and fresh unpooled `erp_app` clients from APP_DATABASE_URL / APP_DIRECT_DATABASE_URL.',
        'tests/Tenancy/PoolConfigurationTest.php' => 'reads PgBouncer\'s SHOW CONFIG over PDO as `erp_app` (APP_DATABASE_URL).',
        'tests/Tenancy/support/hold_transaction.php' => 'a proc_open child of PooledConnectionTest holding an `erp_app` transaction (APP_DATABASE_URL).',
        'tests/Api/support/hold_order_lock.php' => 'a proc_open child of PurchaseOrderRowLockTest holding an `erp_app` row lock (APP_DIRECT_DATABASE_URL).',
    ];

    /** Files in DIRECT_CONNECTION_FILES that may name the owner's key. */
    private const OWNER_KEY_FILES = ['bin/assert-clean-database.php', 'bin/demo-seed.php', 'tests/bootstrap.php'];

    #[Test]
    public function theDomainIsEveryPhpBearingFileIncludingExtensionlessOnes(): void
    {
        $files = array_keys(self::phpFiles());

        foreach (['public/index.php', 'bin/console', 'bin/phpunit', 'src/Kernel.php', 'tests/bootstrap.php', 'config/bundles.php'] as $known) {
            self::assertContains($known, $files, 'the scan domain misses a known PHP-bearing file: '.$known);
        }
        self::assertNotContains('vendor/autoload.php', $files, 'vendor/ is outside the domain');
    }

    #[Test]
    public function publicHoldsExactlyOnePhpFile(): void
    {
        $public = array_values(array_filter(array_keys(self::phpFiles()), static fn (string $path): bool => str_starts_with($path, 'public/')));

        self::assertSame(['public/index.php'], $public, 'public/ may hold exactly one PHP-bearing file');
    }

    #[Test]
    public function onlyTheTwoEntryPointsAndTheKernelReferenceTheKernel(): void
    {
        // `src/Kernel.php` may reference itself; it declares `class Kernel` in `namespace App` and today does not.
        $this->assertRuleMatchesExactly(self::KERNEL_REFERENCE_FILES, static fn (array $tokens): bool => self::referencesTheKernel($tokens), 'App\\'.'Kernel reference', ['src/Kernel.php']);
    }

    #[Test]
    public function onlyTheKernelExtendsTheKernel(): void
    {
        $this->assertRuleMatchesExactly(self::KERNEL_SUBCLASS_FILES, static fn (array $tokens): bool => self::extendsAKernel($tokens), 'Kernel subclass');
    }

    #[Test]
    public function onlyTheTwoEntryPointsLoadTheRuntime(): void
    {
        $this->assertRuleMatchesExactly(self::RUNTIME_FILES, static fn (array $tokens): bool => self::referencesTheRuntime($tokens), 'runtime reference');
    }

    /**
     * **Assertion count is derived from source length, and moves when a listed file is edited** (VAR investigation,
     * 2026-09-16, `docs/session10/progress-VAR.md`). The second half asserts once per non-whitespace, non-comment token
     * of every listed file outside OWNER_KEY_FILES, plus one `assertSame` for the rule match: 2141 at `6d6f7e1`
     * (2140 tokens). Adding code to one of those four files — not a new test anywhere — raises the suite's total: B6's
     * `785c8f6` added 92 tokens to PoolConfigurationTest.php, which is why the suite went +96 for a 4-assertion test.
     * Benign: every token is checked whatever their number, so the verdict cannot depend on the count, and the count is
     * identical across repeated runs and fresh volumes on one HEAD (five runs, per-test junit diff, 0 moving). Separately,
     * `phpFiles()` caches statically, so its `assertNotEmpty` is counted once per process, in whichever test calls it
     * first — fixed in a full run (default order), different under `--filter`.
     */
    #[Test]
    public function onlyTheListedFilesOpenAConnectionOrLoadADotenvFileDirectly(): void
    {
        $this->assertRuleMatchesExactly(array_keys(self::DIRECT_CONNECTION_FILES), static fn (array $tokens): bool => self::opensADirectConnection($tokens), 'direct connection');

        foreach (array_diff(array_keys(self::DIRECT_CONNECTION_FILES), self::OWNER_KEY_FILES) as $path) {
            foreach (self::tokens(self::phpFiles()[$path]) as $token) {
                self::assertFalse(
                    $token->is([\T_CONSTANT_ENCAPSED_STRING]) && 'DATABASE_URL' === trim($token->text, '\'"'),
                    $path.' opens a direct connection and names the owner\'s key; only '.implode(', ', self::OWNER_KEY_FILES).' may',
                );
            }
        }
    }

    #[Test]
    public function composerScriptsStartNoKernelRuntimeOrDotenv(): void
    {
        $composer = json_decode((string) file_get_contents(self::backend().'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer['scripts'] ?? null, 'composer.json has no scripts section to scan');

        $text = json_encode($composer['scripts'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        foreach (['Kernel', 'autoload'.'_runtime', 'Dot'.'env'] as $needle) {
            self::assertStringNotContainsString($needle, stripslashes($text), 'a composer.json script references '.$needle);
        }
    }

    /**
     * @param list<string>                     $allowed     files that must match (a listed file that no longer matches is stale)
     * @param callable(list<\PhpToken>): bool  $rule
     * @param list<string>                     $mayMatch    files that are permitted to match but not required to
     */
    private function assertRuleMatchesExactly(array $allowed, callable $rule, string $label, array $mayMatch = []): void
    {
        $matched = [];
        foreach (self::phpFiles() as $path => $absolute) {
            if ($rule(self::tokens($absolute))) {
                $matched[] = $path;
            }
        }
        $matched = array_values(array_diff($matched, $mayMatch));
        sort($matched);
        $expected = $allowed;
        sort($expected);

        self::assertSame($expected, $matched, $label.': the files matching the rule must be exactly the allowed files (an unlisted file is a new entry point or connection; a listed file that no longer matches is stale)');
    }

    /** @param list<\PhpToken> $tokens */
    private static function referencesTheKernel(array $tokens): bool
    {
        foreach ($tokens as $i => $token) {
            if ($token->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED]) && 'App\\'.'Kernel' === ltrim($token->text, '\\')) {
                return true;
            }
            if ($token->is(\T_STRING) && 'Kernel' === $token->text && ($tokens[$i + 1] ?? null)?->is(\T_DOUBLE_COLON) && ($tokens[$i + 2] ?? null)?->is(\T_CLASS)) {
                return true;
            }
            if (self::isStringLiteral($token) && str_contains(str_replace('\\\\', '\\', $token->text), 'App\\'.'Kernel')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<\PhpToken> $tokens */
    private static function extendsAKernel(array $tokens): bool
    {
        foreach ($tokens as $i => $token) {
            if ($token->is(\T_EXTENDS) && \in_array(self::lastSegment($tokens[$i + 1] ?? null), ['Kernel', 'BaseKernel'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<\PhpToken> $tokens */
    private static function referencesTheRuntime(array $tokens): bool
    {
        foreach ($tokens as $token) {
            foreach (['autoload'.'_runtime', 'Symfony'.'Runtime', 'Generic'.'Runtime', 'frankenphp'.'_handle_request'] as $needle) {
                if (str_contains($token->text, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<\PhpToken> $tokens */
    private static function opensADirectConnection(array $tokens): bool
    {
        $driverManager = 'Driver'.'Manager';
        foreach ($tokens as $i => $token) {
            $segment = self::lastSegment($token);
            if ('Dot'.'env' === $segment || \in_array($token->text, ['pg_'.'connect', 'pg_'.'pconnect'], true)) {
                return true;
            }
            if ($token->is(\T_NEW) && 'PD'.'O' === self::lastSegment($tokens[$i + 1] ?? null)) {
                return true;
            }
            if ($driverManager === $segment) {
                $isPlainImport = ($tokens[$i - 1] ?? null)?->is(\T_USE) && ($tokens[$i + 1] ?? null)?->text === ';';
                $asksForDriverNames = ($tokens[$i + 1] ?? null)?->is(\T_DOUBLE_COLON) && 'getAvailableDrivers' === ($tokens[$i + 2] ?? null)?->text;
                if (!$isPlainImport && !$asksForDriverNames) {
                    return true;
                }
            }
            if (self::isStringLiteral($token)) {
                foreach (['Dot'.'env\\Dot'.'env', 'Dot'.'env\\\\Dot'.'env', $driverManager, 'pg_'.'connect'] as $needle) {
                    if (str_contains($token->text, $needle)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function lastSegment(?\PhpToken $token): ?string
    {
        if (null === $token || !$token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
            return null;
        }
        $parts = explode('\\', $token->text);

        return end($parts);
    }

    private static function isStringLiteral(\PhpToken $token): bool
    {
        return $token->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE]);
    }

    /** @return list<\PhpToken> non-whitespace, non-comment tokens */
    private static function tokens(string $absolute): array
    {
        return array_values(array_filter(
            \PhpToken::tokenize((string) file_get_contents($absolute)),
            static fn (\PhpToken $token): bool => !$token->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT]),
        ));
    }

    /** @return array<string, string> relative path => absolute path, for every PHP-bearing file in the domain */
    private static function phpFiles(): array
    {
        static $files = null;
        if (null !== $files) {
            return $files;
        }

        $backend = self::backend();
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($backend, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $file) use ($backend): bool {
                $relative = substr($file->getPathname(), \strlen($backend) + 1);

                return !\in_array($relative, ['vendor', 'var'], true) && 1 !== preg_match('#^tools/[^/]+/vendor$#', $relative);
            },
        ));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $head = (string) file_get_contents($file->getPathname(), false, null, 0, 256);
            if (str_starts_with($head, '<?php') || 1 === preg_match('/^#![^\n]*php[^\n]*\n<\?php/', $head)) {
                $files[substr($file->getPathname(), \strlen($backend) + 1)] = $file->getPathname();
            }
        }
        ksort($files);
        self::assertNotEmpty($files, 'the scan found no PHP-bearing file at all');

        return $files;
    }

    private static function backend(): string
    {
        return \dirname(__DIR__, 2);
    }
}
