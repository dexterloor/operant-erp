<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Inventory\ApiResource\State\StockLevelCollectionProvider;
use App\Modules\Inventory\ApiResource\StockLevelResource;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * **Stock reads C1 — `ListStockLevels`**, `GET /api/v1/stock`, `stock_level.view`, *(bounded)*, exactly one of
 * `storage_location_id`, `inventory_item_id` (`docs/session13/stock-reads-design.md` §2.1, §4.7 and Revision 1; owner ruling
 * Q1 of 2026-10-14; `SEC` / `UX` / `DATA` / `PO` role files of the same date).
 *
 * Pins:
 *  - the `/stock` path is exactly {@see self::PINNED_STOCK_LEVEL_OPERATIONS}, joined into
 *    `AuthOperationContractTest::theShippedSurfaceIsExactlyTheOperationsThisWaveHasPinned`'s union;
 *  - API-19b's exactly-one scope: neither, both → 422 `exactly_one_scope` on both pointers with `UX`'s copy; a malformed id
 *    → 422; an undeclared parameter alone is named as such (the generic refusal comes first); a non-holder is 403 before
 *    any of it (R-M6);
 *  - the literal body, whole (`assertSame` on the decoded row — two agreeing analyses are one analysis): no
 *    `qty_reserved`, no `qty_available` (Q1); `warehouse`'s keys are exactly `id`, `name` (PC-9's fourth exception);
 *  - rows at 0, in an archived item, in quarantine and archived bins and in a bin of an archived warehouse are SERVED
 *    (unfiltered joins; G-47), and an archived bin or item is scoped like a live one;
 *  - an unknown and another workspace's id are byte-equal empty pages; `tenant_has_rows` tells an empty workspace from an
 *    empty scope (M13);
 *  - `version` is the row's and moves when a receipt posts into the bin;
 *  - a holder of `stock_level.view` alone still gets the three display references (PC-15, and PC-9's fourth exception).
 */
final class StockLevelReadTest extends PurchaseOrderTestCase
{
    /**
     * The `/stock` operations, held equal to the registry.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_STOCK_LEVEL_OPERATIONS = [
        'ListStockLevels' => ['method' => 'GET', 'path' => '/stock', 'idem' => false],
    ];

    /** `StockLevelResource`'s serialized members — §4.4.3's StockLevel row (owner ruling Q1). */
    public const PINNED_STOCK_LEVEL_FIELDS = ['id', 'inventory_item', 'storage_location', 'warehouse', 'qty_on_hand', 'version'];

    #[Test]
    public function theStockPathDeclaresExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $path = (string) $operation->getUriTemplate();
            if (1 === preg_match('#^/stock(/|$|\?)#', $path)) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $path];
            }
        }
        ksort($declared);
        $pinned = array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_STOCK_LEVEL_OPERATIONS);
        ksort($pinned);

        self::assertSame($pinned, $declared);
    }

    /** Independent of `docs/`: the DTO's serialized members, in order, and the inline schema is exactly them. */
    #[Test]
    public function theWireSchemaIsTheResourcesOwnMembers(): void
    {
        $members = [];
        foreach (new \ReflectionClass(StockLevelResource::class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $attribute = new \ReflectionProperty(StockLevelResource::class, $parameter->getName())->getAttributes(SerializedName::class)[0] ?? null;
            $members[] = null === $attribute ? $parameter->getName() : $attribute->newInstance()->serializedName;
        }

        self::assertSame(self::PINNED_STOCK_LEVEL_FIELDS, $members);
        self::assertSame($members, array_keys(StockLevelResource::WIRE_SCHEMA['properties']));
        self::assertSame($members, StockLevelResource::WIRE_SCHEMA['required']);
        self::assertSame(['id', 'name'], array_keys(StockLevelResource::WIRE_SCHEMA['properties']['warehouse']['properties']));
        self::assertSame('integer', StockLevelResource::WIRE_SCHEMA['properties']['version']['type']);
    }

    /**
     * API-19b's third clause (`API.md` :3561): neither scope, or both, is refused — never a flat list, never an AND of the
     * two. `UX`'s copy is pinned by literal on one pointer per variant (`stock-reads-ux.md` §4).
     */
    #[Test]
    public function neitherOrBothScopesAreRefused(): void
    {
        $this->send('GET', '/stock');
        $this->assertScopeRefusal(StockLevelCollectionProvider::NEITHER_SCOPE);
        self::assertSame('storage_location_id or inventory_item_id is required: stock is listed for one bin or one item.',
            $this->json()['errors'][0]['message']);

        $this->send('GET', '/stock?storage_location_id='.Uuid::v4()->toRfc4122().'&inventory_item_id='.Uuid::v4()->toRfc4122());
        $this->assertScopeRefusal(StockLevelCollectionProvider::BOTH_SCOPES);
        self::assertSame('Send storage_location_id or inventory_item_id, not both: stock is listed for one bin or one item.',
            $this->json()['errors'][1]['message']);

        // An empty value is no scope.
        $this->send('GET', '/stock?storage_location_id=');
        $this->assertScopeRefusal(StockLevelCollectionProvider::NEITHER_SCOPE);
    }

    /** T-3 / T-4: a malformed id is a 422; an undeclared parameter alone is named as undeclared, before the scope rule. */
    #[Test]
    public function aMalformedScopeAndAnUndeclaredParameterAreRefusedByTheirOwnRules(): void
    {
        $this->send('GET', '/stock?storage_location_id=not-a-uuid');
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNotContains('exactly_one_scope', array_column($this->json()['errors'] ?? [], 'code'));

        $this->send('GET', '/stock?foo=1');
        self::assertSame(422, $this->httpStatus());
        self::assertContains(['pointer' => '?foo', 'code' => 'undeclared_parameter'],
            array_map(static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors']));

        foreach (['limit=1', 'after=x', 'before=x', 'sort=sku', 'q=A'] as $query) {
            $this->send('GET', '/stock?storage_location_id='.Uuid::v4()->toRfc4122().'&'.$query);
            self::assertSame(422, $this->httpStatus(), $query);
        }
    }

    /** R-M6: the gate refuses before the provider runs, so a non-holder learns nothing, scope or none. */
    #[Test]
    public function aCallerWithoutTheCodeIsRefusedBeforeTheScopeRule(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::FINANCE));

        $bodies = [];
        foreach (['/stock', '/stock?storage_location_id='.Uuid::v4()->toRfc4122(), '/stock?inventory_item_id='.Uuid::v4()->toRfc4122()] as $path) {
            $this->send('GET', $path);
            self::assertSame(403, $this->httpStatus(), $path);
            self::assertSame('urn:erp:problem:permission-denied', $this->json()['type'], $path);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[1], $bodies[2]);
    }

    /**
     * A bin's stock: exactly its rows, by item `sku`, including a row at 0 and an archived item's row, and nothing from
     * another bin or workspace. The body is compared WHOLE.
     */
    #[Test]
    public function aBinsStockIsListedWholeInSkuOrder(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Stock Main');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'A-01');
        $otherBin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'A-02');
        $b = $this->seedItem(self::TENANT_A, 'SKU-STK-B');
        $a = $this->seedItem(self::TENANT_A, 'SKU-STK-A');
        $gone = $this->seedItem(self::TENANT_A, 'SKU-STK-C');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid', [$gone]);

        $rowB = $this->level($b, $bin, '5');
        $rowA = $this->level($a, $bin, '0');
        $rowGone = $this->level($gone, $bin, '2.5');
        $this->level($a, $otherBin, '9');
        $foreignWarehouse = $this->seedWarehouse(self::TENANT_B, 'Theirs');
        $this->level($this->seedItem(self::TENANT_B, 'SKU-STK-A'), $this->seedStorageLocation(self::TENANT_B, $foreignWarehouse, 'A-01'), '7', self::TENANT_B);

        $this->send('GET', '/stock?storage_location_id='.$bin);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $warehouseRef = ['id' => $warehouse, 'name' => 'Stock Main'];
        $binRef = ['id' => $bin, 'code' => 'A-01'];
        self::assertSame([
            'items' => [
                ['id' => $rowA, 'inventory_item' => ['id' => $a, 'sku' => 'SKU-STK-A', 'name' => 'SKU-STK-A'], 'storage_location' => $binRef, 'warehouse' => $warehouseRef, 'qty_on_hand' => '0.000000', 'version' => 1],
                ['id' => $rowB, 'inventory_item' => ['id' => $b, 'sku' => 'SKU-STK-B', 'name' => 'SKU-STK-B'], 'storage_location' => $binRef, 'warehouse' => $warehouseRef, 'qty_on_hand' => '5.000000', 'version' => 1],
                ['id' => $rowGone, 'inventory_item' => ['id' => $gone, 'sku' => 'SKU-STK-C', 'name' => 'SKU-STK-C'], 'storage_location' => $binRef, 'warehouse' => $warehouseRef, 'qty_on_hand' => '2.500000', 'version' => 1],
            ],
            'next_cursor' => null,
            'previous_cursor' => null,
            'tenant_has_rows' => true,
        ], $this->json());
        foreach ($this->json()['items'] as $row) {
            $keys = array_keys($row['warehouse']);
            sort($keys);
            self::assertSame(['id', 'name'], $keys, 'PC-9\'s fourth exception is bounded to exactly id and name.');
        }
    }

    /**
     * An item's stock: every bin holding it, by bin `code`, each with its own warehouse — a quarantine bin, an archived
     * bin and a bin of an ARCHIVED warehouse included (unfiltered joins; `SEC` §1.5 Edit B; G-47). M6/M6b/M6c.
     */
    #[Test]
    public function anItemsStockIsListedInEveryBinWithItsWarehouse(): void
    {
        $main = $this->seedWarehouse(self::TENANT_A, 'Stock North');
        $closed = $this->seedWarehouse(self::TENANT_A, 'Stock Closed');
        $shelf = $this->seedStorageLocation(self::TENANT_A, $main, 'C-01');
        $held = $this->seedStorageLocation(self::TENANT_A, $main, 'A-01', 'quarantine');
        $retired = $this->seedStorageLocation(self::TENANT_A, $main, 'B-01');
        $inClosed = $this->seedStorageLocation(self::TENANT_A, $closed, 'D-01');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$retired]);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$closed]);
        $item = $this->seedItem(self::TENANT_A, 'SKU-STK-ITEM');
        $other = $this->seedItem(self::TENANT_A, 'SKU-STK-OTHER');

        $rows = [
            $this->level($item, $held, '1'),
            $this->level($item, $retired, '2'),
            $this->level($item, $shelf, '3'),
            $this->level($item, $inClosed, '4'),
        ];
        $this->level($other, $shelf, '8');

        $this->send('GET', '/stock?inventory_item_id='.$item);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $items = $this->json()['items'];
        self::assertSame($rows, array_column($items, 'id'), 'Not every bin holding the item, in code order.');
        self::assertSame(['A-01', 'B-01', 'C-01', 'D-01'], array_map(static fn (array $r): string => $r['storage_location']['code'], $items));
        self::assertSame(
            [['id' => $main, 'name' => 'Stock North'], ['id' => $main, 'name' => 'Stock North'], ['id' => $main, 'name' => 'Stock North'], ['id' => $closed, 'name' => 'Stock Closed']],
            array_column($items, 'warehouse'),
        );
        self::assertSame(['1.000000', '2.000000', '3.000000', '4.000000'], array_column($items, 'qty_on_hand'));
        foreach ($items as $row) {
            self::assertArrayNotHasKey('qty_reserved', $row);
            self::assertArrayNotHasKey('qty_available', $row);
        }
    }

    /** R-H3: an archived bin and an archived item are scoped like live ones — their rows are served. */
    #[Test]
    public function anArchivedBinOrItemIsScopedLikeALiveOne(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Stock Archive');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'A-09');
        $item = $this->seedItem(self::TENANT_A, 'SKU-STK-ARCH');
        $row = $this->level($item, $bin, '3');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$bin]);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid', [$item]);

        $this->send('GET', '/stock?storage_location_id='.$bin);
        self::assertSame([$row], array_column($this->json()['items'], 'id'));

        $this->send('GET', '/stock?inventory_item_id='.$item);
        self::assertSame([$row], array_column($this->json()['items'], 'id'));
    }

    /** No existence oracle: an unknown and another workspace's bin or item answer byte-equal empty pages (V2-C-11). */
    #[Test]
    public function anUnknownAndAForeignScopeAreTheSameEmptyPage(): void
    {
        $foreignWarehouse = $this->seedWarehouse(self::TENANT_B, 'Stock Theirs');
        $foreignBin = $this->seedStorageLocation(self::TENANT_B, $foreignWarehouse, 'A-01');
        $foreignItem = $this->seedItem(self::TENANT_B, 'SKU-STK-THEIRS');
        $this->level($foreignItem, $foreignBin, '6', self::TENANT_B);
        // A row of A's own, so both pages carry `tenant_has_rows: true` and the two bodies can be compared.
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Stock Mine');
        $this->level($this->seedItem(self::TENANT_A, 'SKU-STK-MINE'), $this->seedStorageLocation(self::TENANT_A, $warehouse, 'A-01'), '1');

        foreach (['storage_location_id' => $foreignBin, 'inventory_item_id' => $foreignItem] as $scope => $foreign) {
            $this->send('GET', '/stock?'.$scope.'='.$foreign);
            self::assertSame(200, $this->httpStatus());
            $theirs = (string) $this->client()->getResponse()->getContent();
            $this->send('GET', '/stock?'.$scope.'='.Uuid::v4()->toRfc4122());
            self::assertSame(200, $this->httpStatus());
            self::assertSame($theirs, (string) $this->client()->getResponse()->getContent(), $scope);
            self::assertSame(['items' => [], 'next_cursor' => null, 'previous_cursor' => null, 'tenant_has_rows' => true], $this->json());
        }
    }

    /** API-22 / M13: an empty scope says whether the workspace holds any stock row at all. */
    #[Test]
    public function anEmptyScopeSaysWhetherTheWorkspaceHoldsAnyStock(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Stock Empty');
        $emptyBin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'A-01');

        $this->send('GET', '/stock?storage_location_id='.$emptyBin);
        self::assertSame([[], false], [$this->json()['items'], $this->json()['tenant_has_rows']]);

        $this->level($this->seedItem(self::TENANT_A, 'SKU-STK-ELSEWHERE'), $this->seedStorageLocation(self::TENANT_A, $warehouse, 'A-02'), '1');
        $this->send('GET', '/stock?storage_location_id='.$emptyBin);
        self::assertSame([[], true], [$this->json()['items'], $this->json()['tenant_has_rows']]);
    }

    /** `version` is the row's own, as an integer, and it moves when a goods receipt posts into the bin (S-12, ST-6). */
    #[Test]
    public function theVersionIsTheRowsAndMovesWhenStockIsReceived(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Stock Receiving');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'A-01');
        $item = $this->seedItem(self::TENANT_A, 'SKU-STK-RCV');
        $this->level($item, $bin, '1');
        $order = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $this->seedSupplier(self::TENANT_A, 'Stock Supplier'), $warehouse,
            status: PurchaseOrder::STATUS_ORDERED);
        $line = $this->seedLine(self::TENANT_A, $order, $item);

        $this->send('GET', '/stock?storage_location_id='.$bin);
        $before = $this->json()['items'][0];
        self::assertSame(1, $before['version']);
        self::assertSame((int) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT version FROM stock_level WHERE id = ?::uuid', [$before['id']])[0]['version'], $before['version']);

        $this->send('POST', '/purchase-orders/'.$order.'/goods-receipts',
            ['lines' => [['purchase_order_line_id' => $line, 'quantity' => '2', 'storage_location_id' => $bin]]],
            ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('GET', '/stock?storage_location_id='.$bin);
        $after = $this->json()['items'][0];
        self::assertSame($before['id'], $after['id']);
        self::assertSame('3.000000', $after['qty_on_hand']);
        self::assertGreaterThan($before['version'], $after['version']);
        self::assertSame((int) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT version FROM stock_level WHERE id = ?::uuid', [$after['id']])[0]['version'], $after['version']);
    }

    /**
     * PC-15 (and PC-9's fourth exception for `warehouse`): a holder of `stock_level.view` and NOTHING else still gets the
     * item, the bin and the warehouse named — the references are display, they grant no access.
     */
    #[Test]
    public function aHolderOfTheStockCodeAloneStillSeesTheThreeReferences(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Stock Display');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'A-01');
        $item = $this->seedItem(self::TENANT_A, 'SKU-STK-DISPLAY');
        $this->level($item, $bin, '4');
        $this->actAs(self::TENANT_A, ['stock_level.view']);

        $this->send('GET', '/stock?inventory_item_id='.$item);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $row = $this->json()['items'][0];
        self::assertSame(['id' => $item, 'sku' => 'SKU-STK-DISPLAY', 'name' => 'SKU-STK-DISPLAY'], $row['inventory_item']);
        self::assertSame(['id' => $bin, 'code' => 'A-01'], $row['storage_location']);
        self::assertSame(['id' => $warehouse, 'name' => 'Stock Display'], $row['warehouse']);
    }

    /** `SECURITY_MODEL.md` §4: every default role holding `stock_level.view` reads it; `finance` does not hold it. */
    #[Test]
    public function everyDefaultHolderOfTheCodeReadsIt(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Stock Roles');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'A-01');
        $this->level($this->seedItem(self::TENANT_A, 'SKU-STK-ROLE'), $bin, '1');

        $holders = 0;
        foreach (DefaultRoles::names() as $role) {
            $codes = DefaultRoles::codesFor($role);
            $this->actAs(self::TENANT_A, $codes);
            $this->send('GET', '/stock?storage_location_id='.$bin);
            if (\in_array('stock_level.view', $codes, true)) {
                ++$holders;
                self::assertSame(200, $this->httpStatus(), $role);
                self::assertContains('warehouse.view', $codes, $role.' holds stock_level.view without warehouse.view (SEC §1.1 derived the opposite).');
            } else {
                self::assertSame(403, $this->httpStatus(), $role);
            }
        }
        self::assertGreaterThanOrEqual(7, $holders, 'Fewer default roles hold stock_level.view than SEC §1.1 derived.');
    }

    private function assertScopeRefusal(string $message): void
    {
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        self::assertSame([
            ['pointer' => '?storage_location_id', 'code' => 'exactly_one_scope', 'message' => $message],
            ['pointer' => '?inventory_item_id', 'code' => 'exactly_one_scope', 'message' => $message],
        ], $this->json()['errors']);
    }

    /** A stock row, as the owner (no `src/` path inserts one but the receipt and return writers). @return string its id */
    private function level(string $item, string $bin, string $quantity, string $tenant = self::TENANT_A): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenant,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [$id, $tenant, $item, $bin, $quantity]);

        return $id;
    }
}
