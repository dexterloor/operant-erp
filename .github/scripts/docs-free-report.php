<?php

declare(strict_types=1);

/*
 * The docs-free subset's report: proves the run was not a fake green.
 *
 *   php .github/scripts/docs-free-report.php <dir>
 *
 * <dir> holds the four files the job writes: junit.xml and teamcity.txt (PHPUnit's two machine logs), phpunit.exit (the
 * suite's exit code) and ini.txt (the PHP preflight's values). The only other thing it reads is the source of the test
 * suite's document readers (backend/tests/Support/*Document.php), to learn which documents a skip may name. It opens no
 * connection and loads no autoloader. It prints Markdown and exits non-zero on any FAIL.
 *
 * Its rules are driven, one planted fixture per check, by backend/tests/Architecture/CiWorkflowTest.php.
 */

const LABEL = 'Docs-free subset — not the gate of record.';
const SKIP_MESSAGE = '#^(docs/[A-Za-z0-9_./-]+\.md) is gitignored#';

$dir = $argv[1] ?? '';
if ('' === $dir || !is_dir($dir)) {
    fwrite(\STDERR, "usage: docs-free-report.php <dir>\n");
    exit(2);
}
$read = static function (string $name) use ($dir): ?string {
    $path = rtrim($dir, '/').'/'.$name;

    return is_file($path) ? (string) file_get_contents($path) : null;
};

$exitText = $read('phpunit.exit');
$junitText = $read('junit.xml');
$teamcityText = $read('teamcity.txt');
$iniText = $read('ini.txt');

if (null === $exitText) {
    echo '## '.LABEL."\n\nThe suite did not run, so there is nothing to report. **FAIL**\n";
    exit(1);
}

/** @return array{tests:int,assertions:int,failures:int,errors:int,skipped:int}|null */
function junitTotals(?string $xml): ?array
{
    if (null === $xml || '' === trim($xml)) {
        return null;
    }
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadXML($xml, \LIBXML_NONET);
    libxml_use_internal_errors($previous);
    if (!$loaded || null === $document->documentElement || 'testsuites' !== $document->documentElement->nodeName) {
        return null;
    }
    $totals = ['tests' => 0, 'assertions' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0];
    $suites = 0;
    foreach ($document->documentElement->childNodes as $child) {
        if ($child instanceof DOMElement && 'testsuite' === $child->nodeName) {
            ++$suites;
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $child->getAttribute($key);
            }
        }
    }

    return $suites > 0 ? $totals : null;
}

/**
 * Parses TeamCity service messages: `##teamcity[name key='value' …]`, with the six escapes `||`, `|'`, `|n`, `|r`, `|[`
 * and `|]`.
 *
 * @return list<array{0:string,1:array<string,string>}>|null
 */
function teamcityMessages(?string $text): ?array
{
    if (null === $text) {
        return null;
    }
    $messages = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        if (!str_starts_with($line, '##teamcity[')) {
            continue;
        }
        $body = substr($line, \strlen('##teamcity['));
        if (1 !== preg_match('/^([A-Za-z]+)/', $body, $m)) {
            return null;
        }
        $name = $m[1];
        $attributes = [];
        $i = \strlen($name);
        $length = \strlen($body);
        while (true) {
            while ($i < $length && ' ' === $body[$i]) {
                ++$i;
            }
            if ($i >= $length) {
                return null;
            }
            if (']' === $body[$i]) {
                break;
            }
            if (1 !== preg_match('/\G([A-Za-z]+)=\'/', $body, $km, 0, $i)) {
                return null;
            }
            $key = $km[1];
            $i += \strlen($km[0]);
            $value = '';
            while (true) {
                if ($i >= $length) {
                    return null;
                }
                $c = $body[$i];
                if ('|' === $c) {
                    $next = $body[$i + 1] ?? '';
                    $value .= match ($next) {
                        '|' => '|', "'" => "'", 'n' => "\n", 'r' => "\r", '[' => '[', ']' => ']',
                        default => '|'.$next,
                    };
                    $i += 2;
                    continue;
                }
                if ("'" === $c) {
                    ++$i;
                    break;
                }
                $value .= $c;
                ++$i;
            }
            $attributes[$key] = $value;
        }
        $messages[] = [$name, $attributes];
    }

    return $messages;
}

