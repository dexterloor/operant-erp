<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Operation;
use App\Platform\Http\PathParameterDescriptions;
use App\Platform\Runtime\ServingCredentialGuard;
use App\Tests\Support\PhpSourceScan;
use App\Tests\Support\RegexEndAnchorPlant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **G-103: every `preg_*` pattern in `src/` ends where the string ends.** PCRE's `$` without the `D` modifier also matches
 * just before one final `\n`, so `/^\d+$/` accepts `"1\n"`: a decimal followed by a line break passed its shape check and
 * reached bcmath (a 500), was stored, or was refused with the wrong code. The house form is `\A…\z`; `$` with `D` is
 * accepted as its equivalent.
 *
 * **Domain, derived:** every call site of the eight `preg_*` functions that take a pattern first, found by a token walk of
 * every file under `src/` (a method call, a static call or a declaration of the same name is not a call site). Its first
 * argument is resolved as one string literal or one `X::CONST` (through `self`/`static`/`use`, then reflection). The walk
 * is local to this test (`docs/session13/g103-trailing-newline-design.md` Q-ARCH-1: ARCH to confirm where it lives).
 *
 * **Rule A**, on a resolved pattern. Refused:
 *  - a `$` outside a character class without `D`; `\Z`;
 *  - an `m` or `x` modifier, or an inline option group (`(?m)`, `(?x:…)`) whose letters include either: this scan cannot
 *    judge `$` under them, so it fails closed;
 *  - a trailing optional line-break atom before the end anchor (`\n?\z`, `\s*\z`, `[\r\n]*\z`, `(?:\n)?\z`, `\x0a?\z`, `\n?$`
 *    under `D`, …): it re-opens G-103 through an anchor rule A otherwise accepts. A `+` is not flagged: it requires the
 *    character. Exempt: {@see self::DENY_LIST_END_SITES}, two-way.
 *
 * **Rule B**: an unreadable first argument is refused unless pinned in {@see self::UNREADABLE_PATTERN_SITES} (two-way), and
 * no `use function preg_*` import exists in `src/` (an alias would hide every call from the walk).
 *
 * **Rule C, backstop** (the primary arm is {@see \App\Tests\Api\OpenApiContractTest::noServedParameterSchemaCarriesAPattern()}):
 * no `Symfony\Component\Validator\Constraints\Regex` and no `'pattern' =>` in `src/`. API Platform compiles a parameter
 * schema's `pattern` as `'#'.$pattern.'#'` without `D` (`vendor/api-platform/validator/Util/ParameterValidationConstraints.php`),
 * so an ECMA-correct `^x$` would re-open G-103 where this walk cannot see it.
 *
 * **Floors, derived (no literal count is asserted):**
 *  (i) every provider of a served operation that declares `limit` (`contracts/openapi.json`, mapped through its
 *      `ApiResource` `provider:` argument by reflection) holds — itself, or in a class it calls statically (one hop, read
 *      from its own tokens) — ≥ 1 readable call site whose pattern is end-anchored and matches `'1'`;
 *  (ii) every `const QUANTITY|MONEY|DECIMAL = '<literal>'` in `src/`, found by a token walk independent of the call-site
 *      walk, is the resolved first argument of ≥ 1 call site and is end-anchored;
 *  (iii) the domain holds ≥ 1 literal, ≥ 1 same-class constant and ≥ 1 cross-class constant.
 * At C3 (information, not a pin): 77 call sites; 76 readable (37 literal, 36 same-class constant, 3 cross-class constant),
 * 1 unreadable; 60 end-anchored; 15 providers for 15 `limit` lists, 14 distinct satisfying classes; 16 decimal constants.
 *
 * **Positive control:** {@see RegexEndAnchorPlant} (and {@see \App\Tests\Support\RegexEndAnchorPlantConstants}), scanned alone
 * with its own exemption set, must give exactly {@see self::PLANT_FLAGGED}, and must read every pattern of
 * {@see self::PLANT_NOT_FLAGGED} without flagging it.
 *
 * **Not seen (declared review checks, G-103 "Not closed" (ii)):** a pattern reaching PCRE through `call_user_func`, a
 * variable function name, a vendor API, or a config file; a line break inside a final alternation branch
 * (`(\d+\n?|x)\z`): the trailing-atom check reads the pattern's last atom only.
 */
