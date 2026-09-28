<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Sales step 6, C6 — `ReverseInvoicePayment`'s schema and grant: B′'s "a payment on this invoice was ever reversed",
 * and a COLUMN-level `UPDATE` on that one column** (design §19 C6; owner ruling (d) *"B: only you/co-owner"* → `SEC`'s
 * B′, `output9-sales-7-sec.md` §15; RED-3 F-1).
 *
 *  1. **`payment_reversed_at timestamptz` — nullable, set ONCE and never cleared.** Its writer is not the ORM: the entity
 *     maps it `insertable: false, updatable: false`, and `InvoicePaymentReversal` issues, after the flush and inside the
 *     same locked transaction, `UPDATE invoice SET payment_reversed_at = COALESCE(payment_reversed_at, now())` — the
 *     DATABASE clock (the clock `issued_at`'s default and the CHECK below read), and `COALESCE`, so the column means
 *     *"first reversed at"* and a later reversal cannot move it (`SEC` §15, modification 1). C7's `VoidInvoice` reads it:
 *     an invoice whose payment was ever reversed is voidable only by an owner-marker holder (B′).
 *  2. **`invoice_payment_reversed_not_before_issue`** — the backstop that a reversal cannot predate the invoice. With
 *     the value taken from `now()` it cannot fail; it is there so a PHP clock, if one is ever reintroduced, fails loudly.
 *  3. **`GRANT UPDATE (payment_reversed_at) ON invoice TO erp_app`** — exactly the column the writer's second statement
 *     sets; the reversal's ORM flush writes `status`, `paid_at`, `paid_by_user_id` and `updated_at`, already granted by
 *     `Version20260928000400` (M-11, pinned by `InvoicePaymentReversalTest`). **C6 does ship a grant**, which the
 *     design once said it would not (`SEC` §15). ADD COLUMN precedes the GRANT. It is not an actor column: no FK, no ORM
 *     association, no PC-11 exposure; `erp_ai` inherits SELECT on a timestamp (table-level ACL).
 *
 * ⚠ **What no CHECK can hold (G-10 / G-15, stated so nobody calls B′ a database fact):** the grant lets SQL running as
 * `erp_app` set the column back to NULL, and a CHECK cannot forbid that — the condition needs the old value. "Ever
 * reversed" is an APPLICATION fact against a deliberate `erp_app` attacker, exactly like `status`. The closing work is
 * G-15's `BEFORE UPDATE` trigger, which gains *"`payment_reversed_at` never returns to NULL"*.
 *
 * **Transactional; separate from the catalogue re-issue** (`Version20260928000700`, design §19.6 F-7). **`down()`**:
 * the column REVOKE, the CHECK, the column — reverse order.
 */
final class Version20260928000600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sales step 6 C6: invoice.payment_reversed_at (B′, set once), its not-before-issue CHECK, and erp_app UPDATE on that column.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD COLUMN payment_reversed_at timestamptz');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT invoice_payment_reversed_not_before_issue CHECK (payment_reversed_at IS NULL OR payment_reversed_at >= issued_at)');
        $this->addSql('GRANT UPDATE (payment_reversed_at) ON invoice TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (payment_reversed_at) ON invoice FROM erp_app');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT IF EXISTS invoice_payment_reversed_not_before_issue');
        $this->addSql('ALTER TABLE invoice DROP COLUMN IF EXISTS payment_reversed_at');
    }
}
