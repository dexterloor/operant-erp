<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Support\ContractDocument;
use App\Tests\Support\IaDocument;
use PHPUnit\Framework\Attributes\Test;

/**
 * API-19b / API-19c (`docs/API.md` §10.3) — the query-parameter contract, decided
 * against §4.2's `Query parameters` column, the sort manifest in `IA.md` §5.6 and
 * `SchemaManifest`.
 *
 * ---------------------------------------------------------------------------
 * Built paths only — the owner's reading of the contract (decision (e), 2026-09-14)
 * ---------------------------------------------------------------------------
 * The manifest has rows for routes no operation serves yet (`/sales-orders`,
 * `/invoices`, `/stock`, …), so a comparison "in both directions" against the
 * whole manifest would be red on correct code. **The owner accepted restricting
 * the comparison to built paths.** "Built" is derived here, never listed: a
 * manifest row is in the domain when its route, under API-19a's mapping (parsed
 * from §10.2 by `ContractDocument::manifestPathMapping()`), equals the path of a
 * list operation in `TransactionBoundaryTest::contractOperations()`. Every manifest
 * row is accounted for as in or out, and both halves are asserted non-empty, so
 * the restriction cannot silently exclude everything.
 */
final class QueryParameterContractTest extends PurchaseOrderTestCase
{
    use ListOperationFixtures;
    use OperationSuccessRecipes;

    /** The parameter name no §4.2 row declares, sent to every operation. */
    private const PROBE = 'c5_undeclared_probe';

    /** While set, `send()` appends this query string to requests addressed to this operation's route. */
    private ?array $probeTarget = null;