final class RegexEndAnchorTest extends TestCase
{
    /** The eight PHP functions whose first argument is a pattern. */
    private const FUNCTIONS = ['preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback', 'preg_split', 'preg_grep', 'preg_filter', 'preg_replace_callback_array'];

    /** `Class::method` => reason. Two-way: an entry that is no longer an unreadable site is red. */
    private const UNREADABLE_PATTERN_SITES = [
        PathParameterDescriptions::class.'::described' => 'built from the server\'s own route names for the published spec; no end anchor',
    ];

    /**
     * `Class::CONST` => reason. The only patterns allowed a trailing optional line break before the end anchor. Two-way: an
     * entry no call site reads, or whose pattern no longer ends that way, is red ("stale exemption").
     */
    private const DENY_LIST_END_SITES = [
        ServingCredentialGuard::class.'::PASSWORD_TOKEN' => 'deny-list: keeps the reach `$` had over a name ending in a line break (G-103, Q-SEC-4 (b))',
        ServingCredentialGuard::class.'::API_KEY_SUFFIX' => 'deny-list: keeps the reach `$` had over a name ending in a line break (G-103, Q-SEC-4 (b))',
    ];

    /** What the plant must report, exactly. */
    private const PLANT_FLAGGED = [
        'literal /^\d+$/',
        'self::DOLLAR /^\d+$/',
        'cross RegexEndAnchorPlantConstants::OTHER_DOLLAR /^\d+$/',
        'literal /(a|b$)c/',
        'literal /^x$/m',
        'literal /(?m)^x$/D',
        'literal /^x\Z/',
        'literal /\n?\z/',
        'literal /\A\d+\n*\z/',
        'literal /\A\d+\R?\z/',
        'literal /\A\d+\s*\z/',
        'literal /\A\d+[\r\n]*\z/',
        'literal /\A\d+\n?$/D',
        'literal /\A\d+\n{0,1}\z/',
        'literal /\A\d+(?:\n)?\z/',
        'literal /\A\d+\x0a?\z/',
        'self::NOT_EXEMPT_DEMO /_DEMO\n?\z/',
        'unreadable RegexEndAnchorPlant::unreadable',
        'import preg_match as matches',
    ];

    /** What the plant must read and not flag. */
    private const PLANT_NOT_FLAGGED = [
        '/\A\d+\z/', '/^\d+$/D', '/a\$b/', '/[$]/', '/[^$]/', '/[]$]/', '/\A\s+|\s+\z/u', '/\A\d+\s+\z/', '/\A\d+(\.\d+)?\z/',
        '/_DEMO\n?\z/',
    ];

    #[Test]
    public function everyPregPatternInSrcEndsWhereTheStringEnds(): void
    {
        $sites = self::callSites(PhpSourceScan::phpFilesUnder(self::src()));
        $offenders = [];
        foreach ($sites as $site) {
            foreach (self::violations($site, self::DENY_LIST_END_SITES) as $why) {
                $offenders[] = \sprintf('%s:%d %s — %s', $site['file'], $site['line'], $site['pattern'] ?? $site['text'], $why);
            }
        }
        self::assertSame([], $offenders, "A pattern in src/ can match before a final line break (G-103):\n".implode("\n", $offenders));

        // UNREADABLE_PATTERN_SITES, two-way.
        $unreadable = [];
        foreach ($sites as $site) {
            if ('unreadable' === $site['kind']) {
                $unreadable[] = $site['method'];
            }
        }
        $unreadable = array_values(array_unique($unreadable));
        sort($unreadable);
        $pinned = array_keys(self::UNREADABLE_PATTERN_SITES);
        sort($pinned);
        self::assertSame($pinned, $unreadable, 'UNREADABLE_PATTERN_SITES is two-way: every unreadable call site is pinned, and every pin is an unreadable call site (stale otherwise).');

        // DENY_LIST_END_SITES, two-way: read by a call site, and still ending in a trailing line-break atom.
        foreach (self::DENY_LIST_END_SITES as $constant => $reason) {
            $readers = array_filter($sites, static fn (array $s): bool => ($s['constant'] ?? null) === $constant);
            self::assertNotSame([], $readers, 'Stale exemption: no call site reads '.$constant.'.');
            foreach ($readers as $reader) {
                self::assertTrue(self::endsInATrailingLineBreakAtom((string) $reader['pattern']), 'Stale exemption: '.$constant.' no longer ends in a trailing line-break atom.');
            }
        }

        // Floor (iii): the resolver reached each kind of first argument.
        $kinds = array_count_values(array_column($sites, 'kind'));
        foreach (['literal', 'self', 'cross'] as $kind) {
            self::assertGreaterThanOrEqual(1, $kinds[$kind] ?? 0, 'Anti-vacuity: no '.$kind.' first argument was resolved, so the walk is blind to that form.');
        }
    }

