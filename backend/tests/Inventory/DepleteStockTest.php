<?php

declare(strict_types=1);

namespace App\Tests\Inventory;

use App\Modules\Inventory\Application\Contract\DepleteStock;
use App\Modules\Inventory\Application\Contract\NoCallerTransaction;
use App\Modules\Inventory\Application\Contract\ReceiveStock;
use App\Modules\Inventory\Application\Contract\StockDepletion;
use App\Modules\Inventory\Application\Contract\StockDepletionLine;
use App\Modules\Inventory\Application\Contract\StockDepletionRefused;
use App\Modules\Inventory\Application\Contract\StockReceipt;
use App\Modules\Inventory\Application\Contract\StockReceiptLine;
use App\Platform\Tenancy\TenantContextViolation;
use App\Tests\Api\ApiTestCase;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * `Inventory\Application\Contract\DepleteStock` — the fulfilment half of
 * ARCHITECTURE §5.5's two cross-module write edges (W-3), built in sales step 5
 * commit 1 with no caller (its one caller, `FulfilmentWriter`, landed in commit 4), and driven here the way
 * `CreateFulfilment`'s writer will drive it: inside a tenancy transaction the
 * caller opened. `ReceiveStockTest`'s mirror, plus the one thing a depletion
 * has that a receipt does not — **it can be refused for want of stock, and
 * that refusal must be a domain refusal, never the raw 23514 the schema would
 * otherwise raise** (G-7's backstop; `stock_level` has no `CHECK
 * (qty_on_hand >= 0)`).
 *
 * Stock is put in through the real `ReceiveStock`, so every level these tests
 * deplete was written by the one production writer of levels.
 */
final class DepleteStockTest extends ApiTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    #[Test]
    public function aDepletionWritesOneNegativeMovementPerLineAndLowersTheLevelsItTouches(): void
    {
        [$warehouse, $bin] = $this->warehouseWithBin();
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $nuts = $this->seedItem(self::TENANT_A, 'NUT');
        $this->stock($warehouse, [[$bolts, $bin, '10'], [$nuts, $bin, '4']]);
        $fulfilment = Uuid::v4()->toRfc4122();

        $this->tenantContext()->run(self::TENANT_A, fn () => $this->depleter()->deplete(new StockDepletion(
            fulfilmentId: $fulfilment,
            warehouseId: $warehouse,
            occurredAt: new \DateTimeImmutable('2026-09-22T08:00:00+00:00'),
            lines: [new StockDepletionLine($bolts, $bin, '7'), new StockDepletionLine($nuts, $bin, '1.5')],
        )));

        self::assertSame([
            ['inventory_item_id' => $bolts, 'quantity' => '-7.000000', 'reason' => 'fulfilment', 'source_type' => 'fulfilment', 'source_id' => $fulfilment, 'occurred' => '2026-09-22 08:00:00'],
            ['inventory_item_id' => $nuts, 'quantity' => '-1.500000', 'reason' => 'fulfilment', 'source_type' => 'fulfilment', 'source_id' => $fulfilment, 'occurred' => '2026-09-22 08:00:00'],
        ], $this->depletionMovements($bin, $fulfilment));

        self::assertSame([
            ['inventory_item_id' => $bolts, 'qty_on_hand' => '3.000000', 'qty_reserved' => '0.000000', 'version' => 2],
            ['inventory_item_id' => $nuts, 'qty_on_hand' => '2.500000', 'qty_reserved' => '0.000000', 'version' => 2],
        ], $this->levels($bin));
    }

    /** Taking out exactly what is there leaves zero, which is stock and not an error. */
    #[Test]
    public function aDepletionOfExactlyWhatTheBinHoldsLeavesItAtZero(): void
    {
        [$warehouse, $bin] = $this->warehouseWithBin();
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $this->stock($warehouse, [[$bolts, $bin, '5']]);

        $this->depleteAs($warehouse, [new StockDepletionLine($bolts, $bin, '5')]);

        self::assertSame([
            ['inventory_item_id' => $bolts, 'qty_on_hand' => '0.000000', 'qty_reserved' => '0.000000', 'version' => 2],
        ], $this->levels($bin));
    }

    /**
     * **The load-bearing refusal.** A bin holding 5 asked for 7 is refused in
     * domain terms, naming the rule, the line, the bin, the item and the figure —
     * and NOTHING the depletion wrote survives, because the caller's rollback is
     * a clean one: no SQL error was raised, so the transaction was never aborted.
     */
    #[Test]
    public function aDepletionThatExceedsWhatTheBinHoldsIsRefusedInDomainTermsAndWritesNothing(): void
    {
        [$warehouse, $bin] = $this->warehouseWithBin();
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $this->stock($warehouse, [[$bolts, $bin, '5']]);

        try {
            $this->depleteAs($warehouse, [new StockDepletionLine($bolts, $bin, '7')]);
            self::fail('A depletion of 7 from a bin holding 5 was accepted.');
        } catch (StockDepletionRefused $refused) {
            self::assertSame('stock_level.on_hand_covers_the_depletion', $refused->rule());
            self::assertSame([0], $refused->lineIndexes());
            self::assertSame($bolts, $refused->inventoryItemId());
            self::assertSame($bin, $refused->storageLocationId());
            self::assertSame('5.000000', $refused->onHand());
            self::assertSame('7.000000', $refused->requested());
        }

        self::assertSame([], $this->depletionMovements($bin), 'A refused depletion left a movement behind.');
        self::assertSame([
            ['inventory_item_id' => $bolts, 'qty_on_hand' => '5.000000', 'qty_reserved' => '0.000000', 'version' => 1],
        ], $this->levels($bin));
    }

    /**
     * **The positive control for the test above: without the domain refusal, the
     * same arithmetic is a raw 23514.** The statement a naive depletion would
     * issue — an unconditional decrement — is sent as `erp_app` against the same
     * shape of level, and the schema refuses it through `stock_level_reserved_sane`,
     * the one CHECK in the schema that refuses negative on-hand (G-7). If this
     * ever stops raising, the backstop is gone and the domain refusal is all
     * that is left; if the test above ever sees a 23514, the domain refusal is.
     */
    #[Test]
    public function withoutThePreCheckTheSameDecrementIsARaw23514FromTheReservationCheck(): void
    {
        [$warehouse, $bin] = $this->warehouseWithBin();
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $this->stock($warehouse, [[$bolts, $bin, '5']]);

        try {
            $this->tenantContext()->run(self::TENANT_A, fn () => $this->app()->executeStatement(
                'UPDATE stock_level SET qty_on_hand = qty_on_hand - 7 WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid',
                [$bolts, $bin],
            ));
            self::fail('An unconditional decrement past zero was accepted: the schema no longer refuses negative stock.');
        } catch (DriverException $e) {
            self::assertSame('23514', $e->getSQLState());
            self::assertStringContainsString('stock_level_reserved_sane', $e->getMessage());
        }
    }

    /**
     * The refusal is raised by PHP after an `UPDATE` that matched nothing, so the
     * caller's transaction is still usable — measured here by running a statement
     * in it after catching the refusal. A 23514 would have left it aborted (25P02).
     */
    #[Test]
    public function theRefusalLeavesTheCallersTransactionAlive(): void
    {
        [$warehouse, $bin] = $this->warehouseWithBin();
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $this->stock($warehouse, [[$bolts, $bin, '5']]);

        $after = $this->tenantContext()->run(self::TENANT_A, function () use ($warehouse, $bolts, $bin): int {
            try {
                $this->depleter()->deplete($this->depletion($warehouse, [new StockDepletionLine($bolts, $bin, '7')]));
                self::fail('The depletion was accepted.');
            } catch (StockDepletionRefused) {
            }

            return (int) $this->app()->fetchOne('SELECT count(*) FROM stock_level');
        });

        self::assertSame(1, $after);
    }

    /**
     * No level row at all — nothing was ever received into that bin — is the same
     * refusal, and the figure it reports is 0 rather than a crash on a missing row
     * (RED R-4(b)).
     */
    #[Test]
    public function aBinThatHasNeverHeldTheItemIsRefusedWithAFigureOfZero(): void
    {
        [$warehouse, $bin] = $this->warehouseWithBin();
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');

        try {
            $this->depleteAs($warehouse, [new StockDepletionLine($bolts, $bin, '1')]);
            self::fail('A depletion from a bin with no level was accepted.');
        } catch (StockDepletionRefused $refused) {
            self::assertSame('stock_level.on_hand_covers_the_depletion', $refused->rule());
            self::assertSame('0.000000', $refused->onHand());
        }

        self::assertSame([], $this->levels($bin), 'A refused depletion created a level.');
        self::assertSame([], $this->depletionMovements($bin));
    }

    /**
     * Two lines for one (item, bin) are ONE decrement of their sum — each fits,
     * together they do not — and the refusal names **both** lines, because the
     * level is refused, not either line alone (RED R-4(c)).
     */
    #[Test]
    public function twoLinesForOneBinAreSummedAndTheRefusalNamesBoth(): void
    {
        [$warehouse, $bin] = $this->warehouseWithBin();
        $other = $this->seedLocation($warehouse, 'A-02');
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $this->stock($warehouse, [[$bolts, $bin, '5'], [$bolts, $other, '5']]);

        try {
            $this->depleteAs($warehouse, [
                new StockDepletionLine($bolts, $bin, '3'),
                new StockDepletionLine($bolts, $other, '1'),
                new StockDepletionLine($bolts, $bin, '3'),
            ]);
            self::fail('Two lines summing past the bin were accepted.');
        } catch (StockDepletionRefused $refused) {
            self::assertSame('stock_level.on_hand_covers_the_depletion', $refused->rule());
            self::assertSame([0, 2], $refused->lineIndexes());
            self::assertSame('6.000000', $refused->requested());
        }
    }

    /**
     * The accepting half, a test of its own: a refusal inside `wrapInTransaction()`
     * closes the entity manager, so nothing may follow one in the same test.
     */
    #[Test]
    public function twoLinesForOneBinThatFitAreOneWriteMovingTheVersionOnce(): void
    {
        [$warehouse, $bin] = $this->warehouseWithBin();
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $this->stock($warehouse, [[$bolts, $bin, '5']]);

        $this->depleteAs($warehouse, [new StockDepletionLine($bolts, $bin, '2'), new StockDepletionLine($bolts, $bin, '3')]);

        self::assertSame([
            ['inventory_item_id' => $bolts, 'qty_on_hand' => '0.000000', 'qty_reserved' => '0.000000', 'version' => 2],
        ], $this->levels($bin), 'Two lines for one bin must be one write, moving version once.');
        self::assertCount(2, $this->depletionMovements($bin), 'Each line is its own movement.');
    }

    /** W-2, exactly as `ReceiveStockTest` pins it for the receipt. */
    #[Test]
    public function aDepletionWithNoCallerTransactionOpenIsRefusedAndWritesNothing(): void
    {
        [$warehouse, $bin] = $this->warehouseWithBin();
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $this->stock($warehouse, [[$bolts, $bin, '5']]);

        self::assertSame(0, $this->em()->getConnection()->getTransactionNestingLevel());

        try {
            $this->depleter()->deplete($this->depletion($warehouse, [new StockDepletionLine($bolts, $bin, '1')]));
            self::fail('A depletion with no caller transaction open was accepted.');
        } catch (NoCallerTransaction) {
        }

        self::assertSame([], $this->depletionMovements($bin));
        self::assertSame('5.000000', $this->levels($bin)[0]['qty_on_hand']);
    }

    #[Test]
    public function aDepletionInsideATransactionTenantContextDidNotOpenIsRefused(): void
    {
        [$warehouse, $bin] = $this->warehouseWithBin();
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $this->stock($warehouse, [[$bolts, $bin, '5']]);

        $connection = $this->em()->getConnection();
        $connection->beginTransaction();
        try {
            $this->depleter()->deplete($this->depletion($warehouse, [new StockDepletionLine($bolts, $bin, '1')]));
            self::fail('A depletion inside a transaction with no tenant bound was accepted.');
        } catch (TenantContextViolation) {
        } finally {
            $connection->rollBack();
        }

        self::assertSame([], $this->depletionMovements($bin));
    }

    /** W-2's other half: the caller fails after the depletion, so the depletion never happened. */
    #[Test]
    public function whenTheCallerFailsAfterTheDepletionNothingItWroteSurvives(): void
    {
        [$warehouse, $bin] = $this->warehouseWithBin();
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $this->stock($warehouse, [[$bolts, $bin, '5']]);
        $failure = new \RuntimeException('the fulfilment could not be saved');

        try {
            $this->tenantContext()->run(self::TENANT_A, function () use ($warehouse, $bolts, $bin, $failure): never {
                $this->depleter()->deplete($this->depletion($warehouse, [new StockDepletionLine($bolts, $bin, '2')]));

                throw $failure;
            });
        } catch (\RuntimeException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame([], $this->depletionMovements($bin));
        self::assertSame('5.000000', $this->levels($bin)[0]['qty_on_hand']);
    }

    #[Test]
    public function aDepletionWithNoLinesCannotBeBuilt(): void
    {
        try {
            new StockDepletion(Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122(), new \DateTimeImmutable(), []);
            self::fail('A depletion with no lines was built.');
        } catch (StockDepletionRefused $refused) {
            self::assertSame('stock_movement.depletion_has_a_line', $refused->rule());
            self::assertNull($refused->lineIndex());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function quantitiesThatAreNotAPositiveDepletion(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative — a depletion only takes out' => ['-1'];
        yield 'not a number' => ['five'];
        yield 'seven decimals' => ['1.0000001'];
        yield 'fourteen integer digits' => ['10000000000000'];
        yield 'trailing newline' => ["5\n"];
    }

    #[Test]
    #[DataProvider('quantitiesThatAreNotAPositiveDepletion')]
    public function aLineWhoseQuantityIsNotAPositiveDepletionIsRefusedAndNamed(string $quantity): void
    {
        $item = Uuid::v4()->toRfc4122();
        $bin = Uuid::v4()->toRfc4122();

        try {
            new StockDepletion(Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122(), new \DateTimeImmutable(), [
                new StockDepletionLine($item, $bin, '1'),
                new StockDepletionLine($item, $bin, $quantity),
            ]);
            self::fail(\sprintf('A depletion line of "%s" was accepted.', $quantity));
        } catch (StockDepletionRefused $refused) {
            self::assertSame('stock_movement.quantity_is_positive', $refused->rule());
            self::assertSame(1, $refused->lineIndex());
            self::assertSame(\sprintf('Line 1: "%s" is not a positive quantity of at most 13 digits and 6 decimal places.', $quantity), $refused->getMessage());
        }
    }

    #[Test]
    public function aFulfilmentThatHasAlreadyPostedIsRefusedAndTheStockIsNotTakenTwice(): void
    {
        [$warehouse, $bin] = $this->warehouseWithBin();
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $this->stock($warehouse, [[$bolts, $bin, '10']]);
        $fulfilment = Uuid::v4()->toRfc4122();

        $this->depleteAs($warehouse, [new StockDepletionLine($bolts, $bin, '2')], $fulfilment);

        try {
            $this->depleteAs($warehouse, [new StockDepletionLine($bolts, $bin, '2')], $fulfilment);
            self::fail('A fulfilment posted its stock twice.');
        } catch (StockDepletionRefused $refused) {
            self::assertSame('stock_movement.fulfilment_posted_once', $refused->rule());
            self::assertNull($refused->lineIndex());
        }

        self::assertSame('8.000000', $this->levels($bin)[0]['qty_on_hand']);
    }

    #[Test]
    public function aDepletionFromAnArchivedWarehouseIsRefusedEvenFromItsLiveBins(): void
    {
        [$warehouse, $bin] = $this->warehouseWithBin();
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $this->stock($warehouse, [[$bolts, $bin, '5']]);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$warehouse]);

        try {
            $this->depleteAs($warehouse, [new StockDepletionLine($bolts, $bin, '1')]);
            self::fail('Stock was taken out of an archived warehouse.');
        } catch (StockDepletionRefused $refused) {
            self::assertSame('warehouse.is_live', $refused->rule());
            self::assertNull($refused->lineIndex());
        }

        self::assertSame('5.000000', $this->levels($bin)[0]['qty_on_hand']);
    }

    /**
     * @return iterable<string, array{string, string}> case => the rule it must be refused under
     */
    public static function binsThatCannotBePickedFrom(): iterable
    {
        yield 'a bin of another warehouse of the same workspace' => ['other-warehouse', 'storage_location.belongs_to_fulfilling_warehouse'];
        yield 'another workspace\'s bin (RLS hides it)' => ['other-tenant', 'storage_location.belongs_to_fulfilling_warehouse'];
        // Owner ruling R2, 2026-09-22: only live, non-quarantine stock is pickable.
        yield 'an archived bin of the fulfilling warehouse' => ['archived', 'storage_location.is_pickable'];
        yield 'a quarantine location of the fulfilling warehouse' => ['quarantine', 'storage_location.is_pickable'];
    }

    /**
     * **R2: only live, non-quarantine locations of the fulfilling warehouse are
     * pickable** — and this holds even when the location has stock, which is the
     * case that matters: quarantine stock is on hand and must not ship. Refused
     * before any write, naming the line.
     */
    #[Test]
    #[DataProvider('binsThatCannotBePickedFrom')]
    public function aLineFromALocationThatIsNotPickableIsRefusedAndNamed(string $case, string $rule): void
    {
        [$warehouse, $goodBin] = $this->warehouseWithBin();
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $badBin = match ($case) {
            'other-warehouse' => $this->seedLocation($this->seedWarehouse(), 'B-01'),
            'other-tenant' => $this->seedForeignLocation(),
            'archived' => $this->seedLocation($warehouse, 'A-09'),
            'quarantine' => $this->seedLocation($warehouse, 'Q-01', 'quarantine'),
        };
        // Stock the location first where this workspace can, so the refusal is about
        // the location and never about an empty bin.
        if ('other-warehouse' !== $case && 'other-tenant' !== $case) {
            $this->stock($warehouse, [[$bolts, $badBin, '5']]);
        }
        $this->stock($warehouse, [[$bolts, $goodBin, '5']]);
        if ('archived' === $case) {
            $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$badBin]);
        }

        try {
            $this->depleteAs($warehouse, [new StockDepletionLine($bolts, $goodBin, '1'), new StockDepletionLine($bolts, $badBin, '1')]);
            self::fail('A line from a location that is not pickable was accepted: '.$case);
        } catch (StockDepletionRefused $refused) {
            self::assertSame($rule, $refused->rule());
            self::assertSame(1, $refused->lineIndex());
        }

        self::assertSame([], $this->depletionMovements($goodBin));
        self::assertSame('5.000000', $this->levels($goodBin)[0]['qty_on_hand']);
    }

    /**
     * W-5: levels are written LAST and in ONE order, `(inventory_item_id,
     * storage_location_id)` ascending, whatever order the lines arrive in — the
     * order `ReceiveStock` writes in, which is what lets a fulfilment and a receipt
     * over the same levels queue rather than deadlock. `cmin`, read inside the
     * transaction, is the instrument (`ReceiveStockTest`'s).
     */
    #[Test]
    public function stockLevelsAreWrittenLastAndInOneOrderWhateverOrderTheLinesArriveIn(): void
    {
        [$warehouse, $binA] = $this->warehouseWithBin();
        $binB = $this->seedLocation($warehouse, 'A-02');
        $items = [$this->seedItem(self::TENANT_A, 'BOLT'), $this->seedItem(self::TENANT_A, 'NUT')];

        $keys = [];
        foreach ($items as $item) {
            foreach ([$binA, $binB] as $bin) {
                $keys[] = [$item, $bin];
            }
        }
        usort($keys, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $this->stock($warehouse, array_map(static fn (array $k): array => [$k[0], $k[1], '10'], $keys));

        $fulfilment = Uuid::v4()->toRfc4122();
        [$levels, $lastMovement] = $this->tenantContext()->run(self::TENANT_A, function () use ($warehouse, $keys, $fulfilment): array {
            $this->depleter()->deplete(new StockDepletion(
                fulfilmentId: $fulfilment,
                warehouseId: $warehouse,
                occurredAt: new \DateTimeImmutable('2026-09-22T09:00:00+00:00'),
                lines: array_reverse(array_map(static fn (array $k): StockDepletionLine => new StockDepletionLine($k[0], $k[1], '1'), $keys)),
            ));

            return [
                $this->app()->fetchAllAssociative(
                    'SELECT inventory_item_id::text AS item, storage_location_id::text AS bin, cmin::text::bigint AS cid
                       FROM stock_level ORDER BY cmin::text::bigint'),
                (int) $this->app()->fetchOne(
                    'SELECT max(cmin::text::bigint) FROM stock_movement WHERE source_id = ?::uuid', [$fulfilment]),
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
     * **Two depletions of one bin that together exceed it: exactly one commits
     * and the other is refused in domain terms — no 23514, no lost update.**
     *
     * Connection A (`tests/Api/support/hold_order_lock.php`, out of process, on
     * the unpooled endpoint) takes the level's row lock with the depletion's own
     * conditional decrement of 7 from 10 and holds it; connection B is the real
     * `DepleteStock` asking 7 more. B blocks on the row, A commits, and under READ
     * COMMITTED Postgres re-evaluates B's `WHERE qty_on_hand >= 7` against the
     * committed 3 (EvalPlanQual) and matches nothing — so B is refused with the
     * figure 3. **Under M3 (an unconditional decrement) B is a raw 23514**, which
     * is what makes this a test of the predicate.
     *
     * ⚠ **What this does NOT prove: anything about lock ORDER.** One shared row
     * cannot deadlock under any ordering, and a depleter racing a depleter mutates
     * both sides identically. The lock-order proof is `CreateFulfilment`'s
     * two-row cross-flow test (design §6, RED R-5), which lands with its caller.
     * Connection A is also a hand-written copy of the depletion's statement, not
     * `StockDepleter` — it proves the refusal against that copy.
     *
     * The holder's `HOLD_ORDER` names no row, deliberately: the harness takes an
     * order lock first, and a `FOR NO KEY UPDATE` of an id that matches nothing
     * locks nothing, so the only lock in the race is the level's.
     */
    #[Test]
    public function twoDepletionsOfOneBinThatTogetherExceedItCommitOneAndRefuseTheOther(): void
    {
        [$warehouse, $bin] = $this->warehouseWithBin();
        $bolts = $this->seedItem(self::TENANT_A, 'BOLT');
        $this->stock($warehouse, [[$bolts, $bin, '10']]);

        $refused = null;
        $holder = $this->raceAgainstAHolder(
            [[
                'UPDATE stock_level SET qty_on_hand = qty_on_hand - ?::numeric, version = version + 1, updated_at = now()
                  WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid AND qty_on_hand >= ?::numeric',
                ['7', $bolts, $bin, '7'],
            ]],
            function () use ($warehouse, $bolts, $bin, &$refused): void {
                try {
                    $this->depleteAs($warehouse, [new StockDepletionLine($bolts, $bin, '7')]);
                } catch (StockDepletionRefused $e) {
                    $refused = $e;
                }
            },
        );

        self::assertTrue($holder['waiter_seen'], 'The depletion never waited on the holder, so nothing raced: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertInstanceOf(StockDepletionRefused::class, $refused, 'The second depletion was not refused.');
        self::assertSame('stock_level.on_hand_covers_the_depletion', $refused->rule());
        self::assertSame('3.000000', $refused->onHand());
        self::assertSame('3.000000', $this->levels($bin)[0]['qty_on_hand']);
        self::assertSame([], $this->depletionMovements($bin), 'The refused depletion left a movement.');
    }

    /** @return list<string> [warehouse, bin] */
    private function warehouseWithBin(): array
    {
        $warehouse = $this->seedWarehouse();

        return [$warehouse, $this->seedLocation($warehouse, 'A-01')];
    }

    /** @param list<array{0: string, 1: string, 2: string}> $lines [item, bin, quantity] */
    private function stock(string $warehouseId, array $lines): void
    {
        $receiver = self::getContainer()->get('test.inventory.receive_stock');
        \assert($receiver instanceof ReceiveStock);

        $this->tenantContext()->run(self::TENANT_A, fn () => $receiver->receive(new StockReceipt(
            goodsReceiptId: Uuid::v4()->toRfc4122(),
            warehouseId: $warehouseId,
            occurredAt: new \DateTimeImmutable('2026-09-21T08:00:00+00:00'),
            lines: array_map(static fn (array $l): StockReceiptLine => new StockReceiptLine($l[0], $l[1], $l[2]), $lines),
        )));
    }

    /** @param list<StockDepletionLine> $lines */
    private function depletion(string $warehouseId, array $lines, ?string $fulfilmentId = null): StockDepletion
    {
        return new StockDepletion(
            fulfilmentId: $fulfilmentId ?? Uuid::v4()->toRfc4122(),
            warehouseId: $warehouseId,
            occurredAt: new \DateTimeImmutable('2026-09-22T08:00:00+00:00'),
            lines: $lines,
        );
    }

    /** @param list<StockDepletionLine> $lines */
    private function depleteAs(string $warehouseId, array $lines, ?string $fulfilmentId = null): void
    {
        $this->tenantContext()->run(self::TENANT_A, fn () => $this->depleter()->deplete($this->depletion($warehouseId, $lines, $fulfilmentId)));
    }

    private function depleter(): DepleteStock
    {
        $depleter = self::getContainer()->get('test.inventory.deplete_stock');
        \assert($depleter instanceof DepleteStock);

        return $depleter;
    }

    private function seedWarehouse(): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, ?, 'Main', 'DE')
            SQL, [$id, self::TENANT_A, strtoupper(substr(md5($id), 0, 8))]);

        return $id;
    }

    private function seedLocation(string $warehouseId, string $code, string $kind = 'bin'): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO storage_location (id, tenant_id, warehouse_id, code, kind) VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?)
            SQL, [$id, self::TENANT_A, $warehouseId, $code, $kind]);

        return $id;
    }

    private function seedForeignLocation(): string
    {
        $warehouse = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_B, <<<'SQL'
            INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, 'THEIRS', 'Theirs', 'DE')
            SQL, [$warehouse, self::TENANT_B]);
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_B, <<<'SQL'
            INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, 'A-01')
            SQL, [$id, self::TENANT_B, $warehouse]);

        return $id;
    }

    /** @return list<array<string, mixed>> the depletion (negative) movements in one bin, as the owner reads them */
    private function depletionMovements(string $locationId, ?string $fulfilmentId = null): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT inventory_item_id::text AS inventory_item_id, quantity::text AS quantity, reason, source_type,
                   source_id::text AS source_id, to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS occurred
              FROM stock_movement WHERE storage_location_id = ?::uuid AND quantity < 0
               AND (?::uuid IS NULL OR source_id = ?::uuid)
             ORDER BY stock_movement.quantity
            SQL, [$locationId, $fulfilmentId, $fulfilmentId]);
    }

    /** @return list<array<string, mixed>> */
    private function levels(string $locationId): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT inventory_item_id::text AS inventory_item_id, qty_on_hand::text AS qty_on_hand,
                   qty_reserved::text AS qty_reserved, version
              FROM stock_level WHERE storage_location_id = ?::uuid
             ORDER BY qty_on_hand DESC, inventory_item_id
            SQL, [$locationId]);
    }

    /**
     * `PurchaseOrderRowLockTest::raceAgainstAHolder()`'s harness, pointed at an
     * order id that names no row (see the race test's docblock).
     *
     * @param list<array{0: string, 1: list<string>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_deplete_');
        file_put_contents($readyFile, '');

        $proc = proc_open(
            [PHP_BINARY, \dirname(__DIR__).'/Api/support/hold_order_lock.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'APP_DIRECT_DATABASE_URL' => $_ENV['APP_DIRECT_DATABASE_URL'],
                'HOLD_TENANT' => self::TENANT_A,
                'HOLD_ORDER' => Uuid::v4()->toRfc4122(),
                'HOLD_TABLE' => 'purchase_order',
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
