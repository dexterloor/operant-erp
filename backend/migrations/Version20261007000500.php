<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Roles slice 4, C3 — `DeleteRole`'s grant** (`docs/session13/roles-s4-design.md` §8.1; ADR-002 A4.1 — a write is granted
 * with its first caller, exactly what that caller issues). Measured before (`has_column_privilege`): `role.deleted_at`
 * UPDATE false; `updated_at` UPDATE already true (C2, `Version20261007000300`).
 *  - `UPDATE (deleted_at) ON role` — `RoleDeletion::DELETE_ROLE` sets `deleted_at` and `updated_at`; only `deleted_at` is
 *    new. Column-level: `is_system`, `created_at`, `id` and `tenant_id` stay un-updatable by `erp_app` (`GrantFootprintTest`
 *    probes every column, with a planted `GRANT UPDATE (is_system)` going red). No `DELETE` on `role`: the delete is soft.
 * `erp_ai` unchanged (SELECT only, declared in `SchemaManifest`).
 * **G-10, stated (owner D-1, 2026-10-07):** from this grant SQL running as `erp_app` can also SOFT-DELETE any role — the
 * system roles included, since the `NOT is_system` guard is the application's statement's, not a grant's — and a
 * soft-deleted held role silently stops conferring its codes on every holder, with no `role.revoked` row. Inside the owner's
 * widened bound (`SECURITY_MODEL.md` §5.2.10, `RESIDUAL_GAPS.md` G-10); detected on invocation only and partially (arm F,
 * when no active owner-marker holder is left).
 *
 * Transactional. `down()` revokes it.
 */
final class Version20261007000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Roles slice 4 C3: erp_app column UPDATE (deleted_at) on role (DeleteRole).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT UPDATE (deleted_at) ON role TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (deleted_at) ON role FROM erp_app');
    }
}
