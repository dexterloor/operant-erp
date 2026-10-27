<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **U-C1b "Let an administrator add a user, who then sets their own password from a link" — the two grants, each with its
 * caller** (`docs/session13/u-c1-build-plan.md` Revision 2 FINAL R2.2 M-A; ADR-002 A4.1, A4.4 duty 1). Grants only, no DDL.
 *  - `erp_app` INSERT on exactly the six `app_user` columns `UserCreation`'s one `INSERT` names: `id, tenant_id, email,
 *    password_hash, display_name, identity_mac`. **Never `password_mac`** (an inserted, pre-sealed credential is the one write
 *    that must not exist: `GrantFootprintTest` H5's insert clause), nor `is_active`, `deleted_at`, `sessions_valid_after`,
 *    `last_login_at` or the timestamps (defaults). `identity_mac` is required: the keyed seal is computed by the application
 *    (Mail-C1 carry item 4), and nobody may UPDATE it.
 *  - `erp_worker` SELECT `(password_mac)` on `app_user` — `IssueInvitationHandler` mails only a person who has never had a
 *    credential (`password_mac IS NULL`; Mail-C1 FA-6, granted with its caller).
 * The token table needs nothing: the worker's INSERT already names `purpose`, and `password_reset_token_purpose_values`
 * already admits `invitation`. `down()` revokes exactly what `up()` grants.
 */
final class Version20261027000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'U-C1b CreateUser: erp_app may insert six app_user columns; erp_worker may read app_user.password_mac';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT INSERT (id, tenant_id, email, password_hash, display_name, identity_mac) ON app_user TO erp_app');
        $this->addSql('GRANT SELECT (password_mac) ON app_user TO erp_worker');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE SELECT (password_mac) ON app_user FROM erp_worker');
        $this->addSql('REVOKE INSERT (id, tenant_id, email, password_hash, display_name, identity_mac) ON app_user FROM erp_app');
    }
}
