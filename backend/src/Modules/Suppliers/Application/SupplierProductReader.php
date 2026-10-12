<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application;

use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`ListSupplierProducts`** (suppliers slice 1 C3; `docs/session13/suppliers-s1-design.md` FINAL F-4 and §5.1;
 * `CustomerAddressReader`'s shape), the row load behind `UpdateSupplierProduct`, and the read-back the writer shares — as
 * {@see SupplierProductView}s.
 *
 * **Loaded whole, for now** (owner Q-S3 (b), 2026-10-12, "Accept all"): §4.2 marks the list *(bounded)*, so no `limit`,
 * `after`, `before` or `sort` is accepted and the whole catalogue comes back ordered `supplier_sku, id`. That overrides
 * `IA.md` §3.1 (*"the terms section … is **not** a bounded sub-list"*) and breaches TB-1c for a large catalogue — recorded
 * as `RESIDUAL_GAPS.md` G-42, with final RED L-F's two costs (a written `UX` statement overridden; adding paging later
 * truncates silently for a client that relied on the whole set).
 *
 * **The parent resolves first and includes an archived supplier** (§5.2a: reading an archived row's data is the direct-hit
 * case); an unknown or another workspace's supplier is null, the caller's 404. **One entry is always named by the PAIR
 * `(supplier, product)`** ({@see self::readInside()}): another supplier's entry under this path is the same 404 as a
 * random id (ST-5).
 *
 * The item reference is a raw-SQL join on `inventory_item` (no PHP edge to Inventory — `deptrac.yaml` lets
 * `SuppliersApplication` reach no `InventoryContract`), **archived items included**: a reference names the row it was
 * made against (PC-15 condition 3), and an archived item stays named. Every statement runs under `TenantContext::run()` on
 * the entity manager's own connection (`erp_app`, under RLS), never a `Doctrine\DBAL\Connection` (A-10).
 */
final readonly class SupplierProductReader
{
    private const SELECT = <<<'SQL'
        SELECT sp.id, sp.inventory_item_id, i.sku AS item_sku, i.name AS item_name, sp.supplier_sku, sp.unit_cost,
               sp.lead_time_days, sp.min_order_quantity, sp.currency_code
          FROM supplier_product sp
          JOIN inventory_item i ON i.tenant_id = sp.tenant_id AND i.id = sp.inventory_item_id
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /** @return ?list<SupplierProductView> null when the supplier is not visible (404); archived suppliers included */
    public function list(string $supplierId): ?array
    {
        if (!Uuid::isValid($supplierId)) {
            return null;
        }
        $supplierId = Uuid::fromString($supplierId)->toRfc4122();

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($supplierId): ?array {
            $connection = $em->getConnection();
            // No `deleted_at` predicate: an archived supplier's catalogue is read (§5.2a).
            if (false === $connection->fetchOne('SELECT 1 FROM supplier WHERE id = ?::uuid', [$supplierId])) {
                return null;
            }

            return array_map(self::view(...), $connection->fetchAllAssociative(
                self::SELECT.' WHERE sp.supplier_id = ?::uuid ORDER BY sp.supplier_sku, sp.id', [$supplierId]));
        });
    }

    /** API-22's probe for an empty set: does this workspace hold any catalogue entry at all (RLS, no filter predicate). */
    public function tenantHasRows(): bool
    {
        return $this->tenants->run(
            $this->tenant->tenantId(),
            static fn (EntityManagerInterface $em): bool => (bool) $em->getConnection()->fetchOne('SELECT EXISTS (SELECT 1 FROM supplier_product)'),
        );
    }

    /** `UpdateSupplierProduct`'s row load: the pair, or null (404). */
    public function get(string $supplierId, string $productId): ?SupplierProductView
    {
        if (!Uuid::isValid($supplierId) || !Uuid::isValid($productId)) {
            return null;
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            static fn (EntityManagerInterface $em): ?SupplierProductView => self::readInside(
                $em,
                Uuid::fromString($supplierId)->toRfc4122(),
                Uuid::fromString($productId)->toRfc4122(),
            ),
        );
    }

    /**
     * One catalogue entry **of this supplier**, inside a transaction the caller holds; null when it is another supplier's
     * or absent. The `supplier_id` predicate is the ONE place an entry is tied to its path's supplier.
     */
    public static function readInside(EntityManagerInterface $em, string $supplierId, string $productId): ?SupplierProductView
    {
        $row = $em->getConnection()->fetchAssociative(
            self::SELECT.' WHERE sp.id = ?::uuid AND sp.supplier_id = ?::uuid',
            [$productId, $supplierId],
        );

        return false === $row ? null : self::view($row);
    }

    /** @param array<string, mixed> $row */
    private static function view(array $row): SupplierProductView
    {
        return new SupplierProductView(
            id: (string) $row['id'],
            itemId: (string) $row['inventory_item_id'],
            itemSku: (string) $row['item_sku'],
            itemName: (string) $row['item_name'],
            supplierSku: (string) $row['supplier_sku'],
            unitCost: (string) $row['unit_cost'],
            leadTimeDays: (int) $row['lead_time_days'],
            minimumOrderQuantity: (string) $row['min_order_quantity'],
            currencyCode: (string) $row['currency_code'],
        );
    }
}
