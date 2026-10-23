<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\UserManagement\Domain\SealsCredentials;
use DoctrineMigrations\Version20261023000600;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

require_once \dirname(__DIR__, 2).'/migrations/Version20261023000600.php';

/**
 * **T-C10 — `Version20261023000600` seals every credential that existed before it, in every workspace, and leaves RLS
 * forced** (Cred-C1; design §3.2, §3.4; Cred-C1 RED D-1). The `TenantSettingsDisplayNameMigrationTest` shape: at any HEAD
 * where this test exists the column already exists, so the migration's own `down()` SQL, then its `up()` SQL, then its
 * `postUp()` run **as the owner** (the principal Doctrine migrates with), in ONE transaction that is ROLLED BACK. No other
 * connection touches `app_user` inside it (`ALTER TABLE` holds ACCESS EXCLUSIVE until the rollback).
 *
 * The pre-migration rows are three users across the two rig workspaces with a hash and no seal. The owner is NOBYPASSRLS
 * and `app_user` is FORCE ROW LEVEL SECURITY, so without the migration's `NO FORCE` the backfill would see no row and seal
 * nothing — and the throw-if-no-key rule would never fire (mutation (a)). A tenant predicate in the backfill leaves one
 * workspace's row unsealed (mutation (b)); forgetting `FORCE` leaves `relforcerowsecurity` false (mutation (c)).
 */
final class CredentialBackfillTest extends TenancyTestCase
{
    private const HASH = '$argon2id$v=19$m=1024,t=3,p=1$c2FsdHNhbHQ$aGFzaGhhc2hoYXNo';

    #[Test]
    public function everyExistingCredentialInEveryWorkspaceIsSealedAndRlsIsForcedAgain(): void
    {
        $users = [self::TENANT_A => [$this->seedUnsealed(self::TENANT_A), $this->seedUnsealed(self::TENANT_A)], self::TENANT_B => [$this->seedUnsealed(self::TENANT_B)]];
        $owner = $this->owner();
        $owner->beginTransaction();

        try {
            $this->runMigration($owner, 'down');
            self::assertFalse($this->hasColumn($owner), 'down() left the column in place.');
            $migration = $this->runMigration($owner, 'up');
            $migration->postUp(new Schema());

            foreach ($users as $tenantId => $ids) {
                foreach ($ids as $id) {
                    self::assertSame($this->seal()->seal($tenantId, $id, self::HASH), $this->macInside($owner, $tenantId, $id),
                        "The row $id in workspace $tenantId was not sealed with the configured key.");
                }
            }
            self::assertSame(
                ['force' => true, 'auth_select' => true, 'app_update' => true, 'app_insert' => false, 'check' => 1],
                $owner->fetchAssociative(<<<'SQL'
                    SELECT c.relforcerowsecurity AS force,
                           has_column_privilege('erp_auth', 'app_user', 'password_mac', 'SELECT') AS auth_select,
                           has_column_privilege('erp_app', 'app_user', 'password_mac', 'UPDATE') AS app_update,
                           has_column_privilege('erp_app', 'app_user', 'password_mac', 'INSERT') AS app_insert,
                           (SELECT count(*)::int FROM pg_constraint WHERE conrelid = c.oid AND conname = 'app_user_password_mac_shape') AS "check"
                      FROM pg_class c WHERE c.oid = 'app_user'::regclass
                    SQL),
            );
        } finally {
            $owner->rollBack();
        }

        self::assertTrue($this->hasColumn($owner), 'The rollback did not restore the column.');
    }

    /** Rows needing a seal and no key: refused, by count and key name, never by value — and nothing is sealed. */
    #[Test]
    public function aMissingKeyStopsTheMigrationWhenThereIsSomethingToSeal(): void
    {
        $this->seedUnsealed(self::TENANT_A);
        $owner = $this->owner();
        $owner->beginTransaction();

        $refusal = null;
        try {
            $owner->executeStatement('ALTER TABLE app_user NO FORCE ROW LEVEL SECURITY');
            Version20261023000600::sealAll($owner, null);
        } catch (\RuntimeException $e) {
            $refusal = $e->getMessage();
        } finally {
            $owner->rollBack();
        }

        self::assertNotNull($refusal, 'Rows needed a seal and no key was given, and the backfill did not refuse.');
        self::assertStringContainsString('1 app_user row(s)', $refusal);
        self::assertStringContainsString(Version20261023000600::KEY_NAME, $refusal);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM app_user WHERE password_mac IS NOT NULL'), 'The refused backfill sealed a row.');
    }

    /** Nothing to seal needs no key: a fresh database migrates without one. */
    #[Test]
    public function nothingToSealNeedsNoKey(): void
    {
        $owner = $this->owner();
        $owner->beginTransaction();
        try {
            $owner->executeStatement('ALTER TABLE app_user NO FORCE ROW LEVEL SECURITY');
            self::assertSame(0, (int) $owner->fetchOne('SELECT count(*) FROM app_user WHERE password_mac IS NULL'), 'Control: the rig left an unsealed row.');
            self::assertSame(0, Version20261023000600::sealAll($owner, null));
        } finally {
            $owner->rollBack();
        }
    }

    private function seedUnsealed(string $tenantId): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO app_user (id, tenant_id, email, password_hash, display_name) VALUES (?::uuid, ?::uuid, ?, ?, 'Backfill User')
            SQL, [$id, $tenantId, 'backfill-'.substr($id, 0, 8).'@example.test', self::HASH]);

        return $id;
    }

    private function runMigration(Connection $owner, string $direction): Version20261023000600
    {
        $migration = new Version20261023000600($owner, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $owner->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        return $migration;
    }

    private function hasColumn(Connection $owner): bool
    {
        return true === $owner->fetchOne("SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = 'app_user'::regclass AND attname = 'password_mac' AND NOT attisdropped)");
    }

    /** Inside the open transaction, under FORCE again: the sanctioned GUC writer, then one read. */
    private function macInside(Connection $owner, string $tenantId, string $id): mixed
    {
        $this->tenantContext()->establishOn($owner, $tenantId);

        return $owner->fetchOne('SELECT password_mac FROM app_user WHERE id = ?::uuid', [$id]);
    }

    private function seal(): SealsCredentials
    {
        $seal = self::getContainer()->get('test.auth.credential_seal');
        \assert($seal instanceof SealsCredentials);

        return $seal;
    }
}
