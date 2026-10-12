<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\Test;

/**
 * **Suppliers slice 1 C1 — `ListSuppliers`** (`docs/session13/suppliers-s1-design.md` FINAL F-2; TB-5a's `supplier.name`
 * sentence, signed by `UX` 2026-10-12; `CustomerListTest`'s shape).
 *
 * Live rows only; `sort` `name` (default) or `code`; keyset cursor `(sort key, id)`; `q` a prefix of the name ignoring
 * case, or of the code as stored. The registry-wide list checks drive it through `ListOperationFixtures` with one probe;
 * this class pins what they cannot see — a prefix against an infix (mutation C1-2), the name leaf's case rule (C1-7), and
 * the code leaf staying as stored (C1-4b, final RED C1-4).
 *
 * **No duplicate-key paging test, deliberately:** `name` and `code` are both unique among live suppliers
 * (`supplier_tenant_name_live`, `supplier_tenant_code_live`), so a page boundary inside a run of equal keys cannot be
 * built through this list. The cursor's shape is held by `PaginationContractTest::everyCursorCarriesTheSortKeyAndTheId`.
 *
 * **No mixed-case order is pinned** (`RESIDUAL_GAPS.md` G-39): this cluster collates bytewise, so an order between `Acme`
 * and `acme` would pass here and differ on a glibc cluster. Orders are pinned over same-case ASCII names only, and search
 * results are compared as sets.
 */
final class SupplierListTest extends PurchaseOrderTestCase
{
    /** Each item is exactly `GetSupplier`'s body for that supplier. */
    #[Test]
    public function eachItemIsTheGetSupplierBody(): void
    {
        $this->seedSupplier(self::TENANT_A, 'Beta Components');
        $this->seedSupplier(self::TENANT_A, 'Alpha Components');

        $this->send('GET', '/suppliers');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $page = $this->json();
        self::assertSame(['Alpha Components', 'Beta Components'], array_column($page['items'], 'name'), 'The default order is not by name.');
        self::assertSame([null, null], [$page['next_cursor'], $page['previous_cursor']]);

        foreach ($page['items'] as $item) {
            $this->send('GET', '/suppliers/'.$item['id']);
            self::assertSame($this->json(), $item, 'A ListSuppliers item is not GetSupplier\'s body for '.$item['id']);
        }
    }

    /** Mutation C1-1: an archived supplier is not in the list (the Archived view is `⛔ D-a`; `archived` is not offered). */
    #[Test]
    public function theListHoldsLiveSuppliersOnly(): void
    {
        $this->seedSupplier(self::TENANT_A, 'Live Supplier');
        $archived = $this->seedSupplier(self::TENANT_A, 'Archived Supplier');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        self::assertSame(['Live Supplier'], $this->names(''));

        $this->send('GET', '/suppliers?archived=true');
        self::assertSame(422, $this->httpStatus(), 'archived is not offered on /suppliers (API-19c limb 1), so it must be refused.');
    }

    /** `sort=code` orders by the code as stored; an unknown order is refused. */
    #[Test]
    public function theListOrdersByNameOrByCode(): void
    {
        $this->seedNamed('Zeta', 'S-AAA');
        $this->seedNamed('Alpha', 'S-ZZZ');

        self::assertSame(['Alpha', 'Zeta'], $this->names(''));
        self::assertSame(['Alpha', 'Zeta'], $this->names('?sort=name'));
        self::assertSame(['Zeta', 'Alpha'], $this->names('?sort=code'));

        $this->send('GET', '/suppliers?sort=created_at');
        self::assertSame(422, $this->httpStatus());
    }

