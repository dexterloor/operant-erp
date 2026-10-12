<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Suppliers slice 1, C4 — the `DELETE` `DeleteSupplierProduct` issues, and nothing more** (ADR-002 A4.1: the grant ships
 * with its caller, granting exactly what the caller issues; `Version20261012000500`'s sequel).
 *
 * `SupplierProductWriter::DELETE` is `DELETE FROM supplier_product WHERE id = ? AND supplier_id = ?`. Postgres has no
 * column-level `DELETE`, so the grant is **table-level**. `supplier_product` is class `hard` (`SchemaManifest`): a purchase
 * order line snapshots lead time and cost, so removing current terms strands nothing, and no foreign key references the
 * table (measured, `pg_constraint.confrelid`). The third table `erp_app` may delete from, after `user_role`
 * (`Version20260929000300`) and `role_permission`.
 *
 * `erp_ai` gains nothing. `GrantFootprintTest` holds the table footprint against `SchemaManifest`, and its catalogue probe
 * now runs the `DELETE` as `erp_app` as a positive control; `TenantIsolationTest` proves RLS bounds it to the caller's
 * workspace (ADR-002 A4.4 item 2's pattern).
 */
final class Version20261012000600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppliers slice 1 C4: erp_app DELETE on supplier_product (DeleteSupplierProduct)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT DELETE ON supplier_product TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE DELETE ON supplier_product FROM erp_app');
    }
}
