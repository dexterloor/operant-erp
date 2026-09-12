<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * `GetPurchaseOrder` and `ListPurchaseOrderLines` — §4.4.3's declared field
 * lists, §5.3's refusals, and §5.2a's carve-out.
 */
final class PurchaseOrderReadTest extends PurchaseOrderTestCase
{
    /**
     * **§4.4.3's derivation for `PurchaseOrder`, asserted as an exact key set.**
     *
     * Steps 1–3 give `supplier`, `warehouse`, `currency_code`, `expected_at`,
     * `id` and `archived_at`; the additions row gives `order_number`, `status`,
     * `total_amount`, `ordered_at` and `created_by`. *"And nothing else"* is why
     * this is `assertSame` on `array_keys` rather than a set of
     * `assertArrayHasKey`s: a field that is neither derivable nor listed is the
     * defect, and only an exact comparison finds it.
     */
    #[Test]
    public function theResourceCarriesExactlyItsDerivedFieldList(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

        $this->send('GET', '/purchase-orders/'.$draft['order']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame([
            'id', 'supplier', 'warehouse', 'currency_code', 'expected_at',
            'order_number', 'status', 'total_amount', 'ordered_at', 'created_by', 'archived_at',
        ], array_keys($this->json()));
    }

    /**
     * **API-23b — a reference is expanded as `{id, <display field>}` and never as
     * a bare uuid, and an expansion carries the id and the display field only.**
     *
     * N-3 is what this closes: revision 2 of §4.4.3 expanded some references and
     * left others as raw `_id` fields, so `/purchase-orders` would render a
     * supplier uuid while `/stock` rendered an item name. A uuid on screen forces
     * the client into an N+1 of lookups it may not be permitted to make.
     *
     * The "only" half is what keeps PC-12 out: expanding a supplier discloses its
     * **name**, which `purchase_order.view` already implies — a document names
     * who it is addressed to — and never `payment_terms_days` or anything else
     * `supplier.view` and `supplier_product.view` gate.
     */
    #[Test]
    public function everyReferenceIsExpandedAndCarriesNothingButItsDisplayField(): void
    {
        $buyer = $this->seedPrincipal(self::TENANT_A, []);
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', $buyer);

        $this->send('GET', '/purchase-orders/'.$draft['order']);
        $body = $this->json();

        self::assertSame(['id', 'name'], array_keys($body['supplier']));
        self::assertSame('Acme PO-1', $body['supplier']['name']);
        self::assertSame(['id', 'name'], array_keys($body['warehouse']));

        // §4.4.3 names this expansion's display field explicitly, and it is not
        // `name`: `app_user` has no such column.
        self::assertSame(['id', 'display_name'], array_keys($body['created_by']));
        self::assertSame($buyer, $body['created_by']['id']);

        // No member of any response, at any depth, is named `tenant_id`
        // (API-6, P-6, T-1).
        self::assertSame([], ItemReadTest::tenantKeysIn($body));
    }

    /** `created_by` is null on an order the reorder agent raised, and that is DSoD-1's third case. */
    #[Test]
    public function anOrderWithNoHumanAuthorRendersANullCreator(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

        $this->send('GET', '/purchase-orders/'.$draft['order']);

        self::assertNull($this->json()['created_by']);
    }

    /**
     * §5.3 clause 2 — a row RLS does not return is `not-found`, never
     * `permission-denied`, and all three of "another tenant's", "never existed"
     * and "not a uuid at all" answer **identically**. A 403 on a cross-tenant id
     * confirms the id exists somewhere (ST-5).
     */
    #[Test]
    public function everyInvisibleIdGetsTheSameNotFound(): void
    {
        $inB = $this->seedPrincipal(self::TENANT_B, []);
        $supplierB = $this->seedSupplier(self::TENANT_B, 'Other');
        $warehouseB = $this->seedWarehouse(self::TENANT_B, 'Other');
        $orderInB = $this->seedOrder(self::TENANT_B, 'PO-B', $supplierB, $warehouseB, $inB);

        $bodies = [];

        foreach ([$orderInB, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->send('GET', '/purchase-orders/'.$id);
            self::assertSame(404, $this->httpStatus(), $id.' did not answer 404.');
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }

        self::assertSame([$bodies[0], $bodies[0], $bodies[0]], $bodies);
    }

    /**
     * **API-13a / §5.2a — a direct hit on a soft-deleted row inside the caller's
     * own tenant returns 200 with a non-null `archived_at`, never 404.**
     *
     * ST-9: *"Never a 404 — documents legitimately reference it, which is the
     * entire reason it is soft-deleted."* On this table the reference is real: a
     * `goods_receipt` points at the order it received against.
     *
     * *Mutation, run:* `AND po.deleted_at IS NULL` added to
     * `PurchaseOrderReader::get()`'s statement. Red here with a 404, and green in
     * every list test — which is the pair §5.2a's second mutation names.
     */
    #[Test]
    public function anArchivedOrderIsReadableByDirectHit(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $order = $this->seedOrder(self::TENANT_A, 'PO-1', $supplier, $warehouse, archived: true);

        $this->send('GET', '/purchase-orders/'.$order);

        self::assertSame(200, $this->httpStatus());
        self::assertNotNull($this->json()['archived_at']);
    }

    // -----------------------------------------------------------------------
    // `ListPurchaseOrderLines`
    // -----------------------------------------------------------------------

    /** §4.4.3's derivation for `PurchaseOrderLine`, asserted as an exact key set. */
    #[Test]
    public function theLineCarriesExactlyItsDerivedFieldList(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $this->send('GET', '/purchase-orders/'.$draft['order'].'/lines');

        self::assertSame(200, $this->httpStatus());
        self::assertSame([
            'id', 'inventory_item', 'quantity', 'unit_cost',
            'quantity_received', 'quantity_outstanding', 'archived_at',
        ], array_keys($this->json()['items'][0]));
    }

    /**
     * §4.4.3: *"F-3 step 2 pre-fills every outstanding line with its
     * **outstanding** quantity, not its ordered quantity, and a client that has
     * to subtract two numbers it was given will eventually subtract the wrong
     * two."*
     *
     * ⚠ **Renamed 2026-09-12, because the old name outlived what the body could
     * show.** It was `…AndNeverNegative`, and at step 8 the fixture it proved
     * that with — 8 received of 5 ordered — became a row the database refuses,
     * so the body was changed to assert the refusal instead. The name was not,
     * and what was left asserted non-negativity of an expression that cannot be
     * negative. **Measured, not inferred:** with the fixture gone, deleting
     * `GREATEST(…, 0)` from `PurchaseOrderLineRow` left this test and the whole
     * suite green, and so did deleting the `::numeric(19,6)` cast that existed
     * only to undo `GREATEST`'s scale-0 literal. Both are therefore deleted
     * rather than kept behind a comment, and the name now says what is actually
     * checked.
     *
     * **The three things this does check**, each of which fails on its own: the
     * subtraction (`10 − 4 = 6`); that it renders at **scale 6** on both a
     * partly received line and one received in full, which is what S-12 makes a
     * client compare on and is all that pins the column's shape now the cast is
     * gone; and that `purchase_order_line_not_over_received` refuses the
     * over-received row, which is what makes the result non-negative. The last
     * is asserted here rather than assumed because it is this read's
     * precondition, and `GoodsReceiptCreateTest` asserts it again from the
     * write side.
     */
    #[Test]
    public function theOutstandingQuantityIsOrderedMinusReceivedAtScaleSixAndTheDatabaseKeepsItNonNegative(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item'], 1, '10.000000', '2.5000', '4.000000');
        // Received in full: the boundary, and the scale the clamp's cast pins.
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item'], 2, '5.000000', '2.5000', '5.000000');

        $this->send('GET', '/purchase-orders/'.$draft['order'].'/lines');
        $items = $this->json()['items'];

        self::assertSame('6.000000', $items[0]['quantity_outstanding']);
        self::assertSame('0.000000', $items[1]['quantity_outstanding'],
            'A line received in full did not report exactly zero outstanding, which F-3 step 2 would pre-fill.');

        // The state the old fixture described is one the database now refuses.
        try {
            $this->seedLine(self::TENANT_A, $draft['order'], $draft['item'], 3, '5.000000', '2.5000', '8.000000');
            self::fail('An over-received order line was written; nothing now stops a negative outstanding quantity but the read clamp.');
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            self::assertSame('23514', $e->getSQLState(), $e->getMessage());
            self::assertStringContainsString('purchase_order_line_not_over_received', $e->getMessage());
        }
    }

    /**
     * **§4.4.2 — a `(bounded)` list carries both cursors null *always*, because
     * the whole set is in `items`.** It is the same envelope, not a second shape.
     */
    #[Test]
    public function theBoundedSubListReturnsTheWholeSetInOneEnvelope(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

        foreach (range(1, 5) as $number) {
            $this->seedLine(self::TENANT_A, $draft['order'], $draft['item'], $number);
        }

        $this->send('GET', '/purchase-orders/'.$draft['order'].'/lines');

        $body = $this->json();
        self::assertSame(['items', 'next_cursor', 'previous_cursor', 'tenant_has_rows'], array_keys($body));
        self::assertCount(5, $body['items']);
        self::assertNull($body['next_cursor']);
        self::assertNull($body['previous_cursor']);
    }

    /** §10.2 exclusion 2 and §10.3 step 4: a bounded list declares no paging parameter, so it refuses one. */
    #[Test]
    public function theBoundedSubListRefusesAPagingParameter(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

        foreach (['limit=2', 'after=x', 'sort=line_number'] as $parameter) {
            $this->send('GET', '/purchase-orders/'.$draft['order'].'/lines?'.$parameter);

            self::assertSame(422, $this->httpStatus(), $parameter.' was accepted on a bounded list.');
            self::assertSame('undeclared_parameter', $this->json()['errors'][0]['code']);
        }
    }

    /**
     * **API-19c limb 2 — `archived` selects *which* whole set is returned.** TB-3
     * still holds in the default direction: a live list never returns a
     * soft-deleted row.
     */
    #[Test]
    public function archivedSelectsTheArchivedLinesAndTheDefaultReturnsTheLiveOnes(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $live = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item'], 1);
        $gone = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item'], 2);

        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE purchase_order_line SET deleted_at = now() WHERE id = ?::uuid', [$gone]);

        $this->send('GET', '/purchase-orders/'.$draft['order'].'/lines');
        self::assertSame([$live], array_column($this->json()['items'], 'id'), 'TB-3: a live list returned an archived row.');

        $this->send('GET', '/purchase-orders/'.$draft['order'].'/lines?archived=true');
        self::assertSame([$gone], array_column($this->json()['items'], 'id'));
        self::assertNotNull($this->json()['items'][0]['archived_at']);
    }

    /** API-19's *"refuse, never ignore"* applied to a boolean: `?archived=maybe` must not silently mean false. */
    #[Test]
    public function anUnparseableArchivedValueIsRefusedRatherThanCoerced(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

        $this->send('GET', '/purchase-orders/'.$draft['order'].'/lines?archived=maybe');

        self::assertSame(422, $this->httpStatus());
        self::assertSame('not_a_boolean', $this->json()['errors'][0]['code']);
    }

    /**
     * §5.3 clause 2 over the parent: a sub-list of an order the caller cannot see
     * is the same `not-found` a direct hit on that order gets, so the sub-list is
     * not an existence oracle for its parent.
     */
    #[Test]
    public function theSubListOfAnInvisibleOrderIsNotFound(): void
    {
        $inB = $this->seedPrincipal(self::TENANT_B, []);
        $supplierB = $this->seedSupplier(self::TENANT_B, 'Other');
        $warehouseB = $this->seedWarehouse(self::TENANT_B, 'Other');
        $orderInB = $this->seedOrder(self::TENANT_B, 'PO-B', $supplierB, $warehouseB, $inB);

        $this->send('GET', '/purchase-orders/'.$orderInB.'/lines');
        $foreign = (string) $this->client()->getResponse()->getContent();
        self::assertSame(404, $this->httpStatus());

        $this->send('GET', '/purchase-orders/'.Uuid::v4()->toRfc4122().'/lines');
        self::assertSame(404, $this->httpStatus());
        self::assertSame($foreign, (string) $this->client()->getResponse()->getContent());
    }

    /**
     * ST-2's discriminator on an empty sub-list. API-22 fixes the probe as an
     * `EXISTS` over **the resource's table** with no filter, so it answers *"does
     * this workspace have any lines at all"* — which is what distinguishes a
     * brand-new workspace from an order somebody emptied.
     */
    #[Test]
    public function anEmptySubListReportsWhetherTheWorkspaceHasAnyLinesAtAll(): void
    {
        $first = $this->seedDraft(self::TENANT_A, 'PO-1');

        $this->send('GET', '/purchase-orders/'.$first['order'].'/lines');
        self::assertSame([], $this->json()['items']);
        self::assertFalse($this->json()['tenant_has_rows']);

        $second = $this->seedDraft(self::TENANT_A, 'PO-2');
        $this->seedLine(self::TENANT_A, $second['order'], $second['item']);

        $this->send('GET', '/purchase-orders/'.$first['order'].'/lines');
        self::assertSame([], $this->json()['items']);
        self::assertTrue($this->json()['tenant_has_rows'],
            'The workspace has lines and this empty sub-list reported first-run, which is ST-2\'s wrong empty state.');
    }

    /** P-7: nothing the reader returns can issue a query, so the response serializes after the commit. */
    #[Test]
    public function serializingTheResponseIssuesNoQueryAfterTheTransactionHasClosed(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $reader = self::getContainer()->get(\App\Modules\Purchasing\Application\PurchaseOrderReader::class);
        \assert($reader instanceof \App\Modules\Purchasing\Application\PurchaseOrderReader);
        $normalizer = self::getContainer()->get('serializer');
        \assert($normalizer instanceof \Symfony\Component\Serializer\Normalizer\NormalizerInterface);

        $order = $reader->get($draft['order']);
        $lines = $reader->listLines($draft['order'], false);

        self::assertNotNull($order);
        self::assertNotNull($lines);
        self::assertNotSame([], $lines->items, 'Nothing was hydrated, so the assertion below would pass over an empty object.');

        // The instrument, proven live in the same window shape it is about to be
        // trusted in -- without this, an unwired recorder reports zero statements
        // and the assertion below is the comfortable green API-10a names.
        $this->recorder()->start();
        $this->app()->fetchOne('SELECT 1');
        $this->recorder()->stop();
        self::assertCount(1, $this->recorder()->statements(), 'The SQL recorder is not wired.');

        // `'iri' => false` on both, and it is about the **instrument** rather
        // than about the claim. `AbstractItemNormalizer` opens by resolving an
        // IRI for whatever it is handed, and neither of these two DTOs has one:
        // `PurchaseOrderLineCollection` and `PurchaseOrderLineResource` are both
        // addressed at `/purchase-orders/{id}/lines…`, so their URI needs the
        // parent order's id and the object does not carry it (§4.4.3's
        // derivation removes an `_id` naming a parent the path implies). Every
        // request path supplies the IRI from the route it matched — measured:
        // the HTTP list, the HTTP create and the HTTP read all serialize — so
        // this is a call nothing in the application makes.
        //
        // What matters here is that **IRI resolution is not a database access**,
        // so suppressing it removes an unrelated vendor path and leaves the
        // claim intact: the recorder below is watching the property reads.
        $this->recorder()->start();
        $normalized = [
            $normalizer->normalize($order, 'json', ['iri' => false]),
            $normalizer->normalize($lines, 'json', ['iri' => false]),
        ];
        $this->recorder()->stop();

        self::assertSame([], $this->recorder()->statements(), 'Serialization reached the database.');
        self::assertSame('Acme PO-1', $normalized[0]['supplier']['name'], 'The expansion was not hydrated inside the transaction.');
    }

    /** The reader leaves nothing holding the pooled backend (ADR-002 A1.7). */
    #[Test]
    public function theTenancyTransactionIsClosedBeforeTheReaderReturns(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

        $reader = self::getContainer()->get(\App\Modules\Purchasing\Application\PurchaseOrderReader::class);
        \assert($reader instanceof \App\Modules\Purchasing\Application\PurchaseOrderReader);
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        \assert($em instanceof \Doctrine\ORM\EntityManagerInterface);

        $reader->get($draft['order']);

        self::assertSame(0, $em->getConnection()->getTransactionNestingLevel());
    }

    /** The status vocabulary on the wire is the entity's own, not a second spelling. */
    #[Test]
    public function theStatusOnTheWireIsTheEntitysOwnConstant(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $order = $this->seedOrder(self::TENANT_A, 'PO-1', $supplier, $warehouse, status: PurchaseOrder::STATUS_PARTIALLY_RECEIVED);

        $this->send('GET', '/purchase-orders/'.$order);

        self::assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $this->json()['status']);
    }
}
