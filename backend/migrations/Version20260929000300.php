<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Roles slice 2, C2 — `RevokeRole`'s grant: `DELETE` on `user_role` for `erp_app`, and nothing else**
 * (`docs/session13/roles-s2-design.md` §6; ADR-002 A4.1 — the grant ships with its caller — and A4.4 duty 1, *exactly*
 * what the caller issues).
 *
 * **The project's first `DELETE` grant on any table** (measured before this migration: `erp_app` held `DELETE` on no
 * table). Its one caller is `RoleRevocation`, which issues exactly `DELETE FROM user_role WHERE id = ?::uuid` under the
 * role-administration lock. `user_role` is `SchemaManifest::HARD` (a join row; that it existed lives in `audit_log`), no
 * foreign key points at it, so the delete cascades nowhere. RLS policy `tenant_isolation` is command `*`, so it binds the
 * `DELETE` too — ADR-002 A4.4 duty 2's isolation test (`TenantIsolationTest::erpAppCannotDeleteAnotherTenantsUserRole`)
 * ships in the same commit.
 *
 * **Not `INSERT`, not `UPDATE`** — `AssignRole` (C3) grants its own column-level `INSERT`; nothing updates a join row, and
 * the advisory lock needs no table privilege. `erp_ai` is unmoved (`SELECT`, inherited). **G-5's fourth trigger does not
 * fire:** it names a `DELETE` grant on the three auth counter tables, not this one.
 *
 * `down()` revokes it. Transactional (Doctrine's default): one statement.
 */
final class Version20260929000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Roles slice 2 C2: erp_app DELETE on user_role, for RevokeRole.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT DELETE ON user_role TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE DELETE ON user_role FROM erp_app');
    }
}
