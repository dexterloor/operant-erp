<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Suppliers slice 1, C2 — the writes `CreateSupplier` and `UpdateSupplier` issue, and nothing more** (ADR-002 A4.1:
 * the grant ships with its caller, granting exactly what the caller issues; `Version20261009000300`'s twin).
 *
 * `erp_app` held SELECT only on `supplier`. `SupplierWriter` issues one INSERT and one UPDATE (plus a row lock on its
 * first read), both raw SQL naming their columns, so the grants are **column-level**:
 *
 *   INSERT (id, tenant_id, code, name, country_code, currency_code, payment_terms_days)
 *   UPDATE (code, name, country_code, currency_code, payment_terms_days, updated_at)
 *
 * **Why not table-level:** a table UPDATE would let SQL as `erp_app` archive a supplier (`deleted_at`) or set
 * `email`/`phone`/`is_active`, which no operation writes (owner Q-S6, `RESIDUAL_GAPS.md` G-36). Defaulted columns need no
 * column privilege. **No `deleted_at`, no DELETE**: `DeleteSupplier` is not built (Q-S1 (d)); its soft delete adds
 * `deleted_at` with its caller.
 *
 * The UPDATE grant also makes `UpdateSupplier`'s first-read `FOR NO KEY UPDATE` legal (A4.3 constraint 1), and C3's
 * `FOR SHARE` on the parent supplier (final RED M-B). Narrowing it to no columns turns that lock into a 42501.
 *
 * `erp_ai` keeps exactly its inherited table SELECT. `GrantFootprintTest` holds both lists against `SchemaManifest` and
 * runs each statement as `erp_app`.
 */
final class Version20261012000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppliers slice 1 C2: erp_app column INSERT and column UPDATE on supplier (CreateSupplier, UpdateSupplier)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT INSERT (id, tenant_id, code, name, country_code, currency_code, payment_terms_days) ON supplier TO erp_app');
        $this->addSql('GRANT UPDATE (code, name, country_code, currency_code, payment_terms_days, updated_at) ON supplier TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (code, name, country_code, currency_code, payment_terms_days, updated_at) ON supplier FROM erp_app');
        $this->addSql('REVOKE INSERT (id, tenant_id, code, name, country_code, currency_code, payment_terms_days) ON supplier FROM erp_app');
    }
}
