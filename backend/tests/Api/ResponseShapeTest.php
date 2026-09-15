<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Support\ContractDocument;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\SerializedName;

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
 * itself as reads are built. `::everyDeclaredFieldIsDerivedOrDeclaredAsAnAddition`
 * is the comparison this probe protects, and both read one derivation,
 * {@see self::derivation()}, and one additions parser, {@see self::declaredAdditions()}.
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
     * **The probe.** No built read resource's derived expectation is empty
     * beyond `id` and `archived_at`.
     *
     * The controls are inside this method: a sibling test does not protect a
     * `--filter` run of this one.
     *
     * It reads the same {@see self::derivation()} the comparison below reads, with
     * step 3 excluded (no soft tables passed), so there is one derivation.
     *
     * **Mutation (empty an expectation):** make {@see self::derivation()} ignore
     * the additions; `CurrentUser`, which has no `Create*` row and whose
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
        $additions = self::declaredAdditions();

        // Control 1 — both documents the derivation reads were actually read.
        self::assertNotSame([], $rows, '§4.2 yielded no operation rows.');
        self::assertNotSame([], $additions, '§4.4.3\'s additions table yielded no rows, so every expectation below would be Create-only.');

        // Control 2 — the detector fires on the defect's own shape: a resource
        // with no Create row and no additions row derives to {id} and is vacuous.
        self::assertSame(['id'], array_keys(self::derivation('ResourceNoRowDescribes', $rows, $additions, [])));
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
            $expectation = array_keys(self::derivation($resource, $rows, $additions, []));
            if (self::isVacuous($expectation)) {
                $vacuous[] = \sprintf('%s (read by %s) derives to {%s}', $resource, implode(', ', $operations), implode(', ', $expectation));
            }
        }

        self::assertSame([], $vacuous,
            'A built read resource has a derived expectation with nothing beyond id and archived_at. API-23a requires an '
            .'additions row for a resource with no Create* row, or with a Create* row under a different Resource name.');
    }

    /**
     * **API-23 in both directions — every field a built read DTO serializes is
     * derivable by steps 1–3 or listed in §4.4.3, and every field the derivation
     * yields is on the DTO.** Also API-23a: a resource with no `Create*` row under
     * its own name has an additions row. **And one level down:** every entry of an
     * embedded nested list carries exactly what step 1 derives for it plus the
     * additions sentence's members.
     *
     * Domain: the resources the built `GET`s read, as the probe above names them.
     * The DTO is the output class of every registry operation whose §4.2 cell
     * names the resource with a non-`List` verb (they must agree on one class);
     * its fields are its public properties under their `#[SerializedName]`.
     *
     * **The derivation, as applied here, with the readings it takes:**
     * 1. the `Create / <Resource>` row's accepted fields, minus a parent `_id`
     *    the path implies; a remaining `x_id` is expected as `x`, because
     *    API-23b returns it expanded; a `lines[].*` token is `lines`, **and step
     *    1's sub-list exception** (owner ruling Q1(a), 2026-09-15) drops a nested
     *    `n[]` that {@see self::subListedChild()} names — a bounded `List` at the
     *    `Get` path + `/n`, a `Create` at that path, and every `n[].f` accepted
     *    there. A nested field that is kept is an **embedded** list;
     * 2. `id`;
     * 3. `archived_at` iff the resource's table is `soft`. The resource → table
     *    tie is §4.2's `Restore / <Resource>` row (`guard:<table>.deleted_at`),
     *    held equal to `SchemaManifest::softDeletedTables()` in both directions
     *    first, so it is a derivation and not a guess;
     * 4. §4.4.3's additions, read by {@see self::declaredAdditions()}.
     *
     * An embedded entry is expected to carry `{id}` ∪ its `n[].f` sub-fields
     * (a trailing `_id` stripped: API-23b expands it) ∪ the nested additions
     * (`**R also carries `n[].member`**`). Step 3 is not applied to an entry, which
     * has no resource of its own. Every entry of `$.n` in every driven body of a
     * non-`List` operation on the resource is compared.
     *
     * Mutations: `docs/session10/progress-Q1Q2.md` (C4); the first run of row 10
     * is `docs/session9/progress-C5B.md`.
     */
    #[Test]
    public function everyDeclaredFieldIsDerivedOrDeclaredAsAnAddition(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored; the derivation reads §4.2 and §4.4.3 and has nothing to read without it.');
        }

        $rows = ContractDocument::operationRows();
        $additions = self::declaredAdditions($nestedAdditions);
        $softResources = ContractDocument::restoreGuardTables();
        $operations = TransactionBoundaryTest::contractOperations();

        // Step 1's sub-list exception, as the rule text states it.
        $section = (string) preg_replace('/\s+/', ' ', ContractDocument::sectionBetween('#### 4.4.3 ', '#### 4.4.4 '));
        self::assertStringContainsString('**and except** a nested', $section, '§4.4.3 step 1 no longer states the sub-list exception.');
        self::assertStringContainsString('not an implied part of the parent', $section, '§4.4.3 step 1 no longer grounds the sub-list exception.');

        // Control 1 — the resource → table tie covers the soft set exactly.
        $tied = array_values($softResources);
        sort($tied);
        $manifest = SchemaManifest::softDeletedTables();
        sort($manifest);
        self::assertNotSame([], $manifest, 'SchemaManifest declares no soft table.');
        self::assertSame($manifest, $tied, '§4.2\'s Restore guards and SchemaManifest\'s soft tables disagree, so step 3 cannot be derived.');
        self::assertNotSame([], $additions, '§4.4.3\'s additions table yielded no rows.');

        // Control 2 — the comparison reports both directions.
        self::assertSame(['carries `extra`', 'lacks `missing`'],
            self::compare(['id' => 'step 2', 'missing' => 'probe'], ['id', 'extra']),
            'The comparison does not report both directions, so the assertion below cannot go red on one of them.');

        $known = self::knownResources($rows, $additions);
        $resources = [];
        foreach ($operations as $name => $operation) {
            if ('GET' === $operation->getMethod()) {
                self::assertArrayHasKey($name, $rows, $name.' is a built read with no §4.2 row.');
                $resources[self::resourceOf($rows[$name]['verbResource'], $known)][] = $name;
            }
        }

        // Control 3 — the domain is not empty.
        self::assertNotSame([], $resources, 'The registry produced no read operation, so nothing was compared.');

        $defects = [];

        // Orphans — a nested addition names a list the derivation keeps embedded.
        foreach ($nestedAdditions as $resource => $lists) {
            $embedded = [];
            self::derivation($resource, $rows, $additions, $softResources, embedded: $embedded);
            foreach (array_keys($lists) as $nested) {
                if (!\array_key_exists($nested, $embedded)) {
                    $defects[] = \sprintf('%s\'s additions name %s[].%s, but %s is not an embedded field of %s', $resource, $nested,
                        implode(', '.$nested.'[].', $lists[$nested]), $nested, $resource);
                }
            }
        }

        $bodies = null;
        $entriesExamined = 0;
        foreach ($resources as $resource => $readers) {
            $class = self::dtoClassOf($resource, $rows, $operations);
            $embedded = [];
            $expected = self::derivation($resource, $rows, $additions, $softResources, $defects, $embedded);

            $hasCreate = [] !== array_filter($rows, static fn (array $row): bool => 'Create / '.$resource === $row['verbResource']);
            if (!$hasCreate && !\array_key_exists($resource, $additions)) {
                $defects[] = $resource.' has no Create row under its own name and no additions row (API-23a)';
            }

            foreach (self::compare($expected, self::serializedFields($class)) as $difference) {
                $field = (string) preg_replace('/^.*`([^`]+)`$/', '$1', $difference);
                $defects[] = \sprintf('%s (%s, read by %s) %s%s', $resource, $class, implode(', ', $readers), $difference,
                    str_starts_with($difference, 'lacks') ? ', which '.$expected[$field].' declares' : ', which is neither derivable by steps 1–3 nor listed in §4.4.3');
            }

            // The nested half: every entry of each embedded list, in every driven
            // body of a non-List operation on this resource.
            foreach ($embedded as $nested => $createOperation) {
                $entry = ['id' => 'step 2'];
                foreach (ContractDocument::nestedAcceptedFields($createOperation, $nested) as $sub) {
                    $entry[str_ends_with($sub, '_id') ? substr($sub, 0, -3) : $sub] ??= 'step 1';
                }
                foreach ($nestedAdditions[$resource][$nested] ?? [] as $member) {
                    $entry[$member] ??= 'the additions sentence';
                }

                $bodies ??= $this->drivenBodies();
                $observed = 0;
                foreach ($operations as $name => $operation) {
                    if (1 !== preg_match('/^(?!List )[A-Za-z]+ \/ '.$resource.'$/', $rows[$name]['verbResource'] ?? '')) {
                        continue;
                    }
                    foreach ((array) (($bodies[$name] ?? [])[$nested] ?? []) as $item) {
                        ++$observed;
                        foreach (self::compare($entry, array_keys((array) $item)) as $difference) {
                            $member = (string) preg_replace('/^.*`([^`]+)`$/', '$1', $difference);
                            $defects[] = \sprintf('%s %s[] entry (%s) %s%s', $resource, $nested, $name, $difference,
                                str_starts_with($difference, 'lacks')
                                    ? ', which '.$entry[$member].' declares'
                                    : ', which step 1 does not derive and no additions sentence adds');
                        }
                    }
                }
                $entriesExamined += $observed;
                if (0 === $observed) {
                    $defects[] = \sprintf('%s %s[]: no entry was observed in any driven body, so the nested half compared nothing', $resource, $nested);
                }
            }
        }

        self::assertSame([], $defects, 'API-23: a built read DTO and its derived field list disagree.');
        self::assertGreaterThan(0, $entriesExamined, 'No embedded list entry was compared, so the nested half had no subject.');
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
     * **Clause (i) is owner ruling Q1(b) (2026-09-15):** a reference to a row of a
     * sub-listed line table displays `{id, line_number}` and nothing else, and it
     * takes precedence over clause (ii). The set is
     * {@see self::subListedLineTables()}'s, derived from §4.2 and the schema. No
     * bare reference is excused: until that ruling a goods receipt's
     * `lines[].purchase_order_line_id` was, by a registry this test no longer has.
     *
     * Controls inside this method: recipes equal the registry and each answered its
     * success status; at least one expansion was examined and resolved to a table;
     * the walker flags a planted bare `supplier_id` and a planted three-member
     * expansion; clause (i)'s set contains `purchase_order_line`, and its
     * guardless mapping throws on a planted missing table and a planted
     * `line_number` column; clause (i)'s rule text is present; clause (ii)'s table is read from the document
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

        // Clause (i)'s rule text, and its set.
        self::assertStringContainsString('**(i) A reference to a row of a sub-listed line table displays its `line_number`.**', $section,
            '§4.4.3 no longer states API-23b clause (i).');
        self::assertStringContainsString('**Clause (i) takes precedence:**', $section, '§4.4.3 no longer says clause (i) takes precedence.');

        try {
            self::tableOfGuardlessCandidate('NoSuchThing', SchemaManifest::TABLES, static fn (string $table): array => []);
            self::fail('tableOfGuardlessCandidate() did not fail on a candidate with no table.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('NoSuchThing', $e->getMessage());
        }
        try {
            self::tableOfGuardlessCandidate('CustomerContact', SchemaManifest::TABLES, static fn (string $table): array => ['id', 'line_number']);
            self::fail('tableOfGuardlessCandidate() did not fail on a guardless candidate with a line_number column.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('CustomerContact', $e->getMessage());
        }
        self::assertSame('supplier_product', self::tableOfGuardlessCandidate('SupplierProduct', SchemaManifest::TABLES, static fn (string $table): array => ['id', 'sku']));

        $subListedLineTables = $this->subListedLineTables();
        self::assertNotSame([], $subListedLineTables, 'Clause (i) derived no sub-listed line table, so it was read from nothing.');
        self::assertContains('purchase_order_line', $subListedLineTables, 'Clause (i)\'s set does not contain purchase_order_line.');

        $defects = [];
        $examined = 0;

        foreach ($this->drivenBodies() as $name => $body) {
            $found = [];
            self::walkReferences($body, '$', $nested, $found);

            foreach ($found as [$kind, $where, $value]) {
                if ('bare' === $kind) {
                    $defects[] = \sprintf('%s returns %s unexpanded', $name, $where);
                    continue;
                }

                ++$examined;
                $want = $this->expectedMembers((string) $value['id'], $skuTable, $documentNumbers, $subListedLineTables);
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
     *
     * **Residual non-coverage (V1-R-9, recorded 2026-09-16, V4 B4e; from the V1
     * RED review, not re-measured here):** the walk matches **key names** only. A
     * tenant uuid carried under any other key (`workspace_id`, `owner.tenant`), or
     * as a value with no telling key, is not seen; nor is the `tenant_id` claim
     * inside `IssueToken`'s access token, which D-5 puts there on purpose.
     * Owner: BE-LEAD. Trigger to revisit: the first expansion or field that carries
     * a tenant-scoped id under another key — then also walk values for the
     * fixture tenants' uuids, with the token allow-listed.
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
     * @param array<string, string> $documentNumbers     table => document-number column
     * @param list<string>          $subListedLineTables clause (i)'s set
     *
     * @return list<string>|null
     */
    private function expectedMembers(string $id, string $skuTable, array $documentNumbers, array $subListedLineTables): ?array
    {
        foreach (SchemaManifest::tenantScopedTables() as $table) {
            $columns = $this->columnsOf($table);
            if (!\in_array('id', $columns, true)) {
                continue;
            }
            if ([] === $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT 1 FROM '.$table.' WHERE id::text = ?', [$id])) {
                continue;
            }

            return self::displayMembers($table, $columns, \in_array($table, $subListedLineTables, true), $documentNumbers[$table] ?? null, $skuTable);
        }

        return null;
    }

    /**
     * **API-23b clause (i)'s set, derived — never listed.** A sub-listed line
     * table is the table of a resource `S` that §4.2 gives both a `List` row
     * marked *(bounded)* at a path `P` and a `Create / S` row at the same `P`,
     * **and** that has a `line_number` column. It is defined without the parent's
     * nested create (staged E9 (i)).
     *
     * The table is the one `S`'s `Restore` row names by its `guard:`; a resource
     * with no `Restore` row is mapped by {@see self::tableOfGuardlessCandidate()},
     * which never skips.
     *
     * @return list<string>
     */
    private function subListedLineTables(): array
    {
        $rows = ContractDocument::operationRows();
        $guards = ContractDocument::restoreGuardTables();
        $tables = [];

        foreach ($rows as $list) {
            if (1 !== preg_match('/^List \/ ([A-Za-z]+)$/', $list['verbResource'], $plural) || !str_contains($list['query'], '*(bounded)*')) {
                continue;
            }
            $singulars = self::singularsOf($plural[1]);

            foreach ($rows as $create) {
                if ($create['path'] !== $list['path'] || 1 !== preg_match('/^Create \/ ([A-Za-z]+)$/', $create['verbResource'], $resource)
                    || !\in_array($resource[1], $singulars, true)) {
                    continue;
                }

                $table = $guards[$resource[1]]
                    ?? self::tableOfGuardlessCandidate($resource[1], SchemaManifest::TABLES, fn (string $t): array => $this->columnsOf($t));
                if (\in_array('line_number', $this->columnsOf($table), true)) {
                    $tables[] = $table;
                }
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * A clause (i) candidate with no `Restore` row, mapped to its table by snake
     * case (LEAD ruling D3). It **fails loudly, never skips**: no table of that
     * name, or a table that has `line_number` (so clause (i) would need a ruling
     * to name it), throws, naming the resource.
     *
     * @param array<string, mixed>              $manifestTables
     * @param callable(string): list<string>    $columnsOf
     */
    private static function tableOfGuardlessCandidate(string $resource, array $manifestTables, callable $columnsOf): string
    {
        $table = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $resource));

        if (!\array_key_exists($table, $manifestTables)) {
            throw new \LogicException(\sprintf('Sub-listed resource %s has no Restore row and its snake-case table %s does not exist, so clause (i) cannot decide it.', $resource, $table));
        }
        if (\in_array('line_number', $columnsOf($table), true)) {
            throw new \LogicException(\sprintf('Sub-listed resource %s has a line_number column but no Restore row, so clause (i) cannot name its table.', $resource));
        }

        return $table;
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

    /** @param list<string> $expectation */
    private static function isVacuous(array $expectation): bool
    {
        return [] === array_diff($expectation, self::FREE_FIELDS);
    }

    /**
     * @param array<string, string> $expected field → where the derivation got it
     * @param list<string>          $actual
     *
     * @return list<string>
     */
    private static function compare(array $expected, array $actual): array
    {
        $differences = [];
        foreach (array_diff($actual, array_keys($expected)) as $field) {
            $differences[] = 'carries `'.$field.'`';
        }
        foreach (array_diff(array_keys($expected), $actual) as $field) {
            $differences[] = 'lacks `'.$field.'`';
        }

        return $differences;
    }

    /**
     * API-23 steps 1–4 for one resource, as field → source. Pass no soft
     * resources to leave step 3 out (the probe).
     *
     * Step 1's sub-list exception: a nested `n[]` that
     * {@see self::subListedChild()} names is not a field. A nested field whose
     * bounded list and same-path create exist but whose sub-fields that create
     * does not all accept is kept, and recorded as a defect, because step 1
     * cannot except it. Every nested field that is kept is returned in
     * `$embedded` as `n => the Create operation that declares it`.
     *
     * @param array<string, array{operationId: string, verbResource: string, method: string, path: string, query: string}> $rows
     * @param array<string, list<string>>                                                                                 $additions
     * @param array<string, string>                                                                                       $softResources
     * @param list<string>                                                                                                $defects
     * @param array<string, string>                                                                                       $embedded
     *
     * @return array<string, string>
     */
    private static function derivation(string $resource, array $rows, array $additions, array $softResources, array &$defects = [], array &$embedded = []): array
    {
        $fields = ['id' => 'step 2'];
        $parents = [];

        foreach ($rows as $operationId => $row) {
            if ('Create / '.$resource !== $row['verbResource']) {
                continue;
            }
            $parents = self::parentsImpliedBy($row['path']);
            foreach (ContractDocument::acceptedFields($operationId) as $field) {
                if ([] !== ContractDocument::nestedAcceptedFields($operationId, $field)) {
                    $failed = null;
                    if (null !== self::subListedChild($resource, $field, $rows, $failed)) {
                        continue;
                    }
                    if (null !== $failed) {
                        foreach ($failed['fields'] as $unaccepted) {
                            $defects[] = \sprintf('%s\'s %s[] names `%s`, which %s does not accept, so step 1 cannot except it',
                                $resource, $field, $unaccepted, $failed['create']);
                        }
                    }
                    $embedded[$field] = $operationId;
                }
                if (str_ends_with($field, '_id')) {
                    if (\in_array(substr($field, 0, -3), $parents, true)) {
                        continue;
                    }
                    $field = substr($field, 0, -3);
                }
                $fields[$field] ??= 'step 1 (`'.$operationId.'`)';
            }
        }

        if (\array_key_exists($resource, $softResources)) {
            $fields['archived_at'] ??= 'step 3 (`'.$softResources[$resource].'` is soft)';
        }

        foreach ($additions[$resource] ?? [] as $field) {
            if ('@parent' === $field) {
                self::assertCount(1, $parents, $resource.'\'s additions name "the parent" and its Create path does not imply exactly one.');
                $field = $parents[0];
            }
            $fields[$field] ??= 'the §4.4.3 additions row';
        }

        return $fields;
    }

    /**
     * **API-23 step 1's sub-list predicate.** The child resource whose rows a
     * nested `n[]` on `Create / <resource>` are, when all three hold:
     *
     *   1. the `Get / <resource>` path followed by `/<n>` is, placeholders
     *      normalised, the path of a `List` row whose query cell is marked
     *      `*(bounded)*`;
     *   2. a `Create / <singular of that List>` row exists at the same path;
     *   3. every `n[].f` on `Create / <resource>` is in that `Create` row's
     *      accepted set.
     *
     * Otherwise null. When 1 and 2 hold and 3 does not, `$failed` names the
     * child's create and the sub-fields it does not accept.
     *
     * @param array<string, array{verbResource: string, path: string, query: string}> $rows
     * @param array{create: string, fields: list<string>}|null                         $failed
     */
    public static function subListedChild(string $resource, string $nested, array $rows, ?array &$failed = null): ?string
    {
        $failed = null;
        $get = null;
        $create = null;
        foreach ($rows as $operationId => $row) {
            if ('Get / '.$resource === $row['verbResource']) {
                $get = $row['path'];
            }
            if ('Create / '.$resource === $row['verbResource']) {
                $create = $operationId;
            }
        }
        if (null === $get || null === $create) {
            return null;
        }

        $normalise = static fn (string $path): string => (string) preg_replace('/\{[^}]+\}/', '{}', $path);
        $target = $normalise($get).'/'.$nested;

        foreach ($rows as $list) {
            if (1 !== preg_match('/^List \/ ([A-Za-z]+)$/', $list['verbResource'], $plural)
                || !str_contains($list['query'], '*(bounded)*') || $normalise($list['path']) !== $target) {
                continue;
            }
            foreach ($rows as $childCreate => $row) {
                if ($normalise($row['path']) !== $target || 1 !== preg_match('/^Create \/ ([A-Za-z]+)$/', $row['verbResource'], $child)
                    || !\in_array($child[1], self::singularsOf($plural[1]), true)) {
                    continue;
                }
                $unaccepted = array_values(array_diff(
                    ContractDocument::nestedAcceptedFields($create, $nested),
                    ContractDocument::acceptedFields($childCreate),
                ));
                if ([] === $unaccepted) {
                    return $child[1];
                }
                $failed = ['create' => $childCreate, 'fields' => $unaccepted];

                return null;
            }
        }

        return null;
    }

    /** @return list<string> the `-s`, `-es` and `-ies → -y` singular candidates of a plural resource name */
    private static function singularsOf(string $plural): array
    {
        return array_values(array_unique([
            (string) preg_replace('/s$/', '', $plural),
            (string) preg_replace('/es$/', '', $plural),
            (string) preg_replace('/ies$/', 'y', $plural),
        ]));
    }

    /**
     * §4.4.3's additions — the one parser in this file. The rule: the backticked
     * names before the first ` — ` or sentence break, parentheses removed, plus
     * three readings of prose the built rows use, stated here:
     *
     * - a row naming two resources pairs `` `a` / `b` `` positionally
     *   (`**GoodsReceipt** / **Fulfilment**`: `receipt_number` / `fulfilment_number`);
     * - *"the parent order's `{…}`"* is a field named after the parent the
     *   resource's `Create*` path implies (`@parent`, resolved in the derivation);
     * - a bold sentence *"**<Resource> also carries `field` …**"* adds that field
     *   to that resource (API-O-33's `received_by`). The capture refuses a name
     *   followed by `[`, so `` `lines[].inventory_item` `` does not add `lines`;
     *   that form is a **nested** addition, returned in `$nested` as
     *   `resource => list => members`.
     *
     * @param array<string, array<string, list<string>>> $nested
     *
     * @return array<string, list<string>>
     */
    private static function declaredAdditions(?array &$nested = null): array
    {
        $table = [];
        $nested = [];

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
            $leading = preg_split('/ — |\. /', $prose, 2)[0];

            foreach ($names[1] as $position => $name) {
                $own = (string) preg_replace_callback('/`([a-z][a-z_]*)` \/ `([a-z][a-z_]*)`/',
                    static fn (array $m): string => '`'.($m[$position + 1] ?? $m[1]).'`', $leading);
                $own = (string) preg_replace("/\\bparent [a-z]+'s `\\{[^`]*\\}`/", '`@parent`', $own);

                preg_match_all('/`(@parent|[a-z][a-z_]*)/', $own, $tokens);
                preg_match_all('/\*\*'.$name.' also carries `([a-z][a-z_]*)(?![\[a-z_])/', $cells[2], $also);

                $table[$name] = array_values(array_unique(array_diff([...$tokens[1], ...$also[1]], ['null'])));
            }

            preg_match_all('/\*\*([A-Z][A-Za-z]+) also carries `([a-z][a-z_]*)\[\]\.([a-z][a-z_]*)/', $cells[2], $members, \PREG_SET_ORDER);
            foreach ($members as [, $resource, $list, $member]) {
                $nested[$resource][$list][] = $member;
            }
        }

        return $table;
    }

    /**
     * The one DTO class a resource is serialized from: the output class of every
     * registry operation whose §4.2 cell names it with a non-`List` verb.
     *
     * @param array<string, array{verbResource: string}> $rows
     * @param array<string, HttpOperation>               $operations
     *
     * @return class-string
     */
    private static function dtoClassOf(string $resource, array $rows, array $operations): string
    {
        $classes = [];
        foreach ($operations as $name => $operation) {
            if (1 === preg_match('/^(?!List )[A-Za-z]+ \/ '.$resource.'$/', $rows[$name]['verbResource'] ?? '')) {
                $classes[] = $operation->getOutput()['class'] ?? $operation->getClass();
            }
        }
        $classes = array_values(array_unique(array_filter($classes)));
        self::assertCount(1, $classes, $resource.' is not serialized from exactly one DTO class: ['.implode(', ', $classes).']');

        return $classes[0];
    }

    /**
     * A DTO's serialized field names: its public properties, under
     * `#[SerializedName]` where one is given, less any marked `#[Ignore]`.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function serializedFields(string $class): array
    {
        $fields = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || [] !== $property->getAttributes(Ignore::class)) {
                continue;
            }
            $renamed = $property->getAttributes(SerializedName::class)[0] ?? null;
            $fields[] = null === $renamed ? $property->getName() : (string) $renamed->newInstance()->serializedName;
        }

        return $fields;
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
