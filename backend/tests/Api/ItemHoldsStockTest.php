<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Inventory\Application\ItemRow;
use App\Modules\Inventory\Application\ItemWriter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **BK-27 — an item read says whether the item still holds stock** (`docs/design/owed-to-backend.md` BK-27; items batch
 * C2, `docs/session13/items-batch-design.md` "Revision 1 — FINAL"; `SEC` `docs/session13/items-batch-sec-arch.md` S-1 as
 * corrected by its RED M-1). The web app's delete dialog warns from it (`RESIDUAL_GAPS.md` G-51's interim).
 *
 * `holds_stock` is {@see ItemRow::HOLDS_STOCK}: any `stock_level` row of the item with `qty_on_hand <> 0`, in any location
 * of any kind. Held here:
 *  - true for stock held only in a QUARANTINE bin (so it is not "available": G-47), false for a zero row and for no row;
 *  - true on an ARCHIVED item, read through `ListItems?archived=true` (G-51's case);
 *  - the same answer on `GetItem`, `ListItems`, the `UpdateItem` response and the `RestoreItem` response (one `ItemRow`);
 *  - the item EXPORT's header names the column and its cell carries the same answer (`SEC` RED M-1: pinned on purpose,
 *    not by accident of the header being derived from `ItemResource`);
 *  - {@see ItemWriter::STOCKED} — the bulk refusal's read — contains {@see ItemRow::HOLDS_STOCK} verbatim. Behaviour
 *    cannot hold that: two copies that agree today pass every behaviour test, and a copy that drifts by one character
 *    (`<> 0` → `> 0`) passes them too, because no writer leaves a negative on-hand. So the containment is a text pin.
 */
final class ItemHoldsStockTest extends PurchaseOrderTestCase
{
    use ExportTestSupport;

    #[Test]
    public function stockOnlyInAQuarantineBinStillCounts(): void
    {
        $item = $this->item('HS-Q');
        $this->stock($item, $this->bin('quarantine'), '3');

        self::assertTrue($this->getHoldsStock($item),
            'Stock held only in a quarantine bin reads as no stock: holds_stock is "any stock", not "available" (G-47).');
    }

    #[Test]
    public function aZeroStockRowAndNoRowBothReadFalse(): void
    {
        $zero = $this->item('HS-Z');
        $this->stock($zero, $this->bin('bin'), '0');
        $none = $this->item('HS-N');

        self::assertFalse($this->getHoldsStock($zero), 'A stock row at zero on-hand reads as holding stock.');
        self::assertFalse($this->getHoldsStock($none), 'An item with no stock row reads as holding stock.');
    }

    #[Test]
    public function anArchivedItemsStockIsReadThroughTheArchivedView(): void
    {
        $sku = 'HS-A-'.$this->suffix();
        $item = $this->seedItem(self::TENANT_A, $sku);
        $this->stock($item, $this->bin('bin'), '5');
        $this->send('DELETE', '/items/'.$item);
        self::assertSame(204, $this->httpStatus(), 'Fixture: the single delete no longer archives a stocked item (G-51 moved).');

        $this->send('GET', '/items?'.http_build_query(['archived' => 'true', 'q' => $sku]));
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $rows = array_values(array_filter($this->json()['items'], static fn (array $r): bool => $r['id'] === $item));
        self::assertCount(1, $rows, 'Fixture: the archived item is not in the Archived view.');
        self::assertTrue($rows[0]['holds_stock'], 'An archived item\'s stock is not reported: the delete dialog\'s G-51 case reads false.');
    }

