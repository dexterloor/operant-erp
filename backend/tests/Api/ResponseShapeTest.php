<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Support\ContractDocument;
use PHPUnit\Framework\Attributes\Test;

/**
 * API-23 and API-23a (`docs/API.md` §4.4.3) — the anti-vacuity probe §15 item 11
 * names second.
 *
 * ---------------------------------------------------------------------------
 * The defect this exists for
 * ---------------------------------------------------------------------------
 * A read resource's declared field list is **derived**: the accepted fields of
 * its `Create*` row (minus a parent `_id` the path already implies), plus `id`,
 * plus `archived_at` for a soft-deleted table, plus the resource's row in
 * §4.4.3's additions table. Revision 2 shipped `StockMovement` with a `Create*`
 * row under a different resource name, deletion class `none` and no additions
 * row, so its whole derived response was `{id}` — and **a two-directional
 * comparison of an empty expectation against an empty shape passes in both
 * directions.** This probe is the check that the expectation has content before
 * anything is compared against it.
 *
 * ---------------------------------------------------------------------------
 * Scope, and one reading taken here
 * ---------------------------------------------------------------------------
 * The domain is the **built read shapes**: the resource each `GET` operation in
 * `TransactionBoundaryTest::contractOperations()` reads, named by that
 * operation's §4.2 `Verb / Resource` cell (`List / Items` → `Item`). It widens by
 * itself as reads are built. `::everyDeclaredFieldIsDerivedOrDeclaredAsAnAddition`,
 * the comparison this probe protects, does not exist yet.
 *
 * **The assertion is slightly stronger than "not `{id}` alone"**: steps 1 and 4
 * must contribute at least one field, so `{id, archived_at}` is vacuous too. Step
 * 3 only ever adds `archived_at`, and without this a soft-deleted resource whose
 * §4.2 and §4.4.3 reads both came back empty would pass — the parser failing
 * silently is the same vacuity arriving by a different road. Step 3 is therefore
 * not computed here: it cannot rescue an expectation.
 */
final class ResponseShapeTest extends PurchaseOrderTestCase
{
    use OperationSuccessRecipes;

    /** The fields every row may get without §4.2 or §4.4.3 saying anything about it. */
    private const FREE_FIELDS = ['id', 'archived_at'];

    /**
     * **Bare references that are known and not yet ruled: operation => JSON path
     * (list indices written `[N]`) => reason.** Registered 2026-09-15.
     *
     * `lines[].purchase_order_line_id` on a goods receipt is a reference to a
     * **document line**, and API-23b's display-field chain (`name`, else `code`,
     * else `sku`, else the document number) has no answer for one:
     * `purchase_order_line` has none of the first three, and its `line_number` is
     * an integer outside the five `text` document-number columns `docs/ux/IA.md`
     * §5.6 names under R-1 — and `PurchaseOrderLineResource` records that
     * `line_number` is a column, not a field. Picking a display field here would
     * be choosing per resource, which API-23b forbids. The question goes to the
     * owner: `docs/session10/red-row11-attack.md`, "R2". Until it is ruled, this
     * path is excused and **only** this path; `storage_location_id` on the same
     * lines was the shipped defect and is expanded.
     *
     * Every entry must still be observed in a driven body, so the excuse cannot
     * outlive the bare id it excuses — when R2 is built, this entry turns the
     * test red until it is removed.
     *
     * @var array<string, array<string, non-empty-string>>
     */
    private const REGISTERED_BARE_REFERENCES = [
        'GetGoodsReceipt' => [
            '$.lines[N].purchase_order_line_id' => 'A document-line reference has no API-23b display field; owner ruling owed '
                .'(docs/session10/red-row11-attack.md R2, registered 2026-09-15).',
        ],
        'CreateGoodsReceipt' => [
            '$.lines[N].purchase_order_line_id' => 'A document-line reference has no API-23b display field; owner ruling owed '
                .'(docs/session10/red-row11-attack.md R2, registered 2026-09-15).',
        ],
    ];

