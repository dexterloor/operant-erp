<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Application\Contract\ManagedItem;
use App\Modules\Inventory\Application\Contract\ReorderStock;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one implementation of {@see ReorderStock} (remaining-ops G3-C1). Like {@see PickableStockReader} it reads
 * `storage_location` and `warehouse`, tables `Modules\Warehouses` owns — the accepted A-2 residual the stock readers carry —
 * and it reads them through that reader's fragment, never a copy. Runs as `erp_app` under RLS on every table.
 */
final readonly class ReorderStockReader implements ReorderStock
{
    /**
     * S1 (design §2.3): every managed item with its pickable on-hand total, in ONE aggregate pass over the tenant's stock —
     * the stock is grouped once and joined, never probed per item (plan test P-2 asserts no `SubPlan`). Public so the plan
     * test explains the statement this reader issues.
     */
    public const MANAGED_ITEMS = 'SELECT i.id::text AS id, i.sku, i.name, i.reorder_point::text AS reorder_point,'
        .' i.reorder_quantity::text AS reorder_quantity, i.supplier_id::text AS supplier_id, s.on_hand::text AS on_hand'
        .' FROM inventory_item i'
        .' LEFT JOIN (SELECT sl.tenant_id, sl.inventory_item_id, SUM(sl.qty_on_hand) AS on_hand'
        .' FROM stock_level sl '.PickableStockReader::PICKABLE_FROM
        .' WHERE '.PickableStockReader::PICKABLE_WHERE
        .' GROUP BY sl.tenant_id, sl.inventory_item_id) s ON s.tenant_id = i.tenant_id AND s.inventory_item_id = i.id'
        .' WHERE i.deleted_at IS NULL AND (i.reorder_point > 0 OR i.reorder_quantity > 0)'
        .' ORDER BY i.sku, i.id';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    public function managedItems(): array
    {
        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em): array {
            $items = [];
            foreach ($em->getConnection()->fetchAllAssociative(self::MANAGED_ITEMS) as $row) {
                $items[] = new ManagedItem(
                    (string) $row['id'],
                    (string) $row['sku'],
                    (string) $row['name'],
                    (string) $row['reorder_point'],
                    (string) $row['reorder_quantity'],
                    null === $row['supplier_id'] ? null : (string) $row['supplier_id'],
                    // A sum of numeric(19,6) is scale 6 but unbounded in width; rendered here, never cast in SQL (a cast to
                    // numeric(19,6) could overflow and 500). No pickable stock is zero.
                    bcadd(null === $row['on_hand'] ? '0' : (string) $row['on_hand'], '0', 6),
                );
            }

            return $items;
        });
    }

    public function tenantHasItems(): bool
    {
        return $this->tenants->run($this->tenant->tenantId(), static fn (EntityManagerInterface $em): bool =>
            false !== $em->getConnection()->fetchOne('SELECT 1 FROM inventory_item LIMIT 1'));
    }
}
