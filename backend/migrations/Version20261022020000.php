<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **G7b `UpdateSettings` — the workspace's own display name** (owner ruling RO-S1, `docs/session13/remaining-ops-ruling.md`:
 * a new `tenant_settings.display_name`, owner-editable, currency read-only, **never write `tenant`**, which has no RLS;
 * `docs/session13/g3-g7b-reorder-settings-design.md` §3.2 + Revision 1 FINAL R1.2 H-3/L-8/M-6; final RED F-M5, F-L5).
 *
 * One transaction (DDL is transactional, and `ALTER TABLE` holds ACCESS EXCLUSIVE until COMMIT, so no other session ever
 * sees the table un-forced or the column half-filled):
 *  1. `ADD COLUMN display_name text` — **no default**, the table's own rule (`SchemaManifest`: *"either one would relocate
 *     the same defect"*): a workspace's name is stated or the row is not written;
 *  2. `NO FORCE ROW LEVEL SECURITY` — **measured 2026-10-22: the migration principal (`neondb_owner`) is NOT superuser and
 *     NOT BYPASSRLS, and it owns the table**, so under FORCE its UPDATE is policed and, with no `app.tenant_id`, would match
 *     0 rows (`Version20260910000100::backfillExistingTenants()` records the same fact for its INSERT). NO FORCE exempts the
 *     owner only; `erp_app` stays policed throughout (ENABLE stays on);
 *  3. backfill from the registry: `btrim(tenant.name)` — the value `GetSettings` served until this commit, so the screen
 *     shows the same name after the deploy (RO-S1's notice). `tenant` is READ here, never written;
 *  4. a named refusal if any row is still NULL (it cannot be, `tenant_settings_tenant_fk` + `tenant.name NOT NULL`; the
 *     check is there so a future registry change fails with its cause, not as a bare `SET NOT NULL` error);
 *  5. `SET NOT NULL`;
 *  6. `tenant_settings_display_name_shape` — **`role_name_shape`'s predicate byte for byte, the column renamed** (L-8;
 *     `Version20261007000100`): 1..100 characters, no leading/trailing ASCII space, no C0/C1 control character and none of
 *     the listed format (Cf) characters. A backstop for SQL as `erp_app`; `SettingsWriter` refuses the same set first
 *     (422 `/display_name`). **A registry name that fails it (blank, over 100 characters, a control or listed format
 *     character, or surrounding characters `btrim` does not remove) stops THIS migration with the constraint's name, for
 *     every workspace, until that name is fixed** (final RED F-L5, owner notice N-12). Validated by the heap scan, so RLS
 *     hides no row from it;
 *  7. `FORCE ROW LEVEL SECURITY` again (`SchemaManifestTest` / `SchemaGuardTest` read `relforcerowsecurity`, so forgetting
 *     it is red);
 *  8. `GRANT UPDATE (display_name, updated_at)` to `erp_app` — `UpdateSettings`' one UPDATE, column-level, exactly its two
 *     SET columns (ADR-002 A4.1: the grant ships with its caller). `updated_at` beside RO-S1's "that one column" is a
 *     stated deviation (M-6; `Version20260928000400`'s precedent). `default_currency_code` stays unwritable: the currency's
 *     read-only half, pinned at the database (`TenantSettingsStorageTest`).
 *
 * `down()` is exact (final RED F-M5): revoke the column grant, drop the CHECK, drop the column. FORCE stays on.
 * `TenantSettingsDisplayNameMigrationTest` runs `down()` then `up()` on a seeded registry as the owner, inside a
 * transaction it rolls back.
 */
final class Version20261022020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G7b UpdateSettings: tenant_settings.display_name backfilled from tenant.name, its shape CHECK, and erp_app column UPDATE (display_name, updated_at).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_settings ADD COLUMN display_name text');
        $this->addSql('ALTER TABLE tenant_settings NO FORCE ROW LEVEL SECURITY');
        $this->addSql(<<<'SQL'
            UPDATE tenant_settings ts
               SET display_name = btrim(t.name)
              FROM tenant t
             WHERE t.id = ts.tenant_id
            SQL);
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                missing bigint;
            BEGIN
                SELECT count(*) INTO missing FROM tenant_settings WHERE display_name IS NULL;
                IF missing > 0 THEN
                    RAISE EXCEPTION 'tenant_settings rows left without a display name: %', missing;
                END IF;
            END
            $$
            SQL);
        $this->addSql('ALTER TABLE tenant_settings ALTER COLUMN display_name SET NOT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_display_name_shape CHECK (
                    char_length(display_name) BETWEEN 1 AND 100
                AND display_name = btrim(display_name)
                AND display_name !~ '[\u0001-\u001F\u007F-\u009F]'
                AND display_name !~ '[­؜᠎​-‏‪-‮⁠-⁤⁦-⁯﻿]'
            )
            SQL);
        $this->addSql('ALTER TABLE tenant_settings FORCE ROW LEVEL SECURITY');
        $this->addSql('GRANT UPDATE (display_name, updated_at) ON tenant_settings TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (display_name, updated_at) ON tenant_settings FROM erp_app');
        $this->addSql('ALTER TABLE tenant_settings DROP CONSTRAINT tenant_settings_display_name_shape');
        $this->addSql('ALTER TABLE tenant_settings DROP COLUMN display_name');
    }
}
