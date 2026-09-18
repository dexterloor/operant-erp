<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The read half of the `/sales-orders` slice. One operation today, `GetSalesOrder`.
 *
 * ---------------------------------------------------------------------------
 * P-7 — the transaction does not span serialization
 * ---------------------------------------------------------------------------
 * The one method here opens exactly one tenancy transaction, hydrates completely into a DTO of scalars,
 * and returns. Nothing it hands back can issue a query, so the response is serialized after the commit.
 * ADR-002 A1.7 measured 1505 ms of pooled-backend pinning when the transaction was held across the rest
 * of the request; and this entity has **three** tenant-scoped associations (customer, warehouse, creator)
 * plus a currency, so an entity-graph response would have four ways to raise after the commit rather
 * than one.
 *
 * ---------------------------------------------------------------------------
 * Why DBAL rather than DQL
 * ---------------------------------------------------------------------------
 * `PurchaseOrderReader`'s reasons, which are not weaker here: API-13a requires the soft-delete predicate
 * on a list and **not** on a direct hit, which one globally enabled Doctrine filter cannot express; and
 * the creator join is an outer join onto a table this module has no mapping for. Tenant scoping is
 * unaffected — RLS is the boundary and it binds at table level, underneath whatever the ORM believes
 * (ADR-002 §4).
 *
 * **`erp_app` needs nothing new for this.** It already holds `SELECT` on `sales_order`, `customer`,
 * `warehouse` and `app_user` — the three sales tables fall to `TENANT_READ_DEFAULT`
 * (`SchemaManifest.php`, no `grants` key) and `GrantFootprintTest` measures the whole footprint against
 * `has_table_privilege()` every run. A read ships no migration.
 */
final readonly class SalesOrderReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /**
     * API-13a / §5.2a — a direct hit on a soft-deleted row inside the caller's own tenant returns the row
     * with a non-null `archived_at`, never a 404. There is deliberately no `deleted_at` predicate here.
     *
     * §5.3 clause 2: a row RLS does not return is `not-found`, never `permission-denied`, and an id that
     * is not a uuid at all takes the same path — so "another tenant's", "hard-deleted" and "never
     * existed" answer identically.
     *
     * **`RestoreSalesOrder` is declared in §4.2 and unbuilt**, so today this carve-out leads nowhere on
     * this resource either; it is still right for ST-9's reason — an archived order stays readable
     * because a fulfilment or an invoice legitimately references it.
     */
    public function get(string $id): ?SalesOrderResource
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($id): ?SalesOrderResource {
            $row = $em->getConnection()->fetchAssociative(
                'SELECT '.SalesOrderRow::COLUMNS.' '.SalesOrderRow::FROM.' WHERE so.id = ?::uuid',
                [$id],
            );

            return false === $row ? null : SalesOrderRow::toResource($row);
        });
    }
}
