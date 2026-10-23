<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Http\Bulk\BulkArchival;
use App\Platform\Http\Bulk\BulkArchivalPermission;
use App\Platform\Http\Idempotency\IdempotencyEnvelopeStore;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Security\PermissionRequirements;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateBulkArchival` over HTTP** (G5-C2, 2026-10-22; `g5-g6-bulk-export-design.md` §5.6 as amended by R1.2 and
 * `g5-g6-final-red.md`).
 *
 * **Parity is DIFFERENTIAL** (design §5.6): each `<resource>` test seeds the same fixtures twice — one per refusal the single
 * `Delete*` makes, plus a good one, an absent id and another workspace's row. The single `DELETE` runs on one copy, the bulk
 * on the other; the bulk must archive EXACTLY the members whose twin answered 204, write `<table>.deleted` exactly for
 * them, and count the rest as one undifferentiated `requested − applied` (E-14). The twin's statuses are asserted too, so a
 * fixture that stopped exercising its rule cannot pass silently. Every twin refusal must be an allow-listed kind (404,
 * 409 business-rule-violated — design L-3), else a bulk would 500 on it.
 *
 * What these cases cannot show: a member whose single writer were edited would move both sides together (parity holds by
 * construction: one method). That is the point, not a gap — the one deliberate divergence (the stocked item) is pinned
 * two-way by {@see self::aStockedItemIsSkippedWhileTheSingleDeleteStillArchivesIt}.
 */
final class BulkArchivalTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;
    use BulkRaceHolder;

    /** The `/bulk` path family (joined into `AuthOperationContractTest`'s shipped-surface union). */
    public const PINNED_BULK_OPERATIONS = [
        'CreateBulkArchival' => ['method' => 'POST', 'path' => '/bulk/archivals', 'idem' => true],
        'CreateBulkRestoration' => ['method' => 'POST', 'path' => '/bulk/restorations', 'idem' => true],
    ];

    // -----------------------------------------------------------------------
    // Parity, one resource at a time.
    // -----------------------------------------------------------------------

    #[Test]
    public function inventoryItemParity(): void
    {
        $this->assertParity('inventory_item', 'inventory_item.deleted', function (string $t): array {
            $archived = $this->seedItem(self::TENANT_A, 'BLK-ARC-'.$t);
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

            return [
                'good' => [$this->seedItem(self::TENANT_A, 'BLK-OK-'.$t), '/items/%s', 204],
                'archived' => [$archived, '/items/%s', 409],
                'foreign' => [$this->seedItem(self::TENANT_B, 'BLK-FOR-'.$t), '/items/%s', 404],
                'absent' => [Uuid::v4()->toRfc4122(), '/items/%s', 404],
            ];
        });
    }

    #[Test]
    public function warehouseParity(): void
    {
        $this->assertParity('warehouse', 'warehouse.deleted', function (string $t): array {
            $archived = $this->seedWarehouse(self::TENANT_A, 'WH ARC '.$t);
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
            $withPo = $this->seedWarehouse(self::TENANT_A, 'WH PO '.$t);
            $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $this->seedSupplier(self::TENANT_A, 'Sup WH '.$t), $withPo, status: PurchaseOrder::STATUS_ORDERED, orderedAt: '2026-09-20T10:00:00Z');
            $withSo = $this->seedWarehouse(self::TENANT_A, 'WH SO '.$t);
            $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), null, $withSo, SalesOrder::STATUS_CONFIRMED, '2026-09-20T10:00:00Z');
            $stocked = $this->seedWarehouse(self::TENANT_A, 'WH STK '.$t);
            $this->stock($this->seedItem(self::TENANT_A, 'BLK-WHS-'.$t), $this->seedStorageLocation(self::TENANT_A, $stocked, 'S-01'), '3');

            return [
                'good' => [$this->seedWarehouse(self::TENANT_A, 'WH OK '.$t), '/warehouses/%s', 204],
                'archived' => [$archived, '/warehouses/%s', 409],
                'open purchase order' => [$withPo, '/warehouses/%s', 409],
                'open sales order' => [$withSo, '/warehouses/%s', 409],
                'holds stock' => [$stocked, '/warehouses/%s', 409],
                'foreign' => [$this->seedWarehouse(self::TENANT_B, 'WH FOR '.$t), '/warehouses/%s', 404],
                'absent' => [Uuid::v4()->toRfc4122(), '/warehouses/%s', 404],
            ];
        });
    }

    #[Test]
    public function storageLocationParity(): void
    {
        $this->assertParity('storage_location', 'storage_location.deleted', function (string $t): array {
            $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH LOC '.$t);
            $path = '/warehouses/'.$warehouse.'/locations/%s';
            $archived = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'ARC');
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
            $quarantine = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'QUA', 'quarantine');
            $this->stock($this->seedItem(self::TENANT_A, 'BLK-LOC-'.$t), $quarantine, '2');
            $archivedParent = $this->seedWarehouse(self::TENANT_A, 'WH LOC ARC '.$t);
            $orphan = $this->seedStorageLocation(self::TENANT_A, $archivedParent, 'ORP');
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$archivedParent]);
            $foreignWarehouse = $this->seedWarehouse(self::TENANT_B, 'WH LOC FOR '.$t);

            return [
                'good' => [$this->seedStorageLocation(self::TENANT_A, $warehouse, 'OK'), $path, 204],
                'archived' => [$archived, $path, 409],
                'stocked quarantine (G-44 bypass)' => [$quarantine, $path, 409],
                'in an archived warehouse' => [$orphan, '/warehouses/'.$archivedParent.'/locations/%s', 409],
                'foreign' => [$this->seedStorageLocation(self::TENANT_B, $foreignWarehouse, 'FOR'), '/warehouses/'.$foreignWarehouse.'/locations/%s', 404],
                'absent' => [Uuid::v4()->toRfc4122(), $path, 404],
            ];
        });
    }

    #[Test]
    public function supplierParity(): void
    {
        $this->assertParity('supplier', 'supplier.deleted', function (string $t): array {
            $archived = $this->seedSupplier(self::TENANT_A, 'Sup ARC '.$t);
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
            $open = $this->seedSupplier(self::TENANT_A, 'Sup OPEN '.$t);
            $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $open, $this->seedWarehouse(self::TENANT_A, 'WH SUP '.$t), status: PurchaseOrder::STATUS_ORDERED, orderedAt: '2026-09-20T10:00:00Z');

            return [
                'good' => [$this->seedSupplier(self::TENANT_A, 'Sup OK '.$t), '/suppliers/%s', 204],
                'archived' => [$archived, '/suppliers/%s', 409],
                'open purchase order' => [$open, '/suppliers/%s', 409],
                'foreign' => [$this->seedSupplier(self::TENANT_B, 'Sup FOR '.$t), '/suppliers/%s', 404],
                'absent' => [Uuid::v4()->toRfc4122(), '/suppliers/%s', 404],
            ];
        });
    }

    #[Test]
    public function customerParity(): void
    {
        $this->assertParity('customer', 'customer.deleted', function (string $t): array {
            $unpaid = $this->issuedInvoice('BLK-UNPAID-'.$t)['shipment']['customer'];
            $this->actAs(self::TENANT_A);
            $archived = $this->seedCustomer(self::TENANT_A, 'Cus ARC '.$t);
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
            $open = $this->seedCustomer(self::TENANT_A, 'Cus OPEN '.$t);
            $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $open);

            return [
                'good' => [$this->seedCustomer(self::TENANT_A, 'Cus OK '.$t), '/customers/%s', 204],
                'archived' => [$archived, '/customers/%s', 409],
                'open sales order' => [$open, '/customers/%s', 409],
                'unpaid invoice' => [$unpaid, '/customers/%s', 409],
                'foreign' => [$this->seedCustomer(self::TENANT_B, 'Cus FOR '.$t), '/customers/%s', 404],
                'absent' => [Uuid::v4()->toRfc4122(), '/customers/%s', 404],
            ];
        });
    }

    #[Test]
    public function salesOrderParity(): void
    {
        $this->assertParity('sales_order', 'sales_order.deleted', function (string $t): array {
            $archived = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'));
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

            return [
                'good (draft)' => [$this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO')), '/sales-orders/%s', 204],
                'archived' => [$archived, '/sales-orders/%s', 409],
                'confirmed' => [$this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), null, null, SalesOrder::STATUS_CONFIRMED, '2026-09-20T10:00:00Z'), '/sales-orders/%s', 409],
                'foreign' => [$this->seedSalesOrder(self::TENANT_B, self::freshNumber('SO')), '/sales-orders/%s', 404],
                'absent' => [Uuid::v4()->toRfc4122(), '/sales-orders/%s', 404],
            ];
        });
    }

    #[Test]
    public function purchaseOrderParity(): void
    {
        $this->assertParity('purchase_order', 'purchase_order.deleted', function (string $t): array {
            $archived = $this->seedDraft(self::TENANT_A, self::freshNumber('PO'))['order'];
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE purchase_order SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
            $ordered = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $this->seedSupplier(self::TENANT_A, 'Sup PO '.$t), $this->seedWarehouse(self::TENANT_A, 'WH PO '.$t), status: PurchaseOrder::STATUS_ORDERED, orderedAt: '2026-09-20T10:00:00Z');

            return [
                'good (draft)' => [$this->seedDraft(self::TENANT_A, self::freshNumber('PO'))['order'], '/purchase-orders/%s', 204],
                'archived' => [$archived, '/purchase-orders/%s', 409],
                'ordered' => [$ordered, '/purchase-orders/%s', 409],
                'foreign' => [$this->seedDraft(self::TENANT_B, self::freshNumber('PO'))['order'], '/purchase-orders/%s', 404],
                'absent' => [Uuid::v4()->toRfc4122(), '/purchase-orders/%s', 404],
            ];
        });
    }

    // -----------------------------------------------------------------------
    // The divergence, and the rules parity inherits.
    // -----------------------------------------------------------------------

    /**
     * **G-51, pinned two-way** (design §5.2, D-13): the single `DeleteItem` archives a stocked item (shipped, accepted); the
     * bulk does not. The day `DeleteItem` refuses one, the single half reddens and `ItemWriter::archiveUnlessStocked()`
     * folds away with this test. *Mutation:* drop the bulk-only check → the bulk half reddens.
     */
    #[Test]
    public function aStockedItemIsSkippedWhileTheSingleDeleteStillArchivesIt(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH G51');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'G51');
        $single = $this->seedItem(self::TENANT_A, 'BLK-G51-S');
        $bulk = $this->seedItem(self::TENANT_A, 'BLK-G51-B');
        $this->stock($single, $bin, '4');
        $this->stock($bulk, $bin, '4');

        $this->send('DELETE', '/items/'.$single);
        self::assertSame(204, $this->httpStatus(), 'G-51: the single delete still archives a stocked item. If this is now refused, delete the bulk-only check.');

        $this->bulk('inventory_item', [$bulk]);
        self::assertSame(['requested' => 1, 'applied' => 0], $this->json());
        self::assertFalse($this->archived('inventory_item', $bulk), 'A stocked item was archived in bulk (G-51 at scale).');
        self::assertSame([], array_values(array_filter($this->auditRowsOf('inventory_item.deleted'), static fn (array $r): bool => $r['subject_id'] === $bulk)));
    }

    /** G-44's bypass, named for the tripwire: stocked quarantine is refused per member, exactly as the single delete refuses it. */
    #[Test]
    public function aStockedQuarantineLocationIsNotArchivedInBulk(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Q');
        $quarantine = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'Q-1', 'quarantine');
        $this->stock($this->seedItem(self::TENANT_A, 'BLK-Q'), $quarantine, '1');
        $empty = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'Q-2', 'quarantine');

        $this->bulk('storage_location', [$quarantine, $empty]);

        self::assertSame(['requested' => 2, 'applied' => 1], $this->json());
        self::assertFalse($this->archived('storage_location', $quarantine));
        self::assertTrue($this->archived('storage_location', $empty), 'An empty quarantine bin is not refused (no held stock to hide).');
    }

    /** Owner Q-2 (a): the single delete leaves a warehouse's live locations live, so the bulk does too. */
    #[Test]
    public function aWarehouseArchiveLeavesItsLiveLocationsLive(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH LIVE');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-1');

        $this->bulk('warehouse', [$warehouse]);

        self::assertSame(['requested' => 1, 'applied' => 1], $this->json());
        self::assertTrue($this->archived('warehouse', $warehouse));
        self::assertFalse($this->archived('storage_location', $location), 'Bulk archived a location its warehouse\'s single delete leaves live.');
    }

    /**
     * A refused member leaves nothing: no `deleted_at`, no `updated_at` move, no audit row — its savepoint rolled back —
     * while the applied members around it stand. *Mutation:* remove the savepoint → a refused member's earlier statements
     * would stand, or the whole transaction aborts on the next statement.
     */
    #[Test]
    public function aRefusedMemberLeavesNoWriteAndNoAuditRow(): void
    {
        $first = $this->seedCustomer(self::TENANT_A, 'Cus R1');
        $refused = $this->seedCustomer(self::TENANT_A, 'Cus R2');
        $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $refused);
        $last = $this->seedCustomer(self::TENANT_A, 'Cus R3');
        $before = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS u FROM customer WHERE id = ?::uuid', [$refused])[0]['u'];

        $this->bulk('customer', [$first, $refused, $last]);

        self::assertSame(['requested' => 3, 'applied' => 2], $this->json());
        self::assertTrue($this->archived('customer', $first));
        self::assertTrue($this->archived('customer', $last));
        self::assertFalse($this->archived('customer', $refused));
        self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS u FROM customer WHERE id = ?::uuid', [$refused])[0]['u']);

        $subjects = array_column($this->auditRowsOf('customer.deleted'), 'subject_id');
        sort($subjects);
        $expected = [$first, $last];
        sort($expected);
        self::assertSame($expected, $subjects);
        foreach ($this->auditRowsOf('customer.deleted') as $row) {
            self::assertSame($this->businessRow('customer.deleted', 'customer', (string) $row['subject_id'])['actor_user_id'], $row['actor_user_id'], 'The row names the bulk caller (parity, design §5.2 (d)).');
        }
    }

    /**
     * E-14 (`API.md` §4.2 `BulkOperationTest::unresolvedIdsAreReportedAsOneUndifferentiatedGroup`): the body is exactly
     * `{requested, applied}`; a foreign, an absent, an already-archived and an open-work member are indistinguishable — two
     * requests with different refusals but the same counts answer the same bytes. *Mutation:* split the counts → red.
     */
    #[Test]
    public function unresolvedIdsAreReportedAsOneUndifferentiatedGroup(): void
    {
        $archived = $this->seedSupplier(self::TENANT_A, 'Sup U1');
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        $open = $this->seedSupplier(self::TENANT_A, 'Sup U2');
        $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $open, $this->seedWarehouse(self::TENANT_A, 'WH U'), status: PurchaseOrder::STATUS_ORDERED, orderedAt: '2026-09-20T10:00:00Z');

        $this->bulk('supplier', [$this->seedSupplier(self::TENANT_A, 'Sup U3'), $archived, $open]);
        $first = $this->responseContent();
        $this->bulk('supplier', [$this->seedSupplier(self::TENANT_A, 'Sup U4'), $this->seedSupplier(self::TENANT_B, 'Sup U5'), Uuid::v4()->toRfc4122()]);
        $second = $this->responseContent();

        self::assertSame('{"requested":3,"applied":1}', $first);
        self::assertSame($first, $second, 'Different refusals with the same counts must answer the same bytes (E-14).');
        self::assertSame(['requested', 'applied'], array_keys($this->json()));
    }

    // -----------------------------------------------------------------------
    // The permission: one decision, before any id is read.
    // -----------------------------------------------------------------------

    /**
     * E-1 / E-14 (`API.md` §4.2 `BulkOperationTest::thePermissionIsCheckedOnceBeforeAnyIdIsRead`): a caller without
     * `customer.delete` naming 50 customers is refused 403 naming that code, with one E-3 row `(null, null)`, and no statement
     * reads `customer` during the request. *Mutation:* check the permission per id → more than one row, or a customer read.
     */
    #[Test]
    public function thePermissionIsCheckedOnceBeforeAnyIdIsRead(): void
    {
        $ids = [];
        for ($i = 0; $i < BulkArchival::MAXIMUM_MEMBERS; ++$i) {
            $ids[] = $this->seedCustomer(self::TENANT_A, 'Cus P'.$i);
        }
        $this->actAs(self::TENANT_A, ['customer.view']);
        $denialsBefore = \count($this->auditRowsOf('authz.permission.denied'));

        $this->recorder()->start();
        try {
            $this->bulk('customer', $ids);
        } finally {
            $this->recorder()->stop();
        }

        self::assertSame(403, $this->httpStatus(), $this->responseContent());
        self::assertSame('customer.delete', $this->json()['required_permission'] ?? null);
        $rows = $this->auditRowsOf('authz.permission.denied');
        self::assertCount($denialsBefore + 1, $rows, 'One decision, one E-3 row.');
        $row = $rows[\count($rows) - 1];
        self::assertSame([null, null, 'customer.delete'], [$row['subject_type'], $row['subject_id'], $row['permission_code']]);
        self::assertSame([], $this->recorder()->matching('/\bcustomer\b(?!_)/i'), 'A customer row was read before the decision.');
        foreach ($ids as $id) {
            self::assertFalse($this->archived('customer', $id));
        }
    }

    /** `role` and `app_user` are out by ruling (RO-B2): 422 `unknown_resource`, before the permission, with L-10's sentence. */
    #[Test]
    public function anExcludedOrUnknownResourceIs422BeforeThePermission(): void
    {
        foreach (['role', 'app_user', 'supplier_product', 'sales_order_line'] as $resource) {
            $this->bulk($resource, [Uuid::v4()->toRfc4122()]);

            self::assertSame(422, $this->httpStatus(), $resource.': '.$this->responseContent());
            self::assertSame([['pointer' => '/resource', 'code' => 'unknown_resource', 'message' => BulkArchivalPermission::UNKNOWN_RESOURCE]],
                array_map(static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code'], 'message' => $e['message']], $this->json()['errors']), $resource);
        }
    }

    /** M-3 precedence, by a caller who holds NOTHING (RED L-5): an unknown resource is 422 before any 403, a known one 403. */
    #[Test]
    public function anUnknownResourceIs422EvenForACallerWhoHoldsNothing(): void
    {
        $this->actAs(self::TENANT_A, []);

        $this->bulk('role', [Uuid::v4()->toRfc4122()]);
        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        self::assertSame('unknown_resource', $this->json()['errors'][0]['code']);

        $this->bulk('customer', [Uuid::v4()->toRfc4122()]);
        self::assertSame(403, $this->httpStatus(), $this->responseContent());
        self::assertSame('customer.delete', $this->json()['required_permission']);
    }

    /**
     * A full bulk at the cap (RED L-1): 50 suppliers, every one applied, one row each. It is the case that runs 50 member
     * savepoints and releases; a missing `RELEASE` after a `ROLLBACK TO` is exercised by
     * {@see self::aRefusedMemberLeavesNoWriteAndNoAuditRow}'s refused middle member. The lock-hold measurement is recorded in
     * `g5-c2-progress.md` (wall time of this request), not asserted (no timing assertion is a stable test).
     */
    #[Test]
    public function aFullBulkAtTheCapArchivesEveryMember(): void
    {
        $ids = [];
        for ($i = 0; $i < BulkArchival::MAXIMUM_MEMBERS; ++$i) {
            $ids[] = $this->seedSupplier(self::TENANT_A, 'Sup CAP '.$i);
        }

        $started = hrtime(true);
        $this->bulk('supplier', $ids);
        $elapsedMs = (hrtime(true) - $started) / 1e6;

        self::assertSame(['requested' => 50, 'applied' => 50], $this->json(), $this->responseContent());
        self::assertCount(50, $this->auditRowsOf('supplier.deleted'));
        fwrite(\STDERR, \sprintf("\n[G5-C2 measurement] 50-member bulk archive of suppliers: %.0f ms\n", $elapsedMs));
    }

    /** G5-D0 carry (1): a body resolved for one resource and acted on as another is a 500, never an archival. */
    #[Test]
    public function aBodyReadTwoWaysIsAServerErrorNotAnArchival(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Cus TWO');
        $dispatcher = self::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $plant = static function (ControllerEvent $event): void {
            $event->getRequest()->attributes->set(PermissionRequirements::REQUEST_ATTRIBUTE, ['resource' => 'supplier', 'code' => 'supplier.delete']);
        };
        $dispatcher->addListener(KernelEvents::CONTROLLER, $plant, -1000);

        try {
            $this->bulk('customer', [$customer]);
        } finally {
            $dispatcher->removeListener(KernelEvents::CONTROLLER, $plant);
        }

        self::assertSame(500, $this->httpStatus());
        self::assertFalse($this->archived('customer', $customer));
    }

    // -----------------------------------------------------------------------
    // The body.
    // -----------------------------------------------------------------------

    #[Test]
    public function duplicateIdsTooManyIdsAndBadUuidsAre422(): void
    {
        $id = Uuid::v4()->toRfc4122();
        $cases = [
            'absent' => [null, [['/ids', 'at_least_one_id']]],
            'empty' => [[], [['/ids', 'at_least_one_id']]],
            'object' => [['a' => $id], [['/ids', 'not_a_list']]],
            'too many' => [array_map(static fn (): string => Uuid::v4()->toRfc4122(), range(0, BulkArchival::MAXIMUM_MEMBERS)), [['/ids', 'too_many_ids']]],
            'not a uuid' => [[$id, 'nope', 7], [['/ids/1', 'not_a_uuid'], ['/ids/2', 'not_a_uuid']]],
            'duplicate (case-insensitive)' => [[$id, strtoupper($id)], [['/ids/1', 'duplicate_id']]],
        ];

        foreach ($cases as $label => [$ids, $expected]) {
            $body = ['resource' => 'customer'];
            if (null !== $ids) {
                $body['ids'] = $ids;
            }
            $this->send('POST', '/bulk/archivals', $body, [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);

            self::assertSame(422, $this->httpStatus(), $label.': '.$this->responseContent());
            self::assertSame($expected, array_map(static fn (array $e): array => [$e['pointer'], $e['code']], $this->json()['errors']), $label);
        }
    }

    /** §6.2 / P-5: the header is required (428), and it is checked after the permission (E-1: a refused caller learns nothing). */
    #[Test]
    public function theRetryKeyIsRequiredAfterThePermission(): void
    {
        $this->send('POST', '/bulk/archivals', ['resource' => 'customer', 'ids' => [Uuid::v4()->toRfc4122()]]);
        self::assertSame(428, $this->httpStatus(), $this->responseContent());

        $this->actAs(self::TENANT_A, ['customer.view']);
        $this->send('POST', '/bulk/archivals', ['resource' => 'customer', 'ids' => [Uuid::v4()->toRfc4122()]]);
        self::assertSame(403, $this->httpStatus(), $this->responseContent());
    }

    /**
     * Final RED M-2: every applied member is a subtransaction holding its subxid until commit, and PostgreSQL caches 64 per
     * backend; the cap plus `bulk_work` must stay below it, or one bulk slows every snapshot in the cluster.
     */
    #[Test]
    public function theCapKeepsEverySubtransactionInTheSubxidCache(): void
    {
        self::assertSame(50, BulkArchival::MAXIMUM_MEMBERS, 'Final RED M-2 set 50 (DATA to confirm); a change is a decision, not a tidy-up.');
        self::assertLessThan(BulkArchival::POSTGRES_CACHED_SUBXIDS, BulkArchival::MAXIMUM_MEMBERS + 1);
    }

    // -----------------------------------------------------------------------
    // The replay store (the first store operation).
    // -----------------------------------------------------------------------

    /**
     * §6.2: the same key and the same request answer the stored envelope byte for byte — status, body, content type. Between
     * the two calls one member is RESTORED (as the owner), so a recomputation would answer `applied: 1` and archive it again.
     * *Mutation:* recompute on replay → red. The control: a fresh key over the same ids does recompute.
     */
    #[Test]
    public function aReplayIsByteIdenticalOverHttp(): void
    {
        $a = $this->seedCustomer(self::TENANT_A, 'Cus RP1');
        $b = $this->seedCustomer(self::TENANT_A, 'Cus RP2');
        $key = Uuid::v4()->toRfc4122();

        $this->bulk('customer', [$a, $b], $key);
        $first = [$this->httpStatus(), $this->responseContent(), $this->contentType()];
        self::assertSame([200, '{"requested":2,"applied":2}', BulkArchival::CONTENT_TYPE], $first);

        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = NULL WHERE id = ?::uuid', [$a]);

        $this->recorder()->start();
        try {
            $this->bulk('customer', [$b, $a], $key);
        } finally {
            $this->recorder()->stop();
        }
        self::assertSame($first, [$this->httpStatus(), $this->responseContent(), $this->contentType()], 'A replay (ids reordered) is the stored answer.');
        // The answer alone cannot see a recomputation: the C-1 protocol rolls a lost duplicate's work back and answers the
        // stored envelope anyway (mutation M8 was GREEN on the answer). What it CAN see: a replay reads no member at all.
        self::assertSame([], $this->recorder()->matching('/\bcustomer\b(?!_)/i'), 'The replay ran member work before answering the stored envelope.');
        self::assertFalse($this->archived('customer', $a), 'The replay re-ran the work.');
        self::assertCount(2, $this->auditRowsOf('customer.deleted'));

        $this->bulk('customer', [$a, $b]);
        self::assertSame('{"requested":2,"applied":1}', $this->responseContent(), 'Control: a new key does the work.');
    }

    /** §6.2: the same key with another request is 409; with another actor (L-1, the actor in the digest) also 409. */
    #[Test]
    public function aKeyReusedForAnotherRequestOrByAnotherPersonIsAConflict(): void
    {
        $a = $this->seedCustomer(self::TENANT_A, 'Cus K1');
        $b = $this->seedCustomer(self::TENANT_A, 'Cus K2');
        $key = Uuid::v4()->toRfc4122();

        $this->bulk('customer', [$a], $key);
        self::assertSame(200, $this->httpStatus());

        $this->bulk('customer', [$b], $key);
        self::assertSame(409, $this->httpStatus(), $this->responseContent());
        self::assertFalse($this->archived('customer', $b));

        $this->actAs(self::TENANT_A);
        $this->bulk('customer', [$a], $key);
        self::assertSame(409, $this->httpStatus(), 'Another person\'s reuse of a key is a conflict, never their colleague\'s counts (L-1). '.$this->responseContent());
    }

    /** RO-B1 (a′): an expired key is refused at read (422 `key_expired`) and its row is kept as it was. */
    #[Test]
    public function anExpiredKeyIsRefusedAndKept(): void
    {
        $a = $this->seedCustomer(self::TENANT_A, 'Cus EXP');
        $key = Uuid::v4()->toRfc4122();
        $this->bulk('customer', [$a], $key);
        $this->executeAsOwnerForTenant(self::TENANT_A, "UPDATE idempotency_key SET created_at = now() - interval '25 hours' WHERE key = ?", [IdempotencyKeyHash::of($key)]);

        $this->bulk('customer', [$a], $key);

        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        self::assertSame(IdempotencyEnvelopeStore::EXPIRED_CODE, $this->json()['errors'][0]['code']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM idempotency_key WHERE key = ?', [IdempotencyKeyHash::of($key)]));
    }

    /**
     * **Design C-1, the race:** a same-key, same-request transaction (connection A) holds its uncommitted envelope; this
     * request does its member work, then its `record()` WAITS at the unique, and inserts nothing once A commits. It must
     * roll its work back to `bulk_work` and answer A's envelope, byte for byte: the customer stays live, no audit row. A
     * second `run()` from a catch (the `309c674` shape) would be a 500. A's body is deliberately one this request could
     * never compute (`applied: 0`), so the answer is provably the winner's.
     */
    #[Test]
    public function aConcurrentDuplicateReplaysTheWinnerByteForByteAndLeavesNoLoserWrites(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Cus RACE');
        $key = Uuid::v4()->toRfc4122();
        $digest = IdempotencyEnvelopeStore::requestDigest(BulkArchival::OPERATION, (string) $this->actorId, ['resource' => 'customer', 'ids' => [$customer]]);
        $winner = '{"requested":1,"applied":0}';

        $holder = $this->raceAgainstAHolder([[
            "INSERT INTO idempotency_key (id, tenant_id, operation_id, key, request_digest, response_status, response_digest, response_body, response_headers)
             VALUES (gen_random_uuid(), ?::uuid, ?, ?, decode(?, 'hex'), 200, sha256(convert_to(?, 'UTF8')), convert_to(?, 'UTF8'), ?::jsonb)",
            [self::TENANT_A, BulkArchival::OPERATION, IdempotencyKeyHash::of($key), $digest, $winner, $winner, '{"content-type":"'.BulkArchival::CONTENT_TYPE.'"}'],
        ]], function () use ($customer, $key): void {
            $this->bulk('customer', [$customer], $key);
        });

        self::assertTrue($holder['waiter_seen'], 'The request never waited on the winner\'s envelope: '.json_encode($holder));
        self::assertTrue($holder['committed'], json_encode($holder));
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame($winner, $this->responseContent(), 'The loser must answer the winner\'s envelope.');
        self::assertFalse($this->archived('customer', $customer), 'The loser\'s member work stood.');
        self::assertSame([], $this->auditRowsOf('customer.deleted'), 'The loser\'s audit row stood.');
    }

    // -----------------------------------------------------------------------

    /**
     * @param callable(string): array<string, array{0: string, 1: string, 2: int}> $seed label => [id, single-delete path
     *                                                                                     pattern, the single delete's status]
     */
    private function assertParity(string $resource, string $event, callable $seed): void
    {
        $bulkSet = $seed('A');
        $singleSet = $seed('B');
        $this->actAs(self::TENANT_A);
        self::assertSame(array_keys($bulkSet), array_keys($singleSet));
        self::assertArrayHasKey('foreign', $bulkSet, 'Every resource is checked against another workspace\'s row.');

        $expectedApplied = [];
        foreach ($singleSet as $label => [$id, $path, $expected]) {
            $this->send('DELETE', \sprintf($path, $id));
            $status = $this->httpStatus();
            self::assertSame($expected, $status, $resource.' / '.$label.': the single delete\'s fixture no longer exercises its rule. '.$this->responseContent());
            if (204 !== $status) {
                self::assertTrue(\in_array($this->json()['type'] ?? null, ['urn:erp:problem:not-found', 'urn:erp:problem:business-rule-violated'], true),
                    $resource.' / '.$label.': the single delete refuses with a kind the bulk does not count as "not applied" (L-3).');
            }
            $expectedApplied[$label] = 204 === $status;
        }

        $this->bulk($resource, array_column($bulkSet, 0));

        self::assertSame(200, $this->httpStatus(), $resource.': '.$this->responseContent());
        self::assertSame(['requested' => \count($bulkSet), 'applied' => \count(array_filter($expectedApplied))], $this->json(), $resource);

        $applied = [];
        foreach ($bulkSet as $label => [$id]) {
            if ('absent' === $label) {
                continue;
            }
            $tenant = 'foreign' === $label ? self::TENANT_B : self::TENANT_A;
            $wasArchived = 'archived' === $label;
            self::assertSame($expectedApplied[$label] || $wasArchived, $this->archived($resource, $id, $tenant), $resource.' / '.$label);
            if ($expectedApplied[$label]) {
                $applied[] = $id;
            }
        }

        $bulkIds = array_column($bulkSet, 0);
        $rows = array_values(array_filter($this->auditRowsOf($event), static fn (array $r): bool => \in_array($r['subject_id'], $bulkIds, true)));
        self::assertSame($applied, array_column($rows, 'subject_id'), $resource.': one '.$event.' row per applied member, none for the rest.');
    }

    /** @param list<string> $ids */
    private function bulk(string $resource, array $ids, ?string $key = null): void
    {
        $this->send('POST', '/bulk/archivals', ['resource' => $resource, 'ids' => $ids], [IdempotencyKeyGuard::HEADER => $key ?? Uuid::v4()->toRfc4122()]);
    }

    private function archived(string $table, string $id, string $tenant = self::TENANT_A): bool
    {
        \assert(1 === preg_match('/^[a-z_]+$/', $table));
        $rows = $this->fetchAsOwnerForTenant($tenant, \sprintf('SELECT deleted_at IS NOT NULL AS archived FROM %s WHERE id = ?::uuid', $table), [$id]);
        self::assertCount(1, $rows, $table.' '.$id.' is not seeded.');

        return true === $rows[0]['archived'];
    }

    private function stock(string $item, string $bin, string $qty): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin, $qty]);
    }
}
