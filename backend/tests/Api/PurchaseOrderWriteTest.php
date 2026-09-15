<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * `UpdatePurchaseOrder`, `DeletePurchaseOrder` and the three line operations.
 *
 * ---------------------------------------------------------------------------
 * The two rules every test here is about
 * ---------------------------------------------------------------------------
 * **L-5** — *"the detail page is the editor only while `status = 'draft'`. After
 * the first transition the page is read-only except for the transition actions
 * themselves. **The state machine, not a permission, is what closes editing.**"*
 *
 * **L-8** — *"A document is deletable only while `status = 'draft'`."* And L-8
 * says why it cannot be a permission test: `purchasing_manager` holds
 * `purchase_order.delete` **and** `.approve` **and** `.cancel`, so a permission
 * test alone would let it delete a placed order. Disposing of a draft and
 * repudiating a placed commitment are different acts with different permissions,
 * and it is the **status** that decides which is available.
 */
final class PurchaseOrderWriteTest extends PurchaseOrderTestCase
{
    // -----------------------------------------------------------------------
    // `UpdatePurchaseOrder`
    // -----------------------------------------------------------------------

    /** API-21 branch 2 — 200 with the updated resource. */
    #[Test]
    public function updatingADraftReturnsTheUpdatedResource(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $other = $this->seedSupplier(self::TENANT_A, 'Other');

        $this->send('PATCH', '/purchase-orders/'.$draft['order'], [
            'supplier_id' => $other,
            'expected_at' => '2026-04-02T00:00:00+00:00',
        ]);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($other, $this->json()['supplier']['id']);
        self::assertSame('2026-04-02T00:00:00.000+00:00', $this->json()['expected_at']);
        // Untouched by an absent field.
        self::assertSame($draft['warehouse'], $this->json()['warehouse']['id']);
    }

    /**
     * The `PATCH` three-way distinction: absent leaves the field alone, an
     * explicit `null` clears it. Without the uninitialised-property trick the
     * first two collapse and every `PATCH` would clear every field it omitted.
     */
    #[Test]
    public function anExplicitNullClearsTheExpectedDateAndAnAbsentFieldDoesNot(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $order = $this->seedOrder(self::TENANT_A, 'PO-1', $supplier, $warehouse, expectedAt: '2026-03-01T00:00:00+00:00');

        $this->send('PATCH', '/purchase-orders/'.$order, ['supplier_id' => $supplier]);
        self::assertSame('2026-03-01T00:00:00.000+00:00', $this->json()['expected_at'],
            'An absent field cleared the value, so "absent" and "sent as null" are the same case.');

        $this->send('PATCH', '/purchase-orders/'.$order, ['expected_at' => null]);
        self::assertNull($this->json()['expected_at']);
    }

