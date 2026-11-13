<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Inventory\Application\ItemWriter;
use App\Platform\Http\Problem\TransactionContention;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **BK-24 — an item's unit cannot change once anything records quantities in it** (items batch C3, 2026-11-10;
 * `docs/session13/items-batch-design.md` "Revision 1 — FINAL" C3, as corrected by `items-batch-red.md` "Final RED — on
 * Revision 1" FH-1, FM-2, FL-1…FL-5; `docs/design/items-owner-items.md` IO-3 / H-2).
 *
 * Quantities are stored as bare numbers with no unit of their own, so `EA` → `KG` would silently turn "12 each" into
 * "12 kilograms" everywhere. `UpdateItem` therefore answers 409 `inventory_item.unit_in_use` when the submitted unit
 * DIFFERS from the stored one and any row of {@see ItemWriter::UNIT_IN_USE_LEGS} names the item — deleted order lines
 * included. A same-unit save, or a save that sends no unit, is not checked and never waits.
 *
 * **The lock** ({@see ItemWriter::UNIT_LOCK}): an upgrade of `EDIT_LOCK` to `FOR UPDATE`, taken only when the unit
 * differs, so it waits for every in-flight `KEY SHARE` on the item (the FK of an insert into any leg, and the writers'
 * explicit liveness reads); the leg reads after it see what those holders committed. Only that upgrade is bounded, by
 * {@see ItemWriter::UNIT_LOCK_TIMEOUT}; a `55P03` (timeout) or `40P01` (a lock cycle) answers 409
 * `transaction.contention`, nothing saved.
 *
 * **The races (real second connection, `support/hold_order_lock.php` as `erp_app`, `BulkRaceHolder`):**
 *  (1) a same-unit save does not wait on a `KEY SHARE` holder;
 *  (2) {@see self::aUnitChangeWaitsForEachKindOfWriteThatNamesTheItem()} — H-4's race test (`items-owner-items.md`):
 *      one holder per kind of write, each shown waited on, each answered `unit_in_use` after the holder commits;
 *  (3) the upgrade's bound: a holder that outlasts the timeout → 409 `transaction.contention`;
 *  (4) two unit changes serialise on `EDIT_LOCK`: the second reads the first's unit, and its trail `from` is it;
 *  (5) FH-1's deterministic single-holder cycle (a harness-only shape: no `src/` path takes `KEY SHARE` then `EDIT_LOCK`
 *      on one item). The product's three-party cycle (unit change + multi-line receipt + bulk archival) is a REVIEW CHECK:
 *      its unit-change arm is the one (5) reaches, and the other two arms are `BulkArchivalLockOrderTest`'s.
 */
final class ItemUnitInUseTest extends PurchaseOrderTestCase
{
    use BulkRaceHolder;

    /** `KEY SHARE` on the item — what every leg's FK check and the writers' liveness reads take. */
    private const ITEM_KEY_SHARE = 'SELECT 1 FROM inventory_item WHERE id = ?::uuid FOR KEY SHARE';

    /**
     * FL-5: one seeder per {@see ItemWriter::UNIT_IN_USE_LEGS} member, each with the variants it is driven in. A sixth
     * leg added to the constant without a seeder here is an error in the provider, not a silently untested leg.
     */
    private const LEG_VARIANTS = [
        'purchase_order_line' => ['live', 'deleted'],
        'sales_order_line' => ['live', 'deleted'],
        'stock_level' => ['zero'],
        'stock_movement' => ['only'],
        'supplier_product' => ['entry'],
    ];

    // ----------------------------------------------------------------------- the rule

    /** @return iterable<string, array{string, string}> */
    public static function legs(): iterable
    {
        $missing = array_diff(ItemWriter::UNIT_IN_USE_LEGS, array_keys(self::LEG_VARIANTS));
        if ([] !== $missing) {
            throw new \LogicException('No seeder for unit-in-use leg(s) '.implode(', ', $missing).': add one to LEG_VARIANTS.');
        }
        foreach (ItemWriter::UNIT_IN_USE_LEGS as $table) {
            foreach (self::LEG_VARIANTS[$table] as $variant) {
                yield $table.' ('.$variant.')' => [$table, $variant];
            }
        }
    }

