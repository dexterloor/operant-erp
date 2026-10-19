<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Customers slice 2, C1 — the writes `CreateCustomerContact` and `UpdateCustomerContact` issue, and nothing more**
 * (ADR-002 A4.1: the grant ships with its caller, granting exactly what the caller issues;
 * `docs/session13/customers-s2-design.md` Revision 2 R2.1).
 *
 * `erp_app` held SELECT only on `customer_contact` (and `erp_ai` nothing since C0, `Version20261019000100`, SEC S-2).
 * `CustomerContactWriter` issues raw SQL naming its columns, so the grants are **column-level** (`customer_address`'s
 * precedent, `Version20261009000500`):
 *
 *   INSERT (id, tenant_id, customer_id, name, email, phone, is_primary, idempotency_key, request_digest)
 *   UPDATE (name, email, phone, is_primary, updated_at)
 *
 * `created_at` and `updated_at` are omitted from the INSERT (defaulted). **The retry key and digest are INSERT-only:** an
 * UPDATE of either would let SQL as `erp_app` re-point a stored key at another request. The UPDATE grant also makes the
 * writer's `FOR KEY SHARE` key read and `FOR NO KEY UPDATE` row read legal (A4.3 constraint 1). **No DELETE**:
 * `DeleteCustomerContact` is C2. `erp_ai` stays at nothing. `GrantFootprintTest` holds both lists against `SchemaManifest`
 * and runs each statement as `erp_app`.
 */
final class Version20261019000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customers slice 2 C1: erp_app column INSERT and column UPDATE on customer_contact (CreateCustomerContact, UpdateCustomerContact)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT INSERT (id, tenant_id, customer_id, name, email, phone, is_primary, idempotency_key, request_digest) ON customer_contact TO erp_app');
        $this->addSql('GRANT UPDATE (name, email, phone, is_primary, updated_at) ON customer_contact TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (name, email, phone, is_primary, updated_at) ON customer_contact FROM erp_app');
        $this->addSql('REVOKE INSERT (id, tenant_id, customer_id, name, email, phone, is_primary, idempotency_key, request_digest) ON customer_contact FROM erp_app');
    }
}
