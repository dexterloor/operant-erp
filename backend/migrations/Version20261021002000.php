<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Remaining-ops G-I — the `/items` Archived view's index** (`IA.md` §5.7 **D-a**, the fifth and last table it names after
 * `supplier`, `customer`, `sales_order` and — at order restores C3 — `purchase_order`; `docs/session13/remaining-ops-final-red.md`
 * H-1: *"G-I ships `inventory_item_tenant_archived_idx` with a plan test that can go red"*).
 *
 * `inventory_item_tenant_archived_idx ON inventory_item (tenant_id, deleted_at, id) WHERE deleted_at IS NOT NULL` — "what was
 * archived, most recently first", the statement `ListItems?archived=true` issues (`ItemReader::listStatement()`), with `id`
 * for the TB-2a keyset tie the paged view needs (`deleted_at DESC, id DESC`): the three-column shape `DATA` signed for
 * `supplier` (`owed-data-c5.md` "Response to RED") and every D-a index since. A partial index on `deleted_at IS NOT NULL`
 * stores no entry for a live row, so its cost falls on archiving, not on ordinary writes.
 *
 * **No grant.** `RestoreItem` and `DeleteItem` shipped with their writes long ago; this commit adds a read path only.
 * `ItemOperationContractTest::archivedIsOfferedOnItemsExactlyWhenItsIndexIsMigrated` ties this index to `ListItems`'
 * `archived` parameter; `SchemaQueryPlanTest::theItemArchivedViewUsesTheArchivedIndex` pins the plan; the ORM attribute on
 * `InventoryItem` keeps `DoctrineMappingTest`'s schema diff empty.
 */
final class Version20261021002000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remaining-ops G-I: inventory_item_tenant_archived_idx (the /items Archived view, D-a)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX inventory_item_tenant_archived_idx ON inventory_item (tenant_id, deleted_at, id) WHERE deleted_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX inventory_item_tenant_archived_idx');
    }
}
