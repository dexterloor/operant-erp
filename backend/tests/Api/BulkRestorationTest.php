<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Bulk\BulkArchival;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionRequirements;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateBulkRestoration` over HTTP** (G5-C3, 2026-10-23; `docs/session13/g5-c3-design.md`; `g5-g6-bulk-export-design.md`
 * §5.7). The archival's twin through ONE coordinator, so what is the engine's (savepoints, the unit-of-work guard,
 * contention, the concurrent duplicate) is proved once in `BulkArchivalCoordinatorTest` / `BulkArchivalTest`; this class proves
 * what restoration adds: per-resource parity with each single `Restore*`, its collision translations as "not applied" (never a
 * 500), and the operation-scoped envelope.
 *
 * **Parity is DIFFERENTIAL** (the archival's method): each resource seeds the same fixtures twice — one per refusal the single
 * `Restore*` makes (live, a parent archived, a code/SKU/name now held by a live row), a good one, an absent id and another
 * workspace's row. The single `POST …/restoration` runs on one copy, the bulk on the other; the bulk must restore EXACTLY the
 * members whose twin answered 200, write `<table>.restored` exactly for them, and count the rest as one `requested − applied`
 * (E-14). Every twin refusal must be an allow-listed kind (404, 409 business-rule-violated — L-3), else a bulk would 500.
 * *Mutation:* drop a single restore's collision translation → its twin answers 500 and the bulk 500s → red.
 */
final class BulkRestorationTest extends PurchaseOrderTestCase
{
    // -----------------------------------------------------------------------
    // Parity, one resource at a time.
    // -----------------------------------------------------------------------

    #[Test]
    public function inventoryItemParity(): void
    {
        $this->assertParity('inventory_item', 'inventory_item.restored', function (string $t): array {
            $taken = $this->archivedRow('inventory_item', $this->seedItem(self::TENANT_A, 'BLK-RS-DUP-'.$t));
            $this->seedItem(self::TENANT_A, 'BLK-RS-DUP-'.$t);

            return [
                'good' => [$this->archivedRow('inventory_item', $this->seedItem(self::TENANT_A, 'BLK-RS-OK-'.$t)), '/items/%s', 200],
                'live' => [$this->seedItem(self::TENANT_A, 'BLK-RS-LIVE-'.$t), '/items/%s', 409],
                'sku now held by a live item' => [$taken, '/items/%s', 409],
                'foreign' => [$this->archivedRow('inventory_item', $this->seedItem(self::TENANT_B, 'BLK-RS-FOR-'.$t), self::TENANT_B), '/items/%s', 404],
                'absent' => [Uuid::v4()->toRfc4122(), '/items/%s', 404],
            ];
        });
    }

    #[Test]
    public function warehouseParity(): void
    {
        $this->assertParity('warehouse', 'warehouse.restored', function (string $t): array {
            $taken = $this->archivedRow('warehouse', $this->seedWarehouse(self::TENANT_A, 'WH RS DUP '.$t));
            $holder = $this->seedWarehouse(self::TENANT_A, 'WH RS HOLD '.$t);
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET code = (SELECT code FROM warehouse WHERE id = ?::uuid) WHERE id = ?::uuid', [$taken, $holder]);

            return [
                'good' => [$this->archivedRow('warehouse', $this->seedWarehouse(self::TENANT_A, 'WH RS OK '.$t)), '/warehouses/%s', 200],
                'live' => [$this->seedWarehouse(self::TENANT_A, 'WH RS LIVE '.$t), '/warehouses/%s', 409],
                'code now held by a live warehouse' => [$taken, '/warehouses/%s', 409],
                'foreign' => [$this->archivedRow('warehouse', $this->seedWarehouse(self::TENANT_B, 'WH RS FOR '.$t), self::TENANT_B), '/warehouses/%s', 404],
                'absent' => [Uuid::v4()->toRfc4122(), '/warehouses/%s', 404],
            ];
        });
    }

    #[Test]
    public function storageLocationParity(): void
    {
        $this->assertParity('storage_location', 'storage_location.restored', function (string $t): array {
            $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH RS LOC '.$t);
            $path = '/warehouses/'.$warehouse.'/locations/%s/restoration';
            $taken = $this->archivedRow('storage_location', $this->seedStorageLocation(self::TENANT_A, $warehouse, 'DUP'));
            $this->seedStorageLocation(self::TENANT_A, $warehouse, 'DUP');
            $archivedParent = $this->seedWarehouse(self::TENANT_A, 'WH RS LOC ARC '.$t);
            $orphan = $this->archivedRow('storage_location', $this->seedStorageLocation(self::TENANT_A, $archivedParent, 'ORP'));
            $this->archivedRow('warehouse', $archivedParent);
            $foreignWarehouse = $this->seedWarehouse(self::TENANT_B, 'WH RS LOC FOR '.$t);

            return [
                'good' => [$this->archivedRow('storage_location', $this->seedStorageLocation(self::TENANT_A, $warehouse, 'OK')), $path, 200],
                'live' => [$this->seedStorageLocation(self::TENANT_A, $warehouse, 'LIVE'), $path, 409],
                'code now held by a live location' => [$taken, $path, 409],
                'in an archived warehouse' => [$orphan, '/warehouses/'.$archivedParent.'/locations/%s/restoration', 409],
                'foreign' => [$this->archivedRow('storage_location', $this->seedStorageLocation(self::TENANT_B, $foreignWarehouse, 'FOR'), self::TENANT_B), '/warehouses/'.$foreignWarehouse.'/locations/%s/restoration', 404],
                'absent' => [Uuid::v4()->toRfc4122(), $path, 404],
            ];
        });
    }

    #[Test]
    public function supplierParity(): void
    {
        $this->assertParity('supplier', 'supplier.restored', function (string $t): array {
            $taken = $this->archivedRow('supplier', $this->seedSupplier(self::TENANT_A, 'Sup RS DUP '.$t));
            $this->seedSupplier(self::TENANT_A, 'Sup RS DUP '.$t);

            return [
                'good' => [$this->archivedRow('supplier', $this->seedSupplier(self::TENANT_A, 'Sup RS OK '.$t)), '/suppliers/%s', 200],
                'live' => [$this->seedSupplier(self::TENANT_A, 'Sup RS LIVE '.$t), '/suppliers/%s', 409],
                'code and name now held by a live supplier' => [$taken, '/suppliers/%s', 409],
                'foreign' => [$this->archivedRow('supplier', $this->seedSupplier(self::TENANT_B, 'Sup RS FOR '.$t), self::TENANT_B), '/suppliers/%s', 404],
                'absent' => [Uuid::v4()->toRfc4122(), '/suppliers/%s', 404],
            ];
        });
    }

    #[Test]
    public function customerParity(): void
    {
        $this->assertParity('customer', 'customer.restored', function (string $t): array {
            $taken = $this->archivedRow('customer', $this->seedCustomer(self::TENANT_A, 'Cus RS DUP '.$t));
            $holder = $this->seedCustomer(self::TENANT_A, 'Cus RS HOLD '.$t);
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET code = (SELECT code FROM customer WHERE id = ?::uuid) WHERE id = ?::uuid', [$taken, $holder]);

            return [
                'good' => [$this->archivedRow('customer', $this->seedCustomer(self::TENANT_A, 'Cus RS OK '.$t)), '/customers/%s', 200],
                'live' => [$this->seedCustomer(self::TENANT_A, 'Cus RS LIVE '.$t), '/customers/%s', 409],
                'code now held by a live customer' => [$taken, '/customers/%s', 409],
                'foreign' => [$this->archivedRow('customer', $this->seedCustomer(self::TENANT_B, 'Cus RS FOR '.$t), self::TENANT_B), '/customers/%s', 404],
                'absent' => [Uuid::v4()->toRfc4122(), '/customers/%s', 404],
            ];
        });
    }

    #[Test]
    public function salesOrderParity(): void
    {
        $this->assertParity('sales_order', 'sales_order.restored', function (string $t): array {
            $warehouseGone = $this->seedWarehouse(self::TENANT_A, 'WH RS SO '.$t);
            $onArchivedWarehouse = $this->archivedRow('sales_order', $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), null, $warehouseGone));
            $this->archivedRow('warehouse', $warehouseGone);
            $customerGone = $this->seedCustomer(self::TENANT_A, 'Cus RS SO '.$t);
            $ofArchivedCustomer = $this->archivedRow('sales_order', $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $customerGone));
            $this->archivedRow('customer', $customerGone);

            return [
                'good (draft)' => [$this->archivedRow('sales_order', $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'))), '/sales-orders/%s', 200],
                'live' => [$this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO')), '/sales-orders/%s', 409],
                'warehouse archived' => [$onArchivedWarehouse, '/sales-orders/%s', 409],
                'customer archived' => [$ofArchivedCustomer, '/sales-orders/%s', 409],
                'foreign' => [$this->archivedRow('sales_order', $this->seedSalesOrder(self::TENANT_B, self::freshNumber('SO')), self::TENANT_B), '/sales-orders/%s', 404],
                'absent' => [Uuid::v4()->toRfc4122(), '/sales-orders/%s', 404],
            ];
        });
    }

    #[Test]
    public function purchaseOrderParity(): void
    {
        $this->assertParity('purchase_order', 'purchase_order.restored', function (string $t): array {
            $onArchivedWarehouse = $this->seedDraft(self::TENANT_A, self::freshNumber('PO'));
            $this->archivedRow('purchase_order', $onArchivedWarehouse['order']);
            $this->archivedRow('warehouse', $onArchivedWarehouse['warehouse']);
            $ofArchivedSupplier = $this->seedDraft(self::TENANT_A, self::freshNumber('PO'));
            $this->archivedRow('purchase_order', $ofArchivedSupplier['order']);
            $this->archivedRow('supplier', $ofArchivedSupplier['supplier']);

            return [
                'good (draft)' => [$this->archivedRow('purchase_order', $this->seedDraft(self::TENANT_A, self::freshNumber('PO'))['order']), '/purchase-orders/%s', 200],
                'live' => [$this->seedDraft(self::TENANT_A, self::freshNumber('PO'))['order'], '/purchase-orders/%s', 409],
                'warehouse archived' => [$onArchivedWarehouse['order'], '/purchase-orders/%s', 409],
                'supplier archived' => [$ofArchivedSupplier['order'], '/purchase-orders/%s', 409],
                'foreign' => [$this->archivedRow('purchase_order', $this->seedDraft(self::TENANT_B, self::freshNumber('PO'))['order'], self::TENANT_B), '/purchase-orders/%s', 404],
                'absent' => [Uuid::v4()->toRfc4122(), '/purchase-orders/%s', 404],
            ];
        });
    }

    // -----------------------------------------------------------------------
    // What restoration adds to the engine.
    // -----------------------------------------------------------------------

    /**
     * A refused member leaves nothing — a collision (a 23505 inside the joined single restore, translated there) is rolled
     * back to its savepoint and the members around it stand; the transaction is not left aborted. *Mutation:* a member's
     * collision rethrown untranslated → 500 → red.
     */
    #[Test]
    public function aCollidingMemberIsNotAppliedAndTheRestStand(): void
    {
        $first = $this->archivedRow('customer', $this->seedCustomer(self::TENANT_A, 'Cus RC1'));
        $colliding = $this->archivedRow('customer', $this->seedCustomer(self::TENANT_A, 'Cus RC2'));
        $holder = $this->seedCustomer(self::TENANT_A, 'Cus RC2 holder');
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET code = (SELECT code FROM customer WHERE id = ?::uuid) WHERE id = ?::uuid', [$colliding, $holder]);
        $last = $this->archivedRow('customer', $this->seedCustomer(self::TENANT_A, 'Cus RC3'));

        $this->bulk('customer', [$first, $colliding, $last]);

        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame(['requested' => 3, 'applied' => 2], $this->json());
        self::assertFalse($this->isArchived('customer', $first));
        self::assertFalse($this->isArchived('customer', $last));
        self::assertTrue($this->isArchived('customer', $colliding));
        $subjects = array_column($this->auditRowsOf('customer.restored'), 'subject_id');
        sort($subjects);
        $expected = [$first, $last];
        sort($expected);
        self::assertSame($expected, $subjects, 'One customer.restored row per applied member, none for the collision.');
    }

    /**
     * RED L-5: a collision only a bulk can reach — two ARCHIVED customers sharing one code, restored in one request. The
     * first (ascending id) restores; the second then meets the first's live code and is not applied, never a 500.
     */
    #[Test]
    public function twoArchivedRowsSharingACodeRestoreOneAndCountTheOther(): void
    {
        $ids = [$this->seedCustomer(self::TENANT_A, 'Cus TWIN 1'), $this->seedCustomer(self::TENANT_A, 'Cus TWIN 2')];
        sort($ids, \SORT_STRING);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id IN (?::uuid, ?::uuid)', $ids);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET code = (SELECT code FROM customer WHERE id = ?::uuid) WHERE id = ?::uuid', [$ids[0], $ids[1]]);

        $this->bulk('customer', [$ids[1], $ids[0]]);

        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame(['requested' => 2, 'applied' => 1], $this->json());
        self::assertFalse($this->isArchived('customer', $ids[0]), 'The lower id is restored first.');
        self::assertTrue($this->isArchived('customer', $ids[1]));
    }

    /** A restored order brings back the lines archived with it, exactly as the single restore's cascade does. */
    #[Test]
    public function aRestoredOrderBringsBackItsLines(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, self::freshNumber('PO'));
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item'], lineNumber: 1);
        $this->archivedRow('purchase_order', $draft['order']);
        self::assertTrue($this->isArchived('purchase_order_line', $line), 'Fixture: the cascade did not archive the line.');

        $this->bulk('purchase_order', [$draft['order']]);

        self::assertSame(['requested' => 1, 'applied' => 1], $this->json());
        self::assertFalse($this->isArchived('purchase_order', $draft['order']));
        self::assertFalse($this->isArchived('purchase_order_line', $line));
    }

    /** E-14: different refusals with the same counts answer the same bytes. */
    #[Test]
    public function unresolvedIdsAreReportedAsOneUndifferentiatedGroup(): void
    {
        $live = $this->seedSupplier(self::TENANT_A, 'Sup RU1');
        $taken = $this->archivedRow('supplier', $this->seedSupplier(self::TENANT_A, 'Sup RU2'));
        $this->seedSupplier(self::TENANT_A, 'Sup RU2');

        $this->bulk('supplier', [$this->archivedRow('supplier', $this->seedSupplier(self::TENANT_A, 'Sup RU3')), $live, $taken]);
        $first = $this->responseContent();
        $this->bulk('supplier', [$this->archivedRow('supplier', $this->seedSupplier(self::TENANT_A, 'Sup RU4')), $this->archivedRow('supplier', $this->seedSupplier(self::TENANT_B, 'Sup RU5'), self::TENANT_B), Uuid::v4()->toRfc4122()]);

        self::assertSame('{"requested":3,"applied":1}', $first);
        self::assertSame($first, $this->responseContent());
    }

    /** The permission is the single Restore's code, one decision, before any id is read; 403 names it. */
    #[Test]
    public function thePermissionIsTheSingleRestoresCodeCheckedBeforeAnyIdIsRead(): void
    {
        $customer = $this->archivedRow('customer', $this->seedCustomer(self::TENANT_A, 'Cus RP'));
        $this->actAs(self::TENANT_A, ['customer.view']);

        $this->recorder()->start();
        try {
            $this->bulk('customer', [$customer]);
        } finally {
            $this->recorder()->stop();
        }

        self::assertSame(403, $this->httpStatus(), $this->responseContent());
        self::assertSame('customer.delete', $this->json()['required_permission'] ?? null);
        self::assertSame([], $this->recorder()->matching('/\bcustomer\b(?!_)/i'), 'A customer row was read before the refusal.');
        self::assertTrue($this->isArchived('customer', $customer));

        $this->actAs(self::TENANT_A, ['customer.delete', 'customer.view']);
        $this->bulk('customer', [$customer]);
        self::assertSame(['requested' => 1, 'applied' => 1], $this->json(), 'Control: the holder of the single restore\'s code restores.');
    }

    #[Test]
    public function anExcludedOrUnknownResourceIs422(): void
    {
        foreach (['role', 'app_user', 'supplier_product', 'nope'] as $resource) {
            $this->send('POST', '/bulk/restorations', ['resource' => $resource, 'ids' => [Uuid::v4()->toRfc4122()]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
            self::assertSame(422, $this->httpStatus(), $resource.': '.$this->responseContent());
        }
    }

    /** G5-D0 carry (1): a body resolved for one resource and acted on as another is a 500, never a restoration. */
    #[Test]
    public function aBodyReadTwoWaysIsAServerErrorNotARestoration(): void
    {
        $customer = $this->archivedRow('customer', $this->seedCustomer(self::TENANT_A, 'Cus RTWO'));
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
        self::assertTrue($this->isArchived('customer', $customer));
    }

    /** The archival's five `ids` codes, one copy (`CreateBulkArchivalProcessor::ids()`), and the header after the permission. */
    #[Test]
    public function theBodyAndTheRetryKeyAreCheckedAsTheArchivalChecksThem(): void
    {
        $id = Uuid::v4()->toRfc4122();
        $this->send('POST', '/bulk/restorations', ['resource' => 'customer', 'ids' => [$id, strtoupper($id)]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        self::assertSame([['/ids/1', 'duplicate_id']], array_map(static fn (array $e): array => [$e['pointer'], $e['code']], $this->json()['errors']));

        $this->send('POST', '/bulk/restorations', ['resource' => 'customer', 'ids' => array_map(static fn (): string => Uuid::v4()->toRfc4122(), range(0, BulkArchival::MAXIMUM_MEMBERS))], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(422, $this->httpStatus());

        $this->send('POST', '/bulk/restorations', ['resource' => 'customer', 'ids' => [$id]]);
        self::assertSame(428, $this->httpStatus(), $this->responseContent());
    }

    /**
     * §6.2: the same key and request answer the stored envelope byte for byte; between the calls a member is archived again (as
     * the owner), so a recomputation would answer `applied: 2` and restore it. *Mutation:* recompute on replay → red.
     */
    #[Test]
    public function aReplayIsByteIdenticalOverHttp(): void
    {
        $a = $this->archivedRow('customer', $this->seedCustomer(self::TENANT_A, 'Cus RRP1'));
        $b = $this->seedCustomer(self::TENANT_A, 'Cus RRP2');
        $key = Uuid::v4()->toRfc4122();

        $this->bulk('customer', [$a, $b], $key);
        $first = [$this->httpStatus(), $this->responseContent(), $this->contentType()];
        self::assertSame([200, '{"requested":2,"applied":1}', BulkArchival::CONTENT_TYPE], $first);

        $this->archivedRow('customer', $a);
        $this->archivedRow('customer', $b);

        $this->recorder()->start();
        try {
            $this->bulk('customer', [$b, $a], $key);
        } finally {
            $this->recorder()->stop();
        }
        self::assertSame($first, [$this->httpStatus(), $this->responseContent(), $this->contentType()], 'A replay (ids reordered) is the stored answer.');
        self::assertSame([], $this->recorder()->matching('/\bcustomer\b(?!_)/i'), 'The replay ran member work before answering the stored envelope.');
        self::assertTrue($this->isArchived('customer', $a), 'The replay re-ran the work.');

        $this->bulk('customer', [$a, $b]);
        self::assertSame('{"requested":2,"applied":2}', $this->responseContent(), 'Control: a new key does the work.');
    }

    /** The envelope is scoped to its operation: one key used to archive, then to restore, is two requests, never a replay. */
    #[Test]
    public function oneKeyForAnArchivalAndARestorationIsTwoRequests(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Cus RKEY');
        $key = Uuid::v4()->toRfc4122();

        $this->send('POST', '/bulk/archivals', ['resource' => 'customer', 'ids' => [$customer]], [IdempotencyKeyGuard::HEADER => $key]);
        self::assertSame('{"requested":1,"applied":1}', $this->responseContent());
        $this->bulk('customer', [$customer], $key);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame('{"requested":1,"applied":1}', $this->responseContent());
        self::assertFalse($this->isArchived('customer', $customer), 'The restoration answered the archival\'s envelope instead of restoring.');

        $this->bulk('customer', [$this->seedCustomer(self::TENANT_A, 'Cus RKEY2')], $key);
        self::assertSame(409, $this->httpStatus(), 'The same key with another restoration request is a conflict. '.$this->responseContent());
    }

    // -----------------------------------------------------------------------

    /**
     * @param callable(string): array<string, array{0: string, 1: string, 2: int}> $seed label => [id, single-restore path
     *                                                                                     pattern (`%s` = id), its status]
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
            $target = str_ends_with($path, '/restoration') ? \sprintf($path, $id) : \sprintf($path, $id).'/restoration';
            $this->send('POST', $target);
            $status = $this->httpStatus();
            self::assertSame($expected, $status, $resource.' / '.$label.': the single restore\'s fixture no longer exercises its rule. '.$this->responseContent());
            if (200 !== $status) {
                self::assertTrue(\in_array($this->json()['type'] ?? null, ['urn:erp:problem:not-found', 'urn:erp:problem:business-rule-violated'], true),
                    $resource.' / '.$label.': the single restore refuses with a kind the bulk does not count as "not applied" (L-3).');
            }
            $expectedApplied[$label] = 200 === $status;
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
            $wasLive = 'live' === $label;
            self::assertSame(!($expectedApplied[$label] || $wasLive), $this->isArchived($resource, $id, $tenant), $resource.' / '.$label);
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
        $this->send('POST', '/bulk/restorations', ['resource' => $resource, 'ids' => $ids], [IdempotencyKeyGuard::HEADER => $key ?? Uuid::v4()->toRfc4122()]);
    }

    /** Archives a row as the schema owner (the DB cascade archives an order's lines) and returns its id. */
    private function archivedRow(string $table, string $id, string $tenant = self::TENANT_A): string
    {
        \assert(1 === preg_match('/^[a-z_]+$/', $table));
        $this->executeAsOwnerForTenant($tenant, \sprintf('UPDATE %s SET deleted_at = now() WHERE id = ?::uuid', $table), [$id]);

        return $id;
    }

    private function isArchived(string $table, string $id, string $tenant = self::TENANT_A): bool
    {
        \assert(1 === preg_match('/^[a-z_]+$/', $table));
        $rows = $this->fetchAsOwnerForTenant($tenant, \sprintf('SELECT deleted_at IS NOT NULL AS archived FROM %s WHERE id = ?::uuid', $table), [$id]);
        self::assertCount(1, $rows, $table.' '.$id.' is not seeded.');

        return true === $rows[0]['archived'];
    }
}
