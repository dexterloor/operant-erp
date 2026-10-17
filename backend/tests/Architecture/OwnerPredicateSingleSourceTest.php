<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **Only one answer to "is this the owner" may ever ship** (V2-R-19 + V2-S-5; design §19 C6a, added after RED-3 F-4 and
 * N-8; sales step 6 C6a, 2026-09-28). `OwnerPredicate::holdsMarker()` is that answer; this test makes a second one
 * impossible to add unseen, not merely forbidden in prose. It scans the TOKENS of every file under `src/` — comments and
 * docblocks are not code, so the record of the retired name test in `PurchaseOrderDutySeparation`'s docblock is not a
 * finding — and asserts:
 *
 *  (a) no identifier `OWNER_ROLE_NAME` exists (the deleted constant the name test was built on);
 *  (b) no string literal compares a role name to `'owner'` (`/\bname\s*=\s*'owner'/i`, the SQL shape), and no PHP
 *      comparison `==` / `===` / `!=` / `!==` has `'owner'` or `DefaultRoles::OWNER` on either side, outside
 *      `DefaultRoles` itself;
 *  (c) the classes that read `OwnerMarker::CODE` are exactly {@see self::MARKER_READERS}, each with its reason — so a
 *      second predicate over the marker cannot appear without a line here.
 *
 * **Planted controls, same test, run on in-test scratch sources through the same functions:** a `name = 'owner'` SQL
 * literal; a `=== 'owner'` and a `DefaultRoles::OWNER ===` comparison; a second class reading `OwnerMarker::CODE`.
 * Each must be found, so a green run is a scan that sees, not a scan that is blind.
 */
final class OwnerPredicateSingleSourceTest extends TestCase
{
    /**
     * Derived at build (2026-09-28) by this test's own scan, then fixed here with reasons.
     *
     * @var array<string, string> short class name => why it reads the marker
     */
    private const MARKER_READERS = [
        'OwnerPredicate' => 'THE predicate: the caller is the owner when the request\'s permission set contains the marker.',
        'AdministrativeCharter' => 'Not an owner check: it folds E-15b\'s marker into X(admin)\'s excluded codes (PC-14), a static '
            .'role-catalogue computation over no caller.',
        // Roles slice 2, C2 (design §4.2): one query for API-20's floor and reconciliation arm F. Every CALLER decision
        // still goes through OwnerPredicate::holdsMarker(), fed the set re-derived under the role-administration lock.
        'LiveGrantReader' => 'Counts live, active holders of the marker for API-20 and reconciliation arm F; decides nothing '
            .'about the caller.',
    ];

    /**
     * **Every class that calls `OwnerPredicate::holdsMarker()`** — G-29 / G-30 part 3 (`SEC` S-2, R-5, 2026-10-05). Each
     * caller is an act the owner marker decides, and granting the marker appoints a co-owner for every one of them, so
     * the predicate's docblock enumerates them. A new caller fails here until the docblock list and this pin are both
     * edited deliberately. Derived at build by `git grep -nE "holdsMarker\("` over `src/`, never copied from a design.
     *
     * @var list<string>
     */
    private const HOLDS_MARKER_CALLERS = [
        'AdjustmentDecisionWriter',
        'CreditNoteWriter',
        'GrantConfirmationPolicy',
        'GrantRules',
        'InvoicePaymentReversal',
        'InvoiceVoiding',
        'PurchaseOrderDutySeparation',
        'RoleGrantProposalConfirmation',
        'RoleGrantProposalRejection',
        'RoleRevocation',
        // Roles slice 4 C2 (2026-10-07): Q-A — only an owner changes a role that makes its holders owners.
        'RoleUpdate',
        'SalesReturnWriter',
        // Stock count C1 (2026-10-16): owner Q1 (C′) — only an owner counts a quarantine bin down or counts up a held item.
        'StockAdjuster',
        // Warehouses slice 1 C3 (2026-10-13): Q-W1 (d) — only an owner releases a stocked location from quarantine.
        'StorageLocationWriter',
    ];

