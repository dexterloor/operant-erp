<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sales step 7 C1 — `IssueCreditNote`'s permission code and its writer's grant, in the commit that ships the caller
 * (ADR-002 A4.1).
 *
 * **The code** (owner rulings Q-F, Q-B; ADR-008 A2.1b, applied in this commit with SoD-5): `credit_note.issue`, module
 * `Modules\Sales`, description derived from the code as `Version20260918000100` derives them. It enters the
 * `permission` table in the SAME commit as its SoD-3 entry (A2.1b) and SoD-5's row (A2.2/A2.7), so no tenant can be
 * granted it before the separation that governs it exists — which is why no holder listing is owed (pointer item 4).
 * No `role_permission` row is written: no tenant holds seeded system roles (the per-tenant seed is deferred; measured
 * 2026-10-01, see the build log).
 *
 * **The grant** — exactly what `CreditNoteWriter` issues:
 * - `credit_note`: a **column-level** `INSERT` on the eleven columns its one `INSERT … SELECT` names. Never
 *   `issued_at`, `created_at` or `updated_at`, which default — so SQL as `erp_app` cannot backdate a credit note's issue
 *   (`IssueInvoice`'s precedent, `Version20260928000200`). Table-level INSERT stays false.
 * - `credit_note_line`: a **table-level** `INSERT` — the writer names every column but the two timestamps, so a column
 *   grant would protect nothing (`fulfilment_line`'s and `sales_return_line`'s precedent).
 * Never UPDATE, DELETE or TRUNCATE on either: nothing edits an issued document (OI-2), and TB-4's Credited segment is
 * monotonic only while no role can (`CreditedSegmentLapseTest`). `invoice` needs nothing: the writer reads it and
 * takes `InvoiceRowLock`, which rides `Version20260928000400`'s column UPDATE.
 */
final class Version20261001000700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sales step 7 C1: permission row credit_note.issue; erp_app column INSERT on credit_note and INSERT on credit_note_line.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO permission (code, module, description) VALUES
                ('credit_note.issue', 'Modules\Sales', 'credit note: issue')
            SQL);

        $this->addSql(<<<'SQL'
            GRANT INSERT (id, tenant_id, invoice_id, sales_return_id, credit_note_number, reason, currency_code,
                          total_amount, issued_by_user_id, idempotency_key, request_digest)
               ON credit_note TO erp_app
            SQL);
        $this->addSql('GRANT INSERT ON credit_note_line TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE INSERT ON credit_note_line FROM erp_app');
        $this->addSql(<<<'SQL'
            REVOKE INSERT (id, tenant_id, invoice_id, sales_return_id, credit_note_number, reason, currency_code,
                           total_amount, issued_by_user_id, idempotency_key, request_digest)
                ON credit_note FROM erp_app
            SQL);
        $this->addSql("DELETE FROM permission WHERE code = 'credit_note.issue'");
    }
}
