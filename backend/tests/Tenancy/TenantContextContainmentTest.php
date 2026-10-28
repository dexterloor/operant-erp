<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TenantContext guards its own API. It does not guard the connection.
 *
 * RED demonstrated this: a direct set_config inside a work block, with no nested
 * run() anywhere, repoints the context and nothing intercepts it --
 *
 *     [probe] direct set_config inside work, no nested run
 *             context became: 22222222-2222-4222-8222-222222222222
 *
 * -- because the join-path verification only fires on a nested call, and there
 * was none. Postgres cannot help here either: erp_app is entitled to call
 * set_config, and it must be, or TenantContext could not do its job.
 *
 * So the rule is a source rule, and this is its enforcement. It is a grep with
 * a parser behind it, not a security control: it stops the mistake from being
 * merged, it does not stop it from running.
 *
 * ---------------------------------------------------------------------------
 * What it catches, and what it does not
 * ---------------------------------------------------------------------------
 * Scanning uses PHP's own tokenizer and looks only inside string literals and
 * heredoc bodies, so:
 *
 *   caught      single or double quotes, heredocs and nowdocs, any whitespace or
 *               casing, `SET`, `SET LOCAL`, `RESET` and `set_config`, and any new
 *               file that starts writing tenant context
 *   ignored     comments and documentation, which name the GUC constantly and
 *               would otherwise force an allow-list full of prose
 *
 * NOT caught, stated rather than papered over:
 *
 *   - a name assembled at runtime: 'app.' . 'tenant_id', sprintf('app.%s', ...),
 *     a value read from configuration or the environment
 *   - `RESET ALL` and `DISCARD ALL`, which clear the setting without naming it
 *   - anything outside this repository -- a migration run by hand, a psql
 *     session, a query issued by a tool
 *
 * A determined evasion beats it. An accident does not, and accidents are the
 * failure mode this exists for.
 */
final class TenantContextContainmentTest extends TestCase
{
    private const GUC = 'app.tenant_id';

    private const SANCTIONED = 'src/Platform/Tenancy/';

    /**
     * Writers outside src/ must be named here, with the reason. Every one of
     * these is a test that has to establish or destroy context by hand, and two
     * of them exist specifically to demonstrate the forbidden forms.
     */
    private const KNOWN_WRITERS_OUTSIDE_SRC = [
        'bin/demo-seed.php' =>
            'W-3, `make demo-seed`: an owner tool with no container (the sanctioned TenantContext needs an entity manager); '
            .'set_config(..., true) inside its one write transaction, so FORCE RLS\'s WITH CHECK holds for every seeded row.',
        'tests/Api/BulkArchivalCoordinatorTest.php' =>
            'G5-C2: a real 55P03 needs a second connection (unpooled) holding a customer FOR UPDATE under the tenant, '
            .'set_config inside its own transaction, rolled back; it carries the only test that can redden the lock-timeout arm.',
        'tests/Api/PurchaseOrderRowLockTest.php' =>
            'The role-administration lock\'s refusal twins (roles slice 2, C2): set_config to ANOTHER tenant inside a '
            .'bare transaction on the app connection, rolled back, to prove RoleAdministrationLock refuses a mismatched '
            .'setting and takes no advisory lock. If this file stops writing context, that twin has lost its subject.',
        'tests/Api/support/hold_order_lock.php' =>
            'Out-of-process holder of a purchase-order row lock for PurchaseOrderRowLockTest (step 6c); '
            .'connection A of a two-connection race, on the unpooled endpoint, with no container.',
        'tests/Tenancy/TenancyTestCase.php' =>
            'RESET, to clear the leak the bare-SET test deliberately creates; and set_config on the '
            .'owner connection, which needs its own context to read ground truth under FORCE RLS.',
        'tests/Tenancy/TenantContextTest.php' =>
            'The ADR-002 §C-3 control: bare SET and SET LOCAL, run on purpose to prove they fail as '
            .'documented. If this file stops writing context, the suite has stopped being able to '
            .'exhibit the bug it guards against.',
        'tests/Tenancy/TenantIsolationTest.php' =>
            'set_config to the empty string, for the RESET-leaves-it-empty path through the policy.',
        'tests/Tenancy/support/hold_transaction.php' =>
            'Out-of-process transaction holder for the O-1 pool measurement; it has no container.',
    ];

