<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sales step 7 R2 — `CreateSalesReturn`'s two permission codes and its writer's grant, in the commit that ships the
 * caller (ADR-002 A4.1).
 *
 * **The codes** (owner rulings Q-F and Q-B, 2026-09-30; `SECURITY_MODEL.md` §3): `sales_return.create` (the
 * operation; `OwedAmendmentTripwireTest`'s A2.1 pointer item 2) and `sales_return.view` (`GetSalesReturn`, `SEC` S-6).
 * Module `Modules\Sales`, descriptions derived from the code exactly as `Version20260918000100` derives them
 * (`PermissionCatalogueSeedTest::everySeededDescriptionReconstructsItsOwnCode`). A forward migration, never an edit of
 * the seed in place. **SoD-5 does NOT enter here**: its second code, `credit_note.issue`, is absent until C1 (A2.7).
 * No `role_permission` row is written: no tenant holds seeded system roles (the per-tenant seed is deferred).
 *
 * **The grant**: `INSERT` on `sales_return` and `sales_return_line` to `erp_app` — the writer's two raw-SQL INSERTs.
 * **Table-level**, on `fulfilment`'s precedent (`Version20260922000200`): the writer names every column but
 * `created_at`/`updated_at`, which only default, so a column grant would protect nothing `invoice`'s did (there,
 * `status` and `paid_at` must never be written by the issuer). Never UPDATE, never DELETE: nothing edits an issued
 * document (OI-2). `stock_movement` / `stock_level` need nothing: `StockReceiver`'s table-level grants cover
 * `StockReturner`'s statements (measured 2026-10-01, design §13 item 2); `sales_order` needs nothing for the lock
 * (`Version20260919000300`).
 */
final class Version20261001000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sales step 7 R2: permission rows sales_return.create and sales_return.view; erp_app INSERT on sales_return and sales_return_line.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO permission (code, module, description) VALUES
                ('sales_return.create', 'Modules\Sales', 'sales return: create'),
                ('sales_return.view',   'Modules\Sales', 'sales return: view')
            SQL);

        $this->addSql('GRANT INSERT ON sales_return, sales_return_line TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE INSERT ON sales_return, sales_return_line FROM erp_app');
        $this->addSql("DELETE FROM permission WHERE code IN ('sales_return.create', 'sales_return.view')");
    }
}
