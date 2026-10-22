<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Inventory\Application\ItemWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **G-59's closing work** (G5-C2, 2026-10-22 — the bulk archival is G-59's hard trigger; RED `g5-c2-red.md` M-4). The item
 * liveness reads of the purchase-order line, sales-order line and supplier-catalogue writers took no lock, so a line or
 * entry written while an item was being archived landed naming the just-archived item. They now read the item
 * `FOR KEY SHARE`, which waits on the archive's `FOR UPDATE` (`ItemWriter::DELETE_LOCK`, which `DeleteItem` and the bulk
 * item member both take), and the re-checked `deleted_at IS NULL` then refuses it as an unknown reference.
 *
 * Each case: connection A runs `DeleteItem`'s own statements, uncommitted; the write is sent on connection B; it must WAIT on
 * the item (the holder sees it blocked) and then answer 422 `unknown_reference`. *Mutation:* drop `FOR KEY SHARE` from a
 * writer → the request never waits on the holder and lands the row (201) → red.
 */
final class ItemLivenessRaceTest extends PurchaseOrderTestCase
{
    use BulkRaceHolder;

    #[Test]
    public function aPurchaseOrderLineNamingAnItemBeingArchivedWaitsAndIsRefused(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, self::freshNumber('PO'));

        $holder = $this->raceAgainstTheArchive($draft['item'], function () use ($draft): void {
            $this->send('POST', '/purchase-orders/'.$draft['order'].'/lines', ['inventory_item_id' => $draft['item'], 'quantity' => '1', 'unit_cost' => '1'],
                [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedAndRefused($holder, '/inventory_item_id');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM purchase_order_line WHERE purchase_order_id = ?::uuid', [$draft['order']]));
    }

    #[Test]
    public function aSalesOrderLineNamingAnItemBeingArchivedWaitsAndIsRefused(): void
    {
        $order = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'));
        $item = $this->seedItem(self::TENANT_A, 'LIVE-SO');

        $holder = $this->raceAgainstTheArchive($item, function () use ($order, $item): void {
            $this->send('POST', '/sales-orders/'.$order.'/lines', ['inventory_item_id' => $item, 'quantity' => '1', 'unit_price' => '1'],
                [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedAndRefused($holder, '/inventory_item_id');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM sales_order_line WHERE sales_order_id = ?::uuid', [$order]));
    }

    #[Test]
    public function aCatalogueEntryNamingAnItemBeingArchivedWaitsAndIsRefused(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Sup LIVE');
        $item = $this->seedItem(self::TENANT_A, 'LIVE-CAT');

        $holder = $this->raceAgainstTheArchive($item, function () use ($supplier, $item): void {
            $this->send('POST', '/suppliers/'.$supplier.'/products', [
                'inventory_item_id' => $item, 'supplier_sku' => 'LIVE-1', 'unit_cost' => '1', 'lead_time_days' => 3,
            ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedAndRefused($holder, '/inventory_item_id');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM supplier_product WHERE supplier_id = ?::uuid', [$supplier]));
    }

    /** @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} */
    private function raceAgainstTheArchive(string $item, callable $request): array
    {
        return $this->raceAgainstAHolder([[ItemWriter::DELETE_LOCK, [$item]], [ItemWriter::ARCHIVE, [$item]]], $request);
    }

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertWaitedAndRefused(array $holder, string $pointerSuffix): void
    {
        self::assertTrue($holder['waiter_seen'], 'The write never waited on the archive, so it read the item unlocked (G-59). '.json_encode($holder).' response: '.$this->httpStatus().' '.$this->responseContent());
        self::assertTrue($holder['committed'], json_encode($holder));
        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        $codes = array_map(static fn (array $e): string => $e['pointer'].' '.$e['code'], $this->json()['errors']);
        self::assertNotSame([], array_filter($codes, static fn (string $c): bool => str_ends_with(explode(' ', $c)[0], $pointerSuffix) && str_ends_with($c, 'unknown_reference')),
            'Expected the archived item refused as an unknown reference: '.implode(', ', $codes));
    }
}