/** @return list<string> the document paths the readers declare, relative to the repository root */
function readerPaths(string $supportDir, int &$readers): array
{
    $paths = [];
    $readers = 0;
    foreach (glob($supportDir.'/*Document.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);
        if (1 !== preg_match('/function\s+isAvailable\b/', $source)) {
            continue;
        }
        ++$readers;
        if (1 === preg_match_all("#return \\\\dirname\(__DIR__, 3\)\.'/(docs/[A-Za-z0-9_./-]+\.md)';#", $source, $m)) {
            $paths[] = $m[1][0];
        }
    }
    sort($paths);

    return $paths;
}

$totals = junitTotals($junitText);
$messages = teamcityMessages($teamcityText);
$exitCode = 1 === preg_match('/^\s*(\d+)\s*$/', $exitText, $em) ? (int) $em[1] : -1;
$readerCount = 0;
$documents = readerPaths(dirname(__DIR__, 2).'/backend/tests/Support', $readerCount);

$ignored = [];
$failedCount = 0;
$suiteLevelIgnores = [];
foreach ($messages ?? [] as $index => [$name, $attributes]) {
    if ('testIgnored' === $name) {
        $ignored[] = $attributes;
        $next = $messages[$index + 1] ?? null;
        if (null !== $next && 'testSuiteFinished' === $next[0] && ($next[1]['name'] ?? null) === ($attributes['name'] ?? '')) {
            $suiteLevelIgnores[] = $attributes['name'] ?? '';
        }
    } elseif ('testFailed' === $name) {
        ++$failedCount;
    }
}

$checks = [];
$tests = $totals['tests'] ?? 0;
$skipped = $totals['skipped'] ?? 0;

$k1 = null !== $totals && 0 === $exitCode && 0 === $totals['failures'] && 0 === $totals['errors'];
$k1Why = 'the suite exited '.$exitCode.' with '.($totals['failures'] ?? '?').' failures and '.($totals['errors'] ?? '?').' errors';
if (null !== $totals && 0 !== $exitCode && 0 === $totals['failures'] && 0 === $totals['errors']) {
    $k1Why .= ': a deprecation, notice or warning (failOn*) or a runner error — see the suite step\'s log';
}
$checks['K-1'] = [$k1, $k1Why];

$k2 = null !== $totals && null !== $messages && \count($ignored) === $skipped && [] === $suiteLevelIgnores;
$k2Why = \count($ignored).' skips in the TeamCity log, '.$skipped.' in JUnit';
if ([] !== $suiteLevelIgnores) {
    $k2Why .= '; a whole test class was skipped ('.implode(', ', $suiteLevelIgnores).'), which this report does not accept';
}
$checks['K-2'] = [$k2, $k2Why];

$bad = [];
$byDocument = [];
foreach ($ignored as $attributes) {
    $message = $attributes['message'] ?? '';
    if (1 === preg_match(SKIP_MESSAGE, $message, $sm)) {
        $byDocument[$sm[1]][] = $attributes['name'] ?? '?';
    } else {
        $bad[] = ($attributes['name'] ?? '?').': '.strtok($message, "\n");
    }
}
$checks['K-3'] = [[] === $bad, [] === $bad ? 'every skip names a gitignored document' : 'skips for another reason: '.implode('; ', $bad)];

$unknown = array_values(array_diff(array_keys($byDocument), $documents));
$k4 = [] !== $documents && \count($documents) === $readerCount && [] === $unknown;
$checks['K-4'] = [$k4, \count($documents).' document paths from '.$readerCount.' readers'.([] === $unknown ? '' : '; unknown documents named: '.implode(', ', $unknown))];

$checks['K-5'] = [$skipped > 0, 0 === $skipped ? 'no test skipped, so the private documents may be in this checkout' : $skipped.' skipped'];

$checks['K-7'] = [$tests > 0 && null !== $totals && null !== $messages, null === $totals ? 'the JUnit log is missing or unreadable' : (null === $messages ? 'the TeamCity log is missing or unreadable' : $tests.' tests')];

$expectedFailed = ($totals['failures'] ?? 0) + ($totals['errors'] ?? 0);
$checks['K-9'] = [null !== $messages && $failedCount === $expectedFailed, $failedCount.' reported failed in TeamCity, '.$expectedFailed.' failures and errors in JUnit'.($failedCount === $expectedFailed ? '' : ': a test was reported failed or risky that JUnit did not count (risky test, or a class-level hook error)')];

echo '## '.LABEL.' '.$skipped.' of '.$tests.' tests skipped because the private design documents are not in this repository; the full suite runs locally with them present.'."\n\n";
if (null !== $totals) {
    echo '| tests | assertions | failures | errors | skipped |'."\n".'|---|---|---|---|---|'."\n";
    echo '| '.$totals['tests'].' | '.$totals['assertions'].' | '.$totals['failures'].' | '.$totals['errors'].' | '.$totals['skipped']." |\n\n";
}
ksort($byDocument);
foreach ($byDocument as $document => $names) {
    echo '### Skipped because `'.$document.'` is absent ('.\count($names).")\n\n";
    foreach ($names as $name) {
        echo '- '.$name."\n";
    }
    echo "\n";
}
if (null !== $iniText) {
    echo "### PHP\n\n```\n".trim($iniText)."\n```\n\n";
}
echo "### Checks\n\n";
$failed = false;
foreach ($checks as $id => [$ok, $why]) {
    echo '- **'.$id.'** '.($ok ? 'PASS' : 'FAIL').': '.$why."\n";
    $failed = $failed || !$ok;
}
echo "\nK-6 and K-8 run as their own steps.\n";

exit($failed ? 1 : 0);
