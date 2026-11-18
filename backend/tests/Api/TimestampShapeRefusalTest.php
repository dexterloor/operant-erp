<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **G-105: a timestamp with a zone name, a military letter or a year past 9999 in UTC is a field defect, never a 500 and
 * never a different stored time** (shipped-defect fix, 2026-11-18; measured in `docs/session13/bk18-r18fg-h1-measure.md`).
 *
 * Before the fix the shared parser accepted all five shapes below, and `CreatePurchaseOrder` and `UpdatePurchaseOrder`
 * bound the raw text, so Postgres read it itself: `…a` answered 500 on both (22007), `…GMT+2` was stored as 02:00Z
 * while the parser, the retry digest and `ApprovePurchaseOrder` read 22:00Z, and `9999-12-31T23:59:59-15:59` answered
 * 500 on all three (year 10000, which no reader can read back). `UTC` and `Europe/Paris` were accepted and stored
 * correctly; they are refused now because the rule is "`Z` or a numeric offset", not "the names that misbehave".
 *
 * **Each operation is driven separately**, because each binds `expected_at` on its own path: the create INSERT, the
 * update's `CAST(:expectedAt AS timestamptz)` and the approval's ORM write. The positive control on each shows the
 * parsed instant is what is stored and served.
 *
 * **Not seen:** a path binding the raw text of a value the parser accepts and Postgres reads the same way (a numeric
 * offset) cannot be told from one binding the parsed instant; that half is a review check (the commit's M-C0a).
 */
final class TimestampShapeRefusalTest extends PurchaseOrderTestCase
{
    private const BEFORE = '2026-06-01T00:00:00.000+00:00';

    /** @return iterable<string, array{string, string}> */
    public static function refusedShapes(): iterable
    {
        $shapes = [
            'military letter' => '2026-10-01T00:00:00a',
            'region name' => '2026-10-01T00:00:00Europe/Paris',
            'GMT+2' => '2026-10-01T00:00:00GMT+2',
            // Ends in a valid offset: only a rule anchored to the seconds refuses it.
            'GMT+02:00' => '2026-10-01T00:00:00GMT+02:00',
            'UTC zone name' => '2026-10-01T00:00:00UTC',
            'year 10000 in UTC' => '9999-12-31T23:59:59-15:59',
        ];

        foreach (['CreatePurchaseOrder', 'UpdatePurchaseOrder', 'ApprovePurchaseOrder'] as $operation) {
            foreach ($shapes as $name => $value) {
                yield $operation.' × '.$name => [$operation, $value];
            }
        }
    }

    #[Test]
    #[DataProvider('refusedShapes')]
    public function aZoneNameOrAYearPast9999IsRefusedAtTheFieldAndChangesNothing(string $operation, string $value): void
    {
        $order = 'CreatePurchaseOrder' === $operation ? null : $this->seedPlaceableDraft();

        $this->sendExpectedAt($operation, $order, $value);

        self::assertNotSame(500, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        self::assertSame(
            [['pointer' => '/expected_at', 'code' => 'not_a_timestamp']],
            array_map(static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors']),
        );

        if (null === $order) {
            self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));

            return;
        }

        self::assertSame(
            [['status' => PurchaseOrder::STATUS_DRAFT, 'expected_at' => '2026-06-01 00:00:00+00']],
            $this->fetchAsOwnerForTenant(self::TENANT_A,
                "SELECT status, to_char(expected_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' AS expected_at FROM purchase_order WHERE id = ?::uuid",
                [$order]),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        yield 'CreatePurchaseOrder' => ['CreatePurchaseOrder'];
        yield 'UpdatePurchaseOrder' => ['UpdatePurchaseOrder'];
        yield 'ApprovePurchaseOrder' => ['ApprovePurchaseOrder'];
    }

    /** Positive control: a numeric offset succeeds, and what is stored and served is the instant it names. */
    #[Test]
    #[DataProvider('operations')]
    public function aNumericOffsetIsStoredAsTheInstantItNames(string $operation): void
    {
        $order = 'CreatePurchaseOrder' === $operation ? null : $this->seedPlaceableDraft();

        $this->sendExpectedAt($operation, $order, '2026-10-01T00:00:00+02:00');

        self::assertSame('CreatePurchaseOrder' === $operation ? 201 : 200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $id = $order ?? $this->json()['id'];

        $this->send('GET', '/purchase-orders/'.$id);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('2026-09-30T22:00:00.000+00:00', $this->json()['expected_at']);
        self::assertSame(
            [['expected_at' => '2026-09-30 22:00:00']],
            $this->fetchAsOwnerForTenant(self::TENANT_A,
                "SELECT to_char(expected_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS expected_at FROM purchase_order WHERE id = ?::uuid",
                [$id]),
        );
    }

    /** The shared parser reaches every module: one case shows it (the rest go through the same `parse()`). */
    #[Test]
    public function aZoneNameIsRefusedOnAShipmentToo(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH TS-1');
        $order = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), null, $warehouse, SalesOrder::STATUS_CONFIRMED, '2026-09-20T10:00:00Z');
        $item = $this->seedItem(self::TENANT_A, 'SKU-TS-1');
        $line = $this->seedSalesOrderLine(self::TENANT_A, $order, $item, lineNumber: 1, quantity: '5');

        $this->send('POST', '/sales-orders/'.$order.'/fulfilments', [
            'shipped_at' => '2026-10-01T00:00:00UTC',
            'lines' => [['sales_order_line_id' => $line, 'quantity' => '1', 'storage_location_id' => Uuid::v4()->toRfc4122()]],
        ], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['/shipped_at', 'not_a_timestamp'], [$this->json()['errors'][0]['pointer'], $this->json()['errors'][0]['code']]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM fulfilment WHERE sales_order_id = ?::uuid', [$order]));
    }

    /** And a query filter: `decided_since` is parsed by the same class. */
    #[Test]
    public function aZoneNameIsRefusedInADecidedSinceFilter(): void
    {
        $this->send('GET', '/sales-returns?adjustment_decided=same&decided_since='.rawurlencode('2026-10-01T00:00:00UTC'));

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['?decided_since', 'not_a_timestamp'], [$this->json()['errors'][0]['pointer'], $this->json()['errors'][0]['code']]);
    }

    /** A draft with a live line and an `expected_at`, created by someone other than the caller, so it can be approved. */
    private function seedPlaceableDraft(): string
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme '.Uuid::v4());
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.Uuid::v4());
        $item = $this->seedItem(self::TENANT_A, 'SKU-'.substr(Uuid::v4()->toRfc4122(), 0, 8));
        $order = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse,
            $this->seedPrincipal(self::TENANT_A, []), expectedAt: self::BEFORE);
        $this->seedLine(self::TENANT_A, $order, $item);

        return $order;
    }

    private function sendExpectedAt(string $operation, ?string $order, string $value): void
    {
        match ($operation) {
            'CreatePurchaseOrder' => $this->send('POST', '/purchase-orders', [
                'supplier_id' => $this->seedSupplier(self::TENANT_A, 'Acme '.Uuid::v4()),
                'warehouse_id' => $this->seedWarehouse(self::TENANT_A, 'WH '.Uuid::v4()),
                'expected_at' => $value,
            ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]),
            'UpdatePurchaseOrder' => $this->send('PATCH', '/purchase-orders/'.$order, ['expected_at' => $value]),
            'ApprovePurchaseOrder' => $this->send('POST', '/purchase-orders/'.$order.'/approval', ['expected_at' => $value],
                ['Idempotency-Key' => Uuid::v4()->toRfc4122()]),
        };
    }
}
