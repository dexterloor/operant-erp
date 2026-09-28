<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Sales step 6, C3 — `IssueInvoice`'s grant: a COLUMN-LEVEL `INSERT` on exactly the ten columns its raw-SQL writer
 * names, and nothing else** (ADR-002 A4.1 / A4.4 duty 1: the grant ships with its caller and grants *exactly* what
 * the caller issues).
 *
 * The ten are the column list of `InvoiceIssuer`'s one `INSERT INTO invoice (…) SELECT …`:
 * `id, tenant_id, fulfilment_id, sales_order_id, customer_id, invoice_number, currency_code, total_amount, due_at,
 * issued_by_user_id`. **Not granted, deliberately:** `status` (defaults to `issued`; a writer able to insert it could
 * mint a `paid` or `void` invoice with no payment or reason), `paid_at`, `issued_at` (defaults to the transaction
 * timestamp, which `invoice_due_window` compares `due_at` against — so no PHP clock reaches that CHECK), `created_at`
 * and `updated_at`. The ORM would insert every mapped column and meet `42501`; that is intended, and it is why the
 * writer is SQL (design §19 C3).
 *
 * **Table-level `INSERT` stays false** (M-9): `has_table_privilege('erp_app','invoice','INSERT')` answers false for a
 * column grant, so `GrantFootprintTest::everyRoleHoldsExactlyTheTablePrivilegesTheManifestDeclares` is unmoved and
 * `everyColumnLevelPrivilegeIsExactlyTheDeclaredSet` is what sees these ten, against `SchemaManifest`'s
 * `column_grants`. `theIssuerCanInsertOnlyTheInvoiceColumnsItWrites` holds a hand-typed copy of the ten and runs the
 * statement as `erp_app`, so a joint widening of this file and the manifest still fails.
 *
 * **Transactional (Doctrine's default), on purpose, and separate from the catalogue re-issue**
 * (`Version20260928000300`, design §19 C3 as revised after RED-3 F-7): every catalogue re-issue is
 * `isTransactional(): false` for its `NOT VALID` → `VALIDATE` split, and mixing the two would make this grant
 * commit piecemeal. A failure in the catalogue file leaves this grant complete and the CHECK at its previous list.
 *
 * **`down()` is the exact column REVOKE**, which leaves `attacl` NULL (M-22, measured on the `app_user` precedent:
 * a column REVOKE with no table grant under it clears the entry). No table-level statement is needed in either
 * direction: `erp_app` has held no table-level `INSERT` on `invoice` since `Version20260916000300`.
 *
 * **Locks:** `GRANT`/`REVOKE` take `AccessExclusiveLock` on `invoice` briefly for the catalogue update, no scan. No
 * `lock_timeout`: nothing is deployed and this runs on a destroyed volume (the `Version20260916000300` note).
 */
final class Version20260928000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sales step 6 C3: erp_app may INSERT exactly the ten invoice columns IssueInvoice names (column-level grant).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT INSERT (id, tenant_id, fulfilment_id, sales_order_id, customer_id, invoice_number, currency_code, total_amount, due_at, issued_by_user_id) ON invoice TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE INSERT (id, tenant_id, fulfilment_id, sales_order_id, customer_id, invoice_number, currency_code, total_amount, due_at, issued_by_user_id) ON invoice FROM erp_app');
    }
}
