<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Warehouses slice 1, C3 — the writes `CreateStorageLocation` and `UpdateStorageLocation` issue, and nothing more**
 * (ADR-002 A4.1: the grant ships with its caller, granting exactly what the caller issues; `Version20261013000100`'s twin).
 *
 * `erp_app` held SELECT only on `storage_location` (`Version20260916000300` revoked the rest). `StorageLocationWriter` issues
 * one INSERT and one UPDATE (plus row locks on its reads), both raw SQL naming their columns, so the grants are
 * **column-level**:
 *
 *   INSERT (id, tenant_id, warehouse_id, code, kind)
 *   UPDATE (code, kind, updated_at)
 *
 * **Why not table-level:** a table UPDATE would let SQL as `erp_app` move a location to another warehouse (`warehouse_id`
 * — its stock would move with no movement) or archive it (`deleted_at`), which no operation writes. Defaulted columns
 * need no column privilege. **No `deleted_at`, no DELETE**: `DeleteStorageLocation` is not built (G-27).
 *
 * **The UPDATE grant is also what makes the row locks legal** (A4.3 constraint 1; measured before this file, 42501 on
 * `FOR KEY SHARE` / `FOR UPDATE`): `UpdateStorageLocation`'s `FOR UPDATE` / `FOR NO KEY UPDATE`, and **`StockDepleter`'s
 * `FOR KEY SHARE`** on the locations it picks from (defence in depth, same commit). Narrowing it to no columns turns every
 * pick into a 42501.
 *
 * `erp_ai` keeps exactly its inherited table SELECT. `GrantFootprintTest` holds both lists against `SchemaManifest` and runs
 * each statement as `erp_app`.
 */
final class Version20261013000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Warehouses slice 1 C3: erp_app column INSERT and column UPDATE on storage_location (CreateStorageLocation, UpdateStorageLocation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT INSERT (id, tenant_id, warehouse_id, code, kind) ON storage_location TO erp_app');
        $this->addSql('GRANT UPDATE (code, kind, updated_at) ON storage_location TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (code, kind, updated_at) ON storage_location FROM erp_app');
        $this->addSql('REVOKE INSERT (id, tenant_id, warehouse_id, code, kind) ON storage_location FROM erp_app');
    }
}
