<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Inventory\Application\InventoryHeldStock;
use App\Modules\Purchasing\Application\GoodsReceiptWriter;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\Warehouses\Application\Contract\LocationKinds;
use App\Modules\Warehouses\Application\StorageLocationWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Warehouse/location delete-restore C3 — `DeleteStorageLocation`, `DELETE /warehouses/{id}/locations/{locationId}`,
 * `storage_location.delete`** (`docs/session13/wh-loc-delete-design.md` §0, §2.2, §3.3, §7.1–§7.3, Revision 2; owner Q-1
 * adopted under delegation, `wh-loc-delete-q-ruling.md`: a location holding stock may not be archived). `DeleteSupplier`'s
 * shape, API-21 branch 3.
 *
 * 204, no body, `deleted_at` set; the location stays listed on the Archived view and documents that name it keep its code
 * (G-43). Refusals, in order: a foreign, absent or malformed id (or another warehouse's location) → the same 404; the
 * warehouse archived → 409 `warehouse.is_archived`; already archived → 409 `storage_location.is_archived`; stock in it, any
 * kind (quarantine included) → 409 `storage_location.holds_stock`, with the archived-item sentence when any of the stock is an
 * archived item's (`RESIDUAL_GAPS.md` G-51). Zero rows do not block (G-47). One `storage_location.deleted`, `{}`, on success,
 * none on any refusal. Copy is `UX`'s final (`wh-loc-delete-roles.md` U-1 C3), each sentence pinned literally.
 *
 * **The two shipped bin refusals made product-reachable here** (`wh-loc-delete-roles-red.md` M-2): a receipt and a return
 * into a location just archived are refused on the line, in the new passive sentences — pinned literally below.
 *
 * **The races (§7.1), real second connections (`hold_order_lock.php`), each failing if the request never waited.** Writer
 * first, the delete is B: a stock write holding the location `FOR KEY SHARE` (explicitly, as the count and the transfer take
 * it, or by the foreign key of the row it inserts, as a receipt and a return do) makes the delete's `FOR UPDATE` wait; the
 * stock question, a later statement, then sees the stock and refuses (R-L1, R-L2, R-L4 shapes); a move OUT of the location
 * leaves it empty and the delete goes through (R-L3); an `UpdateStorageLocation` first is waited for and the delete proceeds
 * (R-L6). Delete first, the writer is B: the location's `FOR UPDATE` + archive held, each writer waits and is refused in its
 * own terms — count and transfer 409 `storage_location.is_archived`, receipt 422 `storage_location.belongs_to_receiving_warehouse`,
 * return 422 `storage_location.belongs_to_returning_warehouse`, edit 409 `storage_location.is_archived` (T-6, measured).
 * **Mutations:** M7 (`FOR UPDATE` → `FOR NO KEY UPDATE`) reddens the writer-first races (the delete never waits and archives
 * a location the writer is filling); M8 (stock predicate never true) and M9 (audit on a refusal) redden the refusal tests;
 * M6 (lookup without `warehouse_id`) reddens the other-warehouse 404.
 */
final class StorageLocationDeleteTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;

    private const HOLDER_DEADLINE_SECONDS = 10.0;

    /** An admin archives an empty location: 204, no body; on the Archived view, off the live list; one `.deleted`, `{}`. */
    #[Test]
    public function anAdminArchivesAnEmptyLocation(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Delete WH');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'D-01');
        $this->actAsRole(DefaultRoles::ADMIN);

        $this->delete($warehouse, $location);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('', (string) $this->client()->getResponse()->getContent());
        $stored = $this->stored($location);
        self::assertNotNull($stored['deleted_at']);
        self::assertSame($stored['deleted_at'], $stored['updated_at'], 'The archive did not move updated_at with deleted_at.');
        self::assertNotContains('D-01', $this->listedCodes($warehouse, ''), 'An archived location is still on the live list.');
        self::assertContains('D-01', $this->listedCodes($warehouse, '?archived=true'), 'An archived location is not on the Archived view.');

        $rows = $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_DELETED);
        self::assertCount(1, $rows);
        self::assertSame($location, $rows[0]['subject_id']);
        self::assertSame('storage_location', $rows[0]['subject_type']);
        self::assertSame([], json_decode((string) $rows[0]['metadata'], true));
    }

    /** E9-3 end to end: what `DeleteStorageLocation` archives, `RestoreStorageLocation` brings back. */
    #[Test]
    public function anArchivedLocationIsRestoredByTheOperationThatPairsWithIt(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Round Trip WH');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'RT-01');
        $this->actAsRole(DefaultRoles::OWNER);

        $this->delete($warehouse, $location);
        self::assertSame(204, $this->httpStatus());
        $this->send('POST', '/warehouses/'.$warehouse.'/locations/'.$location.'/restoration');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNull($this->json()['archived_at']);
        $this->delete($warehouse, $location);
        self::assertSame(204, $this->httpStatus(), 'A restored location could not be archived again.');
    }

    /** ST-9: a repeated delete is refused, not replayed (§4.2 `Idem` `no`), and writes nothing. */
    #[Test]
    public function aRepeatedDeleteIsRefusedBecauseTheLocationIsArchived(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Twice WH');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'TW-01');
        $this->actAsRole(DefaultRoles::ADMIN);

        $this->delete($warehouse, $location);
        self::assertSame(204, $this->httpStatus());
        $before = $this->stored($location);

        $this->delete($warehouse, $location);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('storage_location.is_archived', $this->json()['rule']);
        self::assertSame('This location is already archived. It\'s in this warehouse\'s archived locations.', $this->json()['detail']);
        self::assertSame(StorageLocationWriter::ALREADY_ARCHIVED, $this->json()['detail']);
        self::assertSame($before, $this->stored($location), 'A refused delete wrote the row.');
        self::assertCount(1, $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_DELETED), 'A refused delete wrote a trail row.');
    }

    /** ST-5 (mutation M6): another workspace's, another warehouse's, a random and a malformed id are one 404; nothing written. */
    #[Test]
    public function aDeleteOfAnUnknownLocationIsTheSame404(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Mine WH');
        $mine = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'M-01');
        $otherWarehouse = $this->seedWarehouse(self::TENANT_A, 'Other WH');
        $elsewhere = $this->seedStorageLocation(self::TENANT_A, $otherWarehouse, 'O-01');
        $theirWarehouse = $this->seedWarehouse(self::TENANT_B, 'Theirs WH');
        $theirs = $this->seedStorageLocation(self::TENANT_B, $theirWarehouse, 'T-01');
        $this->actAsRole(DefaultRoles::OWNER);

        $bodies = [];
        foreach ([[$warehouse, $elsewhere], [$theirWarehouse, $theirs], [$warehouse, Uuid::v4()->toRfc4122()], [$warehouse, 'not-a-uuid']] as [$w, $l]) {
            $this->delete($w, $l);
            self::assertSame(404, $this->httpStatus(), $w.'/'.$l);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[2], 'Another warehouse\'s location is told apart from a random id.');
        self::assertNull($this->stored($elsewhere)['deleted_at'], 'A location of another warehouse was archived through this path.');
        self::assertNull($this->stored($mine)['deleted_at']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM storage_location WHERE id = ?::uuid AND deleted_at IS NOT NULL', [$theirs]));
        self::assertSame([], $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_DELETED));
    }

    /** ST-9 / owner Q-2 (a): a location of an archived warehouse is read-only until the warehouse is restored. */
    #[Test]
    public function aLocationOfAnArchivedWarehouseIsDeletedOnlyAfterTheWarehouseIsRestored(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Closed WH');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'C-01');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$warehouse]);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->delete($warehouse, $location);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('warehouse.is_archived', $this->json()['rule']);
        self::assertSame('This location\'s warehouse is archived, so the location can\'t be deleted until the warehouse is restored.', $this->json()['detail']);
        self::assertNull($this->stored($location)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_DELETED));

        $this->send('POST', '/warehouses/'.$warehouse.'/restoration');
        self::assertSame(200, $this->httpStatus());
        $this->delete($warehouse, $location);
        self::assertSame(204, $this->httpStatus(), 'Restoring the warehouse did not make its location deletable.');
    }

    /**
     * Owner Q-1 (mutations M8, M9): stock in a location of any kind — quarantine included — refuses the delete and writes
     * nothing; the stock and the row are untouched. Reserved-only stock cannot exist (`stock_level_reserved_sane`), so the
     * `qty_reserved` disjunct is not driven (M17 cannot go red; disclosed in `InventoryHeldStock`).
     */
    #[Test]
    #[DataProvider('everyLocationKind')]
    public function aLocationHoldingStockCannotBeArchivedWhateverItsKind(string $kind): void
    {
        // One kind per run, never a loop: a refused request closes the shared entity manager.
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Stocked '.$kind);
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'S-01', $kind);
        $item = $this->seedItem(self::TENANT_A, 'SKU-HELD-'.strtoupper($kind));
        $this->stockIn($item, $location, '0.000001');
        $this->actAsRole(DefaultRoles::OWNER);

        $this->delete($warehouse, $location);

        self::assertSame(409, $this->httpStatus(), $kind.': '.(string) $this->client()->getResponse()->getContent());
        self::assertSame('storage_location.holds_stock', $this->json()['rule']);
        self::assertSame('This location still holds stock, so it can\'t be deleted until all of it is moved to another location or counted off.', $this->json()['detail']);
        self::assertSame(StorageLocationWriter::HOLDS_STOCK, $this->json()['detail']);
        self::assertNull($this->stored($location)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_DELETED), 'A refused delete wrote a trail row.');
    }

    /** @return iterable<string, array{string}> every `LocationKinds::ALL` member — quarantine included */
    public static function everyLocationKind(): iterable
    {
        foreach (LocationKinds::ALL as $kind) {
            yield $kind => [$kind];
        }
    }

    /** G-47: a location whose rows are all at zero holds no stock — the zero row stays, the archive goes through. */
    #[Test]
    public function aLocationWithOnlyZeroRowsIsArchived(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Zero WH');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'Z-01');
        $item = $this->seedItem(self::TENANT_A, 'SKU-ZERO');
        $this->stockIn($item, $location, '0');
        $this->actAsRole(DefaultRoles::ADMIN);

        $this->delete($warehouse, $location);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_level WHERE storage_location_id = ?::uuid', [$location]),
            'The zero row was removed (G-47: zero rows stay).');
    }

    /**
     * **`RESIDUAL_GAPS.md` G-51's mechanism** (`wh-loc-delete-roles-red.md` M-4): the item is archived **through `DeleteItem`
     * (204 asserted), never by owner SQL**, so the day `DeleteItem` refuses a stocked item this test goes red at its first
     * step — the deliberate move that retires or rewords G-51. Both sentences pinned literally, for all and for part of the
     * stock being an archived item's (`UX` U-4: one sentence true for both).
     */
    #[Test]
    public function aLocationHoldingAnArchivedItemsStockNamesTheItem(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Archived Item WH');
        $all = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'AI-01');
        $part = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'AI-02');
        $archived = $this->seedItem(self::TENANT_A, 'SKU-ARCHIVED-HELD');
        $live = $this->seedItem(self::TENANT_A, 'SKU-LIVE-HELD');
        $this->stockIn($archived, $all, '3');
        $this->stockIn($archived, $part, '1');
        $this->stockIn($live, $part, '2');
        $this->actAsRole(DefaultRoles::OWNER);

        $this->send('DELETE', '/items/'.$archived);
        self::assertSame(204, $this->httpStatus(), 'DeleteItem refused a stocked item: G-51\'s closing work has landed — retire or reword G-51 deliberately. '
            .(string) $this->client()->getResponse()->getContent());

        $sentence = 'This location still holds stock, so it can\'t be deleted until all of it is moved to another location or counted off. '
            .'Stock of an archived item can be moved or counted off only once that item is restored.';
        foreach ([$all, $part] as $location) {
            $this->delete($warehouse, $location);
            self::assertSame(409, $this->httpStatus());
            self::assertSame('storage_location.holds_stock', $this->json()['rule']);
            self::assertSame($sentence, $this->json()['detail']);
            self::assertSame(StorageLocationWriter::HOLDS_STOCK.' '.StorageLocationWriter::ARCHIVED_ITEM_STOCK, $this->json()['detail']);
        }
    }

    /** G-43: an issued receipt keeps showing the code of the location it received into after that location is archived. */
    #[Test]
    public function anIssuedReceiptStillShowsTheArchivedLocationsCode(): void
    {
        $po = $this->orderedOrder(self::freshNumber('PO'));
        $bin = $this->seedStorageLocation(self::TENANT_A, $po['warehouse'], 'G43-01');
        $this->actAsRole(DefaultRoles::OWNER);
        $this->receive($po['order'], $po['line'], '2', $bin);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $receipt = $this->json()['id'];
        // Count the received stock off, so the location can be archived.
        $this->send('POST', '/stock-movements/adjustments', ['inventory_item_id' => $po['item'], 'storage_location_id' => $bin,
            'counted_quantity' => '0', 'version' => 1, 'note' => 'Cleared'], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->delete($po['warehouse'], $bin);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('GET', '/goods-receipts/'.$receipt);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('G43-01', $this->json()['lines'][0]['storage_location']['code'], 'The archived location\'s code vanished from an issued receipt.');
    }

    /** Only `storage_location.delete` (owner, `admin`) may archive: `warehouse_operator` (`.edit`, no `.delete`) and `viewer` → 403. */
    #[Test]
    public function onlyAHolderOfStorageLocationDeleteMayArchive(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Perm WH');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'P-01');
        foreach ([DefaultRoles::WAREHOUSE_OPERATOR, DefaultRoles::VIEWER] as $role) {
            $this->actAsRole($role);
            $this->delete($warehouse, $location);
            self::assertSame(403, $this->httpStatus(), $role);
        }
        self::assertNull($this->stored($location)['deleted_at']);
    }

    /**
     * `wh-loc-delete-roles-red.md` M-2, receipt half: a receipt into a location archived through the product is refused on
     * its line, in `UX`'s passive sentence (never the contract's raw ids).
     */
    #[Test]
    public function aReceiptIntoADeletedLocationIsRefusedInPlainWords(): void
    {
        $po = $this->orderedOrder(self::freshNumber('PO'));
        $bin = $this->seedStorageLocation(self::TENANT_A, $po['warehouse'], 'GR-DEL');
        $this->actAsRole(DefaultRoles::OWNER);
        $this->delete($po['warehouse'], $bin);
        self::assertSame(204, $this->httpStatus());

        $this->receive($po['order'], $po['line'], '1', $bin);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([[
            'pointer' => '/lines/0/storage_location_id',
            'code' => 'storage_location.belongs_to_receiving_warehouse',
            'message' => 'This location can\'t take goods: it\'s archived, or it isn\'t in this order\'s warehouse.',
        ]], $this->errors());
        self::assertSame(GoodsReceiptWriter::BIN_CANNOT_TAKE_GOODS, $this->errors()[0]['message']);
    }

    /** The return twin of the receipt case (`wh-loc-delete-roles-red.md` M-2). */
    #[Test]
    public function aReturnIntoADeletedLocationIsRefusedInPlainWords(): void
    {
        $s = $this->shipped('SLD-RET', [['5', '10.0000', '4']]);
        $warehouse = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT warehouse_id::text AS w FROM fulfilment WHERE id = ?::uuid', [$s['fulfilment']])[0]['w'];
        $line = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id::text AS id FROM fulfilment_line WHERE fulfilment_id = ?::uuid', [$s['fulfilment']])[0]['id'];
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'RET-DEL');
        $this->actAsRole(DefaultRoles::OWNER);
        $this->delete($warehouse, $bin);
        self::assertSame(204, $this->httpStatus());

        $this->returnInto($s['fulfilment'], $line, $bin);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([[
            'pointer' => '/lines/0/storage_location_id',
            'code' => 'storage_location.belongs_to_returning_warehouse',
            'message' => 'This location can\'t take the goods back: it\'s archived, or it isn\'t in the warehouse they were shipped from.',
        ]], $this->errors());
    }

    // ---- races: the writer first, the delete is B ------------------------------------------------------------------

    /** R-L1/R-L2 shape: a count or a transfer INTO the location holds it `FOR KEY SHARE` (explicit) and adds stock (M7). */
    #[Test]
    public function aDeleteRacingAnExplicitlyLockedStockWriteWaitsAndRefuses(): void
    {
        $s = $this->emptyLocation('RL1');
        $holder = $this->raceAgainstAHolder([
            ['SELECT 1 FROM warehouse WHERE id = ?::uuid FOR KEY SHARE', [$s['warehouse']]],
            ['SELECT kind FROM storage_location WHERE id = ?::uuid FOR KEY SHARE', [$s['location']]],
            $this->aLevelInsert($s['item'], $s['location'], '5'),
        ], function () use ($s): void {
            $this->assertTheRequestConnectionIsReadCommitted();
            $this->delete($s['warehouse'], $s['location']);
        });

        $this->assertTheDeleteWaitedAndRefusedHeldStock($holder, $s['location']);
    }

    /** R-L4/R-L5 shape: a receipt or a return holds the location only through the foreign key of the row it inserts (M7). */
    #[Test]
    public function aDeleteRacingAForeignKeyLockedStockWriteWaitsAndRefuses(): void
    {
        $s = $this->emptyLocation('RL4');
        $holder = $this->raceAgainstAHolder([
            $this->aLevelInsert($s['item'], $s['location'], '2'),
        ], function () use ($s): void {
            $this->delete($s['warehouse'], $s['location']);
        });

        $this->assertTheDeleteWaitedAndRefusedHeldStock($holder, $s['location']);
    }

    /** R-L3: a move that empties the location first: the delete waits, then sees it empty and archives it. */
    #[Test]
    public function aDeleteRacingAMoveThatEmptiesTheLocationWaitsAndArchives(): void
    {
        $s = $this->emptyLocation('RL3');
        $this->stockIn($s['item'], $s['location'], '4');
        $holder = $this->raceAgainstAHolder([
            ['SELECT kind FROM storage_location WHERE id = ?::uuid FOR KEY SHARE', [$s['location']]],
            ['UPDATE stock_level SET qty_on_hand = 0, version = version + 1 WHERE storage_location_id = ?::uuid', [$s['location']]],
        ], function () use ($s): void {
            $this->delete($s['warehouse'], $s['location']);
        });

        self::assertTrue($holder['waiter_seen'], 'The delete never waited on the move. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNotNull($this->stored($s['location'])['deleted_at']);
    }

    /** R-L6, edit first: an `UpdateStorageLocation` kind change (`FOR UPDATE`) is waited for; the delete then archives. */
    #[Test]
    public function aDeleteRacingALocationEditWaitsAndArchives(): void
    {
        $s = $this->emptyLocation('RL6');
        $holder = $this->raceAgainstAHolder([
            ['SELECT 1 FROM storage_location WHERE id = ?::uuid FOR UPDATE', [$s['location']]],
            ["UPDATE storage_location SET kind = 'shelf', updated_at = now() WHERE id = ?::uuid", [$s['location']]],
        ], function () use ($s): void {
            $this->delete($s['warehouse'], $s['location']);
        });

        self::assertTrue($holder['waiter_seen'], 'The delete never waited on the edit. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    // ---- races: the delete first, the writer is B (T-6: each writer's own refusal) ----------------------------------

    /** R-L1, delete first: a count up into the location waits on its `FOR KEY SHARE`, re-reads, and is refused. */
    #[Test]
    public function aCountIntoALocationBeingArchivedIsRefused(): void
    {
        $s = $this->emptyLocation('DL1');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($s), function () use ($s): void {
            $this->send('POST', '/stock-movements/adjustments', ['inventory_item_id' => $s['item'], 'storage_location_id' => $s['location'],
                'counted_quantity' => '5', 'version' => null, 'note' => 'Found'], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedThenRefused($holder, 409, 'storage_location.is_archived', $s['location']);
    }

    /** R-L2, delete first: a transfer into the location waits, re-reads, and is refused naming the destination. */
    #[Test]
    public function aTransferIntoALocationBeingArchivedIsRefused(): void
    {
        $s = $this->emptyLocation('DL2');
        $source = $this->seedStorageLocation(self::TENANT_A, $s['warehouse'], 'SRC-01');
        $this->stockIn($s['item'], $source, '5');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($s), function () use ($s, $source): void {
            $this->send('POST', '/stock-movements/transfers', ['inventory_item_id' => $s['item'], 'source_storage_location_id' => $source,
                'destination_storage_location_id' => $s['location'], 'quantity' => '2'], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedThenRefused($holder, 409, 'storage_location.is_archived', $s['location']);
        self::assertSame('5.000000', $this->onHand($source), 'Stock left the source although the move was refused.');
    }

    /** R-L4, delete first: a receipt's line insert waits on the archive; the receiver then refuses the location. */
    #[Test]
    public function aReceiptIntoALocationBeingArchivedIsRefused(): void
    {
        $po = $this->orderedOrder(self::freshNumber('PO'));
        $s = ['warehouse' => $po['warehouse'], 'location' => $this->seedStorageLocation(self::TENANT_A, $po['warehouse'], 'DL4-01')];
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($s), function () use ($po, $s): void {
            $this->receive($po['order'], $po['line'], '1', $s['location']);
        });

        $this->assertWaitedThenRefused($holder, 422, 'storage_location.belongs_to_receiving_warehouse', $s['location']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt WHERE purchase_order_id = ?::uuid', [$po['order']]));
    }

    /** R-L5, delete first: a return's line insert waits on the archive; the returner then refuses the location. */
    #[Test]
    public function aReturnIntoALocationBeingArchivedIsRefused(): void
    {
        $shipment = $this->shipped('SLD-RACE', [['5', '10.0000', '4']]);
        $warehouse = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT warehouse_id::text AS w FROM fulfilment WHERE id = ?::uuid', [$shipment['fulfilment']])[0]['w'];
        $line = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id::text AS id FROM fulfilment_line WHERE fulfilment_id = ?::uuid', [$shipment['fulfilment']])[0]['id'];
        $s = ['warehouse' => $warehouse, 'location' => $this->seedStorageLocation(self::TENANT_A, $warehouse, 'DL5-01')];
        $this->actAsRole(DefaultRoles::OWNER);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($s), function () use ($shipment, $line, $s): void {
            $this->returnInto($shipment['fulfilment'], $line, $s['location']);
        });

        $this->assertWaitedThenRefused($holder, 422, 'storage_location.belongs_to_returning_warehouse', $s['location']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM sales_return WHERE fulfilment_id = ?::uuid', [$shipment['fulfilment']]));
    }

    /** R-L6, delete first: an edit of the location waits, re-reads it archived, and is refused (ST-9). */
    #[Test]
    public function anEditOfALocationBeingArchivedIsRefused(): void
    {
        $s = $this->emptyLocation('DL6');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($s), function () use ($s): void {
            $this->send('PATCH', '/warehouses/'.$s['warehouse'].'/locations/'.$s['location'], ['kind' => 'shelf']);
        });

        $this->assertWaitedThenRefused($holder, 409, 'storage_location.is_archived', $s['location']);
        self::assertSame(StorageLocationWriter::ARCHIVED, $this->json()['detail']);
    }

    // -----------------------------------------------------------------------

    private function delete(string $warehouse, string $location): void
    {
        $this->send('DELETE', '/warehouses/'.$warehouse.'/locations/'.$location);
    }

    /** @return array{deleted_at: ?string, updated_at: string} */
    private function stored(string $location): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at::text AS deleted_at, updated_at::text AS updated_at FROM storage_location WHERE id = ?::uuid', [$location])[0];
    }

    /** @return list<string> */
    private function listedCodes(string $warehouse, string $query): array
    {
        $this->send('GET', '/warehouses/'.$warehouse.'/locations'.$query);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return array_column($this->json()['items'], 'code');
    }

    private function stockIn(string $item, string $location, string $quantity): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $location, $quantity]);
    }

    private function onHand(string $location): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT COALESCE(SUM(qty_on_hand), 0)::numeric(19,6)::text AS q FROM stock_level WHERE storage_location_id = ?::uuid', [$location])[0]['q'];
    }

    /** @return array{warehouse: string, location: string, item: string} */
    private function emptyLocation(string $tag): array
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Race '.$tag.' '.Uuid::v4()->toRfc4122());

        return [
            'warehouse' => $warehouse,
            'location' => $this->seedStorageLocation(self::TENANT_A, $warehouse, $tag.'-01'),
            'item' => $this->seedItem(self::TENANT_A, 'SKU-'.$tag.'-'.strtoupper(substr(Uuid::v4()->toRfc4122(), 0, 8))),
        ];
    }

    /** @return array{order: string, line: string, warehouse: string, item: string} an ordered purchase order with one line of 10 */
    private function orderedOrder(string $number): array
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme '.$number);
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.$number);
        $order = $this->seedOrder(self::TENANT_A, $number, $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED);
        $item = $this->seedItem(self::TENANT_A, 'SKU-'.$number);

        return ['order' => $order, 'line' => $this->seedLine(self::TENANT_A, $order, $item, lineNumber: 1, quantity: '10'), 'warehouse' => $warehouse, 'item' => $item];
    }

    private function receive(string $order, string $line, string $quantity, string $location): void
    {
        $this->send('POST', '/purchase-orders/'.$order.'/goods-receipts', ['lines' => [
            ['purchase_order_line_id' => $line, 'quantity' => $quantity, 'storage_location_id' => $location],
        ]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
    }

    private function returnInto(string $fulfilment, string $line, string $location): void
    {
        $this->send('POST', '/fulfilments/'.$fulfilment.'/sales-returns', ['reason' => 'other', 'lines' => [
            ['fulfilment_line_id' => $line, 'quantity' => '1', 'storage_location_id' => $location],
        ]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
    }

    /** @return list<array{pointer: string, code: string, message: string}> */
    private function errors(): array
    {
        return array_map(static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code'], 'message' => $e['message']], $this->json()['errors'] ?? []);
    }

    /** @return array{0: string, 1: list<string>} a stock row as the stock writers insert it (its FK takes the location `KEY SHARE`) */
    private function aLevelInsert(string $item, string $location, string $quantity): array
    {
        return ['INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $location, $quantity]];
    }

    /**
     * The delete's own statements — THE WRITER'S constants, never a transcription (`SupplierDeleteTest`'s reason): the parent
     * `FOR SHARE`, the location `FOR UPDATE`, the archive.
     *
     * @param array{warehouse: string, location: string} $s
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function theDeletesOwnStatements(array $s): array
    {
        return [
            [StorageLocationWriter::PARENT_LOCK, [$s['warehouse']]],
            [StorageLocationWriter::DELETE_LOCK, [$s['location'], $s['warehouse']]],
            [StorageLocationWriter::ARCHIVE, [$s['location']]],
        ];
    }

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertTheDeleteWaitedAndRefusedHeldStock(array $holder, string $location): void
    {
        self::assertTrue($holder['waiter_seen'], 'The delete never waited on the stock write: its location lock is not FOR UPDATE. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('storage_location.holds_stock', $this->json()['rule']);
        self::assertNull($this->stored($location)['deleted_at'], 'A location was archived while stock landed in it.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_DELETED));
    }

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertWaitedThenRefused(array $holder, int $status, string $rule, string $location): void
    {
        self::assertTrue($holder['waiter_seen'], 'The request never waited on the delete. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame($status, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        $rules = 422 === $status ? array_column($body['errors'] ?? [], 'code') : [$body['rule'] ?? null];
        self::assertSame([$rule], $rules, (string) $this->client()->getResponse()->getContent());
        self::assertSame('0.000000', $this->onHand($location), 'Stock landed in a location archived under it.');
    }

    /** §5.2.5's precedent: the re-reads are correct only under READ COMMITTED — asserted, not assumed. */
    private function assertTheRequestConnectionIsReadCommitted(): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        self::assertSame('read committed', $em->getConnection()->fetchOne('SHOW transaction_isolation'),
            'The request connection is not READ COMMITTED; the delete\'s stock question is only correct under it.');
        // The statement the delete asks is Inventory's own, read from the class (never transcribed here).
        self::assertStringContainsString('qty_on_hand <> 0 OR sl.qty_reserved <> 0', InventoryHeldStock::LOCATION);
    }

    /**
     * `SupplierDeleteTest::raceAgainstAHolder`'s shape, another copy (not extracted, the earlier copies' reason): the holder's
     * own lock is on a random `purchase_order` id, so it matches no row; the real locks are `HOLD_BEFORE`'s.
     *
     * @param list<array{0: string, 1: list<string|null>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_location_delete_');
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