    #[Test]
    public function theCallersOfTheMarkerAreExactlyThePinnedList(): void
    {
        $callers = [];
        foreach (PhpSourceScan::phpFilesUnder(\dirname(__DIR__, 2).'/src') as $path) {
            $tokens = array_values(array_filter(self::codeTokens((string) file_get_contents($path)), static fn (array $t): bool => \T_WHITESPACE !== $t[0]));
            foreach ($tokens as $i => [$id, $text]) {
                if (\T_STRING === $id && 'holdsMarker' === $text && '::' === ($tokens[$i - 1][1] ?? null) && '(' === ($tokens[$i + 1][1] ?? null)
                    && 'OwnerPredicate.php' !== basename($path)) {
                    $callers[] = basename($path, '.php');
                    break;
                }
            }
        }
        sort($callers);
        self::assertGreaterThan(5, \count($callers), 'The scan found almost no caller, so it is not scanning.');
        self::assertSame(self::HOLDS_MARKER_CALLERS, $callers,
            'The classes calling OwnerPredicate::holdsMarker() are not exactly HOLDS_MARKER_CALLERS. Each caller is an act the owner '
            .'marker decides; add it to OwnerPredicate\'s docblock enumeration AND to this pin, deliberately (SEC S-2, R-5).');
    }

    #[Test]
    public function thereIsExactlyOneAnswerToWhetherTheCallerIsTheOwner(): void
    {
        $sources = [];
        foreach (PhpSourceScan::phpFilesUnder(\dirname(__DIR__, 2).'/src') as $path) {
            $sources[$path] = (string) file_get_contents($path);
        }
        self::assertGreaterThan(100, \count($sources), 'The scan found almost no source under src/, so it is not scanning.');

        self::assertSame([], self::findings($sources),
            "A second answer to \"is this the owner\" exists, or the marker gained a reader nobody listed. The owner is decided by\n"
            ."OwnerPredicate::holdsMarker() over the request's permission set and nothing else (V2-R-19 + V2-S-5):\n  "
            .implode("\n  ", self::findings($sources)));

        self::assertSame(array_keys(self::MARKER_READERS), self::markerReaders($sources),
            'The classes reading OwnerMarker::CODE are not exactly MARKER_READERS. A new reader needs a line and a reason here; '
            .'a second owner predicate is a violation in both tracks.');

        // Planted controls.
        $plants = [
            'PlantSql.php' => "<?php final class PlantSql { public function f(\$c) { return \$c->fetchOne(\"SELECT 1 FROM role r WHERE r.name = 'owner'\"); } }",
            'PlantPhp.php' => "<?php final class PlantPhp { public function f(\$r) { return \$r->name === 'owner'; } }",
            'PlantConst.php' => '<?php use App\Modules\UserManagement\Domain\DefaultRoles; final class PlantConst { public function f($r) { return DefaultRoles::OWNER === $r; } }',
            'PlantConstant.php' => '<?php final class PlantConstant { public const OWNER_ROLE_NAME = \'x\'; }',
            'PlantReader.php' => '<?php use App\Modules\UserManagement\Domain\OwnerMarker; final class PlantReader { public function f(array $c) { return in_array(OwnerMarker::CODE, $c, true); } }',
            'PlantComment.php' => "<?php final class PlantComment { /** WHERE name = 'owner', OWNER_ROLE_NAME, === 'owner' */ public function f() {} }",
        ];
        $found = self::findings($plants);
        foreach (['PlantSql.php', 'PlantPhp.php', 'PlantConst.php', 'PlantConstant.php'] as $plant) {
            self::assertNotSame([], array_filter($found, static fn (string $f): bool => str_starts_with($f, $plant)),
                "The planted {$plant} was not found, so this scan is blind to that shape.");
        }
        self::assertSame([], array_filter($found, static fn (string $f): bool => str_starts_with($f, 'PlantComment.php')),
            'A docblock was read as code, so the scan would flag the record of the retired name test.');
        self::assertSame(['PlantReader'], self::markerReaders(['PlantReader.php' => $plants['PlantReader.php']]),
            'A second class reading OwnerMarker::CODE was not seen.');
    }

