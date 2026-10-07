<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Roles slice 4, C2 — `UpdateRole`'s grants** (`docs/session13/roles-s4-design.md` §8.1; ADR-002 A4.1 — a write is granted
 * with its first caller, exactly what that caller issues). Measured before (`has_column_privilege`): every `role` column
 * UPDATE false, `role_permission` DELETE false.
 *  - `UPDATE (name, description, updated_at) ON role` — `RoleUpdate::UPDATE_ROLE`'s three SET columns, column-level.
 *    `is_system`, `deleted_at` (C3's), `created_at`, `id` and `tenant_id` stay un-updatable by `erp_app`
 *    (`GrantFootprintTest` probes every column, with a planted `GRANT UPDATE (is_system)` going red).
 *  - `DELETE ON role_permission` — `RoleUpdate::DELETE_CODES`; table-level (Postgres has no column-level DELETE). Never
 *    `UPDATE` on `role_permission`: a changed code is a delete and an insert.
 * `erp_ai` unchanged (SELECT only on both, declared in `SchemaManifest`).
 * **G-10, stated (owner D-1, 2026-10-07):** from this grant SQL running as `erp_app` can also RENAME any role and change its
 * description, and DELETE any role's codes — the system roles included; the `NOT is_system` guards are the application's
 * statements', not a grant's. Inside the owner's widened bound (`SECURITY_MODEL.md` §5.2.10, `RESIDUAL_GAPS.md` G-10);
 * detected on invocation only (arm D, the interim `system_role_has_permission_rows`; nothing reports a rename).
 *
 * Transactional. `down()` revokes both.
 */
final class Version20261007000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Roles slice 4 C2: erp_app column UPDATE (name, description, updated_at) on role and DELETE on role_permission (UpdateRole).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT UPDATE (name, description, updated_at) ON role TO erp_app');
        $this->addSql('GRANT DELETE ON role_permission TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE DELETE ON role_permission FROM erp_app');
        $this->addSql('REVOKE UPDATE (name, description, updated_at) ON role FROM erp_app');
    }
}