    #[Test]
    #[DataProvider('legs')]
    public function aUsedItemsUnitCannotBeChanged(string $table, string $variant): void
    {
        $item = $this->seedItem(self::TENANT_A, 'UIU-'.strtoupper(substr(md5($table.$variant), 0, 8)));
        $this->seedLeg($table, $variant, $item);

        $this->send('PATCH', '/items/'.$item, ['unit_of_measure_code' => 'KG']);

        self::assertSame(409, $this->httpStatus(), $table.' ('.$variant.') does not put the unit in use: '.$this->responseContent());
        self::assertSame('inventory_item.unit_in_use', $this->json()['rule']);
        self::assertSame(ItemWriter::UNIT_IN_USE, $this->json()['detail']);
        self::assertSame('EA', $this->unitOf($item), 'The refused change landed.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_UPDATED), 'A refused unit change wrote a trail row.');
    }

    /** The literal UX sentence (`items-owner-items.md` H-2 draft; `UX` TO CONFIRM; noun re-worded by `items-i4-po.md` item 2 (BK-30)): names no leg, no count, no document (SEC S-8). */
    #[Test]
    public function theRefusalSentenceIsPinned(): void
    {
        self::assertSame('This item\'s unit can\'t be changed because stock, orders or supplier catalogues already record quantities in it.',
            ItemWriter::UNIT_IN_USE);
    }

    #[Test]
    public function anUnusedItemsUnitCanBeChanged(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'UIU-FREE');

        $this->send('PATCH', '/items/'.$item, ['unit_of_measure_code' => 'KG']);

        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame('KG', $this->json()['unit_of_measure_code']);
        self::assertSame('KG', $this->unitOf($item));
    }

    /** The rule is on a DIFFERENCE, never on presence (M20): resending the stored unit of a used item is not a change. */
    #[Test]
    public function aUsedItemsSameUnitCanBeResent(): void
    {
        $item = $this->usedItem('UIU-SAME');

        $this->send('PATCH', '/items/'.$item, ['unit_of_measure_code' => 'EA', 'name' => 'Same unit, new name']);

        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame('Same unit, new name', $this->json()['name']);
    }

    #[Test]
    public function aUsedItemCanBeEditedWithoutSendingAUnit(): void
    {
        $item = $this->usedItem('UIU-NOUNIT');

        $this->send('PATCH', '/items/'.$item, ['name' => 'Renamed while in use']);

        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame('Renamed while in use', $this->json()['name']);
        self::assertSame('EA', $this->json()['unit_of_measure_code']);
    }

    /** Order (design §1 C3 step 1): an unknown unit is a 422 on the field before any business rule, used item or not. */
    #[Test]
    public function anUnknownUnitOnAUsedItemIsAFieldErrorFirst(): void
    {
        $item = $this->usedItem('UIU-UNKNOWN');

        $this->send('PATCH', '/items/'.$item, ['unit_of_measure_code' => 'XX']);

        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        self::assertSame('unknown_reference', $this->json()['errors'][0]['code'] ?? null, $this->responseContent());
    }

    /**
     * **M-3: the legs are derived from the server, not listed by hand.** Every table with a foreign key to
     * `inventory_item` is a leg; a sixth (a future `stock_count_line`) must be decided on, not silently escape the rule.
     */
    #[Test]
    public function everyTableThatNamesAnItemIsALegOfTheRule(): void
    {
        $tables = $this->owner()->fetchFirstColumn(
            "SELECT DISTINCT conrelid::regclass::text FROM pg_constraint WHERE contype = 'f' AND confrelid = 'inventory_item'::regclass");
        sort($tables);

        self::assertNotSame([], $tables, 'Positive control: no foreign key references inventory_item, so the query reads nothing.');
        self::assertContains('stock_level', $tables, 'Positive control: stock_level\'s FK is not seen, so the query is wrong.');

        $legs = ItemWriter::UNIT_IN_USE_LEGS;
        sort($legs);
        self::assertSame($tables, $legs,
            'A table now references inventory_item; decide whether a row in it means the unit is in use (PO), then add a leg '
            .'or an exception with its reason.');
    }

    // ----------------------------------------------------------------------- the races

    /** Race (1), S-1 #1: a same-unit save of a used item takes no upgrade, so it does not wait on a `KEY SHARE` holder. */
    #[Test]
    public function aSameUnitSaveDoesNotWaitOnAStockWrite(): void
    {
        $item = $this->usedItem('UIU-NOWAIT');

        $holder = $this->raceAgainstAHolder([[self::ITEM_KEY_SHARE, [$item]]], function () use ($item): void {
            $this->send('PATCH', '/items/'.$item, ['unit_of_measure_code' => 'EA', 'name' => 'No wait']);
        });

        self::assertFalse($holder['waiter_seen'], 'A same-unit save waited on a KEY SHARE holder (M21: the upgrade is unconditional). '.json_encode($holder));
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
    }

