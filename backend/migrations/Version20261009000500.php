<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Customers slice 1, C3 — the writes `CreateCustomerAddress` and `UpdateCustomerAddress` issue, and nothing more**
 * (ADR-002 A4.1: the grant ships with its caller, granting exactly what the caller issues).
 *
 * `erp_app` held SELECT only on `customer_address`. `CustomerAddressWriter` issues one INSERT and one UPDATE, both raw SQL
 * naming their columns, so the grants are **column-level** (`customer`'s precedent, `Version20261009000300`):
 *
 *   INSERT (id, tenant_id, customer_id, kind, address_line, city, postal_code, country_code, idempotency_key, request_digest)
 *   UPDATE (kind, address_line, city, postal_code, country_code, updated_at)
 *
 * `region` is omitted from both (neither accepted nor served; its default `''` needs no privilege), as are `created_at`
 * and `updated_at` on insert (defaulted). **The retry key and digest are INSERT-only:** an UPDATE of either would let SQL
 * as `erp_app` re-point a stored key at another request. **No DELETE**: `DeleteCustomerAddress` is not built.
 *
 * `erp_ai` keeps exactly its inherited table SELECT. `GrantFootprintTest` holds both lists against `SchemaManifest` and
 * runs each statement as `erp_app`.
 */
final class Version20261009000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customers slice 1 C3: erp_app column INSERT and column UPDATE on customer_address (CreateCustomerAddress, UpdateCustomerAddress)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT INSERT (id, tenant_id, customer_id, kind, address_line, city, postal_code, country_code, idempotency_key, request_digest) ON customer_address TO erp_app');
        $this->addSql('GRANT UPDATE (kind, address_line, city, postal_code, country_code, updated_at) ON customer_address TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (kind, address_line, city, postal_code, country_code, updated_at) ON customer_address FROM erp_app');
        $this->addSql('REVOKE INSERT (id, tenant_id, customer_id, kind, address_line, city, postal_code, country_code, idempotency_key, request_digest) ON customer_address FROM erp_app');
    }
}
