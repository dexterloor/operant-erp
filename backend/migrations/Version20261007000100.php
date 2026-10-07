<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Roles slice 4, C1 — `CreateRole`'s grants and `role`'s two shape CHECKs** (`docs/session13/roles-s4-design.md` §8.1;
 * `roles-s4-sec-data.md` D-10.1; ADR-002 A4.1 — a write is granted with its first caller, exactly what that caller issues).
 *
 * **Grants, column-level, exactly `RoleCreation`'s two statements:**
 *  - `INSERT (id, tenant_id, name, description) ON role` — `is_system`, `created_at`, `updated_at` and `deleted_at` stay
 *    unwritable by `erp_app`, so E-15h's *"`is_system` set by any path other than provisioning"* is closed at the
 *    privilege level (`GrantFootprintTest` probes every column, with a planted `GRANT INSERT (is_system)` going red);
 *  - `INSERT (id, tenant_id, role_id, permission_code) ON role_permission` — never `UPDATE` (no `ON CONFLICT DO UPDATE`,
 *    A4.3 constraint 1) and no `DELETE` until `UpdateRole` (C2).
 * `erp_ai` keeps exactly its inherited `SELECT` on both (declared explicitly in `SchemaManifest`).
 * **G-10, stated:** from this grant SQL running as `erp_app` can insert a role and give ANY role — the system roles included
 * — any catalogue code; the `NOT is_system` guards are the application's. Inside the owner's accepted bound (§5.2.10:
 * *"change a held role's codes"*), detected only on invocation (reconciliation arm D and the interim
 * `system_role_has_permission_rows` finding).
 *
 * **The CHECKs (`DATA` D-10.1), backstops for SQL as `erp_app`, never the rule** — the application refuses the same set first
 * (422 on `/name`, `/description`), so neither fires on an API write:
 *  - `role_name_shape`: 1..100 characters, no leading/trailing ASCII space, no C0/C1 control character and none of the
 *    listed format (Cf) characters (zero-width, bidi overrides, soft hyphen, BOM …) that make two names render alike;
 *  - `role_description_shape`: at most 500 characters (the proposal `reason` precedent), no control character except tab,
 *    newline and carriage return.
 * Code points are written as regex escapes (`\uXXXX`, the ARE syntax), measured on the live server (PostgreSQL 17, UTF8,
 * `en_US.utf8`) with a planted U+200B and a trailing space each refused `23514`, before this file was committed. Every
 * existing row passes (measured: none exists outside the test rig's owner-written fixtures, whose names are plain).
 *
 * Transactional. `down()` drops both CHECKs and revokes the two column grants.
 */
final class Version20261007000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Roles slice 4 C1: erp_app column INSERT on role and role_permission (CreateRole); role_name_shape and role_description_shape CHECKs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT INSERT (id, tenant_id, name, description) ON role TO erp_app');
        $this->addSql('GRANT INSERT (id, tenant_id, role_id, permission_code) ON role_permission TO erp_app');

        $this->addSql(<<<'SQL'
            ALTER TABLE role ADD CONSTRAINT role_name_shape CHECK (
                    char_length(name) BETWEEN 1 AND 100
                AND name = btrim(name)
                AND name !~ '[\u0001-\u001F\u007F-\u009F]'
                AND name !~ '[­؜᠎​-‏‪-‮⁠-⁤⁦-⁯﻿]'
            )
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE role ADD CONSTRAINT role_description_shape CHECK (
                    char_length(description) <= 500
                AND description !~ '[\u0001-\u0008\u000B\u000C\u000E-\u001F\u007F-\u009F]'
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE role DROP CONSTRAINT IF EXISTS role_description_shape');
        $this->addSql('ALTER TABLE role DROP CONSTRAINT IF EXISTS role_name_shape');
        $this->addSql('REVOKE INSERT (id, tenant_id, role_id, permission_code) ON role_permission FROM erp_app');
        $this->addSql('REVOKE INSERT (id, tenant_id, name, description) ON role FROM erp_app');
    }
}
