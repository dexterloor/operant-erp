<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Customers slice 2, C2 — the DELETE `DeleteCustomerContact` issues, and nothing more** (ADR-002 A4.1: the grant ships
 * with its caller; `docs/session13/customers-s2-design.md` Revision 2 R2.1).
 *
 * `customer_contact` is `SchemaManifest::HARD`, so removing a contact is a real `DELETE`, and Postgres has no column-level
 * DELETE: the grant is table-level, the fourth on the application's footprint (after `user_role`, `role_permission` and
 * `supplier_product`). ADR-002 A4.4 duty 2's RLS-DELETE isolation test ships in the same commit
 * (`TenantIsolationTest::erpAppCannotDeleteAnotherTenantsCustomerContact`), and the derived
 * `everyTableTheApplicationMayDeleteHasAnIsolationTest` now holds every DELETE grant to one. `erp_ai` stays at nothing.
 */
final class Version20261019000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customers slice 2 C2: erp_app DELETE on customer_contact (DeleteCustomerContact)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT DELETE ON customer_contact TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE DELETE ON customer_contact FROM erp_app');
    }
}
