<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\Inventory\Application\ItemWriter;
use App\Modules\Warehouses\Application\StorageLocationWriter;
use App\Modules\Warehouses\Application\WarehouseWriter;
use App\Platform\Http\Bulk\BulkArchival;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Problem\TransactionContention;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateBulkArchival`'s lock order, measured against real second connections** (G5-C2; design §5.3; final RED M-3).
 * Each case holds, on connection A, the statements the OTHER side takes (the single writer's own constants, never a
 * transcription where one exists), then sends the request on connection B and records what each side answers.
 *
 *  - **Two bulks in opposite request order** do not cycle: members run in ascending id whatever order the request names.
 *    *Mutation:* process ids in request order → the cases deadlock (one side aborted) → red.
 *  - **Bulk locations ∥ `DeleteWarehouse`**, both orders, and **bulk warehouses ∥ `DeleteStorageLocation`**, both orders:
 *    one side waits on the other's warehouse / location lock, never a cycle, never a 500.
 *  - **M-3: bulk items ∥ a multi-line stock writer whose lines run `[B, A]`.** The bulk locks items `FOR UPDATE` in
 *    ascending order, the writer `KEY SHARE`s them in line order: a real cycle, which PostgreSQL breaks after
 *    `deadlock_timeout`. Run both ways round. **Which side PostgreSQL aborts is NOT fixed** (each waiter runs its own
 *    deadlock check; under load either may fire first — LEAD gate run 2, 2026-10-23, aborted the bulk where run 1 had
 *    aborted the receipt), so the cases assert only what is guaranteed ({@see self::assertExactlyOneSideAborted()}): one
 *    side aborts, the other commits, nothing is half-applied, and the victim answers as specified — the bulk 409
 *    `bulk.contention`, nothing done; a SHIPPED writer 409 `transaction.contention`, nothing saved (G-65's closing work,
 *    2026-10-23; it was an untranslated 500; the deterministic proof is `TransactionContentionTest`). The victim of
 *    each run is printed as data.
 */
final class BulkArchivalLockOrderTest extends PurchaseOrderTestCase
{
    use BulkRaceHolder;

    private const KEY_SHARE_ITEM = 'SELECT 1 FROM inventory_item WHERE id = ?::uuid FOR KEY SHARE';

    #[Test]
    public function twoBulksNamingTheSameIdsInOppositeOrderDoNotDeadlock(): void
    {
        [$a, $b] = $this->sortedCustomers('Cus ORD');

        // A holds the ascending first, then (after B waits) the second: what a correct concurrent bulk does.
        $holder = $this->raceAgainstAHolder([[CustomerWriter::DELETE_LOCK, [$a]]], function () use ($a, $b): void {
            $this->bulk('customer', [$b, $a]);
        }, [[CustomerWriter::DELETE_LOCK, [$b]]]);

        $this->assertNoCycle($holder);
        self::assertSame(['requested' => 2, 'applied' => 2], $this->json());
    }

    #[Test]
    public function bulkLocationsWaitOnDeleteWarehouseAndAreThenRefused(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH LO1');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-1');

        $holder = $this->raceAgainstAHolder([[WarehouseWriter::DELETE_LOCK, [$warehouse]], [WarehouseWriter::ARCHIVE, [$warehouse]]], function () use ($location): void {
            $this->bulk('storage_location', [$location]);
        });

        $this->assertNoCycle($holder);
        self::assertSame(['requested' => 1, 'applied' => 0], $this->json(), 'A location of the warehouse archived first is refused (warehouse.is_archived).');
    }

    #[Test]
    public function deleteWarehouseWaitsOnABulkLocationArchive(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH LO2');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-1');

        $holder = $this->raceAgainstAHolder([
            [StorageLocationWriter::PARENT_LOCK, [$warehouse]],
            [StorageLocationWriter::DELETE_LOCK, [$location, $warehouse]],
        ], function () use ($warehouse): void {
            $this->send('DELETE', '/warehouses/'.$warehouse);
        });

        $this->assertNoCycle($holder);
        self::assertSame(204, $this->httpStatus(), $this->responseContent());
    }

    #[Test]
    public function bulkWarehousesWaitOnDeleteStorageLocation(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH LO3');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-1');

        $holder = $this->raceAgainstAHolder([
            [StorageLocationWriter::PARENT_LOCK, [$warehouse]],
            [StorageLocationWriter::DELETE_LOCK, [$location, $warehouse]],
        ], function () use ($warehouse): void {
            $this->bulk('warehouse', [$warehouse]);
        });

        $this->assertNoCycle($holder);
        self::assertSame(['requested' => 1, 'applied' => 1], $this->json());
    }

    #[Test]
    public function deleteStorageLocationWaitsOnABulkWarehouseArchive(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH LO4');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-1');

        $holder = $this->raceAgainstAHolder([[WarehouseWriter::DELETE_LOCK, [$warehouse]]], function () use ($warehouse, $location): void {
            $this->send('DELETE', '/warehouses/'.$warehouse.'/locations/'.$location);
        });

        $this->assertNoCycle($holder);
        self::assertSame(204, $this->httpStatus(), $this->responseContent());
    }

    /** M-3, the stock writer first: its `KEY SHARE` on B, then (after the bulk waits on B) on A — a cycle; the bulk is aborted. */
    #[Test]
    public function aBulkItemArchiveCyclingWithAStockWriterAbortsExactlyOneSide(): void
    {
        [$a, $b] = $this->sortedItems('BLK-DL1');

        $holder = $this->raceAgainstAHolder([[self::KEY_SHARE_ITEM, [$b]]], function () use ($a, $b): void {
            $this->bulk('inventory_item', [$a, $b]);
        }, [[self::KEY_SHARE_ITEM, [$a]]]);

        $archived = fn (): int => $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM inventory_item WHERE id IN (?::uuid, ?::uuid) AND deleted_at IS NOT NULL', [$a, $b]);
        $this->assertExactlyOneSideAborted($holder, 'bulk item archive vs a stock writer [B, A]', requestIsBulk: true, requestSucceeded: 200,
            requestWhole: fn (): bool => 2 === $archived(), requestAbsent: fn (): bool => 0 === $archived());
    }

    /**
     * M-3, the bulk first (its item `FOR UPDATE` on A, then on B after the receipt waits): a real two-line receipt over HTTP
     * with lines `[B, A]` takes `KEY SHARE` on B and waits on A; the bulk then wants B — a cycle; PostgreSQL aborts one
     * side (either may be chosen). Final RED M-3: do NOT reorder the receipt's lines here (a shipped W-5 change for a
     * bulk-only reason); the shipped side, when chosen, answers 409 `transaction.contention` (G-65 closed).
     */
    #[Test]
    public function aReceiptCyclingWithABulkItemArchiveAbortsExactlyOneSide(): void
    {
        [$a, $b] = $this->sortedItems('BLK-DL2');
        $supplier = $this->seedSupplier(self::TENANT_A, 'Sup DL2');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH DL2');
        $order = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse, status: 'ordered');
        $lineB = $this->seedLine(self::TENANT_A, $order, $b, lineNumber: 1);
        $lineA = $this->seedLine(self::TENANT_A, $order, $a, lineNumber: 2);
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-01');

        $holder = $this->raceAgainstAHolder([[ItemWriter::DELETE_LOCK, [$a]]], function () use ($order, $lineB, $lineA, $bin): void {
            $this->send('POST', '/purchase-orders/'.$order.'/goods-receipts', ['lines' => [
                ['purchase_order_line_id' => $lineB, 'quantity' => '1', 'storage_location_id' => $bin],
                ['purchase_order_line_id' => $lineA, 'quantity' => '1', 'storage_location_id' => $bin],
            ]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        }, [[ItemWriter::DELETE_LOCK, [$b]]]);

        $receipts = fn (): int => $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt WHERE purchase_order_id = ?::uuid', [$order]);
        $this->assertExactlyOneSideAborted($holder, 'receipt [B, A] vs bulk item archive', requestIsBulk: false, requestSucceeded: 201,
            requestWhole: fn (): bool => 1 === $receipts(), requestAbsent: fn (): bool => 0 === $receipts());
    }

    /** RED M-3 (G5-C2): the same cycle through a two-line fulfilment `[B, A]` (its `stock_movement` FKs, line order). */
    #[Test]
    public function aFulfilmentCyclingWithABulkItemArchiveAbortsExactlyOneSide(): void
    {
        [$a, $b] = $this->sortedItems('BLK-DL3');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH DL3');
        $order = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), null, $warehouse, 'confirmed', '2026-09-20T10:00:00Z');
        $lineB = $this->seedSalesOrderLine(self::TENANT_A, $order, $b, lineNumber: 1, quantity: '2');
        $lineA = $this->seedSalesOrderLine(self::TENANT_A, $order, $a, lineNumber: 2, quantity: '2');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-01');
        $this->stock($b, $bin);
        $this->stock($a, $bin);

        $holder = $this->raceAgainstAHolder([[ItemWriter::DELETE_LOCK, [$a]]], function () use ($order, $lineB, $lineA, $bin): void {
            $this->send('POST', '/sales-orders/'.$order.'/fulfilments', ['lines' => [
                ['sales_order_line_id' => $lineB, 'quantity' => '1', 'storage_location_id' => $bin],
                ['sales_order_line_id' => $lineA, 'quantity' => '1', 'storage_location_id' => $bin],
            ]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        }, [[ItemWriter::DELETE_LOCK, [$b]]]);

        $shipped = fn (): int => $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM fulfilment WHERE sales_order_id = ?::uuid', [$order]);
        $this->assertExactlyOneSideAborted($holder, 'fulfilment [B, A] vs bulk item archive', requestIsBulk: false, requestSucceeded: 201,
            requestWhole: fn (): bool => 1 === $shipped(), requestAbsent: fn (): bool => 0 === $shipped());
    }

    /**
     * RED M-3 (G5-C2): a nested `CreatePurchaseOrder` with lines `[B, A]` — each line's item liveness read is `FOR KEY SHARE`
     * since G-59's closing work (this commit), taken in line order, so it cycles with a bulk exactly like a receipt.
     */
    #[Test]
    public function aNestedPurchaseOrderCreateCyclingWithABulkItemArchiveAbortsExactlyOneSide(): void
    {
        [$a, $b] = $this->sortedItems('BLK-DL4');
        $supplier = $this->seedSupplier(self::TENANT_A, 'Sup DL4');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH DL4');
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $a);
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $b);

        $holder = $this->raceAgainstAHolder([[ItemWriter::DELETE_LOCK, [$a]]], function () use ($supplier, $warehouse, $a, $b): void {
            $this->send('POST', '/purchase-orders', [
                'supplier_id' => $supplier, 'warehouse_id' => $warehouse, 'currency_code' => 'EUR',
                'lines' => [['inventory_item_id' => $b, 'quantity' => '1', 'unit_cost' => '1'], ['inventory_item_id' => $a, 'quantity' => '1', 'unit_cost' => '1']],
            ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        }, [[ItemWriter::DELETE_LOCK, [$b]]]);

        $orders = fn (): int => $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM purchase_order WHERE supplier_id = ?::uuid', [$supplier]);
        $this->assertExactlyOneSideAborted($holder, 'nested CreatePurchaseOrder [B, A] vs bulk item archive', requestIsBulk: false, requestSucceeded: 201,
            requestWhole: fn (): bool => 1 === $orders(), requestAbsent: fn (): bool => 0 === $orders());
    }

    /**
     * RED M-3 (G5-C2): the `storage_location` member holds bins `FOR UPDATE` in `(warehouse_id, id)` order; a receipt into
     * bins `[L2, L1]` takes `KEY SHARE` on them in line order (its `stock_level` / `stock_movement` FKs). The same cycle.
     */
    #[Test]
    public function aReceiptIntoTwoBinsCyclingWithABulkLocationArchiveAbortsExactlyOneSide(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Sup DL5');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH DL5');
        $bins = [$this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-1'), $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-2')];
        sort($bins, \SORT_STRING);
        [$l1, $l2] = $bins;
        $order = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse, status: 'ordered');
        $first = $this->seedLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'BLK-DL5-1'), lineNumber: 1);
        $second = $this->seedLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'BLK-DL5-2'), lineNumber: 2);

        $holder = $this->raceAgainstAHolder([
            [StorageLocationWriter::PARENT_LOCK, [$warehouse]],
            [StorageLocationWriter::DELETE_LOCK, [$l1, $warehouse]],
        ], function () use ($order, $first, $second, $l1, $l2): void {
            $this->send('POST', '/purchase-orders/'.$order.'/goods-receipts', ['lines' => [
                ['purchase_order_line_id' => $first, 'quantity' => '1', 'storage_location_id' => $l2],
                ['purchase_order_line_id' => $second, 'quantity' => '1', 'storage_location_id' => $l1],
            ]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        }, [[StorageLocationWriter::DELETE_LOCK, [$l2, $warehouse]]]);

        $receipts = fn (): int => $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt WHERE purchase_order_id = ?::uuid', [$order]);
        $this->assertExactlyOneSideAborted($holder, 'receipt into bins [L2, L1] vs bulk location archive', requestIsBulk: false, requestSucceeded: 201,
            requestWhole: fn (): bool => 1 === $receipts(), requestAbsent: fn (): bool => 0 === $receipts());
    }

    /**
     * A single-item stock writer cannot cycle with a bulk (it holds no second item): it waits on the member's item lock
     * and then answers as it answers `DeleteItem` (C2a measured the same statements, `ItemDeleteLockOrderTest`) — the
     * adjustment refuses the archived item.
     */
    #[Test]
    public function aSingleItemAdjustmentWaitsOnABulkItemArchiveAndIsRefused(): void
    {
        [$a] = $this->sortedItems('BLK-ADJ');
        $bin = $this->seedStorageLocation(self::TENANT_A, $this->seedWarehouse(self::TENANT_A, 'WH ADJ'), 'L-1');

        $holder = $this->raceAgainstAHolder([[ItemWriter::DELETE_LOCK, [$a]], [ItemWriter::ARCHIVE, [$a]]], function () use ($a, $bin): void {
            $this->send('POST', '/stock-movements/adjustments', [
                'inventory_item_id' => $a, 'storage_location_id' => $bin, 'counted_quantity' => '3', 'version' => null, 'note' => 'Counted',
            ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        self::assertTrue($holder['waiter_seen'], json_encode($holder));
        self::assertTrue($holder['committed'], json_encode($holder));
        self::assertSame(409, $this->httpStatus(), $this->responseContent());
        self::assertSame('inventory_item.is_archived', $this->json()['rule'] ?? null, $this->responseContent());
    }

    // -----------------------------------------------------------------------

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertNoCycle(array $holder): void
    {
        self::assertTrue($holder['waiter_seen'], 'The request never waited on the holder, so nothing here was a race. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit (a deadlock aborts one side): '.json_encode($holder));
        self::assertNotSame(500, $this->httpStatus(), $this->responseContent());
        self::assertNotSame(409, $this->httpStatus(), 'Contention where no cycle should exist: '.$this->responseContent());
    }

    /**
     * **What a real lock cycle guarantees, and only that** (LEAD gate run 2, 2026-10-23: the side PostgreSQL aborts is NOT
     * fixed — every waiter runs its own deadlock check after `deadlock_timeout`, and under load either may fire first; run 1
     * aborted the shipped writer, run 2 the bulk). Asserted: the cycle formed (the request waited on the holder); EXACTLY one
     * side was aborted; an aborted holder failed with `40P01`; an aborted request answers as specified — the bulk 409
     * `bulk.contention`, a shipped writer 409 `transaction.contention` (G-65 closed 2026-10-23; it was a 500); the surviving request's work
     * is whole and the aborted request's is absent (nothing half-applied; the holder's statements are locks only). Which side
     * was chosen is DATA, written to STDERR, never an assertion.
     *
     * @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder
     */
    private function assertExactlyOneSideAborted(array $holder, string $what, bool $requestIsBulk, int $requestSucceeded, callable $requestWhole, callable $requestAbsent): void
    {
        $status = $this->httpStatus();
        $body = $this->responseContent();
        self::assertTrue($holder['waiter_seen'], $what.': no cycle formed. '.json_encode($holder).' response: '.$status.' '.$body);

        // G-65 closed (2026-10-23): either victim now answers 409 — the bulk `bulk.contention`, a shipped writer
        // `transaction.contention` (one listener arm) — never a 500. Keyed on the rule, so another 409 is not an abort.
        self::assertNotSame(500, $status, $what.': an aborted request answered 500 — the G-65 contention arm is missing. '.$body);
        $abortRule = $requestIsBulk ? BulkArchival::CONTENTION_RULE : TransactionContention::RULE;
        $requestAborted = 409 === $status && $abortRule === ($this->json()['rule'] ?? null);
        fwrite(\STDERR, \sprintf("\n[G5-C2 deadlock measurement] %s: victim = %s\n", $what, $requestAborted ? 'the request' : 'the holder'));

        if ($requestAborted) {
            self::assertTrue($holder['committed'], $what.': the request was aborted, so the holder must have committed. '.json_encode($holder));
            self::assertSame($requestIsBulk ? BulkArchival::CONTENTION_MESSAGE : TransactionContention::MESSAGE, $this->json()['detail'] ?? null, $body);
            self::assertTrue($requestAbsent(), $what.': the aborted request left work behind.');

            return;
        }

        self::assertFalse($holder['committed'], $what.': both sides committed, so no cycle was resolved by an abort. '.json_encode($holder).' '.$status);
        self::assertSame('40P01', $holder['sqlstate'], $what.': the holder failed for another reason. '.json_encode($holder));
        self::assertSame($requestSucceeded, $status, $what.': the holder was the victim, so the request must succeed. '.$body);
        self::assertTrue($requestWhole(), $what.': the surviving request\'s work is not whole.');
    }

    private function stock(string $item, string $bin): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 5)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin]);
    }

    /** @return array{0: string, 1: string} two customers, byte-sorted ids */
    private function sortedCustomers(string $tag): array
    {
        $ids = [$this->seedCustomer(self::TENANT_A, $tag.' 1'), $this->seedCustomer(self::TENANT_A, $tag.' 2')];
        sort($ids, \SORT_STRING);

        return [$ids[0], $ids[1]];
    }

    /** @return array{0: string, 1: string} two items, byte-sorted ids */
    private function sortedItems(string $tag): array
    {
        $ids = [$this->seedItem(self::TENANT_A, $tag.'-1'), $this->seedItem(self::TENANT_A, $tag.'-2')];
        sort($ids, \SORT_STRING);

        return [$ids[0], $ids[1]];
    }

    /** @param list<string> $ids */
    private function bulk(string $resource, array $ids): void
    {
        $this->send('POST', '/bulk/archivals', ['resource' => $resource, 'ids' => $ids], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
    }
}
