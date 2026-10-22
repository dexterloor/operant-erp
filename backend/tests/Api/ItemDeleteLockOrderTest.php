<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Inventory\Application\ItemWriter;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **G5-C2a — `DeleteItem`'s new item `FOR UPDATE` against the shipped stock writers** (`docs/session13/g5-g6-final-red.md`
 * M-3, measured rather than assumed). Before G5-C2a `DeleteItem` took only the `FOR NO KEY UPDATE` its UPDATE implies, which
 * does not conflict with the `KEY SHARE` a stock writer takes on each item (the `stock_movement` FK, line by line, or
 * `StockAdjuster` / `StockTransferrer`'s explicit item read). `FOR UPDATE` does, so the two now serialise.
 *
 * **Why no deadlock is possible here, and what is measured instead.** A cycle needs each side to hold a lock the other
 * wants. `DeleteItem` takes exactly one row lock ({@see ItemWriter::DELETE_LOCK}, then {@see ItemWriter::ARCHIVE} on the
 * same row) and then only an `audit_log` INSERT (whose trigger reads the trail without locking). So against a multi-line
 * receipt or fulfilment whose lines run `[B, A]`, the writer holds B and waits on A, and `DeleteItem` wants nothing B
 * holds: one side waits, the other commits. The cycle M-3 describes needs a SECOND item lock on the archiving side, which
 * only the bulk archival (G5-C2) will take — its pairs are measured there.
 *
 * **What these tests can and cannot show (RED `g5-c2a-red.md` L-1).** The holder runs nothing after the request starts
 * waiting, so no cycle can form inside a test and the "no 500, no `40P01`" assertions cannot go red by themselves: they pin
 * that the request WAITED on the item lock (mutations M1 / M3 / M8 turn every case red by removing the wait) and what it
 * answers afterwards. Deadlock-freedom rests on the structural argument above, not on these runs.
 *
 * Each race is a real second connection (`support/hold_order_lock.php`) and fails if the request never waited:
 *  - **the delete first** (the writer's own statements, uncommitted) ∥ each shipped stock writer over HTTP with the deleted
 *    item on its LAST line: the writer waits on the item, then answers as recorded below — never a 500, never `40P01`;
 *  - **the stock writer first** (its two item `KEY SHARE`s, lines `[B, A]`, uncommitted) ∥ `DeleteItem(A)`: the delete
 *    waits, then archives (204).
 *
 * **What the writers answer after waiting** (measured 2026-10-22, `docs/session13/g5-c2a-progress.md`): the adjustment and
 * the transfer re-read the item `FOR KEY SHARE` and refuse it as archived (409 `inventory_item.is_archived`, the owner's
 * Q4 refusal). The receipt and the fulfilment read no item liveness, so they post against the just-archived item (201) —
 * the same outcome as before G5-C2a, when they did not even wait; recorded, not changed here.
 */
final class ItemDeleteLockOrderTest extends PurchaseOrderTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    /** A stock writer's per-item lock, as its FK check takes it. */
    private const ITEM_KEY_SHARE = 'SELECT 1 FROM inventory_item WHERE id = ?::uuid FOR KEY SHARE';

    #[Test]
    public function aReceiptWhoseLastLineIsAnItemBeingArchivedWaitsAndDoesNotDeadlock(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Lock Supplier');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH lock receipt');
        $order = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse, status: 'ordered');
        $first = $this->seedItem(self::TENANT_A, 'LOCK-GR-B');
        $archived = $this->seedItem(self::TENANT_A, 'LOCK-GR-A');
        $lineB = $this->seedLine(self::TENANT_A, $order, $first, lineNumber: 1);
        $lineA = $this->seedLine(self::TENANT_A, $order, $archived, lineNumber: 2);
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-01');

        $holder = $this->raceAgainstTheDelete($archived, function () use ($order, $lineB, $lineA, $bin): void {
            $this->send('POST', '/purchase-orders/'.$order.'/goods-receipts', ['lines' => [
                ['purchase_order_line_id' => $lineB, 'quantity' => '1', 'storage_location_id' => $bin],
                ['purchase_order_line_id' => $lineA, 'quantity' => '1', 'storage_location_id' => $bin],
            ]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedAndAnswered($holder, 201);
    }

    #[Test]
    public function aFulfilmentWhoseLastLineIsAnItemBeingArchivedWaitsAndDoesNotDeadlock(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH lock fulfil');
        $order = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), null, $warehouse, SalesOrder::STATUS_CONFIRMED, '2026-09-20T10:00:00Z');
        $first = $this->seedItem(self::TENANT_A, 'LOCK-FUL-B');
        $archived = $this->seedItem(self::TENANT_A, 'LOCK-FUL-A');
        $lineB = $this->seedSalesOrderLine(self::TENANT_A, $order, $first, lineNumber: 1, quantity: '2');
        $lineA = $this->seedSalesOrderLine(self::TENANT_A, $order, $archived, lineNumber: 2, quantity: '2');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-02');
        $this->stock($first, $bin, '5');
        $this->stock($archived, $bin, '5');

        $holder = $this->raceAgainstTheDelete($archived, function () use ($order, $lineB, $lineA, $bin): void {
            $this->send('POST', '/sales-orders/'.$order.'/fulfilments', ['lines' => [
                ['sales_order_line_id' => $lineB, 'quantity' => '1', 'storage_location_id' => $bin],
                ['sales_order_line_id' => $lineA, 'quantity' => '1', 'storage_location_id' => $bin],
            ]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedAndAnswered($holder, 201);
    }

    #[Test]
    public function anAdjustmentOfAnItemBeingArchivedWaitsAndIsRefusedAsArchived(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH lock adjust');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-03');
        $archived = $this->seedItem(self::TENANT_A, 'LOCK-ADJ-A');

        $holder = $this->raceAgainstTheDelete($archived, function () use ($archived, $bin): void {
            $this->send('POST', '/stock-movements/adjustments', [
                'inventory_item_id' => $archived, 'storage_location_id' => $bin, 'counted_quantity' => '3', 'version' => null, 'note' => 'Counted',
            ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedAndAnswered($holder, 409);
        self::assertSame('inventory_item.is_archived', $this->json()['rule']);
    }

    #[Test]
    public function aTransferOfAnItemBeingArchivedWaitsAndIsRefusedAsArchived(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH lock transfer');
        $from = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-04');
        $to = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-05');
        $archived = $this->seedItem(self::TENANT_A, 'LOCK-TRF-A');
        $this->stock($archived, $from, '5');

        $holder = $this->raceAgainstTheDelete($archived, function () use ($archived, $from, $to): void {
            $this->send('POST', '/stock-movements/transfers', [
                'inventory_item_id' => $archived, 'source_storage_location_id' => $from, 'destination_storage_location_id' => $to,
                'quantity' => '2', 'note' => 'Moved',
            ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedAndAnswered($holder, 409);
        self::assertSame('inventory_item.is_archived', $this->json()['rule']);
    }

    /** The other order: a multi-line stock writer holds `[B, A]` first; the delete of A waits on it and then archives. */
    #[Test]
    public function aDeleteOfAnItemAStockWriterHoldsWaitsThenArchives(): void
    {
        $first = $this->seedItem(self::TENANT_A, 'LOCK-SW-B');
        $archived = $this->seedItem(self::TENANT_A, 'LOCK-SW-A');

        $holder = $this->raceAgainstAHolder([[self::ITEM_KEY_SHARE, [$first]], [self::ITEM_KEY_SHARE, [$archived]]], function () use ($archived): void {
            $this->send('DELETE', '/items/'.$archived);
        });

        $this->assertWaitedAndAnswered($holder, 204);
        self::assertNotNull($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at FROM inventory_item WHERE id = ?::uuid', [$archived])[0]['deleted_at']);
    }

    // -----------------------------------------------------------------------

    private function stock(string $item, string $bin, string $qty): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin, $qty]);
    }

    /** @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} */
    private function raceAgainstTheDelete(string $item, callable $request): array
    {
        return $this->raceAgainstAHolder([[ItemWriter::DELETE_LOCK, [$item]], [ItemWriter::ARCHIVE, [$item]]], $request);
    }

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertWaitedAndAnswered(array $holder, int $status): void
    {
        $body = (string) $this->client()->getResponse()->getContent();
        self::assertTrue($holder['waiter_seen'], 'The request never waited on the holder, so nothing here was a race. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit (a deadlock would abort one side): '.json_encode($holder));
        self::assertNotSame(500, $this->httpStatus(), 'The request failed with a 500 (a 40P01 surfaces as one): '.$body);
        self::assertStringNotContainsString('40P01', $body);
        self::assertSame($status, $this->httpStatus(), $body);
    }

    /**
     * `ItemDeleteTest::raceAgainstAHolder`'s shape (a copy, not extracted, the earlier copies' reason).
     *
     * @param list<array{0: string, 1: list<string>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_item_lock_order_');
        file_put_contents($readyFile, '');

        $proc = proc_open(
            [PHP_BINARY, __DIR__.'/support/hold_order_lock.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'APP_DIRECT_DATABASE_URL' => $_ENV['APP_DIRECT_DATABASE_URL'],
                'HOLD_TENANT' => self::TENANT_A,
                'HOLD_ORDER' => Uuid::v4()->toRfc4122(),
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