    /** Walking `limit=1` forward then back visits every live supplier once each way (no duplicate keys exist — see the class note). */
    #[Test]
    public function aWalkOnePageAtATimeVisitsEachSupplierOnce(): void
    {
        foreach (['Alpha', 'Bravo', 'Charlie'] as $name) {
            $this->seedSupplier(self::TENANT_A, $name);
        }

        $seen = [];
        $cursor = null;
        $previous = null;
        do {
            $this->send('GET', '/suppliers?limit=1'.(null === $cursor ? '' : '&after='.$cursor));
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $page = $this->json();
            $seen = [...$seen, ...array_column($page['items'], 'name')];
            $cursor = $page['next_cursor'];
            $previous = $page['previous_cursor'];
        } while (null !== $cursor && \count($seen) < 10);
        self::assertSame(['Alpha', 'Bravo', 'Charlie'], $seen);

        $back = [];
        $cursor = $previous;
        while (null !== $cursor && \count($back) < 10) {
            $this->send('GET', '/suppliers?limit=1&before='.$cursor);
            $page = $this->json();
            $back = [...$back, ...array_column($page['items'], 'name')];
            $cursor = $page['previous_cursor'];
        }
        self::assertSame(['Bravo', 'Alpha'], $back);
    }

    /**
     * TB-5a (`supplier.name` decided 2026-10-12, signed by `UX`) and mutations C1-2 / C1-7 / C1-4b: a PREFIX, never an
     * infix; the name ignoring case, the code as stored (one column folds, never two — TB-5); `%` and `_` match themselves.
     */
    #[Test]
    public function aSearchIsANamePrefixIgnoringCaseOrACodePrefixAsStored(): void
    {
        $this->seedNamed('Acme Ltd', 'S-100');
        $this->seedNamed('acme trading', 'S-200');
        $this->seedNamed('Zeta', 'ACME-9');
        $this->seedNamed('Per%cent', 'S-300');
        $this->seedNamed('Under_score', 'S-400');
        $this->seedNamed('Underxscore', 'S-500');

        self::assertSame(['Acme Ltd', 'acme trading'], $this->found('acme'), 'The name is not matched ignoring case, or the code is matched ignoring case.');
        self::assertSame(['Acme Ltd', 'Zeta', 'acme trading'], $this->found('ACM'), 'The name is not matched ignoring case, or the code not as stored.');
        self::assertSame(['Zeta'], $this->found('ACME-'), 'The code is not matched as stored.');
        self::assertSame([], $this->found('acme-'), 'The code leaf folds case: TB-5 allows one folded column, never two.');
        self::assertSame([], $this->found('cme'), 'The search matched inside a name: it is not a prefix.');
        self::assertSame([], $this->found('ME-9'), 'The search matched inside a code: it is not a prefix.');
        self::assertSame(['Per%cent'], $this->found('Per%'), '% is a wildcard, not itself.');
        self::assertSame(['Under_score'], $this->found('Under_'), '_ is a wildcard, not itself.');

        self::assertSame([], $this->found('nobody'));
        self::assertTrue($this->json()['tenant_has_rows'], 'A filtered empty page must still say the workspace has suppliers.');
    }

    /**
     * TB-5a's reason, observed: the name unique is case-sensitive, so a case-variant near-twin is ACCEPTED (owner notice,
     * suppliers slice 1 ruling) — and a folded search finds both, which is what lets a user see the existing row first.
     */
    #[Test]
    public function aCaseVariantTwinIsAcceptedAndTheSearchFindsBoth(): void
    {
        $this->seedSupplier(self::TENANT_A, 'Globex');
        $this->seedSupplier(self::TENANT_A, 'GLOBEX');

        self::assertSame(['GLOBEX', 'Globex'], $this->found('globex'));
    }

    /** API-22: a workspace with no supplier at all says so on its empty page. */
    #[Test]
    public function anEmptyWorkspaceSaysItHasNoSuppliers(): void
    {
        self::assertSame([], $this->names(''));
        self::assertFalse($this->json()['tenant_has_rows']);
    }

    // -----------------------------------------------------------------------

    /** @return list<string> */
    private function names(string $query): array
    {
        $this->send('GET', '/suppliers'.$query);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return array_column($this->json()['items'], 'name');
    }

    /**
     * The names a search returns, sorted in PHP: a search pins WHICH rows match, never the collation's order (G-39).
     *
     * @return list<string>
     */
    private function found(string $q): array
    {
        $names = $this->names('?q='.rawurlencode($q));
        sort($names, \SORT_STRING);

        return $names;
    }

    private function seedNamed(string $name, string $code): string
    {
        $id = $this->seedSupplier(self::TENANT_A, $name);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET code = ? WHERE id = ?::uuid', [$code, $id]);

        return $id;
    }
}
