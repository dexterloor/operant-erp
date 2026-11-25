<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Support\DeployArtefacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * **The docs-free CI job's rules, as a mechanism the local gate runs** (CI C1, 2026-11-25; design
 * `docs/session13/ci-docs-free-design.md` "Revision 1 — FINAL" A–D, `SEC`'s conditions in
 * `docs/session13/ci-docs-free-sec.md` §3 as corrected by `ci-docs-free-sec-red.md`, owner rulings
 * `ci-docs-free-ruling.md`).
 *
 * The job (`.github/workflows/docs-free-subset.yml`) runs the whole suite on the public mirror, where the private design
 * documents are absent. It is **not the gate of record**. What it may and may not do is held here, because nothing on
 * GitHub's side holds it:
 *   - every job is guarded by exactly `github.repository == 'dexterloor/operant-erp'`;
 *   - `on` is `push` to `main` plus an input-less `workflow_dispatch`, nothing else;
 *   - `permissions` is exactly `{contents: read}` and no job widens it; `defaults.run.shell` is `bash`;
 *   - `runs-on` is a GitHub-hosted Ubuntu label pinned to a version (never `self-hosted`, a list or `*-latest`), and
 *     `timeout-minutes` is set;
 *   - every `uses:` is `owner/repo@<40 hex>`, the set of actions is exactly checkout and setup-php, and no job calls a
 *     reusable workflow, a local action or a `docker://` action;
 *   - checkout keeps no credentials; setup-php uses `ini-file: development` and is passed no `github-token`;
 *   - no `services:` or `container:` key; no `${{ … }}` anywhere in a `run:`; no `secrets.` or `vars.` anywhere; no
 *     `*_UPDATE=` assignment; no `docker` command, no `git push` and no compose file named in a `run:` (the stack starts
 *     only through `make`, so there is no compose override);
 *   - the workflow path is not a deploy artefact for {@see DeployArtefacts::isArtefact()}.
 * The report script is held to reading only the run's four log files and the document readers' source, and is driven on a
 * planted fixture per check.
 *
 * **Why these conditions matter beyond CI hygiene** (`SEC`, 2026-11-21, recorded in `RESIDUAL_GAPS.md` G-1.x's last
 * bullet): the job runs this stack, superuser password login and PgBouncer `plain` auth included, on a runner. That is
 * accepted for this job only, and only while the runner is GitHub-hosted and ephemeral, every credential and key is
 * generated inside the run, nothing but the log and step summary leaves it, every service is on `127.0.0.1`, and the job
 * starts only on `push` or an input-less `workflow_dispatch` with a read-only token. A CI test run is not a "deployment"
 * in the owner's triggers (G-5, G-10, G-12, G-14), and that reading narrows none of them. The loopback half is pinned by
 * {@see ComposeLocalServiceTest}; the rest by this class. Three conditions are repository settings that no test can see
 * (runner registration, debug logging, the allowed-actions list); breaking one fires G-1.x when it is made.
 *
 * **Carry for M1:** the private repository has Actions switched off (owner ruling, 2026-11-21). When M1 re-enables them,
 * the `if:` guard is the only thing that keeps this job off the private repository, and whether a job skipped by `if:`
 * bills minutes must be answered in M1's ruling.
 */
final class CiWorkflowTest extends TestCase
{
    private const GUARD = "github.repository == 'dexterloor/operant-erp'";
    private const ACTIONS = ['actions/checkout', 'shivammathur/setup-php'];
    private const REPORT = '.github/scripts/docs-free-report.php';
    private const FILL = '.github/scripts/fill-env.sh';
    private const REPORT_INPUTS = ['ini.txt', 'junit.xml', 'phpunit.exit', 'teamcity.txt'];

