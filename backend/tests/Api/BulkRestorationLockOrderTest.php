<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Warehouses\Application\WarehouseWriter;
use App\Platform\Http\Bulk\BulkArchival;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateBulkRestoration`'s lock order, measured against real second connections** (G5-C3, 2026-10-23;
 * `docs/session13/g5-c3-design.md` D-3 as rewritten after RED `g5-c3-red.md` M-1).
 *
 *  - **Two bulk restores naming the same ids in opposite order** do not cycle: members run in ascending id whatever order
 *    the request names (the archival's engine). *Mutation:* process ids in request order → a deadlock → red.
 *  - **A bulk order restore ∥ a bulk warehouse archival CAN cycle** — the restore takes each order's warehouse `FOR KEY
 *    SHARE` in ORDER-id order, the archival takes warehouses `FOR UPDATE` in WAREHOUSE-id order. PostgreSQL aborts one side
 *    (which one is not fixed, `BulkArchivalLockOrderTest`'s finding), and either answer is safe: the aborted request is a
 *    409 `bulk.contention`, nothing done; the survivor's work is whole. Accepted as a contention residual (G-65's class,
 *    answered 409 since its closing work), never a 500.
 *  - **A bulk location restore ∥ `DeleteWarehouse`** waits on the warehouse lock (the restore's parent `FOR SHARE`), no
 *    cycle, and is then refused per member (`warehouse.is_archived`) — counted, not a 409 or a 500.
 */
final class BulkRestorationLockOrderTest extends PurchaseOrderTestCase
{
    use BulkRaceHolder;

    private const NO_KEY_UPDATE_CUSTOMER = 'SELECT 1 FROM customer WHERE id = ?::uuid FOR NO KEY UPDATE';

    #[Test]
    public function twoBulkRestoresNamingTheSameIdsInOppositeOrderDoNotDeadlock(): void
    {
        $ids = [$this->archived('customer', $this->seedCustomer(self::TENANT_A, 'Cus RLO 1')), $this->archived('customer', $this->seedCustomer(self::TENANT_A, 'Cus RLO 2'))];
        sort($ids, \SORT_STRING);
        [$a, $b] = $ids;

        $holder = $this->raceAgainstAHolder([[self::NO_KEY_UPDATE_CUSTOMER, [$a]]], function () use ($a, $b): void {
            $this->restore('customer', [$b, $a]);
        }, [[self::NO_KEY_UPDATE_CUSTOMER, [$b]]]);

        self::assertTrue($holder['waiter_seen'], 'The request never waited on the holder. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit (a deadlock aborts one side): '.json_encode($holder));
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame(['requested' => 2, 'applied' => 2], $this->json());
    }

    #[Test]
    public function aBulkOrderRestoreCyclingWithABulkWarehouseArchivalAbortsExactlyOneSide(): void
    {
        $warehouses = [$this->seedWarehouse(self::TENANT_A, 'WH RLO A'), $this->seedWarehouse(self::TENANT_A, 'WH RLO B')];
        sort($warehouses, \SORT_STRING);
        [$wa, $wb] = $warehouses;
        $orders = [$this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO')), $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'))];
        sort($orders, \SORT_STRING);
        [$first, $second] = $orders;
        // The lower order id sits on the HIGHER warehouse, so the restore takes [WB, WA] where the archival takes [WA, WB].
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET warehouse_id = ?::uuid WHERE id = ?::uuid', [$wb, $first]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET warehouse_id = ?::uuid WHERE id = ?::uuid', [$wa, $second]);
        $this->archived('sales_order', $first);
        $this->archived('sales_order', $second);

        $holder = $this->raceAgainstAHolder([[WarehouseWriter::DELETE_LOCK, [$wa]]], function () use ($first, $second): void {
            $this->restore('sales_order', [$first, $second]);
        }, [[WarehouseWriter::DELETE_LOCK, [$wb]]]);

        $status = $this->httpStatus();
        $body = $this->responseContent();
        self::assertTrue($holder['waiter_seen'], 'No cycle formed. '.json_encode($holder).' '.$status.' '.$body);
        self::assertNotSame(500, $status, 'A lost cycle answered 500. '.$body);
        $live = fn (): int => $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM sales_order WHERE id IN (?::uuid, ?::uuid) AND deleted_at IS NULL', [$first, $second]);
        $requestAborted = 409 === $status && BulkArchival::CONTENTION_RULE === ($this->json()['rule'] ?? null);
        fwrite(\STDERR, \sprintf("\n[G5-C3 deadlock measurement] bulk order restore vs bulk warehouse archival: victim = %s\n", $requestAborted ? 'the request' : 'the holder'));

        if ($requestAborted) {
            self::assertTrue($holder['committed'], json_encode($holder));
            self::assertSame(0, $live(), 'The aborted restoration left work behind.');

            return;
        }
        self::assertFalse($holder['committed'], 'Both sides committed, so no cycle was resolved by an abort. '.json_encode($holder).' '.$status);
        self::assertSame('40P01', $holder['sqlstate'], json_encode($holder));
        self::assertSame(['requested' => 2, 'applied' => 2], $this->json(), $body);
        self::assertSame(2, $live());
    }

    #[Test]
    public function aBulkLocationRestoreWaitsOnDeleteWarehouseAndIsThenRefusedPerMember(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH RLO DEL');
        $location = $this->archived('storage_location', $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-1'));

        $holder = $this->raceAgainstAHolder([[WarehouseWriter::DELETE_LOCK, [$warehouse]], [WarehouseWriter::ARCHIVE, [$warehouse]]], function () use ($location): void {
            $this->restore('storage_location', [$location]);
        });

        self::assertTrue($holder['waiter_seen'], json_encode($holder));
        self::assertTrue($holder['committed'], json_encode($holder));
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame(['requested' => 1, 'applied' => 0], $this->json(), 'A location of a warehouse archived meanwhile is not restored.');
    }

    // -----------------------------------------------------------------------

    /** @param list<string> $ids */
    private function restore(string $resource, array $ids): void
    {
        $this->send('POST', '/bulk/restorations', ['resource' => $resource, 'ids' => $ids], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
    }

    private function archived(string $table, string $id): string
    {
        \assert(1 === preg_match('/^[a-z_]+$/', $table));
        $this->executeAsOwnerForTenant(self::TENANT_A, \sprintf('UPDATE %s SET deleted_at = now() WHERE id = ?::uuid', $table), [$id]);

        return $id;
    }
}
