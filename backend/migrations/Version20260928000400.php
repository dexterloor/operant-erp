<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Sales step 6, C5 — `PayInvoice`'s schema and grant: who recorded the payment, the two-way payment invariant, and a
 * COLUMN-LEVEL `UPDATE` on exactly the four columns `markPaid()` writes** (design §19 C5; `ARCH` §3, §4, §7; owner
 * ruling OI-2 of 2026-09-14 — *"`paid_at IS NOT NULL ⇔ status = 'paid'`, as a two-way CHECK"*; `SEC` §2, Q7).
 *
 *  1. **`paid_by_user_id uuid` — nullable**, because an unpaid invoice has no payer. It carries the composite
 *     `invoice_payer_fk (tenant_id, paid_by_user_id) → app_user (tenant_id, id)`, `MATCH SIMPLE`, so a NULL payer is
 *     not checked (the unpaid quadrants). A new column inherits the table-level ACL — SELECT only for `erp_app` and
 *     `erp_ai` (M-1 / M-15) — so the payer is never INSERTable: `IssueInvoice`'s column grant names ten columns and
 *     does not extend to new ones.
 *  2. **`invoice_paid_at_set` is replaced by `invoice_paid_at_matches_status`, in ONE `ALTER TABLE`**, so no moment
 *     exists with neither: `(status = 'paid') = (paid_at IS NOT NULL)`. `status` is NOT NULL and `IS NOT NULL` is
 *     never null, so the boolean equality is exactly ⇔ with no null hole (`ARCH` §3). The one-way CHECK let a
 *     `paid_at` survive on an `issued` or `void` invoice; the reversal (C6) is the first transition that must CLEAR it.
 *  3. **`invoice_paid_by_matches_status`** — the same two-way shape for the payer, so a payment is never recorded
 *     without its recorder, and a reversal cannot leave one behind.
 *  4. **`invoice_paid_not_before_issue`** — a payment cannot be dated before the invoice existed. `PayInvoice` refuses
 *     that case in domain terms first (`invoice.paid_after_issue`), from the database clock; this is the backstop.
 *  5. **`GRANT UPDATE (status, paid_at, paid_by_user_id, updated_at) ON invoice TO erp_app`** — exactly the SET list
 *     of `markPaid()`'s ORM flush (M-11, measured from the statement log), and nothing else. `updated_at` is
 *     genuinely written (`markPaid()`, `void()`), so it is a caller column under A4.4 duty 1's *"exactly"*, not a
 *     convenience. **Table-level `UPDATE` stays false.** Any one granted column also makes `InvoiceRowLock`'s
 *     `FOR NO KEY UPDATE` legal (ADR-002 A4.3 constraint 1; M-21). The ADD COLUMN precedes the GRANT: a GRANT naming
 *     a missing column errors.
 *
 * **Existing rows validate by construction:** every invoice a C3/C4 database can hold is `issued` with `paid_at` and
 * the new column NULL, so each CHECK passes on every row. Every legal transition writes its CHECK-coupled columns in
 * ONE `UPDATE` (pay: `status` + `paid_at` + `paid_by_user_id`; reverse, C6: all three cleared) and a CHECK is
 * evaluated per row at statement end, so no intermediate state is ever visible.
 *
 * **Transactional (Doctrine's default), and separate from the catalogue re-issue** (`Version20260928000500`, design
 * §19.6 F-7): mixing this file with a `NOT VALID` → `VALIDATE` file would make the schema commit piecemeal.
 *
 * **`down()`** revokes the column UPDATE, drops the three new CHECKs, restores `invoice_paid_at_set` under its old name
 * and definition (`Version20260906000100`), and drops the FK and the column — the reverse order. It fails on a
 * database holding a paid invoice whose payer the old CHECK does not care about: it does not, so it succeeds; it
 * loses who recorded each payment, which is what reverting this migration means.
 *
 * **Locks:** each `ADD CONSTRAINT … CHECK` takes `ACCESS EXCLUSIVE` on `invoice` for a scan, and the FK takes `SHARE
 * ROW EXCLUSIVE` on `invoice` and `app_user`. On the tables this runs against (a destroyed volume, nothing deployed)
 * that is milliseconds; **do not copy this file to a live system unguarded** (`ARCH` §3, the `Version20260922000300`
 * note: `lock_timeout`, `NOT VALID` then `VALIDATE`).
 */
final class Version20260928000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sales step 6 C5: invoice.paid_by_user_id, the two-way payment CHECKs, and erp_app UPDATE on the four columns PayInvoice writes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD COLUMN paid_by_user_id uuid');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT invoice_payer_fk FOREIGN KEY (tenant_id, paid_by_user_id) REFERENCES app_user (tenant_id, id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE invoice DROP CONSTRAINT invoice_paid_at_set,
                                ADD  CONSTRAINT invoice_paid_at_matches_status CHECK ((status = 'paid') = (paid_at IS NOT NULL))
            SQL);
        $this->addSql("ALTER TABLE invoice ADD CONSTRAINT invoice_paid_by_matches_status CHECK ((status = 'paid') = (paid_by_user_id IS NOT NULL))");
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT invoice_paid_not_before_issue CHECK (paid_at IS NULL OR paid_at >= issued_at)');
        $this->addSql('GRANT UPDATE (status, paid_at, paid_by_user_id, updated_at) ON invoice TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (status, paid_at, paid_by_user_id, updated_at) ON invoice FROM erp_app');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT IF EXISTS invoice_paid_not_before_issue');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT IF EXISTS invoice_paid_by_matches_status');
        $this->addSql(<<<'SQL'
            ALTER TABLE invoice DROP CONSTRAINT IF EXISTS invoice_paid_at_matches_status,
                                ADD  CONSTRAINT invoice_paid_at_set CHECK (status <> 'paid' OR paid_at IS NOT NULL)
            SQL);
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT IF EXISTS invoice_payer_fk');
        $this->addSql('ALTER TABLE invoice DROP COLUMN IF EXISTS paid_by_user_id');
    }
}
