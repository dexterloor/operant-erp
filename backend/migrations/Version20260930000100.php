<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Roles slice 2, C3 — `AssignRole`'s grant, and H1: who inserted a grant is recorded by the database, not the caller**
 * (`docs/session13/roles-s2-design.md` §5.2 "H1" and §6; `roles-e15e-q7-spar.md` §2.2; `RESIDUAL_GAPS.md` G-10; ADR-002
 * A4.1 — the grant ships with its caller — and A4.4 duty 1, *exactly* what the caller issues).
 *
 *  1. **`user_role.inserted_by text NOT NULL DEFAULT session_user`.** `session_user` is the LOGIN, which `SET ROLE` does not
 *     change, so an owner-process row reads the owner's login and an API row reads `erp_app` (measured by `RED` through the
 *     local pool; Neon's pooler unmeasured). `session_user` is `STABLE`, so the existing rows (none on a fresh volume) are
 *     filled once with this migration's login — the owner, which is what wrote them. **H1's limit:** it separates
 *     owner-process rows from `erp_app` rows; it can NOT separate an API grant from a forged `erp_app` grant — both read
 *     `erp_app` (G-10, *not prevented, not reliably detected*).
 *  2. **`GRANT INSERT (id, tenant_id, user_id, role_id) ON user_role TO erp_app`** — column-level, exactly the four columns
 *     `RoleAssignment`'s one `INSERT` names. Table-level `INSERT` stays false, so `inserted_by` (and `created_at` /
 *     `updated_at`) cannot be written by the application at all: a forged `inserted_by` is a 42501, not a lie. Never a
 *     column `REVOKE` under a table grant (D-B: that does nothing, measured). ADD COLUMN precedes the GRANT.
 *
 * No `UPDATE`: nothing updates a join row. `erp_ai` is unmoved (its inherited `SELECT` now covers `inserted_by` too; `SEC`'s
 * standing question, design §13). `down()` revokes the grant and drops the column.
 */
final class Version20260930000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Roles slice 2 C3: user_role.inserted_by (H1) and erp_app INSERT on four user_role columns, for AssignRole.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_role ADD COLUMN inserted_by text NOT NULL DEFAULT session_user');
        $this->addSql('GRANT INSERT (id, tenant_id, user_id, role_id) ON user_role TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE INSERT (id, tenant_id, user_id, role_id) ON user_role FROM erp_app');
        $this->addSql('ALTER TABLE user_role DROP COLUMN inserted_by');
    }
}
