<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **W-3 "Let the owner seed a local demo workspace with its built-in roles and one owner" — `role.default_key`**
 * (`DATA` D-10.2, reduced): which built-in role a system role is, so the role check (`app:roles:reconcile`, arm D-system)
 * can compare its permission rows with `DefaultRoles::codesFor()` — the one source of the default roles' codes.
 *
 *  - `default_key text NULL` — a `DefaultRoles::names()` member on a system role, NULL on every other role.
 *  - `role_default_key_only_on_system_roles` — `CHECK (default_key IS NULL OR is_system)`. **One-way, a recorded deviation
 *    from D-10.2's `is_system = (default_key IS NOT NULL)`:** test fixtures insert or flip keyless system roles on purpose,
 *    and a keyless system role keeps its existing finding (`system_role_has_permission_rows`) in the role check.
 *  - `role_tenant_default_key_live` — one live role per key per workspace (partial on `deleted_at IS NULL`, ADR-007 §1: a
 *    soft-deleted role does not hold its key). Rows with no key never collide (NULLs are distinct in a unique index), so the
 *    predicate is the deletion marker alone, as `SchemaManifestTest` requires of every unique on a soft-deleted table — the
 *    design's extra `default_key IS NOT NULL` clause would have needed a by-name exception for no difference in behaviour.
 *
 * **No grant.** `erp_app`'s INSERT and UPDATE on `role` are column grants (`Version20261007000100`, `…000300`, `…000500`)
 * and do not reach a new column; its table-level SELECT does. Only an owner process (the local demo seed,
 * `bin/demo-seed.php`) writes it. `GrantFootprintTest` proves the column is refused to `erp_app`.
 * `seed_catalogue_version` is deferred to the commit that first needs it (D-10.2 deviation (iii)).
 */
final class Version20261028000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'W-3: role.default_key (system roles only, one live per key per workspace)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE role ADD COLUMN default_key text NULL');
        $this->addSql('ALTER TABLE role ADD CONSTRAINT role_default_key_only_on_system_roles CHECK (default_key IS NULL OR is_system)');
        $this->addSql('CREATE UNIQUE INDEX role_tenant_default_key_live ON role (tenant_id, default_key) WHERE deleted_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX role_tenant_default_key_live');
        $this->addSql('ALTER TABLE role DROP CONSTRAINT role_default_key_only_on_system_roles');
        $this->addSql('ALTER TABLE role DROP COLUMN default_key');
    }
}
