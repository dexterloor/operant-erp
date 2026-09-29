<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * `docs/adr/ADR-008-separation-of-duty-and-role-expansion.md` §2's SoD table,
 * read as data.
 *
 * The third gitignored-document reader, and it exists for the same reason as
 * `ContractDocument` and `SecurityModelDocument`: the committed artifact
 * (`App\Modules\UserManagement\Domain\SeparationOfDuty`) is the runtime home of
 * §2's table, and a copy with no comparison is how two copies of a fact end up
 * disagreeing. Where the document is present, this class is the comparison.
 *
 * ---------------------------------------------------------------------------
 * It reads the halves SEPARATELY, and that is the point
 * ---------------------------------------------------------------------------
 * `docs/adr/sod_check.py`'s own docstring recorded the defect this avoids *(until
 * 2026-09-29, when sales step 6 C7 made the script's check-back an equality and
 * derived `admin` — ADR-008 A2.1a)*: its check-back asserted `(a|b) <= expanded`, **a subset test**, so *"extra tokens in
 * the DOCUMENT are invisible to it"* — if A2.1 lands in §2 and the script's dict
 * is not updated, it still prints `SoD encoding checked back … OK`. Its docstring
 * names the remedy: *"a superset or equality test over the classified tokens is
 * what would close it."*
 *
 * That is what this class enables. Every row is written `preparing act /
 * consummating act`, so the cell splits on ` / ` into two **classified** halves,
 * and `SeparationOfDutyDocumentTest` compares them for **equality** with the
 * artifact, per half, per row. A token added to either side of §2 fails, and so
 * does a row written the other way round — which no subset test over the union
 * can see.
 */
final class Adr008Document
{
    public static function path(): string
    {
        return \dirname(__DIR__, 3).'/docs/adr/ADR-008-separation-of-duty-and-role-expansion.md';
    }

    public static function isAvailable(): bool
    {
        return is_file(self::path());
    }

    /**
     * §2's four rows, each split into its two halves.
     *
     * The shorthand `` `purchase_order.approve` **or** `.cancel` `` is expanded
     * the way §3's catalogue shorthand is: a backticked token beginning with a
     * dot inherits the resource from the token before it, **within the same
     * half**.
     *
     * @return array<string, array{preparing: list<string>, controlled: list<string>}>
     */
    public static function separationOfDutyRows(): array
    {
        $rows = [];

        foreach (explode("\n", (string) file_get_contents(self::path())) as $line) {
            if (1 !== preg_match('/^\| \*\*(SoD-\d)\*\* \| (.*?) \| /', $line, $match)) {
                continue;
            }

            $halves = explode(' / ', $match[2]);

            if (2 !== \count($halves)) {
                // A row this parser cannot classify is reported as an empty row
                // rather than silently dropped: the test asserts every row it
                // expects is present and non-empty on both sides.
                $rows[$match[1]] = ['preparing' => [], 'controlled' => []];

                continue;
            }

            $rows[$match[1]] = [
                'preparing' => self::codesIn($halves[0]),
                'controlled' => self::codesIn($halves[1]),
            ];
        }

        return $rows;
    }

    /**
     * The permission codes in one half of a row, shorthand expanded.
     *
     * @return list<string>
     */
    private static function codesIn(string $half): array
    {
        $codes = [];
        $resource = null;

        preg_match_all('/`([A-Za-z_][A-Za-z0-9_]*)?\.([a-z_]+)`/', $half, $matches, \PREG_SET_ORDER);

        foreach ($matches as $match) {
            if ('' !== ($match[1] ?? '')) {
                $resource = $match[1];
            }
            if (null === $resource) {
                continue;
            }

            $code = $resource.'.'.$match[2];

            if (!\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
