<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\Sales\Application\SalesOrderWriter;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Modules\Warehouses\Application\WarehouseWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`RestoreSalesOrder`** (order restores C2, 2026-10-21; `docs/session13/order-restores-design.md` §5.2 as amended by
 * Revision 1 and `order-restores-final-red.md`). `POST /sales-orders/{id}/restoration`, `sales_order.delete`, no body,
 * 200 with `GetSalesOrder`'s body; `guard:sales_order.deleted_at`.
 *
 * The collisions are each reached ALONE (sales slice 4's lesson: the unpredicted collision fired first), the parent
 * refusals each alone and together, and the races in R1.7's shape: the holder is a separate process running SQL, the API
 * request the other side (`CustomerDeleteTest::raceAgainstAHolder()`'s harness, a copy for that file's reason). Where a
 * holder runs the restore's own statements it runs the writer's PUBLIC constants, never a transcription.
 */
final class SalesOrderRestoreTest extends PurchaseOrderTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    // -----------------------------------------------------------------------
    // The happy path
    // -----------------------------------------------------------------------

    /**
     * Delete then restore → 200, the body is `GetSalesOrder`'s, `archived_at` null; the lines deleted WITH the order are
     * live again, and **a line deleted on its own before the order stays deleted** (owner ruling Q3). **The stored total
     * equals the live lines' sum** — the restore does not recompute (R1.3, sales), so this is the assertion that would
     * catch a delete or a cascade that left the total wrong.
     */
    #[Test]
    public function aDeletedDraftComesBackWithTheLinesDeletedWithItAndATotalThatIsTheirSum(): void
    {
        $order = $this->raiseOrder('SO-RT1', [['3', '2.5'], ['4', '10'], ['1', '7']]);
        $lines = $this->lineIds($order);
        $this->send('DELETE', '/sales-orders/'.$order.'/lines/'.$lines[2]);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('DELETE', '/sales-orders/'.$order);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->restore($order);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertNull($body['archived_at']);
        self::assertSame(SalesOrder::STATUS_DRAFT, $body['status']);
        $this->send('GET', '/sales-orders/'.$order);
        self::assertSame($this->json(), $body, 'The restore\'s body is not GetSalesOrder\'s.');

        $this->send('GET', '/sales-orders/'.$order.'/lines');
        self::assertEqualsCanonicalizing([$lines[0], $lines[1]], array_column($this->json()['items'], 'id'),
            'The lines deleted WITH the order did not come back, or a line deleted on its own did.');
        $this->send('GET', '/sales-orders/'.$order.'/lines?archived=true');
        self::assertSame([$lines[2]], array_column($this->json()['items'], 'id'), 'Owner ruling Q3: a line deleted on its own stays deleted.');

        $sum = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT o.total_amount::text AS stored, (SELECT sum(l.quantity * l.unit_price) FROM sales_order_line l
                    WHERE l.sales_order_id = o.id AND l.deleted_at IS NULL)::numeric(19,4)::text AS live
              FROM sales_order o WHERE o.id = ?::uuid
            SQL, [$order])[0];
        self::assertSame('47.5000', $sum['live']);
        self::assertSame($sum['live'], $sum['stored'], 'After a restore the stored total is not the live lines\' sum.');
        self::assertSame('47.5000', $body['total_amount']);
    }

    /** One `sales_order.restored` row naming the actor, business, `{}`, subject the order; a refused repeat adds none. */
    #[Test]
    public function aRestoreWritesOneRestoredRowAndARepeatIsRefusedWithoutASecond(): void
    {
        $order = $this->raiseOrder('SO-RT2');
        $this->send('DELETE', '/sales-orders/'.$order);
        $this->restore($order);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = $this->restoredRows($order);
        self::assertCount(1, $rows);
        self::assertSame($this->actorId, $rows[0]['actor_user_id']);
        self::assertSame('sales_order', $rows[0]['subject_type']);
        self::assertSame('{}', $rows[0]['metadata']);
        self::assertNull($rows[0]['ip_address']);

        $this->restore($order);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order.is_not_archived', $this->json()['rule']);
        self::assertSame(SalesOrderWriter::NOT_ARCHIVED, $this->json()['detail']);
        self::assertCount(1, $this->restoredRows($order), 'A refused repeat wrote a second restored row.');
    }

    /** A live order was never deleted: refused, nothing written. */
    #[Test]
    public function aLiveOrderHasNothingToRestore(): void
    {
        $order = $this->raiseOrder('SO-RT3');

        $this->restore($order);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order.is_not_archived', $this->json()['rule']);
        self::assertSame([], $this->restoredRows($order));
    }

    // -----------------------------------------------------------------------
    // The parents (R1.4: both read under the order lock; warehouse refused first)
    // -----------------------------------------------------------------------

    /** C5's invariant: an archived customer holds no open document, so a draft is not restored onto one. */
    #[Test]
    public function aDraftIsNotRestoredOntoAnArchivedCustomer(): void
    {
        $c = $this->deletedOrder('SO-RT4');
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$c['customer']]);

        $this->restore($c['order']);

        $this->assertRefusedAndUnchanged($c['order'], 'sales_order.customer_is_live', SalesOrderWriter::RESTORE_CUSTOMER_ARCHIVED);
    }

    /** API-O-34's invariant: an archived warehouse holds no open order, so a draft is not restored onto one. */
    #[Test]
    public function aDraftIsNotRestoredOntoAnArchivedWarehouse(): void
    {
        $c = $this->deletedOrder('SO-RT5');
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$c['warehouse']]);

        $this->restore($c['order']);

        $this->assertRefusedAndUnchanged($c['order'], 'sales_order.warehouse_is_live', SalesOrderWriter::RESTORE_WAREHOUSE_ARCHIVED);
    }

    /** Both archived → the WAREHOUSE is named (`PurchaseOrder::place()`'s order; RED L-3, final RED M-1). */
    #[Test]
    public function whenBothParentsAreArchivedTheWarehouseIsNamed(): void
    {
        $c = $this->deletedOrder('SO-RT6');
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$c['customer']]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$c['warehouse']]);

        $this->restore($c['order']);

        $this->assertRefusedAndUnchanged($c['order'], 'sales_order.warehouse_is_live', SalesOrderWriter::RESTORE_WAREHOUSE_ARCHIVED);
    }

    // -----------------------------------------------------------------------
    // The collisions (R1.2, sales column), each reached ALONE
    // -----------------------------------------------------------------------

    /**
     * `sales_order_tenant_idem_live` — REACHABLE: a retry of the create after the order was deleted makes a NEW order
     * holding the same key (the key lookup reads live rows only). Nested lines carry no key, so only the order key collides.
     */
    #[Test]
    public function anOrderWhoseRetryKeyALiveOrderNowHoldsIsRefusedOnTheOrderKey(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $w = $this->workspace('SO-RT7');
        $this->raise($w, [['1', '1']], $key);
        $first = $this->idFromLocation();
        $this->send('DELETE', '/sales-orders/'.$first);
        $this->raise($w, [['1', '1']], $key);
        self::assertSame(201, $this->httpStatus(), 'The retry after a delete was not a new order: '.$this->client()->getResponse()->getContent());
        self::assertNotSame($first, $this->idFromLocation());

        $this->restore($first);

        $this->assertRefusedAndUnchanged($first, 'sales_order.retry_key_is_held_by_a_live_order', SalesOrderWriter::RESTORE_ORDER_KEY_HELD);
    }

    /**
     * `sales_order_line_tenant_idem_live` — REACHABLE ACROSS ORDERS, raised inside the cascade trigger's UPDATE. T-1 is this
     * test: the trigger's 23505 still names the constraint, or the answer would be a 500. The two orders' own keys differ.
     */
    #[Test]
    public function anOrderWhoseLineKeyALineOnAnotherOrderNowHoldsIsRefusedOnTheLineKey(): void
    {
        $lineKey = Uuid::v4()->toRfc4122();
        $a = $this->raiseOrder('SO-RT8');
        $item = $this->seedItem(self::TENANT_A, 'SKU-RT8-X');
        $this->addLine($a, $item, $lineKey);
        $this->send('DELETE', '/sales-orders/'.$a);

        $b = $this->raiseOrder('SO-RT8B');
        $this->addLine($b, $item, $lineKey);
        self::assertSame(201, $this->httpStatus(), 'The line key was not reusable on another order: '.$this->client()->getResponse()->getContent());

        $this->restore($a);

        $this->assertRefusedAndUnchanged($a, 'sales_order.line_retry_key_is_held_by_another_order', SalesOrderWriter::RESTORE_LINE_KEY_HELD);
    }

    /**
     * `sales_order_line_unique` — unreachable through the API (sales has no legacy window), **mapped anyway** so the
     * create path's "try again" can never answer it (R1.2). Owner-seeded: two lines numbered 1 under one archive stamp.
     */
    #[Test]
    public function twoDeletedLinesSharingANumberAreRefusedOnTheLineNumber(): void
    {
        $order = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'));
        $stamp = '2026-10-01 12:00:00+00';
        $one = $this->seedSalesOrderLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-RT9-A'), 1);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order_line SET deleted_at = ?::timestamptz WHERE id = ?::uuid', [$stamp, $one]);
        $this->seedSalesOrderLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-RT9-B'), 1);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET deleted_at = ?::timestamptz WHERE id = ?::uuid', [$stamp, $order]);
        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM sales_order_line WHERE sales_order_id = ?::uuid AND deleted_at = ?::timestamptz', [$order, $stamp]),
            'Fixture: both lines do not carry the order\'s stamp, so the restore cannot revive both.');

        $this->restore($order);

        $this->assertRefusedAndUnchanged($order, 'sales_order.line_number_is_taken_on_this_order', SalesOrderWriter::RESTORE_LINE_NUMBER_TAKEN);
    }

    /**
     * `sales_order_tenant_number_live` — UNREACHABLE since owner ruling Q4 (a deleted order's number is never reissued),
     * so NOT translated; this is the proof: delete the newest, create another, restore the first → 200, two numbers.
     */
    #[Test]
    public function aDeletedOrdersNumberIsNeverReissuedSoItsRestoreCannotCollideOnIt(): void
    {
        $first = $this->raiseOrder('SO-RT10');
        $this->send('GET', '/sales-orders/'.$first);
        $number = $this->json()['order_number'];
        $this->send('DELETE', '/sales-orders/'.$first);

        $second = $this->raiseOrder('SO-RT10B');
        $this->send('GET', '/sales-orders/'.$second);
        self::assertNotSame($number, $this->json()['order_number'], 'Q4 broken: the deleted order\'s number was reissued.');

        $this->restore($first);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($number, $this->json()['order_number']);
    }

    // -----------------------------------------------------------------------
    // Who may, and which rows
    // -----------------------------------------------------------------------

    /** `sales_order.delete` is the code; `viewer` and an editor without it are refused before anything is read. */
    #[Test]
    public function onlyAHolderOfTheDeleteCodeMayRestore(): void
    {
        $c = $this->deletedOrder('SO-RT11');

        foreach ([['sales_order.view'], ['sales_order.view', 'sales_order.edit', 'sales_order.create']] as $codes) {
            $this->actAs(self::TENANT_A, $codes);
            $this->restore($c['order']);
            self::assertSame(403, $this->httpStatus(), implode(',', $codes).': '.$this->client()->getResponse()->getContent());
        }

        self::assertNotNull($this->stored($c['order'])['deleted_at']);
        self::assertSame([], $this->restoredRows($c['order']));
    }

    /** §5.3 clause 2: another tenant's order, an id no row answers and a non-uuid are the same 404. */
    #[Test]
    public function anotherTenantsOrderAndAnIdNoRowAnswersAreNotFound(): void
    {
        $theirs = $this->seedSalesOrder(self::TENANT_B, self::freshNumber('SO'));
        $this->executeAsOwnerForTenant(self::TENANT_B, 'UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$theirs]);

        foreach ([$theirs, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->restore($id);
            self::assertSame(404, $this->httpStatus(), $id.': '.$this->client()->getResponse()->getContent());
        }
        self::assertNotNull($this->fetchAsOwnerForTenant(self::TENANT_B, 'SELECT deleted_at FROM sales_order WHERE id = ?::uuid', [$theirs])[0]['deleted_at']);
    }

    // -----------------------------------------------------------------------
    // Races (R1.7)
    // -----------------------------------------------------------------------

    /**
     * **R-1d — delete first, the evidence for the restore's customer lock.** The holder runs `DeleteCustomer`'s own
     * statements (`CustomerWriter::DELETE_LOCK`, `::ARCHIVE`) and holds before COMMIT. The locked restore waits on its
     * `FOR KEY SHARE`, re-reads the committed row and refuses. **Mutation "unlocked customer read":** the restore does
     * not wait, sees the customer live and commits a draft onto it — 200, `waiter_seen` false → red.
     */
    #[Test]
    public function aRestoreRacingADeleteOfItsCustomerWaitsAndIsRefused(): void
    {
        $c = $this->deletedOrder('SO-RT13');

        $holder = $this->raceAgainstAHolder(
            [[CustomerWriter::DELETE_LOCK, [$c['customer']]], [CustomerWriter::ARCHIVE, [$c['customer']]]],
            fn () => $this->restore($c['order']),
        );

        $this->assertWaitedThenRefused($holder, $c['order'], 'sales_order.customer_is_live', SalesOrderWriter::RESTORE_CUSTOMER_ARCHIVED);
    }

    /** **R-2d** — R-1d's twin on the warehouse (`WarehouseWriter::DELETE_LOCK`, `::ARCHIVE`). */
    #[Test]
    public function aRestoreRacingADeleteOfItsWarehouseWaitsAndIsRefused(): void
    {
        $c = $this->deletedOrder('SO-RT14');

        $holder = $this->raceAgainstAHolder(
            [[WarehouseWriter::DELETE_LOCK, [$c['warehouse']]], [WarehouseWriter::ARCHIVE, [$c['warehouse']]]],
            fn () => $this->restore($c['order']),
        );

        $this->assertWaitedThenRefused($holder, $c['order'], 'sales_order.warehouse_is_live', SalesOrderWriter::RESTORE_WAREHOUSE_ARCHIVED);
    }

    /**
     * **R-1r — restore first.** The holder runs the restore's own statements (the writer's public constants) and holds;
     * the API `DeleteCustomer` waits on the `FOR KEY SHARE`, then counts the restored draft and refuses. Evidence that the
     * delete waits on the restore's lock — and, because the holder runs `RESTORE_CUSTOMER_READ` itself, a mutation of that
     * constant's lock clause reddens this too (R1.7's preferred shape).
     */
    #[Test]
    public function aCustomerDeleteRacingARestoreOfItsDraftWaitsAndCountsIt(): void
    {
        $c = $this->deletedOrder('SO-RT15');

        $holder = $this->raceAgainstAHolder(
            [[SalesOrderWriter::RESTORE_CUSTOMER_READ, [$c['customer']]], [SalesOrderWriter::RESTORE, [$c['order']]]],
            fn () => $this->send('DELETE', '/customers/'.$c['customer']),
            $c['order'],
        );

        self::assertTrue($holder['waiter_seen'], 'The customer delete never waited on the restore. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('customer.has_open_sales_orders', $this->json()['rule']);
        self::assertNull($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at FROM customer WHERE id = ?::uuid', [$c['customer']])[0]['deleted_at']);
    }

    /** **R-3** — restore against restore: the second waits on the order lock, then finds the order live and is refused. */
    #[Test]
    public function aSecondRestoreWaitsForTheFirstAndIsRefused(): void
    {
        $c = $this->deletedOrder('SO-RT16');

        $holder = $this->raceAgainstAHolder(
            [[SalesOrderWriter::RESTORE, [$c['order']]]],
            fn () => $this->restore($c['order']),
            $c['order'],
        );

        self::assertTrue($holder['waiter_seen'], json_encode($holder));
        self::assertTrue($holder['committed'], json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order.is_not_archived', $this->json()['rule']);
        self::assertSame([], $this->restoredRows($c['order']), 'The refused restore wrote a row (the holder writes none).');
    }

    /**
     * **R-4 — restore against a create retrying the same key** (T-11, measured): the holder restores order A (key K) and
     * holds; a create with K reads no live row, waits at `sales_order_tenant_idem_live`, and after the commit loses with
     * the create path's own in-flight answer — never a 500, never a second live order on K.
     */
    #[Test]
    public function aCreateRetryingTheKeyOfAnOrderBeingRestoredIsToldItIsInFlight(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $w = $this->workspace('SO-RT17');
        $this->raise($w, [['1', '1']], $key);
        $order = $this->idFromLocation();
        $this->send('DELETE', '/sales-orders/'.$order);

        $holder = $this->raceAgainstAHolder(
            [[SalesOrderWriter::RESTORE, [$order]]],
            fn () => $this->raise($w, [['1', '1']], $key),
            $order,
        );

        self::assertTrue($holder['waiter_seen'], 'The create never waited on the restore\'s key. '.json_encode($holder));
        self::assertTrue($holder['committed'], json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order.idempotency_key_is_in_flight', $this->json()['rule'] ?? null, (string) $this->client()->getResponse()->getContent());
        self::assertSame([$order], array_column($this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id::text AS id FROM sales_order WHERE customer_id = ?::uuid AND deleted_at IS NULL', [$w['customer']]), 'id'),
            'Two live orders on one retry key, or the restored order is not the live one.');
    }

    /**
     * **R-5 — restore against a line create on ANOTHER order with a revived line's key**: the line create waits at the
     * line key index, then loses with the create path's answer (`idempotency-key-conflict`) — never a 500.
     */
    #[Test]
    public function aLineCreateRacingARestoreThatRevivesItsKeyIsRefusedNotA500(): void
    {
        $lineKey = Uuid::v4()->toRfc4122();
        $a = $this->raiseOrder('SO-RT18');
        $item = $this->seedItem(self::TENANT_A, 'SKU-RT18-X');
        $this->addLine($a, $item, $lineKey);
        $this->send('DELETE', '/sales-orders/'.$a);
        $b = $this->raiseOrder('SO-RT18B');

        $holder = $this->raceAgainstAHolder(
            [[SalesOrderWriter::RESTORE, [$a]]],
            fn () => $this->addLine($b, $item, $lineKey),
            $a,
        );

        self::assertTrue($holder['waiter_seen'], json_encode($holder));
        self::assertTrue($holder['committed'], json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNotSame(\App\Platform\Http\Problem\TransactionContention::RULE, $this->json()['rule'] ?? null, 'A lost lock cycle, not the create path\'s answer (G-65 sweep, RED g65 F-5).');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM sales_order_line WHERE inventory_item_id = ?::uuid AND deleted_at IS NULL', [$item]),
            'Two live lines on one retry key, or the revived line is gone.');
    }

    /** **R-6 — restore against a line delete on the same order**: the delete waits on the order lock, then works on a live draft. */
    #[Test]
    public function aLineDeleteWaitsForTheRestoreOfItsOrderAndThenProceeds(): void
    {
        $order = $this->raiseOrder('SO-RT19');
        $line = $this->lineIds($order)[0];
        $this->send('DELETE', '/sales-orders/'.$order);

        $holder = $this->raceAgainstAHolder(
            [[SalesOrderWriter::RESTORE, [$order]]],
            fn () => $this->send('DELETE', '/sales-orders/'.$order.'/lines/'.$line),
            $order,
        );

        self::assertTrue($holder['waiter_seen'], json_encode($holder));
        self::assertTrue($holder['committed'], json_encode($holder));
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNull($this->stored($order)['deleted_at']);
    }

    // -----------------------------------------------------------------------

    private function restore(string $order): void
    {
        $this->send('POST', '/sales-orders/'.$order.'/restoration');
    }

    /** @return array{customer: string, address: string, warehouse: string, item: string} */
    private function workspace(string $tag): array
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Retail '.$tag);

        return [
            'customer' => $customer,
            'address' => $this->seedCustomerAddress(self::TENANT_A, $customer),
            'warehouse' => $this->seedWarehouse(self::TENANT_A, 'WH '.$tag),
            'item' => $this->seedItem(self::TENANT_A, 'SKU-'.$tag),
        ];
    }

    /**
     * @param array{customer: string, address: string, warehouse: string, item: string} $w
     * @param list<array{0: string, 1: string}>                                        $lines quantity, unit price
     */
    private function raise(array $w, array $lines, ?string $key = null): void
    {
        $items = [];
        foreach ($lines as $n => [$quantity, $price]) {
            $items[] = ['inventory_item_id' => 0 === $n ? $w['item'] : $this->seedItem(self::TENANT_A, 'SKU-'.Uuid::v4()->toRfc4122()), 'quantity' => $quantity, 'unit_price' => $price];
        }
        $this->send('POST', '/sales-orders', [
            'customer_id' => $w['customer'], 'warehouse_id' => $w['warehouse'],
            'currency_code' => 'EUR', 'ship_to_address_id' => $w['address'],
            'lines' => $items,
        ], [IdempotencyKeyGuard::HEADER => $key ?? Uuid::v4()->toRfc4122()]);
    }

    /** @param list<array{0: string, 1: string}> $lines */
    private function raiseOrder(string $tag, array $lines = [['1', '1']]): string
    {
        $this->raise($this->workspace($tag), $lines);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return $this->idFromLocation();
    }

    /** @return array{order: string, customer: string, warehouse: string} */
    private function deletedOrder(string $tag): array
    {
        $w = $this->workspace($tag);
        $this->raise($w, [['1', '1']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $order = $this->idFromLocation();
        $this->send('DELETE', '/sales-orders/'.$order);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return ['order' => $order, 'customer' => $w['customer'], 'warehouse' => $w['warehouse']];
    }

    private function addLine(string $order, string $item, string $key): void
    {
        $this->send('POST', '/sales-orders/'.$order.'/lines', ['inventory_item_id' => $item, 'quantity' => '2', 'unit_price' => '3'],
            [IdempotencyKeyGuard::HEADER => $key]);
    }

    private function idFromLocation(): string
    {
        $location = (string) $this->client()->getResponse()->headers->get('Location');
        self::assertNotSame('', $location, (string) $this->client()->getResponse()->getContent());

        return basename($location);
    }

    /** @return list<string> */
    private function lineIds(string $order): array
    {
        return array_map(static fn (array $r): string => (string) $r['id'], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id FROM sales_order_line WHERE sales_order_id = ?::uuid ORDER BY line_number', [$order]));
    }

    /** @return array<string, mixed> */
    private function stored(string $order): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at, status FROM sales_order WHERE id = ?::uuid', [$order])[0];
    }

    /** @return list<array<string, mixed>> */
    private function restoredRows(string $order): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT actor_user_id::text AS actor_user_id, subject_type, metadata::text AS metadata, ip_address
              FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid
            SQL, [AuditEvent::SALES_ORDER_RESTORED, $order]);
    }

    private function assertRefusedAndUnchanged(string $order, string $rule, string $detail): void
    {
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($rule, $this->json()['rule']);
        self::assertSame($detail, $this->json()['detail']);
        self::assertNotNull($this->stored($order)['deleted_at'], 'A refused restore changed the order.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM sales_order_line WHERE sales_order_id = ?::uuid AND deleted_at IS NULL', [$order]),
            'A refused restore revived a line.');
        self::assertSame([], $this->restoredRows($order), 'A refused restore wrote a restored row.');
    }

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertWaitedThenRefused(array $holder, string $order, string $rule, string $detail): void
    {
        self::assertTrue($holder['waiter_seen'], 'The restore never waited on the delete: its parent read is not locked. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        $this->assertRefusedAndUnchanged($order, $rule, $detail);
    }

    /**
     * `CustomerDeleteTest::raceAgainstAHolder`'s shape, another copy (not extracted, the earlier copies' reason). The holder's
     * own row lock is on `$lockedOrder` (`sales_order`, `FOR NO KEY UPDATE` — `SalesOrderRowLock`'s mode), or on a random
     * id that matches no row when the race is about a parent; the real statements are `HOLD_BEFORE`'s.
     *
     * @param list<array{0: string, 1: list<string>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request, ?string $lockedOrder = null): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_sales_restore_');
        file_put_contents($readyFile, '');

        $proc = proc_open(
            [PHP_BINARY, __DIR__.'/support/hold_order_lock.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'APP_DIRECT_DATABASE_URL' => $_ENV['APP_DIRECT_DATABASE_URL'],
                'HOLD_TENANT' => self::TENANT_A,
                'HOLD_ORDER' => $lockedOrder ?? Uuid::v4()->toRfc4122(),
                'HOLD_TABLE' => 'sales_order',
                'HOLD_BEFORE' => json_encode($before, \JSON_THROW_ON_ERROR),
                'HOLD_AFTER' => json_encode([], \JSON_THROW_ON_ERROR),
                'HOLD_READY_FILE' => $readyFile,
                'HOLD_DEADLINE_SECONDS' => (string) self::HOLDER_DEADLINE_SECONDS,
                'PATH' => (string) getenv('PATH'),
            ],
        );
        self::assertIsResource($proc);

        try {
            $deadline = microtime(true) + self::HOLDER_DEADLINE_SECONDS;
            while ('' === trim((string) file_get_contents($readyFile))) {
                if (microtime(true) > $deadline) {
                    self::fail('The holder never took the lock: '.stream_get_contents($pipes[2]));
                }
                usleep(1000);
            }
            if ('failed' === trim((string) file_get_contents($readyFile))) {
                self::fail('The holder failed before taking the lock: '.stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
            }

            $request();

            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
        } finally {
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($proc);
            @unlink($readyFile);
        }

        $report = json_decode(trim($out), true);
        self::assertIsArray($report, 'The holder printed no report. stdout: '.$out.' stderr: '.$err);

        return $report;
    }
}
