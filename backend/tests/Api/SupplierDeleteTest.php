<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Application\SupplierOpenPurchaseOrders;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Suppliers\Application\SupplierProductWriter;
use App\Modules\Suppliers\Application\SupplierWriter;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **C5 C2b — `DeleteSupplier`, `DELETE /suppliers/{id}`, `supplier.delete`** (`docs/session13/c5-design.md` §2.4, §7.1,
 * Revision 1; owner ruling Q-S2: *"refuse a supplier with open purchase orders"*; `DeleteItem`'s shape, API-21 branch 3).
 *
 * 204, no body, `deleted_at` set; the supplier stays readable (`GetSupplier`, `archived_at` set) and is listed on the
 * Archived view, not the live one; its catalogue is kept. A repeated delete → 409 `supplier.is_archived`; a foreign, absent
 * or malformed id → the same 404; a LIVE purchase order in `draft`, `ordered` or `partially_received` → 409
 * `supplier.has_open_purchase_orders` naming the count; `received`, `cancelled` and deleted drafts do not block. One
 * `supplier.deleted` row, `{}`, on success and none on any refusal. Copy is `UX`'s final (`owed-ux-c5.md` FINAL (a1)); `SEC`
 * S-2 kept the count (`owed-sec-c5.md` §2.3).
 *
 * **The races (§7.1), real second connections (`hold_order_lock.php`), each failing if the request never waited:**
 * a delete holding the supplier ({@see SupplierWriter::DELETE_LOCK} + {@see SupplierWriter::ARCHIVE}, the writer's own
 * statements) against `CreatePurchaseOrder`, `UpdatePurchaseOrder` moving a draft onto it, `CreateSupplierProduct`,
 * `UpdateSupplierProduct`, `DeleteSupplierProduct` and `UpdateSupplier` — each waits and is then refused; and an order
 * created (or moved onto the supplier) first, against the delete — which waits and then counts it. Mutations: M12
 * (`FOR UPDATE` → `FOR NO KEY UPDATE`) reddens the order-first races (the delete never waits) and the delete-first order
 * races (nothing waits on it); M2′ (drop Purchasing's supplier `FOR KEY SHARE`) reddens the delete-first order races (the
 * FK check still waits, then lands an order on an archived supplier); M15 (drop the catalogue `FOR KEY SHARE`) reddens the
 * catalogue update/delete races.
 */
final class SupplierDeleteTest extends PurchaseOrderTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    /** A buyer archives a supplier with no open work: 204, no body; readable, on the Archived view, catalogue kept. */
    #[Test]
    public function aBuyerArchivesASupplierWithNoOpenWork(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Leaving Supplier');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $id, $this->seedItem(self::TENANT_A, 'SKU-DEL-SUP-1'));
        $catalogue = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT * FROM supplier_product WHERE id = ?::uuid', [$entry]);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::BUYER));

        $this->delete($id);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('', (string) $this->client()->getResponse()->getContent());
        self::assertNotNull($this->stored($id)['deleted_at']);

        $this->send('GET', '/suppliers/'.$id);
        self::assertSame(200, $this->httpStatus());
        self::assertNotNull($this->json()['archived_at'], 'An archived supplier is read with archived_at set (§5.2a).');
        self::assertNotContains('Leaving Supplier', array_column($this->listed(''), 'name'), 'An archived supplier is still on the live list.');
        self::assertContains('Leaving Supplier', array_column($this->listed('?archived=true'), 'name'), 'An archived supplier is not on the Archived view.');
        self::assertSame($catalogue, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT * FROM supplier_product WHERE id = ?::uuid', [$entry]),
            'Archiving touched the catalogue (design §4.1: no cascade).');
    }

    /** E9-3 end to end: what `DeleteSupplier` archives, `RestoreSupplier` brings back, and the Archived view shows between. */
    #[Test]
    public function anArchivedSupplierIsRestoredByTheOperationThatPairsWithIt(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Round Trip');

        $this->delete($id);
        self::assertSame(204, $this->httpStatus());
        $this->send('POST', '/suppliers/'.$id.'/restoration');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNull($this->json()['archived_at']);
        $this->delete($id);
        self::assertSame(204, $this->httpStatus(), 'A restored supplier could not be archived again.');
    }

    /** ST-9: a repeated delete is refused, not replayed (§4.2 `Idem` `no`), and writes nothing. */
    #[Test]
    public function aRepeatedDeleteIsRefusedBecauseTheSupplierIsArchived(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Twice Deleted');

        $this->delete($id);
        self::assertSame(204, $this->httpStatus());
        $before = $this->stored($id);

        $this->delete($id);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('supplier.is_archived', $this->json()['rule']);
        self::assertSame('This supplier is already archived. It\'s in the archived suppliers.', $this->json()['detail']);
        self::assertSame($before, $this->stored($id), 'A refused delete wrote the row.');
        self::assertCount(1, $this->auditRowsOf(AuditEvent::SUPPLIER_DELETED), 'A refused delete wrote a trail row.');
    }

    /** ST-5: a foreign, absent or malformed id is the same 404 as `GetSupplier`'s, and nothing is written. */
    #[Test]
    public function aDeleteOfAnUnknownSupplierIsTheSame404(): void
    {
        $theirs = $this->seedSupplier(self::TENANT_B, 'Theirs');
        $bodies = [];
        foreach ([$theirs, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->delete($id);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_B, "SELECT count(*) FROM supplier WHERE name = 'Theirs' AND deleted_at IS NOT NULL"));
        self::assertSame([], $this->auditRowsOf(AuditEvent::SUPPLIER_DELETED));
    }

    /**
     * Q-S2 (mutation M13): each open status blocks — `draft`, `ordered`, `partially_received` — and the refusal counts every
     * open order of the supplier, never another supplier's.
     */
    #[Test]
    public function aSupplierWithOpenPurchaseOrdersCannotBeArchived(): void
    {
        foreach ([PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_ORDERED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED] as $status) {
            $supplier = $this->seedSupplier(self::TENANT_A, 'Open '.$status);
            $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.$status);
            $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse, status: $status);

            $this->delete($supplier);

            self::assertSame(409, $this->httpStatus(), $status.': '.$this->client()->getResponse()->getContent());
            self::assertSame('supplier.has_open_purchase_orders', $this->json()['rule'], $status);
            self::assertSame('This supplier still has 1 open purchase order, so it can\'t be deleted until each open order is received in full or cancelled. A draft can simply be deleted.', $this->json()['detail'], $status);
            self::assertNull($this->stored($supplier)['deleted_at'], $status);
        }

        $many = $this->seedSupplier(self::TENANT_A, 'Many Open');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH many');
        $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $many, $warehouse);
        $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $many, $warehouse, status: PurchaseOrder::STATUS_ORDERED);
        // Another supplier's open order is not this supplier's work.
        $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $this->seedSupplier(self::TENANT_A, 'Bystander'), $warehouse);

        $this->delete($many);
        self::assertSame(409, $this->httpStatus());
        self::assertSame(\sprintf(SupplierWriter::HAS_OPEN_PURCHASE_ORDERS, SupplierWriter::openPurchaseOrders(2)), $this->json()['detail']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::SUPPLIER_DELETED), 'A refused delete wrote a trail row.');
    }

    /** Q-S2's "open" (mutation M14): `received`, `cancelled` and a deleted draft are closed, so they do not block. */
    #[Test]
    public function closedAndDeletedOrdersDoNotBlockArchiving(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Only Closed Work');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH closed');
        $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse, status: PurchaseOrder::STATUS_RECEIVED);
        $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse, status: PurchaseOrder::STATUS_CANCELLED);
        $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse, archived: true);

        $this->delete($supplier);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        // The documents keep naming the archived supplier (G-40's live join): nothing is rewritten.
        self::assertSame(3, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM purchase_order WHERE supplier_id = ?::uuid', [$supplier]));
    }

    /** The open set is Purchasing's own constants, and the statement Purchasing answers with counts exactly those. */
    #[Test]
    public function theOpenStatusesArePurchasingsOwnConstants(): void
    {
        self::assertSame([PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_ORDERED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED], SupplierOpenPurchaseOrders::OPEN_STATUSES);
        self::assertStringContainsString('deleted_at IS NULL', SupplierOpenPurchaseOrders::COUNT);
    }

    /** One `supplier.deleted`, `{}`, naming the deleter; the subject is the supplier (business, PC-11 (4a), no IP). */
    #[Test]
    public function aDeleteWritesExactlyOneSupplierDeletedRow(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Audited Leaver');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::PURCHASING_MANAGER));

        $this->delete($id);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->businessRow(AuditEvent::SUPPLIER_DELETED, 'supplier', $id)], $this->auditRowsOf(AuditEvent::SUPPLIER_DELETED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::SUPPLIER_UPDATED), 'A delete wrote an update row.');
    }

    /** `supplier.delete` gates the delete (§4.2): `viewer` and a holder of `supplier.edit` alone are refused, and nothing moves. */
    #[Test]
    public function onlyAHolderOfSupplierDeleteMayArchive(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Guarded Leaver');

        foreach ([DefaultRoles::codesFor(DefaultRoles::VIEWER), ['supplier.view', 'supplier.edit']] as $codes) {
            $this->actAs(self::TENANT_A, $codes);
            $this->delete($id);
            self::assertSame(403, $this->httpStatus());
        }
        self::assertNull($this->stored($id)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::SUPPLIER_DELETED));
    }

    /** After the delete, purchasing refuses the supplier as it refuses any archived one (unlocked shipped behaviour, C1). */
    #[Test]
    public function anArchivedSupplierCannotBeNamedOnANewOrder(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Gone Before Ordering');
        $this->delete($supplier);
        self::assertSame(204, $this->httpStatus());

        $this->createOrder($supplier, $this->seedWarehouse(self::TENANT_A, 'WH gone'));

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '/supplier_id', 'code' => 'unknown_reference']], $this->pointersAndCodes());
    }

    // ---- races: the delete first --------------------------------------------------------------------------------

    /** Delete first ∥ `CreatePurchaseOrder`: the create's supplier `FOR KEY SHARE` waits, re-reads, and is refused (M2′, M12). */
    #[Test]
    public function anOrderCreatedWhileItsSupplierIsBeingArchivedIsRefused(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Archiving Under Create');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH race create');

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($supplier), function () use ($supplier, $warehouse): void {
            $this->assertTheRequestConnectionIsReadCommitted();
            $this->createOrder($supplier, $warehouse);
        });

        self::assertTrue($holder['waiter_seen'], 'The order create never waited on the delete. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '/supplier_id', 'code' => 'unknown_reference']], $this->pointersAndCodes());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM purchase_order WHERE supplier_id = ?::uuid', [$supplier]),
            'An order landed on a supplier archived under it.');
    }

    /** Delete first ∥ `UpdatePurchaseOrder` moving a draft onto the supplier: waits, re-reads, refused (M2′, M12). */
    #[Test]
    public function aDraftMovedOntoASupplierBeingArchivedIsRefused(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, self::freshNumber('PO'));
        $target = $this->seedSupplier(self::TENANT_A, 'Archiving Under Move');

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($target), function () use ($draft, $target): void {
            $this->send('PATCH', '/purchase-orders/'.$draft['order'], ['supplier_id' => $target]);
        });

        self::assertTrue($holder['waiter_seen'], 'The order edit never waited on the delete. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '/supplier_id', 'code' => 'unknown_reference']], $this->pointersAndCodes());
        self::assertSame($draft['supplier'], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT supplier_id::text AS s FROM purchase_order WHERE id = ?::uuid', [$draft['order']])[0]['s']);
    }

    /** Delete first ∥ `CreateSupplierProduct`: its `FOR SHARE` parent read waits and sees the archived supplier. */
    #[Test]
    public function aCatalogueAddWhileTheSupplierIsBeingArchivedIsRefused(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Archiving Under Add');
        $item = $this->seedItem(self::TENANT_A, 'SKU-DEL-RACE-1');

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($supplier), function () use ($supplier, $item): void {
            $this->send('POST', '/suppliers/'.$supplier.'/products',
                ['inventory_item_id' => $item, 'supplier_sku' => 'SP-RACE-ADD', 'unit_cost' => '1', 'lead_time_days' => 1],
                [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedThenRefusedAsArchived($holder, SupplierProductWriter::ARCHIVED_ON_CREATE);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM supplier_product WHERE supplier_id = ?::uuid', [$supplier]));
    }

    /** Delete first ∥ `UpdateSupplierProduct`: its parent read is `FOR KEY SHARE` since C2b, so it waits (mutation M15). */
    #[Test]
    public function aCatalogueChangeWhileTheSupplierIsBeingArchivedIsRefused(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Archiving Under Change');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $this->seedItem(self::TENANT_A, 'SKU-DEL-RACE-2'), 7);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($supplier), function () use ($supplier, $entry): void {
            $this->send('PATCH', '/suppliers/'.$supplier.'/products/'.$entry, ['lead_time_days' => 3]);
        });

        $this->assertWaitedThenRefusedAsArchived($holder, SupplierProductWriter::ARCHIVED_ON_UPDATE);
        self::assertSame(7, (int) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT lead_time_days FROM supplier_product WHERE id = ?::uuid', [$entry])[0]['lead_time_days']);
    }

    /** Delete first ∥ `DeleteSupplierProduct`: `FOR KEY SHARE` since C2b, so it waits and the entry survives (mutation M15). */
    #[Test]
    public function aCatalogueRemovalWhileTheSupplierIsBeingArchivedIsRefused(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Archiving Under Removal');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $this->seedItem(self::TENANT_A, 'SKU-DEL-RACE-3'));

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($supplier), function () use ($supplier, $entry): void {
            $this->send('DELETE', '/suppliers/'.$supplier.'/products/'.$entry);
        });

        $this->assertWaitedThenRefusedAsArchived($holder, SupplierProductWriter::ARCHIVED_ON_DELETE);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM supplier_product WHERE id = ?::uuid', [$entry]));
    }

    /** Delete first ∥ `UpdateSupplier`: its `FOR NO KEY UPDATE` conflicts with `FOR UPDATE`; it waits, then sees the archive. */
    #[Test]
    public function aSupplierEditWhileItIsBeingArchivedIsRefused(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Archiving Under Edit');

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($supplier), function () use ($supplier): void {
            $this->send('PATCH', '/suppliers/'.$supplier, ['payment_terms_days' => 45]);
        });

        $this->assertWaitedThenRefusedAsArchived($holder, SupplierWriter::ARCHIVED);
        self::assertSame([], $this->auditRowsOf(AuditEvent::SUPPLIER_UPDATED));
    }

    // ---- races: the order first ----------------------------------------------------------------------------------

    /**
     * An order created first ∥ the delete: the order's supplier lock (its liveness read's `FOR KEY SHARE`, then its FK
     * check) makes the delete's `FOR UPDATE` wait; the count, a later statement under READ COMMITTED, sees the committed
     * order and refuses (mutation M12: under `FOR NO KEY UPDATE` the delete never waits — red).
     */
    #[Test]
    public function aDeleteRacingAnOrderCreateCountsTheOrder(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Ordered From Meanwhile');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH order first');

        $holder = $this->raceAgainstAHolder([
            ['SELECT 1 FROM supplier WHERE id = ?::uuid AND deleted_at IS NULL FOR KEY SHARE', [$supplier]],
            $this->anOrderInsert($supplier, $warehouse),
        ], function () use ($supplier): void {
            $this->assertTheRequestConnectionIsReadCommitted();
            $this->delete($supplier);
        });

        $this->assertTheDeleteWaitedAndCountedOneOrder($holder, $supplier);
    }

    /** A draft moved onto the supplier first ∥ the delete: the delete waits on the move's supplier lock, then counts it. */
    #[Test]
    public function aDeleteRacingADraftMovedOntoTheSupplierCountsTheDraft(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, self::freshNumber('PO'));
        $target = $this->seedSupplier(self::TENANT_A, 'Moved Onto Meanwhile');

        $holder = $this->raceAgainstAHolder([
            ['SELECT 1 FROM purchase_order WHERE id = ?::uuid FOR NO KEY UPDATE', [$draft['order']]],
            ['SELECT 1 FROM supplier WHERE id = ?::uuid AND deleted_at IS NULL FOR KEY SHARE', [$target]],
            ['UPDATE purchase_order SET supplier_id = ?::uuid, updated_at = now() WHERE id = ?::uuid', [$target, $draft['order']]],
        ], function () use ($target): void {
            $this->delete($target);
        });

        $this->assertTheDeleteWaitedAndCountedOneOrder($holder, $target);
    }

    // -----------------------------------------------------------------------

    private function delete(string $id): void
    {
        $this->send('DELETE', '/suppliers/'.$id);
    }

    private function createOrder(string $supplier, string $warehouse): void
    {
        $this->send('POST', '/purchase-orders', ['supplier_id' => $supplier, 'warehouse_id' => $warehouse],
            [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
    }

    /** @return list<array{pointer: string, code: string}> */
    private function pointersAndCodes(): array
    {
        return array_map(static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    private function listed(string $query): array
    {
        $this->send('GET', '/suppliers'.$query);
        self::assertSame(200, $this->httpStatus());

        return $this->json()['items'];
    }

    /** @return array{deleted_at: ?string, updated_at: string} */
    private function stored(string $id): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at, updated_at FROM supplier WHERE id = ?::uuid', [$id])[0];
    }

    /**
     * The delete's own two statements — THE WRITER'S constants, never a transcription — so a mutation of either is what the
     * delete-first races hold.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function theDeletesOwnStatements(string $supplier): array
    {
        return [[SupplierWriter::DELETE_LOCK, [$supplier]], [SupplierWriter::ARCHIVE, [$supplier]]];
    }

    /**
     * A draft purchase order as `CreatePurchaseOrder` inserts it (`seedOrder()`'s columns), run by the holder.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function anOrderInsert(string $supplier, string $warehouse): array
    {
        return [<<<'SQL'
            INSERT INTO purchase_order (id, tenant_id, order_number, supplier_id, warehouse_id, status, currency_code, idempotency_key, request_digest)
            VALUES (?::uuid, ?::uuid, ?, ?::uuid, ?::uuid, 'draft', 'EUR', ?, ?)
            SQL, [
            Uuid::v4()->toRfc4122(),
            self::TENANT_A,
            self::freshNumber('PO'),
            $supplier,
            $warehouse,
            IdempotencyKeyHash::of(Uuid::v4()->toRfc4122()),
            RequestDigest::of('CreatePurchaseOrder', [
                'supplier_id' => RequestDigest::uuid($supplier),
                'warehouse_id' => RequestDigest::uuid($warehouse),
                'currency_code' => null,
                'expected_at' => RequestDigest::instant(null),
            ]),
        ]];
    }

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertWaitedThenRefusedAsArchived(array $holder, string $sentence): void
    {
        self::assertTrue($holder['waiter_seen'], 'The request never waited on the delete. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('supplier.is_archived', $this->json()['rule']);
        self::assertSame($sentence, $this->json()['detail']);
    }

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertTheDeleteWaitedAndCountedOneOrder(array $holder, string $supplier): void
    {
        self::assertTrue($holder['waiter_seen'], 'The delete never waited on the order: its row lock is not FOR UPDATE. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('supplier.has_open_purchase_orders', $this->json()['rule']);
        self::assertSame(\sprintf(SupplierWriter::HAS_OPEN_PURCHASE_ORDERS, SupplierWriter::openPurchaseOrders(1)), $this->json()['detail']);
        self::assertNull($this->stored($supplier)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::SUPPLIER_DELETED));
    }

    /** §5.2.5's precedent (`PurchaseOrderRowLockTest`): the re-reads are correct only under READ COMMITTED — asserted, not assumed. */
    private function assertTheRequestConnectionIsReadCommitted(): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        self::assertSame('read committed', $em->getConnection()->fetchOne('SHOW transaction_isolation'),
            'The request connection is not READ COMMITTED; the delete\'s count and the readers\' re-checks are only correct under it.');
    }

    /**
     * `SupplierProductWriteTest::raceAgainstAHolder`'s shape, a fifth copy (not extracted, the earlier copies' reason): the
     * holder's own lock is on a random `purchase_order` id, so it matches no row; the real locks are `HOLD_BEFORE`'s.
     *
     * @param list<array{0: string, 1: list<string>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_supplier_delete_');
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
