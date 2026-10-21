<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Pagination\Cursor;
use PHPUnit\Framework\Attributes\Test;

/**
 * **Remaining-ops G-I — the `/items` Archived view: `ListItems?archived=true`** (`docs/session13/remaining-ops-plan.md` G-I,
 * `remaining-ops-final-red.md` H-1; `IA.md` §5.6 row "`/items` Archived", D-a). `CustomerArchivedViewTest`'s twin, with the
 * differences `/items` itself has:
 *
 *  - **No `sort` parameter, on either view.** `IA.md` §5.6 gives `/items` no alternate order (live `sku`, `—`; Archived
 *    `archived_at`, `—`), so `SortManifestTest` forbids `sort` (§10.3 step 4). Each view has exactly one order; `archived`
 *    alone switches it. The live view's `sku` order is `ItemListTest`'s.
 *  - **`supplier_id` with `archived` is refused** (422 `mutually_exclusive` at `?archived`), `ListSalesOrders`' precedent for
 *    its `customer_id` (order restores C2): the stricter default, `UX`/`SEC` to confirm. `q` is kept inside the archived range,
 *    as on every shipped Archived view.
 *
 * `archived=true` returns the archived items **instead of** the live ones (TB-3), most recently archived first
 * (`deleted_at DESC, id DESC`); the cursor key is the column's text with microseconds; a live-view cursor (an `sku` key) sent to
 * the Archived view is `malformed_cursor`, never a 500. Archived rows are seeded by owner SQL where a test needs distinct
 * instants; one is archived through `DeleteItem` to show the API's own archive lands in the view.
 */
final class ItemArchivedViewTest extends ApiTestCase
{
    /** TB-3: the Archived view holds the archived items only, newest first; the live view is unchanged. */
    #[Test]
    public function theArchivedViewHoldsArchivedItemsOnlyNewestFirst(): void
    {
        $this->seedItem(self::TENANT_A, 'LIVE-1');
        $this->archive($this->seedItem(self::TENANT_A, 'GONE-FIRST'), '2026-10-01 10:00:00+00');
        $this->archive($this->seedItem(self::TENANT_A, 'GONE-LAST'), '2026-10-05 10:00:00+00');
        $this->archive($this->seedItem(self::TENANT_A, 'GONE-MIDDLE'), '2026-10-03 10:00:00+00');

        self::assertSame(['GONE-LAST', 'GONE-MIDDLE', 'GONE-FIRST'], $this->skus('?archived=true'));
        self::assertSame(['GONE-LAST', 'GONE-MIDDLE', 'GONE-FIRST'], $this->skus('?archived=1'));
        self::assertSame(['LIVE-1'], $this->skus('?archived=false'));
        self::assertSame(['LIVE-1'], $this->skus('?archived=0'));
        self::assertSame(['LIVE-1'], $this->skus(''));

        $this->send('GET', '/items?archived=true');
        foreach ($this->json()['items'] as $item) {
            self::assertNotNull($item['archived_at'], 'An item of the Archived view carries no archived_at.');
            $this->send('GET', '/items/'.$item['id']);
            self::assertSame($this->json(), $item, 'An Archived-view item is not GetItem\'s body.');
        }
    }

    /** E9-3's point, end to end: an item archived through `DeleteItem` is found in the view, by a caller who never held its id. */
    #[Test]
    public function anItemDeletedThroughTheApiIsFoundInTheArchivedView(): void
    {
        $id = $this->seedItem(self::TENANT_A, 'DELETED-VIA-API');
        $this->send('DELETE', '/items/'.$id);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('GET', '/items?archived=true');
        self::assertSame([$id], array_column($this->json()['items'], 'id'));

        $this->send('POST', '/items/'.$id.'/restoration');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([], $this->skus('?archived=true'));
        self::assertSame(['DELETED-VIA-API'], $this->skus(''));
    }

