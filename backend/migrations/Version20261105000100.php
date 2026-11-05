<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **The pick queue for one warehouse gets an index in its own order** (BK-21, `ListPickQueueEntries`; design
 * `docs/design/bk21-design.md` Revision 1 §R5 and final review M-2).
 *
 * `sales_order_tenant_warehouse_awaiting_pick_idx (tenant_id, warehouse_id, ordered_at, id) WHERE deleted_at IS NULL AND
 * status IN ('confirmed','partially_fulfilled')`. The queue filtered to a warehouse orders by `(ordered_at, id)`; the shipped
 * `sales_order_tenant_warehouse_open_idx` is `(tenant_id, warehouse_id, status)` and cannot serve that order, so without
 * this the view sorts one warehouse's whole open set before cutting a page. The trailing `id` makes the keyset a full index
 * order, so no sort node remains (`SchemaQueryPlanTest::thePickQueueForOneWarehouseUsesTheWarehouseAwaitingPickIndex`).
 *
 * The predicate's status literals mirror `sales_order_tenant_open_idx`; the reader binds them from `SalesOrderViews` (API-15),
 * DDL is not bound. `ordered_at IS NOT NULL` stays out of the predicate: a btree serves it as an index condition.
 * `sales_order_tenant_open_idx` is not touched. Plain `CREATE INDEX` in a transactional migration, `Version20261020000700`'s
 * precedent — no real-volume tenant exists; a hosted deployment at volume would use `CONCURRENTLY` with
 * `isTransactional(): false`. No grant. The ORM mirror is on `SalesOrder` (`DoctrineMappingTest`).
 */
final class Version20261105000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BK-21: index the open, released sales orders of one warehouse in pick-queue order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE INDEX sales_order_tenant_warehouse_awaiting_pick_idx ON sales_order (tenant_id, warehouse_id, ordered_at, id) WHERE deleted_at IS NULL AND status IN ('confirmed', 'partially_fulfilled')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX sales_order_tenant_warehouse_awaiting_pick_idx');
    }
}
