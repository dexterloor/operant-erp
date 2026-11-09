<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **The suite refuses any database that is not its own, before it removes a row** (2a C2; `demo-seed-2a-design.md`
 * Revision 1 D-6, `demo-seed-2a-roles.md` A-4, `demo-seed-2a-roles-red.md` M-2).
 *
 * Every test that empties tables does it through {@see TenancyTestCase::truncateTenantTables()} — `setUp` calls it, and so
 * does `TenantClaimProbeTest` directly — so the check lives inside that method. Two halves, both pure (no database):
 *   1. the decision: only the comment `erp-local-gate` passes; `erp-local-demo`, no comment at all (a volume created before
 *      the init script wrote one) and any other value are refused with the one fixed line;
 *   2. the order: the method's body calls the check BEFORE its first string token containing `TRUNCATE` (both string
 *      token kinds — the statement is an interpolated string), and such a token exists (anti-vacuity).
 * Mutations: M2 (the decision always passes) → half 1 red; M2b (the call removed, or moved after the statement) → half 2
 * red. What this cannot show: the refusal against a real demo database (that would need a second database); the
 * end-to-end run on a fresh clone records it.
 */
final class SuiteDatabasePurposeTest extends TestCase
{
    private const CHECK = 'refuseUnlessTheSuitesDatabase';

    #[Test]
    public function onlyTheGateCommentPasses(): void
    {
        self::assertNull(TenancyTestCase::refusalForComment('erp-local-gate'));
        foreach (['erp-local-demo', null, '', 'erp-local-gate ', 'ERP-LOCAL-GATE', 'erp-local-gatex', 'gate'] as $comment) {
            self::assertSame(TenancyTestCase::NOT_THE_SUITES_DATABASE, TenancyTestCase::refusalForComment($comment), 'Not refused: '.json_encode($comment));
        }
        self::assertStringContainsString('make reset', TenancyTestCase::NOT_THE_SUITES_DATABASE);
    }

    #[Test]
    public function theTruncationStepChecksTheDatabaseBeforeItsTruncate(): void
    {
        $body = self::methodBody((string) file_get_contents(__DIR__.'/TenancyTestCase.php'), 'truncateTenantTables');
        self::assertNull(self::orderFinding($body));

        // Planted controls through the same decision.
        $plant = static fn (string $statements): array => self::methodBody('<?php class X { protected function truncateTenantTables(): void { '.$statements.' } }', 'truncateTenantTables');
        self::assertNull(self::orderFinding($plant('self::'.self::CHECK.'($this->o()); $this->o()->executeStatement("TRUNCATE $t RESTART IDENTITY");')));
        self::assertNotNull(self::orderFinding($plant('$this->o()->executeStatement("TRUNCATE $t RESTART IDENTITY"); self::'.self::CHECK.'($this->o());')), 'A check after the TRUNCATE was not refused.');
        self::assertNotNull(self::orderFinding($plant('$this->o()->executeStatement(\'TRUNCATE x\');')), 'A TRUNCATE with no check was not refused.');
        self::assertNotNull(self::orderFinding($plant('self::'.self::CHECK.'($this->o());')), 'A body with no TRUNCATE was not reported (anti-vacuity).');
    }

    /** @param list<\PhpToken> $body */
    private static function orderFinding(array $body): ?string
    {
        $check = null;
        $truncate = null;
        foreach ($body as $i => $token) {
            if (null === $check && $token->is(\T_STRING) && self::CHECK === $token->text && '(' === ($body[$i + 1] ?? null)?->text) {
                $check = $i;
            }
            if (null === $truncate && $token->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE]) && str_contains(strtoupper($token->text), 'TRUNCATE')) {
                $truncate = $i;
            }
        }

        return match (true) {
            null === $truncate => 'Anti-vacuity: the method has no string token containing TRUNCATE, so the order check proves nothing.',
            null === $check => 'truncateTenantTables() does not call '.self::CHECK.'(): the suite would empty a demo database.',
            $check > $truncate => 'truncateTenantTables() calls '.self::CHECK.'() only after its TRUNCATE.',
            default => null,
        };
    }

    /** @return list<\PhpToken> the tokens between the method's opening and closing braces (no whitespace or comments) */
    private static function methodBody(string $source, string $method): array
    {
        $tokens = array_values(array_filter(\PhpToken::tokenize($source), static fn (\PhpToken $t): bool => !$t->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])));
        foreach ($tokens as $i => $token) {
            if ($token->is(\T_FUNCTION) && $method === ($tokens[$i + 1] ?? null)?->text) {
                $depth = 0;
                $body = [];
                for ($j = $i + 2, $n = \count($tokens); $j < $n; ++$j) {
                    $text = $tokens[$j]->text;
                    if ('{' === $text || $tokens[$j]->is([\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                        ++$depth;
                        if (1 === $depth) {
                            continue;
                        }
                    } elseif ('}' === $text) {
                        --$depth;
                        if (0 === $depth) {
                            return $body;
                        }
                    }
                    if ($depth >= 1) {
                        $body[] = $tokens[$j];
                    }
                }
            }
        }

        return [];
    }
}
