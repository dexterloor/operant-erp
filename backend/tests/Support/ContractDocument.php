<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * `docs/API.md`, read as data.
 *
 * ---------------------------------------------------------------------------
 * The availability problem, stated rather than hidden
 * ---------------------------------------------------------------------------
 * `docs/` is **gitignored** (repo policy: only runnable code, tests and infra
 * config are committed), so every check `docs/API.md` specifies for itself --
 * §3.3's extraction, §12.3's operationId derivation, §10.3's parameter
 * comparison, §5.2's catalogue -- has a domain that is not in the repository.
 * On a clean checkout those checks have nothing to read.
 *
 * The answer taken here is the `PINNED_` mechanism §12.3 already uses for its
 * singleton exception: each contract-derived test carries a **pin** in the test
 * file, and the pin is checked against the code on every run. Where the
 * document is present the pin is *also* checked against it, in both directions.
 * So a clean checkout still tests something real, a dev checkout additionally
 * catches drift between the pin and the contract, and nothing anywhere passes
 * by having no subject.
 */
final class ContractDocument
{
    public static function path(): string
    {
        return \dirname(__DIR__, 3).'/docs/API.md';
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
     * The document text between two headings, so a check whose subject is a
     * section's prose rather than a table has one accessor to reach it through.
     * The scan `SkipInvariantTest` clause (ii) runs looks for `ContractDocument::`,
     * so every such read stays visible to it.
     */
    public static function sectionBetween(string $startPrefix, string $endPrefix): string
    {
        $inside = false;
        $closed = false;
        $collected = [];

        foreach (self::lines() as $line) {
            if (!$inside && str_starts_with($line, $startPrefix)) {
                $inside = true;

                continue;
            }
            if ($inside && str_starts_with($line, $endPrefix)) {
                $closed = true;
                break;
            }
            if ($inside) {
                $collected[] = $line;
            }
        }

        // **An unmatched end heading returns nothing, never the rest of the
        // document.** `RED`: a caller asking "does §8.3 name this operation?"
        // would otherwise be asking it of every section below §8.3 the day that
        // heading is renamed, and a containment check that widens its own haystack
        // turns vacuous rather than red. The missing-start direction is already
        // safe, because it collects nothing.
        return $closed ? implode("\n", $collected) : '';
    }

    /**
     * §4.2's rows. The field-count rule is §3.3's own: with `-F'|'` a
     * well-formed row has 11 fields, so the columns are $2 … $10.
     *
     * @return array<string, array{operationId: string, verbResource: string, method: string, path: string, perm: string, idem: string, accepts: string, errors: string, query: string}>
     */
    public static function operationRows(): array
    {
        $rows = [];

        foreach (self::lines() as $line) {
            $cells = explode('|', $line);
            if (11 !== \count($cells)) {
                continue;
            }

            $cells = array_map(trim(...), $cells);
            $operationId = trim($cells[1], '`');

            // The header row and the `|---|` separator both have 11 fields.
            if ('operationId' === $operationId || 1 === preg_match('/^-*$/', $operationId)) {
                continue;
            }

            $rows[$operationId] = [
                'operationId' => $operationId,
                'verbResource' => $cells[2],
                'method' => $cells[3],
                'path' => trim($cells[4], '`'),
                'perm' => trim($cells[5], '`'),
                'idem' => self::mechanismCell($cells[6]),
                'accepts' => $cells[7],
                'errors' => $cells[8],
                'query' => $cells[9],
            ];
        }

        return $rows;
    }

    /**
     * §4.2's `Idem` cell, normalised to the bare mechanism token.
     *
     * ⚠ **The cell stopped being a binary on 2026-09-10** (API-14 as amended):
     * it now names the MECHANISM that refuses the repeat — `unique:<index>`,
     * `guard:<entity>.<property>`, `store`, `⛔ none`, or `no`. The old reader
     * did `strtolower(strip_tags(str_replace('*', '', …)))` with **no trim**, so
     * every value it returned carried its surrounding spaces and the only
     * comparison anybody wrote against it was `'yes' === …`. That comparison is
     * now false for all 38 marked rows, which is why the three pins that used it
     * were rewritten to ask {@see self::headerIsRequiredFor()} instead.
     *
     * Decoration is removed rather than parsed: bold (`**`), the backticks the
     * vocabulary table writes its tokens in, and the `⚠` marker `CreateExport`'s
     * row carries. What is left is the token itself, so `**⛔ none**` and
     * `` `unique:foo` `` compare as `⛔ none` and `unique:foo`.
     */
    public static function mechanismCell(string $cell): string
    {
        $bare = str_replace(['*', '`', '⚠'], '', strip_tags($cell));

        return strtolower(trim((string) preg_replace('/\s+/u', ' ', $bare)));
    }

    /** §4.2's `Idem` cell for one operation, as the bare mechanism token. */
    public static function mechanism(string $operationId): string
    {
        return self::operationRows()[$operationId]['idem'] ?? '';
    }

    /**
     * **API-14 as amended: the `Idempotency-Key` header is demanded for `store`
     * and `unique:` only.**
     *
     * A `guard:` operation refuses its own repeat through the state machine — or,
     * for a `Restore*`, through the live-row precondition — whether or not a
     * header arrives, so demanding one buys nothing and 428-ing without one is a
     * refusal with no purpose. `⛔ none` demands no header because the operation
     * is blocked from shipping at all: a header on an operation that
     * deduplicates nothing is the advertisement E9-2 exists to stop.
     */
    public static function headerIsRequiredFor(string $operationId): bool
    {
        $mechanism = self::mechanism($operationId);

        return 'store' === $mechanism || str_starts_with($mechanism, 'unique:');
    }

    /**
     * §14.1's open-item row for one code — `| **API-O-1** | …` — as its raw
     * markdown.
     *
     * The same shape as {@see DeliveryLedgerDocument::gateRow()} and located the
     * same way, by its own first cell, so a row that moves is still found. **A
     * code that no longer has a row returns the empty string, and the caller must
     * treat that as a failure rather than as nothing to check** — an open item
     * that has quietly left the register is the case this must not pass.
     */
    public static function registerRow(string $code): string
    {
        $needle = '| **'.$code.'** |';

        foreach (self::lines() as $line) {
            if (str_starts_with($line, $needle)) {
                return $line;
            }
        }

        return '';
    }

    /**
     * §5.2's catalogue: slug => status.
     *
     * @return array<string, int>
     */
    public static function problemCatalogue(): array
    {
        $catalogue = [];
        $inside = false;

        foreach (self::lines() as $line) {
            if (str_starts_with($line, '### 5.2 ')) {
                $inside = true;
                continue;
            }
            if ($inside && str_starts_with($line, '### 5.2a')) {
                break;
            }
            if (!$inside || !str_starts_with($line, '|')) {
                continue;
            }

            $cells = array_map(trim(...), explode('|', $line));
            if (\count($cells) < 4) {
                continue;
            }

            $slug = trim($cells[1], '`');
            if (1 !== preg_match('/^[a-z-]+$/', $slug) || 1 !== preg_match('/^\d{3}$/', $cells[2])) {
                continue;
            }

            $catalogue[$slug] = (int) $cells[2];
        }

        return $catalogue;
    }

    /**
     * The `Query parameters` cell of one row, as the set of parameter names it
     * declares. Prose in the cell (`*(bounded)*`, "required — exactly one of")
     * is stripped; the names are the backticked tokens.
     *
     * ⚠ **A parenthesised group is a parameter's VALUE SET, never more
     * parameters, and this method used to read it as more parameters.** Found
     * 2026-09-10 building the purchasing slice: `ListPurchaseOrders`' cell reads
     * ``` `status` (`open`·`draft`·`received`·`cancelled`); `supplier_id`; … ```
     * and the extraction returned **eleven** names for a row that declares seven.
     * §10.3's derivation step 2 is explicit that a `status` predicate becomes
     * *"the `status` segment parameter, whose value set is the manifest's own
     * segment names"* — one parameter, four values.
     *
     * It was invisible until now because no shipped row had a value set:
     * `ListItems`' cell has none. It affects `ListSalesOrders` and every future
     * segmented list identically, and in the direction that **fails loudly** — a
     * comparison against the operation's declared parameters cannot accidentally
     * find `open` — so nothing was silently green on it.
     *
     * @return list<string>
     */
    public static function queryParameters(string $operationId): array
    {
        $cell = self::operationRows()[$operationId]['query'] ?? '';

        if ('—' === $cell || '' === $cell) {
            return [];
        }

        // Value sets and prose asides both live in parentheses and neither
        // declares a parameter. Stripped before extraction rather than filtered
        // after it, because a filter would need to know the value names.
        $cell = (string) preg_replace('/\([^()]*\)/', '', $cell);

        preg_match_all('/`([a-z_]+)`/', $cell, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The `Accepted request fields` cell of one row, as the set of top-level
     * field names it declares.
     *
     * ⚠ **A `lines[].x` token declares the top-level field `lines`, and until
     * 2026-09-12 this reader dropped it entirely.** That blind spot was reported
     * in `PurchaseOrderOperationContractTest`'s own pin: `CreatePurchaseOrder`'s
     * cell named four `lines[].*` fields the code did not implement, and because
     * every one of them was discarded here, no comparison in the project could
     * see the shortfall — unlike `expected_at` on a line, which had a tie until
     * owner ruling Q2 withdrew the field on 2026-09-15. The
     * token is now folded to its top-level field, so the nested cell is visible
     * to the same comparison as every other field; the sub-fields themselves are
     * {@see self::nestedAcceptedFields()}'s.
     *
     * @return list<string>
     */
    public static function acceptedFields(string $operationId): array
    {
        $cell = self::operationRows()[$operationId]['accepts'] ?? '';

        if (str_starts_with($cell, '—')) {
            return [];
        }

        // ⚠ **The same parenthesis defect `queryParameters()` records, fixed on
        // this side too rather than left to be found.** That method strips
        // parenthesised groups because a value set is not a parameter; this one
        // did not, so a backticked token inside a parenthesised aside would read
        // as an accepted FIELD. No shipped row triggers it today — `RefreshToken`
        // is the only accepted-field cell carrying parentheses and it opens with
        // `—`, so it returns above before reaching here — which is precisely why
        // the asymmetry survived: the fix was applied where a row already failed
        // and not where the same reader could fail next. Applied in both places
        // now, so the rule is the reader's rather than one cell's.
        $cell = (string) preg_replace('/\([^()]*\)/', '', $cell);

        preg_match_all('/`([a-z_]+(?:\[\]\.[a-z_]+)?)`/', $cell, $matches);

        // `lines[].quantity` is the field `lines`, carrying an object. Folded
        // rather than dropped, and folded before `array_unique()` so the four
        // tokens of one nested field collapse to one name in the position the
        // first of them held.
        return array_values(array_unique(array_map(
            static fn (string $field): string => str_contains($field, '[]') ? strstr($field, '[]', true) : $field,
            $matches[1],
        )));
    }

    /**
     * The sub-fields one `Accepted request fields` cell declares under a nested
     * field — `lines[].quantity` under `lines`.
     *
     * **This is the half `acceptedFields()` cannot express**, and the reason it
     * exists is a measured blind spot rather than symmetry: §4.2 named four
     * `lines[].*` fields on `CreatePurchaseOrder` for a month while the operation
     * refused the whole field, and nothing could go red about it because the
     * tokens were discarded unread.
     *
     * @return list<string>
     */
    public static function nestedAcceptedFields(string $operationId, string $field): array
    {
        $cell = self::operationRows()[$operationId]['accepts'] ?? '';

        if (str_starts_with($cell, '—')) {
            return [];
        }

        $cell = (string) preg_replace('/\([^()]*\)/', '', $cell);

        preg_match_all('/`'.preg_quote($field, '/').'\[\]\.([a-z_]+)`/', $cell, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Whether §4.2 marks one row `*(bounded)*` in its `Query parameters` cell —
     * the same literal `ResponseShapeTest::subListedChild()` tests, read through
     * one accessor so batch C's checks do not carry a third spelling of it.
     * API-19a's exclusion 2 says these are exactly the sort manifest's
     * `(bounded, TB-1c` rows, *"so the two documents agree by construction"*.
     */
    public static function isBounded(string $operationId): bool
    {
        return str_contains(self::operationRows()[$operationId]['query'] ?? '', '*(bounded)*');
    }

    /**
     * API-19a's manifest-route → API-path table (§10.2), parsed rather than
     * restated: the first row pairs its backticked routes and paths by position,
     * the second maps a named screen surface, and "every other row" is the
     * identity, which is what a route absent from the returned map means.
     *
     * An empty map means the table was not found; a row whose two cells name a
     * different number of routes throws.
     *
     * @return array<string, string>
     */
    public static function manifestPathMapping(): array
    {
        $map = [];

        foreach (explode("\n", self::sectionBetween('**API-19a', '**Exclusion 1')) as $line) {
            $cells = array_map(trim(...), \array_slice(explode('|', $line), 1, -1));
            if (3 !== \count($cells) || 'Manifest route' === $cells[0] || 1 === preg_match('/^-+$/', $cells[0])) {
                continue;
            }

            preg_match_all('/`([^`]+)`/', $cells[0], $routes);
            preg_match_all('/`(\/[^`]*)`/', $cells[1], $paths);
            if ([] === $paths[1]) {
                continue; // "every other row | identical".
            }
            if (\count($routes[1]) !== \count($paths[1])) {
                throw new \LogicException('API-19a: a mapping row pairs '.\count($routes[1]).' routes with '.\count($paths[1]).' paths: '.$line);
            }

            foreach ($routes[1] as $i => $route) {
                $map[$route] = $paths[1][$i];
            }
        }

        return $map;
    }

    /**
     * Resource → table, read off §4.2's `Restore / <Resource>` rows, whose `Idem`
     * cell names the mechanism `guard:<table>.deleted_at`. It is the one place
     * §4.2 ties a resource name to a `SchemaManifest` table, and it can only name
     * a soft-deleting table — so a caller that needs "which resource is this soft
     * table?" asks here, and holds the result equal to
     * `SchemaManifest::softDeletedTables()` in both directions before trusting it.
     *
     * @return array<string, string>
     */
    public static function restoreGuardTables(): array
    {
        $tables = [];

        foreach (self::operationRows() as $row) {
            if (1 === preg_match('/^Restore \/ ([A-Za-z]+)$/', $row['verbResource'], $resource)
                && 1 === preg_match('/^guard:([a-z_]+)\.deleted_at$/', $row['idem'], $table)) {
                $tables[$resource[1]] = $table[1];
            }
        }

        return $tables;
    }

    /**
     * §15.1's per-row build status, operationId → `built` | `unbuilt`.
     *
     * §15.1 is a **four**-column table (six fields with `-F'|'`), which is why
     * {@see self::operationRows()} never reads it. The section runs from its
     * heading to the next heading **or the end of the document** — it is the last
     * section today, so {@see self::sectionBetween()}, which returns nothing for
     * an unmatched end heading, cannot be used.
     *
     * Strict on purpose: a table line inside the section with any other field
     * count, or a status other than the two words §15.1 uses, throws rather than
     * being skipped. A status row the parser quietly stepped over would take an
     * operation out of every set derived from here without anything going red.
     *
     * @return array<string, string>
     */
    public static function buildStatuses(): array
    {
        $inside = false;
        $statuses = [];

        foreach (self::lines() as $number => $line) {
            if (!$inside) {
                $inside = str_starts_with($line, '### 15.1 ');

                continue;
            }
            if (str_starts_with($line, '#')) {
                break;
            }
            if (!str_starts_with($line, '|')) {
                continue;
            }

            $cells = array_map(trim(...), explode('|', $line));
            if (6 !== \count($cells)) {
                throw new \UnexpectedValueException(\sprintf('§15.1 line %d has %d fields, not 6: %s', $number + 1, \count($cells), $line));
            }

            $operationId = trim($cells[1], '`');
            if ('operationId' === $operationId || 1 === preg_match('/^-*$/', $operationId)) {
                continue;
            }

            $status = str_replace('*', '', $cells[2]);
            if (!\in_array($status, ['built', 'unbuilt'], true)) {
                throw new \UnexpectedValueException(\sprintf('§15.1 line %d has status "%s", not built or unbuilt.', $number + 1, $status));
            }

            if (isset($statuses[$operationId])) {
                throw new \UnexpectedValueException(\sprintf('§15.1 line %d repeats %s.', $number + 1, $operationId));
            }

            $statuses[$operationId] = $status;
        }

        return $statuses;
    }
}
