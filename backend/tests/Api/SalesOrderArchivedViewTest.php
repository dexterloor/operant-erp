<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\Application\SalesOrderViews;
use App\Platform\Http\Pagination\Cursor;
use PHPUnit\Framework\Attributes\Test;

/**
 * **Order restores C2 — the `/sales-orders` Archived view: `ListSalesOrders?archived=true`** (`docs/session13/order-restores-design.md`
 * §5.1 as amended by R1.5 and `order-restores-final-red.md` M-2; `IA.md` §5.6 `/sales-orders` Archived, D-a).
 * `CustomerArchivedViewTest`'s twin, with sales names.
 *
 * `archived=true` returns the archived orders **instead of** the live ones (TB-3); the one order is `archived_at`, newest
 * first (`deleted_at DESC, id DESC`); the cursor key is the column's text with microseconds. The Archived view has its OWN
 * sort list — `ordered_at` and `order_number` are `unserved_sort` there (RED M-1: every archived order is a draft and
 * carries no `ordered_at`), and `archived_at` is `unserved_sort` on the live views. A cursor whose key is not a timestamp
 * is `malformed_cursor`; a live `ordered_at` cursor is accepted as a position (final RED M-2, recorded). `status` and
 * `customer_id` are refused with it (R1.5, the stricter default, `UX`/`SEC` to confirm). Archived rows are seeded by owner
 * SQL with chosen instants; `DeleteSalesOrder` archives through the API and is tested in `SalesOrderDeleteTest`.
 */
final class SalesOrderArchivedViewTest extends PurchaseOrderTestCase
{
    /** TB-3: the Archived view holds the archived orders only, newest first; each item is `GetSalesOrder`'s body. */
    #[Test]
    public function theArchivedViewHoldsArchivedOrdersOnlyNewestFirst(): void
    {
        $this->seedSalesOrder(self::TENANT_A, 'SO-171001');
        $this->archive($this->seedSalesOrder(self::TENANT_A, 'SO-171002'), '2026-10-01 10:00:00+00');
        $this->archive($this->seedSalesOrder(self::TENANT_A, 'SO-171003'), '2026-10-05 10:00:00+00');
        $this->archive($this->seedSalesOrder(self::TENANT_A, 'SO-171004'), '2026-10-03 10:00:00+00');

        self::assertSame(['SO-171003', 'SO-171004', 'SO-171002'], $this->numbers('?archived=true'));
        self::assertSame(['SO-171003', 'SO-171004', 'SO-171002'], $this->numbers('?archived=1&sort=archived_at'));
        self::assertSame(['SO-171001'], $this->numbers('?archived=false'));
        self::assertSame(['SO-171001'], $this->numbers('?archived=0'));
        self::assertSame(['SO-171001'], $this->numbers(''));

        $this->send('GET', '/sales-orders?archived=true');
        foreach ($this->json()['items'] as $item) {
            self::assertNotNull($item['archived_at'], 'An item of the Archived view carries no archived_at.');
            $this->send('GET', '/sales-orders/'.$item['id']);
            self::assertSame($this->json(), $item, 'An Archived-view item is not GetSalesOrder\'s body.');
        }
    }

