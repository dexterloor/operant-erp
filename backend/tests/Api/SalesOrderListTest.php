<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\Application\SalesOrderViews;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * `ListSalesOrders` — the views, the orders each view offers, and the keyset walk.
 *
 * The registry-wide machinery already holds the envelope, the absence of a total,
 * the declared-parameter set and the permission gate. What is here is what
 * `SortManifestTest` says in its own docblock it cannot see: *"the enum is per
 * operation, not per (path, view)"*, so **which value each view accepts is this
 * file's job** — and on this table that is where the one deviation from the sort
 * manifest lives.
 */
final class SalesOrderListTest extends PurchaseOrderTestCase
{
    /**
     * Two spellings of one list, compared — `SEGMENT_NAMES` exists only because an
     * attribute argument must be a constant expression and `array_keys()` is a
     * function call.
     */
    #[Test]
    public function theSegmentNamesAreTheSegmentTablesKeys(): void
    {
        self::assertSame(array_keys(SalesOrderViews::SEGMENTS), SalesOrderViews::SEGMENT_NAMES);
        self::assertSame(SalesOrderViews::SEGMENT_NAMES, SalesOrderViews::segmentNames());
    }

    /**
     * **Why `SalesOrderViews::ORDERED_AT_MAY_BE_NULL` cannot be derived from the
     * server the way its purchasing twin is — asked of the live cluster, not read.**
     *
     * `PurchaseOrderListTest::theStatusesThatMayHaveNoOrderedDateAreTheOnesTheConstraintExempts`
     * asks `purchase_order_ordered_at_set`, one status at a time, which statuses it
     * admits with a NULL `ordered_at`. **No such constraint exists on `sales_order`**,
     * so the same test cannot be written here and the guarantee for the other three
     * statuses is a **code** fact — `SalesOrder::confirm()` is the column's only
     * writer — rather than a database one.
     *
     * This pins that asymmetry from both sides so it cannot be mistaken for the
     * stronger guarantee: the sales table **accepts** a `confirmed` row with a NULL
     * `ordered_at`, and the purchase table **refuses** the equivalent with `23514`.
     * The second half is the control: if the purchase constraint were ever dropped,
     * this test says so rather than quietly agreeing with itself.
     *
     * **If the database guarantee is ever wanted here it is a migration**
     * (`sales_order_ordered_at_set`), not a docblock — and it would still exempt
     * `cancelled`, which is the case `legalSorts()` actually turns on.
     */
    #[Test]
    public function theSalesTableFixesNoOrderedDateGuaranteeSoTheCodeMust(): void
    {
        $checks = $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT conname FROM pg_constraint WHERE conrelid = 'sales_order'::regclass AND contype = 'c'"
                ." AND pg_get_constraintdef(oid) LIKE '%ordered_at%'");
        self::assertSame([], $checks,
            'sales_order now carries an ordered_at CHECK. Derive SalesOrderViews::ORDERED_AT_MAY_BE_NULL from it, '
            .'as PurchaseOrderViews does, instead of from confirm() being the only writer.');

        // The sales table accepts what the purchase table refuses.
        $order = $this->seedSalesOrder(self::TENANT_A, 'SO-NULLDATE', status: SalesOrder::STATUS_CONFIRMED);
        self::assertSame([['ordered_at' => null]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT ordered_at FROM sales_order WHERE id = ?::uuid', [$order]),
            'A confirmed sales order was written with no ordered_at and the database accepted it -- '
            .'which is the premise ORDERED_AT_MAY_BE_NULL rests on. If this is now refused, re-derive that list.');

        // The control: the purchase side's constraint is real and still bites, so
        // the assertion above is a difference between two tables and not a reading
        // of a constraint that has quietly gone away everywhere.
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme NullDate');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH NullDate');
        $placed = $this->seedOrder(self::TENANT_A, 'PO-NULLDATE', $supplier, $warehouse,
            status: \App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder::STATUS_ORDERED);