    /**
     * **§10.3's second fails-if clause — every `?`-placeholder in the sort
     * manifest's predicate column has a parameter on the corresponding operation,
     * and every filter parameter a built list declares is one a manifest row
     * derives.** Both directions, restricted to built paths (class docblock).
     *
     * The derivation is §10.3's, steps 1 and 2: a `col = ?` placeholder derives the
     * parameter `col`; a `status = …` or `status IN (…)` predicate derives the
     * segment parameter §10.3 step 2 names (read from its text). The parameters that
     * are **not** manifest predicates — step 3's, step 4's and API-19d's — are read
     * from §10.3's own text and left out of the reverse direction; `archived` is
     * {@see self::archivedIsOfferedExactlyWhereItIsEarned()}'s subject.
     *
     * The operation side is **both** the registry's declared parameters and §4.2's
     * cell, because §10.3's reverse mutation deletes from the cell.
     *
     * ⚠ **Reading taken, stated rather than hidden:** API-19a's two exclusions are
     * read as exclusions of *orders*, not of predicates. §10.3's own reverse
     * mutation — *"delete `supplier_id` from `ListItems`'s cell; the manifest's
     * `/items` filtered-to-a-supplier row still has the placeholder; red"* — needs
     * that row's placeholder to count, and the row is `⛔ D-c` (*"the filter is
     * served; the order inside it is not"*). So a `⛔` or `(bounded, TB-1c` row's
     * placeholders derive parameters here.
     *
     * Controls inside this method: the manifest parsed to rows and the built-path
     * partition has both halves non-empty; the mapping maps a planted
     * `/admin/audit-log` to `/audit-log` and leaves `/items` alone; the placeholder
     * reader derives `supplier_id` from a planted `deleted_at IS NULL AND
     * supplier_id = ?` and nothing from `deleted_at IS NULL`; at least one
     * placeholder was compared.
     *
     * **Mutations (C5 batch C, 2026-09-15):** §10.3's reverse — `supplier_id`
     * removed from `ListItems`'s §4.2 cell in `docs/API.md` (restored by `cmp`) →
     * red; `supplier_id` removed from `ItemCollection`'s parameters (cache deleted —
     * Rule 17) → red; `/items` filtered-to-a-supplier row's placeholder changed to
     * `vendor_id = ?` in `IA.md` (restored) → red in both directions. Recorded in
     * `docs/session10/progress-C5C.md` row 21.
     *
     * **Residual non-coverage:** a manifest route that differs from its API path
     * other than by API-19a's table is out of the domain rather than red; a
     * predicate written other than `col = ?` / `status …` (e.g. `col IN (?)`)
     * derives nothing.
     */
    #[Test]
    public function everyManifestPredicateHasAParameter(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored; API-19a\'s mapping and §4.2\'s cells are read from it.');
        }
        if (!IaDocument::isAvailable()) {
            self::markTestSkipped('docs/ux/IA.md is gitignored; the sort manifest is read from it.');
        }

        self::assertSame(['supplier_id'], IaDocument::placeholders(['predicate' => '`deleted_at IS NULL AND supplier_id = ?`']));
        self::assertSame([], IaDocument::placeholders(['predicate' => '`deleted_at IS NULL`']));

        $segment = self::segmentParameterName();
        [$domain, $excluded] = $this->builtManifestRows();
        $notPredicates = self::nonPredicateParameters();

        $defects = [];
        $compared = 0;

        foreach ($this->listOperations() as $name => $operation) {
            $path = self::pathOf($operation);
            $derived = [];
            foreach ($domain[$path] ?? [] as $row) {
                foreach (IaDocument::placeholders($row) as $column) {
                    if ($segment !== $column) {
                        $derived[$column][] = self::label($row);
                    }
                }
                if (IaDocument::hasStatusPredicate($row)) {
                    $derived[$segment][] = self::label($row);
                }
            }

            foreach (['registry' => self::declaredParameters($operation), '§4.2 cell' => ContractDocument::queryParameters($name)] as $side => $parameters) {
                foreach ($derived as $parameter => $from) {
                    ++$compared;
                    if (!\in_array($parameter, $parameters, true)) {
                        $defects[] = \sprintf('%s (%s) has no `%s`, which the manifest row %s derives', $name, $side, $parameter, implode(' and ', array_unique($from)));
                    }
                }
                foreach (array_diff($parameters, $notPredicates) as $parameter) {
                    if (!\array_key_exists($parameter, $derived)) {
                        $defects[] = \sprintf('%s (%s) declares `%s`, which no manifest row for %s derives', $name, $side, $parameter, $path);
                    }
                }
            }
        }

        self::assertGreaterThan(0, $compared, 'No manifest placeholder on a built path was compared, so the forward direction had no subject.');
        self::assertSame([], $defects, 'API-19b: a manifest predicate has no parameter, or a filter parameter has no manifest predicate.');
    }

    /**
     * **API-19c — `archived` is offered on a list if and only if the sort manifest
     * gives its route an Archived row that is not `⛔` (limb 1), or the list is
     * §4.2-`*(bounded)*` over a `SchemaManifest` `soft` table (limb 2).** Both
     * directions, over every built list; limb 1 is restricted to built paths
     * (class docblock).
     *
     * Limb 1's domain is parsed from the manifest: a row whose predicate carries
     * `deleted_at IS NOT NULL` (§10.3 step 3's own trigger). Limb 2's is §4.2's
     * marking plus the list's table — the list's resource from its `Verb /
     * Resource` cell (`ResponseShapeTest::resourceOf()`), tied to a table by §4.2's
     * `Restore` guard (`ContractDocument::restoreGuardTables()`, held equal to
     * `SchemaManifest::softDeletedTables()` first) or else by its snake-case name,
     * which must exist in `SchemaManifest::TABLES`. Neither is a route list.
     *
     * Controls inside this method: the resource → table tie covers the soft set
     * exactly; §4.2's bounded marking equals "declares no `limit`" for every built
     * list; every manifest Archived row the parser found names *Archived* in its
     * view; at least one built list is decided by each of: offered, not offered.
     *
     * **Mutations (C5 batch C, 2026-09-15; cache deleted — Rule 17):** §10.3's
     * forward analogue on a built list — `archived` added to `ItemCollection`
     * (`/items` Archived is `⛔ D-a`, not bounded) → red *(re-pointed 2026-10-21,
     * remaining-ops G-I, which ships `ListItems?archived` with its index: the
     * forward analogue is now `archived` added to `PurchaseOrderCollection`
     * (`/purchase-orders` Archived still `⛔ D-a` until order restores C3) → red,
     * re-run in G-I, mutation GI-M12; **re-pointed again 2026-10-22** when G-I was
     * stacked on order restores C3, which ships `ListPurchaseOrders?archived`: no
     * §5.6 Archived row is `⛔ D-a` any more, so limb 1 has no built list left to
     * fail on. The forward mutation is now `archived` added to
     * `StockMovementCollection` — `stock_movement` is not a soft-delete table, so
     * limb 2 fails — → red, re-run, mutation GI-M12′)*; the reverse — `archived`
     * removed from `PurchaseOrderLineCollection` (limb 2 holds) → red; `IA.md`'s
     * `/purchase-orders` Archived row's `⛔ **D-a**` replaced with `order_number`
     * (restored by `cmp`) → red (limb 1 now holds and the list does not offer it).
     * Recorded in `docs/session10/progress-C5C.md` row 22.
     *
     * **Residual non-coverage:** the behaviour of `archived` (which set it
     * selects) is `ArchivedRowTest`'s and `PurchaseOrderReadTest`'s; a list whose
     * route has no manifest row and is not bounded is decided by limb 2 alone.
     */
    #[Test]
    public function archivedIsOfferedExactlyWhereItIsEarned(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored; §4.2\'s (bounded) markings and resource names are read from it.');
        }
        if (!IaDocument::isAvailable()) {
            self::markTestSkipped('docs/ux/IA.md is gitignored; limb 1 is read from the sort manifest.');
        }

        $softResources = ContractDocument::restoreGuardTables();
        $tied = array_values($softResources);
        sort($tied);
        $soft = SchemaManifest::softDeletedTables();
        sort($soft);
        self::assertSame($soft, $tied, 'The §4.2 Restore-guard resource → table tie does not cover SchemaManifest\'s soft tables exactly.');

        [$domain] = $this->builtManifestRows();
        $rows = ContractDocument::operationRows();
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

        $defects = [];
        $decided = [];

        foreach ($this->listOperations() as $name => $operation) {
            $path = self::pathOf($operation);
            $bounded = ContractDocument::isBounded($name);
            self::assertSame($bounded, !\in_array('limit', self::declaredParameters($operation), true),
                $name.': §4.2\'s (bounded) marking and "declares no limit" disagree.');

            $limb1 = [];
            foreach ($domain[$path] ?? [] as $row) {
                if (IaDocument::hasArchivedPredicate($row)) {
                    self::assertStringContainsString('Archived', $row['view'], 'A manifest row with deleted_at IS NOT NULL does not name the Archived view: '.self::label($row));
                    $limb1[] = [self::label($row), !IaDocument::isBlocked($row)];
                }
            }

            $resource = ResponseShapeTest::resourceOf($rows[$name]['verbResource'], $resources);
            $computed = self::computedResources()[$resource] ?? null;
            $table = $softResources[$resource] ?? ContractDocument::derivedResourceTables()[$resource] ?? $computed ?? strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $resource));
            self::assertArrayHasKey($table, SchemaManifest::TABLES, $name.'\'s resource '.$resource.' ties to no SchemaManifest table ('.$table.').');
            // G3-C1: a COMPUTED resource's rows are decided by its computation, so its table's deletion class earns no
            // `archived` (ContractDocument::computedResourceTables()) — held narrow: bounded, and no query parameter at all.
            if (null !== $computed) {
                self::assertTrue($bounded, $name.' is a computed resource but not (bounded): the computed exemption covers bounded lists only.');
                self::assertSame([], self::declaredParameters($operation), $name.' is a computed resource and declares a query parameter: the computed exemption covers parameterless lists only.');
            }
            $isSoft = null === $computed && SchemaManifest::SOFT === SchemaManifest::TABLES[$table]['deletion'];

            $earnedByLimb1 = \in_array(true, array_column($limb1, 1), true);
            $earnedByLimb2 = $bounded && $isSoft;
            $earned = $earnedByLimb1 || $earnedByLimb2;
            $offered = \in_array('archived', self::declaredParameters($operation), true);
            $decided[$offered ? 'offered' : 'not offered'] = true;

            if ($earned !== $offered) {
                $defects[] = \sprintf('%s %s `archived`, but limb 1 %s (%s) and limb 2 %s (bounded: %s, %s is %s)',
                    $name, $offered ? 'offers' : 'does not offer',
                    $earnedByLimb1 ? 'holds' : 'fails', [] === $limb1 ? 'no Archived row for '.$path : implode('; ', array_map(static fn (array $l): string => $l[0].($l[1] ? '' : ' is ⛔'), $limb1)),
                    $earnedByLimb2 ? 'holds' : 'fails', $bounded ? 'yes' : 'no', $table, SchemaManifest::TABLES[$table]['deletion']);
            }
        }

        self::assertSame([], $defects, 'API-19c: `archived` is offered where neither limb earns it, or not offered where one does.');
        self::assertCount(2, $decided, 'The built lists do not include both an offering and a non-offering list, so one direction had no subject.');
    }

    /**
     * **API-19b / §10.3's first fails-if clause — an operation accepts exactly the
     * query parameters its §4.2 row declares: every declared one is accepted, and
     * any other is refused.** *"`—` means this operation takes no query
     * parameter"*, so the domain is **every §4.2 row that is built**, not only the
     * lists: the operation registry, each operation driven by
     * {@see OperationSuccessRecipes}.
     *
     * Three halves:
     * 1. **declared = documented**, both directions, per built operation: the
     *    registry's declared parameters equal §4.2's cell;
     * 2. **nothing else is accepted**: every operation's own request, with
     *    `?c5_undeclared_probe=1` appended (and, on every list, each parameter
     *    another §4.2 row declares and this one does not), answers 422
     *    `validation-failed` naming `?<name>` with `undeclared_parameter` — a 2xx
     *    is the parameter accepted and ignored, which API-19 calls the defect;
     * 3. **every declared parameter is accepted**: on every built list, each
     *    declared parameter sent with a value from the list's fixture answers 200.
     *
     * Controls inside this method: recipes equal the registry; every recipe
     * reached its route under the probe (the append happened); the cross-probe
     * universe parsed from §4.2 is non-empty; every declared list parameter was
     * sent; at least one operation refused the probe, so the refusal shape is
     * reachable.
     *
     * **First run (C5 batch C, 2026-09-15): red on exactly the 19 non-list
     * operations**, each answering 2xx to the probe — a shipped defect, fixed as
     * step F2 by `Platform\Http\UndeclaredQueryParameterRefusal`, which the three
     * lists now share. Its edge decisions are `UndeclaredQueryParameterRefusalTest`'s.
     *
     * **Mutations (cache deleted — Rule 17):** §10.3's, on a built list — a
     * `created_by` `QueryParameter` added to `PurchaseOrderCollection` → half 1 red
     * (§4.2's cell lacks it). F2's — the refusal removed (all 22 named), skipped for
     * writes (the 15 writes named), fed a hard-coded declared list missing
     * `ListPurchaseOrderLines` (its declared `archived` refused), its `code`
     * changed (every operation named). Recorded in `docs/session10/progress-C5C.md`
     * row 20 and `docs/session10/progress-F2.md`.
     */
    #[Test]
    public function everyDeclaredParameterIsAcceptedAndNoOtherIs(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored; §4.2\'s Query parameters column is read from it.');
        }

        $operations = TransactionBoundaryTest::contractOperations();
        $recipes = $this->successRecipes();
        $names = array_keys($operations);
        sort($names);
        $keys = array_keys($recipes);
        sort($keys);
        self::assertSame($names, $keys, 'Every registry operation must have a success recipe, and only those.');

        $universe = [];
        foreach (array_keys(ContractDocument::operationRows()) as $row) {
            $universe = [...$universe, ...ContractDocument::queryParameters($row)];
        }
        $universe = array_values(array_unique($universe));
        self::assertGreaterThan(5, \count($universe), '§4.2\'s Query parameters column parsed to almost nothing.');

        $defects = [];

        // Half 1.
        foreach ($operations as $name => $operation) {
            $declared = self::declaredParameters($operation);
            $documented = ContractDocument::queryParameters($name);
            foreach (array_diff($declared, $documented) as $p) {
                $defects[] = \sprintf('%s declares `%s`, which its §4.2 cell does not', $name, $p);
            }
            foreach (array_diff($documented, $declared) as $p) {
                $defects[] = \sprintf('%s\'s §4.2 cell declares `%s`, which the operation does not', $name, $p);
            }
        }

        // Every fixture step runs before the first request, so a refused write
        // cannot leave a later fixture without its entity manager.
        $contexts = [];
        foreach ($recipes as $name => [$fixture]) {
            $contexts[$name] = $fixture();
        }

        // Half 2, every operation: its own request with the probe appended.
        $refused = 0;
        foreach ($recipes as $name => [, $request]) {
            $this->probeTarget = ['operation' => $operations[$name], 'query' => self::PROBE.'=1', 'hits' => 0];
            $request($contexts[$name]);
            $hits = $this->probeTarget['hits'];
            $this->probeTarget = null;
            self::assertGreaterThan(0, $hits, $name.'\'s recipe never addressed its own route, so the probe was never sent.');

            $defect = $this->refusalDefect(self::PROBE);
            if (null === $defect) {
                ++$refused;
            } else {
                $defects[] = \sprintf('%s accepted `?%s`: %s', $name, self::PROBE, $defect);
            }
        }

        // Halves 2 (cross-probe) and 3, every list.
        $fixtures = $this->listFixtures();
        foreach ($this->listOperations() as $name => $operation) {
            [$path, $seed] = $fixtures[$name]();
            $context = $seed(3);
            $declared = self::declaredParameters($operation);

            foreach (array_diff($universe, $declared) as $other) {
                $this->send('GET', self::withQuery($path, $other.'=1'));
                $defect = $this->refusalDefect($other);
                if (null !== $defect) {
                    $defects[] = \sprintf('%s accepted `?%s`, which another §4.2 row declares and this one does not: %s', $name, $other, $defect);
                }
            }

            $this->send('GET', self::withQuery($path, 'limit=1'));
            $cursor = 200 === $this->httpStatus() ? ($this->json()['next_cursor'] ?? null) : null;
            $values = [
                'limit' => '1',
                'after' => $cursor,
                'before' => $cursor,
                'q' => $context['search'],
                'archived' => 'true',
                // G-29 / G-30 part 3 C1: an instant for `ListSalesReturns`' review window (sent with its companion below).
                'decided_since' => '2026-01-01T00:00:00Z',
                // The list's own filter parameter, keyed by its name so a list filtered by
                // something other than `supplier_id` needs no edit here. A list that declares
                // one and supplies no value is reported below rather than skipped.
                ...$context['filter'],
            ];
            foreach ($declared as $parameter) {
                $schema = $operation->getParameters()?->get($parameter)?->getSchema() ?? [];
                $value = $values[$parameter] ?? ($schema['enum'][0] ?? null);
                if (null === $value) {
                    $defects[] = $name.' declares `'.$parameter.'` and no value for it could be derived, so its acceptance was never sent';
                    continue;
                }
                // A parameter that stands only beside another (`decided_since` narrows `adjustment_decided=same`, and is
                // refused alone with `needs_adjustment_decided`, design §5) is sent with that companion — never alone,
                // which would test the dependency rather than the acceptance. The companion is itself declared.
                $companion = self::COMPANIONS[$parameter] ?? null;
                self::assertTrue(null === $companion || \in_array(explode('=', $companion)[0], $declared, true),
                    $name.' declares `'.$parameter.'` whose companion `'.(string) $companion.'` it does not declare.');
                // A scoped list (`/stock`, stock reads C1): its declared parameters are its mutually exclusive scopes, so
                // each is sent alone against the route — beside the fixture's own scope it would be "both", which tests
                // API-19b's exactly-one rule, not acceptance.
                $base = self::hasExclusiveScope($path) ? self::routeOf($path) : $path;
                $this->send('GET', self::withQuery($base, (null === $companion ? '' : $companion.'&').$parameter.'='.urlencode((string) $value)));
                if (200 !== $this->httpStatus()) {
                    $defects[] = \sprintf('%s refused its declared `%s=%s`: %d %s', $name, $parameter, $value, $this->httpStatus(), substr((string) $this->client()->getResponse()->getContent(), 0, 200));
                }
            }
        }

        self::assertSame([], $defects, 'API-19b: an operation accepts a query parameter its §4.2 row does not declare, or refuses one it does.');
        self::assertGreaterThan(0, $refused, 'No operation refused the probe, so the refusal shape was never observed.');
    }

    /** Parameters accepted only beside a companion parameter, with the companion's accepted value (G-29 / G-30 part 3). */
    private const COMPANIONS = ['decided_since' => 'adjustment_decided=same'];

    /**
     * **The computed resources, pinned in code** (remaining-ops G3-C1, 2026-10-22; `docs/session13/g3-c1-red-2.md` M-1): a
     * resource whose rows are a computation over a table rather than the table's rows (`ContractDocument::computedResourceTables()`),
     * so API-19c limb 2 and API-19d do not oblige it. Held EQUAL to §4.4.3's "**X is computed from `t`**" sentences, so neither
     * a doc edit nor a code edit alone widens the exemption; {@see self::computedResources()} also requires each member to
     * have no table of its own and to be in neither of the other two resource → table lists. `SearchContractTest` reads it.
     *
     * @var array<string, string>
     */
    public const COMPUTED_RESOURCES = ['ReorderSuggestion' => 'inventory_item'];

    /**
     * The pinned computed resources, after checking the pin against the document and its own conditions.
     *
     * @return array<string, string>
     */
    public static function computedResources(): array
    {
        self::assertSame(self::COMPUTED_RESOURCES, ContractDocument::computedResourceTables(),
            '§4.4.3\'s "is computed from" sentences and QueryParameterContractTest::COMPUTED_RESOURCES disagree: move both or neither.');
        foreach (self::COMPUTED_RESOURCES as $resource => $table) {
            $own = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $resource));
            self::assertArrayNotHasKey($own, SchemaManifest::TABLES, $resource.' has a table of its own, so it is not a computed resource.');
            self::assertArrayNotHasKey($resource, ContractDocument::restoreGuardTables(), $resource.' is tied to a table by a Restore guard too.');
            self::assertArrayNotHasKey($resource, ContractDocument::derivedResourceTables(), $resource.' is also "derived from" a table.');
            self::assertArrayHasKey($table, SchemaManifest::TABLES, $resource.' is computed from a table SchemaManifest does not hold.');
        }

        return self::COMPUTED_RESOURCES;
    }

    private function refusalDefect(string $parameter): ?string
    {
        $status = $this->httpStatus();
        $body = json_decode((string) $this->client()->getResponse()->getContent(), true);
        $errors = \is_array($body) ? ($body['errors'] ?? []) : [];

        foreach ((array) $errors as $error) {
            if (\is_array($error) && ('?'.$parameter) === ($error['pointer'] ?? null) && 'undeclared_parameter' === ($error['code'] ?? null)) {
                return 422 === $status ? null : 'refused by name with status '.$status;
            }
        }

        return 'answered '.$status.' without naming it undeclared';
    }

    protected function send(string $method, string $path, ?array $body = null, array $headers = [], string $accept = 'application/json'): void
    {
        if (null !== $this->probeTarget) {
            $operation = $this->probeTarget['operation'];
            $bare = str_starts_with($path, self::BASE) ? substr($path, \strlen(self::BASE)) : $path;
            $pattern = '#^'.preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/?]+', preg_quote(self::pathOf($operation), '#')).'(\?|$)#';
            if ($method === $operation->getMethod() && 1 === preg_match($pattern, $bare)) {
                $path .= (str_contains($path, '?') ? '&' : '?').$this->probeTarget['query'];
                ++$this->probeTarget['hits'];
            }
        }

        parent::send($method, $path, $body, $headers, $accept);
    }

    /**
     * The manifest rows on built paths, keyed by API path, and the rows left out.
     *
     * **Since remaining-ops G1 (`ListGoodsReceipts`, 2026-10-21) every manifest row is on a built path**, so the real
     * left-out list is empty and can no longer show that the restriction works. It was asserted non-empty until then; the
     * assertion is replaced by a **positive control** on the same partition: with one built path withheld, exactly that
     * path's rows must fall out, and every row must still be in or out. The real left-out list stays unasserted, so a
     * manifest row written ahead of its route keeps passing, as the restriction was built to allow.
     *
     * @return array{0: array<string, list<array<string, string>>>, 1: list<string>}
     */
    private function builtManifestRows(): array
    {
        $manifest = IaDocument::sortManifest();
        self::assertGreaterThan(10, \count($manifest), 'The §5.6 parser found almost nothing, so every comparison below would be vacuous.');

        $mapping = ContractDocument::manifestPathMapping();
        self::assertSame('/audit-log', self::mapRoute('/admin/audit-log', $mapping), 'API-19a\'s mapping does not strip /admin from a planted route.');
        self::assertSame('/items', self::mapRoute('/items', $mapping), 'API-19a\'s mapping changed a route its table does not name.');

        $built = array_map(self::pathOf(...), $this->listOperations());

        [$domain, $excluded] = self::partition($manifest, $mapping, $built);

        self::assertNotSame([], $domain, 'No manifest row is on a built path, so the restriction left nothing to compare.');
        self::assertSame(\count($manifest), \count($excluded) + array_sum(array_map(\count(...), $domain)), 'A manifest row is neither in nor out of the built-path domain.');

        // The positive control: withhold one built path the manifest names and the restriction must drop exactly its rows.
        $withheld = array_key_first($domain);
        [$controlDomain, $controlExcluded] = self::partition($manifest, $mapping, array_values(array_diff($built, [$withheld])));
        self::assertArrayNotHasKey($withheld, $controlDomain, 'A withheld path stayed in the built-path domain, so the restriction does not restrict.');
        $expected = [...$excluded, ...array_map(self::label(...), $domain[$withheld])];
        sort($expected);
        sort($controlExcluded);
        self::assertSame($expected, $controlExcluded, 'Withholding one built path did not move exactly its manifest rows out of the domain.');

        return [$domain, $excluded];
    }

    /**
     * @param list<array<string, string>> $manifest
     * @param array<string, string>       $mapping
     * @param list<string>                $built
     *
     * @return array{0: array<string, list<array<string, string>>>, 1: list<string>}
     */
    private static function partition(array $manifest, array $mapping, array $built): array
    {
        $domain = [];
        $excluded = [];
        foreach ($manifest as $row) {
            $path = self::mapRoute($row['route'], $mapping);
            if (\in_array($path, $built, true)) {
                $domain[$path][] = $row;
            } else {
                $excluded[] = self::label($row);
            }
        }

        return [$domain, $excluded];
    }

    /** @param array<string, string> $mapping */
    private static function mapRoute(string $route, array $mapping): string
    {
        return $mapping[$route] ?? $route;
    }

    /** @param array<string, string> $row */
    private static function label(array $row): string
    {
        return trim('`'.$row['route'].'` '.$row['view']);
    }

    /** §10.3 derivation step 2's segment parameter, read from its bold text. */
    private static function segmentParameterName(): string
    {
        $text = (string) preg_replace('/\s+/', ' ', ContractDocument::sectionBetween('**The derivation, in order:**', '**API-19c'));
        self::assertSame(1, preg_match('/\*\*`([a-z_]+)` segment parameter\*\*/', $text, $m), '§10.3 step 2 no longer names its segment parameter.');

        return $m[1];
    }

    /**
     * The parameters §10.3 derives from something other than a manifest predicate:
     * step 3's and step 4's backticked names, and API-19d's `q`.
     *
     * @return list<string>
     */
    private static function nonPredicateParameters(): array
    {
        $text = (string) preg_replace('/\s+/', ' ', ContractDocument::sectionBetween('**The derivation, in order:**', '**API-19c'));
        self::assertSame(1, preg_match('/ 3\. (.*?) 4\. (.*)$/', $text, $steps), '§10.3\'s derivation no longer has steps 3 and 4.');
        preg_match_all('/`([a-z_]+)`/', $steps[1].' '.$steps[2], $names);

        $search = implode(' ', array_filter(ContractDocument::lines(), static fn (string $line): bool => str_starts_with($line, '**API-19d')));
        self::assertSame(1, preg_match('/is the parameter `([a-z_]+)`/', $search, $q), 'API-19d no longer names its parameter.');

        $names = array_values(array_unique([...$names[1], $q[1]]));
        self::assertNotSame([], $names);

        return $names;
    }
}
