<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Mail-C2 `CreatePasswordResetRedemption` — the reads and the one write its caller issues, and no others**
 * (`docs/session13/mail-c2-build-plan.md` §2 M-A, §3; ADR-002 A4.1 / A4.4 duty 1: grants ship with their caller and
 * `SchemaManifest` moves in the same commit).
 *
 *  - `erp_auth` (the redeem's one lookup, `PasswordResetTokenLookup`): column SELECT on the nine `password_reset_token`
 *    columns the statement names (`created_at` for owner Q-3: a link minted at or before the person's latest session floor
 *    is dead), and on `app_user.identity_mac` (the identity seal is re-checked at redeem) and `app_user.sessions_valid_after`
 *    (Q-3's floor). Never `message_id`.
 *  - `erp_app` (the redeem's write, `PasswordResetRedemptionWriter` step 7a): column SELECT on exactly the five
 *    `password_reset_token` columns its UPDATE's `WHERE`/`RETURNING` reference (Postgres requires SELECT on every column an
 *    UPDATE reads; `GrantFootprintTest`'s positive control proves the need), and `UPDATE (used_at)` — the only token column
 *    any principal may update. Never `selector` or `verifier_mac`: `erp_app` cannot read a link's secret half's MAC.
 *  - **No `app_user` UPDATE grant** — measured: `erp_app` already holds `UPDATE (password_hash, password_mac,
 *    sessions_valid_after, updated_at, …)` (G4-C1 `Version20261023000100`, Cred-C1). No DELETE anywhere (deletion class n/a).
 *
 * **What this opens (`RESIDUAL_GAPS.md` G-12, recorded with this commit):** SQL as `erp_app` can mark any person's
 * outstanding links used (a recovery denial of service) or clear `used_at` on a used one (useless without the raw verifier,
 * which exists only in the mail), and can now READ who was sent a link and when (`user_id`, `expires_at`, `used_at`).
 *
 * REVOKE first, then GRANT (`Version20261023000800`'s shape): the token footprint is independent of default privileges.
 * `down()` revokes exactly these.
 */
final class Version20261024000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mail-C2 CreatePasswordResetRedemption: erp_auth reads a reset token and the identity seal; erp_app marks links used';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('REVOKE ALL ON password_reset_token FROM erp_app, erp_auth');
        $this->addSql(<<<'SQL'
            GRANT SELECT (id, tenant_id, user_id, purpose, selector, verifier_mac, expires_at, used_at, created_at)
               ON password_reset_token TO erp_auth
            SQL);
        $this->addSql('GRANT SELECT (identity_mac, sessions_valid_after) ON app_user TO erp_auth');
        $this->addSql('GRANT SELECT (id, tenant_id, user_id, used_at, expires_at) ON password_reset_token TO erp_app');
        $this->addSql('GRANT UPDATE (used_at) ON password_reset_token TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (used_at) ON password_reset_token FROM erp_app');
        $this->addSql('REVOKE SELECT (id, tenant_id, user_id, used_at, expires_at) ON password_reset_token FROM erp_app');
        $this->addSql('REVOKE SELECT (identity_mac, sessions_valid_after) ON app_user FROM erp_auth');
        $this->addSql(<<<'SQL'
            REVOKE SELECT (id, tenant_id, user_id, purpose, selector, verifier_mac, expires_at, used_at, created_at)
                ON password_reset_token FROM erp_auth
            SQL);
    }
}
