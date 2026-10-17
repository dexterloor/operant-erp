<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Application\GoodsReceiptWriter;
use App\Modules\Purchasing\Application\PurchaseOrderWriter;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\SharedKernel\ValueObject\AuditEvent;
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
        // A second bin with a different code, so a reader that joins the wrong
        // bin, or hands one line the other's code, renders a real code that is
        // the wrong one.
        $secondBin = $this->seedLocation(self::TENANT_A, $po['warehouse'], 'B-07');
        // Names that are not the SKU (the seeder writes name = sku), so a reader
        // that returns one for the other renders a real value that is wrong.
        $this->renameItems($po['items'], ['Item zero', 'Item one']);

        $this->receive($po['order'], [
            ['purchase_order_line_id' => $po['lines'][0], 'quantity' => '6', 'storage_location_id' => $po['bin']],
            ['purchase_order_line_id' => $po['lines'][1], 'quantity' => '4.000000', 'storage_location_id' => $secondBin],
        ]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertTrue(Uuid::isValid((string) $body['id']));
        self::assertSame('GR-000001', $body['receipt_number']);
        self::assertSame(['id' => $po['order'], 'order_number' => 'PO-000001'], $body['purchase_order']);
        self::assertCount(2, $body['lines']);
        // Every line member but the line's own id, by name, value and order:
        // API-23b clause (i)'s `purchase_order_line = {id, line_number}` (owner
        // ruling Q1(b)), the item under clause (ii) as `{id, sku, name}` (Q1(c),
        // Q-A), and `storage_location = {id, code}` — each key, every member,
        // nothing else.
        self::assertSame(
            [
                [
                    'purchase_order_line' => ['id' => $po['lines'][0], 'line_number' => 1],
                    'inventory_item' => ['id' => $po['items'][0], 'sku' => 'SKU-PO-000001-0', 'name' => 'Item zero'],
                    'quantity' => '6.000000',
                    'storage_location' => ['id' => $po['bin'], 'code' => 'A-01'],
                ],
                [
                    'purchase_order_line' => ['id' => $po['lines'][1], 'line_number' => 2],
                    'inventory_item' => ['id' => $po['items'][1], 'sku' => 'SKU-PO-000001-1', 'name' => 'Item one'],
                    'quantity' => '4.000000',
                    'storage_location' => ['id' => $secondBin, 'code' => 'B-07'],
                ],
            ],
            array_map(static fn (array $l): array => array_diff_key($l, ['id' => true]), $body['lines']),
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

    /**
     * **API-O-33, option (a) — the receipt names who booked it.** §4.4.3's
     * GoodsReceipt row carries `received_by = {id, display_name}`, the
     * `ActorReference` expansion of `goods_receipt.received_by_user_id`, on the
     * 201 and on the read alike.
     *
     * The order is given a creator of its own, with a different name, so a reader
     * that expanded the order's `created_by` instead of the receipt's receiver
     * renders a real person who is the wrong one — and this fails on the id and
     * on the name, not only on a null.
     */
    #[Test]
    public function aReceiptNamesTheUserWhoBookedItOnTheCreateAndOnTheRead(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $orderer = $this->seedPrincipal(self::TENANT_A, []);
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE app_user SET display_name = 'Olga Orderer' WHERE id = ?::uuid", [$orderer]);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE purchase_order SET created_by_user_id = ?::uuid WHERE id = ?::uuid', [$orderer, $po['order']]);
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE app_user SET display_name = 'Rita Receiver' WHERE id = ?::uuid", [$this->actorId]);

        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '3', 'storage_location_id' => $po['bin']]]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $created = $this->json();
        self::assertSame(['id' => $this->actorId, 'display_name' => 'Rita Receiver'], $created['received_by']);

        $this->send('GET', '/goods-receipts/'.$created['id']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $read = $this->json();
        // §4.4.3's whole GoodsReceipt shape, as an exact key set.
        $keys = array_keys($read);
        sort($keys);
        self::assertSame(['id', 'lines', 'purchase_order', 'receipt_number', 'received_at', 'received_by'], $keys);
        self::assertSame(['id' => $this->actorId, 'display_name' => 'Rita Receiver'], $read['received_by']);
        self::assertSame([], ItemReadTest::tenantKeysIn($read));
    }

    /**
     * **B-4a (roles slice 2, C1, 2026-09-29): a receipt writes exactly one `goods_receipt.created` row, naming the
     * receiver** — `SEC`'s filing of long standing (`SECURITY_MODEL.md` §4.1, confirmed in
     * `docs/session13/roles-s2-sec.md` §3): business, subject the receipt, `{}`, no IP. Before this commit posting a
     * receipt left nothing on the trail. **Negative in the same test:** an over-receipt, refused before any write, adds
     * no row. The replay half (no second row on a key retry) is `IdempotencyTest::replayCreateGoodsReceipt`.
     */
    #[Test]
    public function aReceiptWritesExactlyOneGoodsReceiptCreatedRowAndARefusedOneWritesNone(): void
    {
        $po = $this->orderedOrderWithLines(['10']);

        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '11', 'storage_location_id' => $po['bin']]]);
        self::assertSame(409, $this->httpStatus(), 'Control: the over-receipt was not refused.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::GOODS_RECEIPT_CREATED), 'A refused receipt wrote a trail row.');

        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '3', 'storage_location_id' => $po['bin']]]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        self::assertSame([$this->businessRow(AuditEvent::GOODS_RECEIPT_CREATED, 'goods_receipt', $this->json()['id'])],
            $this->auditRowsOf(AuditEvent::GOODS_RECEIPT_CREATED),
            'CreateGoodsReceipt did not write exactly one goods_receipt.created row naming the receiver (B-4a).');
    }

    /**
     * The column is nullable by design (`Version20260911000400`: a writer that is
     * not a person may post receipts), and §4.4.3 says a receipt with no recorded
     * receiver renders as unknown — `null`, the key present, never `{}` and never
     * an error. No API path writes NULL, so the owner does.
     */
    #[Test]
    public function aReceiptWithNoRecordedReceiverRendersANullReceiver(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '3', 'storage_location_id' => $po['bin']]]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $id = (string) $this->json()['id'];
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE goods_receipt SET received_by_user_id = NULL WHERE id = ?::uuid', [$id]);

        $this->send('GET', '/goods-receipts/'.$id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertArrayHasKey('received_by', $body);
        self::assertNull($body['received_by']);
    }

    /**
     * **A receipt line names its ordered line by number and its item — by value,
     * not by position** (owner rulings Q1(b), Q1(c) and Q-A, 2026-09-15).
     *
     * Three lines are ordered, each for a different item renamed away from its
     * SKU; only lines 3 and 2 are received, **in that request order**. So a
     * positional number (`ROW_NUMBER()` over the receipt) reads `[1, 2]` where the
     * order line's column reads `[2, 3]`, and an item joined through the wrong
     * order line renders a real item that is the wrong one. The reader orders
     * lines by the order line's number, on the 201 and on the read alike.
     */
    #[Test]
    public function aReceiptLineNamesItsOrderLineByNumberAndItsItem(): void
    {
        $po = $this->orderedOrderWithLines(['5', '5', '5']);
        $this->renameItems($po['items'], ['Item zero', 'Item one', 'Item two']);
        $secondBin = $this->seedLocation(self::TENANT_A, $po['warehouse'], 'B-07');

        $this->receive($po['order'], [
            ['purchase_order_line_id' => $po['lines'][2], 'quantity' => '2', 'storage_location_id' => $po['bin']],
            ['purchase_order_line_id' => $po['lines'][1], 'quantity' => '3', 'storage_location_id' => $secondBin],
        ]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $expected = [
            [['id' => $po['lines'][1], 'line_number' => 2], ['id' => $po['items'][1], 'sku' => 'SKU-PO-000001-1', 'name' => 'Item one']],
            [['id' => $po['lines'][2], 'line_number' => 3], ['id' => $po['items'][2], 'sku' => 'SKU-PO-000001-2', 'name' => 'Item two']],
        ];
        $pairs = static fn (array $body): array => array_map(
            static fn (array $l): array => [$l['purchase_order_line'], $l['inventory_item']],
            $body['lines'],
        );
        self::assertSame($expected, $pairs($this->json()));

        $this->send('GET', '/goods-receipts/'.$this->json()['id']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($expected, $pairs($this->json()));
    }

    /**
     * **An item archived after the delivery is still named on the receipt**, for
     * the bin's reason: the item join carries no `deleted_at IS NULL`, so
     * tidying a catalogue neither drops the line nor un-names what arrived.
     */
    #[Test]
    public function aReceiptWhoseItemWasLaterArchivedStillNamesTheItem(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '3', 'storage_location_id' => $po['bin']]]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $id = (string) $this->json()['id'];
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid', [$po['items'][0]]);

        $this->send('GET', '/goods-receipts/'.$id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(
            [['id' => $po['items'][0], 'sku' => 'SKU-PO-000001-0', 'name' => 'SKU-PO-000001-0']],
            array_column($this->json()['lines'], 'inventory_item'),
        );
    }

    /**
     * **A bin retired after the delivery still names where the goods went.**
     * API-23b's `storage_location = {id, code}` is joined with no
     * `deleted_at IS NULL`, as the receiver is: archiving a bin does not un-book
     * the stock that was put in it, and a filtered join would drop the line.
     */
    #[Test]
    public function aReceiptWhoseBinWasLaterArchivedStillNamesTheBin(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '3', 'storage_location_id' => $po['bin']]]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $id = (string) $this->json()['id'];
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$po['bin']]);

        $this->send('GET', '/goods-receipts/'.$id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['id' => $po['bin'], 'code' => 'A-01']], array_column($this->json()['lines'], 'storage_location'));
    }

    /**
     * **Mirrors `created_by`, and says so:** `PurchaseOrderRow` joins `app_user`
     * with no `deleted_at` filter, so an archived creator is still named. The
     * receiver is joined the same way — a receipt records a fact about a past
     * delivery, and archiving the person who booked it does not un-book it.
     *
     * The read is made by a SECOND principal holding only `goods_receipt.view`,
     * which is the PC-11 (4a) ruling itself: whoever may read the receipt may read
     * its receiver, and no further code is needed.
     */
    #[Test]
    public function aReceiverWhoWasArchivedIsStillNamedToAnyoneWhoMayReadTheReceipt(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '3', 'storage_location_id' => $po['bin']]]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $id = (string) $this->json()['id'];
        $receiver = (string) $this->actorId;
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$receiver]);

        $this->actAs(self::TENANT_A, ['goods_receipt.view']);
        $this->send('GET', '/goods-receipts/'.$id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $receiver, 'display_name' => 'Fixture Actor'], $this->json()['received_by']);
    }

    /** No new surface: a caller without `goods_receipt.view` gets the existing denial, and the receiver is not in it. */
    #[Test]
    public function aCallerWithoutTheViewCodeIsRefusedAndTheReceiverIsNotDisclosed(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '3', 'storage_location_id' => $po['bin']]]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $id = (string) $this->json()['id'];
        $receiver = (string) $this->actorId;

        $this->actAs(self::TENANT_A, array_values(array_diff(self::everyDeclaredPermission(), ['goods_receipt.view'])));
        $this->send('GET', '/goods-receipts/'.$id);

        $content = (string) $this->client()->getResponse()->getContent();
        self::assertSame(403, $this->httpStatus(), $content);
        self::assertStringNotContainsString($receiver, $content);
        self::assertStringNotContainsString('received_by', $content);
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
        $other = $this->orderedOrderWithLines(['10'], number: 'PO-000002');

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
     * **Two receipts from two orders into one bin, whose sum no single receipt can see, are refused as a business rule
     * when the second would push the level past numeric(19,6) — not a 500.**
     *
     * Shipped defect, fixed 2026-10-16 (owner told the same day): `StockReceiver` bounded only the per-receipt sum
     * (`received_quantity_fits`); the upsert into an EXISTING level was `qty_on_hand + EXCLUDED.qty_on_hand` with no
     * bound, so the second receipt failed with `SQLSTATE[22003]` → HTTP 500. Each order line's quantity fits its own
     * column, so nothing upstream could refuse it. The refusal names the ordered line by its `line_number` (V2-C-19).
     *
     * **Mutation:** drop the upsert's `WHERE` (or the refusal after it) → red, HTTP 500 with 22003.
     */
    #[Test]
    public function aSecondOrdersReceiptThatWouldOverfillTheBinsLevelIsRefusedAndWritesNothing(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme OVF');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH OVF');
        $bin = $this->seedLocation(self::TENANT_A, $warehouse, 'A-01');
        $item = $this->seedItem(self::TENANT_A, 'SKU-OVF');
        $first = $this->seedOrder(self::TENANT_A, 'PO-000001', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED);
        $firstLine = $this->seedLine(self::TENANT_A, $first, $item, quantity: '9999999999999.000000', unitCost: '0.0000');
        $second = $this->seedOrder(self::TENANT_A, 'PO-000002', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED);
        $secondLine = $this->seedLine(self::TENANT_A, $second, $item, lineNumber: 3, quantity: '2.000000', unitCost: '0.0000');

        $this->receive($first, [['purchase_order_line_id' => $firstLine, 'quantity' => '9999999999999', 'storage_location_id' => $bin]]);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the first receipt. '.(string) $this->client()->getResponse()->getContent());

        $this->receive($second, [['purchase_order_line_id' => $secondLine, 'quantity' => '1', 'storage_location_id' => $bin]]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('stock_level.on_hand_fits', $this->json()['rule']);
        self::assertSame('Order line 3: receiving this would take the stock of this item in this location past the largest quantity that can be recorded. Nothing was received. The figure on record there may be wrong; it can be corrected by a stock count.', $this->json()['detail']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM goods_receipt WHERE purchase_order_id = ?::uuid', [$second]), 'A refused receipt left a goods_receipt row.');
        self::assertSame([['q' => '0.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT quantity_received::text AS q FROM purchase_order_line WHERE id = ?::uuid', [$secondLine]), 'A refused receipt moved its order line.');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM stock_movement WHERE storage_location_id = ?::uuid', [$bin]), 'A refused receipt moved stock.');
        self::assertSame([['q' => '9999999999999.000000', 'version' => 1]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q, version FROM stock_level WHERE storage_location_id = ?::uuid', [$bin]), 'A refused receipt moved the level.');

        $this->receive($second, [['purchase_order_line_id' => $secondLine, 'quantity' => '0.999999', 'storage_location_id' => $bin]]);
        self::assertSame(201, $this->httpStatus(), 'The bound is inclusive: a receipt that lands the level exactly on the maximum fits. '.(string) $this->client()->getResponse()->getContent());
        self::assertSame([['q' => '9999999999999.999999', 'version' => 2]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q, version FROM stock_level WHERE storage_location_id = ?::uuid', [$bin]));
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
            VALUES (?::uuid, ?::uuid, 'PO-120000', ?::uuid, ?::uuid, 'ordered', 'EUR',
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

    /**
     * **A key already used, sent at an order that does not exist for this caller,
     * is 404 — not 409.** §5.3 / ST-5: a row the caller cannot see and a row that
     * does not exist are one answer, and that answer comes **before** anything
     * the request carries is judged. Otherwise the refusal itself tells the
     * caller the key was used, which is a fact about this workspace's traffic.
     *
     * Step 8-ii read the replay row before it read the order, so this returned
     * `idempotency-key-conflict`. Nothing crossed a tenant boundary — the replay
     * read is under RLS, so it only ever saw this workspace's own row — but the
     * status was wrong and it was wrong in the direction that answers a question
     * the caller had not earned.
     *
     * The order id here is simply unknown. The other-workspace case is the same
     * row under RLS and is covered by the tenancy suite; what this pins is the
     * **order of the two checks**.
     *
     * *Red before the fix: 409.*
     */
    #[Test]
    public function aReusedKeyAgainstAnOrderThatIsNotThereIsNotFound(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $key = Uuid::v4()->toRfc4122();

        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '1', 'storage_location_id' => $po['bin']]], $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->receive(Uuid::v4()->toRfc4122(),
            [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '1', 'storage_location_id' => $po['bin']]], $key);

        self::assertSame(404, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt'));
    }

    /**
     * **A replay still answers even when the order has been archived since.**
     * The not-found check moved in front of the replay read for the test above,
     * and this is the half that must NOT move with it: the archived refusal stays
     * **behind** the replay, because a receipt that was accepted is a fact, and a
     * retry of the request that created it is answered from the row whatever has
     * happened to the order since.
     */
    #[Test]
    public function aRetryIsStillAnsweredAfterTheOrderWasArchived(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $key = Uuid::v4()->toRfc4122();
        $lines = [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '1', 'storage_location_id' => $po['bin']]];

        $this->receive($po['order'], $lines, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json();

        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE purchase_order SET deleted_at = now() WHERE id = ?::uuid', [$po['order']]);

        $this->receive($po['order'], $lines, $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($first, $this->json());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt'));
    }

    /**
     * **A new receipt against an archived order is refused, and the refusal promises no restore** (`owed-ux-c5.md` FINAL
     * (a3)): it read *"Restore it before changing it"*, pointing at `RestorePurchaseOrder`, which is unbuilt (G-8). Reachable
     * only by owner SQL today (an ordered order cannot be deleted through the API), used here, as in the retry test above.
     * **Mutation:** put the old sentence back in `GoodsReceiptWriter`; red here on the `detail` literal.
     */
    #[Test]
    public function aNewReceiptAgainstAnArchivedOrderIsRefusedWithoutPromisingARestore(): void
    {
        $po = $this->orderedOrderWithLines(['10']);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE purchase_order SET deleted_at = now() WHERE id = ?::uuid', [$po['order']]);

        $this->receive($po['order'], [['purchase_order_line_id' => $po['lines'][0], 'quantity' => '1', 'storage_location_id' => $po['bin']]]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.is_archived', $this->json()['rule']);
        self::assertSame('This purchase order is archived, so nothing can be received against it.', $this->json()['detail']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt'));
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
    /**
     * `received_at` dates the receipt and every movement it posts; an hour ahead
     * is refused, and a clock a few seconds fast is clamped rather than refused.
     *
     * **The tolerance is 60 seconds and the clamp is to the server's now** — see
     * `GoodsReceiptWriter::receivedAt()` for why a field F-3 never collects
     * needed one. The clamped case runs **last** deliberately: it writes a second
     * receipt, and the backdated assertion above reads the whole table.
     */
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

        // A clock a few seconds fast is tolerated, and the stored instant is the
        // server's own now rather than the client's future one.
        $before = new \DateTimeImmutable();
        $this->receive($po['order'], $lines, extra: ['received_at' => $before->modify('+5 seconds')->format(\DATE_RFC3339)]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $stored = new \DateTimeImmutable((string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT received_at FROM goods_receipt WHERE id = ?::uuid', [$this->json()['id']])[0]['received_at']);
        self::assertGreaterThanOrEqual($before, $stored, 'The clamped instant went backwards.');
        self::assertLessThanOrEqual(new \DateTimeImmutable(), $stored,
            'A received_at inside the tolerance was stored as sent, so a receipt is dated in the future.');
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

    /**
     * **An over-receipt names the ORDERED line by its `line_number`; a duplicate still names the earlier request line by its
     * pointer.**
     *
     * Both used to read `Line %d` with the 0-based array index — *"Line 0"* for the first line — which a caller reads as an
     * ordinal and corrects the wrong line. 8a's answer was the pointer (`/lines/1`). **V2-C-19 (V4 B3e, 2026-09-16)** moved
     * the over-receipt half on: `business-rule-violated` carries no pointer, a JSON pointer is not the user's terms (ST-3),
     * and since owner ruling Q1(b) the ordered line's `line_number` is on the wire — it is what the receive page shows. The
     * rule is about the ORDER line, so its number is the one to name.
     *
     * **The fixture is built so the three readings differ.** The over-received order line is `line_number` 2 and is sent
     * FIRST in the receipt, so its pointer is `/lines/0` and a 0-based ordinal would say 0. The message is pinned exactly.
     *
     * **The two numbers are rendered under QR-2 (`docs/ux/STATES.md` §QR), and this pin moved with the string on
     * 2026-09-18 — CD-1 step 2.** Until then it read *"would reach 11.000000 received of 10.000000 ordered"*, the
     * column's scale printed at a person. CD-1's hard trigger is that *a change to the refusal string or to its pin
     * without the rule landing first re-opens this entry rather than closing it*, so the rule, the string and this
     * assertion are one commit. Both values are QR-5 system-held — `bcadd(…, 6)` output and a stored
     * `numeric(19,6)` column — and neither is an echo of what the person typed.
     *
     * **Case (a2) is why this test has a second over-receipt and not just a tidier first one.** `10`/`11` are whole,
     * so a "strip everything after the point" implementation would pass (a) exactly. (a2) orders `10.5` and receives
     * `0.75`, which must render `11.25` and `10.5` — partial trims, both of which that implementation would get wrong.
     *
     * **Mutation:** revert the over-receipt message to the pointer text → red on the exact message. **Second
     * mutation:** interpolate `$after` and `quantity_ordered` raw again → red on (a) *and* (a2).
     */
    #[Test]
    public function anOverReceiptNamesTheOrderedLineByItsNumberAndADuplicateByItsPointer(): void
    {
        $po = $this->orderedOrderWithLines(['10', '10']);
        // Both fixtures are seeded before the first request: a refused receipt
        // closes the EntityManager, so nothing can be seeded after (a).
        $fractional = $this->orderedOrderWithLines(['10.5'], number: 'PO-000002');

        // (a) `business-rule-violated` carries no pointer, so the ORDER line is
        //     named in the DETAIL, by its number. Line 2 is over-received and is
        //     the first entry of the receipt.
        $this->receive($po['order'], [
            ['purchase_order_line_id' => $po['lines'][1], 'quantity' => '11', 'storage_location_id' => $po['bin']],
            ['purchase_order_line_id' => $po['lines'][0], 'quantity' => '1', 'storage_location_id' => $po['bin']],
        ]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order_line.is_not_over_received', $this->json()['rule']);
        self::assertSame(
            'Order line 2 would reach 11 received of 10 ordered. Over-receipt is not accepted.',
            (string) $this->json()['detail'],
            'The over-receipt refusal does not name the ordered line by the number the receive page shows (V2-C-19), '
            .'or does not render its quantities under QR-2 (CD-1).',
        );

        // (a2) A fractional order line, over-received by a fraction: QR-2 trims
        //      the scale and keeps every digit the columns hold.
        $this->receive($fractional['order'], [
            ['purchase_order_line_id' => $fractional['lines'][0], 'quantity' => '11.25', 'storage_location_id' => $fractional['bin']],
        ]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(
            'Order line 1 would reach 11.25 received of 10.5 ordered. Over-receipt is not accepted.',
            (string) $this->json()['detail'],
            'QR-2 trims trailing zeros and nothing else: a partial trim must keep every digit the column holds.',
        );

        // (b) A validation error carries its own pointer for the DUPLICATE, and
        //     names the line it duplicates in the message. That one is the first.
        $this->receive($po['order'], [
            ['purchase_order_line_id' => $po['lines'][0], 'quantity' => '1', 'storage_location_id' => $po['bin']],
            ['purchase_order_line_id' => $po['lines'][0], 'quantity' => '1', 'storage_location_id' => $po['bin']],
        ]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '/lines/1/storage_location_id', 'code' => 'duplicate_line']], $this->errorPointers());
        $message = (string) $this->json()['errors'][0]['message'];
        self::assertStringContainsString('/lines/0', $message, 'The duplicate refusal does not name the line already receiving that pair.');
        self::assertStringNotContainsString('Line ', $message,
            'The duplicate refusal names the earlier line as an ordinal, and it is 0-based — it printed "Line 0" for '
            .'the first line, which is not a line number any caller can act on.');
    }

    /**
     * **`lines[]` is capped, and the receipt is the side of the asymmetry that
     * needed it most.**
     *
     * 8a capped `CreatePurchaseOrder` at
     * {@see \App\Modules\Purchasing\Application\PurchaseOrderWriter::MAXIMUM_LINES}
     * and left this uncapped, and the reviewer found the asymmetry was **the
     * wrong way round**: `CreatePurchaseOrder` holds **no** row lock — it
     * inserts an order nobody can address until it commits — while
     * `CreateGoodsReceipt` takes `PurchaseOrderRowLock::holdInside()` on the
     * order **before** it inserts anything, and then inserts one
     * `goods_receipt_line` per request line, one `UPDATE` per order line, and
     * one stock movement per line, all inside that lock.
     *
     * **So the honest reason is the one 8a could not use, and it is not the one
     * 8a's first attempt gave.** (8a's two false reasons are recorded as false
     * in that constant's docblock — the number is §10.1/API-17's, not §4.2's,
     * and `ListPurchaseOrderLines` is unpaginated, so there is no page to
     * overflow. Neither is repeated here.) An uncapped receipt is an unbounded
     * amount of work performed **while holding a lock every other write to that
     * order has to wait behind** — approval, cancellation, every line edit, and
     * every other delivery. The cap bounds how long one caller can hold it.
     *
     * **The number is tied, not derived**, exactly as 8a tied its own: the two
     * bound different things, and what the tie says is that the largest batch
     * this API accepts in one request against one order is **one magnitude,
     * reviewed in one place**. The tie is asserted here, in a test, rather than
     * by importing `PurchaseOrderWriter::MAXIMUM_LINES` into
     * `GoodsReceiptWriter` — a production import would add a new
     * `PurchasingApplication → PurchasingApplication` class-pair edge to
     * `deptrac.yaml`, which registers those edges per class pair.
     *
     * **Mutations:** raise the cap in `GoodsReceiptWriter` alone → the tie is
     * red; remove the cap → the edge case is red with 201.
     */
    #[Test]
    public function aReceiptWithMoreLinesThanTheCapIsRefusedBeforeAnythingIsLocked(): void
    {
        self::assertSame(PurchaseOrderWriter::MAXIMUM_LINES, GoodsReceiptWriter::MAXIMUM_LINES,
            'The two write paths into one purchase order accept different numbers of lines in one request. They bound '
            .'different mechanisms — one an unlocked insert, one an insert holding the order\'s row lock — but they are '
            .'one magnitude and are reviewed together. If one moves deliberately, move this assertion with it and say '
            .'in both docblocks why they now differ.');

        // **The over-cap request is otherwise VALID**, which is what makes the
        // "remove the cap" mutation worth running: one bin per line, so no line
        // duplicates another, and an order quantity that covers all of them, so
        // nothing over-receipts. With the cap removed this request is a 201 and
        // writes every one of those lines. A fixture of identical lines would
        // have been refused by the duplicate rule either way, and the cap would
        // never have had to be the thing that spoke.
        $po = $this->orderedOrderWithLines([(string) (GoodsReceiptWriter::MAXIMUM_LINES + 1)]);

        $lines = [];
        for ($i = 0; $i <= GoodsReceiptWriter::MAXIMUM_LINES; ++$i) {
            $lines[] = [
                'purchase_order_line_id' => $po['lines'][0],
                'quantity' => '1',
                'storage_location_id' => $this->seedLocation(self::TENANT_A, $po['warehouse'], \sprintf('B-%04d', $i)),
            ];
        }

        $this->receive($po['order'], $lines);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('too_many_lines', array_column($this->json()['errors'], 'code', 'pointer')['/lines'] ?? null);
        $this->assertNothingReceived($po, PurchaseOrder::STATUS_ORDERED);
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
    protected function orderedOrderWithLines(array $quantities, string $status = PurchaseOrder::STATUS_ORDERED, string $number = 'PO-000001'): array
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

    /**
     * Renames each item so its `name` differs from its `sku` (the seeder writes
     * `name = sku`).
     *
     * @param list<string> $items
     * @param list<string> $names
     */
    protected function renameItems(array $items, array $names): void
    {
        foreach ($items as $i => $item) {
            $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE inventory_item SET name = ? WHERE id = ?::uuid', [$names[$i], $item]);
        }
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
