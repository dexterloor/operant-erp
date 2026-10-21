<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **OQ-1, ruled: an archived purchase order is always a draft** (order restores C1, 2026-10-21;
 * `docs/session13/order-restores-ruling.md`). The purchase twin of `Version20260921000300`.
 *
 * Until this commit purchasing was draft-only in `PurchaseOrderWriter::archive()` alone: the database accepted an
 * archived `ordered` order from any writer that skipped the edit window. The owner ruled on 2026-10-21 that the rule is
 * made a mechanism, as it is for sales, before anything restores a purchase order:
 *
 *   - **this CHECK** — `purchase_order_archived_only_while_draft CHECK (deleted_at IS NULL OR status = 'draft')`, so no
 *     writer, raw SQL included, can produce an archived non-draft. It also **freezes an archived order's status**: it
 *     cannot be placed, received or cancelled underneath the API either;
 *   - which lets `RestorePurchaseOrder` (order restores C3) carry no draft guard of its own — the CHECK is the guard.
 *
 * If `DeletePurchaseOrder`'s window is ever widened, the first archived non-draft is a `23514` → HTTP 500 rather than a
 * silent wrong state, and the question is the owner's again (`EditWindowGuardTest` pins the window).
 *
 * ---------------------------------------------------------------------------------------------------
 * ⚠ `ADD CONSTRAINT` VALIDATES EXISTING ROWS — and that is kept, not `NOT VALID`
 * ---------------------------------------------------------------------------------------------------
 * Measured before this commit (T-4): `SELECT count(*) FROM purchase_order WHERE deleted_at IS NOT NULL AND status <>
 * 'draft'` → 0 on the local database; no `src/` path has ever archived a non-draft (`archive()` refuses one). Four
 * shipped tests did, as owner SQL; they are re-seeded as archived drafts (one retires) in this commit. `make reset`
 * builds from an empty volume and is unaffected. A developer volume holding the residue of an interrupted run of one of
 * those tests from before this commit makes `make migrate` fail here with `23514`; the remedy is `make reset`, which is
 * what the gate runs anyway. `NOT VALID` would let such a row survive while claiming the property.
 *
 * Not a time comparison: `TimeComparisonCheckInventoryTest` is unmoved (one timestamp column, no clock function).
 *
 * **Grants nothing.** A CHECK is not a privilege.
 */
final class Version20261021000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OQ-1: a purchase order can be archived only while it is a draft';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE purchase_order ADD CONSTRAINT purchase_order_archived_only_while_draft CHECK (deleted_at IS NULL OR status = 'draft')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_order DROP CONSTRAINT purchase_order_archived_only_while_draft');
    }
}