    /** @return iterable<string, array{string}> */
    public static function holders(): iterable
    {
        yield 'a stock write (movement + level)' => ['stock'];
        yield 'a purchase-order line insert' => ['purchase_order_line'];
        yield 'a sales-order line insert' => ['sales_order_line'];
        yield 'a catalogue entry insert' => ['supplier_product'];
    }

    /**
     * Race (2), **H-4's race test** (`items-owner-items.md` H-4; first RED M-2): one holder per kind of write, as `erp_app`
     * through the harness (FL-4), each holding its transaction open with a row that names the unused item. The
     * differing-unit save is shown WAITING on the holder's transaction (`CustomerContactWriteTest`'s wait assertion); the
     * holder commits; the save then sees the row and answers `unit_in_use`. Without the upgrade (M22) the save reads
     * before the commit, answers 200, and no waiter is ever seen.
     */
    #[Test]
    #[DataProvider('holders')]
    public function aUnitChangeWaitsForEachKindOfWriteThatNamesTheItem(string $kind): void
    {
        $item = $this->seedItem(self::TENANT_A, 'UIU-H-'.strtoupper(substr(md5($kind), 0, 6)));

        $holder = $this->raceAgainstAHolder($this->holderInserts($kind, $item), function () use ($item): void {
            $this->send('PATCH', '/items/'.$item, ['unit_of_measure_code' => 'KG']);
        });

        self::assertTrue($holder['waiter_seen'], $kind.': the unit change never waited on the holder (M22: no upgrade). '.json_encode($holder).' '.$this->responseContent());
        self::assertTrue($holder['committed'], $kind.': the holder did not commit: '.json_encode($holder));
        self::assertContains(['locktype' => 'transactionid', 'relation' => null, 'mode' => 'ShareLock', 'granted' => false, 'xid' => $holder['holder_xid']],
            $holder['waiter_locks'], $kind.': the unit change did not wait on the holder\'s transaction: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), $kind.': '.$this->responseContent());
        self::assertSame('inventory_item.unit_in_use', $this->json()['rule']);
        self::assertSame('EA', $this->unitOf($item));
        self::assertSame([], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_UPDATED));
    }

    /**
     * Race (3), H-1: **the upgrade is bounded.** A holder keeps `KEY SHARE` on the item for twice the timeout (FL-3:
     * `pg_sleep` after the waiter is seen, before its commit); the save gives up at {@see ItemWriter::UNIT_LOCK_TIMEOUT}
     * with `55P03` and answers 409 `transaction.contention`, nothing saved. The seconds are read from the constant (FL-2),
     * so `DATA` changing the value does not break this. Without the timeout (M29) the save waits out the holder's sleep and
     * answers 200.
     */
    #[Test]
    public function aUnitChangeThatCannotTakeItsLockInTimeIsToldToTryAgain(): void
    {
        $timeout = self::unitLockTimeoutSeconds();
        $item = $this->seedItem(self::TENANT_A, 'UIU-TIMEOUT');
        $started = 0.0;
        $elapsed = 0.0;

        $holder = $this->raceAgainstAHolder(
            [[self::ITEM_KEY_SHARE, [$item]]],
            function () use ($item, &$started, &$elapsed): void {
                $started = microtime(true);
                $this->send('PATCH', '/items/'.$item, ['unit_of_measure_code' => 'KG']);
                $elapsed = microtime(true) - $started;
            },
            [['SELECT pg_sleep(?::float8)', [(string) (2 * $timeout)]]],
        );

        self::assertTrue($holder['waiter_seen'], 'The unit change never waited: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), 'The upgrade is not bounded (M29), or its timeout did not answer 409: '.$this->responseContent());
        self::assertSame(TransactionContention::RULE, $this->json()['rule']);
        self::assertSame(TransactionContention::MESSAGE, $this->json()['detail']);
        self::assertGreaterThanOrEqual($timeout - 0.5, $elapsed, 'The save gave up before its timeout.');
        self::assertLessThan(2 * $timeout - 0.5, $elapsed, 'The save waited out the holder instead of its own timeout.');
        self::assertSame('EA', $this->unitOf($item));
        self::assertSame([], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_UPDATED));
    }

    /**
     * Race (4): two unit changes on one unused item serialise on `EDIT_LOCK`. The holder runs the first change's own
     * statements (`EDIT_LOCK`, `UNIT_LOCK`, the UPDATE to `KG`); the request (to `L`) waits, then reads `KG` — so its trail
     * row's `from` is the first change's `to`, never the unit it last saw before waiting.
     */
    #[Test]
    public function twoUnitChangesSerialiseAndTheSecondRecordsTheFirstsUnit(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'UIU-SERIAL');

