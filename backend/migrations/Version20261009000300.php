<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Customers slice 1, C2 — the writes `CreateCustomer` and `UpdateCustomer` issue, and nothing more** (ADR-002 A4.1:
 * the grant ships with its caller, granting exactly what the caller issues).
 *
 * `erp_app` held SELECT only on `customer` since `Version20260916000300`. `CustomerWriter` issues one INSERT and one
 * UPDATE (plus a row lock on its first read, below), both raw SQL naming their columns, so the grants are **column-level**, `role`'s precedent
 * (`Version20261007000100`):
 *
 *   INSERT (id, tenant_id, code, name, country_code, currency_code, payment_terms_days)
 *   UPDATE (code, name, country_code, currency_code, payment_terms_days, updated_at)
 *
 * **Why not table-level (`inventory_item`'s `APP_INSERT_UPDATE`):** a table UPDATE would let SQL as `erp_app` archive a
 * customer (`deleted_at`) or set `credit_limit`/`is_active`/`email`/`phone`, which no operation writes (owner Q-C2,
 * `RESIDUAL_GAPS.md`). Defaulted columns (`created_at`, `updated_at` on insert, `credit_limit`, `is_active`,
 * `payment_terms_days` when omitted) need no column privilege. **No DELETE**: `DeleteCustomer` is not built, and its
 * delete would be soft.
 *
 * The UPDATE grant also makes `UpdateCustomer`'s first-read `FOR NO KEY UPDATE` legal (A4.3 constraint 1,
 * `SalesOrderRowLock`'s precedent). Narrowing it to no columns turns that lock into a 42501.
 *
 * `erp_ai` keeps exactly its inherited table SELECT. `GrantFootprintTest` holds both lists against `SchemaManifest` and
 * runs each statement as `erp_app`.
 */
final class Version20261009000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customers slice 1 C2: erp_app column INSERT and column UPDATE on customer (CreateCustomer, UpdateCustomer)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT INSERT (id, tenant_id, code, name, country_code, currency_code, payment_terms_days) ON customer TO erp_app');
        $this->addSql('GRANT UPDATE (code, name, country_code, currency_code, payment_terms_days, updated_at) ON customer TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (code, name, country_code, currency_code, payment_terms_days, updated_at) ON customer FROM erp_app');
        $this->addSql('REVOKE INSERT (id, tenant_id, code, name, country_code, currency_code, payment_terms_days) ON customer FROM erp_app');
    }
}