    /** Rule B's second half (final RED L-6): an imported alias would make every call a bare name the walk skips. */
    #[Test]
    public function noFileImportsAPregFunctionUnderAnotherName(): void
    {
        $imports = [];
        foreach (PhpSourceScan::phpFilesUnder(self::src()) as $file) {
            foreach (self::pregImports((string) file_get_contents($file)) as $import) {
                $imports[] = self::relative($file).': '.$import;
            }
        }
        self::assertSame([], $imports, 'A `use function preg_*` import hides its calls from this scan.');
    }

    /** Floor (i): every `limit` list's provider parses `limit` with an end-anchored pattern, itself or one static hop away. */
    #[Test]
    public function everyLimitProviderHoldsAnEndAnchoredPageSizeCheck(): void
    {
        $spec = json_decode((string) file_get_contents(\dirname(self::src(), 2).'/contracts/openapi.json'), true, flags: \JSON_THROW_ON_ERROR);
        $limitOperations = [];
        foreach ($spec['paths'] as $operations) {
            foreach ($operations as $operation) {
                if (!\is_array($operation) || !isset($operation['operationId'])) {
                    continue;
                }
                foreach ($operation['parameters'] ?? [] as $parameter) {
                    if ('limit' === ($parameter['name'] ?? null) && 'query' === ($parameter['in'] ?? null)) {
                        $limitOperations[] = (string) $operation['operationId'];
                    }
                }
            }
        }
        self::assertNotSame([], $limitOperations, 'Anti-vacuity: no served operation declares limit.');

        $providers = self::providersByOperation();
        $sites = self::callSites(PhpSourceScan::phpFilesUnder(self::src()));
        $byClass = [];
        foreach ($sites as $site) {
            $byClass[$site['class']][] = $site;
        }

        $missing = [];
        foreach ($limitOperations as $operationId) {
            $provider = $providers[$operationId] ?? null;
            if (null === $provider) {
                $missing[] = $operationId.': no ApiResource provider: argument resolves for it (R-7: fails closed).';
                continue;
            }
            $candidates = [$provider, ...self::staticCallees($provider)];
            $satisfied = false;
            foreach ($candidates as $class) {
                foreach ($byClass[$class] ?? [] as $site) {
                    if (null !== $site['pattern'] && self::isEndAnchored($site['pattern']) && 1 === @preg_match($site['pattern'], '1')) {
                        $satisfied = true;
                    }
                }
            }
            if (!$satisfied) {
                $missing[] = \sprintf('%s: neither %s nor a class it calls statically holds an end-anchored check that accepts "1".', $operationId, $provider);
            }
        }
        self::assertSame([], $missing, implode("\n", $missing));
    }