    #[Test]
    public function everyWorkflowKeepsTheDocsFreeRules(): void
    {
        $files = self::workflowFiles();
        self::assertNotSame([], $files, 'Anti-vacuity: no workflow file was found, so no rule was checked.');
        $actions = [];
        foreach ($files as $path) {
            $text = (string) file_get_contents(DeployArtefacts::repositoryRoot().'/'.$path);
            self::assertSame([], self::findings($path, $text, $actions), "$path breaks the docs-free job's rules.");
        }
        sort($actions);
        self::assertSame(self::ACTIONS, array_values(array_unique($actions)), 'The set of actions changed: a third action, or a missing one, must move this pin deliberately.');

        // Planted controls through the same decision: each one edit of the real file must be refused.
        $path = '.github/workflows/docs-free-subset.yml';
        self::assertContains($path, $files);
        $real = (string) file_get_contents(DeployArtefacts::repositoryRoot().'/'.$path);
        $unused = [];
        self::assertSame([], self::findings($path, $real, $unused));
        $plants = [
            'no guard' => ["    if: github.repository == 'dexterloor/operant-erp'\n", ''],
            'tag pin' => ['@3d3c42e5aac5ba805825da76410c181273ba90b1', '@v7.0.1'],
            'untrusted trigger' => ['  workflow_dispatch: {}', "  workflow_dispatch: {}\n  pull_request_target: {}"],
            'expression in run' => ['run: make deptrac', 'run: echo ${{ github.ref }}'],
            'wider permissions' => ['  contents: read', '  contents: write'],
            'self-hosted' => ['runs-on: ubuntu-24.04', 'runs-on: [self-hosted]'],
        ];
        foreach ($plants as $what => [$from, $to]) {
            self::assertStringContainsString($from, $real, "The planted control '$what' no longer matches the file.");
            $planted = [];
            self::assertNotSame([], self::findings($path, str_replace($from, $to, $real), $planted), "The planted control '$what' was not refused.");
        }
        self::assertNotSame([], self::findings('.github/workflows/docs-free-deploy.yml', $real, $unused), 'A workflow named like a deploy artefact was not refused.');
    }

