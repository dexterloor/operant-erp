<?php

declare(strict_types=1);

namespace App\Tests\Api;

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
 *
 * ---------------------------------------------------------------------------
 * `::everyDeclaredParameterIsAcceptedAndNoOtherIs` is not here
 * ---------------------------------------------------------------------------
 * C5 batch C wrote it (inventory row 20) and it was **red on shipped code**: every
 * non-list operation answers 2xx to an undeclared query parameter, which §10.3's
 * first clause names a defect. It is staged, uncommitted, at
 * `docs/session10/staged/C5C-QueryParameterContractTest.php.txt` for the owner
 * and LEAD (`docs/session10/progress-C5C.md`).
 */
final class QueryParameterContractTest extends PurchaseOrderTestCase
{
    use ListOperationFixtures;

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
     * (`/items` Archived is `⛔ D-a`, not bounded) → red; the reverse — `archived`
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
        $resources = array_values(array_unique($resources));

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
            $table = $softResources[$resource] ?? strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $resource));
            self::assertArrayHasKey($table, SchemaManifest::TABLES, $name.'\'s resource '.$resource.' ties to no SchemaManifest table ('.$table.').');
            $isSoft = SchemaManifest::SOFT === SchemaManifest::TABLES[$table]['deletion'];

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
     * The manifest rows on built paths, keyed by API path, and the rows left out.
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

        self::assertNotSame([], $domain, 'No manifest row is on a built path, so the restriction left nothing to compare.');
        self::assertNotSame([], $excluded, 'Every manifest row is on a built path, so the restriction was never exercised.');
        self::assertSame(\count($manifest), \count($excluded) + array_sum(array_map(\count(...), $domain)), 'A manifest row is neither in nor out of the built-path domain.');

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
