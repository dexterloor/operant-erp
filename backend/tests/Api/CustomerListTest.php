<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\Test;

/**
 * **Customers slice 1 C1 — `ListCustomers`** (`docs/session13/customers-s1-design.md` §3; `customers-s1-ux.md` §1, TB-5a).
 *
 * Live rows only (the Archived view is `CustomerArchivedViewTest`'s, C5 C4a); `sort` `name` (default) or `code`; keyset cursor `(sort key, id)`; `q` a prefix of the name ignoring
 * case, or of the code as stored. The registry-wide list checks (`ListEnvelopeTest`, `PaginationContractTest`,
 * `QueryParameterContractTest`, `SortManifestTest`, `SearchContractTest`) drive it through `ListOperationFixtures` with one
 * probe; this class pins what they cannot see — a prefix against an infix (mutation C1-2), the case rule in both
 * directions (mutation C1-7), and duplicate names across a page boundary (mutation C1-3).
 */
final class CustomerListTest extends PurchaseOrderTestCase
{
    /** Each item is exactly `GetCustomer`'s body for that customer. */
    #[Test]
    public function eachItemIsTheGetCustomerBody(): void
    {
        $this->seedCustomer(self::TENANT_A, 'Beta Stores');
        $this->seedCustomer(self::TENANT_A, 'Alpha Stores');

        $this->send('GET', '/customers');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $page = $this->json();
        self::assertSame(['Alpha Stores', 'Beta Stores'], array_column($page['items'], 'name'), 'The default order is not by name.');
        self::assertSame([null, null], [$page['next_cursor'], $page['previous_cursor']]);

        foreach ($page['items'] as $item) {
            $this->send('GET', '/customers/'.$item['id']);
            self::assertSame($this->json(), $item, 'A ListCustomers item is not GetCustomer\'s body for '.$item['id']);
        }
    }

    /**
     * Mutation C1-1: an archived customer is not in the live list. Since C5 C4a `archived=true` serves them instead (the
     * Archived view, `CustomerArchivedViewTest`).
     */
    #[Test]
    public function theListHoldsLiveCustomersOnly(): void
    {
        $this->seedCustomer(self::TENANT_A, 'Live Customer');
        $archived = $this->seedCustomer(self::TENANT_A, 'Archived Customer');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        self::assertSame(['Live Customer'], $this->names(''));
        self::assertSame(['Archived Customer'], $this->names('?archived=true'));
    }

    /** `sort=code` orders by the code as stored; an unknown order is refused. */
    #[Test]
    public function theListOrdersByNameOrByCode(): void
    {
        $this->seedNamed('Zeta', 'C-AAA');
        $this->seedNamed('Alpha', 'C-ZZZ');

        self::assertSame(['Alpha', 'Zeta'], $this->names(''));
        self::assertSame(['Alpha', 'Zeta'], $this->names('?sort=name'));
        self::assertSame(['Zeta', 'Alpha'], $this->names('?sort=code'));

        $this->send('GET', '/customers?sort=created_at');
        self::assertSame(422, $this->httpStatus());
    }

    /**
     * Mutation C1-3: three customers share a name, and a page boundary falls between them — the cursor is `(name, id)`,
     * so walking forward with `limit=1` visits each exactly once, and walking back returns them in reverse.
     */
    #[Test]
    public function customersSharingANamePageCorrectly(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $ids[] = $this->seedCustomer(self::TENANT_A, 'Same Name');
        }
        // Sorts after 'Same Name' in any collation, so the run of equal names is the first three rows.
        $this->seedCustomer(self::TENANT_A, 'Zulu Name');
        sort($ids);

        $seen = [];
        $query = '?limit=1';
        $cursor = null;
        do {
            $this->send('GET', '/customers'.$query.(null === $cursor ? '' : '&after='.$cursor));
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $page = $this->json();
            foreach ($page['items'] as $item) {
                $seen[] = $item['id'];
            }
            $cursor = $page['next_cursor'];
            $previous = $page['previous_cursor'];
        } while (null !== $cursor && \count($seen) < 10);

        self::assertCount(4, $seen, 'A page boundary inside a run of equal names dropped or repeated a row.');
        self::assertSame($ids, \array_slice($seen, 0, 3), 'Equal names are not ordered by id.');

        $back = [];
        $cursor = $previous;
        while (null !== $cursor && \count($back) < 10) {
            $this->send('GET', '/customers?limit=1&before='.$cursor);
            $page = $this->json();
            foreach ($page['items'] as $item) {
                $back[] = $item['id'];
            }
            $cursor = $page['previous_cursor'];
        }
        self::assertSame(array_reverse(\array_slice($seen, 0, 3)), $back, 'Walking back did not retrace the run of equal names.');
    }

    /**
     * TB-5a (2026-10-09, `customers-s1-ux.md` §1.1) and mutations C1-2 / C1-7: a PREFIX, never an infix; the name ignoring
     * case, the code as stored; `%` and `_` match themselves.
     */
    #[Test]
    public function aSearchIsANamePrefixIgnoringCaseOrACodePrefixAsStored(): void
    {
        $this->seedNamed('Acme Ltd', 'C-100');
        $this->seedNamed('acme trading', 'C-200');
        $this->seedNamed('Zeta', 'ACME-9');
        $this->seedNamed('Per%cent', 'C-300');
        $this->seedNamed('Under_score', 'C-400');
        $this->seedNamed('Underxscore', 'C-500');

        self::assertSame(['Acme Ltd', 'acme trading'], $this->found('acme'), 'The name is not matched ignoring case, or the code is matched ignoring case.');
        self::assertSame(['Acme Ltd', 'Zeta', 'acme trading'], $this->found('ACM'), 'The name is not matched ignoring case, or the code not as stored.');
        self::assertSame(['Acme Ltd', 'Zeta', 'acme trading'], $this->found('ACME'), 'The code is not matched as stored.');
        self::assertSame(['Zeta'], $this->found('ACME-'), 'The code is not matched as stored.');
        self::assertSame([], $this->found('cme'), 'The search matched inside a name: it is not a prefix.');
        self::assertSame([], $this->found('ME-9'), 'The search matched inside a code: it is not a prefix.');
        self::assertSame(['Per%cent'], $this->found('Per%'), '% is a wildcard, not itself.');
        self::assertSame(['Under_score'], $this->found('Under_'), '_ is a wildcard, not itself.');

        self::assertSame([], $this->found('nobody'));
        self::assertTrue($this->json()['tenant_has_rows'], 'A filtered empty page must still say the workspace has customers.');
    }

    /** API-22: a workspace with no customer at all says so on its empty page. */
    #[Test]
    public function anEmptyWorkspaceSaysItHasNoCustomers(): void
    {
        self::assertSame([], $this->names(''));
        self::assertFalse($this->json()['tenant_has_rows']);
    }

    // -----------------------------------------------------------------------

    /** @return list<string> */
    private function names(string $query): array
    {
        $this->send('GET', '/customers'.$query);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return array_column($this->json()['items'], 'name');
    }

    /**
     * The names a search returns, sorted in PHP: a search pins WHICH rows match, and the order of mixed-case names is the
     * cluster collation's business (this cluster's `en_US.utf8` on Alpine/musl orders `Zeta` before `acme`; glibc would not).
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
        $id = $this->seedCustomer(self::TENANT_A, $name);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET code = ? WHERE id = ?::uuid', [$code, $id]);

        return $id;
    }
}
