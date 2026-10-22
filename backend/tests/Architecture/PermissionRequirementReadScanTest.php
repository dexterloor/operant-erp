<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **P-0: nobody reads the permission declaration except `PermissionRequirement`** (`g3-g7b-final-red.md` F-M1).
 *
 * Before P-0 twelve sites read the raw extra property and filtered it with `\is_string()` -- the fail-open class a list
 * (G3-C1) or a derived sentinel (G5-D0) would have slipped through. They now ask `PermissionRequirement`. This scan keeps
 * a thirteenth from appearing, and it is an ALLOWLIST, not a list of forbidden forms (a forbidden-form list is evaded by
 * an alias, a comparison, or the literal key -- F-M1 measured four such reads):
 *
 *  - **Rule A, the constant.** Outside `PermissionGate.php` and `PermissionRequirement.php`, the tokens
 *    `OPERATION_PERMISSION` and `DERIVED_PERMISSION` may appear ONLY as `PermissionGate::X` written as an array key --
 *    preceded by `[` or `,`, immediately followed by `=>` -- whose value is a declaration form (`null`, a string literal,
 *    a list of string literals, `PermissionGate::DERIVED`, or for the resolver a string literal / `X::class`), only as an
 *    element of the `extraProperties: [` named argument itself, and only in `src/**\/ApiResource/**` outside `State/`. Every other occurrence, in `src/` and `tests/` alike, is red. The two P-0 test files that
 *    build synthetic operations ({@see self::DECLARATION_FIXTURES}) may use the declaration form outside `ApiResource`;
 *    the form rule still binds them (the `extraProperties:` rule does not: one builds its array in a variable).
 *  - **Rule B, the literal key.** A bare `'required_permission'` / `'derived_permission'` string literal is red unless it
 *    subscripts a DECODED RESPONSE BODY -- `->json()[…]`, `json_decode(…)[…]`, or a `$…body[…]` variable -- or is the key
 *    argument of `assertArray(Not)HasKey(…, $…body)`, or is `ApiProblem`'s `#[SerializedName(…)]`. A longer literal (a JSON
 *    body, a message) is not the key and is not matched.
 *  - **Comments are stripped**: the scan reads PHP tokens, so a docblock naming the constant is not code.
 *
 * Controls, in this file: seven planted reads that must be red (alias, `===` comparison, `getExtraProperties()` by literal
 * key, a subscript in a test, a non-declaration value in an `ApiResource`, and -- RED M-1 -- a declaration-shaped read
 * in an `ApiResource` and in a processor) and two plants that must stay green (a
 * multi-line declaration, a docblock mention); plus anti-vacuity on the real tree (the declarations were found).
 *
 * **Not covered (stated):** a key spelled by concatenation (`'required_'.'permission'`), `constant('…')`, reflection
 * on `PermissionGate`, or an extras array held in a variable named `$…body` (Rule B trusts the name). Each is a deliberate evasion rather than a house idiom; the gate, the parser and the sweeps still
 * fail closed on whatever they declare.
 */
final class PermissionRequirementReadScanTest extends TestCase
{
    private const TOKENS = ['OPERATION_PERMISSION', 'DERIVED_PERMISSION'];

    /** Spelled by concatenation so this file is not itself a bare-literal read (the one evasion it documents). */
    private const KEYS = ['required'.'_permission', 'derived'.'_permission'];

    /** Rule A and B do not apply: the constants' home and the one parser. */
    private const OWNERS = [
        'src/Platform/Security/PermissionGate.php',
        'src/Platform/Security/PermissionRequirement.php',
    ];

    /** P-0's own tests build synthetic operations: the declaration FORM may appear outside `ApiResource`, nothing else. */
    private const DECLARATION_FIXTURES = [
        'tests/Platform/Security/PermissionRequirementTest.php',
        'tests/Api/PermissionGateRequirementTest.php',
        // G5-D0 (2026-10-22): the derived arm is proven on planted operations until G5-C2 ships a derived one.
        'tests/Api/DerivedPermissionTest.php',
        'tests/Api/PermissionSweepCasesTest.php',
        // G6-C0 (2026-10-22): the export's admissible-set derivation is proven on a planted registry (design L-8's shape).
        'tests/Api/ExportAdmissibleSetTest.php',
    ];