    /**
     * **The probe.** No built read resource's derived expectation is empty
     * beyond `id` and `archived_at`.
     *
     * The controls are inside this method: a sibling test does not protect a
     * `--filter` run of this one.
     *
     * **Mutation (empty an expectation):** make {@see self::expectation()} ignore
     * the additions table; `CurrentUser`, which has no `Create*` row and whose
     * additions row is its whole shape, derives to `{id}`; red. **Mutation
     * (delete a branch of the derivation):** delete the `CurrentUser` row from
     * §4.4.3's table in `docs/API.md`; red, by name — this is the built-surface
     * form of the `StockMovement` mutation item 11 names, which becomes runnable
     * when Output 9 builds `StockMovement`.
     */
    #[Test]
    public function everyReadResourceHasANonEmptyExpectation(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored; the derivation reads §4.2 and §4.4.3 and has nothing to read without it.');
        }

        $rows = ContractDocument::operationRows();
        $additions = self::additionsTable();

        // Control 1 — both documents the derivation reads were actually read.
        self::assertNotSame([], $rows, '§4.2 yielded no operation rows.');
        self::assertNotSame([], $additions, '§4.4.3\'s additions table yielded no rows, so every expectation below would be Create-only.');

        // Control 2 — the detector fires on the defect's own shape: a resource
        // with no Create row and no additions row derives to {id} and is vacuous.
        self::assertSame(['id'], self::expectation('ResourceNoRowDescribes', $rows, $additions));
        self::assertTrue(self::isVacuous(['id']), 'An expectation of {id} alone was not recognised as vacuous, so the assertion below cannot go red.');

