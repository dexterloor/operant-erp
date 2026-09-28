<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Application\PurchaseOrderViews;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Platform\Http\Pagination\Cursor;
use PHPUnit\Framework\Attributes\Test;

/**
 * `ListPurchaseOrders` — the envelope (API-22), keyset pagination
 * (API-17/API-18), the segment parameter (§10.3 step 2) and the per-view sort
 * derivation (API-19).
 */
final class PurchaseOrderListTest extends PurchaseOrderTestCase
{
    #[Test]
    public function theEnvelopeCarriesExactlyItsFourMembers(): void
    {
        $this->seedDraft(self::TENANT_A, 'PO-000001');

        $this->send('GET', '/purchase-orders');

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['items', 'next_cursor', 'previous_cursor', 'tenant_has_rows'], array_keys($this->json()));
    }

    /** TB-2b / API-3. Named individually, because these are the members a page-shaped implementation adds back. */
    #[Test]
    public function noListResponseCarriesATotalAPageOrAnOffset(): void
    {
        $this->seedDraft(self::TENANT_A, 'PO-000001');

        $this->send('GET', '/purchase-orders');

        foreach (['total', 'totalItems', 'hydra:totalItems', 'page', 'offset', 'count'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $this->json());
        }
    }

    /** TB-3 — a live list never returns a soft-deleted row, and this list has no way to ask for one. */
    #[Test]
    public function anArchivedOrderIsAbsentFromTheLiveList(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $live = $this->seedOrder(self::TENANT_A, 'PO-000001', $supplier, $warehouse);
        $this->seedOrder(self::TENANT_A, 'PO-000002', $supplier, $warehouse, archived: true);

        $this->send('GET', '/purchase-orders');

        self::assertSame([$live], array_column($this->json()['items'], 'id'));
    }

    /** ST-5 — the list is bounded by RLS and never shows another tenant's rows. */
    #[Test]
    public function theListIsBoundedToTheTenantInTheToken(): void
    {
        $this->seedDraft(self::TENANT_A, 'PO-101000');

        $supplierB = $this->seedSupplier(self::TENANT_B, 'Other');
        $warehouseB = $this->seedWarehouse(self::TENANT_B, 'Other');
        $this->seedOrder(self::TENANT_B, 'PO-104000', $supplierB, $warehouseB);
        $this->seedOrder(self::TENANT_B, 'PO-105000', $supplierB, $warehouseB);

        $this->send('GET', '/purchase-orders');

        self::assertSame(['PO-101000'], array_column($this->json()['items'], 'order_number'));
    }

    // -----------------------------------------------------------------------
    // §10.3 step 2 — the status SEGMENT
    // -----------------------------------------------------------------------

    /**
     * **`open` is a segment covering two statuses, and that is the whole reason
     * the parameter is not the entity's raw `STATUS_*` value.** §10.3: *"a client
     * that had to know which two would be carrying the state machine."*
     *
     * *Mutation, run:* `PurchaseOrderViews::SEGMENTS['open']` reduced to
     * `[STATUS_ORDERED]`. Red — `partially_received` leaves the Open segment,
     * which is F-2 step 1's *"the buyer's work queue"* losing every order that
     * has had a partial delivery.
     */
    #[Test]
    public function theOpenSegmentCoversBothStatusesTheIndexPredicateNames(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        $this->seedOrder(self::TENANT_A, 'PO-000001', $supplier, $warehouse, status: PurchaseOrder::STATUS_DRAFT);
        $this->seedOrder(self::TENANT_A, 'PO-000002', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED, orderedAt: '2026-01-01T00:00:00+00:00');
        $this->seedOrder(self::TENANT_A, 'PO-000003', $supplier, $warehouse, status: PurchaseOrder::STATUS_PARTIALLY_RECEIVED, orderedAt: '2026-01-02T00:00:00+00:00');
        $this->seedOrder(self::TENANT_A, 'PO-000004', $supplier, $warehouse, status: PurchaseOrder::STATUS_RECEIVED, orderedAt: '2026-01-03T00:00:00+00:00');
        $this->seedOrder(self::TENANT_A, 'PO-000005', $supplier, $warehouse, status: PurchaseOrder::STATUS_CANCELLED, orderedAt: '2026-01-04T00:00:00+00:00');

        $this->send('GET', '/purchase-orders?status=open');

        self::assertSame(['PO-000002', 'PO-000003'], array_column($this->json()['items'], 'order_number'));
    }

    /** The other three segments, driven from the table rather than restated as three tests. */
    #[Test]
    public function eachSingleStatusSegmentReturnsItsOwnStatus(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        $numbers = [];
        foreach (PurchaseOrderViews::SEGMENTS as $segment => $statuses) {
            if ('open' === $segment) {
                continue;
            }

            $numbers[$segment] = self::freshNumber('PO');
            $this->seedOrder(self::TENANT_A, $numbers[$segment], $supplier, $warehouse, status: $statuses[0]);
        }

        self::assertCount(3, $numbers, 'The segment table no longer has three single-status segments.');

        foreach ($numbers as $segment => $number) {
            $this->send('GET', '/purchase-orders?status='.$segment);
            self::assertSame([$number], array_column($this->json()['items'], 'order_number'), $segment.' returned the wrong set.');
        }
    }

    /** A segment name outside §4.2's four is `validation-failed`, not an empty list. */
    #[Test]
    public function anUnknownSegmentIsRefused(): void
    {
        $this->seedDraft(self::TENANT_A, 'PO-000001');

        $this->send('GET', '/purchase-orders?status=ordered');

        self::assertSame(422, $this->httpStatus(),
            'The raw STATUS_* value was accepted as a segment name. §10.3 step 2 makes the segment names the '
            .'manifest\'s own, and accepting both makes the client responsible for knowing the difference.');
        // Same as `limit`: the segment set is declared on the parameter's schema
        // and again in `PurchaseOrderViews::SEGMENTS`, so the refusal may come
        // from the vendor's parameter validator or from the provider. The status
        // and the type are the contract; the code is not.
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
    }

    // -----------------------------------------------------------------------
    // API-19 — the per-view sort derivation
    // -----------------------------------------------------------------------

    /**
     * **`IA.md` §5.6's Open row: default `ordered_at`, alternate `order_number`.**
     *
     * The two are asserted to produce *different* orders, so a `sort` that is
     * accepted and ignored — API-19's named defect, *"an order the screen claims
     * and the database did not produce"* — is red rather than green.
     */
    #[Test]
    public function theOpenSegmentDefaultsToOrderedAtAndOffersOrderNumber(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        // Numbered against the ordering date, so the two orders disagree.
        $this->seedOrder(self::TENANT_A, 'PO-000003', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED, orderedAt: '2026-01-01T00:00:00+00:00');
        $this->seedOrder(self::TENANT_A, 'PO-000001', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED, orderedAt: '2026-01-02T00:00:00+00:00');
        $this->seedOrder(self::TENANT_A, 'PO-000002', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED, orderedAt: '2026-01-03T00:00:00+00:00');

        $this->send('GET', '/purchase-orders?status=open');
        self::assertSame(['PO-000003', 'PO-000001', 'PO-000002'], array_column($this->json()['items'], 'order_number'));

        $this->send('GET', '/purchase-orders?status=open&sort=ordered_at');
        self::assertSame(['PO-000003', 'PO-000001', 'PO-000002'], array_column($this->json()['items'], 'order_number'));

        $this->send('GET', '/purchase-orders?status=open&sort=order_number');
        self::assertSame(['PO-000001', 'PO-000002', 'PO-000003'], array_column($this->json()['items'], 'order_number'),
            'The alternate order was accepted and ignored, which is API-19\'s named defect.');
    }

    /**
     * **The Draft segment offers `order_number` alone**, and `ordered_at` is
     * refused rather than ignored — the manifest's Draft row lists no alternate
     * order, and every draft has a NULL `ordered_at`
     * (`purchase_order_ordered_at_set`), so it is not a sort key at all.
     */
    #[Test]
    public function theDraftSegmentRefusesTheOrderedAtSort(): void
    {
        $this->seedDraft(self::TENANT_A, 'PO-000001');

        $this->send('GET', '/purchase-orders?status=draft&sort=ordered_at');

        self::assertSame(422, $this->httpStatus());
        self::assertSame('unserved_sort', $this->json()['errors'][0]['code']);

        $this->send('GET', '/purchase-orders?status=draft&sort=order_number');
        self::assertSame(200, $this->httpStatus());
    }

    /**
     * **The manifest's *filtered to a supplier* row: `ordered_at` by default.**
     *
     * ⚠ The request carries a segment as well, and it has to: the deviation
     * `PurchaseOrderViews::legalSorts()` records is that the supplier view
     * **without** one includes drafts, whose `ordered_at` is NULL, and a keyset
     * row comparison over a NULL sort key silently drops rows. §4.4.2 says every
     * document list opens on a segment, so this is the shape the screen sends.
     */
    #[Test]
    public function theSupplierFilteredViewOrdersByOrderedAt(): void
    {
        $acme = $this->seedSupplier(self::TENANT_A, 'Acme');
        $other = $this->seedSupplier(self::TENANT_A, 'Other');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        $this->seedOrder(self::TENANT_A, 'PO-000003', $acme, $warehouse, status: PurchaseOrder::STATUS_RECEIVED, orderedAt: '2026-01-01T00:00:00+00:00');
        $this->seedOrder(self::TENANT_A, 'PO-000001', $acme, $warehouse, status: PurchaseOrder::STATUS_RECEIVED, orderedAt: '2026-01-02T00:00:00+00:00');
        $this->seedOrder(self::TENANT_A, 'PO-000009', $other, $warehouse, status: PurchaseOrder::STATUS_RECEIVED, orderedAt: '2026-01-03T00:00:00+00:00');

        $this->send('GET', '/purchase-orders?status=received&supplier_id='.$acme);

        self::assertSame(['PO-000003', 'PO-000001'], array_column($this->json()['items'], 'order_number'),
            'The supplier view is ordered by order_number, so the manifest row that gives it ordered_at is not served.');
    }

    /**
     * The same segment **without** the supplier filter offers `order_number`
     * alone — the manifest's Received row lists no alternate. This is the pair
     * that shows the sort set is a property of the *view* and not of the route.
     */
    #[Test]
    public function theReceivedSegmentAloneDoesNotOfferOrderedAt(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $this->seedOrder(self::TENANT_A, 'PO-000001', $supplier, $warehouse, status: PurchaseOrder::STATUS_RECEIVED, orderedAt: '2026-01-01T00:00:00+00:00');

        $this->send('GET', '/purchase-orders?status=received&sort=ordered_at');
        self::assertSame(422, $this->httpStatus());

        $this->send('GET', '/purchase-orders?status=received&supplier_id='.$supplier.'&sort=ordered_at');
        self::assertSame(200, $this->httpStatus(),
            'The supplier view must offer ordered_at: `purchase_order_tenant_supplier_idx` is (tenant_id, supplier_id, ordered_at).');
    }

    /**
     * **The Cancelled segment, filtered to a supplier, must not be ordered by
     * `ordered_at` — every row it can return is allowed to have none.**
     *
     * `purchase_order_ordered_at_set` exempts **two** statuses, not one:
     * `CHECK (status IN ('draft', 'cancelled') OR ordered_at IS NOT NULL)` since
     * `Version20260911000200` widened it so that §8.3's legal `draft → cancelled`
     * stopped being refused with `23514`. `PurchaseOrder::cancel()` accepts
     * `draft`, so an order cancelled before it was ever placed is reachable
     * through `CancelPurchaseOrder` and carries a NULL `ordered_at` for good.
     *
     * API-18's cursor is a row comparison and `(NULL, id) > (?, ?)` evaluates to
     * NULL, so such a row is **silently dropped** from every page after the
     * first. This is the same defect the *filtered to a supplier* deviation was
     * written for; it was guarded against `draft` alone and the constraint has
     * named `cancelled` too since the day cancellation shipped.
     */
    #[Test]
    public function theCancelledSupplierViewDoesNotLoseAnOrderCancelledBeforeItWasPlaced(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        $this->seedOrder(self::TENANT_A, 'PO-000001', $supplier, $warehouse, status: PurchaseOrder::STATUS_CANCELLED, orderedAt: '2026-01-01T00:00:00+00:00');
        $this->seedOrder(self::TENANT_A, 'PO-000002', $supplier, $warehouse, status: PurchaseOrder::STATUS_CANCELLED, orderedAt: '2026-01-02T00:00:00+00:00');
        // Cancelled while still a draft: never placed, so no ordered date. The
        // constraint admits it and `cancel()` is the shipped path that makes it.
        $this->seedOrder(self::TENANT_A, 'PO-000003', $supplier, $warehouse, status: PurchaseOrder::STATUS_CANCELLED);

        $seen = [];
        $pages = 0;
        $cursor = null;

        do {
            $this->send('GET', '/purchase-orders?status=cancelled&supplier_id='.$supplier.'&limit=2'
                .(null === $cursor ? '' : '&after='.urlencode($cursor)));
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

            $body = $this->json();
            $seen = [...$seen, ...array_column($body['items'], 'order_number')];
            $cursor = $body['next_cursor'];
            ++$pages;
            self::assertLessThan(10, $pages, 'The client walked off the end: next_cursor never went null.');
        } while (null !== $cursor);

        sort($seen);
        self::assertSame(['PO-000001', 'PO-000002', 'PO-000003'], $seen,
            'An order cancelled before it was placed has a NULL ordered_at, and the Cancelled supplier view '
            .'ordered by ordered_at drops it across the page boundary: (NULL, id) > (?, ?) is NULL, not true.');
    }

    /**
     * **The second failure mode of the same defect: a `next_cursor` that 500s
     * when the client follows it.**
     *
     * `PurchaseOrderReader::cursors()` builds `new Cursor((string) $row[$sort], …)`
     * and `(string) null` is the empty string, so a page boundary landing on a
     * row with no `ordered_at` emits a cursor whose sort key is `''`. It decodes
     * (it is a string, and the id is a UUID), reaches the statement as
     * `''::timestamptz`, and Postgres refuses it — a 500 on a cursor this API
     * handed the client one request earlier.
     */
    #[Test]
    public function theCancelledSupplierViewNeverHandsOutACursorItCannotFollow(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        $this->seedOrder(self::TENANT_A, 'PO-000001', $supplier, $warehouse, status: PurchaseOrder::STATUS_CANCELLED);
        $this->seedOrder(self::TENANT_A, 'PO-000002', $supplier, $warehouse, status: PurchaseOrder::STATUS_CANCELLED);

        $this->send('GET', '/purchase-orders?status=cancelled&supplier_id='.$supplier.'&limit=1');
        self::assertSame(200, $this->httpStatus());

        $next = $this->json()['next_cursor'];
        self::assertNotNull($next, 'Two rows and a limit of one: there is a second page and this assertion needs it.');

        $this->send('GET', '/purchase-orders?status=cancelled&supplier_id='.$supplier.'&limit=1&after='.urlencode($next));
        self::assertSame(200, $this->httpStatus(),
            'Following the next_cursor this API issued one request earlier raised. The empty sort key reached '
            .'the statement as \'\'::timestamptz.');
        self::assertSame(['PO-000002'], array_column($this->json()['items'], 'order_number'));

        // The reason, asserted separately so a future regression names itself
        // rather than arriving as an unexplained 500.
        self::assertNotSame('', Cursor::decode($next)->sortKey,
            'The cursor carries an empty sort key, which is (string) null on a row with no ordered_at.');
    }

    /**
     * **`PurchaseOrderViews::ORDERED_AT_MAY_BE_NULL` is the constraint's own
     * exempt set, asked of the constraint.**
     *
     * The two tests above are about one view. This one is about the premise both
     * of them rest on, and it exists because that premise was carried by a
     * docblock for nine days after the migration falsified it: `legalSorts()`
     * guarded `draft` alone while `purchase_order_ordered_at_set` had named
     * `cancelled` too since `Version20260911000200`.
     *
     * Nothing here is parsed out of a constraint definition and nothing is
     * listed: the **domain** is every status `purchase_order_status_values`
     * admits, read off the server, and the **answer** for each is whether the
     * database accepts that status with a NULL `ordered_at` — which is the fact
     * `legalSorts()` needs and is independent of how the constraint is worded.
     *
     * *Mutation, run:* `ORDERED_AT_MAY_BE_NULL` reduced to `[STATUS_DRAFT]`.
     * Red, naming `cancelled` as admitted and undeclared.
     */
    #[Test]
    public function theStatusesThatMayHaveNoOrderedDateAreTheOnesTheConstraintExempts(): void
    {
        $definition = (string) $this->owner()->fetchOne(
            'SELECT pg_get_constraintdef(oid) FROM pg_constraint '
            ."WHERE conrelid = 'purchase_order'::regclass AND conname = ?",
            ['purchase_order_status_values'],
        );
        self::assertNotSame('', $definition, 'purchase_order_status_values is not on the table, so this test has no domain.');

        preg_match_all("/'([a-z_]+)'/", $definition, $matches);
        $everyStatus = array_values(array_unique($matches[1]));
        sort($everyStatus);
        self::assertGreaterThan(3, \count($everyStatus), 'The status domain read off the server is too small to be the real one.');
        self::assertContains(PurchaseOrder::STATUS_CANCELLED, $everyStatus);

        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        $admitted = [];
        foreach ($everyStatus as $status) {
            // A placed order, so the only thing the UPDATE changes is the pair
            // the constraint is about. One row per status: a successful attempt
            // leaves its row in the state it just proved legal.
            $order = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse,
                status: PurchaseOrder::STATUS_ORDERED, orderedAt: '2026-01-01T00:00:00+00:00');

            try {
                $this->executeAsOwnerForTenant(self::TENANT_A,
                    'UPDATE purchase_order SET status = ?, ordered_at = NULL WHERE id = ?::uuid', [$status, $order]);
                $admitted[] = $status;
            } catch (\Throwable $e) {
                self::assertStringContainsString('purchase_order_ordered_at_set', $e->getMessage(),
                    $status.' with a NULL ordered_at was refused by something other than the constraint under test.');
            }
        }

        self::assertNotSame([], $admitted, 'No status was admitted with a NULL ordered_at, so the comparison below is vacuous.');
        self::assertNotSame($everyStatus, $admitted, 'Every status was admitted, so the constraint is not constraining anything.');

        $declared = PurchaseOrderViews::ORDERED_AT_MAY_BE_NULL;
        sort($declared);
        self::assertSame($declared, $admitted,
            'PurchaseOrderViews::ORDERED_AT_MAY_BE_NULL disagrees with purchase_order_ordered_at_set. '
            .'legalSorts() offers ordered_at over every view this list does not name, so a status missing '
            .'from it is a view whose keyset pagination drops rows and emits a cursor that raises.');
    }

    /** An unsegmented, unfiltered list has no manifest row and is ordered by `order_number`. */
    #[Test]
    public function theUnsegmentedListIsOrderedByOrderNumber(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        $this->seedOrder(self::TENANT_A, 'PO-000002', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED, orderedAt: '2026-01-01T00:00:00+00:00');
        $this->seedOrder(self::TENANT_A, 'PO-000001', $supplier, $warehouse);

        $this->send('GET', '/purchase-orders');
        self::assertSame(['PO-000001', 'PO-000002'], array_column($this->json()['items'], 'order_number'));

        $this->send('GET', '/purchase-orders?sort=ordered_at');
        self::assertSame(422, $this->httpStatus(),
            'An unsegmented list includes drafts, whose ordered_at is NULL, and a keyset comparison over a '
            .'NULL sort key drops every one of them from every page after the first.');
    }

    // -----------------------------------------------------------------------
    // Filters
    // -----------------------------------------------------------------------

    /** The `supplier_id` filter, which is the manifest's own `supplier_id = ?` placeholder. */
    #[Test]
    public function theSupplierFilterRestrictsToOneSupplier(): void
    {
        $acme = $this->seedSupplier(self::TENANT_A, 'Acme');
        $other = $this->seedSupplier(self::TENANT_A, 'Other');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        $this->seedOrder(self::TENANT_A, 'PO-000001', $acme, $warehouse);
        $this->seedOrder(self::TENANT_A, 'PO-000002', $other, $warehouse);

        $this->send('GET', '/purchase-orders?supplier_id='.$acme);

        self::assertSame(['PO-000001'], array_column($this->json()['items'], 'order_number'));
    }

    /**
     * **API-19d / TB-5 — `q` searches `purchase_order.order_number` and nothing
     * else.** A cross-column `ILIKE` is what TB-5 forbids, and the supplier's
     * name is the tempting second column.
     */
    #[Test]
    public function searchTouchesTheDocumentNumberAndNotTheSupplierName(): void
    {
        $acme = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        // Two numbers of the product's own shape that differ after the prefix (CD-7, `RED` F8): since every number
        // is `PO-dddddd`, `q=PO-` alone matches every row and would no longer prove the prefix FILTERS anything.
        $this->seedOrder(self::TENANT_A, 'PO-000011', $acme, $warehouse);
        $this->seedOrder(self::TENANT_A, 'PO-000020', $acme, $warehouse);

        $this->send('GET', '/purchase-orders?q=PO-00001');
        self::assertSame(['PO-000011'], array_column($this->json()['items'], 'order_number'));

        $this->send('GET', '/purchase-orders?q=Acme');
        self::assertSame([], $this->json()['items'],
            'The search reached the supplier name. TB-5 names one searchable column on this table.');
    }

    /** An undeclared parameter is refused (API-19b), never ignored. */
    #[Test]
    public function anUndeclaredParameterIsRefused(): void
    {
        $this->seedDraft(self::TENANT_A, 'PO-000001');

        $this->send('GET', '/purchase-orders?created_by=x');

        self::assertSame(422, $this->httpStatus());
        self::assertSame('?created_by', $this->json()['errors'][0]['pointer']);
    }

    // -----------------------------------------------------------------------
    // API-17 / API-18 — keyset pagination
    // -----------------------------------------------------------------------

    /** API-22 — `next_cursor` is null on the last page and only there. A client pages until it is null. */
    #[Test]
    public function theCursorWalksEveryRowExactlyOnceAndStopsOnNull(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        foreach (['PO-000001', 'PO-000002', 'PO-000003', 'PO-000004', 'PO-000005'] as $number) {
            $this->seedOrder(self::TENANT_A, $number, $supplier, $warehouse);
        }

        $seen = [];
        $pages = 0;
        $cursor = null;

        do {
            $this->send('GET', '/purchase-orders?limit=2'.(null === $cursor ? '' : '&after='.urlencode($cursor)));
            self::assertSame(200, $this->httpStatus());

            $body = $this->json();
            foreach ($body['items'] as $item) {
                $seen[] = $item['order_number'];
            }

            $cursor = $body['next_cursor'];
            ++$pages;
            self::assertLessThan(10, $pages, 'The client walked off the end: next_cursor never went null.');
        } while (null !== $cursor);

        self::assertSame(['PO-000001', 'PO-000002', 'PO-000003', 'PO-000004', 'PO-000005'], $seen);
        self::assertSame(3, $pages);
    }

    /**
     * **API-18 / TB-2a — the cursor is (sort key, id), and a tie group is the
     * case that proves it.** Three orders share one `ordered_at`, so a cursor on
     * the key alone either skips or repeats rows across the page boundary.
     */
    #[Test]
    public function aTieGroupIsPagedWithoutSkippingOrRepeating(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        foreach (['PO-000001', 'PO-000002', 'PO-000003', 'PO-000004'] as $number) {
            $this->seedOrder(self::TENANT_A, $number, $supplier, $warehouse,
                status: PurchaseOrder::STATUS_ORDERED, orderedAt: '2026-01-01T00:00:00+00:00');
        }

        $seen = [];
        $cursor = null;

        do {
            $this->send('GET', '/purchase-orders?status=open&limit=2'.(null === $cursor ? '' : '&after='.urlencode($cursor)));
            $body = $this->json();
            $seen = [...$seen, ...array_column($body['items'], 'order_number')];
            $cursor = $body['next_cursor'];
        } while (null !== $cursor);

        sort($seen);
        self::assertSame(['PO-000001', 'PO-000002', 'PO-000003', 'PO-000004'], $seen,
            'A tie group on ordered_at was skipped or repeated across the page boundary.');
    }

    /** `previous_cursor` is null on the first page and set once a cursor was used. */
    #[Test]
    public function theBackwardCursorReturnsThePreviousPageInTheListsOwnOrder(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        foreach (['PO-000001', 'PO-000002', 'PO-000003', 'PO-000004'] as $number) {
            $this->seedOrder(self::TENANT_A, $number, $supplier, $warehouse);
        }

        $this->send('GET', '/purchase-orders?limit=2');
        self::assertNull($this->json()['previous_cursor'], 'The first page has no predecessor.');
        $next = $this->json()['next_cursor'];

        $this->send('GET', '/purchase-orders?limit=2&after='.urlencode($next));
        $body = $this->json();
        self::assertSame(['PO-000003', 'PO-000004'], array_column($body['items'], 'order_number'));
        self::assertNotNull($body['previous_cursor']);

        $this->send('GET', '/purchase-orders?limit=2&before='.urlencode($body['previous_cursor']));
        self::assertSame(['PO-000001', 'PO-000002'], array_column($this->json()['items'], 'order_number'));
    }

    /** API-18 / P-6 — the cursor carries the sort key and the id, and no tenant identifier. */
    #[Test]
    public function theCursorCarriesTheSortKeyAndTheIdAndNoTenantIdentifier(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $first = $this->seedOrder(self::TENANT_A, 'PO-000001', $supplier, $warehouse);
        $this->seedOrder(self::TENANT_A, 'PO-000002', $supplier, $warehouse);

        $this->send('GET', '/purchase-orders?limit=1');
        $encoded = $this->json()['next_cursor'];

        $decoded = json_decode((string) base64_decode(strtr($encoded, '-_', '+/'), true), true);
        self::assertIsArray($decoded);
        self::assertSame(['k', 'i'], array_keys($decoded));
        self::assertSame('PO-000001', $decoded['k']);
        self::assertSame($first, $decoded['i']);
        self::assertSame([], ItemReadTest::tenantKeysIn($decoded));
        self::assertStringNotContainsString(self::TENANT_A, $encoded);
    }

    /** The cursor's sort key is the **view's** key, so an `ordered_at` page carries a timestamp. */
    #[Test]
    public function theCursorCarriesTheKeyTheViewIsOrderedBy(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $this->seedOrder(self::TENANT_A, 'PO-000001', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED, orderedAt: '2026-01-01T00:00:00+00:00');
        $this->seedOrder(self::TENANT_A, 'PO-000002', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED, orderedAt: '2026-01-02T00:00:00+00:00');

        $this->send('GET', '/purchase-orders?status=open&limit=1');

        $cursor = Cursor::decode($this->json()['next_cursor']);
        self::assertStringStartsWith('2026-01-01', $cursor->sortKey,
            'The cursor carries the order_number while the page is ordered by ordered_at, so the next page '
            .'would seek on the wrong column.');
    }

    /** API-17 / P-3 — offset pagination is forbidden on tenant tables. */
    #[Test]
    public function noListQueryEmitsAnOffset(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        foreach (['PO-000001', 'PO-000002', 'PO-000003'] as $number) {
            $this->seedOrder(self::TENANT_A, $number, $supplier, $warehouse);
        }

        $this->recorder()->start();
        $this->send('GET', '/purchase-orders?limit=2');
        $this->recorder()->stop();

        self::assertNotSame([], $this->recorder()->statements(), 'Nothing was recorded, so this assertion had no subject.');

        foreach ($this->recorder()->statements() as $statement) {
            self::assertDoesNotMatchRegularExpression('/\bOFFSET\b/i', $statement);
        }
    }

    /**
     * API-17's bounds, refused rather than clamped.
     *
     * The `code` is deliberately not asserted. The bound is declared **twice on
     * purpose** — in the parameter's schema and as `PurchaseOrderCollectionProvider`'s
     * own constant, which is what `ItemCollection`'s docblock means by *"the
     * validator and the provider's fallback check cannot disagree about what 200
     * means"* — so which of the two answers is an ordering detail and pinning it
     * would make the fallback unremovable. What the contract fixes is the status
     * and the problem type, and those are asserted.
     */
    #[Test]
    public function aLimitOutsideItsBoundsIsRefused(): void
    {
        $this->seedDraft(self::TENANT_A, 'PO-000001');

        foreach (['0', '201', 'x'] as $limit) {
            $this->send('GET', '/purchase-orders?limit='.$limit);
            self::assertSame(422, $this->httpStatus(), 'limit='.$limit.' was accepted.');
            self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        }
    }

    /** Exactly one of `after` and `before` (API-17). */
    #[Test]
    public function afterAndBeforeAreMutuallyExclusive(): void
    {
        $this->seedDraft(self::TENANT_A, 'PO-000001');

        $this->send('GET', '/purchase-orders?after=x&before=y');

        self::assertSame(422, $this->httpStatus());
        self::assertContains('mutually_exclusive', array_column($this->json()['errors'], 'code'));
    }

    /**
     * ST-2's two empty states, and §4.4.2 uses **this list** to explain why they
     * are not decidable from `items` alone: *"every document list opens on a
     * status segment — `/purchase-orders` opens on Open — so a filter is always
     * set and every empty list would read as filtered. That is the wrong answer
     * on a brand-new tenant."*
     */
    #[Test]
    public function anEmptySegmentIsDistinguishableFromAnEmptyWorkspace(): void
    {
        $this->send('GET', '/purchase-orders?status=open');
        self::assertSame([], $this->json()['items']);
        self::assertFalse($this->json()['tenant_has_rows'], 'A brand-new workspace read as filtered.');

        $this->seedDraft(self::TENANT_A, 'PO-000001');

        $this->send('GET', '/purchase-orders?status=open');
        self::assertSame([], $this->json()['items']);
        self::assertTrue($this->json()['tenant_has_rows'],
            'The workspace has orders and the empty Open segment read as first-run, which would offer '
            .'"raise your first order" to somebody who has one.');
    }

    /** API-22 — the probe runs only on an empty page, and never on one with rows. */
    #[Test]
    public function theEmptyProbeRunsOnlyOnAnEmptyPage(): void
    {
        $this->seedDraft(self::TENANT_A, 'PO-000001');

        $this->recorder()->start();
        $this->send('GET', '/purchase-orders');
        $this->recorder()->stop();

        $probes = array_filter($this->recorder()->statements(),
            static fn (string $sql): bool => 1 === preg_match('/EXISTS \(SELECT 1 FROM purchase_order\)/i', $sql));

        self::assertSame([], $probes, 'The EXISTS probe ran on a page that has rows.');
    }
}
