<?php

declare(strict_types=1);

namespace App\Tests\Inventory;

use App\Modules\Inventory\Application\Contract\NoCallerTransaction;
use App\Modules\Inventory\Application\Contract\ReceiveStock;
use App\Modules\Inventory\Application\Contract\StockReceipt;
use App\Modules\Inventory\Application\Contract\StockReceiptLine;
use App\Modules\Inventory\Application\Contract\StockReceiptRefused;
use App\Platform\Tenancy\TenantContextViolation;
use App\Tests\Api\ApiTestCase;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * `Inventory\Application\Contract\ReceiveStock` — the goods-receipt half of
 * ARCHITECTURE §5.5's two cross-module write edges (W-3), driven the way step
 * 8's caller will drive it: from inside a tenancy transaction the caller opened.
 *
 * Extends `ApiTestCase` for one thing only — `actAs()` sets the request identity
 * that `CurrentTenant` resolves, which is the seam every writer in this tree
 * takes its tenant from. No request is sent.
 */
final class ReceiveStockTest extends ApiTestCase
{
    #[Test]
    public function aReceiptWritesOneMovementPerLineAndCreatesTheLevelsItTouches(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A);
        $bin = $this->seedLocation(self::TENANT_A, $warehouse, 'A-01');
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $nuts = $this->seedItem(self::TENANT_A, 'NUT');
        $goodsReceipt = Uuid::v4()->toRfc4122();

        $this->tenantContext()->run(self::TENANT_A, fn () => $this->receiver()->receive(new StockReceipt(
            goodsReceiptId: $goodsReceipt,
            warehouseId: $warehouse,
            occurredAt: new \DateTimeImmutable('2026-09-11T08:00:00+00:00'),
            lines: [
                new StockReceiptLine($bolts, $bin, '5'),
                new StockReceiptLine($nuts, $bin, '2.5'),
            ],
        )));

        self::assertSame([
            ['inventory_item_id' => $bolts, 'quantity' => '5.000000', 'reason' => 'receipt', 'source_type' => 'goods_receipt', 'source_id' => $goodsReceipt, 'occurred' => '2026-09-11 08:00:00'],
            ['inventory_item_id' => $nuts, 'quantity' => '2.500000', 'reason' => 'receipt', 'source_type' => 'goods_receipt', 'source_id' => $goodsReceipt, 'occurred' => '2026-09-11 08:00:00'],
        ], $this->movements(self::TENANT_A, $bin));

