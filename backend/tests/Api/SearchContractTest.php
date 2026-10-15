<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Support\ContractDocument;
use App\Tests\Support\IaDocument;
use PHPUnit\Framework\Attributes\Test;

/**
 * API-19d / TB-5 (`docs/API.md` §10.3, `docs/ux/IA.md` §5.5) — free-text search
 * touches exactly the columns TB-5 names, and is never a cross-column `ILIKE`.
 *
 * ---------------------------------------------------------------------------
 * Domain
 * ---------------------------------------------------------------------------
 * **Every built list**, from {@see ListOperationFixtures}; the ones offering `q`
 * are driven with `?q=<probe>` under the SQL recorder. **TB-5's columns are parsed
 * from its own sentence** (`IaDocument::tb5SearchableSentence()`), and *"the five
 * document numbers"* — which the sentence names without naming — are derived as
 * API-19d does, from the sort manifest's `<table>_tenant_number_live` /
 * `<table>_number_unique` indexes and the `*_number` order each such row names.
 * (TB-5 said four until 2026-09-15, when `UX` closed API-O-19 at five, the side
 * this test had already derived.) A list's table is its §4.2
 * resource tied by the `Restore` guard or, failing one, by its snake-case name.
 *
 * ---------------------------------------------------------------------------
 * How a searched column is found in the SQL
 * ---------------------------------------------------------------------------
 * Every recorded execution whose bound values contain the probe is read: quoted
 * literals and comments are blanked (length kept), the top-level `WHERE` clause is
 * split into leaf predicates at depth-0 `AND`/`OR` (descending into a predicate
 * that is wholly parenthesised), and each leaf holding a `?` bound to the probe is
 * the search. Its columns are every `alias.column` (resolved through the
 * statement's `FROM`/`JOIN` aliases) and every bare identifier that is a column of
 * a table the statement reads. A probe that appears in the SQL text rather than in
 * a bound value is a defect by itself — an interpolated search cannot be
 * attributed.
 *
 * **Residual non-coverage (both tests):** a search predicate outside the top-level
 * `WHERE` (a CTE, a `JOIN … ON`, a subquery's own `WHERE` is attributed to its
 * whole leaf, which over-counts — the loud direction); `BETWEEN … AND …` splits
 * as two leaves; Postgres dollar-quoted and `E'…'` strings are not modelled; a
 * search on a value transformed before binding so it no longer contains the probe
 * (e.g. reversed, hashed) is not seen.
 */
final class SearchContractTest extends PurchaseOrderTestCase
{
    use ListOperationFixtures;