        try {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'UPDATE purchase_order SET ordered_at = NULL WHERE id = ?::uuid', [$placed]);
            self::fail('purchase_order accepted an ordered row with no ordered_at, so this test is comparing two tables that both allow it.');
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            self::assertSame('23514', $e->getSQLState(), $e->getMessage());
            self::assertStringContainsString('purchase_order_ordered_at_set', $e->getMessage());
        }
    }

    /**
     * §10.3 step 2 — a segment expands to statuses, and an unknown one is refused
     * naming the four this route offers. A raw `STATUS_*` value is not a segment:
     * `?status=confirmed` is refused exactly as `?status=nonsense` is, which is what
     * keeps the state machine out of the client.
     */
    #[Test]
    public function eachSegmentReturnsExactlyItsStatusesAndAnUnknownOneIsRefused(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Segments');
        $numbers = [];
        foreach ([
            SalesOrder::STATUS_DRAFT => null,
            SalesOrder::STATUS_CONFIRMED => '2026-01-01T00:00:00+00:00',
            SalesOrder::STATUS_PARTIALLY_FULFILLED => '2026-01-02T00:00:00+00:00',
            SalesOrder::STATUS_FULFILLED => '2026-01-03T00:00:00+00:00',
            SalesOrder::STATUS_CANCELLED => null,
        ] as $status => $orderedAt) {
            $numbers[$status] = 'SO-SEG-'.strtoupper($status);
            $this->seedSalesOrder(self::TENANT_A, $numbers[$status], $customer, status: $status, orderedAt: $orderedAt);
        }

        $expected = [
            'open' => [$numbers[SalesOrder::STATUS_CONFIRMED], $numbers[SalesOrder::STATUS_PARTIALLY_FULFILLED]],
            'draft' => [$numbers[SalesOrder::STATUS_DRAFT]],
            'fulfilled' => [$numbers[SalesOrder::STATUS_FULFILLED]],
            'cancelled' => [$numbers[SalesOrder::STATUS_CANCELLED]],
        ];
        self::assertSame(SalesOrderViews::SEGMENT_NAMES, array_keys($expected),
            'The segments driven here are not the ones the operation declares, so one would go undriven.');

        foreach ($expected as $segment => $wanted) {
            $this->send('GET', '/sales-orders?status='.$segment.'&sort=order_number');
            self::assertSame(200, $this->httpStatus(), $segment.': '.(string) $this->client()->getResponse()->getContent());

            $returned = array_column($this->json()['items'], 'order_number');
            sort($returned);
            sort($wanted);
            self::assertSame($wanted, $returned, 'The `'.$segment.'` segment did not expand to exactly its statuses.');
        }

        // The five statuses are partitioned by the four segments: every seeded row
        // was returned by exactly one of them.
        $this->send('GET', '/sales-orders?status=confirmed');
        self::assertSame(422, $this->httpStatus(), 'A raw STATUS_* value was accepted as a segment.');
        self::assertSame('?status', $this->json()['errors'][0]['pointer']);
        // ⚠ **The code is the platform's `invalid_parameter`, not the provider's `unknown_segment`, and
        // that was measured rather than assumed.** The declared `QueryParameter` schema carries
        // `enum: SEGMENT_NAMES`, and API Platform validates it **before** the provider runs, so
        // `SalesOrderCollectionProvider`'s own `unknown_segment` branch is unreachable through HTTP for
        // a value outside the enum. `PurchaseOrderCollectionProvider` and `ItemCollectionProvider` carry
        // the identical dead branch and **no test in the suite asserts those codes** -- pinning them here
        // would have pinned a string the API never emits. The contract that matters is API-19's, and it
        // holds: refused with a 422 naming the parameter, never ignored.
        self::assertSame('invalid_parameter', $this->json()['errors'][0]['code']);
    }

    /**
     * **THE DEVIATION FROM THE SORT MANIFEST, as a mechanism rather than a docblock.**
     *
     * `IA.md` §5.6's *"/sales-orders filtered to a customer"* row offers `ordered_at`
     * over a predicate carrying **no status**, so that view admits rows with a NULL
     * `ordered_at` — and API-18's cursor is a row comparison, `(NULL, id) > (?, ?)`,
     * which evaluates to NULL and drops the row from every page after the first.
     * `SalesOrderViews::legalSorts()` therefore refuses `ordered_at` wherever the
     * view admits a `draft` or a `cancelled` row, and offers it where it does not.
     *
     * Both halves are in one test on purpose: an assertion that only refuses would
     * pass on an implementation that refuses `ordered_at` everywhere, which is a
     * different defect.
     *
     * **The mutation this exists for:** copy `PurchaseOrderViews::legalSorts()`
     * across verbatim — i.e. lose the `cancelled` member of `ORDERED_AT_MAY_BE_NULL`
     * — and the 422 below becomes a 200.
     */
    #[Test]
    public function orderedAtIsRefusedOnAViewThatMayHoldRowsWithoutOneAndOfferedWhereItCannot(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Sorts');

        $this->send('GET', '/sales-orders?status=cancelled&customer_id='.$customer.'&sort=ordered_at');
        self::assertSame(422, $this->httpStatus(),
            'The Cancelled customer view offered ordered_at. Every row in it may carry none, and a keyset '
            .'comparison against NULL drops rows silently.');
        self::assertSame('unserved_sort', $this->json()['errors'][0]['code']);
        self::assertSame('The orders this view offers are order_number.', $this->json()['errors'][0]['message']);

        $this->send('GET', '/sales-orders?status=draft&customer_id='.$customer.'&sort=ordered_at');
        self::assertSame(422, $this->httpStatus(), 'The Draft customer view offered ordered_at.');

        $this->send('GET', '/sales-orders?customer_id='.$customer.'&sort=ordered_at');
        self::assertSame(422, $this->httpStatus(), 'The unsegmented customer view offered ordered_at, and it holds drafts.');

        // ...and the two views where every row carries the key still offer it.
        $this->send('GET', '/sales-orders?status=open&sort=ordered_at');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('GET', '/sales-orders?status=fulfilled&customer_id='.$customer.'&sort=ordered_at');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * **The harm the refusal above prevents, driven rather than described.**
     *
     * A customer with two cancelled orders — one cancelled from `draft` (no
     * `ordered_at`, which `CreateSalesOrder` → `CancelSalesOrder` produces today) and
     * one cancelled after confirmation (a date) — paged one row at a time. **Both
     * must come back.** Under `ordered_at` the dateless row would be dropped from
     * every page after the first and the cursor that landed on it would carry `''`,
     * which `''::timestamptz` refuses with a 500.
     */
    #[Test]
    public function aCancelledCustomerViewPagesEveryRowIncludingTheOnesWithNoOrderDate(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Cancelled');
        $this->seedSalesOrder(self::TENANT_A, 'SO-C-1', $customer, status: SalesOrder::STATUS_CANCELLED);
        $this->seedSalesOrder(self::TENANT_A, 'SO-C-2', $customer, status: SalesOrder::STATUS_CANCELLED,
            orderedAt: '2026-02-01T00:00:00+00:00');
        $this->seedSalesOrder(self::TENANT_A, 'SO-C-3', $customer, status: SalesOrder::STATUS_CANCELLED);

        $seen = [];
        $path = '/sales-orders?status=cancelled&customer_id='.$customer.'&limit=1';
        $cursor = null;
        for ($page = 0; $page < 5; ++$page) {
            $this->send('GET', $path.(null === $cursor ? '' : '&after='.urlencode($cursor)));
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $seen = [...$seen, ...array_column($this->json()['items'], 'order_number')];
            $cursor = $this->json()['next_cursor'];
            if (null === $cursor) {
                break;
            }
        }

        self::assertNull($cursor, 'The walk did not terminate.');
        self::assertSame(['SO-C-1', 'SO-C-2', 'SO-C-3'], $seen,
            'A cancelled order was lost across a page boundary, or repeated. Rows with no ordered_at are the ones at risk.');
    }

    /**
     * API-17/API-18 — a keyset walk returns every row exactly once, forwards and
     * backwards, **including across a tie group**: two orders sharing one
     * `ordered_at` are what a key-only cursor skips or repeats.
     */
    #[Test]
    public function theKeysetWalkReturnsEveryRowExactlyOnceForwardsAndBackwards(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Walk');
        // Two rows share `2026-03-02`, which is the tie group the row comparison exists for.
        foreach ([['SO-W-1', '2026-03-01'], ['SO-W-2', '2026-03-02'], ['SO-W-3', '2026-03-02'], ['SO-W-4', '2026-03-03']] as [$number, $day]) {
            $this->seedSalesOrder(self::TENANT_A, $number, $customer,
                status: SalesOrder::STATUS_CONFIRMED, orderedAt: $day.'T00:00:00+00:00');
        }

        $forwards = [];
        $cursor = null;
        for ($page = 0; $page < 6; ++$page) {
            $this->send('GET', '/sales-orders?status=open&sort=ordered_at&limit=1'.(null === $cursor ? '' : '&after='.urlencode($cursor)));
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $forwards = [...$forwards, ...array_column($this->json()['items'], 'order_number')];
            $cursor = $this->json()['next_cursor'];
            if (null === $cursor) {
                break;
            }
        }
        self::assertCount(4, $forwards, 'The forward walk did not return every row exactly once: '.implode(', ', $forwards));
        self::assertSame($forwards, array_values(array_unique($forwards)), 'A row was repeated across a page boundary.');

        // Backwards from the last page: `previous_cursor` walks the same rows in the
        // list's own order.
        $this->send('GET', '/sales-orders?status=open&sort=ordered_at&limit=1&after='.urlencode((string) $this->cursorAfterFirstPage()));
        $previous = $this->json()['previous_cursor'];
        self::assertNotNull($previous, 'A page reached by `after` reported no previous page, so a client cannot walk back.');

        $this->send('GET', '/sales-orders?status=open&sort=ordered_at&limit=1&before='.urlencode((string) $previous));
        self::assertSame(200, $this->httpStatus());
        self::assertSame([$forwards[0]], array_column($this->json()['items'], 'order_number'),
            'Walking back one page did not land on the row the forward walk started from.');
    }

    private function cursorAfterFirstPage(): ?string
    {
        $this->send('GET', '/sales-orders?status=open&sort=ordered_at&limit=1');

        return $this->json()['next_cursor'];
    }

    /**
     * **API-19c limb 1 — `/sales-orders` Archived is `⛔ D-a`, so `archived` is not
     * offered and a green 200 on one would be red.** It is the mirror of
     * `ListSalesOrderLines`, which offers it on limb 2; the two rows are the two
     * sides of the rule and a copy-paste between them satisfies neither.
     */
    #[Test]
    public function archivedIsNotOfferedOnTheDocumentList(): void
    {
        $this->send('GET', '/sales-orders?archived=true');

        self::assertSame(422, $this->httpStatus());
        self::assertSame('undeclared_parameter', $this->json()['errors'][0]['code']);
    }

    /**
     * API-19d / TB-5 — `q` searches **one** column, `sales_order.order_number`, by
     * prefix, which is all `sales_order_tenant_number_live` can serve. The wildcards
     * are escaped, so `%` is a character and not a match-anything.
     */
    #[Test]
    public function theSearchMatchesTheOrderNumberByPrefixAndEscapesItsWildcards(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Findable Customer Name');
        $this->seedSalesOrder(self::TENANT_A, 'SO-FIND-1', $customer);
        $this->seedSalesOrder(self::TENANT_A, 'SO-FIND-2', $customer);
        $this->seedSalesOrder(self::TENANT_A, 'SO-OTHER-1', $customer);

        $this->send('GET', '/sales-orders?q=SO-FIND');
        $found = array_column($this->json()['items'], 'order_number');
        sort($found);
        self::assertSame(['SO-FIND-1', 'SO-FIND-2'], $found);

        // Not a prefix, and not the customer's name: one column, prefix only.
        $this->send('GET', '/sales-orders?q=FIND');
        self::assertSame([], $this->json()['items'], 'The search matched mid-string, which the index cannot serve.');

        $this->send('GET', '/sales-orders?q=Findable');
        self::assertSame([], $this->json()['items'], 'The search reached the customer name, which is the cross-column match TB-5 forbids.');

        $this->send('GET', '/sales-orders?q=%25');
        self::assertSame(200, $this->httpStatus());
        self::assertSame([], $this->json()['items'], 'A literal % matched everything, so the wildcard is not escaped.');

        $this->send('GET', '/sales-orders?q=SO_FIND');
        self::assertSame([], $this->json()['items'], 'A literal _ matched any character, so the wildcard is not escaped.');
    }

    /** API-17's bounds and API-18's exclusivity, each refused rather than clamped or ignored. */
    #[Test]
    public function thePagingParametersAreRefusedRatherThanClampedOrIgnored(): void
    {
        // The codes are the ones the API actually emits, measured: `limit` and `customer_id` carry a
        // schema (`minimum`/`maximum`, `format: uuid`) that API Platform validates before the provider
        // runs, so those refusals are the platform's `invalid_parameter`; the cursor has no schema to
        // check and reaches the provider, which is why `malformed_cursor` survives. See the segment test
        // for the same finding stated in full. What API-19 requires either way is that each is
        // **refused and named**, never clamped and never ignored.
        foreach (['limit=0' => ['?limit', 'invalid_parameter'], 'limit=201' => ['?limit', 'invalid_parameter'],
            'limit=x' => ['?limit', 'invalid_parameter'], 'customer_id=nonsense' => ['?customer_id', 'invalid_parameter'],
            'after=not-a-cursor' => ['?after', 'malformed_cursor']] as $query => [$pointer, $code]) {
            $this->send('GET', '/sales-orders?'.$query);
            self::assertSame(422, $this->httpStatus(), $query.' was accepted.');
            self::assertSame($pointer, $this->json()['errors'][0]['pointer'], $query);
            self::assertSame($code, $this->json()['errors'][0]['code'], $query);
        }

        $this->send('GET', '/sales-orders?after=a&before=b');
        self::assertSame(422, $this->httpStatus());
        self::assertContains('mutually_exclusive', array_column($this->json()['errors'], 'code'));
    }

    /**
     * ST-2 / API-22 — the probe carries **no filter predicate**, so it answers *"does
     * this workspace have any sales orders at all"*. §4.4.2: every document list
     * opens on a segment, so a filter is always set and every empty list would
     * otherwise read as filtered.
     */
    #[Test]
    public function anEmptyPageReportsWhetherTheWorkspaceHasAnyOrdersAtAll(): void
    {
        $this->send('GET', '/sales-orders?status=fulfilled');
        self::assertSame([], $this->json()['items']);
        self::assertFalse($this->json()['tenant_has_rows']);

        $this->seedSalesOrder(self::TENANT_A, 'SO-EMPTY-1');

        $this->send('GET', '/sales-orders?status=fulfilled');
        self::assertSame([], $this->json()['items']);
        self::assertTrue($this->json()['tenant_has_rows'],
            'The workspace has orders and a filtered empty page reported first-run, which is ST-2\'s wrong empty state.');
    }

    /**
     * TB-3 and the tenant boundary on one page: an archived order never appears on a
     * live list, and another tenant's never appears at all.
     *
     * `created_by` is asserted here too, because a list row is the same document
     * under the same code — **owner ruling O-5 / PC-15 amendment 2**, applied rather
     * than extended: §4.2 gates `GetSalesOrder` and `ListSalesOrders` on the same
     * `sales_order.view`, so the disclosure condition is met identically. The seeded
     * rows have no creator, which is the `null` case the resource must render.
     */
    #[Test]
    public function aLiveListIsBoundedByItsTenantAndExcludesArchivedRows(): void
    {
        $mine = $this->seedSalesOrder(self::TENANT_A, 'SO-MINE');
        $archived = $this->seedSalesOrder(self::TENANT_A, 'SO-ARCHIVED');
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        $this->seedPrincipal(self::TENANT_B, []);
        $this->seedSalesOrder(self::TENANT_B, 'SO-THEIRS');

        $this->send('GET', '/sales-orders');

        self::assertSame(200, $this->httpStatus());
        self::assertSame([$mine], array_column($this->json()['items'], 'id'));
        self::assertNull($this->json()['items'][0]['created_by'],
            'A seeded order has no human author, and the list must render that as null rather than guess.');
    }
}
