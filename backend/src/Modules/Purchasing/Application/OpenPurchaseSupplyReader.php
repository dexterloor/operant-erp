<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\Purchasing\Application\Contract\OpenPurchaseSupply;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;

/** The one implementation of {@see OpenPurchaseSupply} (remaining-ops G3-C1). As `erp_app`, under RLS. */
final readonly class OpenPurchaseSupplyReader implements OpenPurchaseSupply
{
    /**
     * S2 (design §2.3). **The status predicate is written as the partial index's own** — `status IN ('ordered',
     * 'partially_received')` with `deleted_at IS NULL`, literals, not parameters — so the planner can prove the predicate
     * implied and read `purchase_order_tenant_open_idx`. A `NOT IN (…)` or a bound parameter spelling the same set defeats
     * that implication (plan test P-1, mutation M-C1-5). No item list is passed, so no large array parameter.
     */
    public const ON_ORDER = 'SELECT pol.inventory_item_id::text AS item, SUM(GREATEST(0, '.PurchaseOrderLineRow::OUTSTANDING.'))::text AS on_order'
        .' FROM purchase_order po'
        .' JOIN purchase_order_line pol ON pol.tenant_id = po.tenant_id AND pol.purchase_order_id = po.id'
        ." WHERE po.deleted_at IS NULL AND po.status IN ('ordered', 'partially_received') AND pol.deleted_at IS NULL"
        .' GROUP BY pol.inventory_item_id';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    public function onOrderByItem(): array
    {
        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em): array {
            $supply = [];
            foreach ($em->getConnection()->fetchAllAssociative(self::ON_ORDER) as $row) {
                $quantity = bcadd((string) $row['on_order'], '0', 6);
                if (1 === bccomp($quantity, '0', 6)) {
                    $supply[(string) $row['item']] = $quantity;
                }
            }

            return $supply;
        });
    }
}
