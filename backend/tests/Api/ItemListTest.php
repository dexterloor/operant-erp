<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Pagination\Cursor;
use PHPUnit\Framework\Attributes\Test;

/**
 * `ListItems` — the envelope (API-22), keyset pagination (API-17/API-18) and the
 * query-parameter contract (API-19, API-19b, API-19d).
 */
final class ItemListTest extends ApiTestCase
{
    #[Test]
    public function theEnvelopeCarriesExactlyItsFourMembers(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-1');

        $this->send('GET', '/items');

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['items', 'next_cursor', 'previous_cursor', 'tenant_has_rows'], array_keys($this->json()));
    }

    /**
     * TB-2b / API-3. Named individually rather than by "no unexpected member",
     * because these four are the ones a page-shaped implementation adds back.
     */
    #[Test]
    public function noListResponseCarriesATotalAPageOrAnOffset(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-1');

        $this->send('GET', '/items');

        foreach (['total', 'totalItems', 'hydra:totalItems', 'page', 'offset', 'count'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $this->json());
        }
    }

    /** API-22 — null on the last page and only there. A client pages until it is null. */
    #[Test]
    public function theCursorWalksEveryRowExactlyOnceAndStopsOnNull(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E'] as $sku) {
            $this->seedItem(self::TENANT_A, $sku);
        }

        $seen = [];
        $pages = 0;
        $cursor = null;

        do {
            $this->send('GET', '/items?limit=2'.(null === $cursor ? '' : '&after='.urlencode($cursor)));
            self::assertSame(200, $this->httpStatus());

            $body = $this->json();
            foreach ($body['items'] as $item) {
                $seen[] = $item['sku'];
            }

            $cursor = $body['next_cursor'];
            ++$pages;
            self::assertLessThan(10, $pages, 'The client walked off the end: next_cursor never went null.');
        } while (null !== $cursor);

        self::assertSame(['A', 'B', 'C', 'D', 'E'], $seen);
        self::assertSame(3, $pages);
    }

    /** `previous_cursor` is null on the first page and set once a cursor was used. */
    #[Test]
    public function theBackwardCursorReturnsThePreviousPageInTheListsOwnOrder(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $sku) {
            $this->seedItem(self::TENANT_A, $sku);
        }

        $this->send('GET', '/items?limit=2');
        self::assertNull($this->json()['previous_cursor'], 'The first page has no predecessor.');
        $next = $this->json()['next_cursor'];

        $this->send('GET', '/items?limit=2&after='.urlencode($next));
        $body = $this->json();
        self::assertSame(['C', 'D'], array_column($body['items'], 'sku'));
        self::assertNotNull($body['previous_cursor']);

        $this->send('GET', '/items?limit=2&before='.urlencode($body['previous_cursor']));
        self::assertSame(['A', 'B'], array_column($this->json()['items'], 'sku'));
        self::assertNotNull($this->json()['next_cursor'], 'A page reached by `before` always has a successor.');
    }

    /** API-18 / TB-2a / P-6 — the cursor is (sort key, id) and carries no tenant. */
    #[Test]
    public function theCursorCarriesTheSortKeyAndTheIdAndNoTenantIdentifier(): void
    {
        $id = $this->seedItem(self::TENANT_A, 'SKU-1');
        $this->seedItem(self::TENANT_A, 'SKU-2');

        $this->send('GET', '/items?limit=1');
        $encoded = $this->json()['next_cursor'];

        $decoded = json_decode((string) base64_decode(strtr($encoded, '-_', '+/'), true), true);
        self::assertIsArray($decoded);
        self::assertSame(['k', 'i'], array_keys($decoded));
        self::assertSame('SKU-1', $decoded['k']);
        self::assertSame($id, $decoded['i']);
        self::assertSame([], ItemReadTest::tenantKeysIn($decoded));
        self::assertStringNotContainsString(self::TENANT_A, $encoded);
    }

    /** API-17 / P-3 — offset pagination is forbidden on tenant tables. */
    #[Test]
    public function noListQueryEmitsAnOffset(): void
    {
        foreach (['A', 'B', 'C'] as $sku) {
            $this->seedItem(self::TENANT_A, $sku);
        }

        $this->recorder()->start();
        $this->send('GET', '/items?limit=1');
        $this->send('GET', '/items?limit=1&after='.urlencode($this->json()['next_cursor']));
        $this->recorder()->stop();

        self::assertSame([], $this->recorder()->matching('/\bOFFSET\b/i'));
        self::assertNotSame([], $this->recorder()->matching('/FROM inventory_item/'), 'Nothing was recorded, so the assertion above was vacuous.');
    }

    /** ST-2 — the two empty states, and the probe that tells them apart. */
    #[Test]
    public function tenantHasRowsDistinguishesFirstRunFromFiltered(): void
    {
        $this->send('GET', '/items');
        self::assertFalse($this->json()['tenant_has_rows'], 'A tenant with no rows at all is first-run.');

        $this->seedItem(self::TENANT_A, 'SKU-1');

        $this->send('GET', '/items?q=NOTHING');
        self::assertSame([], $this->json()['items']);
        self::assertTrue($this->json()['tenant_has_rows'], 'Rows exist and none matched, which is the filtered empty state.');
    }

    /** API-22 — the probe runs on the empty path only, and never on a page with rows. */
    #[Test]
    public function theEmptyProbeRunsOnlyOnAnEmptyPage(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-1');

        $this->recorder()->start();
        $this->send('GET', '/items');
        $this->recorder()->stop();
        self::assertSame([], $this->recorder()->matching('/SELECT EXISTS/i'), 'The probe ran on a page that had rows.');

        $this->recorder()->start();
        $this->send('GET', '/items?q=NOTHING');
        $this->recorder()->stop();
        self::assertCount(1, $this->recorder()->matching('/SELECT EXISTS/i'));
    }

    /** TB-5 / API-19d — one column, and `inventory_item.name` is not it (D-b). */
    #[Test]
    public function searchTouchesOnlyTheColumnTb5Names(): void
    {
        $this->seedItem(self::TENANT_A, 'AAA-1');
        $this->seedItem(self::TENANT_A, 'BBB-1');

        $this->recorder()->start();
        $this->send('GET', '/items?q=AAA');
        $this->recorder()->stop();

        self::assertSame(['AAA-1'], array_column($this->json()['items'], 'sku'));

        $searches = $this->recorder()->matching('/LIKE/i');
        self::assertCount(1, $searches);
        self::assertStringContainsString('i.sku LIKE', $searches[0]);
        self::assertStringNotContainsString('i.name LIKE', $searches[0]);
        self::assertStringNotContainsString('ILIKE', $searches[0]);
    }

    /** The sort manifest's `/items filtered to a supplier` predicate. */
    #[Test]
    public function theSupplierFilterIsServed(): void
    {
        $acme = $this->seedSupplier(self::TENANT_A, 'Acme');
        $other = $this->seedSupplier(self::TENANT_A, 'Other');
        $this->seedItem(self::TENANT_A, 'AAA', $acme);
        $this->seedItem(self::TENANT_A, 'BBB', $other);

        $this->send('GET', '/items?supplier_id='.$acme);

        self::assertSame(['AAA'], array_column($this->json()['items'], 'sku'));
    }

    /** TB-3 — a live list never returns an archived row. */
    #[Test]
    public function anArchivedRowLeavesEveryLiveList(): void
    {
        $id = $this->seedItem(self::TENANT_A, 'SKU-1');
        $this->seedItem(self::TENANT_A, 'SKU-2');

        $this->send('DELETE', '/items/'.$id);
        $this->send('GET', '/items');

        self::assertSame(['SKU-2'], array_column($this->json()['items'], 'sku'));
    }

    /** RLS, seen from the API rather than from a connection. */
    #[Test]
    public function aListNeverReturnsAnotherTenantsRows(): void
    {
        $this->seedItem(self::TENANT_A, 'MINE');
        $this->seedItem(self::TENANT_B, 'THEIRS');

        $this->send('GET', '/items');
        self::assertSame(['MINE'], array_column($this->json()['items'], 'sku'));

        $this->actAs(self::TENANT_B);
        $this->send('GET', '/items');
        self::assertSame(['THEIRS'], array_column($this->json()['items'], 'sku'));
    }

    /**
     * API-19 — an unserved `sort` is refused, not ignored. A server that
     * silently ignored it would return rows in an order the screen claims and
     * the database did not produce.
     *
     * **The refusal is the same; which limb produces it moved.** `IA.md` §5.6
     * offers `/items` no alternate order — `by name` is `⛔ D-b` — so `sort` is
     * no longer a declared parameter at all, and API-19b's exhaustiveness rule
     * refuses it before API-19's enum is consulted. The `code` is asserted for
     * exactly that reason: without it this test passes on either limb and would
     * not notice the surface changing under it. When D-b closes and
     * `ItemOperationContractTest::itemsOffersSortIfAndOnlyIfItsOrderingIndexIsMigrated`
     * forces the parameter back, this assertion is what has to be re-pointed at
     * `unserved_sort` — and it will be red until somebody does.
     */
    #[Test]
    public function anUnservedSortIsRefusedRatherThanIgnored(): void
    {
        $this->send('GET', '/items?sort=name');

        self::assertSame(422, $this->httpStatus());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        self::assertSame('?sort', $this->json()['errors'][0]['pointer']);
        self::assertSame('undeclared_parameter', $this->json()['errors'][0]['code']);
    }

    /** API-19b — the `Query parameters` cell is exhaustive; anything else is refused. */
    #[Test]
    public function anUndeclaredQueryParameterIsRefused(): void
    {
        $this->send('GET', '/items?created_by=someone');

        self::assertSame(422, $this->httpStatus());
        self::assertSame('?created_by', $this->json()['errors'][0]['pointer']);
    }

    /**
     * **API-19c limb 1 — offered since remaining-ops G-I (2026-10-21).** Until then `/items` Archived was `⛔ D-a`, `archived`
     * was not offered, and this test pinned its 422 (`theArchivedParameterIsNotOffered`). D-a's
     * `inventory_item_tenant_archived_idx` arrived with the parameter, so it is now declared and served; its behaviour is
     * `ItemArchivedViewTest`'s. What stays true here is the live view: `archived=false` is the default list.
     */
    #[Test]
    public function archivedFalseIsTheLiveList(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-LIVE');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid',
            [$this->seedItem(self::TENANT_A, 'SKU-GONE')]);

        $this->send('GET', '/items?archived=false');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['SKU-LIVE'], array_column($this->json()['items'], 'sku'));
    }

    /** API-17 — default 50, maximum 200. */
    #[Test]
    public function limitIsBounded(): void
    {
        foreach (['0', '201', 'lots', '-1'] as $bad) {
            $this->send('GET', '/items?limit='.urlencode($bad));
            self::assertSame(422, $this->httpStatus(), 'limit='.$bad.' was accepted.');
        }

        $this->send('GET', '/items?limit=200');
        self::assertSame(200, $this->httpStatus());
    }

    /** Exactly one of `after` and `before` (API-17). */
    #[Test]
    public function afterAndBeforeAreMutuallyExclusive(): void
    {
        $cursor = (new Cursor('SKU-1', '00000000-0000-4000-8000-000000000000'))->encode();

        $this->send('GET', '/items?after='.urlencode($cursor).'&before='.urlencode($cursor));

        self::assertSame(422, $this->httpStatus());
    }

    /** A forged or truncated cursor is a client error, never a 500. */
    #[Test]
    public function aMalformedCursorIsRefusedWithValidationFailed(): void
    {
        $this->send('GET', '/items?after=not-a-cursor');

        self::assertSame(422, $this->httpStatus());
        self::assertSame('?after', $this->json()['errors'][0]['pointer']);
    }
}
