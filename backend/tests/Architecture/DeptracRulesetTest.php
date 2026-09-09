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
    private static function deptrac(array $args): array
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
            .' --config-file='.escapeshellarg(self::backendDir().'/deptrac.yaml')
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