    /** Floor (ii): every decimal-shape constant is read by a call site, end-anchored. */
    #[Test]
    public function everyDecimalShapeConstantIsInTheDomainAndEndAnchored(): void
    {
        $files = PhpSourceScan::phpFilesUnder(self::src());
        $read = [];
        foreach (self::callSites($files) as $site) {
            if (isset($site['constant'])) {
                $read[$site['constant']] = true;
            }
        }

        $found = 0;
        $defects = [];
        foreach ($files as $file) {
            $tokens = self::tokens((string) file_get_contents($file));
            [$namespace, $class] = [null, null];
            foreach ($tokens as $i => $token) {
                if (\is_array($token) && \T_NAMESPACE === $token[0]) {
                    $namespace = \is_array($tokens[$i + 1] ?? null) ? $tokens[$i + 1][1] : null;
                }
                if (\is_array($token) && \in_array($token[0], [\T_CLASS, \T_ENUM, \T_TRAIT, \T_INTERFACE], true) && null === $class && \is_array($tokens[$i + 1] ?? null) && \T_STRING === $tokens[$i + 1][0]) {
                    $class = $namespace.'\\'.$tokens[$i + 1][1];
                }
                if (!\is_array($token) || \T_CONST !== $token[0]) {
                    continue;
                }
                $name = $tokens[$i + 1] ?? null;
                $equals = $tokens[$i + 2] ?? null;
                $value = $tokens[$i + 3] ?? null;
                if (!\is_array($name) || !\in_array($name[1], ['QUANTITY', 'MONEY', 'DECIMAL'], true) || '=' !== $equals || !\is_array($value) || \T_CONSTANT_ENCAPSED_STRING !== $value[0]) {
                    continue;
                }
                ++$found;
                $key = $class.'::'.$name[1];
                $pattern = (string) eval('return '.$value[1].';');
                if (!isset($read[$key])) {
                    $defects[] = $key.' is a decimal shape no preg_* call site reads, so the walk does not judge it.';
                }
                if (!self::isEndAnchored($pattern) || [] !== self::patternViolations($pattern, false)) {
                    $defects[] = $key.' '.$pattern.' is not end-anchored.';
                }
            }
        }
        self::assertGreaterThanOrEqual(1, $found, 'Anti-vacuity: no QUANTITY, MONEY or DECIMAL constant was found.');
        self::assertSame([], $defects, implode("\n", $defects));
    }

    /** The positive control: the plant gives exactly its flagged list, and reads its not-flagged list without flagging it. */
    #[Test]
    public function thePlantedFileIsReportedExactly(): void
    {
        $plant = (new \ReflectionClass(RegexEndAnchorPlant::class))->getFileName();
        \assert(\is_string($plant));
        $sites = self::callSites([$plant]);
        $exempt = [RegexEndAnchorPlant::class.'::DENY_LIST_DEMO' => 'the plant\'s own exemption'];

        $reported = [];
        $readClean = [];
        foreach ($sites as $site) {
            $label = match ($site['kind']) {
                'unreadable' => 'unreadable '.self::short($site['method']),
                'literal' => 'literal '.$site['pattern'],
                'self' => 'self::'.substr((string) $site['constant'], strrpos((string) $site['constant'], '::') + 2).' '.$site['pattern'],
                default => 'cross '.self::short((string) $site['constant']).' '.$site['pattern'],
            };
            if ([] !== self::violations($site, $exempt)) {
                $reported[] = $label;
            } elseif (null !== $site['pattern']) {
                $readClean[] = $site['pattern'];
            }
        }
        foreach (self::pregImports((string) file_get_contents($plant)) as $import) {
            $reported[] = 'import '.$import;
        }
        sort($reported);
        $expected = self::PLANT_FLAGGED;
        sort($expected);
        self::assertSame($expected, $reported, 'The plant was not reported exactly: the scan is blind to, or over-reports, a planted shape.');
        foreach (self::PLANT_NOT_FLAGGED as $pattern) {
            self::assertContains($pattern, $readClean, 'The plant\'s '.$pattern.' was not read, or was flagged.');
        }
        self::assertNotContains('(%1$s)', $readClean, 'A sprintf format no preg_* call reads was taken into the domain.');

        // Rule C's backstop reports both planted framework routes.
        self::assertSame(['Assert\Regex', '\'pattern\' =>'], self::frameworkPatternRoutes((string) file_get_contents($plant)));
    }

