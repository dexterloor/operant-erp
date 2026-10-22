<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Http\Problem\FrameworkProblemTranslator;
use App\Platform\Http\Problem\TransactionContention;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **G-65's closing work (2026-10-23; `docs/session13/g65-design.md`): a shipped write that loses a lock cycle answers 409
 * `transaction.contention`, nothing saved — never a 500.**
 *
 * A real cycle's victim is chosen by PostgreSQL (`BulkArchivalLockOrderTest`: either side may be aborted), so a race cannot
 * make the shipped side lose on demand. These cases make it lose DETERMINISTICALLY: the schema owner plants, for the
 * length of one test, a trigger that raises SQLSTATE `40P01` — the server's own deadlock code, which DBAL maps to
 * {@see DeadlockException} exactly as for a detected cycle, and which aborts the transaction the same way — on a row only
 * THIS test writes (keyed on its own item id, so a trigger left behind by a crash touches no other DATA; the catalogue is kept
 * clean by revoking PUBLIC EXECUTE and by dropping any leftover before planting — RED `g65-red.md` F-2). The raise comes
 * AFTER the request's earlier statements (the receipt / fulfilment / order header and lines, the stock level), so
 * "nothing was saved" is asserted, not assumed.
 *
 * Paths: `CreateGoodsReceipt`, `CreateFulfilment`, nested `CreatePurchaseOrder` and `CreateStockAdjustment` — one per
 * stock-writing module and the order create, the G-65 set; the translation is one listener arm, so these stand for every
 * API write. **Control:** the same plant raising `P0001` (a plain `RAISE EXCEPTION`) is still a 500 — the arm is keyed on
 * the contention states, not on every driver failure. **Retry:** after the 409 the same `Idempotency-Key` is a first
 * send (201, one receipt).
 *
 * *Mutations, run (`docs/session13/g65-g5c3-progress.md`):* the listener arm removed → every 409 case red (500);
 * {@see TransactionContention::is()} widened to every `DriverException` → the control red; `55P03` dropped from the predicate
 * → red in the unit case only (no `lock_timeout` is set on any shipped path, so a real `55P03` is unreachable there — RED
 * F-7); the cause no longer chained → {@see self::theTranslatedProblemKeepsTheDriverFailure} red.
 * *Faithfulness (RED F-7):* a real cycle is raised at a `FOR KEY SHARE` read or an FK check, the plant at a BEFORE INSERT;
 * no catch in `src/` distinguishes the two.
 */
final class TransactionContentionTest extends PurchaseOrderTestCase
{
    private const PLANTED_FUNCTION = 'zz_g65_contention';

    #[Test]
    public function theContentionStatesAndOnlyThoseAreContention(): void
    {
        foreach (['40P01' => true, '40001' => true, '55P03' => true, '23505' => false, 'P0001' => false, '25P02' => false] as $state => $expected) {
            $failure = new DriverException(self::driverFailure((string) $state), null);
            self::assertSame($expected, TransactionContention::is($failure), (string) $state);
        }
        $deadlock = new DeadlockException(self::driverFailure(null), null);
        self::assertTrue(TransactionContention::is($deadlock), 'A DeadlockException is contention whatever its state reads.');
        $unique = new UniqueConstraintViolationException(self::driverFailure('23505'), null);
        self::assertFalse(TransactionContention::is($unique));
    }

