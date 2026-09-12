<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use PHPUnit\Framework\Attributes\DataProvider;
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
    #[DataProvider('ordersThatTakeNoReceipt')]
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
    #[DataProvider('overReceipts')]
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

    /**
     * **What the digest is taken over, pinned as a constant — the check that
     * makes the field set a decision rather than an accident.**
     *
     * §6.2 asks for two things of a `unique:` operation and step 8-ii shipped
     * one. The conflict case existed, but it varied **quantity** alone, so every
     * other digested field was unheld: delete `received_at` from
     * `GoodsReceiptWriter`'s digest and the whole suite stayed green, while a
     * retry that backdated a delivery to a different day was silently answered
     * with the first receipt. `CreatePurchaseOrder` has carried this pin since
     * `f67173b`; this is its port, and the gap between the two is the finding.
     *
     * The constant is **derived from §6.2's rules rather than copied out of a
     * run** — operation id, fields sorted by name, the instant in one UTC form,
     * quantities at scale 6, ids in canonical lower case, and the line list
     * ordered by `(purchase_order_line_id, storage_location_id)`. Every input
     * below is a fixed literal for that reason.
     *
     * **If this goes red, a stored digest and the digest of its own honest retry
     * have parted.** Every receipt written before the change would answer its
     * next retry with a 409. That is a migration story, not a new constant.
     *
     * **Mutations, run** (1 occurrence counted in the writer each time, reverted
     * `cmp`-identical): each of the four digested fields removed in turn —
     * `received_at`, `purchase_order_line_id`, `quantity`,
     * `storage_location_id`. Each reddened this test **and** its own case of
     * `aRetryThatChangesOneAcceptedFieldIsAConflict`, and nothing else. Before
     * this pair existed, three of those four mutations left the entire suite
     * green.
     *
     * **What this test does NOT catch, stated because the tidier claim was
     * measured and is false:** removing the line sort leaves it green. The pinned
     * request has **one** line, so no order exists to be wrong, and it is one line
     * on purpose — a pin is worth more when its canonical form can be reproduced
     * by hand. The sort is held by
     * `aRetryThatReordersItsLinesIsAReplayAndNotAConflict` alone, which was the
     * one red result of that mutation.
     */
    #[Test]
    public function theStoredDigestOfAFixedRequestIsPinned(): void
    {
        $order = '60005e17-0000-4000-8000-000000000001';
        $line = '60005e17-0000-4000-8000-000000000002';
        $bin = '60005e17-0000-4000-8000-000000000003';

        $supplier = $this->seedSupplier(self::TENANT_A, 'Pinned');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Pinned');
        $item = $this->seedItem(self::TENANT_A, 'SKU-PINNED');

        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO purchase_order
                (id, tenant_id, order_number, supplier_id, warehouse_id, status, currency_code,
                 ordered_at, idempotency_key, request_digest)
            VALUES (?::uuid, ?::uuid, 'PO-PINNED', ?::uuid, ?::uuid, 'ordered', 'EUR',
                    '2026-01-01T00:00:00+00:00'::timestamptz, ?, ?)
            SQL, [$order, self::TENANT_A, $supplier, $warehouse,
                str_repeat('a', 64), str_repeat('b', 64)]);
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO purchase_order_line
                (id, tenant_id, purchase_order_id, inventory_item_id, line_number,
                 quantity_ordered, quantity_received, unit_cost, lead_time_days)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 1, 10, 0, 2.5, 7)
            SQL, [$line, self::TENANT_A, $order, $item]);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
            [$bin, self::TENANT_A, $warehouse, 'PIN-01']);

        $this->receive($order, [['purchase_order_line_id' => $line, 'quantity' => '4', 'storage_location_id' => $bin]],
            'pinned-receipt-key-1', ['received_at' => '2026-09-01T12:00:00+02:00']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $row = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT idempotency_key, request_digest FROM goods_receipt WHERE id = ?::uuid', [$this->json()['id']])[0];

        self::assertSame('5b62ba53b9e3b93b39570666c6e78d417ff257631621d582950ada6b006e140e', $row['request_digest'],
            'The writer digests a different field set, spells a field differently, or orders the lines '
            .'differently. Every digest stored before this change will answer its next honest retry with a 409.');
        self::assertSame('a78115bfefbdc942ada7f1e9c85fa7baf41691966ae72d6e9715b003fea61f6f', $row['idempotency_key']);
    }

    /**
     * **Every accepted field is in the digest, one case each.** §6.2's conflict
     * clause is only worth what the field set behind it is worth, and step 8-ii's
     * single quantity case held one field of four.
     *
     * Each case sends the same request twice under one key, changing exactly one
     * accepted field, and requires 409 and no second row. `received_at` is
     * included deliberately: it is the field §4.2 accepts and F-3 never collects,
     * so it is the one a reader is most likely to think does not matter.
     *
     * **Mutation, run:** each field removed from `GoodsReceiptWriter`'s digested
     * array in turn, one at a time (1 occurrence each, counted before the run) —
     * its own case red, the other three green, then reverted `cmp`-identical.
     *
     * @param callable(array{order: string, lines: list<string>, bin: string, secondBin: string}): array{0: array<string, mixed>, 1: array<string, mixed>} $requests
     */
    #[Test]
    #[DataProvider('oneAcceptedFieldChanged')]
    public function aRetryThatChangesOneAcceptedFieldIsAConflict(callable $requests): void
    {
        $po = $this->orderedOrderWithLines(['10', '10']);
        $po['secondBin'] = $this->seedLocation(self::TENANT_A, $po['warehouse'], 'A-02');
        $key = Uuid::v4()->toRfc4122();

        [$first, $second] = $requests($po);

        $this->receive($po['order'], $first['lines'], $key, array_diff_key($first, ['lines' => null]));
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->receive($po['order'], $second['lines'], $key, array_diff_key($second, ['lines' => null]));

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertStringContainsString('idempotency-key-conflict', (string) $this->json()['type']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt'));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt_line'));
    }

    /** @return iterable<string, array{0: callable}> */
    public static function oneAcceptedFieldChanged(): iterable
    {
        $line = static fn (array $po, int $which, string $quantity, string $bin): array
            => [['purchase_order_line_id' => $po['lines'][$which], 'quantity' => $quantity, 'storage_location_id' => $po[$bin]]];

        yield 'received_at' => [static fn (array $po): array => [
            ['lines' => $line($po, 0, '4', 'bin'), 'received_at' => '2026-09-01T10:00:00+00:00'],
            ['lines' => $line($po, 0, '4', 'bin'), 'received_at' => '2026-09-01T11:00:00+00:00'],
        ]];

        yield 'purchase_order_line_id' => [static fn (array $po): array => [
            ['lines' => $line($po, 0, '4', 'bin')],
            ['lines' => $line($po, 1, '4', 'bin')],
        ]];

        yield 'quantity' => [static fn (array $po): array => [
            ['lines' => $line($po, 0, '4', 'bin')],
            ['lines' => $line($po, 0, '5', 'bin')],
        ]];

        yield 'storage_location_id' => [static fn (array $po): array => [
            ['lines' => $line($po, 0, '4', 'bin')],
            ['lines' => $line($po, 0, '4', 'secondBin')],
        ]];
    }

    /**
     * **A goods receipt has no line order, so the digest must not keep one.**
     *
     * `RequestDigest` preserves list order on purpose — for `CreatePurchaseOrder`
     * it is right, because `purchase_order_line.line_number` is minted from the
     * position and line 1 and line 2 swapped is a different order.
     * `goods_receipt_line` has **no line number**: the row is identified by
     * `(purchase_order_line_id, storage_location_id)`, and sending the same two
     * lines the other way round records exactly the same delivery. Under the
     * shipped digest that retry was a 409 — the client did what P-5 asks and was
     * told it asked for something else.
     *
     * The writer therefore sorts the digested list by that pair. The sort is
     * **total**, which is why it is safe: the same pair twice in one receipt is
     * already refused as a duplicate line, so no two entries can compare equal.
     *
     * *Red before the fix: 409 here.*
     */
    #[Test]
    public function aRetryThatReordersItsLinesIsAReplayAndNotAConflict(): void
    {
        $po = $this->orderedOrderWithLines(['10', '10']);
        $key = Uuid::v4()->toRfc4122();

        $first = [
            ['purchase_order_line_id' => $po['lines'][0], 'quantity' => '1', 'storage_location_id' => $po['bin']],
            ['purchase_order_line_id' => $po['lines'][1], 'quantity' => '2', 'storage_location_id' => $po['bin']],
        ];

        $this->receive($po['order'], $first, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $receipt = $this->json();

        $this->receive($po['order'], array_reverse($first), $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($receipt, $this->json());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt'));
        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt_line'));
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
