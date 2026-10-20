<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Inventory\ApiResource\State\CreateStockAdjustmentProcessor;
use App\Modules\Inventory\ApiResource\State\CreateStockTransferProcessor;
use App\Modules\Inventory\Application\StockReceiver;
use App\Modules\Purchasing\Application\GoodsReceiptWriter;
use App\Modules\Purchasing\Application\WarehouseOpenPurchaseOrdersCount;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Sales\Application\FulfilmentWriter;
use App\Modules\Sales\Application\SalesReturnWriter;
use App\Modules\Sales\Application\WarehouseOpenSalesOrdersCount;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\Warehouses\Application\WarehouseWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use App\Tests\Architecture\WriteOwnershipTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Warehouse/location delete-restore C4 — `DeleteWarehouse`, `DELETE /warehouses/{id}`, `warehouse.delete`**
 * (`docs/session13/wh-loc-delete-design.md` §0, §2.1, §3.3, §7.1–§7.3, Revision 2; API-O-34 — the owner's note of
 * 2026-09-13: a warehouse with open orders or stock may not be archived; owner Q-2 (a) and Q-3, `wh-loc-delete-q-ruling.md`).
 * `DeleteSupplier`'s shape, API-21 branch 3.
 *
 * 204, no body, `deleted_at` set; the warehouse stays readable (`GetWarehouse`, `archived_at`) and listed on the Archived
 * view; **its locations are left as they are** (owner Q-2 (a): live, unusable while it is archived — W-6). Refusals, in
 * `UX` U-3's order: a foreign, absent or malformed id → the same 404; already archived → 409 `warehouse.is_archived`; a LIVE
 * purchase order in `draft`, `ordered` or `partially_received` → 409 `warehouse.has_open_purchase_orders`; a live sales order
 * in `draft`, `confirmed` or `partially_fulfilled` → 409 `warehouse.has_open_sales_orders`; stock in any of its locations,
 * live **or archived** → 409 `warehouse.holds_stock` naming how many locations, with the archived-item sentence when any of it
 * is an archived item's (G-51). Closed statuses and deleted drafts do not block; the last live warehouse can be archived
 * (N-2). One `warehouse.deleted`, `{}`, on success; none on any refusal. Each sentence pinned literally (`UX` U-1 C4).
 *
 * **W-6 / owner Q-2 (a), derived** ({@see self::anArchivedWarehouseHoldsNoStockAndItsBinsTakeNone()}): every caller of every
 * stock-writing contract — the callers come from `WriteOwnershipTest`'s registries, never from this file — is driven into a
 * LIVE location of an archived warehouse and must be refused in its own terms, with a positive control (the same drive, the
 * warehouse restored, succeeds). Outside this check: a future read that checks the bin alone (review).
 *
 * **The races (§7.1), real second connections (`hold_order_lock.php`), each failing if the request never waited.** Delete
 * first (the holder takes {@see WarehouseWriter::DELETE_LOCK} + {@see WarehouseWriter::ARCHIVE}, the writer's own
 * statements), the request under test waits on the warehouse `FOR KEY SHARE` C0 gave it and is refused: purchase order
 * create (R-W1) and move (R-W2) → 422 `unknown_reference`; sales order create (R-W3) and move (R-W4) → 422; a return into one
 * of its bins (R-W5) → 409 `warehouse.is_live`; a count up (R-W6) and a transfer in (R-W7) → 409 `warehouse.is_archived`; a
 * location create (R-W8), delete (R-W9) and restore (R-W10) → 409 `warehouse.is_archived`. Work first (the delete is the
 * request), the delete waits and counts it: an order created or moved in, stock landing — 409. **Mutations:** M10 (the
 * delete's `FOR NO KEY UPDATE`) reddens the work-first races; M0-1 / M0-2 (the order liveness reads unlocked) redden R-W1…R-W4;
 * M0-3 / M0-4b (the return path's warehouse locks) redden R-W5; M-W6 / M-W7 (the adjuster's / transferrer's warehouse lock)
 * redden R-W6 / R-W7 — in the delete-first order, where the writer is the request under test. Plus the two restore races
 * (C1 M4 and C2 L4 can go red here: an unlocked restore reads the row before the archive commits).
 */
final class WarehouseDeleteTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;

    private const HOLDER_DEADLINE_SECONDS = 10.0;

    /**
     * `writer-calling class` => the case method that drives it into a live location of an archived warehouse. The keys are
     * held equal, both ways, to the callers `WriteOwnershipTest::STOCK_WRITE_CONTRACTS` names for every contract whose
     * implementation writes stock ({@see self::theStockWriteCasesAreEveryCallerOfEveryStockWritingContract()}).
     */
    private const STOCK_WRITE_CASES = [
        GoodsReceiptWriter::class => 'aReceiptIntoTheArchivedWarehouse',
        FulfilmentWriter::class => 'aShipmentFromTheArchivedWarehouse',
        SalesReturnWriter::class => 'aReturnIntoTheArchivedWarehouse',
        CreateStockAdjustmentProcessor::class => 'aCountInTheArchivedWarehouse',
        CreateStockTransferProcessor::class => 'aTransferIntoTheArchivedWarehouse',
    ];

    /** An admin archives a warehouse with no open work and no stock: 204; readable, archived view; its locations untouched. */
    #[Test]
    public function anAdminArchivesAnIdleWarehouseAndItsLocationsAreLeftAsTheyAre(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Closing Site');
        $live = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-01');
        $ownArchived = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'L-02');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$ownArchived]);
        $this->actAsRole(DefaultRoles::ADMIN);

        $this->delete($warehouse);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('', (string) $this->client()->getResponse()->getContent());
        $stored = $this->stored($warehouse);
        self::assertNotNull($stored['deleted_at']);
        self::assertSame($stored['deleted_at'], $stored['updated_at']);
        $this->send('GET', '/warehouses/'.$warehouse);
        self::assertSame(200, $this->httpStatus());
        self::assertNotNull($this->json()['archived_at']);
        self::assertContains($warehouse, $this->listedIds('?archived=true'));
        self::assertNotContains($warehouse, $this->listedIds(''));
        self::assertNull($this->locationArchivedAt($live), 'Q-2 (a): a live location was archived with its warehouse.');
        self::assertNotNull($this->locationArchivedAt($ownArchived));

        $rows = $this->auditRowsOf(AuditEvent::WAREHOUSE_DELETED);
        self::assertCount(1, $rows);
        self::assertSame([$warehouse, 'warehouse', []], [$rows[0]['subject_id'], $rows[0]['subject_type'], json_decode((string) $rows[0]['metadata'], true)]);
    }

    /** E9-3 / Q-2 (a) end to end: restored, the warehouse and its untouched locations are usable again; archivable again. */
    #[Test]
    public function anArchivedWarehouseIsRestoredByTheOperationThatPairsWithIt(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Round Trip Site');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'RT-01');
        $this->actAsRole(DefaultRoles::OWNER);

        $this->delete($warehouse);
        self::assertSame(204, $this->httpStatus());
        $this->send('POST', '/warehouses/'.$warehouse.'/restoration');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNull($this->json()['archived_at']);
        self::assertNull($this->locationArchivedAt($location));
        $this->delete($warehouse);
        self::assertSame(204, $this->httpStatus(), 'A restored warehouse could not be archived again.');
    }

    /** ST-9: a repeated delete is refused, not replayed (§4.2 `Idem` `no`), and writes nothing. */
    #[Test]
    public function aRepeatedDeleteIsRefusedBecauseTheWarehouseIsArchived(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Twice Closed');
        $this->actAsRole(DefaultRoles::ADMIN);

        $this->delete($warehouse);
        self::assertSame(204, $this->httpStatus());
        $before = $this->stored($warehouse);

        $this->delete($warehouse);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('warehouse.is_archived', $this->json()['rule']);
        self::assertSame('This warehouse is already archived. It\'s in the archived warehouses.', $this->json()['detail']);
        self::assertSame(WarehouseWriter::ALREADY_ARCHIVED, $this->json()['detail']);
        self::assertSame($before, $this->stored($warehouse));
        self::assertCount(1, $this->auditRowsOf(AuditEvent::WAREHOUSE_DELETED));
    }

    /** ST-5: a foreign, absent or malformed id is the same 404 as `GetWarehouse`'s, and nothing is written. */
    #[Test]
    public function aDeleteOfAnUnknownWarehouseIsTheSame404(): void
    {
        $theirs = $this->seedWarehouse(self::TENANT_B, 'Their Site');
        $this->actAsRole(DefaultRoles::OWNER);
        $bodies = [];
        foreach ([$theirs, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->delete($id);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM warehouse WHERE id = ?::uuid AND deleted_at IS NOT NULL', [$theirs]));
        self::assertSame([], $this->auditRowsOf(AuditEvent::WAREHOUSE_DELETED));
    }

    /** API-O-34, first leg (mutation M11): each open purchase-order status blocks, counted, literal sentence. */
    #[Test]
    #[DataProvider('openPurchaseOrderStatuses')]
    public function aWarehouseWithAnOpenPurchaseOrderCannotBeArchived(string $status): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'PO '.$status);
        $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $this->seedSupplier(self::TENANT_A, 'Sup '.$status), $warehouse, status: $status);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->delete($warehouse);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('warehouse.has_open_purchase_orders', $this->json()['rule']);
        self::assertSame('This warehouse still has 1 open purchase order, so it can\'t be deleted until each open order is received in full or cancelled. A draft can be moved to another warehouse or simply deleted.',
            $this->json()['detail']);
        self::assertNull($this->stored($warehouse)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::WAREHOUSE_DELETED));
    }

    /** @return iterable<string, array{string}> */
    public static function openPurchaseOrderStatuses(): iterable
    {
        foreach ([PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_ORDERED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED] as $s) {
            yield $s => [$s];
        }
    }

    /** API-O-34, second leg (mutation M13): each open sales-order status blocks; two orders read in the plural. */
    #[Test]
    #[DataProvider('openSalesOrderStatuses')]
    public function aWarehouseWithAnOpenSalesOrderCannotBeArchived(string $status): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'SO '.$status);
        foreach ([1, 2] as $n) {
            $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), null, $warehouse, $status,
                SalesOrder::STATUS_DRAFT === $status ? null : '2026-09-20T10:00:00Z');
        }
        $this->actAsRole(DefaultRoles::OWNER);

        $this->delete($warehouse);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('warehouse.has_open_sales_orders', $this->json()['rule']);
        self::assertSame('This warehouse still has 2 open sales orders, so it can\'t be deleted until each open order is shipped in full or cancelled. A draft can be moved to another warehouse or simply deleted.',
            $this->json()['detail']);
        self::assertSame(\sprintf(WarehouseWriter::HAS_OPEN_SALES_ORDERS, WarehouseWriter::openSalesOrders(2)), $this->json()['detail']);
        self::assertNull($this->stored($warehouse)['deleted_at']);
    }

    /** @return iterable<string, array{string}> */
    public static function openSalesOrderStatuses(): iterable
    {
        foreach ([SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED] as $s) {
            yield $s => [$s];
        }
    }

    /** M12: closed orders (received, cancelled, fulfilled) and a DELETED draft do not block; the warehouse is archived. */
    #[Test]
    public function closedAndDeletedOrdersDoNotBlockArchiving(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Quiet Site');
        $supplier = $this->seedSupplier(self::TENANT_A, 'Quiet Supplier');
        $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse, status: PurchaseOrder::STATUS_RECEIVED);
        $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse, status: PurchaseOrder::STATUS_CANCELLED);
        $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse, archived: true);
        $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), null, $warehouse, SalesOrder::STATUS_FULFILLED, '2026-09-20T10:00:00Z');
        $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), null, $warehouse, SalesOrder::STATUS_CANCELLED, '2026-09-20T10:00:00Z');
        $deletedDraft = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), null, $warehouse);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$deletedDraft]);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->delete($warehouse);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** API-15: the open statuses are the entities' own constants, read by the Ports — never a literal list here. */
    #[Test]
    public function theOpenStatusesAreTheOrderModulesOwnConstants(): void
    {
        self::assertSame([PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_ORDERED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED], WarehouseOpenPurchaseOrdersCount::OPEN_STATUSES);
        self::assertSame([SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED], WarehouseOpenSalesOrdersCount::OPEN_STATUSES);
    }

    /**
     * API-O-34, third leg (mutation M14): stock in a LIVE location and in a location archived BY SQL both block; the sentence
     * counts locations (two here), never quantities; zero rows do not count.
     */
    #[Test]
    public function stockInAnyLocationLiveOrArchivedBlocksArchiving(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Stocked Site');
        $live = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'S-01');
        $archived = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'S-02');
        $empty = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'S-03');
        $item = $this->seedItem(self::TENANT_A, 'SKU-SITE-STOCK');
        $this->stockIn($item, $live, '1');
        $this->stockIn($item, $archived, '2');
        $this->stockIn($item, $empty, '0');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->delete($warehouse);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('warehouse.holds_stock', $this->json()['rule']);
        self::assertSame('This warehouse still holds stock in 2 locations, so it can\'t be deleted until all of it is moved to another warehouse or counted off.', $this->json()['detail']);
        self::assertNull($this->stored($warehouse)['deleted_at']);
    }

    /** M14's sharp edge: stock ONLY in a location archived by SQL still blocks, in the singular. */
    #[Test]
    public function stockOnlyInAnArchivedLocationStillBlocks(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Hidden Stock Site');
        $archived = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'H-01');
        $this->stockIn($this->seedItem(self::TENANT_A, 'SKU-HIDDEN'), $archived, '3');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->delete($warehouse);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('This warehouse still holds stock in 1 location, so it can\'t be deleted until all of it is moved to another warehouse or counted off.', $this->json()['detail']);
    }

    /** G-51 (`UX` U-4): an archived item's stock adds the sentence; the item is archived through `DeleteItem` (204). */
    #[Test]
    public function stockOfAnArchivedItemAddsTheSentence(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Archived Item Site');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'AI-01');
        $item = $this->seedItem(self::TENANT_A, 'SKU-SITE-ARCH');
        $this->stockIn($item, $location, '1');
        $this->actAsRole(DefaultRoles::OWNER);
        $this->send('DELETE', '/items/'.$item);
        self::assertSame(204, $this->httpStatus(), 'DeleteItem refused a stocked item: G-51\'s closing work has landed.');

        $this->delete($warehouse);

        self::assertSame(409, $this->httpStatus());
        self::assertSame(\sprintf(WarehouseWriter::HOLDS_STOCK, '1 location').' '.WarehouseWriter::ARCHIVED_ITEM_STOCK, $this->json()['detail']);
        self::assertSame('This warehouse still holds stock in 1 location, so it can\'t be deleted until all of it is moved to another warehouse or counted off. '
            .'Stock of an archived item can be moved or counted off only once that item is restored.', $this->json()['detail']);
    }

    /** `UX` U-3 (mutation M16): open purchase orders are named before open sales orders, and both before stock. */
    #[Test]
    public function theRefusalNamesPurchaseOrdersThenSalesOrdersThenStock(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Busy Site');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'B-01');
        $this->stockIn($this->seedItem(self::TENANT_A, 'SKU-BUSY'), $location, '1');
        $so = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), null, $warehouse);
        $po = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $this->seedSupplier(self::TENANT_A, 'Busy Supplier'), $warehouse);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->delete($warehouse);
        self::assertSame('warehouse.has_open_purchase_orders', $this->json()['rule'] ?? null, (string) $this->client()->getResponse()->getContent());
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE purchase_order SET status = 'cancelled', ordered_at = NULL WHERE id = ?::uuid", [$po]);
        $this->delete($warehouse);
        self::assertSame('warehouse.has_open_sales_orders', $this->json()['rule'] ?? null, (string) $this->client()->getResponse()->getContent());
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$so]);
        $this->delete($warehouse);
        self::assertSame('warehouse.holds_stock', $this->json()['rule'] ?? null, (string) $this->client()->getResponse()->getContent());
    }

    /** N-2 (`PO` confirmed): the last live warehouse of the workspace can be archived. */
    #[Test]
    public function theLastLiveWarehouseCanBeArchived(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Only Site');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id <> ?::uuid AND deleted_at IS NULL', [$warehouse]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM warehouse WHERE deleted_at IS NULL'), 'Fixture: one live warehouse.');
        $this->actAsRole(DefaultRoles::OWNER);

        $this->delete($warehouse);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM warehouse WHERE deleted_at IS NULL'));
    }

    /** G-43: an issued (cancelled) purchase order keeps showing the archived warehouse's name. */
    #[Test]
    public function anIssuedOrderStillShowsTheArchivedWarehousesName(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Remembered Site');
        $po = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $this->seedSupplier(self::TENANT_A, 'Remembered Supplier'), $warehouse, status: PurchaseOrder::STATUS_CANCELLED);
        $this->actAsRole(DefaultRoles::OWNER);
        $this->delete($warehouse);
        self::assertSame(204, $this->httpStatus());

        $this->send('GET', '/purchase-orders/'.$po);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('Remembered Site', $this->json()['warehouse']['name'], 'The archived warehouse\'s name vanished from an issued order.');
    }

    /** Only `warehouse.delete` (owner, `admin`) may archive: `warehouse_operator` (`.edit`, no `.delete`) and `viewer` → 403. */
    #[Test]
    public function onlyAHolderOfWarehouseDeleteMayArchive(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Guarded Site');
        foreach ([DefaultRoles::WAREHOUSE_OPERATOR, DefaultRoles::VIEWER] as $role) {
            $this->actAsRole($role);
            $this->delete($warehouse);
            self::assertSame(403, $this->httpStatus(), $role);
        }
        self::assertNull($this->stored($warehouse)['deleted_at']);
    }

    // ---- W-6: an archived warehouse holds no stock, and its live locations take none ------------------------------

    /**
     * **The case map is every caller of every stock-writing contract** (design Revision 2 C4, L-1). Union = the keys of
     * `WriteOwnershipTest::STOCK_LEVEL_RELEASE_DECISIONS` ∪ every `writers` of `STOCK_MOVEMENT_REASONS` (read by reflection,
     * never copied); the callers = every `callers` of a `STOCK_WRITE_CONTRACTS` entry whose `implementation` is in the union;
     * asserted equal, both ways, to {@see self::STOCK_WRITE_CASES}' keys. Positive control: the union holds `StockReceiver`.
     * Mutations M-W8′ (a real `src/` class added to the case map) and M-W9 (a case removed) → red here.
     */
    #[Test]
    public function theStockWriteCasesAreEveryCallerOfEveryStockWritingContract(): void
    {
        $union = array_keys(self::registry('STOCK_LEVEL_RELEASE_DECISIONS'));
        foreach (self::registry('STOCK_MOVEMENT_REASONS') as $reason) {
            array_push($union, ...$reason['writers']);
        }
        $union = array_values(array_unique($union));
        self::assertContains(StockReceiver::class, $union, 'Positive control: the stock-writer union lost StockReceiver, so it is not being read.');

        $callers = [];
        foreach (self::registry('STOCK_WRITE_CONTRACTS') as $contract) {
            if (\in_array($contract['implementation'], $union, true)) {
                array_push($callers, ...$contract['callers']);
            }
        }
        $callers = array_values(array_unique($callers));
        sort($callers);
        $cases = array_keys(self::STOCK_WRITE_CASES);
        sort($cases);

        self::assertSame($callers, $cases,
            'W-6 (ARCHITECTURE.md §5.5): every caller of every stock-writing contract must prove here that it refuses a live '
            .'location of an archived warehouse. A caller missing from STOCK_WRITE_CASES has never been driven; a case whose '
            .'class calls no stock writer guards nothing.');
    }

    /**
     * **W-6, driven** (owner Q-2 (a); design Revision 2 C4 M-4): each caller writes into a LIVE location of an archived
     * warehouse and is refused with its exact status and rule; no `stock_level` row of that warehouse changes; and the
     * identical drive with the warehouse restored (`deleted_at = NULL`, as owner) succeeds — the positive control. The count,
     * the transfer and the return archive through `DeleteWarehouse` (204 asserted); the receipt and the shipment archive by
     * owner SQL, because API-O-34 refuses an archive while the open order they need exists.
     */
    #[Test]
    #[DataProvider('stockWriteCallers')]
    public function anArchivedWarehouseHoldsNoStockAndItsBinsTakeNone(string $caller): void
    {
        $this->{self::STOCK_WRITE_CASES[$caller]}();
    }

    /** @return iterable<string, array{string}> */
    public static function stockWriteCallers(): iterable
    {
        foreach (array_keys(self::STOCK_WRITE_CASES) as $caller) {
            yield substr((string) strrchr($caller, '\\'), 1) => [$caller];
        }
    }

    private function aReceiptIntoTheArchivedWarehouse(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'W6 Receipt');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'W6-R');
        $order = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $this->seedSupplier(self::TENANT_A, 'W6 Supplier'), $warehouse, status: PurchaseOrder::STATUS_ORDERED);
        $line = $this->seedLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-W6-R'), lineNumber: 1, quantity: '5');
        $this->archiveBySql($warehouse);
        $this->actAsRole(DefaultRoles::OWNER);

        $drive = fn () => $this->send('POST', '/purchase-orders/'.$order.'/goods-receipts', ['lines' => [
            ['purchase_order_line_id' => $line, 'quantity' => '1', 'storage_location_id' => $bin],
        ]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        $this->assertRefusedThenAllowedOnceRestored($warehouse, $drive, 409, 'warehouse.is_live', 201);
    }

    private function aShipmentFromTheArchivedWarehouse(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'W6 Shipment');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'W6-F');
        $item = $this->seedItem(self::TENANT_A, 'SKU-W6-F');
        $order = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), null, $warehouse, SalesOrder::STATUS_CONFIRMED, '2026-09-20T10:00:00Z');
        $line = $this->seedSalesOrderLine(self::TENANT_A, $order, $item, lineNumber: 1, quantity: '5', unitPrice: '10.0000');
        $this->stockIn($item, $bin, '5');
        $this->archiveBySql($warehouse);
        $this->actAsRole(DefaultRoles::OWNER);

        $drive = fn () => $this->send('POST', '/sales-orders/'.$order.'/fulfilments', ['lines' => [
            ['sales_order_line_id' => $line, 'quantity' => '1', 'storage_location_id' => $bin],
        ]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        $this->assertRefusedThenAllowedOnceRestored($warehouse, $drive, 409, 'warehouse.is_live', 201);
    }

    /** N-1 too: the returner's closed-warehouse sentence, pinned literally (`UX` U-2, the C1 row whose pin lands here). */
    private function aReturnIntoTheArchivedWarehouse(): void
    {
        $s = $this->shipped('W6-RET', [['1', '10.0000', '1']]);
        $warehouse = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT warehouse_id::text AS w FROM fulfilment WHERE id = ?::uuid', [$s['fulfilment']])[0]['w'];
        $line = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id::text AS id, storage_location_id::text AS bin FROM fulfilment_line WHERE fulfilment_id = ?::uuid', [$s['fulfilment']])[0];
        $this->actAsRole(DefaultRoles::OWNER);
        $this->delete($warehouse);
        self::assertSame(204, $this->httpStatus(), 'Fixture: a warehouse whose only order shipped in full and whose stock left could not be archived. '
            .(string) $this->client()->getResponse()->getContent());

        $drive = fn () => $this->send('POST', '/fulfilments/'.$s['fulfilment'].'/sales-returns', ['reason' => 'other', 'lines' => [
            ['fulfilment_line_id' => $line['id'], 'quantity' => '1', 'storage_location_id' => $line['bin']],
        ]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        $drive();
        self::assertSame('The warehouse these goods were shipped from is archived, so this return can\'t be recorded until the warehouse is restored.',
            $this->json()['detail'] ?? null, (string) $this->client()->getResponse()->getContent());
        $this->assertRefusedThenAllowedOnceRestored($warehouse, $drive, 409, 'warehouse.is_live', 201);
    }

    private function aCountInTheArchivedWarehouse(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'W6 Count');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'W6-C');
        $item = $this->seedItem(self::TENANT_A, 'SKU-W6-C');
        $this->actAsRole(DefaultRoles::OWNER);
        $this->delete($warehouse);
        self::assertSame(204, $this->httpStatus());

        $drive = fn () => $this->send('POST', '/stock-movements/adjustments', ['inventory_item_id' => $item, 'storage_location_id' => $bin,
            'counted_quantity' => '4', 'version' => null, 'note' => 'Found'], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        $this->assertRefusedThenAllowedOnceRestored($warehouse, $drive, 409, 'warehouse.is_archived', 201);
    }

    private function aTransferIntoTheArchivedWarehouse(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'W6 Transfer');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'W6-T');
        $source = $this->seedStorageLocation(self::TENANT_A, $this->seedWarehouse(self::TENANT_A, 'W6 Transfer Source'), 'SRC-01');
        $item = $this->seedItem(self::TENANT_A, 'SKU-W6-T');
        $this->stockIn($item, $source, '5');
        $this->actAsRole(DefaultRoles::OWNER);
        $this->delete($warehouse);
        self::assertSame(204, $this->httpStatus());

        $drive = fn () => $this->send('POST', '/stock-movements/transfers', ['inventory_item_id' => $item, 'source_storage_location_id' => $source,
            'destination_storage_location_id' => $bin, 'quantity' => '2'], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        $this->assertRefusedThenAllowedOnceRestored($warehouse, $drive, 409, 'warehouse.is_archived', 201);
    }

    // ---- races: the delete first, the writer is the request under test ---------------------------------------------

    /** R-W1 (M0-1): a purchase order created into a warehouse being archived waits on its `FOR KEY SHARE` and is refused. */
    #[Test]
    public function aPurchaseOrderCreatedIntoAWarehouseBeingArchivedIsRefused(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'RW1');
        $supplier = $this->seedSupplier(self::TENANT_A, 'RW1 Supplier');
        $this->actAsRole(DefaultRoles::OWNER);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($warehouse), function () use ($supplier, $warehouse): void {
            $this->assertTheRequestConnectionIsReadCommitted();
            $this->send('POST', '/purchase-orders', ['supplier_id' => $supplier, 'warehouse_id' => $warehouse],
                [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedThenRefusedOnTheWarehouseField($holder);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM purchase_order WHERE warehouse_id = ?::uuid', [$warehouse]),
            'A purchase order landed on a warehouse archived under it.');
    }

    /** R-W2 (M0-1): a draft moved onto a warehouse being archived waits and is refused. */
    #[Test]
    public function aPurchaseOrderDraftMovedOntoAWarehouseBeingArchivedIsRefused(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, self::freshNumber('PO'));
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'RW2');
        $this->actAsRole(DefaultRoles::OWNER);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($warehouse), function () use ($draft, $warehouse): void {
            $this->send('PATCH', '/purchase-orders/'.$draft['order'], ['warehouse_id' => $warehouse]);
        });

        $this->assertWaitedThenRefusedOnTheWarehouseField($holder);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM purchase_order WHERE warehouse_id = ?::uuid', [$warehouse]));
    }

    /** R-W3 (M0-2): a sales order created into a warehouse being archived waits and is refused. */
    #[Test]
    public function aSalesOrderCreatedIntoAWarehouseBeingArchivedIsRefused(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'RW3');
        $customer = $this->seedCustomer(self::TENANT_A, 'RW3 Customer');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);
        $this->actAsRole(DefaultRoles::OWNER);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($warehouse), function () use ($customer, $address, $warehouse): void {
            $this->send('POST', '/sales-orders', ['customer_id' => $customer, 'warehouse_id' => $warehouse, 'currency_code' => 'EUR',
                'ship_to_address_id' => $address], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedThenRefusedOnTheWarehouseField($holder);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM sales_order WHERE warehouse_id = ?::uuid', [$warehouse]));
    }

    /** R-W4 (M0-2): a sales draft moved onto a warehouse being archived waits and is refused. */
    #[Test]
    public function aSalesOrderDraftMovedOntoAWarehouseBeingArchivedIsRefused(): void
    {
        $draft = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'));
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'RW4');
        $this->actAsRole(DefaultRoles::OWNER);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($warehouse), function () use ($draft, $warehouse): void {
            $this->send('PATCH', '/sales-orders/'.$draft, ['warehouse_id' => $warehouse]);
        });

        $this->assertWaitedThenRefusedOnTheWarehouseField($holder);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM sales_order WHERE warehouse_id = ?::uuid', [$warehouse]));
    }

    /** R-W5 (M0-3, M0-4b): a return into one of its bins waits on the return path's warehouse lock and is refused. */
    #[Test]
    public function aReturnIntoAWarehouseBeingArchivedIsRefused(): void
    {
        $s = $this->shipped('RW5', [['5', '10.0000', '4']]);
        $warehouse = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT warehouse_id::text AS w FROM fulfilment WHERE id = ?::uuid', [$s['fulfilment']])[0]['w'];
        $line = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id::text AS id, storage_location_id::text AS bin FROM fulfilment_line WHERE fulfilment_id = ?::uuid', [$s['fulfilment']])[0];
        $before = $this->onHandIn($warehouse);
        $this->actAsRole(DefaultRoles::OWNER);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($warehouse), function () use ($s, $line): void {
            $this->send('POST', '/fulfilments/'.$s['fulfilment'].'/sales-returns', ['reason' => 'other', 'lines' => [
                ['fulfilment_line_id' => $line['id'], 'quantity' => '1', 'storage_location_id' => $line['bin']],
            ]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedThenRefused($holder, 409, 'warehouse.is_live');
        self::assertSame($before, $this->onHandIn($warehouse), 'Returned stock landed in a warehouse archived under it.');
    }

    /** R-W6 (M-W6): a count up in one of its bins waits on the adjuster's warehouse `FOR KEY SHARE` and is refused. */
    #[Test]
    public function aCountInAWarehouseBeingArchivedIsRefused(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'RW6');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'RW6-01');
        $item = $this->seedItem(self::TENANT_A, 'SKU-RW6');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($warehouse), function () use ($item, $bin): void {
            $this->send('POST', '/stock-movements/adjustments', ['inventory_item_id' => $item, 'storage_location_id' => $bin,
                'counted_quantity' => '3', 'version' => null, 'note' => 'Found'], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedThenRefused($holder, 409, 'warehouse.is_archived');
        self::assertSame('0.000000', $this->onHandIn($warehouse));
    }

    /** R-W7 (M-W7): a transfer into the warehouse from another waits on the transferrer's warehouse lock and is refused. */
    #[Test]
    public function aTransferIntoAWarehouseBeingArchivedIsRefused(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'RW7');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'RW7-01');
        $source = $this->seedStorageLocation(self::TENANT_A, $this->seedWarehouse(self::TENANT_A, 'RW7 Source'), 'RW7-SRC');
        $item = $this->seedItem(self::TENANT_A, 'SKU-RW7');
        $this->stockIn($item, $source, '5');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($warehouse), function () use ($item, $source, $bin): void {
            $this->send('POST', '/stock-movements/transfers', ['inventory_item_id' => $item, 'source_storage_location_id' => $source,
                'destination_storage_location_id' => $bin, 'quantity' => '2'], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedThenRefused($holder, 409, 'warehouse.is_archived');
        self::assertSame('0.000000', $this->onHandIn($warehouse));
    }

    /** R-W8: a location added to a warehouse being archived waits on the parent `FOR SHARE` and is refused. */
    #[Test]
    public function aLocationAddedToAWarehouseBeingArchivedIsRefused(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'RW8');
        $this->actAsRole(DefaultRoles::OWNER);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($warehouse), function () use ($warehouse): void {
            $this->send('POST', '/warehouses/'.$warehouse.'/locations', ['code' => 'RW8-NEW'], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedThenRefused($holder, 409, 'warehouse.is_archived');
    }

    /** R-W9: a location deleted while its warehouse is being archived waits and is refused (Q-2 (a): left as it was). */
    #[Test]
    public function aLocationDeletedWhileItsWarehouseIsBeingArchivedIsRefused(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'RW9');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'RW9-01');
        $this->actAsRole(DefaultRoles::OWNER);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($warehouse), function () use ($warehouse, $bin): void {
            $this->send('DELETE', '/warehouses/'.$warehouse.'/locations/'.$bin);
        });

        $this->assertWaitedThenRefused($holder, 409, 'warehouse.is_archived');
        self::assertNull($this->locationArchivedAt($bin));
    }

    /** R-W10 (C2 L7): a location restored while its warehouse is being archived waits on the parent `FOR SHARE` and is refused. */
    #[Test]
    public function aLocationRestoredWhileItsWarehouseIsBeingArchivedIsRefused(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'RW10');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'RW10-01');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$bin]);
        $this->actAsRole(DefaultRoles::OWNER);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($warehouse), function () use ($warehouse, $bin): void {
            $this->send('POST', '/warehouses/'.$warehouse.'/locations/'.$bin.'/restoration');
        });

        $this->assertWaitedThenRefused($holder, 409, 'warehouse.is_archived');
        self::assertNotNull($this->locationArchivedAt($bin), 'A location was restored into a warehouse archived under it.');
    }

    /**
     * The warehouse restore's own lock (C1 M4): a restore of a warehouse being archived waits on its `FOR NO KEY UPDATE`, then
     * finds it archived and restores it — 200. Unlocked, it reads the row before the archive commits and answers "not archived".
     */
    #[Test]
    public function aRestoreOfAWarehouseBeingArchivedWaitsAndRestoresIt(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'RW-REST');
        $this->actAsRole(DefaultRoles::OWNER);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($warehouse), function () use ($warehouse): void {
            $this->send('POST', '/warehouses/'.$warehouse.'/restoration');
        });

        self::assertTrue($holder['waiter_seen'], 'The restore never waited on the archive. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNull($this->stored($warehouse)['deleted_at']);
    }

    /** The location restore's own lock (C2 L4), the same shape: a location being archived is waited for, then restored. */
    #[Test]
    public function aRestoreOfALocationBeingArchivedWaitsAndRestoresIt(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'RL-REST');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'RL-REST-01');
        $this->actAsRole(DefaultRoles::OWNER);

        $holder = $this->raceAgainstAHolder([
            ['SELECT deleted_at IS NOT NULL AS archived FROM warehouse WHERE id = ?::uuid FOR SHARE', [$warehouse]],
            ['SELECT 1 FROM storage_location WHERE id = ?::uuid FOR UPDATE', [$bin]],
            ['UPDATE storage_location SET deleted_at = now(), updated_at = now() WHERE id = ?::uuid', [$bin]],
        ], function () use ($warehouse, $bin): void {
            $this->send('POST', '/warehouses/'.$warehouse.'/locations/'.$bin.'/restoration');
        });

        self::assertTrue($holder['waiter_seen'], 'The location restore never waited on the archive. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNull($this->locationArchivedAt($bin));
    }

    // ---- races: the work first, the delete is the request under test (M10) ------------------------------------------

    /** An order created first: its warehouse `FOR KEY SHARE` and insert make the delete wait; the delete then counts it. */
    #[Test]
    public function aDeleteRacingAnOrderCreateCountsTheOrder(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'RW-PO-FIRST');
        $supplier = $this->seedSupplier(self::TENANT_A, 'RW PO First');
        $this->actAsRole(DefaultRoles::OWNER);

        $holder = $this->raceAgainstAHolder([
            ['SELECT 1 FROM warehouse WHERE id = ?::uuid AND deleted_at IS NULL FOR KEY SHARE', [$warehouse]],
            ["INSERT INTO purchase_order (id, tenant_id, order_number, supplier_id, warehouse_id, status, currency_code, idempotency_key, request_digest)
              VALUES (?::uuid, ?::uuid, ?, ?::uuid, ?::uuid, 'draft', 'EUR', encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))",
                [Uuid::v4()->toRfc4122(), self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse]],
        ], function () use ($warehouse): void {
            $this->assertTheRequestConnectionIsReadCommitted();
            $this->delete($warehouse);
        });

        $this->assertTheDeleteWaitedAndRefused($holder, $warehouse, 'warehouse.has_open_purchase_orders');
    }

    /** A sales draft moved in first: the delete waits on the move's warehouse lock, then counts it. */
    #[Test]
    public function aDeleteRacingASalesDraftMovedInCountsIt(): void
    {
        $draft = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'));
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'RW-SO-FIRST');
        $this->actAsRole(DefaultRoles::OWNER);

        $holder = $this->raceAgainstAHolder([
            ['SELECT 1 FROM sales_order WHERE id = ?::uuid FOR NO KEY UPDATE', [$draft]],
            ['SELECT 1 FROM warehouse WHERE id = ?::uuid AND deleted_at IS NULL FOR KEY SHARE', [$warehouse]],
            ['UPDATE sales_order SET warehouse_id = ?::uuid, updated_at = now() WHERE id = ?::uuid', [$warehouse, $draft]],
        ], function () use ($warehouse): void {
            $this->delete($warehouse);
        });

        $this->assertTheDeleteWaitedAndRefused($holder, $warehouse, 'warehouse.has_open_sales_orders');
    }

    /** Stock landing first (a count's warehouse and bin `KEY SHARE`, then the level): the delete waits and sees it. */
    #[Test]
    public function aDeleteRacingStockLandingSeesTheStock(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'RW-STOCK-FIRST');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'RWS-01');
        $item = $this->seedItem(self::TENANT_A, 'SKU-RWS');
        $this->actAsRole(DefaultRoles::OWNER);

        $holder = $this->raceAgainstAHolder([
            ['SELECT deleted_at IS NOT NULL FROM warehouse WHERE id = ?::uuid FOR KEY SHARE', [$warehouse]],
            ['SELECT kind FROM storage_location WHERE id = ?::uuid FOR KEY SHARE', [$bin]],
            ['INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 3)',
                [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin]],
        ], function () use ($warehouse): void {
            $this->delete($warehouse);
        });

        $this->assertTheDeleteWaitedAndRefused($holder, $warehouse, 'warehouse.holds_stock');
    }

    // -----------------------------------------------------------------------

    private function delete(string $warehouse): void
    {
        $this->send('DELETE', '/warehouses/'.$warehouse);
    }

    /** @return array{deleted_at: ?string, updated_at: string} */
    private function stored(string $warehouse): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at::text AS deleted_at, updated_at::text AS updated_at FROM warehouse WHERE id = ?::uuid', [$warehouse])[0];
    }

    private function locationArchivedAt(string $location): ?string
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at::text AS d FROM storage_location WHERE id = ?::uuid', [$location])[0]['d'];
    }

    /** @return list<string> */
    private function listedIds(string $query): array
    {
        $this->send('GET', '/warehouses'.$query);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return array_column($this->json()['items'], 'id');
    }

    private function stockIn(string $item, string $location, string $quantity): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $location, $quantity]);
    }

    private function onHandIn(string $warehouse): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT COALESCE(SUM(sl.qty_on_hand), 0)::numeric(19,6)::text AS q FROM stock_level sl JOIN storage_location l ON l.id = sl.storage_location_id WHERE l.warehouse_id = ?::uuid',
            [$warehouse])[0]['q'];
    }

    private function archiveBySql(string $warehouse): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$warehouse]);
    }

    /**
     * The W-6 case's three asserts: refused with this status and rule; the warehouse's stock unchanged; the same drive with the
     * warehouse restored (as owner) succeeds.
     */
    private function assertRefusedThenAllowedOnceRestored(string $warehouse, callable $drive, int $status, string $rule, int $allowed): void
    {
        $before = $this->onHandIn($warehouse);
        $drive();
        self::assertSame($status, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($rule, $this->json()['rule'] ?? null, (string) $this->client()->getResponse()->getContent());
        self::assertSame($before, $this->onHandIn($warehouse), 'Stock moved in a warehouse that is archived.');

        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = NULL WHERE id = ?::uuid', [$warehouse]);
        $drive();
        self::assertSame($allowed, $this->httpStatus(), 'Positive control: the same drive with the warehouse restored was refused too, so the '
            .'refusal above proves nothing about the archive. '.(string) $this->client()->getResponse()->getContent());
    }

    /** @return array<mixed> one of `WriteOwnershipTest`'s private registries, by reflection — never a copy */
    private static function registry(string $name): array
    {
        $value = (new \ReflectionClassConstant(WriteOwnershipTest::class, $name))->getValue();
        self::assertIsArray($value);

        return $value;
    }

    /** @return list<array{0: string, 1: list<string>}> the delete's own statements — the writer's constants */
    private function theDeletesOwnStatements(string $warehouse): array
    {
        return [[WarehouseWriter::DELETE_LOCK, [$warehouse]], [WarehouseWriter::ARCHIVE, [$warehouse]]];
    }

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertWaitedThenRefusedOnTheWarehouseField(array $holder): void
    {
        self::assertTrue($holder['waiter_seen'], 'The order write never waited on the archive: its warehouse read is not FOR KEY SHARE. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '/warehouse_id', 'code' => 'unknown_reference']],
            array_map(static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors'] ?? []));
    }

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertWaitedThenRefused(array $holder, int $status, string $rule): void
    {
        self::assertTrue($holder['waiter_seen'], 'The request never waited on the archive. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame($status, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($rule, $this->json()['rule'] ?? null, (string) $this->client()->getResponse()->getContent());
    }

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertTheDeleteWaitedAndRefused(array $holder, string $warehouse, string $rule): void
    {
        self::assertTrue($holder['waiter_seen'], 'The delete never waited on the work: its warehouse lock is not FOR UPDATE. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($rule, $this->json()['rule']);
        self::assertNull($this->stored($warehouse)['deleted_at'], 'A warehouse was archived while work landed in it.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::WAREHOUSE_DELETED));
    }

    private function assertTheRequestConnectionIsReadCommitted(): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof \Doctrine\ORM\EntityManagerInterface);
        self::assertSame('read committed', $em->getConnection()->fetchOne('SHOW transaction_isolation'),
            'The request connection is not READ COMMITTED; the delete\'s counts and the writers\' re-reads are only correct under it.');
    }

    /**
     * `SupplierDeleteTest::raceAgainstAHolder`'s shape, another copy (the earlier copies' reason).
     *
     * @param list<array{0: string, 1: list<string|null>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_warehouse_delete_');
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
