<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\ApiResource\SalesOrderLineCollection;
use App\Modules\Sales\ApiResource\SalesOrderLineResource;
use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The read half of the `/sales-orders` slice.
 *
 * ---------------------------------------------------------------------------
 * P-7 — the transaction does not span serialization
 * ---------------------------------------------------------------------------
 * Every method here opens exactly one tenancy transaction, hydrates completely into DTOs of scalars,
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


    /**
     * `ListSalesOrderLines` — bounded (TB-1c), so the whole set comes back in one statement with no
     * `LIMIT` and no cursor.
     *
     * The parent is resolved first and a parent the caller cannot see is `not-found` (§5.3 clause 2) —
     * the same 404 `GetSalesOrder` returns for that id, so a sub-list is not an oracle for the existence
     * of its parent.
     *
     * **The parent probe deliberately carries no `deleted_at` predicate**, which is §5.2a's direct-hit
     * carve-out: an archived order's lines are still readable, because a fulfilment or an invoice
     * legitimately references the order.
     *
     * @return SalesOrderLineCollection|null null when the order is not visible
     */
    public function listLines(string $orderId, bool $archived): ?SalesOrderLineCollection
    {
        if (!Uuid::isValid($orderId)) {
            return null;
        }

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($orderId, $archived): ?SalesOrderLineCollection {
            $connection = $em->getConnection();

            if (false === $connection->fetchOne('SELECT 1 FROM sales_order WHERE id = ?::uuid', [$orderId])) {
                return null;
            }

            // API-19c limb 2: `archived` selects *which* whole set is returned. TB-3's rule still holds
            // in the default direction -- a live list never returns a soft-deleted row.
            $rows = $connection->fetchAllAssociative(
                'SELECT '.SalesOrderLineRow::COLUMNS.' '.SalesOrderLineRow::FROM
                .' WHERE sol.sales_order_id = ?::uuid AND sol.deleted_at IS '.($archived ? 'NOT NULL' : 'NULL')
                // The order `sales_order_line_unique` already maintains. Not a `sort` control: §10.2's
                // exclusion 2 takes a bounded list out of TB-1's scope entirely.
                .' ORDER BY sol.line_number ASC',
                // The SELECT list binds first -- `SalesOrderLineRow::COLUMNS` carries the cancelled
                // status the V2-C-14(b) derivation compares against, and it precedes the WHERE.
                [...SalesOrderLineRow::columnParameters(), $orderId],
            );

            /** @var list<SalesOrderLineResource> $items */
            $items = array_map(SalesOrderLineRow::toResource(...), $rows);

            return new SalesOrderLineCollection(
                items: $items,
                // §4.4.2: a bounded list carries both cursors null **always**, because the whole set is
                // in `items`. It is the same envelope, so a client that pages correctly needs no
                // special case.
                nextCursor: null,
                previousCursor: null,
                tenantHasRows: [] === $items ? self::anySalesOrderLineExists($em) : true,
            );
        });
    }

    /**
     * ST-2's two empty states. The probe carries **no** filter predicate and no ordering: the question
     * is *"does this workspace have any rows at all"*, which is what decides whether the screen offers
     * "raise your first order" or "clear your filters".
     *
     * A method per table rather than one taking a table name, so that no value a request can reach ever
     * decides which table is read. The `EntityManagerInterface` is the same seam every other read here
     * uses — A-10 keeps a bare `Doctrine\DBAL\Connection` out of a module's signatures.
     */
    private static function anySalesOrderLineExists(EntityManagerInterface $em): bool
    {
        return (bool) $em->getConnection()->fetchOne('SELECT EXISTS (SELECT 1 FROM sales_order_line)');
    }
}
