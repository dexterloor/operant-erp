<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Customers slice 2, C3 — the DELETE `DeleteCustomerAddress` issues, and nothing more** (ADR-002 A4.1;
 * `docs/session13/customers-s2-design.md` Revision 2 R2.1).
 *
 * `customer_address` is `SchemaManifest::HARD` (an order holds a snapshot of its ship-to, never a reference), so removing an
 * address is a real `DELETE`; Postgres has no column DELETE, so the grant is table-level — the fifth on the application's
 * footprint. Its RLS-DELETE isolation test ships in the same commit
 * (`TenantIsolationTest::erpAppCannotDeleteAnotherTenantsCustomerAddress`), and `everyTableTheApplicationMayDeleteHasAnIsolationTest`
 * holds it there. `erp_ai` keeps exactly its inherited SELECT.
 */
final class Version20261019000700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customers slice 2 C3: erp_app DELETE on customer_address (DeleteCustomerAddress)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT DELETE ON customer_address TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE DELETE ON customer_address FROM erp_app');
    }
}