    /** Both parent columns are `NOT NULL`, so neither may be cleared by a `PATCH`. */
    #[Test]
    public function aNotNullParentMayNotBeCleared(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

        $this->send('PATCH', '/purchase-orders/'.$draft['order'], ['supplier_id' => null]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('not_nullable', $this->json()['errors'][0]['code']);
        self::assertSame('/supplier_id', $this->json()['errors'][0]['pointer']);
    }

    /**
     * **ST-5's oracle.** A supplier id belonging to another tenant is refused as
     * a *field* defect, not as a `not-found` on a row the caller never addressed
     * — under RLS the application cannot tell it from an id that never existed,
     * and answering 404 would say the order is gone.
     */
    #[Test]
    public function aParentIdFromAnotherTenantIsAFieldDefectAndNotANotFound(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $inB = $this->seedSupplier(self::TENANT_B, 'Other');

        $this->send('PATCH', '/purchase-orders/'.$draft['order'], ['supplier_id' => $inB]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        self::assertContains('unknown_reference', array_column($this->json()['errors'], 'code'));
    }

    /**
     * **An edit may not point a draft at an archived supplier or warehouse** —
     * five-calls follow-up review, 2026-09-13. `CreatePurchaseOrder` refuses both
     * with `unknown_reference`; `UpdatePurchaseOrder` checked the shape only and
     * left the rest to the composite foreign key, which an archived row still
     * satisfies. So a draft could be moved onto a closed site, or a supplier the
     * workspace had retired, as a 200. Approval re-checks the warehouse
     * (`API.md` §8.1 step 6); nothing re-checks the supplier.
     *
     * The rejected id is asserted to be refused *as the create refuses it* — the
     * field named, `unknown_reference` — and the order is asserted to still point
     * where it did.
     */
    #[Test]
    #[DataProvider('editableParents')]
    public function anEditMayNotPointADraftAtAnArchivedParent(string $field, string $table): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $archived = 'warehouse' === $table
            ? $this->seedWarehouse(self::TENANT_A, 'Closed')
            : $this->seedSupplier(self::TENANT_A, 'Retired');
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE $table SET deleted_at = now() WHERE id = ?::uuid", [$archived]);

        $this->send('PATCH', '/purchase-orders/'.$draft['order'], [$field => $archived]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        self::assertSame(['/'.$field => 'unknown_reference'], array_column($this->json()['errors'], 'code', 'pointer'));

        $this->send('GET', '/purchase-orders/'.$draft['order']);
        self::assertSame($draft[$table], $this->json()[$table]['id'], 'The refused edit still moved the order.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function editableParents(): iterable
    {
        yield 'warehouse' => ['warehouse_id', 'warehouse'];
        yield 'supplier' => ['supplier_id', 'supplier'];
    }

    /**
     * **L-5.** *"From here the document is read-only except for its
     * transitions."*
     *
     * *Mutation, run:* `refuseUnlessDraft()`'s body replaced by `return;`. Red
     * here, in `aPlacedOrderCannotBeDeleted` and in all three line tests — five
     * checks on one rule, which is what the rule being in one place buys.
     */
    #[Test]
    public function aPlacedOrderCannotBeEdited(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $order = $this->seedOrder(self::TENANT_A, 'PO-1', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED);

        $this->send('PATCH', '/purchase-orders/'.$order, ['expected_at' => '2026-04-02T00:00:00+00:00']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('purchase_order.is_editable_only_while_draft', $this->json()['rule']);
    }

    /** ST-9 / §5.2a — an archived row renders read-only, and the refusal is the business-rule branch. */
    #[Test]
    public function anArchivedOrderCannotBeEdited(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $order = $this->seedOrder(self::TENANT_A, 'PO-1', $supplier, $warehouse, archived: true);

        $this->send('PATCH', '/purchase-orders/'.$order, ['expected_at' => '2026-04-02T00:00:00+00:00']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order.is_archived', $this->json()['rule']);
    }

    /** API-9 / B-3 — an undeclared field is refused, and `status` is the tempting one. */
    #[Test]
    public function anUndeclaredFieldIsRefused(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

        foreach ([['status' => 'ordered'], ['id' => Uuid::v4()->toRfc4122()], ['currency_code' => 'USD']] as $body) {
            $this->send('PATCH', '/purchase-orders/'.$draft['order'], $body);

            self::assertSame(422, $this->httpStatus(), json_encode($body).' was accepted.');
            self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        }

        // And nothing moved.
        $this->send('GET', '/purchase-orders/'.$draft['order']);
        self::assertSame(PurchaseOrder::STATUS_DRAFT, $this->json()['status']);
    }

    // -----------------------------------------------------------------------
    // `DeletePurchaseOrder`
    // -----------------------------------------------------------------------

    /**
     * API-12 / API-21 branch 3 — 204, no body, and `deleted_at` set. And the
     * database's `purchase_order_cascade_deletion` trigger takes the lines with
     * it: *"an aggregate whose root is deleted and whose lines are not is the
     * defect this column exists to close"*.
     */
    #[Test]
    public function deletingADraftArchivesItAndItsLines(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $this->send('DELETE', '/purchase-orders/'.$draft['order']);

        self::assertSame(204, $this->httpStatus());
        self::assertSame('', (string) $this->client()->getResponse()->getContent());

        // §5.2a: the row is still readable by direct hit, with its archive stamp.
        $this->send('GET', '/purchase-orders/'.$draft['order']);
        self::assertSame(200, $this->httpStatus());
        self::assertNotNull($this->json()['archived_at']);

        $this->send('GET', '/purchase-orders/'.$draft['order'].'/lines?archived=true');
        self::assertCount(1, $this->json()['items'], 'The cascade trigger did not take the line with the order.');
    }

    /**
     * **L-8, and it is the rule that keeps `.delete` and `.cancel` disjoint.**
     *
     * The acting principal holds every declared code — including
     * `purchase_order.delete` — so a 409 here is the *status* refusing and not
     * the permission set.
     */
    #[Test]
    public function aPlacedOrderCannotBeDeleted(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $order = $this->seedOrder(self::TENANT_A, 'PO-1', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED);

        $this->send('DELETE', '/purchase-orders/'.$order);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order.is_editable_only_while_draft', $this->json()['rule']);

        // And the row is untouched: a placed order leaves through the Cancelled
        // segment, which is `CancelPurchaseOrder`'s and does not exist yet.
        $this->send('GET', '/purchase-orders/'.$order);
        self::assertNull($this->json()['archived_at']);
    }

    /** Deleting twice is refused: the second call is told the first committed. */
    #[Test]
    public function deletingAnArchivedOrderIsRefused(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

        $this->send('DELETE', '/purchase-orders/'.$draft['order']);
        self::assertSame(204, $this->httpStatus());

        $this->send('DELETE', '/purchase-orders/'.$draft['order']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order.is_archived', $this->json()['rule']);
    }

    // -----------------------------------------------------------------------
    // `CreatePurchaseOrderLine`
    // -----------------------------------------------------------------------

    /**
     * **API-21 branch 4 — 201, with a `Location` naming the row-level URI §4.2
     * declares for the created row, and the created resource as the body.**
     *
     * *Mutation, run:* the `CreatedRowLocation::OPERATION_URI_TEMPLATE` extra
     * property removed from the operation. The header reverts to API Platform's
     * own derivation, `/api/v1/purchase_order_lines/<uuid>` — a path that answers
     * 404 — and this is red. It is the defect that motivated the listener, and
     * measured before it existed.
     */
    #[Test]
    public function creatingALineAnswers201WithALocationNamingIt(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $draft['item'], 9);

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/lines', [
            'inventory_item_id' => $draft['item'],
            'quantity' => '3.500000',
            'unit_cost' => '4.5000',
        ]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $body = $this->json();
        self::assertSame('3.500000', $body['quantity']);
        self::assertSame('4.5000', $body['unit_cost']);
        self::assertSame($draft['item'], $body['inventory_item']['id']);
        self::assertSame('0.000000', $body['quantity_received']);
        self::assertSame('3.500000', $body['quantity_outstanding']);

        self::assertSame(
            self::BASE.'/purchase-orders/'.$draft['order'].'/lines/'.$body['id'],
            $this->client()->getResponse()->headers->get('Location'),
        );
    }

    /**
     * **F-2 step 3 — the lead time is snapshotted onto the line from the
     * supplier's catalogue, not referenced.** `lead_time_days` is `NOT NULL`,
     * §4.2 accepts no field for it, and `supplier_product` is deletion class
     * `hard` *"precisely so current terms can change without rewriting a placed
     * order"*.
     *
     * Read back from the table because the column is not a field of the read
     * shape — §4.4.3's derivation does not produce it — so the response cannot
     * be the evidence.
     */
    #[Test]
    public function theLineSnapshotsTheSuppliersLeadTime(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $draft['item'], 21);

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/lines', [
            'inventory_item_id' => $draft['item'],
            'quantity' => '1',
            'unit_cost' => '1',
        ]);
        self::assertSame(201, $this->httpStatus());

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT lead_time_days, line_number FROM purchase_order_line WHERE id = ?::uuid', [$this->json()['id']]);

        self::assertSame(21, (int) $rows[0]['lead_time_days']);
        self::assertSame(1, (int) $rows[0]['line_number'], 'The first line on an order is line 1.');
    }

    /**
     * An item the order's supplier does not list has **no lead time to
     * snapshot**, and there is no field for the caller to supply one. It is a
     * rule about this order and this item — the supplier comes from the order,
     * not from the request — so E-2's second case rather than a field defect.
     */
    #[Test]
    public function anItemTheSupplierDoesNotListIsRefused(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/lines', [
            'inventory_item_id' => $draft['item'],
            'quantity' => '1',
            'unit_cost' => '1',
        ]);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order_line.supplier_lists_the_item', $this->json()['rule']);
    }