    /** `archived` takes true/false/1/0 only. */
    #[Test]
    public function archivedIsABoolean(): void
    {
        $this->send('GET', '/sales-orders?archived=maybe');
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '?archived', 'code' => 'not_a_boolean', 'message' => 'archived must be true or false.']], $this->json()['errors']);
    }

    /**
     * `sort` is per view (R1.5, RED M-1): the live orders are refused on the Archived view and `archived_at` on the live
     * views. **Mutation (R1.8 item 1):** the Archived view serves the live `legalSorts()` → `sort=ordered_at` is served → red.
     */
    #[Test]
    public function eachViewServesOnlyItsOwnOrders(): void
    {
        $this->archive($this->seedSalesOrder(self::TENANT_A, 'SO-171011'), '2026-10-01 10:00:00+00');

        foreach (['ordered_at', 'order_number'] as $sort) {
            $this->send('GET', '/sales-orders?archived=true&sort='.$sort);
            self::assertSame(422, $this->httpStatus(), $sort.' was served on the Archived view.');
            self::assertSame('unserved_sort', $this->json()['errors'][0]['code']);
            self::assertSame('The orders this view offers are archived_at.', $this->json()['errors'][0]['message']);
        }

        foreach (['', '?status=open', '?status=draft'] as $view) {
            $this->send('GET', '/sales-orders'.$view.('' === $view ? '?' : '&').'sort=archived_at');
            self::assertSame(422, $this->httpStatus(), 'archived_at was served on the live view '.$view);
            self::assertSame('unserved_sort', $this->json()['errors'][0]['code']);
        }

        self::assertSame([SalesOrderViews::SORT_ARCHIVED_AT], SalesOrderViews::ARCHIVED_SORTS);
    }

    /**
     * The cursor key keeps microseconds (**mutation R1.8 item 6**: keyed on `order_number`, or on the millisecond
     * `archived_at`, the walk skips or repeats). Three orders archived within one millisecond, forward and back.
     */
    #[Test]
    public function aWalkAcrossOneMillisecondVisitsEachArchivedOrderOnce(): void
    {
        $this->archive($this->seedSalesOrder(self::TENANT_A, 'SO-171021'), '2026-10-05 10:00:00.123100+00');
        $this->archive($this->seedSalesOrder(self::TENANT_A, 'SO-171022'), '2026-10-05 10:00:00.123400+00');
        $this->archive($this->seedSalesOrder(self::TENANT_A, 'SO-171023'), '2026-10-05 10:00:00.123700+00');

        $seen = [];
        $cursor = null;
        $previous = null;
        do {
            $this->send('GET', '/sales-orders?archived=true&limit=1'.(null === $cursor ? '' : '&after='.$cursor));
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $page = $this->json();
            $seen = [...$seen, ...array_column($page['items'], 'order_number')];
            $cursor = $page['next_cursor'];
            $previous = $page['previous_cursor'];
        } while (null !== $cursor && \count($seen) < 10);
        self::assertSame(['SO-171023', 'SO-171022', 'SO-171021'], $seen, 'Forward walk across one millisecond skipped or repeated an order.');

        $back = [];
        $cursor = $previous;
        while (null !== $cursor && \count($back) < 10) {
            $this->send('GET', '/sales-orders?archived=true&limit=1&before='.$cursor);
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $page = $this->json();
            $back = [...$back, ...array_column($page['items'], 'order_number')];
            $cursor = $page['previous_cursor'];
        }
        self::assertSame(['SO-171022', 'SO-171023'], $back, 'Backward walk across one millisecond skipped or repeated an order.');
    }

    /** Equal archive instants are ordered by id, descending (TB-2a's tie), and paged without loss across a page boundary. */
    #[Test]
    public function equalArchiveInstantsAreTiedById(): void
    {
        $ids = [];
        foreach (['SO-171031', 'SO-171032', 'SO-171033'] as $number) {
            $ids[] = $id = $this->seedSalesOrder(self::TENANT_A, $number);
            $this->archive($id, '2026-10-05 10:00:00+00');
        }
        rsort($ids);

        $seen = [];
        $cursor = null;
        do {
            $this->send('GET', '/sales-orders?archived=true&limit=2'.(null === $cursor ? '' : '&after='.$cursor));
            $page = $this->json();
            $seen = [...$seen, ...array_column($page['items'], 'id')];
            $cursor = $page['next_cursor'];
        } while (null !== $cursor && \count($seen) < 10);

        self::assertSame($ids, $seen);
    }

    /**
     * Final RED M-2: a cursor whose key is not a timestamp (an `order_number` cursor from the live view) is
     * `malformed_cursor`, never cast to a timestamp (a 500). A live `ordered_at` cursor IS a timestamp and is accepted as a
     * position among archived rows — harmless, recorded, not engineered against (`Cursor` carries no view marker).
     */
    #[Test]
    public function aNonTimestampCursorSentToTheArchivedViewIsMalformedNotA500(): void
    {
        $this->archive($this->seedSalesOrder(self::TENANT_A, 'SO-171041'), '2026-10-01 10:00:00+00');
        $numberCursor = new Cursor('SO-171041', '6f1c0f8e-6c3b-4c39-9a5c-5d0f5b3e4a21')->encode();

        foreach (['after', 'before'] as $direction) {
            $this->send('GET', '/sales-orders?archived=true&'.$direction.'='.$numberCursor);
            self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            self::assertSame('malformed_cursor', $this->json()['errors'][0]['code']);
        }

        $orderedAtCursor = new Cursor('2026-10-09 10:00:00.000000+00', '6f1c0f8e-6c3b-4c39-9a5c-5d0f5b3e4a21')->encode();
        $this->send('GET', '/sales-orders?archived=true&after='.$orderedAtCursor);
        self::assertSame(200, $this->httpStatus(), 'A timestamp cursor is a position, so it must be served.');
        self::assertSame(['SO-171041'], array_column($this->json()['items'], 'order_number'));
    }

    /**
     * R1.5 / final RED L-10: `status` or `customer_id` with `archived=true` is a 422 at `?archived` — the stricter default,
     * `UX`/`SEC` to confirm the code and pointer. **Mutation (R1.8 item 7):** drop the refusal → served → red.
     */
    #[Test]
    public function theArchivedViewIsNotCombinedWithASegmentOrACustomer(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Combo');
        $this->archive($this->seedSalesOrder(self::TENANT_A, 'SO-171051', $customer), '2026-10-01 10:00:00+00');

        foreach (['status=draft', 'status=open', 'customer_id='.$customer] as $filter) {
            $this->send('GET', '/sales-orders?archived=true&'.$filter);
            self::assertSame(422, $this->httpStatus(), $filter.' was served inside the Archived view.');
            self::assertSame(['pointer' => '?archived', 'code' => 'mutually_exclusive',
                'message' => 'The archived orders are their own view: send archived without status or customer_id.'], $this->json()['errors'][0]);
        }

        $this->send('GET', '/sales-orders?archived=false&status=draft');
        self::assertSame(200, $this->httpStatus(), 'archived=false is the live view, and a segment is legal on it.');
    }

    /** `q` is kept as a filter inside the archived range (the customer precedent): an order-number prefix over archived rows only. */
    #[Test]
    public function theArchivedViewCanBeSearched(): void
    {
        $this->seedSalesOrder(self::TENANT_A, 'SO-172001');
        $this->archive($this->seedSalesOrder(self::TENANT_A, 'SO-172002'), '2026-10-01 10:00:00+00');
        $this->archive($this->seedSalesOrder(self::TENANT_A, 'SO-173003'), '2026-10-02 10:00:00+00');

        self::assertSame(['SO-172002'], $this->numbers('?archived=true&q=SO-172'));
        self::assertSame(['SO-172001'], $this->numbers('?q=SO-172'));
    }

    /** API-22: `tenant_has_rows` is the unfiltered probe — an empty Archived view in a workspace with live orders says so (T-14). */
    #[Test]
    public function anEmptyArchivedViewStillSaysTheWorkspaceHasOrders(): void
    {
        $this->seedSalesOrder(self::TENANT_A, 'SO-171061');

        self::assertSame([], $this->numbers('?archived=true'));
        self::assertTrue($this->json()['tenant_has_rows']);
    }

    /** RLS: another workspace's archived orders are not in the view. */
    #[Test]
    public function anotherWorkspacesArchivedOrdersAreNotListed(): void
    {
        $this->archive($this->seedSalesOrder(self::TENANT_B, 'SO-171071'), '2026-10-01 10:00:00+00', self::TENANT_B);

        self::assertSame([], $this->numbers('?archived=true'));
    }

    // -----------------------------------------------------------------------

    /** @return list<string> */
    private function numbers(string $query): array
    {
        $this->send('GET', '/sales-orders'.$query);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return array_column($this->json()['items'], 'order_number');
    }

    private function archive(string $id, string $at, string $tenantId = self::TENANT_A): void
    {
        $this->insertAsOwnerForTenant($tenantId, 'UPDATE sales_order SET deleted_at = ?::timestamptz WHERE id = ?::uuid', [$at, $id]);
    }
}
