<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Output 9, sales slice 1 — `ConfirmSalesOrder` over HTTP, and V2-R-22's ruling as cases.**
 *
 * Half 1 (a zero-line order cannot be confirmed) and Half 2 (no reservation is written) are both
 * asserted here against what the API answers and what the database holds afterwards, rather than
 * against what the code looks like.
 *
 * ⚠ **It extends `PurchaseOrderTestCase` for `SalesOrderCreateTest`'s reason** — the customer, address,
 * warehouse and item fixtures live there because `OperationSuccessRecipes` is
 * `@phpstan-require-extends PurchaseOrderTestCase` and the sales recipes have to live inside that trait
 * (RED F-12). A sales rig of its own would give the two paths two sets of fixtures and nothing to keep
 * them equal.
 */
final class SalesOrderConfirmTest extends PurchaseOrderTestCase
{
    /**
     * **API-21 branch 5: 200, the resource in its NEW state, and no `Location`.** A confirmation creates
     * no row and has no row-level URI to name beyond the one the caller is already on, so F-5 step 3
     * renders its confirmation from the response rather than by refetching.
     *
     * The body is compared key for key with `GetSalesOrder`'s derived field list, because both are built
     * by `SalesOrderRow` and a confirm that assembled its own answer would drift the first time a column
     * moved.
     */
    #[Test]
    public function aConfirmationAnswers200WithTheOrderInItsNewState(): void
    {
        $order = $this->raiseWithALine();

        $this->send('POST', '/sales-orders/'.$order.'/confirmation');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNull($this->client()->getResponse()->headers->get('Location'),
            'A transition that creates no row must not name one.');

        $body = $this->json();
        self::assertSame(SalesOrder::STATUS_CONFIRMED, $body['status']);
        self::assertNotNull($body['ordered_at'], 'The confirmation did not stamp ordered_at.');
        self::assertSame([
            'id', 'customer', 'warehouse', 'currency_code', 'order_number', 'status', 'total_amount',
            'ordered_at', 'created_by', 'ship_to_line', 'ship_to_city', 'ship_to_postal_code',
            'ship_to_country_code', 'archived_at',
        ], array_keys($body));

        // Read back from the database, not from the body it just produced: the response is the thing
        // under test, so it cannot also be the evidence.
        self::assertSame([['status' => SalesOrder::STATUS_CONFIRMED, 'stamped' => true]],
            $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT status, ordered_at IS NOT NULL AS stamped FROM sales_order WHERE id = ?::uuid', [$order]));
    }

    /**
     * **V2-R-22 Half 1 — a zero-line order is refused, and the state is reachable through a shipped
     * operation.** `CreateSalesOrder` accepts an absent or null `lines[]` as a legal empty draft, so this
     * order is raised the way a user would raise it and not seeded past the contract.
     *
     * **The error shape is `business-rule-violated`, 409, members `rule` + `message`, and NO JSON
     * pointer** — that is the consistent answer, not an omission: `ConfirmSalesOrder` accepts nothing, and
     * a pointer names a field of the request that made the refusal. Pointers live in `validation-failed`'s
     * `errors[]`.
     */
    #[Test]
    public function anOrderWithNoLinesCannotBeConfirmed(): void
    {
        $c = $this->workspace();
        $this->send('POST', '/sales-orders', $this->body($c, null), [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $order = (string) $this->json()['id'];

        $this->send('POST', '/sales-orders/'.$order.'/confirmation');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('urn:erp:problem:business-rule-violated', $body['type']);
        self::assertSame('sales_order.has_a_live_line', $body['rule']);
        self::assertSame('This sales order has no lines. Add at least one line before confirming it.', $body['detail']);
        self::assertArrayNotHasKey('pointer', $body, 'A refusal of a request with no fields named a field of it.');
        self::assertArrayNotHasKey('errors', $body);

        $this->assertStatus($order, SalesOrder::STATUS_DRAFT);
    }

    /**
     * **An order whose only line was archived is as empty as one that never had any.** The count is read
     * `WHERE deleted_at IS NULL`, so a soft-deleted line does not keep a draft confirmable.
     */
    #[Test]
    public function anOrderWhoseOnlyLineIsArchivedCannotBeConfirmed(): void
    {
        $order = $this->raiseWithALine();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE sales_order_line SET deleted_at = now() WHERE sales_order_id = ?::uuid', [$order]);

        $this->send('POST', '/sales-orders/'.$order.'/confirmation');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order.has_a_live_line', $this->json()['rule']);
        $this->assertStatus($order, SalesOrder::STATUS_DRAFT);
    }

    /**
     * **The status guard runs BEFORE the line guard, and a cancelled empty order is told *wrong status*
     * rather than *add a line*.** That is D-C's shipped ruling carried to the selling side: adding a line
     * is advice the caller cannot follow, line writes requiring `draft`.
     * `TransitionGuardTest::theStatusGuardRefusesBeforeAnyPreconditionDoes` pins the same ordering in the
     * domain; this is what it looks like over HTTP.
     */
    #[Test]
    public function theStatusGuardAnswersBeforeTheLineGuardDoes(): void
    {
        $c = $this->workspace();
        $this->send('POST', '/sales-orders', $this->body($c, null), [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        $order = (string) $this->json()['id'];
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "UPDATE sales_order SET status = 'cancelled' WHERE id = ?::uuid", [$order]);

        $this->send('POST', '/sales-orders/'.$order.'/confirmation');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order.status_transition_is_legal', $this->json()['rule'],
            'An empty cancelled order was told to add a line, which it cannot do.');
    }

    /**
     * §4.2's `guard:sales_order.status` cell is the whole idempotency mechanism: a replay hits an order
     * that is no longer `draft` and is refused, which is how the caller learns the first attempt
     * committed. `IdempotencyTest::replayConfirmSalesOrder` asserts the row is byte-identical afterwards;
     * this asserts the answer.
     */
    #[Test]
    public function aSecondConfirmationIsRefusedByTheStatusGuard(): void
    {
        $order = $this->raiseWithALine();
        $this->send('POST', '/sales-orders/'.$order.'/confirmation');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/sales-orders/'.$order.'/confirmation');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order.status_transition_is_legal', $this->json()['rule']);
    }

    /**
     * §5.2a / ST-9: a transition on an archived row inside the caller's own tenant is **read** and refused
     * in business terms, never answered 404 — the caller can see the row, so telling it the order is not
     * there would be false. This is why `SalesOrderConfirmation` reads `deleted_at` by statement rather
     * than through `$em->find()`, which `SoftDeleteFilter` would answer `null` for.
     */
    #[Test]
    public function anArchivedOrderCannotBeConfirmed(): void
    {
        $order = $this->raiseWithALine();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$order]);

        $this->send('POST', '/sales-orders/'.$order.'/confirmation');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order.is_archived', $this->json()['rule']);
        $this->assertStatus($order, SalesOrder::STATUS_DRAFT);
    }