    private const SCANNED = ['bin', 'src', 'tests', 'migrations', 'config'];

    /** A string literal that mentions the GUC and also establishes or destroys it. */
    private const WRITER = '/set_config\s*\(|(?:^|[^\w.])(?:SET\s+(?:LOCAL\s+)?|RESET\s+)app\.tenant_id/i';

    private static function projectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * @return array<string, list<int>> relative path => line numbers
     */
    private function findWriters(): array
    {
        $found = [];

        foreach (self::SCANNED as $dir) {
            $root = self::projectDir().'/'.$dir;
            if (!is_dir($root)) {
                continue;
            }

            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $relative = str_replace(self::projectDir().'/', '', $file->getPathname());
                $contents = (string) file_get_contents($file->getPathname());

                if (!str_contains($contents, self::GUC)) {
                    continue;
                }

                $lines = 'php' === $file->getExtension()
                    ? $this->writerLinesInPhp($contents)
                    : $this->writerLinesInPlainText($contents);

                if ([] !== $lines) {
                    $found[$relative] = $lines;
                }
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Only string literals and heredoc bodies count. Comments name this setting
     * on almost every page of documentation in the slice and are not code.
     *
     * @return list<int>
     */
    private function writerLinesInPhp(string $contents): array
    {
        $lines = [];

        foreach (token_get_all($contents) as $token) {
            if (!\is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            if (!\in_array($id, [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE, \T_INLINE_HTML], true)) {
                continue;
            }

            if (str_contains($text, self::GUC) && preg_match(self::WRITER, $text)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** @return list<int> */
    private function writerLinesInPlainText(string $contents): array
    {
        $lines = [];

        foreach (explode("\n", $contents) as $i => $line) {
            if (str_contains($line, self::GUC) && preg_match(self::WRITER, $line)) {
                $lines[] = $i + 1;
            }
        }

        return $lines;
    }

    /**
     * The hard half: production code outside src/Platform/Tenancy/ may not establish or
     * destroy tenant context. No allow-list, because an allow-list here is the
     * thing that erodes.
     */
    #[Test]
    public function noProductionCodeOutsideTheTenancyNamespaceWritesTenantContext(): void
    {
        $offenders = [];

        foreach ($this->findWriters() as $path => $lines) {
            if (str_starts_with($path, 'src/') && !str_starts_with($path, self::SANCTIONED)) {
                $offenders[] = $path.':'.implode(',', $lines);
            }
        }

        self::assertSame([], $offenders,
            "Tenant context is established outside ".self::SANCTIONED.":\n  ".implode("\n  ", $offenders)
            ."\nUse TenantContext::run(). A direct set_config repoints the connection for the rest of the "
            ."transaction and nothing detects it -- there is no nested call for the join check to fire on.");
    }

    /** The enumerated half: a new writer anywhere else has to be argued for. */
    #[Test]
    public function everyWriterOutsideProductionCodeIsAccountedFor(): void
    {
        $outsideSrc = [];

        foreach ($this->findWriters() as $path => $lines) {
            if (!str_starts_with($path, 'src/')) {
                $outsideSrc[] = $path;
            }
        }

        self::assertSame(
            array_keys(self::KNOWN_WRITERS_OUTSIDE_SRC),
            $outsideSrc,
            "The set of files writing tenant context outside src/ has changed.\n"
            ."If the new one is legitimate, add it to KNOWN_WRITERS_OUTSIDE_SRC with the reason. "
            ."If a file has disappeared from this list, check that the control it carried has not been "
            ."deleted with it -- two of these exist only to demonstrate the failure modes.",
        );
    }

    /** The sanctioned implementation must actually be where the rule says it is. */
    #[Test]
    public function theSanctionedWriterIsTenantContextItself(): void
    {
        $inSrc = [];

        foreach ($this->findWriters() as $path => $lines) {
            if (str_starts_with($path, 'src/')) {
                $inSrc[] = $path;
            }
        }

        self::assertSame(['src/Platform/Tenancy/TenantContext.php'], $inSrc,
            'Exactly one production file may write tenant context. If this fails with an empty list, '
            .'the scanner has stopped finding anything and both tests above are vacuous.');
    }
}
