<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **"Does this warehouse still have open orders" gets an index on each order table** (warehouse/location delete-restore C4,
 * 2026-10-20; `DATA` `docs/session13/wh-loc-delete-roles.md` D-3, as corrected by `wh-loc-delete-roles-red.md` L-1/M-1).
 *
 * `purchase_order_tenant_warehouse_open_idx` and `sales_order_tenant_warehouse_open_idx`, each `(tenant_id, warehouse_id,
 * status) WHERE deleted_at IS NULL`. `DeleteWarehouse`'s API-O-34 counts (`WarehouseOpenPurchaseOrdersCount`,
 * `WarehouseOpenSalesOrdersCount`) run while the warehouse row is held `FOR UPDATE`, which blocks every order create and stock
 * write into that warehouse; neither table had any index with `warehouse_id` in it, so the count read every LIVE order of
 * the workspace — and received purchase orders and fulfilled sales orders stay live for good. `(tenant_id, warehouse_id)`
 * alone would save almost nothing (a workspace has a handful of warehouses). **`status` is a key column, not in the
 * predicate**, so the Port's bound `status = ANY(?::text[])` is an `Index Cond` under any plan and the open-status set lives
 * once, in the Port, never in DDL (API-15). The predicate keeps only `deleted_at IS NULL`, which the statement states.
 *
 * Its own transactional migration, never inside the non-transactional catalogue re-issue (`Version20261020000600`; L-1):
 * plain `CREATE INDEX`, `Version20261015120000`'s precedent — no real-volume tenant exists; a hosted deployment at volume would
 * use `CONCURRENTLY` with `isTransactional(): false`. No grant. The ORM mirrors are on `PurchaseOrder` and `SalesOrder`
 * (`DoctrineMappingTest`, M-1); `SchemaQueryPlanTest` pins both plans (D-2).
 */
final class Version20261020000700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Warehouse/location delete-restore C4: index each order table by warehouse and status, so an archive counts open orders without reading history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX purchase_order_tenant_warehouse_open_idx ON purchase_order (tenant_id, warehouse_id, status) WHERE deleted_at IS NULL');
        $this->addSql('CREATE INDEX sales_order_tenant_warehouse_open_idx ON sales_order (tenant_id, warehouse_id, status) WHERE deleted_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX sales_order_tenant_warehouse_open_idx');
        $this->addSql('DROP INDEX purchase_order_tenant_warehouse_open_idx');
    }
}
