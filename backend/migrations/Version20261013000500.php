<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **C5 C2a — `RestoreSupplier`'s write, and the `/suppliers` Archived view's index** (ADR-002 A4.1: the grant ships with its
 * caller, granting exactly what the caller issues; `docs/session13/c5-design.md` §4.2, §5).
 *
 *  1. `GRANT UPDATE (deleted_at) ON supplier TO erp_app` — `SupplierWriter::restore()` sets `deleted_at = NULL` (and
 *     `updated_at`, already granted at suppliers C2, `Version20261012000200`). `DeleteSupplier` (C5 C2b) will reuse it.
 *     Column-level, like every supplier grant: no table UPDATE, no DELETE (the table is `soft`). `email`, `phone`,
 *     `is_active` stay unwritable (owner Q-S6, G-36).
 *  2. `supplier_tenant_archived_idx ON supplier (tenant_id, deleted_at, id) WHERE deleted_at IS NOT NULL` — `IA.md` §5.7
 *     D-a's index for "what was archived, most recently first", with `id` added for the TB-2a keyset tie the paged view
 *     needs (`deleted_at DESC, id DESC`; design §4.2, `DATA` to confirm the shape). A partial index on
 *     `deleted_at IS NOT NULL` stores no entry for a live row, so its cost falls on archiving, not on ordinary writes.
 *
 * `SupplierOperationContractTest` ties the index to `ListSuppliers`' `archived` parameter and to `RestoreSupplier`
 * (E9-3 / D-a); `GrantFootprintTest` holds the column grant against `SchemaManifest` and runs it as `erp_app`.
 */
final class Version20261013000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'C5 C2a: erp_app column UPDATE on supplier.deleted_at (RestoreSupplier) and supplier_tenant_archived_idx (the /suppliers Archived view)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT UPDATE (deleted_at) ON supplier TO erp_app');
        $this->addSql('CREATE INDEX supplier_tenant_archived_idx ON supplier (tenant_id, deleted_at, id) WHERE deleted_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX supplier_tenant_archived_idx');
        $this->addSql('REVOKE UPDATE (deleted_at) ON supplier FROM erp_app');
    }
}
