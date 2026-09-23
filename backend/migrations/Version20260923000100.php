<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Sales step 6, commit C2 — the invoice schema `IssueInvoice` will write into. No operation, and NO GRANT.**
 *
 * - **`fulfilment_id`, NOT NULL**, under the composite `invoice_fulfilment_fk`: an invoice bills one shipment (D-A as
 *   amended 2026-09-13; `ARCH` Q11).
 * - **`issued_by_user_id`, NOT NULL**, under the composite `invoice_issuer_fk`: owner ruling **Q5, *"Column + audit
 *   event"*** (2026-09-23). The `invoice.issued` event ships with its writer in C3.
 * - **`invoice_tenant_fulfilment_not_void`** — at most one live invoice per shipment, and the mechanism §4.2's
 *   `IssueInvoice` `Idem` cell names (API-14). A **unique index, not a constraint**, because PostgreSQL has no partial
 *   unique constraint; a 23505 still reports it by name. Its predicate is a **lifecycle** predicate on a deletion
 *   class `none` table — the one ruled exception to ADR-007 §1's shape, allowed by name in `IdempotencyContractTest`
 *   and, as its own literal, in `SchemaManifestTest`.
 * - **`invoice_number_shape`** — owner ruling **Q12, *"Six (INV-000001)"***: `{6}`, never `{6,}` (IA D-f).
 * - **`invoice_due_window`** — `due_at` from the issue instant to 3650 days later, the upper bound computed **in UTC**
 *   (`ARCH` §3) so that it does not move with the session's `TimeZone`: `timestamptz + interval 'n days'` adds days
 *   on the session's local calendar, which is an hour off across a DST boundary.
 * - **`invoice_total_not_negative`.**
 *
 * **`ADD COLUMN … NOT NULL` with no default fails on the first existing row, on purpose.** `invoice` has no writer in
 * `src/` and holds no row on any database this project runs (measured before this commit: `SELECT count(*) FROM
 * invoice` → 0, `M-4`). A database that did hold invoices would need a backfill decision, and a loud failure is the
 * right way to learn that.
 *
 * **No grant.** New columns inherit the table-level ACL — `erp_app` and `erp_ai` SELECT, nothing else — and no column
 * ACL exists on `invoice` (measured before and after with `has_column_privilege()` / `pg_attribute.attacl`, progress
 * note C2, `M-6`). The column-level INSERT arrives with its caller in C3 (ADR-002 A4.1).
 */
final class Version20260923000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sales step 6 C2: invoice.fulfilment_id + issued_by_user_id (NOT NULL, composite FKs), invoice_tenant_fulfilment_not_void, and the number, due-window and total CHECKs — no grant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD COLUMN fulfilment_id uuid NOT NULL');
        $this->addSql('ALTER TABLE invoice ADD COLUMN issued_by_user_id uuid NOT NULL');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT invoice_fulfilment_fk FOREIGN KEY (tenant_id, fulfilment_id) REFERENCES fulfilment (tenant_id, id)');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT invoice_issuer_fk FOREIGN KEY (tenant_id, issued_by_user_id) REFERENCES app_user (tenant_id, id)');
        $this->addSql("CREATE UNIQUE INDEX invoice_tenant_fulfilment_not_void ON invoice (tenant_id, fulfilment_id) WHERE status <> 'void'");
        $this->addSql("ALTER TABLE invoice ADD CONSTRAINT invoice_number_shape CHECK (invoice_number ~ '^INV-[0-9]{6}$')");
        $this->addSql(<<<'SQL'
            ALTER TABLE invoice ADD CONSTRAINT invoice_due_window CHECK (
                due_at >= issued_at
                AND due_at <= ((issued_at AT TIME ZONE 'UTC') + interval '3650 days') AT TIME ZONE 'UTC'
            )
            SQL);
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT invoice_total_not_negative CHECK (total_amount >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT IF EXISTS invoice_total_not_negative');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT IF EXISTS invoice_due_window');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT IF EXISTS invoice_number_shape');
        $this->addSql('DROP INDEX IF EXISTS invoice_tenant_fulfilment_not_void');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT IF EXISTS invoice_issuer_fk');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT IF EXISTS invoice_fulfilment_fk');
        $this->addSql('ALTER TABLE invoice DROP COLUMN IF EXISTS issued_by_user_id');
        $this->addSql('ALTER TABLE invoice DROP COLUMN IF EXISTS fulfilment_id');
    }
}