    /** `archived` takes true/false/1/0 only (`CustomerCollectionProvider`'s values). */
    #[Test]
    public function archivedIsABoolean(): void
    {
        $this->send('GET', '/items?archived=yes');
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '?archived', 'code' => 'not_a_boolean', 'message' => 'archived must be true or false.']], $this->json()['errors']);
    }

    /**
     * Mutation GI-M4: the Archived view is never combined with `supplier_id` (`ListSalesOrders`' precedent; the archived index
     * serves no supplier filter). `supplier_id` alone, and `archived=false` with it, are still the live filtered list.
     */
    #[Test]
    public function theArchivedViewIsNotFilteredBySupplier(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme Archived Items');
        $this->seedItem(self::TENANT_A, 'ACME-LIVE', $supplier);
        $this->archive($this->seedItem(self::TENANT_A, 'ACME-GONE', $supplier), '2026-10-01 10:00:00+00');

        $this->send('GET', '/items?archived=true&supplier_id='.$supplier);
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([[
            'pointer' => '?archived',
            'code' => 'mutually_exclusive',
            'message' => 'The archived items are their own view: send archived without supplier_id.',
        ]], $this->json()['errors']);

        self::assertSame(['ACME-LIVE'], $this->skus('?supplier_id='.$supplier));
        self::assertSame(['ACME-LIVE'], $this->skus('?archived=false&supplier_id='.$supplier));
    }

    /**
     * Mutation GI-M2: the cursor key keeps microseconds. Three items archived within one millisecond are walked one page at a
     * time, forward and back, each visited exactly once; a millisecond key would skip one.
     */
    #[Test]
    public function aWalkAcrossOneMillisecondVisitsEachArchivedItemOnce(): void
    {
        $this->archive($this->seedItem(self::TENANT_A, 'MICRO-A'), '2026-10-05 10:00:00.123100+00');
        $this->archive($this->seedItem(self::TENANT_A, 'MICRO-B'), '2026-10-05 10:00:00.123400+00');
        $this->archive($this->seedItem(self::TENANT_A, 'MICRO-C'), '2026-10-05 10:00:00.123700+00');

        $seen = [];
        $cursor = null;
        $previous = null;
        do {
            $this->send('GET', '/items?archived=true&limit=1'.(null === $cursor ? '' : '&after='.$cursor));
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $page = $this->json();
            $seen = [...$seen, ...array_column($page['items'], 'sku')];
            $cursor = $page['next_cursor'];
            $previous = $page['previous_cursor'];
        } while (null !== $cursor && \count($seen) < 10);
        self::assertSame(['MICRO-C', 'MICRO-B', 'MICRO-A'], $seen, 'Forward walk across one millisecond skipped or repeated an item.');

        $back = [];
        $cursor = $previous;
        while (null !== $cursor && \count($back) < 10) {
            $this->send('GET', '/items?archived=true&limit=1&before='.$cursor);
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $page = $this->json();
            $back = [...$back, ...array_column($page['items'], 'sku')];
            $cursor = $page['previous_cursor'];
        }
        self::assertSame(['MICRO-B', 'MICRO-C'], $back, 'Backward walk across one millisecond skipped or repeated an item.');
    }

    /** Mutation GI-M3: equal archive instants are ordered by id, descending (TB-2a's tie), and paged without loss. */
    #[Test]
    public function equalArchiveInstantsAreTiedById(): void
    {
        $ids = [];
        foreach (['TIE-A', 'TIE-B', 'TIE-C'] as $sku) {
            $ids[$sku] = $this->seedItem(self::TENANT_A, $sku);
            $this->archive($ids[$sku], '2026-10-05 10:00:00+00');
        }
        $expected = array_values($ids);
        rsort($expected);

        $seen = [];
        $cursor = null;
        do {
            $this->send('GET', '/items?archived=true&limit=2'.(null === $cursor ? '' : '&after='.$cursor));
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $page = $this->json();
            $seen = [...$seen, ...array_column($page['items'], 'id')];
            $cursor = $page['next_cursor'];
        } while (null !== $cursor && \count($seen) < 10);

        self::assertSame($expected, $seen);
    }

    /**
     * Mutation GI-M5: a live-view cursor (an `sku` key) sent to the Archived view is refused as `malformed_cursor`, never cast
     * to a timestamp (a 500); so is a timestamp whose offset Postgres refuses (GI-M11, RED M-1). The reverse — an archived cursor sent to the live view — is a text position among SKUs and is
     * served (a position among rows the caller may read; `ListSalesOrders`' recorded M-2 shape).
     */
    #[Test]
    public function aLiveViewCursorSentToTheArchivedViewIsMalformedNotA500(): void
    {
        $this->archive($this->seedItem(self::TENANT_A, 'GONE'), '2026-10-01 10:00:00+00');
        $cursor = new Cursor('SKU-1', '6f1c0f8e-6c3b-4c39-9a5c-5d0f5b3e4a21')->encode();

        foreach (['after', 'before'] as $direction) {
            $this->send('GET', '/items?archived=true&'.$direction.'='.$cursor);
            self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            self::assertSame('malformed_cursor', $this->json()['errors'][0]['code']);
        }

        // RED M-1: a timestamp-shaped key whose offset Postgres refuses (`+99`: 22009 out of range; `date_parse()` accepts it)
        // is malformed too, never a 500 on the cast. `+15:59` is the widest offset Postgres takes and is served.
        foreach (['2026-10-01 10:00:00+99', '2026-10-01 10:00:00+16', '2026-10-01 10:00:00+05:99'] as $key) {
            $this->send('GET', '/items?archived=true&after='.new Cursor($key, '6f1c0f8e-6c3b-4c39-9a5c-5d0f5b3e4a21')->encode());
            self::assertSame(422, $this->httpStatus(), $key.': '.(string) $this->client()->getResponse()->getContent());
            self::assertSame('malformed_cursor', $this->json()['errors'][0]['code']);
        }
        $this->send('GET', '/items?archived=true&after='.new Cursor('2026-10-01 10:00:00+15:59', '6f1c0f8e-6c3b-4c39-9a5c-5d0f5b3e4a21')->encode());
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $archivedCursor = new Cursor('2026-10-01 10:00:00+00', '6f1c0f8e-6c3b-4c39-9a5c-5d0f5b3e4a21')->encode();
        $this->send('GET', '/items?after='.$archivedCursor);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** `q` is offered on the Archived view too: the same `sku` prefix, over archived rows only. */
    #[Test]
    public function theArchivedViewCanBeSearched(): void
    {
        $this->seedItem(self::TENANT_A, 'ACME-LIVE');
        $this->archive($this->seedItem(self::TENANT_A, 'ACME-GONE'), '2026-10-01 10:00:00+00');
        $this->archive($this->seedItem(self::TENANT_A, 'ZETA-GONE'), '2026-10-02 10:00:00+00');

        self::assertSame(['ACME-GONE'], $this->skus('?archived=true&q=ACME'));
        self::assertSame(['ACME-LIVE'], $this->skus('?q=ACME'));
    }

    /** API-22: `tenant_has_rows` is the unfiltered probe — an empty Archived view in a workspace with live items says it has items. */
    #[Test]
    public function anEmptyArchivedViewStillSaysTheWorkspaceHasItems(): void
    {
        $this->seedItem(self::TENANT_A, 'ONLY-LIVE');

        self::assertSame([], $this->skus('?archived=true'));
        self::assertTrue($this->json()['tenant_has_rows']);
    }

    /** RLS: another workspace's archived items are not in the view. */
    #[Test]
    public function anotherWorkspacesArchivedItemsAreNotListed(): void
    {
        $this->archive($this->seedItem(self::TENANT_B, 'THEIRS-GONE'), '2026-10-01 10:00:00+00', self::TENANT_B);

        self::assertSame([], $this->skus('?archived=true'));
    }

    // -----------------------------------------------------------------------

    /** @return list<string> */
    private function skus(string $query): array
    {
        $this->send('GET', '/items'.$query);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return array_column($this->json()['items'], 'sku');
    }

    private function archive(string $id, string $at, string $tenantId = self::TENANT_A): void
    {
        $this->insertAsOwnerForTenant($tenantId, 'UPDATE inventory_item SET deleted_at = ?::timestamptz WHERE id = ?::uuid', [$at, $id]);
    }
}
