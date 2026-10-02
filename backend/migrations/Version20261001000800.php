<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sales step 7 C1b — `IssueAdjustmentCreditNote`'s permission code, in the commit that ships its caller and its SoD-3
 * entry (ADR-008 A2.1c / A2.8, approved 2026-09-30 under the owner's delegation, owner ruling Q-A = B′).
 *
 * **The code:** `credit_note.adjust`, module `Modules\Sales`. A credit for goods the customer KEEPS reduces a receivable
 * with no return behind it, so it is on SoD-3's controlled half from the moment it exists — it never enters the
 * catalogue or this table without that entry (A2.6), so `admin` never holds it (PC-14) and **no holder listing is owed**.
 * Held by `owner` alone among the default roles; delegable only as `invoice.void` is (E-15e).
 *
 * **No grant.** The writer is `CreditNoteWriter`, whose INSERT on `credit_note` / `credit_note_line` shipped at C1
 * (`Version20261001000700`) and names `sales_return_id`, which an adjustment leaves NULL — the same columns, no new one.
 */
final class Version20261001000800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sales step 7 C1b: permission row credit_note.adjust (A2.1c).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO permission (code, module, description) VALUES
                ('credit_note.adjust', 'Modules\Sales', 'credit note: adjust')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM permission WHERE code = 'credit_note.adjust'");
    }
}
