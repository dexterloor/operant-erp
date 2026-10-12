<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Warehouses slice 1, C2 — the writes `CreateWarehouse` and `UpdateWarehouse` issue, and nothing more** (ADR-002 A4.1:
 * the grant ships with its caller, granting exactly what the caller issues; `Version20261012000200`'s twin).
 *
 * `erp_app` held SELECT only on `warehouse` (`Version20260916000300` revoked the rest). `WarehouseWriter` issues one INSERT
 * and one UPDATE (plus a row lock on its first read), both raw SQL naming their columns, so the grants are
 * **column-level**:
 *
 *   INSERT (id, tenant_id, code, name, country_code)
 *   UPDATE (code, name, country_code, updated_at)
 *
 * **Why not table-level:** a table UPDATE would let SQL as `erp_app` archive a warehouse (`deleted_at`) or set
 * `address_line`/`city`/`postal_code`/`is_active`, which no operation writes (Q-W4, `RESIDUAL_GAPS.md` G-36). Defaulted
 * columns need no column privilege. **No `deleted_at`, no DELETE**: `DeleteWarehouse` is out of this slice (API-O-34, G-7).
 *
 * The UPDATE grant also makes `UpdateWarehouse`'s first-read `FOR NO KEY UPDATE` legal (A4.3 constraint 1), and C3's
 * `FOR SHARE` on the parent warehouse (design §4.1). Narrowing it to no columns turns that lock into a 42501.
 *
 * `erp_ai` keeps exactly its inherited table SELECT. `GrantFootprintTest` holds both lists against `SchemaManifest` and
 * runs each statement as `erp_app`.
 */
final class Version20261013000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Warehouses slice 1 C2: erp_app column INSERT and column UPDATE on warehouse (CreateWarehouse, UpdateWarehouse)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT INSERT (id, tenant_id, code, name, country_code) ON warehouse TO erp_app');
        $this->addSql('GRANT UPDATE (code, name, country_code, updated_at) ON warehouse TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (code, name, country_code, updated_at) ON warehouse FROM erp_app');
        $this->addSql('REVOKE INSERT (id, tenant_id, code, name, country_code) ON warehouse FROM erp_app');
    }
}
