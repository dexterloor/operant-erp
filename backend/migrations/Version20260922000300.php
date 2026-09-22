<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **H0 — ADR-002 A4.0: `erp_app`'s UPDATE on `app_user` becomes column-level, and covers exactly the three
 * columns its two `src/` callers write.**
 *
 * The callers, both ORM flushes on `erp_app` (the default entity manager is pinned to `connection: app`):
 * - `LoginRecorder::record` → `AppUser::recordLogin()` → `UPDATE app_user SET last_login_at, updated_at`;
 * - `ChangePassword` → `AppUser::replacePasswordHash()` → `UPDATE app_user SET password_hash, updated_at`.
 * Both SET lists were measured from the server log on 2026-09-22, not inferred from Doctrine's internals. The
 * union is the grant. Nothing in `src/` creates, deletes, deactivates or renames a user.
 *
 * **What this buys.** SQL running as `erp_app` can no longer write `is_active`, `deleted_at`, `email`,
 * `display_name`, `id`, `tenant_id` or `created_at`. That closes the `is_active` and `deleted_at` routes to a
 * lockout, the `email` rewrite (a takeover route the day a reset or invitation mail exists, API-O-12), a forged
 * `display_name` on every document that names its actor, and rewriting a user's identity columns.
 *
 * **What this does NOT buy — read before citing this migration as closing anything wider.**
 * - **Lockout is not closed.** `password_hash` stays writable because `ChangePassword` writes it. SQL as
 *   `erp_app` can overwrite a victim's hash, which refuses every later sign-in with the real password, and the
 *   victim cannot recover through `ChangePassword` (it needs the current password) or a reset (none exists).
 * - **The credential takeover is not closed**, for the same reason: copy a known hash onto the victim's row.
 *   That is `(e)-cred`'s job (`RESIDUAL_GAPS.md` G-12), not this migration's.
 * - **`last_login_at` and `updated_at` stay forgeable.** Both are written by a caller, so both are granted, and
 *   after a takeover they can be rewritten to hide the sign-in. Nothing closes that.
 * The honest summary: H0 closes named *routes*, not a hazard class.
 *
 * **D-B order is load-bearing.** A column-level `REVOKE` under a table-level grant changes nothing, so `up()`
 * revokes the table-level privilege first and then grants the columns. `Version20260916000300`'s sentence
 * *"`erp_app` holds no column-level privilege on any table"* is **false from this migration on**; that migration
 * is frozen history and is not edited — this docblock records the change.
 *
 * **`down()` revokes the columns before re-granting the table, and the first line is NOT redundant.** Measured
 * 2026-09-22 (rolled back): a table-level `GRANT UPDATE` leaves existing column entries in `attacl`, while a
 * table-level `REVOKE UPDATE` strips them. Without the column REVOKE, `down()` would leave three `erp_app=w`
 * column entries under the restored table grant, and `has_column_privilege()` cannot see them (the table grant
 * masks them). Only `pg_attribute.attacl` can. Do not delete that line as tidy-up.
 *
 * **What does not change:** SELECT stays table-level (ORM hydration reads every column); RLS, FORCE and the
 * policy; `erp_auth`'s column SELECT; `erp_ai`, `erp_authlog` and `erp_ratelimit` (none holds anything here).
 * `ALTER DEFAULT PRIVILEGES` does not interact: it applies to objects created later, and `app_user` exists.
 *
 * **Grant with the caller (ADR-002 A4.4 duty 1).** A new `app_user` column now inherits table-level SELECT but
 * **no** UPDATE. E-15g's deactivate/delete writers grant `UPDATE (is_active, deleted_at)` in their own migration;
 * `(e)-cred` grants `UPDATE (password_mac)` in its own. A `42501` from such a writer is fixed by granting that
 * column, **never by restoring table-level UPDATE**. Keep at least one column UPDATE while anything takes a row
 * lock on `app_user`: PostgreSQL accepts `FOR UPDATE`/`FOR NO KEY UPDATE` given UPDATE on any one column.
 *
 * **Literal statements**, nothing read from `SchemaManifest` (migrations are frozen history). `SchemaManifest`
 * moves in the same commit and `GrantFootprintTest` asserts the result from the server, three ways.
 *
 * **Locks:** `REVOKE`/`GRANT` take `AccessExclusiveLock` on `app_user` briefly for the catalogue update, with no
 * scan or rewrite. **No `lock_timeout` is set because nothing is deployed and this runs on a destroyed volume;** do
 * not copy it to a live system without one (as `Version20260916000300` records). An open pooled `erp_app` backend
 * sees the change at its next executor start.
 */
final class Version20260922000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-002 A4.0 (H0): erp_app\'s UPDATE on app_user becomes column-level — password_hash, last_login_at, updated_at, the columns LoginRecorder and ChangePassword write';
    }

    public function up(Schema $schema): void
    {
        // D-B: a column REVOKE under a table-level grant changes nothing, so the
        // table-level privilege goes first and the columns are granted after it.
        $this->addSql('REVOKE UPDATE ON app_user FROM erp_app');
        $this->addSql('GRANT UPDATE (password_hash, last_login_at, updated_at) ON app_user TO erp_app');
    }

    public function down(Schema $schema): void
    {
        // Load-bearing, measured: a table-level GRANT leaves column entries in
        // attacl, so without this REVOKE three erp_app=w entries would survive
        // under the restored table grant, invisible to has_column_privilege().
        $this->addSql('REVOKE UPDATE (password_hash, last_login_at, updated_at) ON app_user FROM erp_app');
        $this->addSql('GRANT UPDATE ON app_user TO erp_app');
    }
}