    #[Test]
    public function getListUpdateAndRestoreGiveTheSameAnswer(): void
    {
        $sku = 'HS-S-'.$this->suffix();
        $stocked = $this->seedItem(self::TENANT_A, $sku);
        $this->stock($stocked, $this->bin('bin'), '2');
        $emptySku = 'HS-E-'.$this->suffix();
        $empty = $this->seedItem(self::TENANT_A, $emptySku);

        foreach ([[$stocked, $sku, true], [$empty, $emptySku, false]] as [$id, $itemSku, $expected]) {
            self::assertSame($expected, $this->getHoldsStock($id), 'GetItem');

            $this->send('GET', '/items?'.http_build_query(['q' => $itemSku]));
            self::assertSame(200, $this->httpStatus());
            $rows = array_values(array_filter($this->json()['items'], static fn (array $r): bool => $r['id'] === $id));
            self::assertCount(1, $rows, 'Fixture: the item is not on its own List page.');
            self::assertSame($expected, $rows[0]['holds_stock'], 'ListItems disagrees with GetItem.');

            $this->send('PATCH', '/items/'.$id, ['name' => 'Renamed '.$itemSku]);
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            self::assertSame($expected, $this->json()['holds_stock'], 'The UpdateItem response disagrees with GetItem.');

            $this->send('DELETE', '/items/'.$id);
            self::assertSame(204, $this->httpStatus());
            $this->send('POST', '/items/'.$id.'/restoration');
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            self::assertSame($expected, $this->json()['holds_stock'], 'The RestoreItem response disagrees with GetItem.');
        }
    }

    #[Test]
    public function theItemExportCarriesTheColumnWithTheSameAnswer(): void
    {
        $stocked = $this->item('HS-X');
        $this->stock($stocked, $this->bin('bin'), '1');
        $empty = $this->item('HS-Y');

        $this->export(['resource' => 'inventory_item']);
        $rows = $this->exportRows();
        $header = array_shift($rows);
        self::assertContains('holds_stock', $header,
            'The item export no longer carries holds_stock (PC-9\'s sixth exception names CreateExport as a serving operation).');
        $idColumn = array_search('id', $header, true);
        $column = array_search('holds_stock', $header, true);
        self::assertIsInt($idColumn, 'Control: the item export has no id column to find the seeded rows by.');
        $cells = [];
        foreach ($rows as $row) {
            $cells[$row[$idColumn]] = $row[$column];
        }
        self::assertSame('true', $cells[$stocked] ?? null, 'The export says a stocked item holds no stock.');
        self::assertSame('false', $cells[$empty] ?? null, 'The export says an item with no stock holds stock.');
    }

    #[Test]
    public function theBulkRefusalReadsTheSamePredicateAsTheItemRead(): void
    {
        self::assertStringContainsString(ItemRow::HOLDS_STOCK, ItemWriter::STOCKED,
            'ItemWriter::STOCKED no longer embeds ItemRow::HOLDS_STOCK: the bulk refusal and the holds_stock an item read serves '
            .'are now two copies of one predicate, which every behaviour test passes while they agree, and many when they do not.');
        self::assertStringContainsString(ItemRow::HOLDS_STOCK, ItemRow::COLUMNS, 'ItemRow::COLUMNS no longer serves ItemRow::HOLDS_STOCK.');
        self::assertStringContainsString('qty_on_hand <> 0', ItemRow::HOLDS_STOCK, 'HOLDS_STOCK no longer means "any non-zero on-hand".');
    }

    private function getHoldsStock(string $item): bool
    {
        $this->send('GET', '/items/'.$item);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertArrayHasKey('holds_stock', $body, 'GetItem does not serve holds_stock.');
        self::assertIsBool($body['holds_stock'], 'holds_stock is not a boolean.');

        return $body['holds_stock'];
    }

    private function item(string $prefix): string
    {
        return $this->seedItem(self::TENANT_A, $prefix.'-'.$this->suffix());
    }

    private function bin(string $kind): string
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH HS '.$this->suffix());

        return $this->seedStorageLocation(self::TENANT_A, $warehouse, 'HS-'.strtoupper($kind).'-'.$this->suffix(), $kind);
    }

    private function stock(string $item, string $bin, string $qty): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin, $qty]);
    }

    private function suffix(): string
    {
        return strtoupper(str_replace('-', '', Uuid::v4()->toRfc4122()));
    }
}
