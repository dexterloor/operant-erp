<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Order restores C3 — the `/purchase-orders` Archived view's index** (`docs/session13/order-restores-design.md` §5.1,
 * Revision 1 FINAL C3 item 1; §6; `IA.md` §5.7 D-a).
 *
 * `purchase_order_tenant_archived_idx ON purchase_order (tenant_id, deleted_at, id) WHERE deleted_at IS NOT NULL` — "what was
 * archived, most recently first", with `id` for the TB-2a keyset tie the paged view needs (`deleted_at DESC, id DESC`).
 * The customer/supplier/sales-order precedent (`Version20261014000200`, `Version20261013000500`), `DATA` to confirm the shape. A
 * partial index on `deleted_at IS NOT NULL` stores no entry for a live row, so its cost falls on archiving, not on
 * ordinary writes. Plain `CREATE INDEX` in a transactional migration, as the precedents: the table is small, and
 * `CONCURRENTLY` cannot run inside a transaction.
 *
 * **Grants nothing** — `RestorePurchaseOrder` writes `deleted_at` under `erp_app`'s table-level UPDATE on `purchase_order`.
 * `SchemaQueryPlanTest` holds the plan (a backward scan of this index, no sort node).
 */
final class Version20261021000600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Order restores C3: purchase_order_tenant_archived_idx (the /purchase-orders Archived view)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX purchase_order_tenant_archived_idx ON purchase_order (tenant_id, deleted_at, id) WHERE deleted_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX purchase_order_tenant_archived_idx');
    }
}
