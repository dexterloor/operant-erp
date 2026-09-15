<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **ADR-002 Amendment 4: `erp_app` holds only the writes its `src/` callers issue** (V3-4 + RV3-1, V4 batch B7, 2026-09-16).
 *
 * ADR-002 §1 gave `erp_app` "CRUD, RLS-bound", and `SchemaManifest::CRUD` was the default grant for every tenant table.
 * So the application login could INSERT, UPDATE and DELETE on tables no production code writes — among them `role`,
 * `role_permission` and `user_role`, which left in-tenant self-escalation one statement away for any SQL running as
 * `erp_app`. This revokes exactly those unused writes: **51 `(table, verb)` pairs, 17 each of DELETE, INSERT and UPDATE.**
 *
 * **What `erp_app` keeps** (A4.3's expected footprint, reproduced by the authoritative M-4 run on a destroyed volume on
 * 2026-09-16 with no difference — `docs/session10/progress-B7.md`):
 *
 *   SELECT, INSERT, UPDATE  inventory_item, purchase_order, purchase_order_line, stock_level
 *   SELECT, UPDATE          app_user (ORM flushes in LoginRecorder and ChangePassword)
 *   SELECT, INSERT          audit_log, stock_movement (both unchanged), goods_receipt, goods_receipt_line
 *   SELECT                  every other tenant table, including idempotency_key (no `src/` writer)
 *
 * Two kept UPDATEs are implied rather than issued as `UPDATE` statements, and must never be narrowed away:
 *   - `purchase_order`: `PurchaseOrderRowLock::SQL` takes `FOR NO KEY UPDATE`, and a locking clause needs UPDATE on every table
 *     it locks (constraint 1: every table in FROM when `OF` is absent, sub-selects, CTEs, and `FOR KEY SHARE` too);
 *   - `purchase_order_line`: `purchase_order_cascade_deletion` runs `cascade_deletion_to_lines` as the invoker, so a role
 *     with UPDATE on the parent needs UPDATE and SELECT on the child (constraint 2). `sales_order` and `sales_order_line`
 *     lose UPDATE together here, and are re-granted together by the first build that grants any UPDATE on `sales_order`.
 *     `GrantFootprintTest::aRoleThatCanUpdateACascadeParentCanUpdateAndReadItsChild` holds the pairing.
 * `stock_level` keeps UPDATE for `StockReceiver`'s `ON CONFLICT DO UPDATE`.
 *
 * **Literal statements, one per table, deliberately.** No `ON ALL TABLES IN SCHEMA` (it would reach tables outside the
 * list), and nothing here reads `SchemaManifest`: migrations are frozen history (`Version20260906000100`'s docblock), and
 * a migration that computed its grants from today's manifest would replay differently tomorrow. `SchemaManifest` is
 * edited in the same commit and `GrantFootprintTest` asserts the result in both directions.
 *
 * **Table-level REVOKE is sufficient:** `erp_app` holds no column-level privilege on any table (measured), and
 * `GrantFootprintTest::everyColumnLevelPrivilegeIsExactlyTheDeclaredSet` fails if a column grant ever re-widens a table.
 *
 * **`down()` re-grants exactly the same 51 pairs** from the same literal list — never `Version20260906000100`'s
 * `GRANT … ON ALL TABLES` down, which would over-widen. Measured on 2026-09-16: `migrate prev` then `migrate latest`
 * returns `has_table_privilege` for `erp_app` over every table to the before/after state byte for byte.
 *
 * **Duties this leaves on every later build** (ADR-002 A4.4): the migration that ships a writer grants exactly the
 * INSERT/UPDATE/DELETE its callers issue, including implied writes, and edits `SchemaManifest` in the same commit; a
 * `42501` from a new writer is fixed by granting that writer's privilege, never by restoring the old CRUD footprint; the
 * first DELETE grant on any table adds an `erp_app` RLS-DELETE isolation test on that table.
 *
 * **Locks:** `REVOKE`/`GRANT` take `AccessExclusiveLock` on each table briefly for the catalogue update and neither scans
 * nor rewrites. **No `lock_timeout` is set because nothing is deployed and this runs on a destroyed volume;** on a live
 * system the lock queues behind long-running queries and blocks everything behind it, so do not copy this pattern there
 * without one (as `Version20260907000100` records). An open pooled `erp_app` backend sees the change on its next
 * statement: privileges are checked at executor start.
 */
final class Version20260916000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-002 A4: revoke the 51 erp_app write privileges no src/ caller uses (17 DELETE, 17 INSERT, 17 UPDATE), literal per table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('REVOKE INSERT, DELETE ON app_user FROM erp_app');
        $this->addSql('REVOKE INSERT, UPDATE, DELETE ON customer FROM erp_app');
        $this->addSql('REVOKE INSERT, UPDATE, DELETE ON customer_address FROM erp_app');
        $this->addSql('REVOKE INSERT, UPDATE, DELETE ON customer_contact FROM erp_app');
        $this->addSql('REVOKE INSERT, UPDATE ON fulfilment FROM erp_app');
        $this->addSql('REVOKE INSERT, UPDATE ON fulfilment_line FROM erp_app');
        $this->addSql('REVOKE UPDATE ON goods_receipt FROM erp_app');
        $this->addSql('REVOKE UPDATE ON goods_receipt_line FROM erp_app');
        $this->addSql('REVOKE INSERT ON idempotency_key FROM erp_app');
        $this->addSql('REVOKE DELETE ON inventory_item FROM erp_app');
        $this->addSql('REVOKE INSERT, UPDATE ON invoice FROM erp_app');
        $this->addSql('REVOKE DELETE ON purchase_order FROM erp_app');
        $this->addSql('REVOKE DELETE ON purchase_order_line FROM erp_app');
        $this->addSql('REVOKE INSERT, UPDATE, DELETE ON role FROM erp_app');
        $this->addSql('REVOKE INSERT, UPDATE, DELETE ON role_permission FROM erp_app');
        $this->addSql('REVOKE INSERT, UPDATE, DELETE ON sales_order FROM erp_app');
        $this->addSql('REVOKE INSERT, UPDATE, DELETE ON sales_order_line FROM erp_app');
        $this->addSql('REVOKE DELETE ON stock_level FROM erp_app');
        $this->addSql('REVOKE INSERT, UPDATE, DELETE ON storage_location FROM erp_app');
        $this->addSql('REVOKE INSERT, UPDATE, DELETE ON supplier FROM erp_app');
        $this->addSql('REVOKE INSERT, UPDATE, DELETE ON supplier_product FROM erp_app');
        $this->addSql('REVOKE INSERT, UPDATE, DELETE ON user_role FROM erp_app');
        $this->addSql('REVOKE INSERT, UPDATE, DELETE ON warehouse FROM erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('GRANT INSERT, DELETE ON app_user TO erp_app');
        $this->addSql('GRANT INSERT, UPDATE, DELETE ON customer TO erp_app');
        $this->addSql('GRANT INSERT, UPDATE, DELETE ON customer_address TO erp_app');
        $this->addSql('GRANT INSERT, UPDATE, DELETE ON customer_contact TO erp_app');
        $this->addSql('GRANT INSERT, UPDATE ON fulfilment TO erp_app');
        $this->addSql('GRANT INSERT, UPDATE ON fulfilment_line TO erp_app');
        $this->addSql('GRANT UPDATE ON goods_receipt TO erp_app');
        $this->addSql('GRANT UPDATE ON goods_receipt_line TO erp_app');
        $this->addSql('GRANT INSERT ON idempotency_key TO erp_app');
        $this->addSql('GRANT DELETE ON inventory_item TO erp_app');
        $this->addSql('GRANT INSERT, UPDATE ON invoice TO erp_app');
        $this->addSql('GRANT DELETE ON purchase_order TO erp_app');
        $this->addSql('GRANT DELETE ON purchase_order_line TO erp_app');
        $this->addSql('GRANT INSERT, UPDATE, DELETE ON role TO erp_app');
        $this->addSql('GRANT INSERT, UPDATE, DELETE ON role_permission TO erp_app');
        $this->addSql('GRANT INSERT, UPDATE, DELETE ON sales_order TO erp_app');
        $this->addSql('GRANT INSERT, UPDATE, DELETE ON sales_order_line TO erp_app');
        $this->addSql('GRANT DELETE ON stock_level TO erp_app');
        $this->addSql('GRANT INSERT, UPDATE, DELETE ON storage_location TO erp_app');
        $this->addSql('GRANT INSERT, UPDATE, DELETE ON supplier TO erp_app');
        $this->addSql('GRANT INSERT, UPDATE, DELETE ON supplier_product TO erp_app');
        $this->addSql('GRANT INSERT, UPDATE, DELETE ON user_role TO erp_app');
        $this->addSql('GRANT INSERT, UPDATE, DELETE ON warehouse TO erp_app');
    }
}
