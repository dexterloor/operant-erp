<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **CD-4, ruled: an archived sales order is always a draft** (Output 9, sales slice 5, commit 4, 2026-09-21).
 *
 * `RESIDUAL_GAPS.md` CD-4 asked what a line's `quantity_outstanding` means on an order archived while `confirmed`:
 * `SalesOrderLineRow` would fall through to `quantity - quantity_fulfilled` and report outstanding work on a deleted
 * document. The owner ruled **option (i)** on 2026-09-21: under L-8 (*"a document is deletable only while
 * `status = 'draft'`"*) that state has no shipped path, so make it impossible rather than define it. Two halves:
 *
 *   - the API half — `DeleteSalesOrder`'s edit-window refusal (§8.3a), which ships after this commit;
 *   - **this half** — `sales_order_archived_only_while_draft CHECK (deleted_at IS NULL OR status = 'draft')`, so no
 *     writer, raw SQL included, can produce an archived non-draft. It also **freezes an archived order's status**: a
 *     transition cannot run on a discarded order underneath the API either.
 *
 * If `DeleteSalesOrder`'s window is ever widened, the first archived non-draft is a `23514` → HTTP 500 rather than a
 * silent wrong number, and CD-4 is open again and is the owner's.
 *
 * ---------------------------------------------------------------------------------------------------
 * ⚠ `ADD CONSTRAINT` VALIDATES EXISTING ROWS — and that is kept, not `NOT VALID`
 * ---------------------------------------------------------------------------------------------------
 * Measured before this commit: no shipped path has ever produced an archived non-draft, and no sales order has ever
 * been archived outside a test. `make reset` builds from an empty volume and is unaffected. **A developer volume that
 * holds the residue of an interrupted `DeletionPolicyTest` run from before this commit** — a `confirmed` order archived
 * as the owner (`SO-CASCADE`) — makes `make migrate` fail here with `23514`. The remedy is `make reset`, which is what
 * the gate runs anyway. `NOT VALID` would let such a row survive while claiming the property, which is the one thing a
 * constraint must never do.
 *
 * **Sales only, deliberately.** The purchase twin has no such CHECK; `PurchaseOrderWriter::archive()` refuses a
 * non-draft in the writer, and the asymmetry is recorded rather than fixed here — the ruling was about sales.
 *
 * **Grants nothing.** A CHECK is not a privilege.
 */
final class Version20260921000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CD-4 (i): a sales order can be archived only while it is a draft';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE sales_order ADD CONSTRAINT sales_order_archived_only_while_draft CHECK (deleted_at IS NULL OR status = 'draft')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order DROP CONSTRAINT sales_order_archived_only_while_draft');
    }
}