    /** Rule C's backstop over `src/`: no validator `Regex` and no schema `pattern`. */
    #[Test]
    public function noSrcFileHandsAPatternToTheFramework(): void
    {
        $hits = [];
        foreach (PhpSourceScan::phpFilesUnder(self::src()) as $file) {
            foreach (self::frameworkPatternRoutes((string) file_get_contents($file)) as $route) {
                $hits[] = self::relative($file).': '.$route;
            }
        }
        self::assertSame([], $hits, "A pattern handed to the framework is compiled without D (ParameterValidationConstraints.php: '#'.\$pattern.'#'), which re-opens G-103 where this walk cannot see it:\n".implode("\n", $hits));
    }

    // ---------------------------------------------------------------------------------------------------------------------

    /**
     * Every `preg_*` call site in the files.
     *
     * @param list<string> $files
     *
     * @return list<array{file: string, line: int, class: string, method: string, kind: string, pattern: ?string, constant?: string, text: string}>
     */
    public static function callSites(array $files): array
    {
        $sites = [];
        foreach ($files as $file) {
            $tokens = self::tokens((string) file_get_contents($file));
            $namespace = '';
            $class = '';
            $method = '';
            $uses = [];
            $count = \count($tokens);
            for ($i = 0; $i < $count; ++$i) {
                $token = $tokens[$i];
                if (\is_array($token) && \T_NAMESPACE === $token[0]) {
                    $namespace = \is_array($tokens[$i + 1] ?? null) ? $tokens[$i + 1][1] : '';
                }
                if (\is_array($token) && \T_USE === $token[0] && '' === $class && \is_array($tokens[$i + 1] ?? null) && \in_array($tokens[$i + 1][0], [\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                    $fq = ltrim($tokens[$i + 1][1], '\\');
                    $short = substr($fq, (int) strrpos($fq, '\\') + 1);
                    if (\is_array($tokens[$i + 2] ?? null) && \T_AS === $tokens[$i + 2][0] && \is_array($tokens[$i + 3] ?? null)) {
                        $short = $tokens[$i + 3][1];
                    }
                    $uses[$short] = $fq;
                }
                if (\is_array($token) && \in_array($token[0], [\T_CLASS, \T_ENUM, \T_TRAIT, \T_INTERFACE], true) && '' === $class && \is_array($tokens[$i + 1] ?? null) && \T_STRING === $tokens[$i + 1][0]) {
                    $class = $namespace.'\\'.$tokens[$i + 1][1];
                }
                if (\is_array($token) && \T_FUNCTION === $token[0] && \is_array($tokens[$i + 1] ?? null) && \T_STRING === $tokens[$i + 1][0]) {
                    $method = $tokens[$i + 1][1];
                }
                if (!\is_array($token) || !\in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true) || !\in_array(strtolower(ltrim($token[1], '\\')), self::FUNCTIONS, true)) {
                    continue;
                }
                if ('(' !== ($tokens[$i + 1] ?? null)) {
                    continue;
                }
                $previous = $tokens[$i - 1] ?? null;
                if (\is_array($previous) && \in_array($previous[0], [\T_FUNCTION, \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON], true)) {
                    continue;
                }
                $argument = [];
                $depth = 0;
                for ($j = $i + 2; $j < $count; ++$j) {
                    $t = $tokens[$j];
                    if ('(' === $t || '[' === $t || \is_array($t) && \T_CURLY_OPEN === $t[0]) {
                        ++$depth;
                    }
                    if (')' === $t || ']' === $t || '}' === $t) {
                        if (0 === $depth) {
                            break;
                        }
                        --$depth;
                    }
                    if (',' === $t && 0 === $depth) {
                        break;
                    }
                    $argument[] = $t;
                }
                $text = implode('', array_map(static fn (mixed $t): string => \is_array($t) ? $t[1] : $t, $argument));
                $site = ['file' => self::relative($file), 'line' => $token[2], 'class' => $class, 'method' => $class.'::'.$method, 'kind' => 'unreadable', 'pattern' => null, 'text' => $text];
                if (1 === \count($argument) && \is_array($argument[0]) && \T_CONSTANT_ENCAPSED_STRING === $argument[0][0]) {
                    $site['kind'] = 'literal';
                    $site['pattern'] = (string) eval('return '.$argument[0][1].';');
                } elseif (3 === \count($argument) && \is_array($argument[0]) && \is_array($argument[1]) && \T_DOUBLE_COLON === $argument[1][0] && \is_array($argument[2]) && \T_STRING === $argument[2][0]) {
                    $name = $argument[0][1];
                    $resolved = match (true) {
                        \in_array(strtolower($name), ['self', 'static'], true) => $class,
                        str_starts_with($name, '\\') => ltrim($name, '\\'),
                        str_contains($name, '\\') => isset($uses[strstr($name, '\\', true)]) ? $uses[strstr($name, '\\', true)].strstr($name, '\\') : $namespace.'\\'.$name,
                        default => $uses[$name] ?? $namespace.'\\'.$name,
                    };
                    try {
                        $value = (new \ReflectionClassConstant($resolved, $argument[2][1]))->getValue();
                    } catch (\ReflectionException) {
                        $value = null;
                    }
                    if (\is_string($value)) {
                        $site['kind'] = $resolved === $class ? 'self' : 'cross';
                        $site['pattern'] = $value;
                        $site['constant'] = $resolved.'::'.$argument[2][1];
                    }
                }
                $sites[] = $site;
            }
        }

        return $sites;
    }

    /**
     * Rule A and rule B for one call site.
     *
     * @param array{kind: string, pattern: ?string, constant?: string} $site
     * @param array<string, string>                                     $exempt
     *
     * @return list<string>
     */
    private static function violations(array $site, array $exempt): array
    {
        if (null === $site['pattern']) {
            return isset(self::UNREADABLE_PATTERN_SITES[$site['method'] ?? ''])
                ? []
                : ['an unreadable first argument (rule B): pin it in UNREADABLE_PATTERN_SITES with a reason, or make it a literal or a class constant'];
        }

        return self::patternViolations($site['pattern'], isset($site['constant'], $exempt[$site['constant']]));
    }

    /** @return list<string> */
    private static function patternViolations(string $pattern, bool $trailingAtomExempt): array
    {
        $split = self::split($pattern);
        if (null === $split) {
            return ['not a delimited pattern this scan can read'];
        }
        [$body, $modifiers] = $split;
        $why = [];
        if (str_contains($modifiers, 'm') || str_contains($modifiers, 'x')) {
            $why[] = 'this scan cannot judge $ under m/x';
        }
        $masked = self::mask($body);
        if (1 === preg_match('/\(\?[a-zA-Z^-]*[mx][a-zA-Z^-]*[):]/', $masked)) {
            $why[] = 'this scan cannot judge $ under an inline m/x option';
        }
        if (str_contains($masked, '$') && !str_contains($modifiers, 'D')) {
            $why[] = 'end the pattern with \z; $ (and \Z) also match before a final line break (G-103)';
        }
        if (self::hasEscape($body, 'Z')) {
            $why[] = 'end the pattern with \z; $ (and \Z) also match before a final line break (G-103)';
        }
        if (!$trailingAtomExempt && self::endsInATrailingLineBreakAtom($pattern)) {
            $why[] = 'a trailing optional line break before the end anchor re-opens G-103; end the pattern with \z';
        }

        return array_values(array_unique($why));
    }

    /** End-anchored: `\z`, or `$` under `D`, and refused by nothing in rule A. */
    private static function isEndAnchored(string $pattern): bool
    {
        $split = self::split($pattern);
        if (null === $split) {
            return false;
        }
        [$body, $modifiers] = $split;

        return (self::hasEscape($body, 'z') || str_contains(self::mask($body), '$') && str_contains($modifiers, 'D'))
            && [] === self::patternViolations($pattern, true);
    }

    /** Final RED M-1: the body ends, ignoring closing `)`, in a line-break atom under a quantifier whose minimum is zero, then `\z` or `$`. */
    private static function endsInATrailingLineBreakAtom(string $pattern): bool
    {
        $split = self::split($pattern);
        if (null === $split) {
            return false;
        }
        $esc = '\\\\(?:[nrRsv]|x0?[aAdD]|x\{0*[aAdD]\}|0?1[25]|c[JjMm])|\n|\r';
        $atom = '(?:'.$esc.'|\[[^\]]*(?:'.$esc.')[^\]]*\]|\(\?:(?:(?:'.$esc.')[?*]?)+\))';
        $quant = '(?:[?*]|\{0(?:,\d*)?\}|\{,\d+\})[+?]?';

        return 1 === preg_match('/'.$atom.$quant.'\)*(?:\\\\z|\$)\z/', $split[0]);
    }

    /** @return array{0: string, 1: string}|null the body and the modifiers */
    private static function split(string $pattern): ?array
    {
        $delimiter = $pattern[0] ?? '';
        if ('' === $delimiter || ctype_alnum($delimiter) || '\\' === $delimiter || ctype_space($delimiter)) {
            return null;
        }
        $close = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$delimiter] ?? $delimiter;
        $end = strrpos($pattern, $close);
        if (false === $end || 0 === $end) {
            return null;
        }
        $modifiers = substr($pattern, $end + 1);
        if (1 !== preg_match('/\A[a-zA-Z]*\z/', $modifiers)) {
            return null;
        }

        return [substr($pattern, 1, $end - 1), $modifiers];
    }

