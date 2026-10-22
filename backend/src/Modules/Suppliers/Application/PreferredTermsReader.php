<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application;

use App\Modules\Suppliers\Application\Contract\PreferredTerm;
use App\Modules\Suppliers\Application\Contract\PreferredTerms;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;

/** The one implementation of {@see PreferredTerms} (remaining-ops G3-C1). As `erp_app`, under RLS on both tables. */
final readonly class PreferredTermsReader implements PreferredTerms
{
    /** S3 (design §2.3): the pairs as two parallel `uuid[]`, unnested; the supplier, then its entry on the unique key. */
    public const TERMS = 'SELECT p.item_id::text AS item, s.id::text AS supplier, s.name, s.deleted_at IS NOT NULL AS archived,'
        .' sp.unit_cost::text AS unit_cost, sp.currency_code, sp.lead_time_days, sp.min_order_quantity::text AS min_order_quantity'
        .' FROM unnest(?::uuid[], ?::uuid[]) AS p(item_id, supplier_id)'
        .' JOIN supplier s ON s.id = p.supplier_id'
        .' LEFT JOIN supplier_product sp ON sp.tenant_id = s.tenant_id AND sp.supplier_id = s.id AND sp.inventory_item_id = p.item_id';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    public function termsFor(array $supplierIdByItemId): array
    {
        if ([] === $supplierIdByItemId) {
            return [];
        }

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($supplierIdByItemId): array {
            $rows = $em->getConnection()->fetchAllAssociative(self::TERMS, [
                '{'.implode(',', array_keys($supplierIdByItemId)).'}',
                '{'.implode(',', array_values($supplierIdByItemId)).'}',
            ]);

            $terms = [];
            foreach ($rows as $row) {
                $terms[(string) $row['item']] = new PreferredTerm(
                    (string) $row['supplier'],
                    (string) $row['name'],
                    (bool) $row['archived'],
                    null === $row['unit_cost'] ? null : (string) $row['unit_cost'],
                    null === $row['currency_code'] ? null : (string) $row['currency_code'],
                    null === $row['lead_time_days'] ? null : (int) $row['lead_time_days'],
                    null === $row['min_order_quantity'] ? null : (string) $row['min_order_quantity'],
                );
            }

            return $terms;
        });
    }
}
