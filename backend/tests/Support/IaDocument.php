<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * `docs/ux/IA.md`, read as data — **a test-parsed document from C5 batch C
 * (2026-09-15)**, beside `docs/API.md`, `docs/SECURITY_MODEL.md` and
 * `docs/DELIVERY_LEDGER.md`.
 *
 * ⚠ **Editing `IA.md` is now a suite change.** `SortManifestTest`,
 * `QueryParameterContractTest` and `SearchContractTest` read §5.6's sort
 * manifest and §5.5's TB-5 sentence through this class, so an edit to either
 * moves what those tests assert. Stage a copy, apply one edit at a time with a
 * suite run, and move pins in the same change (session 9 lesson 1).
 *
 * `docs/` is gitignored, so this reader carries the availability probe
 * {@see self::isAvailable()}, and every test method that reaches it must skip
 * first when it is false. `SkipInvariantTest` derives the set of document
 * readers from the classes declaring `function isAvailable`, so it covers this
 * class without being edited.
 *
 * Only what the tests need is parsed, and nothing is interpreted here beyond
 * the cell text: the exclusions API-19a defines (`⛔`, `(bounded, TB-1c`) are
 * read off the cells by the helpers below, and the callers decide which side of
 * a comparison each applies to.
 */
final class IaDocument
{
    public static function path(): string
    {
        return \dirname(__DIR__, 3).'/docs/ux/IA.md';
    }

    public static function isAvailable(): bool
    {
        return is_file(self::path());
    }

    /** @return list<string> */
    public static function lines(): array
    {
        return explode("\n", (string) file_get_contents(self::path()));
    }

    /**
     * The text between two headings; an unmatched end heading returns nothing,
     * never the rest of the document ({@see ContractDocument::sectionBetween()}'s
     * rule, for the same reason).
     */
    public static function sectionBetween(string $startPrefix, string $endPrefix): string
    {
        $inside = false;
        $collected = [];

        foreach (self::lines() as $line) {
            if (!$inside && str_starts_with($line, $startPrefix)) {
                $inside = true;

                continue;
            }
            if ($inside && str_starts_with($line, $endPrefix)) {
                return implode("\n", $collected);
            }
            if ($inside) {
                $collected[] = $line;
            }
        }

        return '';
    }

    /**
     * §5.6's table, one entry per row, cells as written. `route` is the first
     * backticked token of the first cell when it is a path, else the whole cell
     * (the `History section (L-7) on any detail page` row, which API-19a maps);
     * `view` is what is left of the first cell.
     *
     * A row whose cell count is not the header's is a parse failure and throws,
     * so a table that changes shape is loud rather than half-read.
     *
     * @return list<array{route: string, view: string, default: string, alternates: string, index: string, predicate: string}>
     */
    public static function sortManifest(): array
    {
        $rows = [];
        $header = false;

        foreach (explode("\n", self::sectionBetween('### 5.6 ', '### 5.7 ')) as $line) {
            if (!str_starts_with($line, '|')) {
                if ($header && [] !== $rows) {
                    break; // The table ended.
                }

                continue;
            }

            $cells = array_map(trim(...), \array_slice(explode('|', $line), 1, -1));

            if (str_starts_with($cells[0] ?? '', 'Route / view')) {
                $header = true;

                continue;
            }
            if (!$header || 1 === preg_match('/^-+$/', $cells[0] ?? '')) {
                continue;
            }
            if (5 !== \count($cells)) {
                throw new \LogicException('IA.md §5.6: a manifest row does not have five cells: '.$line);
            }

            $route = $cells[0];
            $view = '';
            if (1 === preg_match('/^`(\/[^`]*)`(.*)$/u', $cells[0], $m)) {
                $route = $m[1];
                $view = trim(str_replace(['`', '*'], '', $m[2]));
            }

            $rows[] = [
                'route' => $route,
                'view' => $view,
                'default' => $cells[1],
                'alternates' => $cells[2],
                'index' => $cells[3],
                'predicate' => $cells[4],
            ];
        }

        return $rows;
    }

    /** API-19a exclusion 1's marker, read off the default-order cell. */
    public static function isBlocked(array $row): bool
    {
        return str_contains($row['default'], '⛔');
    }

    /** API-19a exclusion 2's marker, the literal `(bounded, TB-1c`. */
    public static function isBounded(array $row): bool
    {
        return str_contains($row['default'], '(bounded, TB-1c');
    }

    /**
     * The orders a row names: the backticked tokens of its default and
     * alternate cells. The caller applies the exclusions.
     *
     * @return list<string>
     */
    public static function orderKeys(array $row): array
    {
        preg_match_all('/`([a-z_]+)`/', $row['default'].' '.$row['alternates'], $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * §10.3 derivation step 1's `?`-placeholders in the predicate cell, as the
     * column each constrains (`supplier_id = ?` → `supplier_id`).
     *
     * @return list<string>
     */
    public static function placeholders(array $row): array
    {
        preg_match_all('/`?([a-z_]+)`?\s*=\s*\?/', $row['predicate'], $m);

        return array_values(array_unique($m[1]));
    }

    /** §10.3 derivation step 2's subject: a `status = …` or `status IN (…)` predicate. */
    public static function hasStatusPredicate(array $row): bool
    {
        return 1 === preg_match('/\bstatus\s*(=|IN\b)/', $row['predicate']);
    }

    /** §10.3 derivation step 3's subject. */
    public static function hasArchivedPredicate(array $row): bool
    {
        return str_contains($row['predicate'], 'deleted_at IS NOT NULL');
    }

    /**
     * TB-5's *Searchable today* sentence (§5.5), from its bold opener to the
     * *Not searchable* sentence that follows it, whitespace collapsed. Empty when
     * either marker is missing, so a caller asserting on it fails rather than
     * reading the wrong prose.
     */
    public static function tb5SearchableSentence(): string
    {
        $section = (string) preg_replace('/\s+/u', ' ', self::sectionBetween('### 5.5 ', '### 5.6 '));

        if (1 !== preg_match('/\*\*Searchable today\*\*(.*?)\*\*Not searchable/u', $section, $m)) {
            return '';
        }

        return trim($m[1]);
    }
}
