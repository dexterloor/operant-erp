<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **G4-C0a — let a deleted person's password stop signing in, before anything can delete one.** Authority:
 * `docs/session13/g4-users-design.md` §3 + Revision 1 FINAL R1.2 (G4-C0a), as amended by `g4-users-final-red.md` L-4.
 *
 * `erp_auth` (the pre-authentication principal, ADR-006 §2) gains `SELECT (deleted_at)` on `app_user`, so
 * `CredentialLookup` can add `AND deleted_at IS NULL` to its one `app_user` statement. A Postgres column privilege is
 * required for a reference in `WHERE` as much as in the select list (measured with the old grant: `42501`), so the grant
 * and the predicate ship in this one commit. The statement count is unchanged, so ADR-006 V-8's identical sequence holds.
 *
 * Column-level, never table-level: `erp_auth` still cannot read `display_name`, `last_login_at` or anything else
 * (`GrantFootprintTest::everyColumnLevelPrivilegeIsExactlyTheDeclaredSet`). `down()` is the exact column `REVOKE`.
 */
final class Version20261022006000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G4-C0a: erp_auth may read app_user.deleted_at, so sign-in can exclude a deleted person';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT SELECT (deleted_at) ON app_user TO erp_auth');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE SELECT (deleted_at) ON app_user FROM erp_auth');
    }
}
