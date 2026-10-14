<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * `backend/deptrac.yaml` is the artifact; this is what stops it being a
 * decoration.
 *
 * -------------------------------------------------------------------------
 * The three ways a boundary ruleset passes without checking anything
 * -------------------------------------------------------------------------
 * 1. **It forbids nothing.** Every layer allows every other layer, so the run
 *    is green and always will be. `aDeliberateViolationIsRejected` is the
 *    answer: it builds an edge ARCHITECTURE.md §5.4 names as forbidden and
 *    requires deptrac to reject it. The ruleset cannot be green for that edge
 *    and green in general at the same time.
 * 2. **It collects nothing.** A layer whose collector matches no class makes
 *    every rule about it vacuous, and a class in no layer at all is invisible
 *    to every rule in the file. `everyClassInSrcBelongsToALayer` uses deptrac's
 *    own `debug:unassigned` to require total coverage of `src/`.
 * 3. **It skips everything.** `skip_violations` can turn any red run green.
 *    `theRunIsCleanAndTheRegisterIsNotStale` requires zero *unregistered*
 *    violations and zero errors — deptrac reports `Skipped violation "X" for
 *    "Y" was not matched` as an ERROR (measured, not assumed), so an edge that
 *    has been repaired cannot leave its licence behind. It also requires the
 *    register to be non-empty, because an empty one would mean the deviations
 *    this output recorded had silently gone.
 *
 * A-15 has no expression inside deptrac, because it is a rule *about* the
 * depfile: "no layer's `allowed` list may use a wildcard collector; every entry
 * resolves to a layer declared in the same file". That is
 * `noAllowedEntryUsesAWildcardOrNamesAnUndeclaredLayer`, and it is the reason
 * A-15 was promoted out of A-3 in the first place — a wildcard in `Ai.allowed`
 * is the single mechanism that could silently defeat A-4.
 *
 * @see \App\Tests\Tenancy\SchemaOwnershipTest for S-7(a), the other half of Output 5
 */
