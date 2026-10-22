<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **G4-C1 `UpdateUser` — the column UPDATEs its one caller issues, and no others** (`docs/session13/g4-users-design.md` §5.5;
 * ADR-002 A4.1 / A4.4 duty 1: the migration that ships the first caller grants the write and edits `SchemaManifest` in the
 * same commit).
 *
 * `erp_app` gains `UPDATE (is_active, display_name, sessions_valid_after)` on `app_user`. The writer is `UserUpdate`'s ORM
 * flush of `AppUser::deactivate()` / `reactivate()` / `rename()`, which set exactly those three columns plus `updated_at`
 * (already granted by H0, `Version20260922000300`). The `FOR UPDATE` row lock `UserLiveness` takes under the role lock
 * (final RED M-2(a)) needs an UPDATE privilege on some column of the table, which this grant also supplies.
 *
 * Column-level, never table-level: `email`, `deleted_at`, `id`, `tenant_id`, `created_at` stay refused to `erp_app`
 * (`GrantFootprintTest::theApplicationCanUpdateOnlyTheUserColumnsItsCallersWrite`, a hand-written literal). `deleted_at` is
 * G4-C2's (`DeleteUser`), granted with that caller.
 *
 * **What this re-opens (`RESIDUAL_GAPS.md` G-12 / G-10, recorded with this commit):** SQL as `erp_app` can now relabel a person
 * (no trail row), clear or set `is_active` (two lockout routes H0 had closed), and move `sessions_valid_after` (a far-future
 * value refuses every new token; NULL lets pre-deactivation tokens back in once the person is active again).
 *
 * `down()` is the exact column `REVOKE`.
 */
final class Version20261023000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G4-C1 UpdateUser: erp_app may update app_user.is_active, display_name and sessions_valid_after';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT UPDATE (is_active, display_name, sessions_valid_after) ON app_user TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (is_active, display_name, sessions_valid_after) ON app_user FROM erp_app');
    }
}