    #[Test]
    public function nothingOutsideTheParserReadsThePermissionDeclaration(): void
    {
        $root = \dirname(__DIR__, 2);
        $violations = [];
        $declarations = 0;
        $bodyKeys = 0;

        foreach (['src', 'tests'] as $top) {
            foreach (PhpSourceScan::phpFilesUnder($root.'/'.$top) as $absolute) {
                $relative = substr($absolute, \strlen($root) + 1);
                if (\in_array($relative, self::OWNERS, true)) {
                    continue;
                }
                [$found, $declared, $keys] = self::scan($relative, (string) file_get_contents($absolute));
                array_push($violations, ...$found);
                $declarations += $declared;
                $bodyKeys += $keys;
            }
        }

        // Anti-vacuity: F-M1 measured 115 declarations under src/; the scan must be seeing them, or it sees nothing.
        self::assertGreaterThan(100, $declarations, 'The scan found almost no `=>` declarations, so it is not reading the tree.');
        // RED L-2: the declarations are all in src/; the response-body key reads are all in tests/ -- so this proves tests/
        // was read too.
        self::assertGreaterThan(0, $bodyKeys, 'The scan found no response-body read of the key, so tests/ was not scanned.');
        self::assertSame([], $violations, "Reads of the permission declaration outside PermissionRequirement (P-0, F-M1):\n  ".implode("\n  ", $violations));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function plantsThatMustBeRed(): iterable
    {
        // The alias and the comparison are planted where declarations ARE allowed, so the form rule (not the path rule)
        // is what must refuse them.
        yield 'an alias of the constant' => ['src/Modules/Inventory/ApiResource/Planted.php',
            '<?php $k = PermissionGate::OPERATION_PERMISSION; $x = $extra[$k];'];
        yield 'a comparison read' => ['src/Modules/Inventory/ApiResource/Planted.php',
            '<?php foreach ($extra as $key => $v) { if (PermissionGate::OPERATION_PERMISSION === $key) { echo $v; } }'];
        yield 'the literal key on the extras' => ['tests/Api/PlantedTest.php',
            "<?php \$code = \$operation->getExtraProperties()['required_permission'];"];
        yield 'a subscript read in a test' => ['tests/Api/PlantedTest.php',
            '<?php $code = $operation->getExtraProperties()[PermissionGate::OPERATION_PERMISSION] ?? null;'];
        yield 'a non-declaration value in an ApiResource' => ['src/Modules/Inventory/ApiResource/Planted.php',
            '<?php $keep = array_intersect_key($extra, [PermissionGate::OPERATION_PERMISSION => 1]);'];
        // RED M-1: the same read with a declaration-shaped value, in a resource file and in a processor.
        yield 'a declaration-shaped read in an ApiResource' => ['src/Modules/Inventory/ApiResource/Planted.php',
            '<?php $keep = array_intersect_key($extra, [PermissionGate::OPERATION_PERMISSION => null]);'];
        yield 'a declaration-shaped read in a processor' => ['src/Modules/Inventory/ApiResource/State/PlantedProcessor.php',
            '<?php $o = new Get(extraProperties: [PermissionGate::OPERATION_PERMISSION => null]);'];
    }

    #[Test]
    #[DataProvider('plantsThatMustBeRed')]
    public function aPlantedReadIsRed(string $path, string $source): void
    {
        self::assertNotSame([], self::scan($path, $source)[0], 'The planted read passed the scan.');
    }

    #[Test]
    public function aDeclarationAndADocblockStayGreen(): void
    {
        $multiLine = <<<'PHP'
            <?php
            #[ApiResource(operations: [new Get(
                extraProperties: [
                    PermissionGate::OPERATION_PERMISSION
                        => [
                            'forecast.view',
                            'stock_level.view',
                        ],
                    'idempotency_key_required' => true,
                ],
            )])]
            final class Planted {}
            PHP;
        $docblock = <<<'PHP'
            <?php
            /** Reads `PermissionGate::OPERATION_PERMISSION` and `$extra['required_permission']` -- in prose only. */
            final class Planted {}
            PHP;

        [$found, $declared] = self::scan('src/Modules/Inventory/ApiResource/Planted.php', $multiLine);
        self::assertSame([], $found, 'A multi-line declaration was refused.');
        self::assertSame(1, $declared, 'The multi-line declaration was not counted, so the green above proves nothing.');
        self::assertSame([], self::scan('tests/Api/PlantedTest.php', $docblock)[0], 'A docblock mention was refused.');
    }

    /**
     * @return array{0: list<string>, 1: int, 2: int} violations, allowed `=>` declarations, allowed response-body key reads
     */
    private static function scan(string $relative, string $source): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT]),
        ));
        $violations = [];
        $declarations = 0;
        $bodyKeys = 0;
        // `ApiResource/State/` holds the providers and processors: code, not declarations (RED M-1; no declaration lives
        // there, measured 2026-10-22).
        $inApiResource = str_starts_with($relative, 'src/') && str_contains($relative, '/ApiResource/') && !str_contains($relative, '/ApiResource/State/');
        $fixture = \in_array($relative, self::DECLARATION_FIXTURES, true);

        foreach ($tokens as $i => $token) {
            $line = $relative.':'.$token->line;

            if ($token->is(\T_STRING) && \in_array($token->text, self::TOKENS, true)) {
                if (!($inApiResource || $fixture)) {
                    $violations[] = $line.' names '.$token->text.' outside an ApiResource declaration';
                    continue;
                }
                $reason = self::declarationDefect($tokens, $i, !$fixture);
                if (null !== $reason) {
                    $violations[] = $line.' '.$reason;
                    continue;
                }
                ++$declarations;
                continue;
            }

            if ($token->is(\T_CONSTANT_ENCAPSED_STRING) && \in_array(self::unquote($token->text), self::KEYS, true)) {
                if (self::isAResponseBodyKey($tokens, $i, $relative)) {
                    ++$bodyKeys;
                } else {
                    $violations[] = $line.' uses the literal key '.$token->text.' on something other than a decoded response body';
                }
            }
        }

        return [$violations, $declarations, $bodyKeys];
    }

    /** @param list<\PhpToken> $tokens */
    private static function declarationDefect(array $tokens, int $i, bool $mustBeTheExtraPropertiesArgument): ?string
    {
        $name = $tokens[$i]->text;
        if (!(($tokens[$i - 1] ?? null)?->is(\T_DOUBLE_COLON) && self::isPermissionGate($tokens[$i - 2] ?? null))) {
            return 'reads '.$name.' other than as PermissionGate::'.$name;
        }
        $before = $tokens[$i - 3] ?? null;
        if (null === $before || !\in_array($before->text, ['[', ','], true)) {
            return 'reads PermissionGate::'.$name.' other than as an array key';
        }
        if (!(($tokens[$i + 1] ?? null)?->is(\T_DOUBLE_ARROW))) {
            return 'reads PermissionGate::'.$name.' other than as a key followed by =>';
        }
        // RED M-1: a declaration-shaped READ (`array_intersect_key($extra, [PermissionGate::X => null])`) is refused by
        // where it stands: the array must be the `extraProperties:` named argument itself.
        if ($mustBeTheExtraPropertiesArgument && !self::opensTheExtraPropertiesArgument($tokens, $i - 3)) {
            return 'names PermissionGate::'.$name.' in an array that is not an operation\'s extraProperties: argument';
        }
        $end = self::valueEnd($tokens, $i + 2, 'OPERATION_PERMISSION' === $name);
        if (null === $end || !\in_array($tokens[$end]->text ?? '', [',', ']'], true)) {
            return 'declares PermissionGate::'.$name.' with a value that is not a declaration form';
        }

        return null;
    }

    /**
     * Whether the array element starting after token $k belongs to an array literal written as `extraProperties: [`.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function opensTheExtraPropertiesArgument(array $tokens, int $k): bool
    {
        $depth = 0;
        for ($m = $k; $m >= 0; --$m) {
            $text = $tokens[$m]->text;
            if (\in_array($text, [']', ')'], true)) {
                ++$depth;
            } elseif (\in_array($text, ['[', '('], true)) {
                if (0 === $depth) {
                    return '[' === $text && ':' === ($tokens[$m - 1]->text ?? '') && 'extraProperties' === ($tokens[$m - 2]->text ?? '');
                }
                --$depth;
            }
        }

        return false;
    }

    /**
     * The index just past a declaration-form value starting at $k, or null.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function valueEnd(array $tokens, int $k, bool $isTheRequirement): ?int
    {
        $t = $tokens[$k] ?? null;
        if (null === $t) {
            return null;
        }
        if ($t->is(\T_CONSTANT_ENCAPSED_STRING)) {
            return $k + 1;
        }
        if ($isTheRequirement && $t->is(\T_STRING) && 'null' === strtolower($t->text)) {
            return $k + 1;
        }
        if ($isTheRequirement && self::isPermissionGate($t) && ($tokens[$k + 1] ?? null)?->is(\T_DOUBLE_COLON) && 'DERIVED' === ($tokens[$k + 2]->text ?? '')) {
            return $k + 3;
        }
        if (!$isTheRequirement && ($t->is(\T_STRING) || $t->is(\T_NAME_QUALIFIED) || $t->is(\T_NAME_FULLY_QUALIFIED))
            && ($tokens[$k + 1] ?? null)?->is(\T_DOUBLE_COLON) && ($tokens[$k + 2] ?? null)?->is(\T_CLASS)) {
            return $k + 3;
        }
        if ($isTheRequirement && '[' === $t->text) {
            $m = $k + 1;
            while (($tokens[$m] ?? null)?->is(\T_CONSTANT_ENCAPSED_STRING)) {
                ++$m;
                if (',' === ($tokens[$m]->text ?? '')) {
                    ++$m;
                }
            }

            return ']' === ($tokens[$m]->text ?? '') && $m > $k + 1 ? $m + 1 : null;
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private static function isAResponseBodyKey(array $tokens, int $i, string $relative): bool
    {
        $previous = $tokens[$i - 1] ?? null;

        if ('src/Platform/Http/Problem/ApiProblem.php' === $relative && '(' === $previous?->text && 'SerializedName' === ($tokens[$i - 2]->text ?? '')) {
            return true;
        }

        if ('[' === $previous?->text) {
            $subject = $tokens[$i - 2] ?? null;
            if (null !== $subject && $subject->is(\T_VARIABLE) && 1 === preg_match('/body$/i', $subject->text)) {
                return true;
            }
            if (')' === $subject?->text) {
                $depth = 0;
                for ($k = $i - 2; $k >= 0; --$k) {
                    $depth += match ($tokens[$k]->text) { ')' => 1, '(' => -1, default => 0 };
                    if (0 === $depth) {
                        return \in_array($tokens[$k - 1]->text ?? '', ['json', 'json_decode'], true);
                    }
                }
            }

            return false;
        }

        // assertArray(Not)HasKey('required_permission', $…body, …)
        return '(' === $previous?->text
            && \in_array($tokens[$i - 2]->text ?? '', ['assertArrayHasKey', 'assertArrayNotHasKey'], true)
            && ',' === ($tokens[$i + 1]->text ?? '')
            && ($tokens[$i + 2] ?? null)?->is(\T_VARIABLE)
            && 1 === preg_match('/body$/i', $tokens[$i + 2]->text);
    }

    private static function isPermissionGate(?\PhpToken $token): bool
    {
        return null !== $token
            && ($token->is(\T_STRING) || $token->is(\T_NAME_QUALIFIED) || $token->is(\T_NAME_FULLY_QUALIFIED))
            && 1 === preg_match('/(^|\\\\)PermissionGate$/', $token->text);
    }

    private static function unquote(string $literal): string
    {
        return substr($literal, 1, -1);
    }
}