final class DeptracRulesetTest extends TestCase
{
    private static function backendDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string}
     */
    private static function deptrac(array $args, ?string $configFile = null): array
    {
        $binary = self::backendDir().'/tools/deptrac/vendor/bin/deptrac';

        self::assertFileExists($binary,
            'deptrac is not installed. It lives in its own composer project so that its dependencies '
            .'never reach the application autoloader -- see tools/deptrac/composer.json for the reason, '
            .'which is a measured one. Run `composer install -d tools/deptrac`. This is an assertion and '
            .'not a skip: a boundary check that quietly does not run is worse than one that fails.');

        // SHELL_VERBOSITY=-1 is set for this suite (phpunit.dist.xml) and reaches
        // child processes once a kernel has booted, which puts Symfony Console
        // into quiet mode and makes deptrac print nothing at all. Measured: this
        // test passed alone and returned an empty string in a full run. Reset for
        // the child rather than assumed absent.
        $cmd = 'SHELL_VERBOSITY=0 '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($binary)
            .' '.implode(' ', array_map(escapeshellarg(...), $args))
            .' --config-file='.escapeshellarg($configFile ?? self::backendDir().'/deptrac.yaml')
            .' --no-ansi 2>&1';

        $output = [];
        $status = 0;
        exec('cd '.escapeshellarg(self::backendDir()).' && '.$cmd, $output, $status);

        return [$status, implode("\n", $output)];
    }

    /** @return array{Violations: int, 'Skipped violations': int, Uncovered: int, Allowed: int, Warnings: int, Errors: int} */
    private static function report(): array
    {
        $file = tempnam(sys_get_temp_dir(), 'deptrac').'.json';

        try {
            [, $text] = self::deptrac(['analyse', '--no-progress', '--formatter=json', '--output='.$file]);

            self::assertFileExists($file, "deptrac produced no report:\n".$text);
            /** @var array{Report: array<string, int>} $json */
            $json = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            /* @phpstan-ignore-next-line */
            return $json['Report'];
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function theRunIsCleanAndTheRegisterIsNotStale(): void
    {
        $report = self::report();

        self::assertSame(0, $report['Violations'],
            'deptrac reports a dependency ARCHITECTURE.md §5 forbids and deptrac.yaml does not register. '
            .'Run `make deptrac` for the edge. Adding it to `skip_violations` is not the '
            .'first answer: the register exists for deviations that pre-date the ruleset, not for new ones.');

        self::assertSame(0, $report['Errors'],
            'deptrac reported an error. The one this assertion is aimed at is `Skipped violation "X" for '
            .'"Y" was not matched`: the edge a `skip_violations` entry licensed has been removed, so the '
            .'entry is stale and must go with it -- a register that outlives what it records starts '
            .'licensing edges nobody argued for. Run `make deptrac` for the text.');

        self::assertSame(0, $report['Warnings'], 'deptrac issued a warning about the depfile.');

        // Non-vacuity, both directions. A ruleset that allowed nothing and a
        // ruleset that registered nothing would each be a different kind of lie
        // about what this file is doing today.
        self::assertGreaterThan(0, $report['Allowed'],
            'No dependency was resolved as allowed, so the layers are collecting nothing.');

        self::assertGreaterThan(0, $report['Skipped violations'],
            'The deviation register is empty. It should not be: deptrac.yaml records the pre-existing '
            .'A-1, A-5, A-7, A-10 and A-12 edges this output found and was not permitted to repair. An '
            .'empty register means either they were silently fixed or the register stopped matching.');
    }

    /**
     * Failure mode 1 in the class docblock — a ruleset that forbids nothing.
     *
     * ⚠ This method is named in that docblock since `7fdcc2a` and **did not
     * exist until step 7** (one mention, zero methods, at `7fdcc2a` and at
     * `c57cd12`). It is written now because step 7 publishes the first
     * `Inventory` contract and step 8 will be the first module to cross into
     * it: A-2 / A-13 say Purchasing reaches Inventory ONLY through
     * `Application\Contract`, and nothing executed that.
     *
     * The real `deptrac.yaml` — its layers and ruleset, untouched — is run over
     * fixture classes in a scratch directory, with the skip register emptied
     * (it names real classes only) and a cache file of its own. Each probe is
     * one constructor dependency, so each is exactly one edge.
     */
    #[Test]
    public function aDeliberateViolationIsRejected(): void
    {
        $dir = sys_get_temp_dir().'/deptrac-probe-'.bin2hex(random_bytes(6));
        mkdir($dir.'/src', 0o777, true);

        try {
            file_put_contents($dir.'/src/PurchasingProbes.php', <<<'PHP'
                <?php
                namespace App\Modules\Purchasing\Application;
                final class ProbeThroughTheContract { public function __construct(public \App\Modules\Inventory\Application\Contract\ReceiveStock $stock) {} }
                final class ProbeIntoTheImplementation { public function __construct(public \App\Modules\Inventory\Application\StockReceiver $stock) {} }
                final class ProbeIntoTheTable { public function __construct(public \App\Modules\Inventory\Infrastructure\Entity\StockLevel $level) {} }
                PHP);
            file_put_contents($dir.'/src/InventoryProbes.php', <<<'PHP'
                <?php
                namespace App\Modules\Inventory\Application;
                final class ProbeIntoPurchasing { public function __construct(public \App\Modules\Purchasing\Application\PurchaseOrderRowLock $lock) {} }
                PHP);

            /** @var array{deptrac: array<string, mixed>} $config */
            $config = Yaml::parseFile(self::backendDir().'/deptrac.yaml');
            $config['deptrac']['paths'] = [$dir.'/src'];
            unset($config['deptrac']['skip_violations']);
            file_put_contents($dir.'/deptrac.yaml', Yaml::dump($config, 8, 2));

            [, $text] = self::deptrac([
                'analyse', '--no-progress', '--formatter=json', '--output='.$dir.'/report.json',
                '--cache-file='.$dir.'/deptrac.cache',
            ], $dir.'/deptrac.yaml');

            self::assertFileExists($dir.'/report.json', "deptrac produced no report:\n".$text);
            /** @var array{Report: array<string, int>, files?: array<string, array{messages: list<array{message: string}>}>} $json */
            $json = json_decode((string) file_get_contents($dir.'/report.json'), true, 512, JSON_THROW_ON_ERROR);

            $messages = [];
            foreach ($json['files'] ?? [] as $file) {
                foreach ($file['messages'] as $message) {
                    $messages[] = $message['message'];
                }
            }
            $violators = array_map(static fn (string $m): string => (string) preg_replace('/^\S*\\\\(Probe\w+) .*$/s', '$1', $m), $messages);
            sort($violators);

            self::assertSame(['ProbeIntoPurchasing', 'ProbeIntoTheImplementation', 'ProbeIntoTheTable'], $violators,
                "Exactly three probes break a rule — Purchasing into Inventory's implementation, Purchasing into "
                ."Inventory's table, and §5.4's Inventory → Purchasing — and the contract edge is not one of them:\n"
                .implode("\n", $messages));
            self::assertSame(3, $json['Report']['Violations']);
            self::assertSame(1, $json['Report']['Allowed'],
                'The one allowed edge is Purchasing\Application → Inventory\Application\Contract (A-2).');
        } finally {
            foreach (['src/PurchasingProbes.php', 'src/InventoryProbes.php', 'deptrac.yaml', 'report.json', 'deptrac.cache'] as $f) {
                @unlink($dir.'/'.$f);
            }
            @rmdir($dir.'/src');
            @rmdir($dir);
        }
    }

    /**
     * **C5 C2b — an `Application\Port` is not a Contract** (`docs/session13/c5-design.md` R1.6; `c5-red.md` RED round 2 F-3,
     * mutation M23). `SupplierOpenWork` returns a count of documents with no `.view` gate, so it lives in `SuppliersPort`,
     * which only `SuppliersApplication` and its one named consumer, `PurchasingApplication`, may use — never `Ai` or
     * `Analytics` (A-4 / A-6). Proven the way {@see self::aDeliberateViolationIsRejected()} proves the Contract edge: the real
     * `deptrac.yaml`, untouched but for paths and an empty skip register, run over probe classes, one constructor edge each.
     *
     * Three things must be true and each probe holds one (F-3): (a) the port is collected by `SuppliersPort` ONLY — were
     * `SuppliersApplication`'s collector still to take it, the Purchasing probe would be a violation (Purchasing may not use
     * `SuppliersApplication`); (b) `Ai` and `Analytics` reach it through nothing — both probes are violations; (c) the
     * ruleset itself, read as data: no `Ai*`/`Analytics*` entry lists a `*Port` layer.
     *
     * **Mutation M23, run:** move `SupplierOpenWork` under `Application\Contract` (or add `SuppliersPort` to `AiApplication`'s
     * allowed list) → red here.
     */
    #[Test]
    public function noAiOrAnalyticsClassCanReachAPort(): void
    {
        /** @var array{deptrac: array{ruleset: array<string, list<string>|null>}} $ruleset */
        $ruleset = Yaml::parseFile(self::backendDir().'/deptrac.yaml');
        $leaks = [];
        foreach ($ruleset['deptrac']['ruleset'] as $layer => $allowed) {
            if (1 === preg_match('/^(Ai|Analytics)/', $layer)) {
                foreach ($allowed ?? [] as $target) {
                    if (str_ends_with($target, 'Port')) {
                        $leaks[] = $layer.' -> '.$target;
                    }
                }
            }
        }
        self::assertSame([], $leaks, 'An Ai/Analytics layer lists a Port layer: an inbound port is not the AI\'s door (A-4, A-6).');
        self::assertContains('SuppliersPort', $ruleset['deptrac']['ruleset']['PurchasingApplication'] ?? [],
            'Control: the one named consumer no longer lists the port, so the probes below would prove nothing about it.');
        self::assertContains('CustomersPort', $ruleset['deptrac']['ruleset']['SalesApplication'] ?? [],
            'Control (C5 C4b): Sales no longer lists the customers port, so the probes below would prove nothing about it.');

        $dir = sys_get_temp_dir().'/deptrac-port-probe-'.bin2hex(random_bytes(6));
        mkdir($dir.'/src', 0o777, true);
        $files = ['src/AiProbe.php', 'src/AnalyticsProbe.php', 'src/PurchasingProbe.php', 'src/SuppliersProbe.php',
            'src/AiCustomersProbe.php', 'src/AnalyticsCustomersProbe.php', 'src/SalesProbe.php', 'src/CustomersProbe.php',
            'deptrac.yaml', 'report.json', 'deptrac.cache'];

        try {
            file_put_contents($dir.'/src/AiProbe.php', <<<'PHP'
                <?php
                namespace App\Modules\Ai\Application;
                final class ProbeIntoThePort { public function __construct(public \App\Modules\Suppliers\Application\Port\SupplierOpenWork $port) {} }
                PHP);
            file_put_contents($dir.'/src/AnalyticsProbe.php', <<<'PHP'
                <?php
                namespace App\Modules\Analytics\Application;
                final class ProbeIntoThePortFromAnalytics { public function __construct(public \App\Modules\Suppliers\Application\Port\SupplierOpenWork $port) {} }
                PHP);
            file_put_contents($dir.'/src/PurchasingProbe.php', <<<'PHP'
                <?php
                namespace App\Modules\Purchasing\Application;
                final class AllowedThroughThePort { public function __construct(public \App\Modules\Suppliers\Application\Port\SupplierOpenWork $port) {} }
                PHP);
            file_put_contents($dir.'/src/SuppliersProbe.php', <<<'PHP'
                <?php
                namespace App\Modules\Suppliers\Application;
                final class AllowedFromItsOwnModule { public function __construct(public \App\Modules\Suppliers\Application\Port\SupplierOpenWork $port) {} }
                PHP);
            // C5 C4b: the customers port, the same four probes.
            file_put_contents($dir.'/src/AiCustomersProbe.php', <<<'PHP'
                <?php
                namespace App\Modules\Ai\Application;
                final class ProbeIntoTheCustomersPort { public function __construct(public \App\Modules\Customers\Application\Port\CustomerOpenWork $port) {} }
                PHP);
            file_put_contents($dir.'/src/AnalyticsCustomersProbe.php', <<<'PHP'
                <?php
                namespace App\Modules\Analytics\Application;
                final class ProbeIntoTheCustomersPortFromAnalytics { public function __construct(public \App\Modules\Customers\Application\Port\CustomerOpenWork $port) {} }
                PHP);
            file_put_contents($dir.'/src/SalesProbe.php', <<<'PHP'
                <?php
                namespace App\Modules\Sales\Application;
                final class AllowedThroughTheCustomersPort { public function __construct(public \App\Modules\Customers\Application\Port\CustomerOpenWork $port) {} }
                PHP);
            file_put_contents($dir.'/src/CustomersProbe.php', <<<'PHP'
                <?php
                namespace App\Modules\Customers\Application;
                final class AllowedFromTheCustomersModule { public function __construct(public \App\Modules\Customers\Application\Port\CustomerOpenWork $port) {} }
                PHP);

            /** @var array{deptrac: array<string, mixed>} $config */
            $config = Yaml::parseFile(self::backendDir().'/deptrac.yaml');
            $config['deptrac']['paths'] = [$dir.'/src'];
            unset($config['deptrac']['skip_violations']);
            file_put_contents($dir.'/deptrac.yaml', Yaml::dump($config, 8, 2));

            [, $text] = self::deptrac([
                'analyse', '--no-progress', '--formatter=json', '--output='.$dir.'/report.json',
                '--cache-file='.$dir.'/deptrac.cache',
            ], $dir.'/deptrac.yaml');

            self::assertFileExists($dir.'/report.json', "deptrac produced no report:\n".$text);
            /** @var array{Report: array<string, int>, files?: array<string, array{messages: list<array{message: string}>}>} $json */
            $json = json_decode((string) file_get_contents($dir.'/report.json'), true, 512, JSON_THROW_ON_ERROR);

            $messages = [];
            foreach ($json['files'] ?? [] as $file) {
                foreach ($file['messages'] as $message) {
                    $messages[] = $message['message'];
                }
            }
            $violators = array_map(static fn (string $m): string => (string) preg_replace('/^\S*\\\\(\w+) .*$/s', '$1', $m), $messages);
            sort($violators);

            self::assertSame(['ProbeIntoTheCustomersPort', 'ProbeIntoTheCustomersPortFromAnalytics', 'ProbeIntoThePort', 'ProbeIntoThePortFromAnalytics'], $violators,
                "Exactly the Ai and Analytics probes break a rule; Purchasing / Sales (each port's named consumer) and the "
                ."port's own module's application do not:\n".implode("\n", $messages));
            self::assertSame(4, $json['Report']['Violations']);
            self::assertSame(4, $json['Report']['Allowed'],
                'The four allowed edges are Purchasing\Application and Suppliers\Application into Suppliers\Application\Port, '
                .'and (C5 C4b) Sales\Application and Customers\Application into Customers\Application\Port (F-3 (a): were a '
                .'port also collected by its module\'s Application layer, the consumer\'s edge would be a violation).');
        } finally {
            foreach ($files as $f) {
                @unlink($dir.'/'.$f);
            }
            @rmdir($dir.'/src');
            @rmdir($dir);
        }
    }

    #[Test]
    public function everyClassInSrcBelongsToALayer(): void
    {
        [, $text] = self::deptrac(['debug:unassigned']);

        self::assertStringContainsString('There are no unassigned tokens.', $text,
            "A class under src/ belongs to no layer, so no rule in deptrac.yaml can be broken by it:\n".$text);
    }

    /**
     * A-15, which is a rule about this file and therefore cannot be a rule
     * inside it.
     */
    #[Test]
    public function noAllowedEntryUsesAWildcardOrNamesAnUndeclaredLayer(): void
    {
        /** @var array{deptrac: array{layers: list<array{name: string}>, ruleset: array<string, list<string>|null>}} $config */
        $config = Yaml::parseFile(self::backendDir().'/deptrac.yaml');

        $declared = array_column($config['deptrac']['layers'], 'name');
        self::assertNotSame([], $declared, 'deptrac.yaml declares no layers.');

        $offenders = [];

        foreach ($config['deptrac']['ruleset'] as $layer => $allowed) {
            if (!\in_array($layer, $declared, true)) {
                $offenders[] = $layer.' is a ruleset entry for a layer that is not declared';
            }
            foreach ($allowed ?? [] as $target) {
                if (str_contains($target, '*')) {
                    $offenders[] = $layer.'.allowed contains the wildcard entry "'.$target.'"';
                } elseif (!\in_array($target, $declared, true)) {
                    $offenders[] = $layer.'.allowed names "'.$target.'", which is not a declared layer';
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders)
            ."\nA-15: every allowed edge is an explicitly named target layer. A wildcard in `Ai.allowed` or "
            .'`Analytics.allowed` is the one mechanism by which a `Modules\*\Application\Contract` collector '
            .'could admit `Modules\Ai\Application\Contract` and quietly break A-4.');
    }

    /** Every layer the ruleset governs is declared, and every declared layer is governed or deliberately a target. */
    #[Test]
    public function everyDeclaredLayerAppearsInTheRuleset(): void
    {
        /** @var array{deptrac: array{layers: list<array{name: string}>, ruleset: array<string, list<string>|null>}} $config */
        $config = Yaml::parseFile(self::backendDir().'/deptrac.yaml');

        $declared = array_column($config['deptrac']['layers'], 'name');
        $governed = array_keys($config['deptrac']['ruleset']);
        $targets = [];
        foreach ($config['deptrac']['ruleset'] as $allowed) {
            foreach ($allowed ?? [] as $t) {
                $targets[] = $t;
            }
        }

        $orphans = array_values(array_diff($declared, $governed, $targets));

        self::assertSame([], $orphans,
            'These layers are declared and then never used, as a rule subject or as an allowed target: '
            .implode(', ', $orphans).". A layer nobody names collects classes and constrains nothing, which "
            .'is indistinguishable from not declaring it -- except that it looks like coverage.');
    }
}
