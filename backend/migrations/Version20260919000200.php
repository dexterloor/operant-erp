<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **The two `INSERT`s `SalesOrderWriter::create()` issues, and nothing else** (Output 9, sales slice 1,
 * commit 2, 2026-09-19).
 *
 * ADR-002 Amendment 4.1: `erp_app` holds only the writes a `src/` caller issues, and *"the migration that
 * ships the first caller grants the write and edits `SchemaManifest` in the same commit"*. This is that
 * migration. `Version20260916000300` revoked `INSERT, UPDATE, DELETE` on both tables from `erp_app`
 * (its `up()`, `:82` and `:83`) because no caller existed; `CreateSalesOrder` is the first, and it
 * inserts. ⚠ **This read `:109`, `:110` until the RED exit audit (F-7) measured it**: those two lines are
 * the same file's `down()`, `GRANT INSERT, UPDATE, DELETE ON sales_order TO erp_app` — the statements
 * that *restore* what the sentence says was revoked. The fact was right and the pointer was inverted, in
 * the migration whose whole subject is grant narrowness.
 *
 * ---------------------------------------------------------------------------
 * `INSERT` only — no `UPDATE`, and the omission is the decision (ruling R-B)
 * ---------------------------------------------------------------------------
 * The obvious shape copies `purchase_order`'s `APP_INSERT_UPDATE`. It would be wrong here, and the reason
 * is a set of statements rather than a precedent:
 *
 *   - `purchase_order_line` holds `UPDATE` because `PurchaseOrderWriter::updateLine()` and `::archiveLine()`
 *     issue raw `UPDATE`s, and because `PurchaseOrderRowLock`'s `FOR NO KEY UPDATE` needs the privilege
 *     even with no `UPDATE` statement (ADR-002 A4.3 constraint 1). **This slice ships no line update, no
 *     line delete, no order delete and no row lock** — `create()` has no existing row to lock.
 *   - `purchase_order` holds `UPDATE` partly for `recomputeTotal()`. **`CreateSalesOrder` issues no
 *     `UPDATE` at all:** `sales_order.total_amount` is computed inside the order's own `INSERT`, from a
 *     `VALUES` list of the request's lines, in `numeric` throughout (S-12).
 *   - `sales_order_cascade_deletion` is `AFTER UPDATE OF deleted_at … WHEN (OLD.deleted_at IS DISTINCT FROM
 *     NEW.deleted_at)` (`Version20260906000100.php:1153-1156`), so **it cannot fire on an `INSERT`** and
 *     A4.4 duty 3's antecedent — a grant on a cascade parent implying one on its child — is never met.
 *
 * A4.4 duty 1's word is *"exactly"*. **The cost asymmetry is the ground, and it is stated rather than
 * assumed:** narrow-and-wrong costs one migration and one deleted expression, visible in the diff and loud
 * at runtime (`42501`). Wide-and-wrong leaves two revenue tables writable with no caller, **silently** —
 * and A4.5, the control that would detect it, is carried and unbuilt (`ADR-002:704-715`). Over-grant is the
 * undetectable direction, and `Version20260916000300` removed these exact privileges because they left
 * *"in-tenant self-escalation one statement away"*.
 *
 * ⚠ **The first build that grants ANY `UPDATE` on `sales_order` — a confirm or a cancel, not only a soft
 * delete — grants `UPDATE` on `sales_order_line` in the same migration**, because the cascade trigger runs
 * as the invoker (`GrantFootprintTest::aRoleThatCanUpdateACascadeParentCanUpdateAndReadItsChild`). That
 * standing note is `SchemaManifest`'s and is unchanged by this migration; it is repeated here because this
 * is the file the next grant will be copied from.
 *
 * ---------------------------------------------------------------------------
 * `erp_ai` is untouched
 * ---------------------------------------------------------------------------
 * It holds `SELECT` on both tables by the tenant default and gains nothing here. The footprint this
 * migration produces is asserted against the manifest, server-derived, by
 * `GrantFootprintTest::everyRoleHoldsExactlyTheTablePrivilegesTheManifestDeclares` on every run — so the
 * `grants` key added to `SchemaManifest` in this commit is a claim the cluster checks, not a comment.
 */
final class Version20260919000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-002 A4.1: grant erp_app the two INSERTs SalesOrderWriter::create issues, and no UPDATE (ruling R-B)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT INSERT ON sales_order TO erp_app');
        $this->addSql('GRANT INSERT ON sales_order_line TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE INSERT ON sales_order FROM erp_app');
        $this->addSql('REVOKE INSERT ON sales_order_line FROM erp_app');
    }
}
