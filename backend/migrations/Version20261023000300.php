<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **G4-C2 `DeleteUser` — the one column UPDATE its caller adds** (`docs/session13/g4-users-design.md` §6; ADR-002 A4.1 / A4.4
 * duty 1). `erp_app` gains `UPDATE (deleted_at)` on `app_user`: `UserDeletion`'s ORM flush of `AppUser::delete($at)` sets
 * `deleted_at`, `sessions_valid_after` and `updated_at` (the last two already granted, C1 / H0).
 *
 * Column-level, never table-level: `email`, `id`, `tenant_id`, `created_at` stay refused
 * (`GrantFootprintTest::theApplicationCanUpdateOnlyTheUserColumnsItsCallersWrite`). A soft delete only — no `DELETE` on
 * `app_user` (audit trails and documents name the person). **What this re-opens (`RESIDUAL_GAPS.md` G-12 / G-10):** SQL as
 * `erp_app` can now soft-delete a person (a lockout route H0 had closed) or clear `deleted_at` (bring a removed member back,
 * then, through G-10, make them owner).
 *
 * `down()` is the exact column `REVOKE`.
 */
final class Version20261023000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G4-C2 DeleteUser: erp_app may update app_user.deleted_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT UPDATE (deleted_at) ON app_user TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (deleted_at) ON app_user FROM erp_app');
    }
}