    #[Test]
    public function aReceiptThatLosesALockCycleIs409NothingSavedAndItsRetrySucceeds(): void
    {
        [$order, $line, $bin, $item] = $this->orderedLine('G65-REC');
        $key = Uuid::v4()->toRfc4122();
        $body = ['lines' => [['purchase_order_line_id' => $line, 'quantity' => '2', 'storage_location_id' => $bin]]];
        $audit = $this->auditRows();

        $this->withPlantedFailure('stock_movement', $item, '40P01', function () use ($order, $body, $key): void {
            $this->send('POST', '/purchase-orders/'.$order.'/goods-receipts', $body, [IdempotencyKeyGuard::HEADER => $key]);
        });

        $this->assertContention();
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt WHERE purchase_order_id = ?::uuid', [$order]));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_level WHERE inventory_item_id = ?::uuid', [$item]));
        self::assertSame($audit, $this->auditRows(), 'The aborted receipt left an audit row behind.');

        // The retry is a first send: the key was never stored.
        $this->send('POST', '/purchase-orders/'.$order.'/goods-receipts', $body, [IdempotencyKeyGuard::HEADER => $key]);
        self::assertSame(201, $this->httpStatus(), $this->responseContent());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt WHERE purchase_order_id = ?::uuid', [$order]));
    }

    #[Test]
    public function aFulfilmentThatLosesALockCycleIs409AndNothingSaved(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'G65-FUL');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH G65 FUL');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-01');
        $order = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), null, $warehouse, 'confirmed', '2026-09-20T10:00:00Z');
        $line = $this->seedSalesOrderLine(self::TENANT_A, $order, $item, lineNumber: 1, quantity: '2');
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 5)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin]);
        $audit = $this->auditRows();

        $this->withPlantedFailure('stock_movement', $item, '40P01', function () use ($order, $line, $bin): void {
            $this->send('POST', '/sales-orders/'.$order.'/fulfilments', ['lines' => [
                ['sales_order_line_id' => $line, 'quantity' => '1', 'storage_location_id' => $bin],
            ]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertContention();
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM fulfilment WHERE sales_order_id = ?::uuid', [$order]));
        self::assertSame(5, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT qty_on_hand::int FROM stock_level WHERE inventory_item_id = ?::uuid', [$item]));
        self::assertSame($audit, $this->auditRows());
    }

    #[Test]
    public function aNestedPurchaseOrderCreateThatLosesALockCycleIs409AndNothingSaved(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Sup G65');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH G65 PO');
        $first = $this->seedItem(self::TENANT_A, 'G65-PO-1');
        $second = $this->seedItem(self::TENANT_A, 'G65-PO-2');
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $first);
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $second);
        $audit = $this->auditRows();

        $this->withPlantedFailure('purchase_order_line', $second, '40P01', function () use ($supplier, $warehouse, $first, $second): void {
            $this->send('POST', '/purchase-orders', [
                'supplier_id' => $supplier, 'warehouse_id' => $warehouse, 'currency_code' => 'EUR',
                'lines' => [['inventory_item_id' => $first, 'quantity' => '1', 'unit_cost' => '1'], ['inventory_item_id' => $second, 'quantity' => '1', 'unit_cost' => '1']],
            ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertContention();
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM purchase_order WHERE supplier_id = ?::uuid', [$supplier]));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM purchase_order_line WHERE inventory_item_id = ?::uuid', [$first]));
        self::assertSame($audit, $this->auditRows());
    }

    #[Test]
    public function aStockAdjustmentThatLosesALockCycleIs409AndNothingSaved(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'G65-ADJ');
        $bin = $this->seedStorageLocation(self::TENANT_A, $this->seedWarehouse(self::TENANT_A, 'WH G65 ADJ'), 'L-1');
        $audit = $this->auditRows();

        $this->withPlantedFailure('stock_movement', $item, '40P01', function () use ($item, $bin): void {
            $this->send('POST', '/stock-movements/adjustments', [
                'inventory_item_id' => $item, 'storage_location_id' => $bin, 'counted_quantity' => '3', 'version' => null, 'note' => 'Counted',
            ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertContention();
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_level WHERE inventory_item_id = ?::uuid', [$item]));
        self::assertSame($audit, $this->auditRows());
    }

    /**
     * RED F-1: the 409's body is the rule only, but the driver failure is chained, so whatever logs the exception keeps
     * PostgreSQL's SQLSTATE and deadlock detail (the evidence a shipped-vs-shipped lock-order defect needs).
     */
    #[Test]
    public function theTranslatedProblemKeepsTheDriverFailure(): void
    {
        $deadlock = new DeadlockException(self::driverFailure('40P01'), null);
        $request = Request::create('/api/v1/purchase-orders', 'POST');
        $request->attributes->set('_api_resource_class', \stdClass::class);
        $event = new ExceptionEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $deadlock);

        self::getContainer()->get(FrameworkProblemTranslator::class)($event);

        $problem = $event->getThrowable();
        self::assertInstanceOf(ApiProblem::class, $problem);
        self::assertSame(409, $problem->getStatusCode());
        self::assertSame(TransactionContention::RULE, $problem->getRule());
        self::assertSame($deadlock, $problem->getPrevious(), 'The contention problem dropped its cause (RED F-1).');
    }

    /** The control: any other driver failure raised at the same point is still a 500 — the arm swallows nothing else. */
    #[Test]
    public function anyOtherDriverFailureIsStillAServerError(): void
    {
        [$order, $line, $bin, $item] = $this->orderedLine('G65-CTL');

        $this->withPlantedFailure('stock_movement', $item, 'P0001', function () use ($order, $line, $bin): void {
            $this->send('POST', '/purchase-orders/'.$order.'/goods-receipts', ['lines' => [
                ['purchase_order_line_id' => $line, 'quantity' => '2', 'storage_location_id' => $bin],
            ]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        self::assertSame(500, $this->httpStatus(), $this->responseContent());
        self::assertStringNotContainsString(TransactionContention::RULE, $this->responseContent());
    }

    // -----------------------------------------------------------------------

    private static function driverFailure(?string $state): AbstractException
    {
        return new class('planted', $state) extends AbstractException {
        };
    }

    private function assertContention(): void
    {
        self::assertSame(409, $this->httpStatus(), $this->responseContent());
        self::assertSame(TransactionContention::RULE, $this->json()['rule'] ?? null, $this->responseContent());
        self::assertSame(TransactionContention::MESSAGE, $this->json()['detail'] ?? null, $this->responseContent());
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} order, line, bin, item */
    private function orderedLine(string $tag): array
    {
        $item = $this->seedItem(self::TENANT_A, $tag);
        $supplier = $this->seedSupplier(self::TENANT_A, 'Sup '.$tag);
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.$tag);
        $order = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse, status: 'ordered');
        $line = $this->seedLine(self::TENANT_A, $order, $item, lineNumber: 1);
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-01');

        return [$order, $line, $bin, $item];
    }

    private function auditRows(): int
    {
        return $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log');
    }

    /**
     * Plants, as the schema owner and COMMITTED (the request runs on another connection), a `BEFORE INSERT` row trigger on
     * `$table` that raises `$sqlstate` for rows naming `$item` only; runs `$request`; drops both in `finally`.
     */
    private function withPlantedFailure(string $table, string $item, string $sqlstate, callable $request): void
    {
        \assert(\in_array($table, ['stock_movement', 'purchase_order_line'], true));
        \assert(1 === preg_match('/^[0-9A-Z]{5}$/', $sqlstate) && Uuid::isValid($item));
        $owner = $this->owner();
        $function = self::PLANTED_FUNCTION;
        self::assertSame(0, $this->em()->getConnection()->getTransactionNestingLevel(), 'The app connection holds an open transaction; the DDL below would wait on it.');
        // RED F-2: a plant left by a crashed run is dropped first, so the test can always re-plant.
        $owner->executeStatement(\sprintf('DROP TRIGGER IF EXISTS %s ON public.%s', $function, $table));
        $owner->executeStatement(\sprintf('DROP FUNCTION IF EXISTS public.%s()', $function));
        $owner->executeStatement(\sprintf(
            "CREATE FUNCTION public.%s() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN RAISE EXCEPTION 'planted contention (G-65 test)' USING ERRCODE = '%s'; END \$f\$",
            $function,
            $sqlstate,
        ));
        try {
            // RED F-2: a new function is PUBLIC-executable by default, which `GrantFootprintTest` refuses for any function in
            // `public`; a trigger's firing checks no EXECUTE, so revoking it costs the plant nothing.
            $owner->executeStatement(\sprintf('REVOKE ALL ON FUNCTION public.%s() FROM PUBLIC', $function));
            $owner->executeStatement(\sprintf(
                "CREATE TRIGGER %s BEFORE INSERT ON public.%s FOR EACH ROW WHEN (NEW.inventory_item_id = '%s'::uuid) EXECUTE FUNCTION public.%s()",
                $function,
                $table,
                $item,
                $function,
            ));
            try {
                $request();
            } finally {
                $owner->executeStatement(\sprintf('DROP TRIGGER IF EXISTS %s ON public.%s', $function, $table));
            }
        } finally {
            $owner->executeStatement(\sprintf('DROP FUNCTION IF EXISTS public.%s()', $function));
        }
    }
}