    /** `line_number` is minted server-side and is the next free number on this order. */
    #[Test]
    public function lineNumbersAreMintedInSequence(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $draft['item']);

        $ids = [];
        foreach (range(1, 3) as $ignored) {
            $this->send('POST', '/purchase-orders/'.$draft['order'].'/lines', [
                'inventory_item_id' => $draft['item'],
                'quantity' => '1',
                'unit_cost' => '1',
            ]);
            self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $ids[] = $this->json()['id'];
        }

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT line_number FROM purchase_order_line ORDER BY line_number');

        self::assertSame([1, 2, 3], array_map(intval(...), array_column($rows, 'line_number')));
        self::assertCount(3, array_unique($ids));
    }

    /**
     * `purchase_order_line_qty_sane` is `quantity_ordered > 0`. Refused here with
     * the field named rather than at the database, because a `CHECK` violation is
     * a 500 with no pointer and §5.2 wants the field.
     */
    #[Test]
    public function aNonPositiveOrMalformedQuantityIsRefusedWithTheFieldNamed(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $draft['item']);

        foreach (['0', '0.000000', '-1', 'x', '1.1234567'] as $quantity) {
            $this->send('POST', '/purchase-orders/'.$draft['order'].'/lines', [
                'inventory_item_id' => $draft['item'],
                'quantity' => $quantity,
                'unit_cost' => '1',
            ]);

            self::assertSame(422, $this->httpStatus(), 'quantity='.$quantity.' was accepted.');
            self::assertSame('/quantity', $this->json()['errors'][0]['pointer']);
        }
    }

    /** An item id from another tenant is a field defect under ST-5, exactly as a supplier id is. */
    #[Test]
    public function anItemFromAnotherTenantIsAFieldDefect(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $itemInB = $this->seedItem(self::TENANT_B, 'SKU-B');

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/lines', [
            'inventory_item_id' => $itemInB,
            'quantity' => '1',
            'unit_cost' => '1',
        ]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('/inventory_item_id', $this->json()['errors'][0]['pointer']);
        self::assertSame('unknown_reference', $this->json()['errors'][0]['code']);
    }

    /** L-5 again, on the child: a placed order takes no new lines. */
    #[Test]
    public function aPlacedOrderTakesNoNewLine(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $draft['item']);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "UPDATE purchase_order SET status = 'ordered', ordered_at = now() WHERE id = ?::uuid", [$draft['order']]);

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/lines', [
            'inventory_item_id' => $draft['item'],
            'quantity' => '1',
            'unit_cost' => '1',
        ]);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order.is_editable_only_while_draft', $this->json()['rule']);
    }

    /**
     * **A purchase order line has no expected date (owner ruling Q2,
     * 2026-09-15), so `expected_at` on a line is refused as undeclared.**
     *
     * §4.2 no longer declares it on this operation. A client sending it is told,
     * loudly, rather than having the value silently discarded; the order's own
     * `expected_at` is the one date.
     */
    #[Test]
    public function aLineExpectedDateIsRefusedBecauseThereIsNowhereToPutIt(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $draft['item']);

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/lines', [
            'inventory_item_id' => $draft['item'],
            'quantity' => '1',
            'unit_cost' => '1',
            'expected_at' => '2026-04-02T00:00:00+00:00',
        ]);

        self::assertSame(422, $this->httpStatus(),
            'expected_at was accepted on a line. §4.2 does not declare it (owner ruling Q2, 2026-09-15), so accepting it '
            .'means discarding it -- and a field a client believes it set is worse than one it was refused.');
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
    }

    // -----------------------------------------------------------------------
    // `UpdatePurchaseOrderLine` and `DeletePurchaseOrderLine`
    // -----------------------------------------------------------------------

    #[Test]
    public function updatingALineReturnsTheUpdatedResource(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $this->send('PATCH', '/purchase-orders/'.$draft['order'].'/lines/'.$line, ['quantity' => '7.000000']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('7.000000', $this->json()['quantity']);
        self::assertSame('2.5000', $this->json()['unit_cost'], 'An absent field was changed.');
    }

    /**
     * §5.3 clause 2 applies to the **pair**: a `lineId` naming a line of a
     * different order is `not-found`, not an edit of somebody else's line.
     *
     * ---------------------------------------------------------------------
     * ⚠ Two sites carry this predicate and they MASK EACH OTHER. Measured.
     * ---------------------------------------------------------------------
     * `PurchaseOrderReader::getLine()` (behind `PurchaseOrderLineProvider`) and
     * `PurchaseOrderWriter::readLineInside()` both address the row by the pair,
     * and the second runs whatever the first did. **Mutating either one alone
     * left this test green** — measured, not assumed — and only the pair mutated
     * together is red.
     *
     * The third assertion is what makes the **provider's** half falsifiable on
     * its own. A `PATCH` resolves its row *before* the body is deserialized, so
     * a wrong-order `lineId` carrying an undeclared field must answer **404 and
     * not 422**: the provider decided before the deserializer ever saw the body.
     * That is `ItemResource`'s stated reason for wiring a provider onto a
     * `PATCH` at all — *"a `PATCH` at an id no row answers is `not-found`
     * before the body is even parsed"* — and with the provider's predicate
     * dropped it becomes a 422.
     *
     * **The writer's half stays unfalsifiable alone and that is stated rather
     * than claimed as covered.** There is no operation that reaches the writer
     * without passing the provider, so its predicate is defence against a future
     * change to the provider and nothing observable turns on it today.
     */
    #[Test]
    public function aLineOfAnotherOrderIsNotFoundAtThisOrdersAddress(): void
    {
        $first = $this->seedDraft(self::TENANT_A, 'PO-1');
        $second = $this->seedDraft(self::TENANT_A, 'PO-2');
        $lineOfSecond = $this->seedLine(self::TENANT_A, $second['order'], $second['item']);

        $this->send('PATCH', '/purchase-orders/'.$first['order'].'/lines/'.$lineOfSecond, ['quantity' => '7']);
        self::assertSame(404, $this->httpStatus());

        $this->send('DELETE', '/purchase-orders/'.$first['order'].'/lines/'.$lineOfSecond);
        self::assertSame(404, $this->httpStatus());

        // The row is resolved before the body is parsed, so this is 404 and not
        // the 422 an undeclared field would otherwise earn.
        $this->send('PATCH', '/purchase-orders/'.$first['order'].'/lines/'.$lineOfSecond, ['bogus' => 1]);
        self::assertSame(404, $this->httpStatus(),
            'The undeclared field was refused before the row was resolved, so the line was found at an '
            .'address that does not name it.');

        // And it is untouched at its own address.
        $this->send('GET', '/purchase-orders/'.$second['order'].'/lines');
        self::assertSame('10.000000', $this->json()['items'][0]['quantity']);
    }

    /** API-21 branch 3 — 204, no body, and the line leaves the live sub-list. */
    #[Test]
    public function deletingALineArchivesIt(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $this->send('DELETE', '/purchase-orders/'.$draft['order'].'/lines/'.$line);

        self::assertSame(204, $this->httpStatus());
        self::assertSame('', (string) $this->client()->getResponse()->getContent());

        $this->send('GET', '/purchase-orders/'.$draft['order'].'/lines');
        self::assertSame([], $this->json()['items']);

        $this->send('GET', '/purchase-orders/'.$draft['order'].'/lines?archived=true');
        self::assertSame([$line], array_column($this->json()['items'], 'id'));
    }

    /**
     * *"A discarded line number can be reused; a live one cannot be taken
     * twice"* — `purchase_order_line_unique` is partial on `deleted_at IS NULL`,
     * and the server's `MAX(line_number) + 1` is over the same live set.
     */
    #[Test]
    public function anArchivedLineNumberIsReused(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $draft['item']);
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $this->send('DELETE', '/purchase-orders/'.$draft['order'].'/lines/'.$line);
        self::assertSame(204, $this->httpStatus());

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/lines', [
            'inventory_item_id' => $draft['item'],
            'quantity' => '1',
            'unit_cost' => '1',
        ]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT line_number FROM purchase_order_line WHERE deleted_at IS NULL');
        self::assertSame([1], array_map(intval(...), array_column($rows, 'line_number')));
    }

    /** L-5 on the two line writes as well. */
    #[Test]
    public function aPlacedOrdersLinesAreReadOnly(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "UPDATE purchase_order SET status = 'ordered', ordered_at = now() WHERE id = ?::uuid", [$draft['order']]);

        $this->send('PATCH', '/purchase-orders/'.$draft['order'].'/lines/'.$line, ['quantity' => '7']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order.is_editable_only_while_draft', $this->json()['rule']);

        $this->send('DELETE', '/purchase-orders/'.$draft['order'].'/lines/'.$line);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order.is_editable_only_while_draft', $this->json()['rule']);
    }

    // -----------------------------------------------------------------------
    // `total_amount`
    // -----------------------------------------------------------------------

    /**
     * ⚠ **`total_amount` is recomputed on every line write, and no document says
     * who maintains it.** §4.4.3 declares it a field of the read shape and the
     * column defaults to `0`, so left alone it would report zero on an order with
     * lines — a number the screen would show and the database would not have
     * earned. **This is a decision taken in `PurchaseOrderWriter` and reported as
     * one, not a derivation.**
     */
    #[Test]
    public function theOrderTotalFollowsItsLiveLines(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $draft['item']);

        $this->send('GET', '/purchase-orders/'.$draft['order']);
        self::assertSame('0.0000', $this->json()['total_amount']);

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/lines', [
            'inventory_item_id' => $draft['item'], 'quantity' => '3', 'unit_cost' => '4.5000',
        ]);
        $line = $this->json()['id'];

        $this->send('GET', '/purchase-orders/'.$draft['order']);
        self::assertSame('13.5000', $this->json()['total_amount']);

        $this->send('PATCH', '/purchase-orders/'.$draft['order'].'/lines/'.$line, ['quantity' => '4']);
        $this->send('GET', '/purchase-orders/'.$draft['order']);
        self::assertSame('18.0000', $this->json()['total_amount']);

        $this->send('DELETE', '/purchase-orders/'.$draft['order'].'/lines/'.$line);
        $this->send('GET', '/purchase-orders/'.$draft['order']);
        self::assertSame('0.0000', $this->json()['total_amount'],
            'An archived line still counts toward the order total.');
    }
}
