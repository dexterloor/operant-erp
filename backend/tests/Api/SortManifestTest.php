<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ContractDocument;
use App\Tests\Support\IaDocument;
use PHPUnit\Framework\Attributes\Test;

/**
 * API-19 / API-19a (`docs/API.md` §10.2) — the `sort` values an operation accepts
 * are exactly the orders the sort manifest (`IA.md` §5.6) offers on its path.
 *
 * ---------------------------------------------------------------------------
 * Built paths only — the owner's reading of the contract (decision (e), 2026-09-14)
 * ---------------------------------------------------------------------------
 * The manifest has rows for routes no operation serves yet, so "both directions"
 * against the whole manifest would be red on correct code; the owner accepted
 * restricting the comparison to built paths. "Built" is derived: a manifest row
 * is in the domain when its route, under API-19a's mapping (parsed from §10.2 by
 * `ContractDocument::manifestPathMapping()`), equals the path of a list operation
 * in `TransactionBoundaryTest::contractOperations()`. Every row is accounted for
 * as in or out, and both halves are non-empty.
 */
final class SortManifestTest extends PurchaseOrderTestCase
{
    use ListOperationFixtures;

    /**
     * **API-19 — the manifest table, under API-19a's mapping and both exclusions,
     * equals the registry's declared `sort` enums, in both directions; and a value
     * outside the enum is never a 200.**
     *
     * The manifest side per built path: rows that are neither `⛔` (exclusion 1)
     * nor `(bounded, TB-1c` (exclusion 2), both read off the cells. §10.3 step 4
     * adds `sort` *"where the manifest offers an alternate order"*, so a path offers
     * `sort` iff one of those rows has a backticked alternate, and then its legal
     * values are every order those rows name (default and alternate).
     * ⚠ **Reading taken:** the enum is per operation, not per (path, view), so the
     * comparison is of the path's union; which value each *view* accepts is
     * `PurchaseOrderListTest`'s per-view tests. The registry side applies the
     * exclusions too: an operation's enum is compared with that union, so a `⛔`
     * order or any `sort` on a bounded list is red on the registry side as well.
     *
     * Behaviour: every order key the manifest names anywhere, sent as `sort` to a
     * built list whose enum lacks it, must not answer 200 (§10.2's *"an unlisted
     * value returns 200"*); every enum value answers 200 on the fixture view that
     * names it.
     *
     * Controls inside this method: the manifest parsed to rows; the built-path
     * partition has both halves non-empty; **the mapping is applied, not
     * declared** — a planted `/admin/audit-log` maps to `/audit-log` and every API
     * path the table names is a §4.2 `List` row's path; **the exclusions cut the
     * same on both documents** — every manifest route with a `(bounded, TB-1c` row
     * maps to a §4.2 list marked `*(bounded)*`, and every such §4.2 list's route
     * has no keyset order; at least one built path offers `sort` and one does not;
     * at least one unlisted value was sent.
     *
     * **Mutations (C5 batch C, 2026-09-15):** §10.2's forward analogue on a built
     * list — `sku` added to `PurchaseOrderCollection`'s sort enum (cache deleted —
     * Rule 17) → red on the enum; the behaviour half — the `sort` parameter given
     * explicit `constraints` (so the vendor's enum `Choice` is not derived) **and**
     * the provider's `unserved_sort` refusal turned into a silent fallback → red,
     * every unlisted value answering 200 (either refusal alone keeps it green,
     * measured); the reverse — `IA.md`'s `/purchase-orders` Open and
     * filtered-to-a-supplier rows deleted (restored by `cmp`) → red, `ordered_at`
     * accepted with no manifest row; the third — the `/admin` row of API-19a's
     * table deleted in `docs/API.md` (restored) → red on the mapping control; the
     * exclusion-2 agreement — `IA.md`'s `/warehouses` row given the keyset order
     * `code` → red. Recorded in `docs/session10/progress-C5C.md` row 23.
     *
     * **Residual non-coverage:** a view-level order (a value legal on one view and
     * not another) is not decided here; a manifest route that differs from its
     * API path other than by API-19a's table is out of the domain.
     */
    #[Test]
    public function everyAcceptedSortHasAManifestRow(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored; API-19a\'s mapping and §4.2\'s markings are read from it.');
        }
        if (!IaDocument::isAvailable()) {
            self::markTestSkipped('docs/ux/IA.md is gitignored; the sort manifest is read from it.');
        }

        $manifest = IaDocument::sortManifest();
        self::assertGreaterThan(10, \count($manifest), 'The §5.6 parser found almost nothing.');

        $mapping = ContractDocument::manifestPathMapping();
        $rows = ContractDocument::operationRows();
        $listPaths = [];
        foreach ($rows as $name => $row) {
            if (str_starts_with($row['verbResource'], 'List / ')) {
                $listPaths[$row['path']] = $name;
            }
        }

        // The mapping is API-19a's table, applied.
        self::assertSame('/audit-log', $mapping['/admin/audit-log'] ?? '/admin/audit-log', 'API-19a\'s mapping does not strip /admin from /admin/audit-log.');
        foreach ($mapping as $route => $path) {
            self::assertArrayHasKey($path, $listPaths, 'API-19a maps '.$route.' to '.$path.', which is no §4.2 List row\'s path.');
        }

        // Exclusion 2 cuts the same on both documents.
        $byRoute = [];
        foreach ($manifest as $row) {
            $byRoute[$mapping[$row['route']] ?? $row['route']][] = $row;
        }
        foreach ($byRoute as $path => $routeRows) {
            $hasBounded = [] !== array_filter($routeRows, IaDocument::isBounded(...));
            $hasKeyset = [] !== array_filter($routeRows, static fn (array $r): bool => !IaDocument::isBlocked($r) && !IaDocument::isBounded($r) && [] !== IaDocument::orderKeys($r));
            if ($hasBounded) {
                self::assertTrue(isset($listPaths[$path]) && ContractDocument::isBounded($listPaths[$path]),
                    'The manifest marks '.$path.' (bounded, TB-1c) and §4.2 has no *(bounded)* List row at that path.');
            }
            if (isset($listPaths[$path]) && ContractDocument::isBounded($listPaths[$path])) {
                self::assertFalse($hasKeyset, '§4.2 marks '.$listPaths[$path].' *(bounded)* and the manifest gives '.$path.' a keyset order.');
            }
        }

        $every = [];
        foreach ($manifest as $row) {
            $every = [...$every, ...IaDocument::orderKeys($row)];
        }
        $every = array_values(array_unique($every));

        $lists = $this->listOperations();
        $built = array_map(self::pathOf(...), $lists);
        $inDomain = 0;
        foreach ($manifest as $row) {
            if (\in_array($mapping[$row['route']] ?? $row['route'], $built, true)) {
                ++$inDomain;
            }
        }
        self::assertGreaterThan(0, $inDomain, 'No manifest row is on a built path.');
        self::assertLessThan(\count($manifest), $inDomain, 'Every manifest row is on a built path, so the restriction was never exercised.');

        $fixtures = $this->listFixtures();
        $defects = [];
        $offers = [];
        $unlistedSent = 0;

        foreach ($lists as $name => $operation) {
            $path = self::pathOf($operation);

            $legal = [];
            $alternate = false;
            foreach ($byRoute[$path] ?? [] as $row) {
                if (IaDocument::isBlocked($row) || IaDocument::isBounded($row)) {
                    continue;
                }
                $legal = [...$legal, ...IaDocument::orderKeys($row)];
                $alternate = $alternate || 1 === preg_match('/`[a-z_]+`/', $row['alternates']);
            }
            $legal = $alternate ? array_values(array_unique($legal)) : [];
            sort($legal);

            $declares = \in_array('sort', self::declaredParameters($operation), true);
            $enum = $declares ? ($operation->getParameters()?->get('sort')?->getSchema()['enum'] ?? []) : [];
            sort($enum);
            $offers[$declares ? 'offers' : 'does not offer'] = true;

            if ($declares && [] === $enum) {
                $defects[] = $name.' declares `sort` with no enum, so every value is accepted';
            }
            foreach (array_diff($enum, $legal) as $value) {
                $defects[] = \sprintf('%s accepts sort=%s on %s, which no manifest row (less ⛔ and bounded) offers', $name, $value, $path);
            }
            foreach (array_diff($legal, $enum) as $value) {
                $defects[] = \sprintf('the manifest offers %s on %s and %s does not accept it', $value, $path, $name);
            }

            [$concrete, $seed] = $fixtures[$name]();
            $context = $seed(2);

            foreach (array_diff($every, $enum) as $unlisted) {
                ++$unlistedSent;
                $this->send('GET', self::withQuery($concrete, 'sort='.$unlisted));
                if (200 === $this->httpStatus()) {
                    $defects[] = \sprintf('%s answered 200 to the unlisted sort=%s', $name, $unlisted);
                }
                // Stock reads C1 (design R-M5, mutation M-F1): a scoped list must refuse the SORT, not trip over a malformed
                // path. "Not 200" alone was satisfied by `/stock?…?sort=x`'s malformed scope uuid — the vacuity RED found.
                if (self::isScoped($concrete) && !$declares && !$this->namesUndeclared('sort')) {
                    $defects[] = \sprintf('%s refused sort=%s without naming ?sort as undeclared_parameter: %d %s', $name, $unlisted,
                        $this->httpStatus(), substr((string) $this->client()->getResponse()->getContent(), 0, 200));
                }
            }
            foreach ($context['views'] as $view) {
                parse_str($view, $q);
                if (isset($q['sort']) && \in_array($q['sort'], $enum, true)) {
                    $this->send('GET', self::withQuery($concrete, $view));
                    if (200 !== $this->httpStatus()) {
                        $defects[] = \sprintf('%s refused its declared %s: %d', $name, $view, $this->httpStatus());
                    }
                }
            }
        }

        self::assertCount(2, $offers, 'The built lists do not include both a list offering sort and one that does not.');
        self::assertGreaterThan(0, $unlistedSent, 'No unlisted sort value was sent.');
        self::assertSame([], $defects, 'API-19: an accepted sort has no manifest row, or a manifest order is not accepted.');
    }

    /** Whether the last response is a 422 naming `?$parameter` as `undeclared_parameter`. */
    private function namesUndeclared(string $parameter): bool
    {
        if (422 !== $this->httpStatus()) {
            return false;
        }
        foreach ((array) ($this->json()['errors'] ?? []) as $error) {
            if (\is_array($error) && '?'.$parameter === ($error['pointer'] ?? null) && 'undeclared_parameter' === ($error['code'] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