    /** The body with every escape pair and every character class's contents replaced by `_`, so `$`, `(?` are structural. */
    private static function mask(string $body): string
    {
        $out = '';
        $inClass = false;
        $length = \strlen($body);
        for ($i = 0; $i < $length; ++$i) {
            $c = $body[$i];
            if ('\\' === $c) {
                $out .= '__';
                ++$i;
                continue;
            }
            if ($inClass) {
                if (']' === $c) {
                    $inClass = false;
                    $out .= ']';
                } else {
                    $out .= '_';
                }
                continue;
            }
            if ('[' === $c) {
                $inClass = true;
                $out .= '[';
                if ('^' === ($body[$i + 1] ?? '')) {
                    $out .= '_';
                    ++$i;
                }
                if (']' === ($body[$i + 1] ?? '')) {
                    $out .= '_';
                    ++$i;
                }
                continue;
            }
            $out .= $c;
        }

        return $out;
    }

    /** Whether the body holds the escape `\<letter>` (an escaped backslash does not count). */
    private static function hasEscape(string $body, string $letter): bool
    {
        $length = \strlen($body);
        for ($i = 0; $i < $length; ++$i) {
            if ('\\' === $body[$i]) {
                if ($letter === ($body[$i + 1] ?? '')) {
                    return true;
                }
                ++$i;
            }
        }

        return false;
    }

