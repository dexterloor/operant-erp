<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **`ConfirmSalesOrder`'s `UPDATE`, on BOTH sales tables** (Output 9, sales slice 1, `ConfirmSalesOrder`).
 *
 * ADR-002 Amendment 4.1: *"the migration that ships the first caller grants the write and edits
 * `SchemaManifest` in the same commit"*. `SalesOrder::confirm()` writes `status`, `ordered_at` and
 * `updated_at`, so this is that migration. `Version20260919000200` granted `INSERT` and deliberately no
 * `UPDATE` (ruling R-B), and its own closing paragraph named the commit that would widen it: *"the first
 * build that grants ANY `UPDATE` on `sales_order` — a confirm or a cancel, not only a soft delete —
 * grants `UPDATE` on `sales_order_line` in the same migration"*. This is that build.
 *
 * ---------------------------------------------------------------------------
 * Why the child is not optional, and why it was MEASURED rather than assumed
 * ---------------------------------------------------------------------------
 * `sales_order_cascade_deletion` runs `cascade_deletion_to_lines`, which is `SECURITY INVOKER` and issues
 * `UPDATE sales_order_line SET deleted_at = …` as whoever fired it. A table-level `UPDATE` on the parent
 * makes `SET deleted_at` reachable for `erp_app` whether or not any `src/` caller soft-deletes an order
 * today, so the child's `UPDATE` is implied by ADR-002 A4.4 duty 3 the moment the parent's is granted.
 *
 * **P-4, run at build time rather than predicted (2026-09-19):** this migration was first written with
 * `GRANT UPDATE ON sales_order` alone. `GrantFootprintTest` answered with exactly one violation —
 * *"erp_app can UPDATE sales_order but lacks UPDATE on sales_order_line"* — under the message *"A role can
 * soft-delete a cascade parent but cannot run the cascade it fires as the invoker."* The obligation
 * `SchemaManifest` states in prose is therefore enforced server-side on every run, and order-only is red
 * by construction, not by convention.
 *
 * ---------------------------------------------------------------------------
 * `UPDATE` only — no `DELETE`, and the omission is still the decision
 * ---------------------------------------------------------------------------
 * A4.4 duty 1's word is *"exactly"*. Archival on both tables is a soft delete, which is an `UPDATE` of
 * `deleted_at`; nothing in `src/` removes a sales row, and `DeleteSalesOrder` is unbuilt. The row lock
 * `SalesOrderRowLock` takes (`FOR NO KEY UPDATE`) needs `UPDATE` on `sales_order` and on nothing else —
 * it names one table, precisely so a locking clause on a join cannot demand privileges on `customer`,
 * `warehouse` or `app_user` (A4.3 constraint 1).
 *
 * `erp_ai` is untouched: it holds `SELECT` on both tables by the tenant default and gains nothing here.
 * The footprint this migration produces is asserted against `SchemaManifest`, server-derived, by
 * `GrantFootprintTest::everyRoleHoldsExactlyTheTablePrivilegesTheManifestDeclares` on every run.
 */
final class Version20260919000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-002 A4.1: grant erp_app the UPDATE SalesOrder::confirm() issues, on sales_order and — A4.4 duty 3, the invoker cascade trigger — on sales_order_line with it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT UPDATE ON sales_order TO erp_app');
        $this->addSql('GRANT UPDATE ON sales_order_line TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE ON sales_order FROM erp_app');
        $this->addSql('REVOKE UPDATE ON sales_order_line FROM erp_app');
    }
}
