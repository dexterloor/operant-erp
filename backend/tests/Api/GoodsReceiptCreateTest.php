<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Step 8-ii — `CreateGoodsReceipt` and `GetGoodsReceipt`, over HTTP.**
 *
 * FLOWS F-3 step 5 is one transaction: lock the order, write the receipt and
 * its lines, move each order line's `quantity_received`, post the stock through
 * `Inventory\Application\Contract\ReceiveStock`, then recompute the order's
 * status. Every assertion here reads the database as the owner, outside the
 * request, so what is checked is what was committed.
 */
final class GoodsReceiptCreateTest extends PurchaseOrderTestCase
{
    /**
     * The operations shipped under `/goods-receipts`. `CreateGoodsReceipt` is
     * pinned in `PurchaseOrderOperationContractTest` because N-8 hangs its path
     * off the order; `GetGoodsReceipt` has a path of its own, so it is pinned
     * here and joined into `AuthOperationContractTest`'s whole-registry union.
     * `ListGoodsReceipts` is declared in §4.2 and not built at step 8.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_GOODS_RECEIPT_OPERATIONS = [
        'GetGoodsReceipt' => ['method' => 'GET', 'path' => '/goods-receipts/{id}', 'idem' => false],
    ];

    /** The registry's `/goods-receipts` operations are exactly the pin, method and path included. */
    #[Test]
    public function theGoodsReceiptPathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            if (str_starts_with((string) $operation->getUriTemplate(), '/goods-receipts')) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $operation->getUriTemplate()];
            }
        }

        self::assertSame(
            array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_GOODS_RECEIPT_OPERATIONS),
            $declared,
        );
    }

    #[Test]
    public function aReceiptPostsEveryLineMovesTheStockAndLeavesThePartOrderPartiallyReceived(): void
    {
        $po = $this->orderedOrderWithLines(['10.000000', '4.000000']);

        $this->receive($po['order'], [
            ['purchase_order_line_id' => $po['lines'][0], 'quantity' => '6', 'storage_location_id' => $po['bin']],
            ['purchase_order_line_id' => $po['lines'][1], 'quantity' => '4.000000', 'storage_location_id' => $po['bin']],
        ]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertTrue(Uuid::isValid((string) $body['id']));
        self::assertSame('GR-000001', $body['receipt_number']);
        self::assertSame(['id' => $po['order'], 'order_number' => 'PO-1'], $body['purchase_order']);
        self::assertCount(2, $body['lines']);
        self::assertSame(
            [[$po['lines'][0], '6.000000', $po['bin']], [$po['lines'][1], '4.000000', $po['bin']]],
            array_map(static fn (array $l): array => [$l['purchase_order_line_id'], $l['quantity'], $l['storage_location_id']], $body['lines']),
        );
        self::assertSame('/api/v1/goods-receipts/'.$body['id'], $this->client()->getResponse()->headers->get('Location'));

        self::assertSame([['quantity_received' => '6.000000'], ['quantity_received' => '4.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT quantity_received::text AS quantity_received FROM purchase_order_line WHERE purchase_order_id = ?::uuid ORDER BY line_number', [$po['order']]));
        self::assertSame([['status' => PurchaseOrder::STATUS_PARTIALLY_RECEIVED]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status FROM purchase_order WHERE id = ?::uuid', [$po['order']]));
        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A,
            "SELECT count(*) FROM stock_movement WHERE reason = 'receipt' AND source_type = 'goods_receipt' AND source_id = ?::uuid", [$body['id']]));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM goods_receipt WHERE id = ?::uuid AND received_by_user_id = ?::uuid', [$body['id'], $this->actorId]));

        // API-21 branch 4: the Location resolves, to the same receipt.
        $this->send('GET', (string) $this->client()->getResponse()->headers->get('Location'));
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($body, $this->json());
    }

    /** F-3 step 6: the receipt that brings the last outstanding line in full leaves the order `received`. */
    #[Test]
    public function theReceiptThatCompletesEveryLineLeavesTheOrderReceived(): void
    {
        $po = $this->orderedOrderWithLines(['10', '4']);
        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '10', 'storage_location_id' => $po['bin']]]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->assertStatus($po['order'], PurchaseOrder::STATUS_PARTIALLY_RECEIVED);

        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][1], 'quantity' => '4', 'storage_location_id' => $po['bin']]]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('GR-000002', $this->json()['receipt_number']);
        $this->assertStatus($po['order'], PurchaseOrder::STATUS_RECEIVED);
    }

    /** Ruled D-C / ledger C6: a receipt with nothing on it records no delivery. */
    #[Test]
    public function aReceiptWithNoLinesIsRefusedAndWritesNothing(): void
    {
        $po = $this->orderedOrderWithLines(['10']);

        $this->receive($po['order'], []);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '/lines', 'code' => 'at_least_one_line']], $this->errorPointers());
        $this->assertNothingReceived($po, PurchaseOrder::STATUS_ORDERED);
    }

    /** §8.3: only `ordered` and `partially_received` take a receipt. */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('ordersThatTakeNoReceipt')]
    public function anOrderThatIsNotOpenTakesNoReceiptAndNothingIsWritten(string $status, string $received): void
    {
        $po = $this->orderedOrderWithLines(['10'], $status);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE purchase_order_line SET quantity_received = ?::numeric WHERE id = ?::uuid', [$received, $po['lines'][0]]);

        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '1', 'storage_location_id' => $po['bin']]]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.status_transition_is_legal', $this->json()['rule']);
        $this->assertNothingReceived($po, $status, $received);
    }

    /** @return iterable<string, array{string, string}> */
    public static function ordersThatTakeNoReceipt(): iterable
    {
        yield 'draft' => [PurchaseOrder::STATUS_DRAFT, '0.000000'];
        yield 'cancelled' => [PurchaseOrder::STATUS_CANCELLED, '0.000000'];
        yield 'received' => [PurchaseOrder::STATUS_RECEIVED, '10.000000'];
    }

    /** F-3 step 3: over-receipt is blocked at the line level — summed over the receipt, so two bins cannot sneak past it. */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('overReceipts')]
    public function anOverReceiptIsRefusedAndNothingIsWritten(string $alreadyReceived, array $quantities): void
    {
        $po = $this->orderedOrderWithLines(['10'], PurchaseOrder::STATUS_PARTIALLY_RECEIVED);
        $second = $this->seedLocation(self::TENANT_A, $po['warehouse'], 'A-02');
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE purchase_order_line SET quantity_received = ?::numeric WHERE id = ?::uuid', [$alreadyReceived, $po['lines'][0]]);

        $bins = [$po['bin'], $second];
        $this->receive($po['order'], array_map(static fn (int $i, string $q): array => [
            'purchase_order_line_id' => $po['lines'][0], 'quantity' => $q, 'storage_location_id' => $bins[$i],
        ], array_keys($quantities), $quantities));

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order_line.is_not_over_received', $this->json()['rule']);
        $this->assertNothingReceived($po, PurchaseOrder::STATUS_PARTIALLY_RECEIVED, bcadd($alreadyReceived, '0', 6));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function overReceipts(): iterable
    {
        yield 'one line past what is outstanding' => ['7', ['3.000001']];
        yield 'two bins that each fit and together do not' => ['7', ['2', '2']];
    }

    /**
     * **The backstop, proved without the code.** `purchase_order_line_qty_sane`
     * allowed any `quantity_received >= 0`; a writer that skipped the check above
     * could record more than was ordered. The owner writes it directly here.
     */
    #[Test]
    public function theDatabaseRefusesAnOverReceivedOrderLineWhateverWritesIt(): void
    {
        $po = $this->orderedOrderWithLines(['10']);

        try {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                "UPDATE purchase_order_line SET quantity_received = '10.000001' WHERE id = ?::uuid", [$po['lines'][0]]);
            self::fail('An order line was recorded as received beyond what was ordered.');
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            self::assertSame('23514', $e->getSQLState(), $e->getMessage());
            self::assertStringContainsString('purchase_order_line_not_over_received', $e->getMessage());
        }
    }

    /** §5.3 clause 2: a line of another order is not a line of this one. */
    #[Test]
    public function aLineOfAnotherOrderIsNotFoundAndNothingIsWritten(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $other = $this->orderedOrderWithLines(['10'], number: 'PO-2');

        $this->receive($po['order'], [['purchase_order_line_id' => $other['lines'][0], 'quantity' => '1', 'storage_location_id' => $po['bin']]]);

        self::assertSame(404, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->assertNothingReceived($po, PurchaseOrder::STATUS_ORDERED);
        $this->assertNothingReceived($other, PurchaseOrder::STATUS_ORDERED);
    }

    /**
     * `ReceiveStock` refuses a bin of another warehouse (step 7); this operation
     * translates it and points at its own field. The receipt row and the line
     * update were already written when it refused, so this is also the proof that
     * the refusal rolls them back (W-2).
     */
    #[Test]
    public function aBinOfAnotherWarehouseIsRefusedAndNamedAndNothingSurvives(): void
    {
        $po = $this->orderedOrderWithLines(['10', '4']);
        $elsewhere = $this->seedLocation(self::TENANT_A, $this->seedWarehouse(self::TENANT_A, 'Far'), 'Z-01');

        $this->receive($po['order'], [
            ['purchase_order_line_id' => $po['lines'][0], 'quantity' => '1', 'storage_location_id' => $po['bin']],
            ['purchase_order_line_id' => $po['lines'][1], 'quantity' => '1', 'storage_location_id' => $elsewhere],
        ]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '/lines/1/storage_location_id', 'code' => 'storage_location.belongs_to_receiving_warehouse']], $this->errorPointers());
        $this->assertNothingReceived($po, PurchaseOrder::STATUS_ORDERED);
    }

    /** ST-5: another workspace's bin and a bin that never existed are one answer, on the field. */
    #[Test]
    public function aBinNoRowInThisWorkspaceHasIsRefusedAndNamed(): void
    {
        $po = $this->orderedOrderWithLines(['10']);

        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '1', 'storage_location_id' => Uuid::v4()->toRfc4122()]]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '/lines/0/storage_location_id', 'code' => 'unknown_reference']], $this->errorPointers());
        $this->assertNothingReceived($po, PurchaseOrder::STATUS_ORDERED);
    }

    /** Step 7's decision, surfaced: a closed site takes no stock in. */
    #[Test]
    public function anOrderWhoseWarehouseIsArchivedTakesNoReceipt(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$po['warehouse']]);

        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '1', 'storage_location_id' => $po['bin']]]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('warehouse.is_live', $this->json()['rule']);
        $this->assertNothingReceived($po, PurchaseOrder::STATUS_ORDERED);
    }

    /**
     * P-5: a retried timeout is the same receipt, not a second delivery — and
     * still is after that receipt completed the order, which a status check
     * run before the replay read would refuse.
     */
    #[Test]
    public function aRetryReturnsTheSameReceiptAndPostsNothingTwiceEvenAfterItCompletedTheOrder(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $key = Uuid::v4()->toRfc4122();
        $lines = [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '10', 'storage_location_id' => $po['bin']]];

        $this->receive($po['order'], $lines, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json();

        // Cosmetically different, the same request.
        $this->receive(strtoupper($po['order']), [['purchase_order_line_id' => strtoupper($po['lines'][0]), 'quantity' => '10.000000', 'storage_location_id' => $po['bin']]], $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($first, $this->json());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt'));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement'));
        self::assertSame([['quantity_received' => '10.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT quantity_received::text AS quantity_received FROM purchase_order_line WHERE id = ?::uuid', [$po['lines'][0]]));
    }

    /** D-B / §6.2: the same key with a different request is a conflict, and the second request writes nothing. */
    #[Test]
    public function theSameKeyWithADifferentRequestIsAConflictAndWritesNothing(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $key = Uuid::v4()->toRfc4122();

        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '2', 'storage_location_id' => $po['bin']]], $key);
        self::assertSame(201, $this->httpStatus());

        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '3', 'storage_location_id' => $po['bin']]], $key);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertStringContainsString('idempotency-key-conflict', (string) $this->json()['type']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt'));
        self::assertSame([['quantity_received' => '2.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT quantity_received::text AS quantity_received FROM purchase_order_line WHERE id = ?::uuid', [$po['lines'][0]]));
    }

    /** SoD-2: `goods_receipt.create` is the operator's code; a caller without it is refused before anything is read. */
    #[Test]
    public function aCallerWithoutTheReceiptCodeIsRefused(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $this->actAs(self::TENANT_A, ['purchase_order.view', 'purchase_order.create', 'purchase_order.approve']);

        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '1', 'storage_location_id' => $po['bin']]]);

        self::assertSame(403, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->assertNothingReceived($po, PurchaseOrder::STATUS_ORDERED);
    }

    /** One order line into two bins is a split delivery and is accepted; the same line into the same bin twice is refused. */
    #[Test]
    public function aLineMaySplitAcrossBinsButNotRepeatOneBin(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $second = $this->seedLocation(self::TENANT_A, $po['warehouse'], 'A-02');
        $line = $po['lines'][0];

        $this->receive($po['order'], [
            ['purchase_order_line_id' => $line, 'quantity' => '1', 'storage_location_id' => $po['bin']],
            ['purchase_order_line_id' => $line, 'quantity' => '1', 'storage_location_id' => $po['bin']],
        ]);
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '/lines/1/storage_location_id', 'code' => 'duplicate_line']], $this->errorPointers());

        $this->receive($po['order'], [
            ['purchase_order_line_id' => $line, 'quantity' => '6', 'storage_location_id' => $po['bin']],
            ['purchase_order_line_id' => $line, 'quantity' => '4', 'storage_location_id' => $second],
        ]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->assertStatus($po['order'], PurchaseOrder::STATUS_RECEIVED);
    }

    /** `received_at` is when the goods arrived: stored, and it dates the stock movements. A future one is refused. */
    #[Test]
    public function receivedAtDatesTheReceiptAndItsMovementsAndCannotBeInTheFuture(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $lines = [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '1', 'storage_location_id' => $po['bin']]];

        $this->receive($po['order'], $lines, extra: ['received_at' => new \DateTimeImmutable('+1 hour')->format(\DATE_RFC3339)]);
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '/received_at', 'code' => 'in_the_future']], $this->errorPointers());

        $this->receive($po['order'], $lines, extra: ['received_at' => '2026-09-10T08:30:00+02:00']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['at' => '2026-09-10 06:30:00', 'moved' => '2026-09-10 06:30:00']], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT to_char(gr.received_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS at,
                   to_char(sm.occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS moved
              FROM goods_receipt gr JOIN stock_movement sm ON sm.source_id = gr.id
            SQL));
    }

    /**
     * **The statement order, read off the rows themselves.** Every row the
     * receipt wrote carries the same `xmin` (one transaction), and its `cmin` —
     * the command id within that transaction — shows the order: the receipt and
     * its lines, then the order lines, then the stock (movements, levels last),
     * then the order's status. `ReceiveStock`'s docblock requires the caller's own
     * rows to be written BEFORE it is called; an ORM-persisted receipt would be
     * flushed after it. None of these rows is written twice in the transaction,
     * so no combo command id hides the value after commit.
     */
    #[Test]
    public function theReceiptIsWrittenInOneTransactionAndInTheOrderF3Requires(): void
    {
        $po = $this->orderedOrderWithLines(['10', '4']);

        $this->receive($po['order'], [
            ['purchase_order_line_id' => $po['lines'][0], 'quantity' => '1', 'storage_location_id' => $po['bin']],
            ['purchase_order_line_id' => $po['lines'][1], 'quantity' => '1', 'storage_location_id' => $po['bin']],
        ]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $receipt = (string) $this->json()['id'];

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT 1 AS step, xmin::text AS tx, cmin::text::int AS cid FROM goods_receipt WHERE id = ?::uuid
            UNION ALL SELECT 2, xmin::text, cmin::text::int FROM goods_receipt_line WHERE goods_receipt_id = ?::uuid
            UNION ALL SELECT 3, xmin::text, cmin::text::int FROM purchase_order_line WHERE purchase_order_id = ?::uuid
            UNION ALL SELECT 4, xmin::text, cmin::text::int FROM stock_movement WHERE source_id = ?::uuid
            UNION ALL SELECT 5, xmin::text, cmin::text::int FROM stock_level WHERE storage_location_id = ?::uuid
            UNION ALL SELECT 6, xmin::text, cmin::text::int FROM purchase_order WHERE id = ?::uuid
            SQL, [$receipt, $receipt, $po['order'], $receipt, $po['bin'], $po['order']]);

        self::assertSame([1, 2, 2, 3, 3, 4, 4, 5, 5, 6], array_map('intval', array_column($rows, 'step')), 'Not every row the receipt should write was found.');
        // Steps 1-5 are statements in the tenancy transaction and share its xid.
        // Step 6 is the ORM flush, and `flush()` calls `beginTransaction()` at
        // nesting level 2, where DBAL 4 opens a SAVEPOINT: a subtransaction with
        // its own xid, committed with its parent. Measured, not assumed — the
        // first run of this test read 18637 for steps 1-5 and 18638 for step 6.
        // What tells a subtransaction from a SEPARATE transaction is the command
        // id: it counts per top-level transaction, so a separate one would
        // restart near 0. The strictly rising cid below is that proof.
        $statements = array_values(array_filter($rows, static fn (array $r): bool => 6 !== (int) $r['step']));
        self::assertCount(1, array_unique(array_column($statements, 'tx')), 'The receipt was not written in one transaction: '.json_encode($rows));
        self::assertCount(\count($rows), array_unique(array_column($rows, 'cid')), 'The instrument is not live: every write should carry its own command id.');

        $maxCid = [];
        $minCid = [];
        foreach ($rows as $row) {
            $maxCid[$row['step']] = max($maxCid[$row['step']] ?? PHP_INT_MIN, (int) $row['cid']);
            $minCid[$row['step']] = min($minCid[$row['step']] ?? PHP_INT_MAX, (int) $row['cid']);
        }
        for ($step = 1; $step < 6; ++$step) {
            self::assertLessThan($minCid[$step + 1], $maxCid[$step], "Step {$step} was written after step ".($step + 1).'.');
        }
    }

    private function assertStatus(string $orderId, string $status): void
    {
        self::assertSame([['status' => $status]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status FROM purchase_order WHERE id = ?::uuid', [$orderId]));
    }

    /** @param array{order: string, lines: list<string>, bin: string} $po */
    private function assertNothingReceived(array $po, string $status, string $received = '0.000000'): void
    {
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM goods_receipt WHERE purchase_order_id = ?::uuid', [$po['order']]), 'A refused receipt left a goods_receipt row.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM stock_movement sm JOIN storage_location sl ON sl.id = sm.storage_location_id WHERE sl.warehouse_id = (SELECT warehouse_id FROM purchase_order WHERE id = ?::uuid)', [$po['order']]), 'A refused receipt moved stock.');
        foreach ($po['lines'] as $line) {
            self::assertSame([['q' => $received]], $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT quantity_received::text AS q FROM purchase_order_line WHERE id = ?::uuid', [$line]), 'A refused receipt moved an order line.');
            $received = '0.000000';
        }
        $this->assertStatus($po['order'], $status);
    }

    /** @return list<array{pointer: string, code: string}> */
    private function errorPointers(): array
    {
        return array_map(static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors'] ?? []);
    }

    /**
     * An ordered order with one line per quantity, each a different item the
     * supplier lists, and one live bin in the order's warehouse.
     *
     * @param list<string> $quantities
     *
     * @return array{order: string, lines: list<string>, bin: string, warehouse: string, items: list<string>}
     */
    protected function orderedOrderWithLines(array $quantities, string $status = PurchaseOrder::STATUS_ORDERED, string $number = 'PO-1'): array
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme '.$number);
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.$number);
        $order = $this->seedOrder(self::TENANT_A, $number, $supplier, $warehouse, status: $status);

        $lines = [];
        $items = [];
        foreach ($quantities as $i => $quantity) {
            $items[] = $item = $this->seedItem(self::TENANT_A, 'SKU-'.$number.'-'.$i);
            $lines[] = $this->seedLine(self::TENANT_A, $order, $item, lineNumber: $i + 1, quantity: $quantity);
        }

        return [
            'order' => $order,
            'lines' => $lines,
            'bin' => $this->seedLocation(self::TENANT_A, $warehouse, 'A-01'),
            'warehouse' => $warehouse,
            'items' => $items,
        ];
    }

    /** @return string the new row's id */
    protected function seedLocation(string $tenantId, string $warehouseId, string $code): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId,
            'INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
            [$id, $tenantId, $warehouseId, $code]);

        return $id;
    }

    /**
     * @param list<array<string, string>> $lines
     * @param array<string, mixed>         $extra
     */
    protected function receive(string $orderId, array $lines, ?string $key = null, array $extra = []): void
    {
        $this->send('POST', '/purchase-orders/'.$orderId.'/goods-receipts', ['lines' => $lines, ...$extra],
            ['Idempotency-Key' => $key ?? Uuid::v4()->toRfc4122()]);
    }
}