    /** @return list<string> every `use function preg_* [as x]` import in the source */
    private static function pregImports(string $source): array
    {
        $found = [];
        $tokens = self::tokens($source);
        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || \T_USE !== $token[0] || !\is_array($tokens[$i + 1] ?? null) || \T_FUNCTION !== $tokens[$i + 1][0]) {
                continue;
            }
            for ($j = $i + 2; isset($tokens[$j]) && ';' !== $tokens[$j]; ++$j) {
                $t = $tokens[$j];
                if (!\is_array($t) || !\in_array($t[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $name = ltrim($t[1], '\\');
                $base = false === strrpos($name, '\\') ? $name : substr($name, strrpos($name, '\\') + 1);
                if (!str_starts_with(strtolower($base), 'preg_')) {
                    continue;
                }
                $alias = \is_array($tokens[$j + 1] ?? null) && \T_AS === $tokens[$j + 1][0] && \is_array($tokens[$j + 2] ?? null) ? ' as '.$tokens[$j + 2][1] : '';
                $found[] = $name.$alias;
            }
        }

        return $found;
    }

    /** @return list<string> the framework routes for a pattern the source holds: a validator `Regex` (any import form) and `'pattern' =>` */
    private static function frameworkPatternRoutes(string $source): array
    {
        $routes = [];
        $regex = str_contains($source, 'Symfony\\Component\\Validator\\Constraints\\Regex');
        if (preg_match_all('/^use\s+Symfony\\\\Component\\\\Validator\\\\Constraints(?:\s+as\s+(\w+))?\s*;/m', $source, $m)) {
            foreach ($m[1] as $alias) {
                $alias = '' === $alias ? 'Constraints' : $alias;
                $regex = $regex || 1 === preg_match('/(?<![\w\\\\])'.preg_quote($alias, '/').'\\\\Regex\b/', $source);
            }
        }
        if ($regex) {
            $routes[] = 'Assert\Regex';
        }
        if (1 === preg_match('/[\'"]pattern[\'"]\s*=>/', $source)) {
            $routes[] = '\'pattern\' =>';
        }

        return $routes;
    }

    /** @return array<string, string> operationId => provider class, from every `ApiResource` in `src/`, by reflection */
    private static function providersByOperation(): array
    {
        $providers = [];
        foreach (PhpSourceScan::phpFilesUnder(self::src()) as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, 'ApiResource(')) {
                continue;
            }
            if (1 !== preg_match('/^namespace\s+([^;]+);/m', $source, $ns) || 1 !== preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $cl)) {
                continue;
            }
            $reflection = new \ReflectionClass($ns[1].'\\'.$cl[1]);
            foreach ($reflection->getAttributes() as $attribute) {
                $instance = $attribute->newInstance();
                $operations = [];
                if ($instance instanceof ApiResource) {
                    foreach ($instance->getOperations() ?? [] as $operation) {
                        $operations[] = $operation;
                    }
                } elseif ($instance instanceof Operation) {
                    $operations[] = $instance;
                }
                foreach ($operations as $operation) {
                    $provider = $operation->getProvider() ?? ($instance instanceof ApiResource ? $instance->getProvider() : null);
                    if (\is_string($operation->getName()) && \is_string($provider)) {
                        $providers[$operation->getName()] = $provider;
                    }
                }
            }
        }

        return $providers;
    }

    /** @return list<string> the classes a class calls statically, read from its own tokens (one hop) */
    private static function staticCallees(string $class): array
    {
        $file = (new \ReflectionClass($class))->getFileName();
        \assert(\is_string($file));
        $tokens = self::tokens((string) file_get_contents($file));
        $namespace = (new \ReflectionClass($class))->getNamespaceName();
        $uses = [];
        $callees = [];
        foreach ($tokens as $i => $token) {
            if (\is_array($token) && \T_USE === $token[0] && \is_array($tokens[$i + 1] ?? null) && \in_array($tokens[$i + 1][0], [\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                $fq = ltrim($tokens[$i + 1][1], '\\');
                $uses[substr($fq, (int) strrpos($fq, '\\') + 1)] = $fq;
            }
            if (\is_array($token) && \in_array($token[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)
                && \is_array($tokens[$i + 1] ?? null) && \T_DOUBLE_COLON === $tokens[$i + 1][0]
                && \is_array($tokens[$i + 2] ?? null) && \T_STRING === $tokens[$i + 2][0] && '(' === ($tokens[$i + 3] ?? null)
                && !\in_array(strtolower($token[1]), ['self', 'static', 'parent'], true)) {
                $name = $token[1];
                $callees[] = str_starts_with($name, '\\') ? ltrim($name, '\\') : ($uses[$name] ?? $namespace.'\\'.$name);
            }
        }

        return array_values(array_unique($callees));
    }

    /** @return list<array{0: int, 1: string, 2: int}|string> tokens without whitespace and comments */
    private static function tokens(string $source): array
    {
        return array_values(array_filter(token_get_all($source), static fn (mixed $t): bool => !\is_array($t) || !\in_array($t[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)));
    }

    private static function short(string $name): string
    {
        $base = strstr($name, '::', true);
        $member = false === $base ? '' : (string) strstr($name, '::');
        $class = false === $base ? $name : $base;

        return substr($class, (int) strrpos($class, '\\') + ((false === strrpos($class, '\\')) ? 0 : 1)).$member;
    }

    private static function relative(string $file): string
    {
        $root = \dirname(self::src());

        return str_starts_with($file, $root.'/') ? substr($file, \strlen($root) + 1) : $file;
    }

    private static function src(): string
    {
        return \dirname(__DIR__, 2).'/src';
    }
}
