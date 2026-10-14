<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **C5 C4a — `RestoreCustomer`'s write, and the `/customers` Archived view's index** (ADR-002 A4.1: the grant ships with its
 * caller, granting exactly what the caller issues; `docs/session13/c5-design.md` §4.2, §5; `Version20261013000500`'s
 * supplier twin).
 *
 *  1. `GRANT UPDATE (deleted_at) ON customer TO erp_app` — `CustomerWriter::restore()` sets `deleted_at = NULL` (and
 *     `updated_at`, already granted at customers C2, `Version20261009000300`). `DeleteCustomer` (C5 C4b) will reuse it.
 *     Column-level, like every customer grant: no table UPDATE, no DELETE (the table is `soft`). `email`, `phone`,
 *     `credit_limit` and `is_active` stay unwritable (owner Q-C2, G-36). Measured before writing (C4a T-1):
 *     `has_column_privilege('erp_app', 'customer', 'deleted_at', 'UPDATE')` = f.
 *  2. `customer_tenant_archived_idx ON customer (tenant_id, deleted_at, id) WHERE deleted_at IS NOT NULL` — `IA.md` §5.7
 *     D-a's index for "what was archived, most recently first", with `id` added for the TB-2a keyset tie the paged view
 *     needs (`deleted_at DESC, id DESC`; design §4.2, `DATA` to confirm the shape). A partial index on
 *     `deleted_at IS NOT NULL` stores no entry for a live row, so its cost falls on archiving, not on ordinary writes.
 *
 * `CustomerOperationContractTest` ties the index to `ListCustomers`' `archived` parameter and to `RestoreCustomer`
 * (E9-3 / D-a); `GrantFootprintTest` holds the column grant against `SchemaManifest` and runs it as `erp_app`.
 */
final class Version20261014000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'C5 C4a: erp_app column UPDATE on customer.deleted_at (RestoreCustomer) and customer_tenant_archived_idx (the /customers Archived view)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT UPDATE (deleted_at) ON customer TO erp_app');
        $this->addSql('CREATE INDEX customer_tenant_archived_idx ON customer (tenant_id, deleted_at, id) WHERE deleted_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX customer_tenant_archived_idx');
        $this->addSql('REVOKE UPDATE (deleted_at) ON customer FROM erp_app');
    }
}
