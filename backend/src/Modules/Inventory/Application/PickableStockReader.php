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
 */
final readonly class PickableStockReader implements PickableStock
{
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
            $rows = $em->getConnection()->fetchAllAssociative(<<<'SQL'
                SELECT sl.inventory_item_id::text AS item, loc.id::text AS location, loc.code, sl.qty_on_hand::text AS qty_on_hand
                  FROM stock_level sl
                  JOIN storage_location loc ON loc.tenant_id = sl.tenant_id AND loc.id = sl.storage_location_id
                  JOIN warehouse w ON w.tenant_id = loc.tenant_id AND w.id = loc.warehouse_id
                 WHERE loc.warehouse_id = ?::uuid
                   AND w.deleted_at IS NULL
                   AND loc.deleted_at IS NULL
                   AND loc.kind <> ?
                   AND sl.qty_on_hand > 0
                   AND sl.inventory_item_id = ANY(?::uuid[])
                 ORDER BY sl.inventory_item_id, loc.code, loc.id
                SQL, [$warehouseId, PickableStock::NEVER_PICKABLE_KIND, '{'.implode(',', $inventoryItemIds).'}']);

            $bins = [];
            foreach ($rows as $row) {
                $bins[(string) $row['item']][] = new PickableBin((string) $row['location'], (string) $row['code'], (string) $row['qty_on_hand']);
            }

            return $bins;
        });
    }
}
