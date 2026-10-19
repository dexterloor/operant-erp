<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Stock transfer C0 — `stock_movement`'s two source CHECKs, both or neither** (`docs/session13/stock-transfer-design.md`
 * Revision 2 §C0; `DATA`'s ruling `stock-transfer-data.md` §1–§3; `SEC` recommended the second, `stock-transfer-sec.md` §2).
 *
 * - **`stock_movement_source_paired`** — `(source_type IS NULL) = (source_id IS NULL)`. The receipt, shipment and return
 *   writers set both; the count writes both NULL; the transfer writer (C1) sets both. It refuses nothing a shipped writer
 *   posts and catches a writer, or SQL as `erp_app`, setting one without the other.
 * - **`stock_movement_transfer_source`** — `(source_type IS NOT DISTINCT FROM 'stock_movement') = (reason IN ('transfer_out',
 *   'transfer_in'))`. `'stock_movement'` in `source_type` means "names the partner leg of a transfer" and nothing else, and
 *   every transfer leg carries it. **`IS NOT DISTINCT FROM` is load-bearing:** with `=`, a NULL `source_type` makes the CHECK
 *   NULL, a CHECK passes on NULL, and an unlinked `transfer_*` leg would be accepted.
 *
 * Neither predicate can be NULL (`IS NULL` on both sides of the first; `IS NOT DISTINCT FROM` and `reason NOT NULL` in the
 * second), so no row escapes either by NULL. Together they guarantee every transfer leg carries `'stock_movement'` **and** a
 * non-null `source_id`; neither alone does. They do **not** guarantee that `source_id` names a real row of the opposite
 * reason naming it back — no FK, `source_id` is polymorphic; that is `StockTransferrer`'s (C1) application rule.
 *
 * **Widen deliberately.** Adding a third transfer reason widens `…_transfer_source` in the same migration as
 * `stock_movement_reason_values`. The CHECK also forecloses two plausible future writers (final RED L-2): a reversal
 * movement that names the movement it reverses (`source_type 'stock_movement'`, reason `adjustment`), and an in-transit
 * transfer naming a `stock_transfer` document — both must widen it on purpose, never by loosening it.
 *
 * **Locks, and the `isTransactional()` override** (`Version20260919000400`'s measured form):
 *   ADD CONSTRAINT … CHECK … NOT VALID  ->  AccessExclusiveLock, no scan
 *   VALIDATE CONSTRAINT                 ->  ShareUpdateExclusiveLock, scans; writers proceed
 * Without the override Doctrine wraps all six statements in one transaction, the `AccessExclusiveLock` from the `ADD` is held
 * across the `VALIDATE` scan, and the split buys nothing. Each statement therefore commits alone, so each `ADD` is preceded by
 * `DROP CONSTRAINT IF EXISTS` to make a re-run after a failure possible; on a first run the `DROP` removes nothing (neither
 * constraint existed before this file).
 *
 * **If `VALIDATE` fails on a used database, that is a finding:** a row written outside the four writers (manual SQL), to be
 * classified — never a constraint to loosen. On every tuple a shipped writer or test fixture produces, both hold
 * (`stock-transfer-data.md` §3.1).
 *
 * No grant (a CHECK binds every role, and needs none). No index: the PK and the existing partial
 * `stock_movement_tenant_source_idx` serve both lookup directions (`stock-transfer-data.md` §5).
 *
 * @see \App\Tests\Tenancy\StockMovementSourceSchemaTest for the definitions read back, `convalidated`, and the refusals
 */
final class Version20261019000800 extends AbstractMigration
{
    /**
     * See "Locks" above: without this the `NOT VALID` / `VALIDATE` split runs inside one transaction and holds
     * `AccessExclusiveLock` across the validation scan.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Stock transfer C0: stock_movement_source_paired and stock_movement_transfer_source CHECKs (NOT VALID, then VALIDATE)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT IF EXISTS stock_movement_source_paired');
        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT IF EXISTS stock_movement_transfer_source');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT stock_movement_source_paired CHECK ((source_type IS NULL) = (source_id IS NULL)) NOT VALID');
        $this->addSql("ALTER TABLE stock_movement ADD CONSTRAINT stock_movement_transfer_source CHECK ((source_type IS NOT DISTINCT FROM 'stock_movement') = (reason IN ('transfer_out', 'transfer_in'))) NOT VALID");
        $this->addSql('ALTER TABLE stock_movement VALIDATE CONSTRAINT stock_movement_source_paired');
        $this->addSql('ALTER TABLE stock_movement VALIDATE CONSTRAINT stock_movement_transfer_source');
    }

    /** Before this migration neither constraint existed, so a plain drop, in reverse order, is the honest reverse. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT IF EXISTS stock_movement_transfer_source');
        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT IF EXISTS stock_movement_source_paired');
    }
}