    #[Test]
    public function theReportOpensNothingAndReadsOnlyTheRunsLogs(): void
    {
        $source = (string) file_get_contents(DeployArtefacts::repositoryRoot().'/'.self::REPORT);
        $forbiddenNames = ['P'.'DO', 'Driver'.'Manager', 'Dot'.'env', 'pg_'.'connect', 'mys'.'qli', 'fsock'.'open', 'stream_socket_'.'client', 'curl_'.'init', 'proc_'.'open', 'she'.'ll_exec'];
        $forbiddenTokens = [\T_REQUIRE, \T_REQUIRE_ONCE, \T_INCLUDE, \T_INCLUDE_ONCE];
        $findings = [];
        $literals = [];
        foreach (\PhpToken::tokenize($source) as $token) {
            if (\in_array($token->id, $forbiddenTokens, true)) {
                $findings[] = 'line '.$token->line.': '.$token->text;
            }
            if (\in_array($token->id, [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                $name = ltrim($token->text, '\\');
                if (\in_array($name, $forbiddenNames, true) || str_starts_with($name, 'Ap'.'p\\')) {
                    $findings[] = 'line '.$token->line.': '.$token->text;
                }
            }
            if (\T_CONSTANT_ENCAPSED_STRING === $token->id && 1 === preg_match('/^[\'"]([a-z]+\.(xml|txt|exit))[\'"]$/', $token->text, $m)) {
                $literals[] = $m[1];
            }
        }
        self::assertSame([], $findings, 'The report opens a connection, loads code or names an application class.');
        sort($literals);
        self::assertSame(self::REPORT_INPUTS, array_values(array_unique($literals)), 'The report reads a file other than the run\'s four logs.');
    }

    #[Test]
    public function theSettingsScriptNeverTracesOrEditsInPlace(): void
    {
        $source = (string) file_get_contents(DeployArtefacts::repositoryRoot().'/'.self::FILL);
        self::assertStringStartsWith("#!/usr/bin/env bash\n", $source);
        self::assertDoesNotMatchRegularExpression('/^\s*set\s+-[a-z]*x/m', $source, 'fill-env.sh traces commands, which would print every value.');
        self::assertDoesNotMatchRegularExpression('/\bsed\s+-i/', $source, 'fill-env.sh edits in place with sed, which differs between Linux and macOS.');
        self::assertStringContainsString('::add-mask::', $source, 'fill-env.sh no longer registers its values as log masks.');
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function reportFixtures(): iterable
    {
        yield 'clean' => ['clean', 0, '**K-9** PASS'];
        yield 'K-1 exit code' => ['exit', 1, '**K-1** FAIL'];
        yield 'K-1 failure' => ['failure', 1, '**K-1** FAIL'];
        yield 'K-2 count mismatch' => ['countMismatch', 1, '**K-2** FAIL'];
        yield 'K-2 suite-level skip' => ['suiteSkip', 1, '**K-2** FAIL'];
        yield 'K-3 other skip reason' => ['otherReason', 1, '**K-3** FAIL'];
        yield 'K-4 unknown document' => ['unknownDocument', 1, '**K-4** FAIL'];
        yield 'K-5 no skips' => ['noSkips', 1, '**K-5** FAIL'];
        yield 'K-7 no tests' => ['noTests', 1, '**K-7** FAIL'];
        yield 'K-7 unreadable log' => ['unreadable', 1, '**K-7** FAIL'];
        yield 'K-9 risky test' => ['risky', 1, '**K-9** FAIL'];
        yield 'suite did not run' => ['didNotRun', 1, 'The suite did not run'];
    }

    #[Test]
    #[DataProvider('reportFixtures')]
    public function theReportGoesRedOnEachPlantedFailureAndGreenOnACleanRun(string $case, int $expectedExit, string $expectedLine): void
    {
        $dir = sys_get_temp_dir().'/docs-free-report-'.bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            self::writeFixture($dir, $case);
            $process = proc_open([\PHP_BINARY, DeployArtefacts::repositoryRoot().'/'.self::REPORT, $dir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process, 'The report could not be started.');
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        self::assertSame($expectedExit, $exit, "Case $case: the report exited $exit.\n".$stdout.$stderr);
        self::assertStringContainsString($expectedLine, $stdout, "Case $case.");
        if ('clean' === $case) {
            self::assertStringNotContainsString('FAIL', $stdout, 'The clean fixture reported a FAIL.');
            self::assertStringContainsString('Docs-free subset — not the gate of record. 1 of 3 tests skipped', $stdout);
            self::assertStringContainsString('### Skipped because `docs/API.md` is absent (1)', $stdout);
        }
    }

    /**
     * @param list<string> $actions collects every action name seen
     *
     * @return list<string>
     */
    private static function findings(string $path, string $text, array &$actions): array
    {
        $findings = [];
        if (DeployArtefacts::isArtefact($path)) {
            $findings[] = "$path is a deploy artefact by name";
        }
        if (1 === preg_match('/\b(secrets|vars)\./', $text)) {
            $findings[] = 'names secrets. or vars.';
        }
        $workflow = Yaml::parse($text);
        if (!\is_array($workflow) || !\array_key_exists('on', $workflow)) {
            return [...$findings, 'no `on` key was parsed'];
        }
        $on = $workflow['on'];
        if (!\is_array($on) || ['push', 'workflow_dispatch'] !== array_keys($on)) {
            $findings[] = '`on` is not exactly push and workflow_dispatch: '.json_encode($on);
        } else {
            if (['branches' => ['main']] !== $on['push']) {
                $findings[] = 'push is not limited to main';
            }
            if (!\in_array($on['workflow_dispatch'], [null, []], true)) {
                $findings[] = 'workflow_dispatch declares something (inputs are not allowed)';
            }
        }
        if (['contents' => 'read'] !== ($workflow['permissions'] ?? null)) {
            $findings[] = 'permissions is not exactly {contents: read}';
        }
        if ('bash' !== ($workflow['defaults']['run']['shell'] ?? null)) {
            $findings[] = 'defaults.run.shell is not bash';
        }
        $jobs = $workflow['jobs'] ?? null;
        if (!\is_array($jobs) || [] === $jobs) {
            return [...$findings, 'no jobs'];
        }
        foreach ($jobs as $id => $job) {
            if (!\is_array($job)) {
                $findings[] = "job $id is not a map";
                continue;
            }
            foreach (['permissions', 'services', 'container', 'uses'] as $key) {
                if (\array_key_exists($key, $job)) {
                    $findings[] = "job $id declares $key";
                }
            }
            if (self::GUARD !== ($job['if'] ?? null)) {
                $findings[] = "job $id is not guarded by exactly ".self::GUARD;
            }
            if (!\is_string($job['runs-on'] ?? null) || 1 !== preg_match('/^ubuntu-[0-9]{2}\.[0-9]{2}$/', $job['runs-on'])) {
                $findings[] = "job $id does not run on a pinned GitHub-hosted Ubuntu label";
            }
            if (!\is_int($job['timeout-minutes'] ?? null) || $job['timeout-minutes'] <= 0) {
                $findings[] = "job $id has no timeout-minutes";
            }
            foreach ((array) ($job['steps'] ?? []) as $n => $step) {
                if (!\is_array($step)) {
                    $findings[] = "job $id step $n is not a map";
                    continue;
                }
                if (isset($step['uses'])) {
                    if (!\is_string($step['uses']) || 1 !== preg_match('#^([\w.-]+/[\w.-]+)@[0-9a-f]{40}$#', $step['uses'], $m)) {
                        $findings[] = "job $id step $n uses ".json_encode($step['uses']).', which is not owner/repo pinned to a full commit';
                        continue;
                    }
                    $actions[] = $m[1];
                    $with = (array) ($step['with'] ?? []);
                    if ('actions/checkout' === $m[1] && false !== ($with['persist-credentials'] ?? null)) {
                        $findings[] = 'checkout does not set persist-credentials: false';
                    }
                    if ('shivammathur/setup-php' === $m[1]) {
                        if ('development' !== ($with['ini-file'] ?? null)) {
                            $findings[] = 'setup-php does not use ini-file: development';
                        }
                        if (\array_key_exists('github-token', $with)) {
                            $findings[] = 'setup-php is passed a github-token';
                        }
                    }
                }
                if (isset($step['run'])) {
                    $run = (string) $step['run'];
                    $rules = [
                        '/\$\{\{/' => 'an expression',
                        '/\b[A-Z0-9_]*_UPDATE=/' => 'an *_UPDATE assignment',
                        '/\bdocker\b/' => 'a docker command',
                        '/\bdocker-compose\b/' => 'a docker-compose command',
                        '/\bgit\s+push\b/' => 'git push',
                        '/compose[\w.-]*\.ya?ml/' => 'a compose file',
                    ];
                    foreach ($rules as $pattern => $what) {
                        if (1 === preg_match($pattern, $run)) {
                            $findings[] = "job $id step $n has $what in run:";
                        }
                    }
                }
            }
        }

        return $findings;
    }

    /** @return list<string> repository-relative workflow paths */
    private static function workflowFiles(): array
    {
        $root = DeployArtefacts::repositoryRoot();
        $files = array_merge(glob($root.'/.github/workflows/*.yml') ?: [], glob($root.'/.github/workflows/*.yaml') ?: []);
        sort($files);

        return array_map(static fn (string $f): string => substr($f, \strlen($root) + 1), $files);
    }

    private static function writeFixture(string $dir, string $case): void
    {
        $tests = 3;
        $failures = 0;
        $errors = 0;
        $skipped = 1;
        $exit = 0;
        $skip = "##teamcity[testIgnored name='aSkippedTest' message='docs/API.md is gitignored and absent |[a|] it|'s ||ok|n|r' duration='0' flowId='1']";
        $lines = [
            "##teamcity[testSuiteStarted name='Project Test Suite' flowId='1']",
            "##teamcity[testStarted name='aPassingTest' flowId='1']",
            "##teamcity[testFinished name='aPassingTest' duration='1' flowId='1']",
            $skip,
            "##teamcity[testSuiteFinished name='Project Test Suite' flowId='1']",
        ];
        switch ($case) {
            case 'exit':
                $exit = 1;
                break;
            case 'failure':
                $exit = 1;
                $failures = 1;
                array_splice($lines, 2, 0, ["##teamcity[testFailed name='aFailingTest' message='expected' details='' flowId='1']"]);
                break;
            case 'countMismatch':
                $skipped = 2;
                break;
            case 'suiteSkip':
                $skipped = 2;
                array_splice($lines, 4, 0, ["##teamcity[testIgnored name='App\\Tests\\SomeClassTest' message='docs/API.md is gitignored' duration='0' flowId='1']", "##teamcity[testSuiteFinished name='App\\Tests\\SomeClassTest' message='docs/API.md is gitignored' duration='0' flowId='1']"]);
                $skipped = 2;
                break;
            case 'otherReason':
                $lines[3] = "##teamcity[testIgnored name='aSkippedTest' message='The redis extension is not loaded' duration='0' flowId='1']";
                break;
            case 'unknownDocument':
                $lines[3] = "##teamcity[testIgnored name='aSkippedTest' message='docs/NOT-A-READER.md is gitignored and absent.' duration='0' flowId='1']";
                break;
            case 'noSkips':
                $skipped = 0;
                unset($lines[3]);
                break;
            case 'noTests':
                $tests = 0;
                break;
            case 'risky':
                array_splice($lines, 2, 0, ["##teamcity[testFailed name='aRiskyTest' message='This test did not perform any assertions' details='' flowId='1']"]);
                break;
        }
        $junit = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<testsuites><testsuite name="phpunit.dist.xml" tests="'.$tests.'" assertions="5" errors="'.$errors.'" failures="'.$failures.'" skipped="'.$skipped.'" time="1"/></testsuites>'."\n";
        if ('unreadable' === $case) {
            $junit = 'this is not XML';
        }
        file_put_contents($dir.'/junit.xml', $junit);
        file_put_contents($dir.'/teamcity.txt', implode("\n", $lines)."\n");
        file_put_contents($dir.'/ini.txt', "php 8.5\n");
        if ('didNotRun' !== $case) {
            file_put_contents($dir.'/phpunit.exit', $exit."\n");
        }
    }
}