        self::assertSame([
            ['inventory_item_id' => $bolts, 'qty_on_hand' => '5.000000', 'qty_reserved' => '0.000000', 'version' => 1],
            ['inventory_item_id' => $nuts, 'qty_on_hand' => '2.500000', 'qty_reserved' => '0.000000', 'version' => 1],
        ], $this->levels(self::TENANT_A, $bin));
    }

    /**
     * S-12: `version` is the optimistic lock the ORM checks on `StockLevel`. A
     * write that changes `qty_on_hand` without moving it would let an ORM writer
     * holding the old figure overwrite this receipt and never notice.
     */
    #[Test]
    public function aSecondReceiptIntoTheSameBinAddsToTheLevelAndBumpsItsVersion(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A);
        $bin = $this->seedLocation(self::TENANT_A, $warehouse, 'A-01');
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');

        $this->receiveAs(self::TENANT_A, $warehouse, [new StockReceiptLine($bolts, $bin, '5')]);
        $this->receiveAs(self::TENANT_A, $warehouse, [new StockReceiptLine($bolts, $bin, '3.25')]);

        self::assertSame([
            ['inventory_item_id' => $bolts, 'qty_on_hand' => '8.250000', 'qty_reserved' => '0.000000', 'version' => 2],
        ], $this->levels(self::TENANT_A, $bin));
        self::assertCount(2, $this->movements(self::TENANT_A, $bin));
    }

    /**
     * W-2: the command runs inside the CALLER's tenancy transaction and opens
     * none of its own. Called with no transaction open, `TenantContext::run()`
     * would quietly open one and commit it — the receipt's stock would land
     * whether or not the goods receipt that justified it ever did.
     */
    #[Test]
    public function aReceiptWithNoCallerTransactionOpenIsRefusedAndWritesNothing(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A);
        $bin = $this->seedLocation(self::TENANT_A, $warehouse, 'A-01');
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');

        self::assertSame(0, $this->em()->getConnection()->getTransactionNestingLevel(),
            'The precondition this test is about: nothing is open.');

        try {
            $this->receiver()->receive(new StockReceipt(
                goodsReceiptId: Uuid::v4()->toRfc4122(),
                warehouseId: $warehouse,
                occurredAt: new \DateTimeImmutable('2026-09-11T08:00:00+00:00'),
                lines: [new StockReceiptLine($bolts, $bin, '5')],
            ));
            self::fail('A receipt with no caller transaction open was accepted.');
        } catch (NoCallerTransaction) {
        }

        self::assertSame([], $this->movements(self::TENANT_A, $bin));
        self::assertSame([], $this->levels(self::TENANT_A, $bin));
    }

    /**
     * W-2's other half: caller and callee commit or roll back TOGETHER. The
     * caller's document fails after the stock was taken in — so the stock was
     * never taken in.
     */
    #[Test]
    public function whenTheCallerFailsAfterTheReceiptNothingTheReceiptWroteSurvives(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A);
        $bin = $this->seedLocation(self::TENANT_A, $warehouse, 'A-01');
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $this->receiveAs(self::TENANT_A, $warehouse, [new StockReceiptLine($bolts, $bin, '5')]);

        $callerFailure = new \RuntimeException('the goods receipt could not be saved');

        try {
            $this->tenantContext()->run(self::TENANT_A, function () use ($warehouse, $bin, $bolts, $callerFailure): never {
                $this->receiver()->receive(new StockReceipt(
                    goodsReceiptId: Uuid::v4()->toRfc4122(),
                    warehouseId: $warehouse,
                    occurredAt: new \DateTimeImmutable('2026-09-11T09:00:00+00:00'),
                    lines: [new StockReceiptLine($bolts, $bin, '7')],
                ));

                throw $callerFailure;
            });
        } catch (\RuntimeException $e) {
            self::assertSame($callerFailure, $e);
        }

        self::assertCount(1, $this->movements(self::TENANT_A, $bin), 'The failed caller\'s movement survived its rollback.');
        self::assertSame([
            ['inventory_item_id' => $bolts, 'qty_on_hand' => '5.000000', 'qty_reserved' => '0.000000', 'version' => 1],
        ], $this->levels(self::TENANT_A, $bin), 'The failed caller\'s stock survived its rollback.');
    }

    /**
     * W-2's mechanism has two halves and this is the one `StockReceiver` does
     * NOT write: a transaction is open, but `TenantContext` did not open it, so
     * no tenant is bound to it. The nesting check lets it through; `run()`
     * refuses it. Pinned because the design leans on that refusal existing.
     */
    #[Test]
    public function aReceiptInsideATransactionTenantContextDidNotOpenIsRefusedAndWritesNothing(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A);
        $bin = $this->seedLocation(self::TENANT_A, $warehouse, 'A-01');
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');

        $connection = $this->em()->getConnection();
        $connection->beginTransaction();

        try {
            $this->receiver()->receive(new StockReceipt(
                goodsReceiptId: Uuid::v4()->toRfc4122(),
                warehouseId: $warehouse,
                occurredAt: new \DateTimeImmutable('2026-09-11T08:00:00+00:00'),
                lines: [new StockReceiptLine($bolts, $bin, '5')],
            ));
            self::fail('A receipt inside a transaction with no tenant bound to it was accepted.');
        } catch (TenantContextViolation) {
        } finally {
            $connection->rollBack();
        }

        self::assertSame([], $this->movements(self::TENANT_A, $bin));
        self::assertSame([], $this->levels(self::TENANT_A, $bin));
    }

    /**
     * A receipt of nothing is a bug in the caller, not a fact worth recording —
     * the same reasoning `stock_movement_quantity_nonzero` applies to a line.
     * Refused at construction, so no `StockReceipt` with no lines can exist to
     * be passed anywhere; the caller translates the refusal (`rule()`), and
     * `lineIndex()` is null because no line is at fault.
     */
    #[Test]
    public function aReceiptWithNoLinesCannotBeBuilt(): void
    {
        try {
            new StockReceipt(
                goodsReceiptId: Uuid::v4()->toRfc4122(),
                warehouseId: Uuid::v4()->toRfc4122(),
                occurredAt: new \DateTimeImmutable('2026-09-11T08:00:00+00:00'),
                lines: [],
            );
            self::fail('A receipt with no lines was built.');
        } catch (StockReceiptRefused $refused) {
            self::assertSame('stock_movement.receipt_has_a_line', $refused->rule());
            self::assertNull($refused->lineIndex());
        }
    }

    /**
     * A receipt takes stock IN, so every line is a positive quantity that fits
     * `numeric(19,6)` exactly. Zero is refused by `stock_movement_quantity_nonzero`
     * too, but only as a 23514 that aborts the caller's whole transaction; a
     * negative quantity the database would accept, and it would take stock OUT
     * through the receipt door. A seventh decimal Postgres rounds away silently,
     * so the stock recorded would not be the stock received.
     *
     * @return iterable<string, array{string}>
     */
    public static function quantitiesThatAreNotAPositiveReceipt(): iterable
    {
        yield 'zero' => ['0'];
        yield 'zero with scale' => ['0.000000'];
        yield 'negative' => ['-1'];
        yield 'not a number' => ['five'];
        yield 'empty' => [''];
        yield 'seven decimals, rounded away by numeric(19,6)' => ['1.0000001'];
        yield 'fourteen integer digits, beyond numeric(19,6)' => ['10000000000000'];
        // `$` matches before a final newline unless the pattern is anchored with
        // `\z`; the value then reaches bccomp(), which throws a ValueError — a
        // 500, measured by the step 7 review on PHP 8.4.6.
        yield 'trailing newline' => ["5\n"];
        yield 'trailing CRLF' => ["5\r\n"];
    }

    #[Test]
    #[DataProvider('quantitiesThatAreNotAPositiveReceipt')]
    public function aLineWhoseQuantityIsNotAPositiveReceiptIsRefusedAndNamed(string $quantity): void
    {
        $item = Uuid::v4()->toRfc4122();
        $bin = Uuid::v4()->toRfc4122();

        try {
            new StockReceipt(
                goodsReceiptId: Uuid::v4()->toRfc4122(),
                warehouseId: Uuid::v4()->toRfc4122(),
                occurredAt: new \DateTimeImmutable('2026-09-11T08:00:00+00:00'),
                lines: [new StockReceiptLine($item, $bin, '1'), new StockReceiptLine($item, $bin, $quantity)],
            );
            self::fail(\sprintf('A receipt line of "%s" was accepted.', $quantity));
        } catch (StockReceiptRefused $refused) {
            self::assertSame('stock_movement.quantity_is_positive', $refused->rule());
            self::assertSame(1, $refused->lineIndex(), 'The refusal must name the line at fault, not the first line.');
            // **The message, pinned — and pinned as a VERBATIM echo (QR-5).**
            // Added 2026-09-18 with CD-1 step 2. It was unpinned until then, and
            // an unpinned message is how the over-receipt defect survived to be
            // found by a reviewer rather than by the suite.
            //
            // **This site is deliberately NOT rendered under QR-2, against the
            // reading in `STATES.md` QR-5.** Measured: the only production
            // construction of a `StockReceipt` is `GoodsReceiptWriter:351`, and
            // `GoodsReceiptWriter::lines()` already refuses any quantity failing
            // this same regex and the same positivity test (`:539`) before it
            // normalises with `bcadd(…, 6)` (`:558`). A value that survives that
            // survives the check here too — so this factory **cannot fire on the
            // production path at all**, and every value that does reach it is one
            // that FAILED decimal validation, straight from a non-HTTP caller.
            // QR-5's own test — *could this string have come from the request
            // body unaltered?* — answers yes, so it is echoed, quoted, exactly as
            // sent. `'0.000000'` above is the case that tells the two apart: a
            // QR-2 rendering would report it as `"0"` and hide the scale the
            // caller actually sent.
            self::assertSame(
                \sprintf('Line 1: "%s" is not a positive quantity of at most 13 digits and 6 decimal places.', $quantity),
                $refused->getMessage(),
                'The refusal does not echo the quantity it was given, verbatim (QR-5).',
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function binsThatAreNotALiveBinOfTheReceivingWarehouse(): iterable
    {
        yield 'a bin in another warehouse of the same workspace' => ['other-warehouse'];
        yield 'an archived bin in the receiving warehouse' => ['archived'];
    }

    /**
     * Inventory owns the fact "this bin is in that warehouse" (F-3 step 4 offers
     * only the order's warehouse's bins). Nothing in the schema ties a movement's
     * location to a warehouse — `stock_movement` has no `warehouse_id` — so
     * without this check stock received at one warehouse could be booked into a
     * bin at another, and the database would accept it.
     */
    #[Test]
    #[DataProvider('binsThatAreNotALiveBinOfTheReceivingWarehouse')]
    public function aLineIntoABinThatIsNotALiveBinOfTheReceivingWarehouseIsRefusedAndNamed(string $case): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A);
        $goodBin = $this->seedLocation(self::TENANT_A, $warehouse, 'A-01');
        $badBin = match ($case) {
            'other-warehouse' => $this->seedLocation(self::TENANT_A, $this->seedWarehouse(self::TENANT_A), 'B-01'),
            'archived' => $this->archived($this->seedLocation(self::TENANT_A, $warehouse, 'A-02')),
        };
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');

        try {
            $this->receiveAs(self::TENANT_A, $warehouse, [
                new StockReceiptLine($bolts, $goodBin, '5'),
                new StockReceiptLine($bolts, $badBin, '2'),
            ]);
            self::fail('A line into a bin that is not a live bin of the receiving warehouse was accepted.');
        } catch (StockReceiptRefused $refused) {
            self::assertSame('storage_location.belongs_to_receiving_warehouse', $refused->rule());
            self::assertSame(1, $refused->lineIndex());
        }

        self::assertSame([], $this->movements(self::TENANT_A, $goodBin));
        self::assertSame([], $this->levels(self::TENANT_A, $goodBin));
        self::assertSame([], $this->movements(self::TENANT_A, $badBin));
    }

    /**
     * The brief's "impossible under RLS + composite FKs — prove it with a test
     * rather than a check", item half. `StockReceiver` has no tenant check of
     * its own: `stock_movement_item_fk` is `(tenant_id, inventory_item_id)`,
     * tenant_id is A's, so B's item id names no row and Postgres answers 23503,
     * which aborts the caller's whole transaction. Neither tenant holds a row.
     *
     * A test of its own, not a half of one: a 23503 inside
     * `wrapInTransaction()` closes the entity manager, so anything the same
     * test did after it would depend on running second.
     */
    #[Test]
    public function anotherWorkspacesItemIsRefusedByTheCompositeForeignKey(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A);
        $bin = $this->seedLocation(self::TENANT_A, $warehouse, 'A-01');
        $theirBolts = $this->seedItem(self::TENANT_B, 'BOLT');

        try {
            $this->receiveAs(self::TENANT_A, $warehouse, [new StockReceiptLine($theirBolts, $bin, '5')]);
            self::fail('Another workspace\'s item was received into this one.');
        } catch (ForeignKeyConstraintViolationException $e) {
            self::assertStringContainsString('stock_movement_item_fk', $e->getMessage());
        }

        $this->assertNoStockInEitherWorkspace();
    }

    /**
     * Bin half. Refused by the warehouse predicate of the bin check, and that
     * predicate cannot be satisfied by another tenant's bin at all: the
     * composite `storage_location_warehouse_fk` is `(tenant_id, warehouse_id)`,
     * so B's bin can never carry A's `warehouse_id`. RLS hides B's bin from A's
     * transaction too, but this test cannot tell the two apart and does not
     * claim to — the refusal would hold with RLS off.
     */
    #[Test]
    public function anotherWorkspacesBinIsRefusedAsNotABinOfTheReceivingWarehouse(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A);
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $theirBin = $this->seedLocation(self::TENANT_B, $this->seedWarehouse(self::TENANT_B), 'A-01');

        try {
            $this->receiveAs(self::TENANT_A, $warehouse, [new StockReceiptLine($bolts, $theirBin, '5')]);
            self::fail('Stock was received into another workspace\'s bin.');
        } catch (StockReceiptRefused $refused) {
            self::assertSame('storage_location.belongs_to_receiving_warehouse', $refused->rule());
        }

        $this->assertNoStockInEitherWorkspace();
    }

    /**
     * Decided at the step 7 review (item 7): archiving a warehouse closes the
     * site, and stock cannot arrive at a place that no longer operates. Its
     * bins are not archived with it — no trigger cascades a warehouse to its
     * locations — so without this refusal a closed site's live bins would keep
     * taking stock. Refused for the receipt as a whole (`lineIndex()` null).
     */
    #[Test]
    public function aReceiptIntoAnArchivedWarehouseIsRefusedEvenIntoItsLiveBins(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A);
        $bin = $this->seedLocation(self::TENANT_A, $warehouse, 'A-01');
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$warehouse]);

        try {
            $this->receiveAs(self::TENANT_A, $warehouse, [new StockReceiptLine($bolts, $bin, '5')]);
            self::fail('Stock was received into an archived warehouse.');
        } catch (StockReceiptRefused $refused) {
            self::assertSame('warehouse.is_live', $refused->rule());
            self::assertNull($refused->lineIndex());
        }

        self::assertSame([], $this->movements(self::TENANT_A, $bin));
        self::assertSame([], $this->levels(self::TENANT_A, $bin));
    }

    /**
     * Item 7's other half, pinned so a future "tidy-up" cannot quietly reverse
     * it: an archived ITEM still takes stock in. SCHEMA §7 soft-deletes items
     * because order lines and movements keep referencing them, and goods
     * ordered before the item was archived still arrive at the dock.
     */
    #[Test]
    public function anArchivedItemStillTakesStockIn(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A);
        $bin = $this->seedLocation(self::TENANT_A, $warehouse, 'A-01');
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid', [$bolts]);

        $this->receiveAs(self::TENANT_A, $warehouse, [new StockReceiptLine($bolts, $bin, '5')]);

        self::assertSame([
            ['inventory_item_id' => $bolts, 'qty_on_hand' => '5.000000', 'qty_reserved' => '0.000000', 'version' => 1],
        ], $this->levels(self::TENANT_A, $bin));
    }

    private function assertNoStockInEitherWorkspace(): void
    {
        foreach ([self::TENANT_A, self::TENANT_B] as $tenant) {
            self::assertSame(0, $this->countAsOwnerForTenant($tenant, 'SELECT count(*) FROM stock_movement'), 'A movement survived in '.$tenant);
            self::assertSame(0, $this->countAsOwnerForTenant($tenant, 'SELECT count(*) FROM stock_level'), 'A level survived in '.$tenant);
        }
    }

    /**
     * W-5: `stock_level` is locked LAST in the transaction, and — within this
     * command — in ONE order, `(inventory_item_id, storage_location_id)`
     * ascending, whatever order the caller's lines arrive in. Two receipts that
     * touch the same bins in opposite line order then queue on the first row
     * instead of each holding the row the other wants next.
     *
     * The instrument is Postgres's own `cmin`: the command id, within this
     * transaction, of the statement that wrote each row version. It is read
     * INSIDE the receipt's transaction, where it is meaningful. The rows exist
     * beforehand, so each write is an `ON CONFLICT DO UPDATE` of an existing
     * row — the row-lock-taking case W-5 is about, not a fresh insert.
     */
    #[Test]
    public function stockLevelsAreWrittenLastAndInOneOrderWhateverOrderTheLinesArriveIn(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A);
        $bins = [$this->seedLocation(self::TENANT_A, $warehouse, 'A-01'), $this->seedLocation(self::TENANT_A, $warehouse, 'A-02')];
        $items = [$this->seedItem(self::TENANT_A, 'BOLT'), $this->seedItem(self::TENANT_A, 'NUT')];

        $keys = [];
        foreach ($items as $item) {
            foreach ($bins as $bin) {
                $keys[] = [$item, $bin];
            }
        }
        usort($keys, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $ascending = array_map(static fn (array $k): StockReceiptLine => new StockReceiptLine($k[0], $k[1], '1'), $keys);

        $this->receiveAs(self::TENANT_A, $warehouse, $ascending);

        $goodsReceipt = Uuid::v4()->toRfc4122();
        [$levels, $lastMovement] = $this->tenantContext()->run(self::TENANT_A, function () use ($warehouse, $ascending, $goodsReceipt): array {
            $this->receiver()->receive(new StockReceipt(
                goodsReceiptId: $goodsReceipt,
                warehouseId: $warehouse,
                occurredAt: new \DateTimeImmutable('2026-09-11T09:00:00+00:00'),
                lines: array_reverse($ascending),
            ));

            return [
                $this->app()->fetchAllAssociative(
                    'SELECT inventory_item_id::text AS item, storage_location_id::text AS bin, cmin::text::bigint AS cid
                       FROM stock_level ORDER BY cmin::text::bigint'),
                (int) $this->app()->fetchOne(
                    'SELECT max(cmin::text::bigint) FROM stock_movement WHERE source_id = ?::uuid', [$goodsReceipt]),
            ];
        });

        self::assertCount(4, array_unique(array_column($levels, 'cid')),
            'The instrument is not live: four level writes should carry four distinct command ids.');
        self::assertSame($keys, array_map(static fn (array $row): array => [$row['item'], $row['bin']], $levels),
            'stock_level rows were written in the order the lines arrived, not in (item, location) order.');
        self::assertGreaterThan($lastMovement, (int) $levels[0]['cid'],
            'A stock_level row was written before the last stock_movement: W-5 says it is locked last.');
    }

    /**
     * O-6 / `StockMovement`'s docblock: `(source_type, source_id)` exists so that
     * "did this goods receipt already post its movements?" has an answer, and
     * `stock_movement_tenant_source_idx` is the index that gives it. A second
     * `receive()` for one goods receipt would double the stock. Refused loudly
     * rather than skipped silently: inside one synchronous transaction a repeat
     * is a caller bug (say, step 8 calling once per line), and a no-op would
     * hide it while the stock for every line but the first went missing.
     */
    #[Test]
    public function aGoodsReceiptThatHasAlreadyPostedItsStockIsRefusedAndTheStockIsNotDoubled(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A);
        $bin = $this->seedLocation(self::TENANT_A, $warehouse, 'A-01');
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $goodsReceipt = Uuid::v4()->toRfc4122();

        $this->receiveAs(self::TENANT_A, $warehouse, [new StockReceiptLine($bolts, $bin, '5')], $goodsReceipt);

        try {
            $this->receiveAs(self::TENANT_A, $warehouse, [new StockReceiptLine($bolts, $bin, '5')], $goodsReceipt);
            self::fail('A goods receipt posted its stock twice.');
        } catch (StockReceiptRefused $refused) {
            self::assertSame('stock_movement.goods_receipt_posted_once', $refused->rule());
            self::assertNull($refused->lineIndex());
        }

        self::assertCount(1, $this->movements(self::TENANT_A, $bin));
        self::assertSame([
            ['inventory_item_id' => $bolts, 'qty_on_hand' => '5.000000', 'qty_reserved' => '0.000000', 'version' => 1],
        ], $this->levels(self::TENANT_A, $bin));
    }

    /**
     * Each line fits numeric(19,6); their sum for one (item, bin) does not. The
     * level upsert would fail with 22003 AFTER the movements were inserted — a
     * 500 that aborts the caller's transaction. Refused before any write, and
     * the refusal names the line whose addition crossed the limit.
     */
    #[Test]
    public function linesWhoseSumForOneBinOverflowsTheLevelAreRefusedBeforeAnyWrite(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A);
        $bin = $this->seedLocation(self::TENANT_A, $warehouse, 'A-01');
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');

        try {
            $this->receiveAs(self::TENANT_A, $warehouse, [
                new StockReceiptLine($bolts, $bin, '9999999999999.999999'),
                new StockReceiptLine($bolts, $bin, '0.000001'),
            ]);
            self::fail('A receipt whose summed quantity overflows numeric(19,6) was accepted.');
        } catch (StockReceiptRefused $refused) {
            self::assertSame('stock_level.received_quantity_fits', $refused->rule());
            self::assertSame(1, $refused->lineIndex());
        }

        self::assertSame([], $this->movements(self::TENANT_A, $bin));
        self::assertSame([], $this->levels(self::TENANT_A, $bin));
    }

    /**
     * The receipt's own sum fits, but the bin ALREADY holds so much that adding it would overflow the level. Until
     * 2026-10-16 the upsert raised 22003 (an HTTP 500 at the caller); now the conditional upsert refuses it on the
     * locked row, naming the first line booking that (item, bin), and leaves the level and its version untouched.
     */
    #[Test]
    public function aReceiptThatWouldOverfillAnExistingLevelIsRefusedAndNamesItsLine(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A);
        $bin = $this->seedLocation(self::TENANT_A, $warehouse, 'A-01');
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $nuts = $this->seedItem(self::TENANT_A, 'NUT');

        $this->receiveAs(self::TENANT_A, $warehouse, [new StockReceiptLine($bolts, $bin, '9999999999999')]);

        try {
            $this->receiveAs(self::TENANT_A, $warehouse, [
                new StockReceiptLine($nuts, $bin, '1'),
                new StockReceiptLine($bolts, $bin, '0.5'),
                new StockReceiptLine($bolts, $bin, '0.5'),
            ]);
            self::fail('A receipt that overfills an existing level was accepted.');
        } catch (StockReceiptRefused $refused) {
            self::assertSame('stock_level.on_hand_fits', $refused->rule());
            self::assertSame(1, $refused->lineIndex());
        }

        self::assertSame([
            ['inventory_item_id' => $bolts, 'qty_on_hand' => '9999999999999.000000', 'qty_reserved' => '0.000000', 'version' => 1],
        ], array_values(array_filter($this->levels(self::TENANT_A, $bin), static fn (array $l): bool => $l['inventory_item_id'] === $bolts)));
    }

    /**
     * One bin is one bin however its id is spelled. The DTOs hold the canonical
     * lower-case RFC 4122 form, so the W-5 sort key, the per-bin sum and the
     * bin check all compare like with like — and a receipt that spells one bin
     * two ways makes ONE level write, moving `version` once.
     */
    #[Test]
    public function idsAreHeldInCanonicalFormSoOneBinIsOneBinWhateverCaseItIsSpelledIn(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A);
        $bin = $this->seedLocation(self::TENANT_A, $warehouse, 'A-01');
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $goodsReceipt = Uuid::v4()->toRfc4122();

        $shouted = new StockReceiptLine(strtoupper($bolts), strtoupper($bin), '1');
        self::assertSame([$bolts, $bin], [$shouted->inventoryItemId, $shouted->storageLocationId]);

        $receipt = new StockReceipt(strtoupper($goodsReceipt), strtoupper($warehouse), new \DateTimeImmutable('2026-09-11T08:00:00+00:00'), [$shouted]);
        self::assertSame([$goodsReceipt, $warehouse], [$receipt->goodsReceiptId, $receipt->warehouseId]);

        $this->receiveAs(self::TENANT_A, strtoupper($warehouse), [$shouted, new StockReceiptLine($bolts, $bin, '2')]);

        self::assertSame([
            ['inventory_item_id' => $bolts, 'qty_on_hand' => '3.000000', 'qty_reserved' => '0.000000', 'version' => 1],
        ], $this->levels(self::TENANT_A, $bin));
    }

    /** @return iterable<string, array{string, string}> */
    public static function idsThatAreNotRfc4122(): iterable
    {
        $id = '0b6e3f2a-5c1d-4e8f-9a7b-2d4c6e8f0a1b';
        $forms = [
            'braces' => '{'.$id.'}',
            'no hyphens' => str_replace('-', '', $id),
            'malformed' => 'not-a-uuid',
            // `Uuid::fromString()` ACCEPTS base58 (and base32) and would
            // canonicalise it silently; only the `isValid()` guard refuses it.
            'base58' => Uuid::fromString($id)->toBase58(),
        ];

        foreach (['inventoryItemId', 'storageLocationId', 'warehouseId', 'goodsReceiptId'] as $field) {
            foreach ($forms as $form => $value) {
                yield $field.', '.$form => [$field, $value];
            }
        }
    }

    /**
     * A malformed id used to reach Postgres as a 22P02, which aborts the
     * caller's whole transaction; braces and no-hyphen forms reached it as a
     * valid uuid that no string comparison here matched. Refused at
     * construction with `\InvalidArgumentException`, as
     * `RequestDigest::uuid()` refuses one: the ids come from the caller's own
     * rows, so a bad one is a bug in the caller, not something a client fixes.
     */
    #[Test]
    #[DataProvider('idsThatAreNotRfc4122')]
    public function anIdThatIsNotAnRfc4122UuidIsRefusedAtConstruction(string $field, string $value): void
    {
        $ids = [
            'inventoryItemId' => Uuid::v4()->toRfc4122(),
            'storageLocationId' => Uuid::v4()->toRfc4122(),
            'warehouseId' => Uuid::v4()->toRfc4122(),
            'goodsReceiptId' => Uuid::v4()->toRfc4122(),
        ];
        $ids[$field] = $value;

        $this->expectException(\InvalidArgumentException::class);

        new StockReceipt(
            goodsReceiptId: $ids['goodsReceiptId'],
            warehouseId: $ids['warehouseId'],
            occurredAt: new \DateTimeImmutable('2026-09-11T08:00:00+00:00'),
            lines: [new StockReceiptLine($ids['inventoryItemId'], $ids['storageLocationId'], '1')],
        );
    }

    /** @return string the id it was given, now archived */
    private function archived(string $locationId): string
    {
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$locationId]);

        return $locationId;
    }

    /**
     * One receipt, received inside a tenancy transaction the test opens, the
     * way step 8's caller will.
     *
     * @param list<StockReceiptLine> $lines
     */
    private function receiveAs(string $tenantId, string $warehouseId, array $lines, ?string $goodsReceiptId = null): void
    {
        $this->tenantContext()->run($tenantId, fn () => $this->receiver()->receive(new StockReceipt(
            goodsReceiptId: $goodsReceiptId ?? Uuid::v4()->toRfc4122(),
            warehouseId: $warehouseId,
            occurredAt: new \DateTimeImmutable('2026-09-11T08:00:00+00:00'),
            lines: $lines,
        )));
    }

    private function receiver(): ReceiveStock
    {
        $receiver = self::getContainer()->get('test.inventory.receive_stock');
        \assert($receiver instanceof ReceiveStock);

        return $receiver;
    }

    /** @return string the new row's id */
    private function seedWarehouse(string $tenantId): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO warehouse (id, tenant_id, code, name, country_code)
            VALUES (?::uuid, ?::uuid, ?, 'Main', 'DE')
            SQL, [$id, $tenantId, strtoupper(substr(md5($id), 0, 8))]);

        return $id;
    }

    /** @return string the new row's id */
    private function seedLocation(string $tenantId, string $warehouseId, string $code): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)
            SQL, [$id, $tenantId, $warehouseId, $code]);

        return $id;
    }

    /**
     * Ground truth, read as the owner and outside the transaction under test.
     *
     * @return list<array<string, mixed>>
     */
    private function movements(string $tenantId, string $locationId): array
    {
        return $this->fetchAsOwnerForTenant($tenantId, <<<'SQL'
            SELECT inventory_item_id::text AS inventory_item_id, quantity::text AS quantity, reason, source_type,
                   source_id::text AS source_id, to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS occurred
              FROM stock_movement WHERE storage_location_id = ?::uuid
             ORDER BY quantity DESC
            SQL, [$locationId]);
    }

    /** @return list<array<string, mixed>> */
    private function levels(string $tenantId, string $locationId): array
    {
        return $this->fetchAsOwnerForTenant($tenantId, <<<'SQL'
            SELECT inventory_item_id::text AS inventory_item_id, qty_on_hand::text AS qty_on_hand,
                   qty_reserved::text AS qty_reserved, version
              FROM stock_level WHERE storage_location_id = ?::uuid
             ORDER BY qty_on_hand DESC
            SQL, [$locationId]);
    }
}
