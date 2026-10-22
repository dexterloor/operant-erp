<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Application\Contract\PickableBin;
use App\Modules\Inventory\Application\Contract\PickableStock;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one implementation of `PickableStock`. It reads `storage_location` and
 * `warehouse`, tables `Modules\Warehouses` owns — the accepted A-2 residual
 * `StockReceiver` and `StockDepleter` already carry — so the rule is written
 * where the stock is.
 *
 * **The pickable predicate is written ONCE, here** (remaining-ops G3-C1, 2026-10-22; design §2.3): {@see self::PICKABLE_FROM}
 * and {@see self::PICKABLE_WHERE} are the joins and the predicate over a `stock_level sl` row, and both this pick read and
 * `ReorderStockReader`'s on-hand total are built from them. `ReorderSuggestionTest::theCountedBinsAreThePickableBins` holds
 * the two readers to one definition by behaviour; the constants hold them by text.
 */
final readonly class PickableStockReader implements PickableStock
{
    /** The bin and its warehouse, joined to a `stock_level sl` row by tenant and id. */
    public const PICKABLE_FROM = <<<'SQL'
        JOIN storage_location loc ON loc.tenant_id = sl.tenant_id AND loc.id = sl.storage_location_id
        JOIN warehouse w ON w.tenant_id = loc.tenant_id AND w.id = loc.warehouse_id
        SQL;

    /** Owner ruling R2: a live location of a live warehouse whose kind is not {@see PickableStock::NEVER_PICKABLE_KIND}. */
    public const PICKABLE_WHERE = "w.deleted_at IS NULL AND loc.deleted_at IS NULL AND loc.kind <> '".PickableStock::NEVER_PICKABLE_KIND."'";

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    public function candidateBins(string $warehouseId, array $inventoryItemIds): array
    {
        if ([] === $inventoryItemIds) {
            return [];
        }

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($warehouseId, $inventoryItemIds): array {
            $rows = $em->getConnection()->fetchAllAssociative(
                'SELECT sl.inventory_item_id::text AS item, loc.id::text AS location, loc.code, sl.qty_on_hand::text AS qty_on_hand'
                .' FROM stock_level sl '.self::PICKABLE_FROM
                .' WHERE loc.warehouse_id = ?::uuid AND '.self::PICKABLE_WHERE
                .' AND sl.qty_on_hand > 0 AND sl.inventory_item_id = ANY(?::uuid[])'
                .' ORDER BY sl.inventory_item_id, loc.code, loc.id',
                [$warehouseId, '{'.implode(',', $inventoryItemIds).'}']);

            $bins = [];
            foreach ($rows as $row) {
                $bins[(string) $row['item']][] = new PickableBin((string) $row['location'], (string) $row['code'], (string) $row['qty_on_hand']);
            }

            return $bins;
        });
    }
}
