<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Warehouse/location delete-restore C2 — `RestoreStorageLocation`'s write** (ADR-002 A4.1: the grant ships with its
 * caller, granting exactly what the caller issues; `docs/session13/wh-loc-delete-design.md` §6.2, `DATA` D-1 in
 * `wh-loc-delete-roles.md`).
 *
 * `GRANT UPDATE (deleted_at) ON storage_location TO erp_app` — `StorageLocationWriter::restore()` sets `deleted_at = NULL`
 * (and `updated_at`, already granted at warehouses slice 1 C3, `Version20261013000300`). `DeleteStorageLocation` (C3)
 * will reuse it. Column-level, like every location grant: no table UPDATE, no DELETE (the table is `soft`);
 * `warehouse_id` stays INSERT-only, so a location still cannot change warehouse (W-5 "warehouse row locks" rule 3). No
 * index: the archived locations list is bounded and ordered by code (`DATA` D-4, none).
 *
 * `GrantFootprintTest` holds the column grant against `SchemaManifest` and runs it as `erp_app`.
 */
final class Version20261020000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Warehouse/location delete-restore C2: erp_app column UPDATE on storage_location.deleted_at (RestoreStorageLocation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT UPDATE (deleted_at) ON storage_location TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (deleted_at) ON storage_location FROM erp_app');
    }
}