        $holder = $this->raceAgainstAHolder(
            [[ItemWriter::EDIT_LOCK, [$item]], [ItemWriter::UNIT_LOCK, [$item]],
             ["UPDATE inventory_item SET unit_of_measure_code = 'KG', updated_at = now() WHERE id = ?::uuid", [$item]]],
            function () use ($item): void {
                $this->send('PATCH', '/items/'.$item, ['unit_of_measure_code' => 'L']);
            },
        );

        self::assertTrue($holder['waiter_seen'], 'The second change never waited on the first: '.json_encode($holder));
        self::assertTrue($holder['committed'], json_encode($holder));
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame('L', $this->unitOf($item));
        $rows = $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_UPDATED);
        self::assertCount(1, $rows);
        $metadata = json_decode($rows[0]['metadata'], true, 512, \JSON_THROW_ON_ERROR);
        ksort($metadata);   // jsonb orders keys its own way
        self::assertSame(['fields' => ['unit_of_measure_code'], 'from' => ['unit_of_measure_code' => 'KG'], 'to' => ['unit_of_measure_code' => 'L']], $metadata);
    }

    /**
     * Race (5), FH-1: **a unit change caught in a lock cycle is told to try again.** On an unused item, the holder takes
     * `KEY SHARE`; the request takes `EDIT_LOCK` (compatible) and waits on its upgrade; the holder, seeing the waiter, then
     * asks for `EDIT_LOCK` itself, which conflicts with the request's — a two-way cycle. PostgreSQL aborts one side after
     * `deadlock_timeout` (1 s, before the 5 s upgrade timeout), so the arm reached is `40P01`, and which side loses is not
     * fixed. Exactly one side aborts: if the request, 409 `transaction.contention`, unit unchanged, no trail row; if the
     * holder, 200, the unit changed, one trail row. Never a 500 (M33).
     */
    #[Test]
    public function aUnitChangeCaughtInALockCycleIsToldToTryAgain(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'UIU-CYCLE');

        $holder = $this->raceAgainstAHolder(
            [[self::ITEM_KEY_SHARE, [$item]]],
            function () use ($item): void {
                $this->send('PATCH', '/items/'.$item, ['unit_of_measure_code' => 'KG']);
            },
            [[ItemWriter::EDIT_LOCK, [$item]]],
        );

        $this->assertExactlyOneSideAborted($holder, $item);
    }

    // -----------------------------------------------------------------------

    /**
     * `BulkArchivalLockOrderTest::assertExactlyOneSideAborted()`'s shape (a copy, the earlier copies' reason), for a unit
     * change: the request is never the bulk, and its "whole" / "absent" are the unit and the trail row.
     *
     * @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder
     */
    private function assertExactlyOneSideAborted(array $holder, string $item): void
    {
        $status = $this->httpStatus();
        $body = $this->responseContent();
        self::assertTrue($holder['waiter_seen'], 'No cycle formed. '.json_encode($holder).' response: '.$status.' '.$body);
        self::assertNotSame(500, $status, 'The unit change lost the cycle and answered 500: the contention arm is missing (M33). '.$body);

        $requestAborted = 409 === $status && TransactionContention::RULE === ($this->json()['rule'] ?? null);
        fwrite(\STDERR, \sprintf("\n[C3 unit-change cycle] victim = %s\n", $requestAborted ? 'the request' : 'the holder'));

        if ($requestAborted) {
            self::assertTrue($holder['committed'], 'The request was aborted, so the holder must have committed. '.json_encode($holder));
            self::assertSame(TransactionContention::MESSAGE, $this->json()['detail'] ?? null, $body);
            self::assertSame('EA', $this->unitOf($item), 'The aborted unit change left its unit behind.');
            self::assertSame([], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_UPDATED), 'The aborted unit change left a trail row.');

            return;
        }

        self::assertFalse($holder['committed'], 'Both sides committed, so no cycle was resolved by an abort. '.json_encode($holder).' '.$status);
        self::assertSame('40P01', $holder['sqlstate'], 'The holder failed for another reason. '.json_encode($holder));
        self::assertSame(200, $status, 'The holder was the victim, so the unit change must succeed. '.$body);
        self::assertSame('KG', $this->unitOf($item));
        self::assertCount(1, $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_UPDATED));
    }

    private static function unitLockTimeoutSeconds(): int
    {
        self::assertSame(1, preg_match("/lock_timeout = '(\\d+)s'/", ItemWriter::UNIT_LOCK_TIMEOUT, $m),
            'UNIT_LOCK_TIMEOUT is not a whole number of seconds; re-derive how this test reads it.');

        return (int) $m[1];
    }

    private function unitOf(string $item): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT unit_of_measure_code FROM inventory_item WHERE id = ?::uuid', [$item])[0]['unit_of_measure_code'];
    }

    /** An item one leg names (a zero stock row), for the cases that need "used" but are not about which leg. */
    private function usedItem(string $sku): string
    {
        $item = $this->seedItem(self::TENANT_A, $sku);
        $this->seedLeg('stock_level', 'zero', $item);

        return $item;
    }

    private function seedLeg(string $table, string $variant, string $item): void
    {
        match ($table.'/'.$variant) {
            'stock_level/zero' => $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 0)',
                [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $this->bin()]),
            'stock_movement/only' => $this->insertAsOwnerForTenant(self::TENANT_A,
                "INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 1, 'adjustment')",
                [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $this->bin()]),
            'supplier_product/entry' => $this->seedSupplierProduct(self::TENANT_A, $this->seedSupplier(self::TENANT_A, 'UIU Supplier '.$item), $item),
            'purchase_order_line/live', 'purchase_order_line/deleted' => $this->purchaseLine($item, 'deleted' === $variant),
            'sales_order_line/live', 'sales_order_line/deleted' => $this->salesLine($item, 'deleted' === $variant),
            default => throw new \LogicException('No seeder for '.$table.' ('.$variant.').'),
        };
    }

    private function purchaseLine(string $item, bool $deleted): void
    {
        $order = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $this->seedSupplier(self::TENANT_A, 'UIU PO '.$item), $this->seedWarehouse(self::TENANT_A, 'UIU PO WH'));
        $line = $this->seedLine(self::TENANT_A, $order, $item);
        if ($deleted) {
            $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE purchase_order_line SET deleted_at = now() WHERE id = ?::uuid', [$line]);
            self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM purchase_order_line WHERE id = ?::uuid AND deleted_at IS NOT NULL', [$line]),
                'Fixture: the line is not deleted.');
        }
    }

    private function salesLine(string $item, bool $deleted): void
    {
        $order = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'));
        $line = $this->seedSalesOrderLine(self::TENANT_A, $order, $item);
        if ($deleted) {
            $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order_line SET deleted_at = now() WHERE id = ?::uuid', [$line]);
            self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM sales_order_line WHERE id = ?::uuid AND deleted_at IS NOT NULL', [$line]),
                'Fixture: the line is not deleted.');
        }
    }

    private function bin(): string
    {
        return $this->seedStorageLocation(self::TENANT_A, $this->seedWarehouse(self::TENANT_A, 'UIU WH'), 'UIU-'.substr(Uuid::v4()->toRfc4122(), 0, 8));
    }

    /**
     * The holder's statements for race (2), run as `erp_app` (FL-4): each inserts one row naming the item, so the FK takes
     * `KEY SHARE` on it and holds it until the holder commits. Parents are seeded first, as the owner.
     *
     * @return list<array{0: string, 1: list<string|int|null>}>
     */
    private function holderInserts(string $kind, string $item): array
    {
        return match ($kind) {
            'stock' => (function () use ($item): array {
                $bin = $this->bin();

                return [
                    ["INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 3, 'adjustment')",
                        [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin]],
                    ['INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 3)',
                        [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin]],
                ];
            })(),
            'purchase_order_line' => [[
                'INSERT INTO purchase_order_line (id, tenant_id, purchase_order_id, inventory_item_id, line_number, quantity_ordered, quantity_received, unit_cost, lead_time_days) '
                ."VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 1, 10, 0, 2.5, 7)",
                [Uuid::v4()->toRfc4122(), self::TENANT_A,
                    $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $this->seedSupplier(self::TENANT_A, 'UIU HPO '.$item), $this->seedWarehouse(self::TENANT_A, 'UIU HPO WH')),
                    $item],
            ]],
            'sales_order_line' => [[
                'INSERT INTO sales_order_line (id, tenant_id, sales_order_id, inventory_item_id, line_number, quantity, quantity_fulfilled, unit_price) '
                .'VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 1, 10, 0, 2.5)',
                [Uuid::v4()->toRfc4122(), self::TENANT_A, $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO')), $item],
            ]],
            'supplier_product' => [[
                'INSERT INTO supplier_product (id, tenant_id, supplier_id, inventory_item_id, supplier_sku, lead_time_days, unit_cost, currency_code) '
                ."VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 'SP-UIUHOLD', 7, 1.0000, 'EUR')",
                [Uuid::v4()->toRfc4122(), self::TENANT_A, $this->seedSupplier(self::TENANT_A, 'UIU HSP '.$item), $item],
            ]],
            default => throw new \LogicException('No holder for '.$kind.'.'),
        };
    }
}