    /**
     * @param array<string, string> $sources path => source
     *
     * @return list<string>
     */
    private static function findings(array $sources): array
    {
        $findings = [];
        foreach ($sources as $path => $source) {
            $name = basename($path);
            $tokens = self::codeTokens($source);
            foreach ($tokens as $i => $token) {
                [$id, $text] = $token;
                if (\T_STRING === $id && 'OWNER_ROLE_NAME' === $text) {
                    $findings[] = $name.': the identifier OWNER_ROLE_NAME';
                }
                if ((\T_CONSTANT_ENCAPSED_STRING === $id || \T_ENCAPSED_AND_WHITESPACE === $id)
                    && 1 === preg_match("/\\bname\\s*=\\s*'owner'/i", $text)) {
                    $findings[] = $name.': a literal comparing a role name to \'owner\'';
                }
                if ('DefaultRoles.php' !== $name && \in_array($id, [\T_IS_IDENTICAL, \T_IS_EQUAL, \T_IS_NOT_IDENTICAL, \T_IS_NOT_EQUAL], true)) {
                    foreach ([self::operandBefore($tokens, $i), self::operandAfter($tokens, $i)] as $operand) {
                        if (\in_array($operand, ["'owner'", '"owner"', 'DefaultRoles::OWNER'], true)) {
                            $findings[] = $name.': a PHP comparison with '.$operand;
                        }
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, string> $sources
     *
     * @return list<string> short class names whose code reads `OwnerMarker::CODE`, sorted as MARKER_READERS is
     */
    private static function markerReaders(array $sources): array
    {
        $readers = [];
        foreach ($sources as $path => $source) {
            $tokens = array_values(array_filter(self::codeTokens($source), static fn (array $t): bool => \T_WHITESPACE !== $t[0]));
            foreach ($tokens as $i => [$id, $text]) {
                if (\in_array($id, [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)
                    && 'OwnerMarker' === substr((string) strrchr('\\'.$text, '\\'), 1)
                    && '::' === ($tokens[$i + 1][1] ?? null) && 'CODE' === ($tokens[$i + 2][1] ?? null)) {
                    $readers[] = basename($path, '.php');
                    break;
                }
            }
        }
        $order = array_flip(array_keys(self::MARKER_READERS));
        usort($readers, static fn (string $a, string $b): int => [$order[$a] ?? \PHP_INT_MAX, $a] <=> [$order[$b] ?? \PHP_INT_MAX, $b]);

        return $readers;
    }

    /** @return list<array{0: int, 1: string}> every token but comments and docblocks, single chars as id 0 */
    private static function codeTokens(string $source): array
    {
        $out = [];
        foreach (token_get_all($source) as $token) {
            $pair = \is_array($token) ? [$token[0], $token[1]] : [0, $token];
            if (\T_COMMENT === $pair[0] || \T_DOC_COMMENT === $pair[0]) {
                continue;
            }
            $out[] = $pair;
        }

        return $out;
    }

    /**
     * The operand beside a comparison operator: the nearest string literal if that is what stands there, otherwise the
     * next three code tokens joined (`DefaultRoles` `::` `OWNER`).
     *
     * @param list<array{0: int, 1: string}> $tokens
     */
    private static function operandAfter(array $tokens, int $i): string
    {
        return self::operand($tokens, $i, 1);
    }

    /** @param list<array{0: int, 1: string}> $tokens */
    private static function operandBefore(array $tokens, int $i): string
    {
        return self::operand($tokens, $i, -1);
    }

    /** @param list<array{0: int, 1: string}> $tokens */
    private static function operand(array $tokens, int $i, int $step): string
    {
        $parts = [];
        for ($j = $i + $step; $j >= 0 && $j < \count($tokens) && \count($parts) < 3; $j += $step) {
            if (\T_WHITESPACE === $tokens[$j][0]) {
                continue;
            }
            if ([] === $parts && \T_CONSTANT_ENCAPSED_STRING === $tokens[$j][0]) {
                return $tokens[$j][1];
            }
            $parts[] = $tokens[$j][1];
        }
        if (-1 === $step) {
            $parts = array_reverse($parts);
        }
        $joined = implode('', $parts);

        return str_ends_with($joined, 'DefaultRoles::OWNER') || str_starts_with($joined, 'DefaultRoles::OWNER') || str_contains($joined, '\\DefaultRoles::OWNER')
            ? 'DefaultRoles::OWNER'
            : $joined;
    }
}
