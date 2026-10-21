<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * §4.2's `Perm`-cell grammar, as a pure function (P-0). Kept out of {@see ContractDocument} so the grammar's planted
 * controls run on every checkout: reading the gitignored document is what must be gated, not parsing a string.
 */
final class PermissionCell
{
    /**
     * **§4.2's `Perm` cell, parsed (P-0, `g3-g7b-final-red.md` F-M2).** The grammar, and nothing else:
     *
     *   - `—` → `none`;
     *   - one or more backticked codes separated by single spaces → `all_of`, codes in CELL order (a caller compares that
     *     order with the byte-sorted declaration, so an unsorted cell is red rather than normalised away);
     *   - `*(derived)*` or `*(derived — see below)*` (any `*(derived…)*`) → `derived`, no codes.
     *
     * A cell outside the grammar is `kind: unparsed` carrying the raw text, so a comparison against it is red and says
     * what it saw. A `''` cell (an unwritten row) is unparsed too, never `none`.
     *
     * @return array{kind: string, codes: list<string>}
     */
    public static function parse(string $cell): array
    {
        $cell = trim($cell);

        if ('—' === $cell) {
            return ['kind' => 'none', 'codes' => []];
        }

        if (1 === preg_match('/^\*\(derived[^)]*\)\*$/u', $cell)) {
            return ['kind' => 'derived', 'codes' => []];
        }

        if (1 === preg_match('/^`[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*`( `[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*`)*$/', $cell)) {
            return ['kind' => 'all_of', 'codes' => array_map(static fn (string $c): string => trim($c, '`'), explode(' ', $cell))];
        }

        return ['kind' => 'unparsed', 'codes' => [$cell]];
    }
}