    /**
     * **TB-5 / API-19d — `q` is offered on a list iff TB-5 names a searchable
     * column on its table, and a search touches exactly those columns.**
     *
     * Controls inside this method: TB-5's sentence was found and parsed to columns,
     * each of which exists in the schema; the document-number derivation is
     * non-empty and each derived column exists; at least one built list offers `q`
     * and at least one does not; every list offering `q` bound the probe to at least
     * one predicate (the search reached SQL); the analyser attributes a planted
     * `i.sku LIKE ?` to `inventory_item.sku`, a planted `(i.sku LIKE ? OR i.name
     * LIKE ?)`, `lower(i.name) LIKE lower(?)`, `i.name ~~* ?`, `i.name SIMILAR TO
     * ?`, `position(? in i.name) > 0` and a bare `name LIKE ?` each to
     * `inventory_item.name`, a joined `s.name LIKE ?` to `supplier.name`, and flags
     * a planted interpolated `i.name LIKE 'probe%'`.
     *
     * **Mutations (C5 batch C, 2026-09-15; cache deleted — Rule 17):** §10.3's
     * own — `ItemReader`'s search becomes `(i.sku LIKE ? OR i.name LIKE ?)` → red,
     * `ListItems` touches `inventory_item.name`; TB-5's sentence in `IA.md` gains
     * `inventory_item.name` (restored by `cmp`) → red, the search lacks it; `q`
     * added to `PurchaseOrderLineCollection` → red, offered where TB-5 names no
     * column. Recorded in `docs/session10/progress-C5C.md` row 24.
     */
    #[Test]
    public function searchTouchesOnlyTheColumnsTB5Names(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored; each list\'s table is read from §4.2.');
        }
        if (!IaDocument::isAvailable()) {
            self::markTestSkipped('docs/ux/IA.md is gitignored; TB-5\'s sentence and the sort manifest are read from it.');
        }

        $this->assertThePlantedSearchesAreAttributed();

        $searchable = $this->tb5Columns();
        $defects = [];
        $offering = [];

        foreach ($this->searchSubjects() as $name => [$table, $offers, $leaves]) {
            $expected = $searchable[$table] ?? [];
            $offering[$offers ? 'offers' : 'does not offer'] = true;

            if ($offers !== ([] !== $expected)) {
                $defects[] = \sprintf('%s %s `q`, and TB-5 names %s on %s', $name, $offers ? 'offers' : 'does not offer',
                    [] === $expected ? 'no searchable column' : implode(', ', $expected), $table);
            }
            if (!$offers) {
                continue;
            }
            if ([] === $leaves) {
                $defects[] = $name.' offers `q` and no search was read from it (its fixture names no search value)';
                continue;
            }

            $touched = [];
            foreach ($leaves as $leaf) {
                if (null !== $leaf['interpolated']) {
                    $defects[] = $name.' interpolates the search value into its SQL: '.$leaf['interpolated'];
                }
                $touched = [...$touched, ...$leaf['columns']];
            }
            $touched = array_values(array_unique($touched));
            sort($touched);

            foreach (array_diff($touched, $expected) as $column) {
                $defects[] = \sprintf('%s\'s search touches %s, which TB-5 does not name', $name, $column);
            }
            foreach (array_diff($expected, $touched) as $column) {
                $defects[] = \sprintf('%s\'s search does not touch %s, which TB-5 names', $name, $column);
            }
        }

        self::assertSame([], $defects, 'TB-5 / API-19d: q is offered where TB-5 names no column, or a search touches other columns than TB-5\'s.');
        self::assertCount(2, $offering, 'The built lists do not include both a list offering q and one that does not.');
    }

    /**
     * **TB-5 — a search is never a cross-column `ILIKE`.** Red when one searched
     * predicate spans two or more columns (a concatenation, a `concat()`, an
     * `ILIKE ANY(ARRAY[…])`), or when the case-insensitive match family —
     * `ILIKE`, `~~*`, `~*`, `SIMILAR TO`, `lower()`/`upper()` — is applied to two
     * or more distinct columns in one statement's search. A case-sensitive prefix
     * `LIKE` over each of two TB-5 columns (`supplier.name` / `.code`) is not this
     * defect; the column set is {@see self::searchTouchesOnlyTheColumnsTB5Names()}'s.
     *
     * Controls inside this method: every list offering `q` bound the probe to at
     * least one predicate; the detector flags a planted `i.sku || i.name ILIKE ?`,
     * `concat(i.sku, i.name) ILIKE ?`, `(i.sku ILIKE ? OR i.name ILIKE ?)`,
     * `i.sku ~~* ? OR i.name ~* ?` and `lower(i.sku) LIKE ? OR upper(i.name) LIKE ?`,
     * and does not flag `i.sku LIKE ?`, `lower(i.sku) LIKE lower(?)`, or
     * `(s.name LIKE ? OR s.code LIKE ?)`.
     *
     * **Mutation (C5 batch C, 2026-09-15; cache deleted — Rule 17):** `ItemReader`'s
     * search becomes `i.sku || i.name ILIKE ?` → red here (one predicate over two
     * columns) and in the sibling (`inventory_item.name`); `PurchaseOrderReader`'s
     * becomes `(lower(po.order_number) LIKE lower(?) OR s.name ~* ?)` → red here
     * (a case-insensitive match over two columns, in two predicates) and in the
     * sibling (`supplier.name`). Recorded in `docs/session10/progress-C5C.md` row 25.
     *
     * **Residual non-coverage (V1-R-11, recorded 2026-09-16, V4 B4e; from the V1
     * RED review, not re-measured here):** the case-insensitive family is lexical.
     * A cross-column case-insensitive match reached through a `citext` column, a
     * nondeterministic ICU collation (`COLLATE "und-x-icu"`), or `unaccent()` is
     * not flagged. Owner: BE-LEAD. Trigger to revisit: the first migration that
     * adds a `citext` column, a nondeterministic collation or the `unaccent`
     * extension.
     */
    #[Test]
    public function noSearchIsACrossColumnIlike(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored; each list\'s table is read from §4.2.');
        }

        $columns = ['inventory_item' => ['id', 'sku', 'name', 'supplier_id'], 'supplier' => ['id', 'name', 'code']];
        $from = 'SELECT i.id FROM inventory_item i LEFT JOIN supplier s ON s.id = i.supplier_id WHERE i.deleted_at IS NULL AND ';
        foreach ([
            'i.sku || i.name ILIKE ?' => 1,
            'concat(i.sku, i.name) ILIKE ?' => 1,
            '(i.sku ILIKE ? OR i.name ILIKE ?)' => 2,
            'i.sku ~~* ? OR i.name ~* ?' => 2,
            'lower(i.sku) LIKE ? OR upper(i.name) LIKE ?' => 2,
        ] as $predicate => $bound) {
            $leaves = self::searchLeaves($from.$predicate.' ORDER BY i.sku LIMIT ?', array_merge(array_fill(1, $bound, 'C5Q%'), [51]), 'C5Q', $columns);
            self::assertNotSame([], self::crossColumnDefects($leaves), 'The detector missed a planted cross-column search: '.$predicate);
        }
        foreach ([
            'i.sku LIKE ?' => 1,
            'lower(i.sku) LIKE lower(?)' => 1,
            '(s.name LIKE ? OR s.code LIKE ?)' => 2,
        ] as $predicate => $bound) {
            $leaves = self::searchLeaves($from.$predicate.' ORDER BY i.sku LIMIT ?', array_merge(array_fill(1, $bound, 'C5Q%'), [51]), 'C5Q', $columns);
            self::assertNotSame([], $leaves, 'The analyser found no search leaf in a planted '.$predicate);
            self::assertSame([], self::crossColumnDefects($leaves), 'The detector flagged a single-column search: '.$predicate);
        }

        $defects = [];
        foreach ($this->searchSubjects() as $name => [, $offers, $leaves]) {
            if (!$offers) {
                continue;
            }
            foreach (self::crossColumnDefects($leaves) as $defect) {
                $defects[] = $name.': '.$defect;
            }
        }

        self::assertSame([], $defects, 'TB-5: a search is implemented as a cross-column ILIKE.');
    }

    /**
     * Every built list: its table, whether it offers `q`, and — where it does —
     * the probe-bound search leaves of every statement `?q=<probe>` issued.
     *
     * @return array<string, array{0: string, 1: bool, 2: list<array{text: string, columns: list<string>, insensitive: bool, interpolated: ?string}>}>
     */
    private function searchSubjects(): array
    {
        $rows = ContractDocument::operationRows();
        $softResources = ContractDocument::restoreGuardTables();
        $resources = [];
        foreach ($rows as $row) {
            if (1 === preg_match('/^(?!List )[A-Za-z]+ \/ ([A-Za-z]+)$/', $row['verbResource'], $m)) {
                $resources[] = $m[1];
            }
        }
        // §4.4.3's additions table names resources too, as in `ResponseShapeTest::knownResources()`: `Permission`
        // (roles slice 1) has only a `List` row, so no non-List row names it and "List / Permissions" would resolve to
        // nothing. Found by running `ListPermissions`, 2026-09-23.
        $resources = array_values(array_unique([...$resources, ...array_keys(ResponseShapeTest::declaredAdditions())]));

        $fixtures = $this->listFixtures();
        $subjects = [];

        foreach ($this->listOperations() as $name => $operation) {
            $resource = ResponseShapeTest::resourceOf($rows[$name]['verbResource'], $resources);
            $table = $softResources[$resource] ?? ContractDocument::derivedResourceTables()[$resource] ?? strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $resource));
            self::assertArrayHasKey($table, SchemaManifest::TABLES, $name.'\'s resource '.$resource.' ties to no SchemaManifest table ('.$table.').');

            $offers = \in_array('q', self::declaredParameters($operation), true);
            $leaves = [];

            [$path, $seed] = $fixtures[$name]();
            $context = $seed(2);

            // A list offering `q` whose fixture has no search value is reported
            // by the caller (the offered-iff clause), not asserted away here.
            if ($offers && null !== $context['search']) {
                $probe = (string) $context['search'];
                self::assertMatchesRegularExpression('/^[A-Za-z0-9-]+$/', $probe, $name.'\'s search probe carries a LIKE metacharacter, so a bound value may not contain it verbatim.');

                $this->recorder()->start();
                $this->send('GET', self::withQuery($path, 'q='.urlencode($probe)));
                $this->recorder()->stop();
                self::assertSame(200, $this->httpStatus(), $name.' did not answer its search.');
                self::assertNotSame([], $this->json()['items'] ?? [], $name.'\'s search matched no seeded row, so the search path may not have run.');

                $columns = [];
                foreach ($this->recorder()->executions() as $execution) {
                    foreach (self::tablesRead($execution['sql']) as $read) {
                        $columns[$read] ??= $this->columnsOf($read);
                    }
                    $leaves = [...$leaves, ...self::searchLeaves($execution['sql'], $execution['params'], $probe, $columns)];
                }
                self::assertNotSame([], $leaves, $name.' bound its search value to no predicate, so there was nothing to attribute.');
            }

            $subjects[$name] = [$table, $offers, $leaves];
        }

        return $subjects;
    }

    /**
     * TB-5's searchable columns, table → sorted list of `table.column`.
     *
     * @return array<string, list<string>>
     */
    private function tb5Columns(): array
    {
        $sentence = IaDocument::tb5SearchableSentence();
        self::assertNotSame('', $sentence, 'TB-5\'s "Searchable today" sentence was not found in IA.md §5.5.');
        self::assertStringContainsString('document numbers', $sentence, 'TB-5\'s sentence no longer names the document numbers.');

        $named = [];
        preg_match_all('/`([a-z_]*)\.([a-z_]+)`/', $sentence, $m, \PREG_SET_ORDER);
        $table = null;
        foreach ($m as [, $t, $column]) {
            $table = '' === $t ? $table : $t;
            self::assertNotNull($table, 'TB-5 names `.'.$column.'` before any table.');
            $named[] = [$table, $column];
        }
        self::assertGreaterThan(3, \count($named), 'TB-5\'s sentence parsed to almost no column.');

        $numbers = [];
        foreach (IaDocument::sortManifest() as $row) {
            preg_match_all('/`([a-z_]+?)_(?:tenant_number_live|number_unique)`/', $row['index'], $indexes);
            foreach ($indexes[1] as $t) {
                $keys = array_values(array_filter(IaDocument::orderKeys($row), static fn (string $k): bool => str_ends_with($k, '_number')));
                self::assertCount(1, $keys, 'The manifest row naming '.$t.'\'s number index names not exactly one *_number order.');
                $numbers[$t.'.'.$keys[0]] = [$t, $keys[0]];
            }
        }
        self::assertNotSame([], $numbers, 'No document-number index was derived from the manifest.');

        $searchable = [];
        foreach ([...$named, ...array_values($numbers)] as [$t, $column]) {
            self::assertContains($column, $this->columnsOf($t), \sprintf('TB-5 / the manifest names %s.%s, which the schema does not have.', $t, $column));
            $searchable[$t][] = $t.'.'.$column;
        }
        foreach ($searchable as $t => $list) {
            $list = array_values(array_unique($list));
            sort($list);
            $searchable[$t] = $list;
        }

        return $searchable;
    }

    private function assertThePlantedSearchesAreAttributed(): void
    {
        $columns = ['inventory_item' => ['id', 'sku', 'name', 'supplier_id', 'deleted_at'], 'supplier' => ['id', 'name', 'code']];
        $from = 'SELECT i.id FROM inventory_item i LEFT JOIN supplier s ON s.id = i.supplier_id WHERE i.deleted_at IS NULL AND ';
        $attributed = static function (string $predicate, int $bound) use ($from, $columns): array {
            $leaves = self::searchLeaves($from.$predicate.' ORDER BY i.sku LIMIT ?', array_merge(array_fill(1, $bound, 'C5Q%'), [51]), 'C5Q', $columns);
            $touched = array_values(array_unique(array_merge([], ...array_column($leaves, 'columns'))));
            sort($touched);

            return $touched;
        };

        self::assertSame(['inventory_item.sku'], $attributed('i.sku LIKE ?', 1));
        self::assertSame(['inventory_item.name', 'inventory_item.sku'], $attributed('(i.sku LIKE ? OR i.name LIKE ?)', 2));
        foreach (['lower(i.name) LIKE lower(?)', 'i.name ~~* ?', 'i.name SIMILAR TO ?', 'position(? in i.name) > 0'] as $predicate) {
            self::assertSame(['inventory_item.name'], $attributed($predicate, 1), 'The analyser did not attribute '.$predicate);
        }
        self::assertSame(['supplier.name'], $attributed('s.name LIKE ?', 1));

        $bare = self::searchLeaves('SELECT id FROM inventory_item WHERE name LIKE ? LIMIT ?', [1 => 'C5Q%', 2 => 51], 'C5Q', $columns);
        self::assertSame(['inventory_item.name'], array_merge([], ...array_column($bare, 'columns')), 'The analyser did not attribute a bare column.');

        $interpolated = self::searchLeaves($from."i.name LIKE 'C5Q%' LIMIT ?", [1 => 51], 'C5Q', $columns);
        self::assertNotNull($interpolated[0]['interpolated'] ?? null, 'The analyser did not flag an interpolated search value.');
    }

    /**
     * @param list<array{text: string, columns: list<string>, insensitive: bool, interpolated: ?string}> $leaves
     *
     * @return list<string>
     */
    private static function crossColumnDefects(array $leaves): array
    {
        $defects = [];
        $insensitive = [];

        foreach ($leaves as $leaf) {
            if (\count(array_unique($leaf['columns'])) >= 2) {
                $defects[] = 'one search predicate spans '.implode(', ', array_unique($leaf['columns'])).': '.trim($leaf['text']);
            }
            if ($leaf['insensitive']) {
                $insensitive = [...$insensitive, ...$leaf['columns']];
            }
        }

        $insensitive = array_values(array_unique($insensitive));
        if (\count($insensitive) >= 2) {
            $defects[] = 'a case-insensitive match is applied to '.implode(', ', $insensitive);
        }

        return $defects;
    }

    /** @return list<string> the tables a statement reads through FROM or JOIN */
    private static function tablesRead(string $sql): array
    {
        return array_values(array_unique(array_column(self::aliases(self::blank($sql)), 'table')));
    }

    /** @return list<array{alias: string, table: string}> */
    private static function aliases(string $blanked): array
    {
        $keywords = ['on', 'where', 'left', 'right', 'inner', 'outer', 'join', 'cross', 'full', 'order', 'group', 'limit', 'as', 'using'];
        preg_match_all('/\b(?:FROM|JOIN)\s+([a-z_][a-z0-9_]*)(?:\s+(?:AS\s+)?([a-z_][a-z0-9_]*))?/i', $blanked, $m, \PREG_SET_ORDER);

        $aliases = [];
        foreach ($m as $match) {
            $table = strtolower($match[1]);
            $alias = isset($match[2]) && !\in_array(strtolower($match[2]), $keywords, true) ? strtolower($match[2]) : $table;
            $aliases[] = ['alias' => $alias, 'table' => $table];
            $aliases[] = ['alias' => $table, 'table' => $table];
        }

        return $aliases;
    }

    /** Comments and quoted literals / identifiers replaced by spaces of the same length. */
    private static function blank(string $sql): string
    {
        return (string) preg_replace_callback('~/\*.*?\*/|--[^\n]*|\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"~s',
            static fn (array $m): string => str_repeat(' ', \strlen($m[0])), $sql);
    }

    /**
     * The leaf predicates of a statement's top-level `WHERE` that hold a `?` bound
     * to a value containing the probe.
     *
     * @param array<int|string, mixed>       $params
     * @param array<string, list<string>>    $columnsByTable
     *
     * @return list<array{text: string, columns: list<string>, insensitive: bool, interpolated: ?string}>
     */
    private static function searchLeaves(string $sql, array $params, string $probe, array $columnsByTable): array
    {
        $blanked = self::blank($sql);

        if (str_contains($sql, $probe) && !str_contains($blanked, $probe)) {
            return [['text' => $sql, 'columns' => [], 'insensitive' => false, 'interpolated' => $sql]];
        }

        $values = array_values($params);
        preg_match_all('/\?/', $blanked, $marks, \PREG_OFFSET_CAPTURE);
        $bound = [];
        foreach ($marks[0] as $i => [, $offset]) {
            if (\is_string($values[$i] ?? null) && str_contains($values[$i], $probe)) {
                $bound[] = $offset;
            }
        }
        if ([] === $bound) {
            return [];
        }

        [$start, $end] = self::whereClause($blanked);
        $aliases = [];
        foreach (self::aliases($blanked) as $a) {
            $aliases[$a['alias']] = $a['table'];
        }

        $leaves = [];
        foreach (self::splitLeaves($blanked, $start, $end) as [$from, $to]) {
            $holds = array_filter($bound, static fn (int $o): bool => $o >= $from && $o < $to);
            if ([] === $holds) {
                continue;
            }
            $text = substr($blanked, $from, $to - $from);

            $columns = [];
            preg_match_all('/\b([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\b/i', $text, $qualified, \PREG_SET_ORDER);
            foreach ($qualified as [, $alias, $column]) {
                $columns[] = ($aliases[strtolower($alias)] ?? strtolower($alias)).'.'.strtolower($column);
            }
            $unqualified = (string) preg_replace('/\b[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*\b/i', ' ', $text);
            preg_match_all('/\b([a-z_][a-z0-9_]*)\b(?!\s*\()/i', $unqualified, $bare);
            foreach ($bare[1] as $word) {
                foreach (array_unique(array_values($aliases)) as $table) {
                    if (\in_array(strtolower($word), $columnsByTable[$table] ?? [], true)) {
                        $columns[] = $table.'.'.strtolower($word);
                    }
                }
            }

            $leaves[] = [
                'text' => $text,
                'columns' => array_values(array_unique($columns)),
                'insensitive' => 1 === preg_match('/\bILIKE\b|~~\*|~\*|\bSIMILAR\s+TO\b|\b(?:lower|upper)\s*\(/i', $text),
                'interpolated' => null,
            ];
        }

        return $leaves;
    }

    /** @return array{int, int} the byte range of the top-level WHERE clause's body */
    private static function whereClause(string $blanked): array
    {
        $depth = 0;
        $start = null;
        $length = \strlen($blanked);

        for ($i = 0; $i < $length; ++$i) {
            $c = $blanked[$i];
            if ('(' === $c) {
                ++$depth;
            } elseif (')' === $c) {
                --$depth;
            } elseif (0 === $depth) {
                $rest = substr($blanked, $i, 12);
                if (null === $start && 1 === preg_match('/^WHERE\b/i', $rest) && (0 === $i || 1 !== preg_match('/\w/', $blanked[$i - 1]))) {
                    $start = $i + 5;
                } elseif (null !== $start && 1 === preg_match('/^(?:ORDER\s+BY|GROUP\s+BY|LIMIT|HAVING|OFFSET|UNION)\b/i', $rest) && 1 !== preg_match('/\w/', $blanked[$i - 1])) {
                    return [$start, $i];
                }
            }
        }

        return null === $start ? [0, 0] : [$start, $length];
    }

    /** @return list<array{int, int}> leaf ranges, descending into wholly parenthesised terms */
    private static function splitLeaves(string $blanked, int $start, int $end): array
    {
        $terms = [];
        $depth = 0;
        $from = $start;

        for ($i = $start; $i < $end; ++$i) {
            $c = $blanked[$i];
            if ('(' === $c) {
                ++$depth;
            } elseif (')' === $c) {
                --$depth;
            } elseif (0 === $depth && ($i === $start || 1 !== preg_match('/\w/', $blanked[$i - 1]))
                && 1 === preg_match('/^(AND|OR)\b/i', substr($blanked, $i, 4), $op)) {
                $terms[] = [$from, $i];
                $i += \strlen($op[1]) - 1;
                $from = $i + 1;
            }
        }
        $terms[] = [$from, $end];

        $leaves = [];
        foreach ($terms as [$a, $b]) {
            $text = substr($blanked, $a, $b - $a);
            $trimmedStart = $a + (\strlen($text) - \strlen(ltrim($text)));
            $trimmedEnd = $b - (\strlen($text) - \strlen(rtrim($text)));
            if ($trimmedEnd > $trimmedStart && '(' === $blanked[$trimmedStart] && ')' === $blanked[$trimmedEnd - 1] && self::closes($blanked, $trimmedStart) === $trimmedEnd - 1) {
                $leaves = [...$leaves, ...self::splitLeaves($blanked, $trimmedStart + 1, $trimmedEnd - 1)];
            } else {
                $leaves[] = [$a, $b];
            }
        }

        return $leaves;
    }

    private static function closes(string $blanked, int $open): int
    {
        $depth = 0;
        for ($i = $open, $n = \strlen($blanked); $i < $n; ++$i) {
            if ('(' === $blanked[$i]) {
                ++$depth;
            } elseif (')' === $blanked[$i] && 0 === --$depth) {
                return $i;
            }
        }

        return -1;
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        return array_column($this->owner()->fetchAllAssociative(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?', [$table]), 'column_name');
    }
}
