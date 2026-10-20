<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Warehouse/location delete-restore C1 — `RestoreWarehouse`'s write** (ADR-002 A4.1: the grant ships with its caller,
 * granting exactly what the caller issues; `docs/session13/wh-loc-delete-design.md` §6.2, `DATA` D-1 in
 * `wh-loc-delete-roles.md`).
 *
 * `GRANT UPDATE (deleted_at) ON warehouse TO erp_app` — `WarehouseWriter::restore()` sets `deleted_at = NULL` (and
 * `updated_at`, already granted at warehouses slice 1 C2, `Version20261013000100`). `DeleteWarehouse` (C4) will reuse it.
 * Column-level, like every warehouse grant: no table UPDATE, no DELETE (the table is `soft`). `address_line`, `city`,
 * `postal_code`, `is_active` stay unwritable (Q-W4, G-36). No index: the `/warehouses` Archived view is bounded and ordered
 * by code (`DATA` D-4, none).
 *
 * `GrantFootprintTest` holds the column grant against `SchemaManifest` and runs it as `erp_app`.
 */
final class Version20261020000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Warehouse/location delete-restore C1: erp_app column UPDATE on warehouse.deleted_at (RestoreWarehouse)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT UPDATE (deleted_at) ON warehouse TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (deleted_at) ON warehouse FROM erp_app');
    }
}
