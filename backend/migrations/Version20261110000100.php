<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **"Does any order line, deleted ones included, name this item?" gets an index on each line table** (BK-24, items batch
 * C3, 2026-11-10; `docs/session13/items-batch-design.md` Revision 0 §1 C3 "Migration 1" and "Revision 1 — FINAL" C3;
 * `docs/design/items-owner-items.md` S-2: the indexes ship now, not at the first non-demo tenant).
 *
 * `purchase_order_line_tenant_item_all_idx` and `sales_order_line_tenant_item_all_idx`, both `(tenant_id, inventory_item_id)`
 * and **non-partial**. `UpdateItem`'s unit-in-use check asks each line table whether any row names the item WITH NO
 * `deleted_at` predicate — a deleted line still recorded a quantity in the item's unit — and the shipped
 * `*_line_tenant_item_idx` are partial on `deleted_at IS NULL`, so they cannot answer it: without these, each check reads
 * the workspace's whole line table (`SchemaQueryPlanTest` pins both ports' plans). The partial indexes stay; they serve the
 * live-only reads (ADR-004's demand and on-order inputs). Whether they become redundant is `DATA`'s (TO CONFIRM, RED L-9).
 *
 * Plain `CREATE INDEX` in a transactional migration, `Version20261105000100`'s precedent: no real-volume tenant exists; a
 * hosted deployment at volume would use `CONCURRENTLY` with `isTransactional(): false` (trigger: the first non-demo tenant,
 * unmechanised). No grant. The ORM mirrors are on `PurchaseOrderLine` and `SalesOrderLine` (`DoctrineMappingTest`).
 */
final class Version20261110000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BK-24: index every order line, deleted ones included, by the item it names';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX purchase_order_line_tenant_item_all_idx ON purchase_order_line (tenant_id, inventory_item_id)');
        $this->addSql('CREATE INDEX sales_order_line_tenant_item_all_idx ON sales_order_line (tenant_id, inventory_item_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX sales_order_line_tenant_item_all_idx');
        $this->addSql('DROP INDEX purchase_order_line_tenant_item_all_idx');
    }
}
