<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **CD-7 — every sales-order, purchase-order, fulfilment and goods-receipt number is six digits wide, by CHECK.**
 *
 * Owner ruling CD7-1 (2026-09-28, *"Yes, all four"*): the invoice cap of Q12 (`invoice_number_shape`,
 * `Version20260923000100`) now binds the other four series. `IA.md` R-1 / R-1a / D-f: document numbers sort in
 * creation order **lexically** because the representation is **fixed-width** — `[0-9]{6}` is the constraint and
 * `[0-9]{6,}` is the defect. Before this migration the writers zero-padded to six with no cap, so the 1,000,000th
 * number of a kind (`…1000000`) text-sorted beside `…100000` on `sort=order_number` (`RESIDUAL_GAPS.md` CD-7).
 *
 * The writers refuse the 1,000,000th number in domain terms (409 `business-rule-violated`,
 * `<table>.number_series_is_exhausted`), **after** their replay lookup, so a retry of the 999,999th create still
 * returns its own document. **These CHECKs are the backstop**: a writer that forgets its guard is refused with a
 * `23514` → HTTP 500, which is loud by design — no `23514` → domain translation is added (`RED` F13).
 *
 * ---------------------------------------------------------------------------------------------------
 * ⚠ `ADD CONSTRAINT` VALIDATES EXISTING ROWS — kept, not `NOT VALID` (`RED` F9; the `Version20260921000300` precedent)
 * ---------------------------------------------------------------------------------------------------
 * Each statement takes `ACCESS EXCLUSIVE` on its table and scans it. `make reset` builds from an empty volume and is
 * unaffected, and no hosted database exists. **A developer volume holding the residue of an interrupted test run from
 * before this commit** (`PO-1`, `SO-CASCADE`, …) makes `make migrate` fail here with `23514`. The remedy is
 * `make reset`, which is what the gate runs anyway. `NOT VALID` would let such a row survive while claiming the
 * property, which is the one thing a constraint must never do.
 *
 * **Grants nothing.** A CHECK is not a privilege (measured before and after with `has_table_privilege()`, progress
 * note `docs/session13/cd7-progress.md`).
 */
final class Version20260928000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CD-7: six-digit number shape CHECKs on sales_order, purchase_order, fulfilment and goods_receipt (validated) — no grant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE sales_order ADD CONSTRAINT sales_order_number_shape CHECK (order_number ~ '^SO-[0-9]{6}$')");
        $this->addSql("ALTER TABLE purchase_order ADD CONSTRAINT purchase_order_number_shape CHECK (order_number ~ '^PO-[0-9]{6}$')");
        $this->addSql("ALTER TABLE fulfilment ADD CONSTRAINT fulfilment_number_shape CHECK (fulfilment_number ~ '^FUL-[0-9]{6}$')");
        $this->addSql("ALTER TABLE goods_receipt ADD CONSTRAINT goods_receipt_number_shape CHECK (receipt_number ~ '^GR-[0-9]{6}$')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE goods_receipt DROP CONSTRAINT IF EXISTS goods_receipt_number_shape');
        $this->addSql('ALTER TABLE fulfilment DROP CONSTRAINT IF EXISTS fulfilment_number_shape');
        $this->addSql('ALTER TABLE purchase_order DROP CONSTRAINT IF EXISTS purchase_order_number_shape');
        $this->addSql('ALTER TABLE sales_order DROP CONSTRAINT IF EXISTS sales_order_number_shape');
    }
}