    /**
     * §5.3 clause 2: another tenant's id, a uuid no row answers and a string that is not a uuid at all
     * take the same path, so "another tenant's", "never existed" and "malformed" are indistinguishable.
     * A 403 on a cross-tenant id would confirm the id exists somewhere.
     */
    #[Test]
    public function anIdThisTenantCannotSeeIsNotFound(): void
    {
        $other = $this->seedSalesOrder(self::TENANT_B, 'SO-B-1');

        foreach ([$other, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->send('POST', '/sales-orders/'.$id.'/confirmation');

            self::assertSame(404, $this->httpStatus(), $id.': '.(string) $this->client()->getResponse()->getContent());
            self::assertSame('urn:erp:problem:not-found', $this->json()['type']);
        }

        $this->assertStatus($other, SalesOrder::STATUS_DRAFT, self::TENANT_B);
    }

    /**
     * §4.2's `Accepted request fields` cell is `—`, and `input: false` / `deserialize: false` make that
     * true of the code: `BodylessWriteRefusal` answers a caller who sends a field with the same
     * `validation-failed` / `undeclared_field` shape a body-reading write gives.
     */
    #[Test]
    public function aBodyIsRefusedAsAnUndeclaredField(): void
    {
        $order = $this->raiseWithALine();

        $this->send('POST', '/sales-orders/'.$order.'/confirmation', ['ordered_at' => '2030-01-01T00:00:00Z']);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        self::assertSame([['pointer' => '/ordered_at', 'code' => 'undeclared_field']], array_map(
            static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']],
            $this->json()['errors'],
        ));
        $this->assertStatus($order, SalesOrder::STATUS_DRAFT);
    }

    /**
     * **V2-R-22 Half 2, model A, as a measurement rather than as a docblock: confirming writes NO
     * reservation.** Nothing in `src/` writes `stock_level.qty_reserved`, so available-to-promise equals
     * on-hand and this operation leaves the stock tables exactly as it found them.
     *
     * Asserted on `stock_level` and `stock_movement` together, because a reservation could be written as
     * either — a column on the level, or a movement with a reason nothing authorises. The positive
     * control is the order itself: it *did* change status, so a run in which nothing happened at all
     * cannot pass this.
     *
     * `WriteOwnershipTest::noWriteStatementUnderSrcNamesQtyReserved` is the static half of the same
     * residual (`RESIDUAL_GAPS.md` G-7) and carries the closing work in its failure message.
     */
    #[Test]
    public function aConfirmationWritesNoReservationAndNoStockMovement(): void
    {
        $order = $this->raiseWithALine();

        $this->send('POST', '/sales-orders/'.$order.'/confirmation');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        // The positive control: the operation really ran.
        $this->assertStatus($order, SalesOrder::STATUS_CONFIRMED);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_level'),
            'Confirming a sales order created a stock level. Model A writes no reservation — read RESIDUAL_GAPS.md G-7.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement'),
            'Confirming a sales order posted a stock movement. It is a document transition and nothing else.');
    }

    /**
     * **There is no `sales_order.confirmed` audit event, and this is where that absence is visible.**
     * The mirror purchase operation writes `purchase_order.placed`. `API.md` §11's **B-4a** owns the
     * deferral with its own *fails if* and `[test]` — V2-R-22's O-C routed this operation into B-4a
     * rather than minting a new `SEC` question. **Asserted rather than left implicit** so that whoever
     * ships B-4a finds a test that says what today's answer is, and has to change it deliberately.
     *
     * =======================================================================
     * ⚠ THIS TEST'S CONTROL FIRED ONE COMMIT LATER, EXACTLY AS DESIGNED
     * =======================================================================
     * It was written asserting that **no `sales_order.*` member existed at all**, with the message *"give
     * this operation its event and change this test in the same commit"*. `CancelSalesOrder`, later the
     * same day, minted `SALES_ORDER_CANCELLED` and turned this red. **That is the control working**, and
     * the answer it forced was examined rather than suppressed:
     *
     *   - **`sales_order.confirmed` is still deferred to B-4a.** The owner's OI-2 named four events and
     *     `confirmed` is not among them; `SEC` has classified `sales_order.cancelled` under PC-11 (4a) and
     *     has classified no confirm event. Minting one here would be a `BE-LEAD` decision on a `SEC` step.
     *   - **`sales_order.cancelled` was NOT deferred**, because `ADR-008` §2 names `audit_log` as the
     *     compensating control for the sales duty split it declines on cancellation, and says nothing of
     *     the kind about confirming — which *"moves nothing out"* on its own reasoning.
     *
     * So the pin is **narrowed to the one event this operation owes**, not deleted. The class-wide version
     * had served its purpose and could not survive any sales event; a narrowed pin still goes red the day
     * `sales_order.confirmed` is minted, which is the day this test has to change.
     *
     * **Two positive controls, and the second is new because the first is now weaker.** The purchase
     * analogue must still be in the catalogue (a list gone empty proves nothing), **and
     * `SALES_ORDER_CANCELLED` must be in it too** — which proves that a `sales_order.*` event *can* be
     * catalogued and that the absence below is about `confirmed` specifically rather than about the whole
     * prefix being unreachable.
     */
    #[Test]
    public function aConfirmationWritesNoAuditRowBecauseB4aOwnsThatDeferral(): void
    {
        self::assertContains(AuditEvent::PURCHASE_ORDER_PLACED, AuditEvent::ALL,
            'The purchase analogue has left the catalogue, so the absence asserted below proves nothing.');
        self::assertContains(AuditEvent::SALES_ORDER_CANCELLED, AuditEvent::ALL,
            'The sales cancellation event has left the catalogue, so this test can no longer distinguish '
            .'"confirmed is deferred" from "no sales_order.* event is reachable at all".');
        self::assertNotContains('sales_order.confirmed', AuditEvent::ALL,
            'sales_order.confirmed exists now. B-4a owned that deferral — give this operation its event, write '
            .'its row, and change this test in the same commit.');

        $order = $this->raiseWithALine();
        $this->send('POST', '/sales-orders/'.$order.'/confirmation');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log'),
            'The confirmation wrote a trail row for an event the catalogue does not declare.');
    }

    /** @return array{customer: string, address: string, warehouse: string, item: string} */
    private function workspace(): array
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Retail');

        return [
            'customer' => $customer,
            'address' => $this->seedCustomerAddress(self::TENANT_A, $customer),
            'warehouse' => $this->seedWarehouse(self::TENANT_A, 'WH SO'),
            'item' => $this->seedItem(self::TENANT_A, 'SKU-SO-1'),
        ];
    }

    /**
     * @param array{customer: string, address: string, warehouse: string, item: string} $c
     * @param list<array<string, string>>|null                                          $lines
     *
     * @return array<string, mixed>
     */
    private function body(array $c, ?array $lines): array
    {
        $body = [
            'customer_id' => $c['customer'],
            'warehouse_id' => $c['warehouse'],
            'currency_code' => 'EUR',
            'ship_to_address_id' => $c['address'],
        ];

        return null === $lines ? $body : [...$body, 'lines' => $lines];
    }

    /** An order raised through the shipped create, with one line, so it is confirmable. */
    private function raiseWithALine(): string
    {
        $c = $this->workspace();
        $this->send('POST', '/sales-orders', $this->body($c, [
            ['inventory_item_id' => $c['item'], 'quantity' => '2', 'unit_price' => '3.5'],
        ]), [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return (string) $this->json()['id'];
    }

    private function assertStatus(string $orderId, string $status, ?string $tenantId = null): void
    {
        self::assertSame([['status' => $status]], $this->fetchAsOwnerForTenant($tenantId ?? self::TENANT_A,
            'SELECT status FROM sales_order WHERE id = ?::uuid', [$orderId]));
    }
}