        $known = self::knownResources($rows, $additions);
        $resources = [];

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            if ('GET' !== $operation->getMethod()) {
                continue;
            }
            self::assertArrayHasKey($name, $rows, \sprintf('%s is a built read with no §4.2 row, so its resource cannot be named.', $name));
            $resources[self::resourceOf($rows[$name]['verbResource'], $known)][] = $name;
        }

        // Control 3 — the domain is not empty.
        self::assertNotSame([], $resources, 'The registry produced no read operation, so the probe examined nothing.');

        $vacuous = [];
        foreach ($resources as $resource => $operations) {
            $expectation = self::expectation($resource, $rows, $additions);
            if (self::isVacuous($expectation)) {
                $vacuous[] = \sprintf('%s (read by %s) derives to {%s}', $resource, implode(', ', $operations), implode(', ', $expectation));
            }
        }

        self::assertSame([], $vacuous,
            'A built read resource has a derived expectation with nothing beyond id and archived_at. API-23a requires an '
            .'additions row for a resource with no Create* row, or with a Create* row under a different Resource name.');
    }

    /**
     * **API-23b — no response field ending `_id` is returned unexpanded (other
     * than `subject_id` and `source_id`), and an expansion carries the id and the
     * display field only.**
     *
     * Subject: the serialized body of every operation in the registry, each driven
     * to success by {@see OperationSuccessRecipes}, walked at every depth. An
     * **expansion** is an object-valued member (or an object in a list member)
     * carrying `id`, other than the envelope's `items` and a nested collection §4.2
     * declares as `name[].field` (`lines`) — those are the resource's own rows, not
     * references. The display field is derived, not chosen: the referenced row is
     * found by its id in the schema's tables, and {@see self::displayMembers()}
     * applies the chain to that table's columns — `name` where it has one, else
     * `code`, else `sku`, else its document number, else `display_name`.
     * **Reading taken:** `app_user` has no `name` column and its name column is
     * `display_name`, which is what §4.4.3 writes for `created_by`.
     *
     * **Document numbers are derived from the schema, not from a `*_number`
     * suffix:** {@see self::documentNumberColumns()} reads the live-unique
     * `*_number_live` / `*_number_unique` indexes, which are exactly `IA.md` R-1's
     * five, so `line_number` or a future `tax_number` earns nothing from the
     * suffix. **Clause (ii) is owner ruling Q-A (2026-09-15, "Yes, everywhere"):**
     * every reference to the one table the clause names — read from §4.4.3's text,
     * never written here — also carries `sku`, unless `sku` is already its display
     * field. It is keyed on that table's name, so another table with a `sku`
     * column gains nothing.
     *
     * One bare reference is excused by {@see self::REGISTERED_BARE_REFERENCES}, by
     * operation and exact path; an entry that is no longer observed is red.
     *
     * Controls inside this method: recipes equal the registry and each answered its
     * success status; at least one expansion was examined and resolved to a table;
     * the walker flags a planted bare `supplier_id` and a planted three-member
     * expansion; the registry excuses its own path and not the same path under a
     * different key or operation; clause (ii)'s table is read from the document
     * exactly once and has a `sku` column; the document-number indexes are
     * exactly five; and planted calls of the pure rule pin each branch.
     *
     * **What this does not check:** the display **values** and the key names (the
     * expansion's key is the `_id` field's name less `_id`). Both are pinned per
     * operation where the expansion is built (`GoodsReceiptCreateTest` for
     * `storage_location`, `PurchaseOrderReadTest` for a line's `inventory_item`).
     *
     * Mutations: `docs/session9/progress-C5B.md` row 11; `docs/session10/progress-R1.md`.
     */
    #[Test]
    public function everyReferenceIsExpanded(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored; the nested collections §4.2 declares are read from it.');
        }

        $nested = self::nestedCollections();
        self::assertNotSame([], $nested, '§4.2 declares no nested collection, so the sub-row carve-out was read from nothing.');

        $planted = [];
        self::walkReferences(['supplier_id' => 'x', 'supplier' => ['id' => 'x', 'name' => 'n', 'code' => 'c']], '$', $nested, $planted);
        self::assertCount(1, array_filter($planted, static fn (array $p): bool => 'bare' === $p[0]), 'The walker missed a planted bare supplier_id.');
        self::assertCount(1, array_filter($planted, static fn (array $p): bool => 'expansion' === $p[0]), 'The walker missed a planted expansion.');

        // Clause (ii)'s table, read from the ruling's own text rather than written
        // here. This match is also clause (ii)'s rule-text assertion.
        $section = (string) preg_replace('/\s+/', ' ', ContractDocument::sectionBetween('#### 4.4.3 ', '#### 4.4.4 '));
        self::assertSame(1, preg_match_all('/\*\*\(ii\) Every reference to `([a-z_]+)` also carries `sku`/', $section, $clause),
            '§4.4.3 does not state API-23b clause (ii) exactly once, so the table that carries sku cannot be read.');
        $skuTable = $clause[1][0];
        self::assertArrayHasKey($skuTable, SchemaManifest::TABLES, \sprintf('Clause (ii) names `%s`, which is not a table.', $skuTable));
        self::assertContains('sku', $this->columnsOf($skuTable), \sprintf('Clause (ii) names `%s`, which has no `sku` column.', $skuTable));

        // Control — the document numbers are IA.md R-1's five.
        $documentNumbers = $this->documentNumberColumns();
        self::assertSame(5, \count($documentNumbers),
            'The live-unique *_number indexes are not IA.md R-1\'s five document numbers: '.json_encode($documentNumbers));

        // Planted controls — each branch of the pure rule, with clause (ii)'s
        // table passed explicitly.
        $planted = [
            'no bare line_number display' => [['x', ['id', 'line_number'], false, null], null],
            'clause (i)' => [['x', ['id', 'line_number'], true, null], ['id', 'line_number']],
            'clause (ii) on the named table' => [['inventory_item', ['id', 'name', 'sku'], false, null], ['id', 'name', 'sku']],
            'a different table with a sku column does not gain sku' => [['supplier_product', ['id', 'name', 'sku'], false, null], ['id', 'name']],
            'the ruled table without sku is loud' => [['inventory_item', ['id', 'name'], false, null], null],
            'no code member' => [['warehouse', ['id', 'name', 'code'], false, null], ['id', 'name']],
            'clause (i) takes precedence over (ii)' => [['inventory_item', ['id', 'name', 'sku', 'line_number'], true, null], ['id', 'line_number']],
            'unless sku is already the display field' => [['inventory_item', ['id', 'sku'], false, null], ['id', 'sku']],
            'no *_number spread' => [['x', ['id', 'tax_number'], false, null], null],
            'document number' => [['purchase_order', ['id', 'order_number'], false, 'order_number'], ['id', 'order_number']],
        ];
        foreach ($planted as $label => [[$table, $columns, $subListed, $number], $expected]) {
            self::assertSame($expected, self::displayMembers($table, $columns, $subListed, $number, 'inventory_item'),
                'The display rule is wrong on a planted case: '.$label.'.');
        }

        // Control — the registry excuses exactly its own operation and path.
        foreach (self::REGISTERED_BARE_REFERENCES as $operation => $paths) {
            foreach (array_keys($paths) as $path) {
                $concrete = str_replace('[N]', '[3]', $path);
                self::assertSame($path, self::registeredBareReference($operation, $concrete), 'The registry does not excuse its own entry at '.$concrete.'.');
                self::assertNull(self::registeredBareReference($operation, $concrete.'_x_id'), 'The registry excuses a path it does not name.');
                self::assertNull(self::registeredBareReference('NoSuch'.$operation, $concrete), 'The registry excuses an operation it does not name.');
            }
        }

        $defects = [];
        $observed = [];
        $examined = 0;

        foreach ($this->drivenBodies() as $name => $body) {
            $found = [];
            self::walkReferences($body, '$', $nested, $found);

            foreach ($found as [$kind, $where, $value]) {
                if ('bare' === $kind) {
                    if (null !== ($path = self::registeredBareReference($name, $where))) {
                        $observed[$name][$path] = true;
                        continue;
                    }
                    $defects[] = \sprintf('%s returns %s unexpanded', $name, $where);
                    continue;
                }

                ++$examined;
                $want = $this->expectedMembers((string) $value['id'], $skuTable, $documentNumbers);
                $members = array_keys($value);
                sort($members);
                if (null === $want || $want !== $members) {
                    $defects[] = \sprintf('%s: the expansion at %s carries [%s], not [%s]', $name, $where, implode(', ', $members),
                        null === $want ? 'id and a display field — no table holding that id has one' : implode(', ', $want));
                }
            }
        }

        self::assertGreaterThan(0, $examined, 'No expansion was examined, so the display-field half had no subject.');
        self::assertSame([], $defects, 'API-23b: a reference is returned unexpanded, or an expansion carries more than its id and display field.');

        $stale = [];
        foreach (self::REGISTERED_BARE_REFERENCES as $operation => $paths) {
            foreach ($paths as $path => $reason) {
                if (!isset($observed[$operation][$path])) {
                    $stale[] = $operation.' at '.$path.' ('.$reason.')';
                }
            }
        }
        self::assertSame([], $stale,
            'A registered bare reference is no longer returned. Remove it from REGISTERED_BARE_REFERENCES, so that it cannot '
            .'excuse a different bare id later.');
    }

    /** The registered path a bare reference matches for this operation, or null when none excuses it. */
    private static function registeredBareReference(string $operation, string $where): ?string
    {
        $path = (string) preg_replace('/\[\d+\]/', '[N]', $where);

        return isset(self::REGISTERED_BARE_REFERENCES[$operation][$path]) ? $path : null;
    }

    /**
     * **P-6 / API-6 — no response carries `tenant_id` or `tenantId`, at any
     * depth, including inside a cursor.**
     *
     * Subject: the serialized body of every operation in the registry, each driven
     * to success by {@see OperationSuccessRecipes}, walked recursively. A string
     * member named `next_cursor` or `previous_cursor` is base64url-decoded and, if
     * it is JSON, walked too — §4.4.3 names the cursor as a place one would hide.
     *
     * Controls inside this method: recipes equal the registry and each answered its
     * success status; the walker finds a planted `tenantId` at depth three and a
     * planted `tenant_id` inside an encoded cursor; at least one body and at least
     * one cursor were walked.
     *
     * Mutations: `docs/session9/progress-C5B.md` row 12.
     */
    #[Test]
    public function noResponseCarriesATenantIdentifier(): void
    {
        $plantedCursor = rtrim(strtr(base64_encode((string) json_encode(['k' => 'a', 'tenant_id' => 'x'])), '+/', '-_'), '=');
        $planted = [];
        $cursors = 0;
        self::walkTenantKeys(['a' => [['b' => ['tenantId' => 1]]], 'next_cursor' => $plantedCursor], '$', $planted, $cursors);
        self::assertSame(['$.a[0].b.tenantId', '$.next_cursor<decoded>.tenant_id'], $planted, 'The walker missed a planted tenant identifier.');

        $found = [];
        $cursors = 0;
        $walked = 0;
        foreach ($this->drivenBodies() as $name => $body) {
            if (null === $body) {
                continue;
            }
            ++$walked;
            $keys = [];
            self::walkTenantKeys($body, '$', $keys, $cursors);
            foreach ($keys as $where) {
                $found[] = $name.' at '.$where;
            }
        }

        self::assertGreaterThan(0, $walked, 'No response body was walked.');
        self::assertGreaterThan(0, $cursors, 'No cursor was decoded, so the inside-a-cursor half had no subject.');
        self::assertSame([], $found, 'P-6: a response carries a tenant identifier.');
    }

    /**
     * Every registry operation driven to success, and its decoded JSON body
     * (`null` for an empty body). Controls: the recipes equal the registry, and
     * each answered the status its recipe drives for — the subject here is the
     * body, so a refusal's body would be the wrong subject.
     *
     * @return array<string, array<mixed>|null>
     */
    private function drivenBodies(): array
    {
        $registry = TransactionBoundaryTest::contractOperations();
        $recipes = $this->successRecipes();
        $names = array_keys($registry);
        sort($names);
        $recipeNames = array_keys($recipes);
        sort($recipeNames);
        self::assertNotSame([], $names, 'The registry is empty.');
        self::assertSame($names, $recipeNames, 'Every operation in the registry must be driven here, and only those.');

        $prepared = [];
        foreach ($recipes as $name => [$prepare]) {
            $prepared[$name] = $prepare();
        }

        // Lists read what the other fixtures seeded, so a list page is not empty.
        $bodies = [];
        foreach ($recipes as $name => [, $drive]) {
            [$status, $label] = $drive($prepared[$name]);
            $content = (string) $this->client()->getResponse()->getContent();
            self::assertSame($status, $this->httpStatus(), \sprintf('%s (%s) was not driven to success: %s', $name, $label, substr($content, 0, 300)));
            $bodies[$name] = '' === $content ? null : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

            // A list that pages is read once more at one row a page, so a body
            // carrying a real cursor is walked too.
            if ('GET' === $registry[$name]->getMethod() && (bool) $registry[$name]->getParameters()?->has('limit')) {
                $uri = (string) parse_url($this->client()->getRequest()->getUri(), \PHP_URL_PATH);
                $this->send('GET', $uri.'?limit=1');
                self::assertSame(200, $this->httpStatus(), $name.' at limit=1 was not driven to success.');
                $bodies[$name.' (limit=1)'] = json_decode((string) $this->client()->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            }
        }

        return $bodies;
    }

    /**
     * @param list<array{0: string, 1: string, 2: mixed}> $found
     * @param list<string>                               $nested
     */
    private static function walkReferences(mixed $value, string $where, array $nested, array &$found, bool $isRow = true): void
    {
        if (!\is_array($value)) {
            return;
        }

        if (array_is_list($value)) {
            foreach ($value as $index => $element) {
                self::walkReferences($element, $where.'['.$index.']', $nested, $found, $isRow);
            }

            return;
        }

        if (!$isRow && \array_key_exists('id', $value)) {
            $found[] = ['expansion', $where, $value];
        }

        foreach ($value as $key => $member) {
            $key = (string) $key;
            if (str_ends_with($key, '_id') && !\in_array($key, ['subject_id', 'source_id'], true)) {
                $found[] = ['bare', $where.'.'.$key, $member];
            }
            // An envelope's items and a declared nested collection hold rows; any
            // other object-valued member is a reference.
            self::walkReferences($member, $where.'.'.$key, $nested, $found, 'items' === $key || \in_array($key, $nested, true));
        }
    }

    /**
     * @param list<string> $found
     */
    private static function walkTenantKeys(mixed $value, string $where, array &$found, int &$cursors): void
    {
        if (!\is_array($value)) {
            return;
        }

        foreach ($value as $key => $member) {
            $path = \is_int($key) ? $where.'['.$key.']' : $where.'.'.$key;
            if (\is_string($key) && \in_array(strtolower(str_replace('_', '', $key)), ['tenantid'], true)) {
                $found[] = $path;
            }
            if (\in_array($key, ['next_cursor', 'previous_cursor'], true) && \is_string($member)) {
                ++$cursors;
                $decoded = json_decode((string) base64_decode(strtr($member, '-_', '+/'), true), true);
                self::walkTenantKeys(\is_array($decoded) ? $decoded : [], $path.'<decoded>', $found, $cursors);
            }
            self::walkTenantKeys($member, $path, $found, $cursors);
        }
    }

    /**
     * The sorted member list API-23b expects for an expansion of the row holding
     * this id, or null when no table holds it or the rule has no answer.
     *
     * @param array<string, string> $documentNumbers table => document-number column
     *
     * @return list<string>|null
     */
    private function expectedMembers(string $id, string $skuTable, array $documentNumbers): ?array
    {
        foreach (SchemaManifest::tenantScopedTables() as $table) {
            $columns = $this->columnsOf($table);
            if (!\in_array('id', $columns, true)) {
                continue;
            }
            if ([] === $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT 1 FROM '.$table.' WHERE id::text = ?', [$id])) {
                continue;
            }

            return self::displayMembers($table, $columns, false, $documentNumbers[$table] ?? null, $skuTable);
        }

        return null;
    }

    /**
     * **API-23b as a pure rule**, so each branch can be planted and shown to fail.
     *
     *   1. Clause (i) first: a row of a sub-listed line table displays
     *      `line_number`, and nothing else — no `sku` (clause (i) takes precedence).
     *   2. Otherwise the display is the first of `name`, `code`, `sku` present;
     *      else the table's document number (derived from its index, never from a
     *      `*_number` suffix); else `display_name`; else there is none.
     *   3. Clause (ii), by table **name** (LEAD ruling D2): the one table the
     *      clause names must have `sku` — null otherwise, so a ruled table that
     *      lost its column is loud — and gains it unless it is already the display.
     *      No other table gains `sku`, whatever its columns.
     *
     * @param list<string> $columns
     *
     * @return list<string>|null
     */
    private static function displayMembers(string $table, array $columns, bool $subListedLine, ?string $documentNumberColumn, string $skuTable): ?array
    {
        if ($subListedLine) {
            return \in_array('line_number', $columns, true) ? ['id', 'line_number'] : null;
        }

        $display = null;
        foreach (['name', 'code', 'sku'] as $candidate) {
            if (\in_array($candidate, $columns, true)) {
                $display = $candidate;
                break;
            }
        }
        if (null === $display && null !== $documentNumberColumn) {
            $display = $documentNumberColumn;
        }
        if (null === $display && \in_array('display_name', $columns, true)) {
            $display = 'display_name';
        }
        if (null === $display) {
            return null;
        }

        $members = ['id', $display];
        if ($table === $skuTable) {
            if (!\in_array('sku', $columns, true)) {
                return null;
            }
            if ('sku' !== $display) {
                $members[] = 'sku';
            }
        }
        sort($members);

        return $members;
    }

    /**
     * Each table's document-number column, from the live-unique indexes the
     * schema actually has (`*_number_live`, `*_number_unique`): the single
     * non-`tenant_id` column each one is built on.
     *
     * @return array<string, string>
     */
    private function documentNumberColumns(): array
    {
        $numbers = [];
        foreach ($this->owner()->fetchAllAssociative(
            "SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname ~ '_number_(live|unique)$'",
        ) as $index) {
            self::assertSame(1, preg_match('/\(([^()]*)\)/', (string) $index['indexdef'], $list), 'Unreadable index definition: '.$index['indexdef']);
            $columns = array_values(array_diff(array_map(trim(...), explode(',', $list[1])), ['tenant_id']));
            self::assertCount(1, $columns, \sprintf('%s is not built on exactly one column besides tenant_id.', $index['indexname']));
            $numbers[(string) $index['tablename']] = $columns[0];
        }

        return $numbers;
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        return array_column($this->owner()->fetchAllAssociative(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?', [$table]), 'column_name');
    }

    /**
     * Every nested collection §4.2's accepted cells declare (`lines[].quantity` → `lines`).
     *
     * @return list<string>
     */
    private static function nestedCollections(): array
    {
        $nested = [];
        foreach (ContractDocument::operationRows() as $row) {
            preg_match_all('/`([a-z_]+)\[\]\./', $row['accepts'], $m);
            $nested = [...$nested, ...$m[1]];
        }

        return array_values(array_unique($nested));
    }

    /**
     * API-23 steps 1, 2 and 4 for one resource. Step 3 is left out on purpose —
     * see the class docblock.
     *
     * @param array<string, array{operationId: string, verbResource: string, method: string, path: string}> $rows
     * @param array<string, list<string>>                                                                    $additions
     *
     * @return list<string>
     */
    private static function expectation(string $resource, array $rows, array $additions): array
    {
        $fields = [];

        foreach ($rows as $operationId => $row) {
            if ('Create / '.$resource !== $row['verbResource']) {
                continue;
            }
            $parents = self::parentsImpliedBy($row['path']);
            foreach (ContractDocument::acceptedFields($operationId) as $field) {
                if (!(str_ends_with($field, '_id') && \in_array(substr($field, 0, -3), $parents, true))) {
                    $fields[] = $field;
                }
            }
        }

        return array_values(array_unique(['id', ...$fields, ...($additions[$resource] ?? [])]));
    }

    /** @param list<string> $expectation */
    private static function isVacuous(array $expectation): bool
    {
        return [] === array_diff($expectation, self::FREE_FIELDS);
    }

    /**
     * §4.4.3's additions table, as resource → field names.
     *
     * The cell is prose. The fields are the backticked names **before the first
     * ` — ` or sentence break**, with parenthesised asides removed first — every
     * row opens with its field list and then explains it. A row naming two
     * resources (`**GoodsReceipt** / **Fulfilment**`) gives both the same list.
     * Over-reading a cell can only make this probe weaker, never red, so the
     * rule errs toward reading less.
     *
     * @return array<string, list<string>>
     */
    private static function additionsTable(): array
    {
        $table = [];

        foreach (explode("\n", ContractDocument::sectionBetween('#### 4.4.3 ', '#### 4.4.4 ')) as $line) {
            if (!str_starts_with($line, '| **')) {
                continue;
            }
            $cells = explode('|', $line);
            if (\count($cells) < 4) {
                continue;
            }

            preg_match_all('/\*\*([A-Z][A-Za-z]+)\*\*/', $cells[1], $names);

            $prose = $cells[2];
            do {
                $prose = (string) preg_replace('/\([^()]*\)/', '', $before = $prose);
            } while ($prose !== $before);
            $prose = preg_split('/ — |\. /', $prose, 2)[0];

            preg_match_all('/`([a-z][a-z_]*)/', $prose, $tokens);
            $fields = array_values(array_unique(array_diff($tokens[1], ['null'])));

            foreach ($names[1] as $name) {
                $table[$name] = $fields;
            }
        }

        return $table;
    }

    /**
     * Every singular resource name §4.2's `Get` and `Create` rows or §4.4.3's
     * additions table use — the set a `List` row's plural is resolved against.
     *
     * @param array<string, array{verbResource: string}> $rows
     * @param array<string, list<string>>                $additions
     *
     * @return list<string>
     */
    public static function knownResources(array $rows, array $additions): array
    {
        $known = array_keys($additions);

        foreach ($rows as $row) {
            if (1 === preg_match('/^(?:Get|Create) \/ ([A-Za-z]+)$/', $row['verbResource'], $match)) {
                $known[] = $match[1];
            }
        }

        return array_values(array_unique($known));
    }

    /**
     * The resource a read's `Verb / Resource` cell names. A `List` cell is
     * plural, and the singular is whichever of `-s`, `-es`, `-ies → -y` another
     * row already uses; anything else is refused rather than guessed.
     *
     * @param list<string> $known
     */
    public static function resourceOf(string $verbResource, array $known): string
    {
        self::assertMatchesRegularExpression('/^(Get|List) \/ [A-Za-z]+$/', $verbResource, 'A built GET operation\'s Verb / Resource cell is neither Get nor List.');
        [$verb, $name] = explode(' / ', $verbResource);

        if ('Get' === $verb) {
            return $name;
        }

        $candidates = array_intersect([
            (string) preg_replace('/s$/', '', $name),
            (string) preg_replace('/es$/', '', $name),
            (string) preg_replace('/ies$/', 'y', $name),
        ], $known);
        $candidates = array_values(array_unique($candidates));

        self::assertCount(1, $candidates, \sprintf('"%s" does not resolve to exactly one singular resource another row names.', $verbResource));

        return $candidates[0];
    }

    /**
     * The parents a `Create*` path implies — `/purchase-orders/{id}/lines` implies
     * `purchase_order` — so step 1 can drop `purchase_order_id`.
     *
     * @return list<string>
     */
    public static function parentsImpliedBy(string $path): array
    {
        preg_match_all('#/([a-z-]+)/\{[^}]+\}#', $path, $matches);

        return array_map(
            static fn (string $segment): string => str_replace('-', '_', (string) preg_replace('/s$/', '', $segment)),
            $matches[1],
        );
    }
}
