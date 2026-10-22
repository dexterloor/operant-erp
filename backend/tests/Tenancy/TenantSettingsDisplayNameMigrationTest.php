<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use DoctrineMigrations\Version20261022020000;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

require_once \dirname(__DIR__, 2).'/migrations/Version20261022020000.php';

/**
 * **`Version20261022020000`'s backfill, proven on rows that existed before it** (G7b; design H-3 (b); final RED F-M5,
 * "apply as prescribed"). At any HEAD where this test exists the column already exists, so the test cannot simply re-run
 * `up()`: it runs the migration's own **`down()`** SQL, then its **`up()`** SQL, as the **owner** (the principal Doctrine
 * migrates with, `doctrine_migrations.yaml`), in ONE transaction that it ROLLS BACK. No `erp_app` statement runs inside that
 * transaction: `ALTER TABLE` holds ACCESS EXCLUSIVE until the rollback, and another connection touching `tenant_settings`
 * would hang rather than fail.
 *
 * **The rows before the migration are the rig's own two settings rows** (`TenancyTestCase::seedTenantSettings`, written as
 * the owner for each tenant under FORCE in `setUp`): `down()` strips their `display_name`, and the registry names are
 * rewritten inside the transaction (padded, distinct) so the backfill has something to prove. That is the builder's stated
 * reading of F-M5 step 3 (it inserts no third tenant; the two seeded rows are two tenants with settings rows) — recorded in
 * `docs/session13/g7b-progress.md`.
 *
 * The owner is NOBYPASSRLS here (measured: `neondb_owner`, `rolbypassrls = false`), so without the migration's
 * `NO FORCE ROW LEVEL SECURITY` the backfill would match 0 rows and the named NULL check would refuse: mutation, run.
 */
final class TenantSettingsDisplayNameMigrationTest extends TenancyTestCase
{
    #[Test]
    public function existingRowsAreBackfilledFromTheTrimmedRegistryNameAndRlsIsForcedAgain(): void
    {
        $owner = $this->owner();
        $owner->beginTransaction();

        try {
            $this->runMigration($owner, 'down');
            self::assertFalse($this->hasDisplayName($owner), 'down() left the column in place.');
            self::assertSame(2, (int) $this->countAsOwnerInside($owner, self::TENANT_A) + (int) $this->countAsOwnerInside($owner, self::TENANT_B),
                'Control: the two pre-migration settings rows are not there.');

            $owner->executeStatement("UPDATE tenant SET name = '  Alpha Padded Ltd  ' WHERE id = ?::uuid", [self::TENANT_A]);
            $owner->executeStatement("UPDATE tenant SET name = 'Bravo Co' WHERE id = ?::uuid", [self::TENANT_B]);

            $this->runMigration($owner, 'up');

            self::assertSame('Alpha Padded Ltd', $this->displayNameInside($owner, self::TENANT_A));
            self::assertSame('Bravo Co', $this->displayNameInside($owner, self::TENANT_B));
            self::assertSame(
                ['force' => true, 'not_null' => true, 'name_update' => true, 'stamp_update' => true, 'currency_update' => false, 'check' => 1],
                $owner->fetchAssociative(<<<'SQL'
                    SELECT c.relforcerowsecurity AS force,
                           (SELECT attnotnull FROM pg_attribute WHERE attrelid = c.oid AND attname = 'display_name') AS not_null,
                           has_column_privilege('erp_app', 'tenant_settings', 'display_name', 'UPDATE') AS name_update,
                           has_column_privilege('erp_app', 'tenant_settings', 'updated_at', 'UPDATE') AS stamp_update,
                           has_column_privilege('erp_app', 'tenant_settings', 'default_currency_code', 'UPDATE') AS currency_update,
                           (SELECT count(*)::int FROM pg_constraint WHERE conrelid = c.oid AND conname = 'tenant_settings_display_name_shape') AS "check"
                      FROM pg_class c WHERE c.oid = 'tenant_settings'::regclass
                    SQL),
            );
        } finally {
            $owner->rollBack();
        }

        self::assertTrue($this->hasDisplayName($owner), 'The rollback did not restore the column.');
        self::assertSame('Tenant A', $owner->fetchOne('SELECT name FROM tenant WHERE id = ?::uuid', [self::TENANT_A]), 'The rollback did not restore the registry.');
    }

    /**
     * F-L5 / owner notice N-12: a registry name the CHECK refuses stops the migration, loudly and by the constraint's name —
     * no silent truncation. One case per transaction (a failed statement aborts it).
     */
    #[Test]
    public function aRegistryNameTheCheckRefusesStopsTheMigrationByName(): void
    {
        foreach ([str_repeat('x', 101), "Bad\u{200B}Name", "Bell\x07"] as $name) {
            $owner = $this->owner();
            $owner->beginTransaction();
            $error = null;
            try {
                $this->runMigration($owner, 'down');
                $owner->executeStatement('UPDATE tenant SET name = ? WHERE id = ?::uuid', [$name, self::TENANT_A]);
                $this->runMigration($owner, 'up');
            } catch (DriverException $e) {
                $error = $e->getMessage();
            } finally {
                $owner->rollBack();
            }

            self::assertNotNull($error, json_encode($name).': the migration accepted a registry name the CHECK refuses.');
            self::assertStringContainsString('tenant_settings_display_name_shape', $error, json_encode($name));
        }

        self::assertTrue($this->hasDisplayName($this->owner()), 'The rollback did not restore the column.');
    }

    /** L-8: the CHECK is `role_name_shape`'s predicate, byte for byte, with the column renamed — one rule, not two. */
    #[Test]
    public function theShapeCheckIsTheRoleNameRule(): void
    {
        $definition = fn (string $name): string => (string) $this->owner()->fetchOne('SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = ?', [$name]);

        $role = $definition('role_name_shape');
        self::assertStringContainsString('char_length(name)', $role, 'Control: role_name_shape is not where it was.');
        self::assertSame(str_replace('name', 'display_name', $role), $definition('tenant_settings_display_name_shape'));
    }

    private function runMigration(Connection $owner, string $direction): void
    {
        $migration = new Version20261022020000($owner, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $owner->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function hasDisplayName(Connection $owner): bool
    {
        return true === $owner->fetchOne("SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = 'tenant_settings'::regclass AND attname = 'display_name' AND NOT attisdropped)");
    }

    /** Inside the open transaction: the sanctioned GUC writer, then one read under FORCE. */
    private function displayNameInside(Connection $owner, string $tenantId): mixed
    {
        $this->tenantContext()->establishOn($owner, $tenantId);

        return $owner->fetchOne('SELECT display_name FROM tenant_settings WHERE tenant_id = ?::uuid', [$tenantId]);
    }

    private function countAsOwnerInside(Connection $owner, string $tenantId): mixed
    {
        $this->tenantContext()->establishOn($owner, $tenantId);

        return $owner->fetchOne('SELECT count(*) FROM tenant_settings WHERE tenant_id = ?::uuid', [$tenantId]);
    }
}
