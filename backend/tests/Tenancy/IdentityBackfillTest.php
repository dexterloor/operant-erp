<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\UserManagement\Domain\SealsIdentities;
use App\Modules\UserManagement\Infrastructure\CredentialKeyIdRefused;
use App\Modules\UserManagement\Infrastructure\CredentialSeal;
use DoctrineMigrations\Version20261023000800;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

require_once \dirname(__DIR__, 2).'/migrations/Version20261023000800.php';

/**
 * **T-B1 — `Version20261023000800` seals every existing identity, in every workspace, deleted rows included, and refuses a
 * wrong or missing key** (Mail-C1, `mail-c1-build-plan.md` R1.2 item 5; G-75 T-G75-2, T-G75-3; the `CredentialBackfillTest`
 * shape: owner, one transaction, ROLLED BACK).
 *
 * Mutations: MU-18 (skip the "none carries this kid" check) → `aBackfillWithAKeyNoPasswordSealWasMadeWithIsRefused` red;
 * a tenant predicate → one workspace unsealed → red; forgetting `FORCE` → `relforcerowsecurity` false → red.
 */
final class IdentityBackfillTest extends TenancyTestCase
{
    private const HASH = '$argon2id$v=19$m=1024,t=3,p=1$c2FsdHNhbHQ$aGFzaGhhc2hoYXNo';

    #[Test]
    public function everyExistingIdentityInEveryWorkspaceIsSealedAndRlsIsForcedAgain(): void
    {
        $users = [
            self::TENANT_A => [$this->seedUnsealed(self::TENANT_A, false), $this->seedUnsealed(self::TENANT_A, true)],
            self::TENANT_B => [$this->seedUnsealed(self::TENANT_B, false)],
        ];
        $owner = $this->owner();
        $owner->beginTransaction();

        try {
            $this->runMigration($owner, 'down');
            $migration = $this->runMigration($owner, 'up');
            $migration->postUp(new Schema());

            foreach ($users as $tenantId => $ids) {
                foreach ($ids as $id) {
                    $this->tenantContext()->establishOn($owner, $tenantId);
                    $row = $owner->fetchAssociative('SELECT email, identity_mac FROM app_user WHERE id = ?::uuid', [$id]);
                    self::assertIsArray($row);
                    self::assertSame($this->seal()->sealIdentity($tenantId, $id, (string) $row['email']), $row['identity_mac'],
                        "The row $id in workspace $tenantId was not sealed with the configured key.");
                }
            }
            self::assertSame(
                ['force' => true, 'worker_select' => true, 'app_update' => false, 'policy' => 1],
                $owner->fetchAssociative(<<<'SQL'
                    SELECT c.relforcerowsecurity AS force,
                           has_column_privilege('erp_worker', 'app_user', 'identity_mac', 'SELECT') AS worker_select,
                           has_column_privilege('erp_app', 'app_user', 'identity_mac', 'UPDATE') AS app_update,
                           (SELECT count(*)::int FROM pg_policy WHERE polname = 'audit_log_worker_events') AS policy
                      FROM pg_class c WHERE c.oid = 'app_user'::regclass
                    SQL),
            );
        } finally {
            $owner->rollBack();
        }
    }

    /** Rows to seal and no key: refused by count and key name — and nothing sealed. */
    #[Test]
    public function aMissingKeyStopsTheBackfillWhenThereIsSomethingToSeal(): void
    {
        $this->seedUnsealed(self::TENANT_A, false);
        $refusal = $this->refusal(static fn (Connection $owner): int => Version20261023000800::sealIdentities($owner, null, null));

        self::assertStringContainsString('app_user row(s)', $refusal);
        self::assertStringContainsString(Version20261023000800::KEY_NAME, $refusal);
    }

    /**
     * **T-G75-3:** password seals exist and none was made with the key the backfill holds → refused, never blessed. (The
     * rig's users are sealed with the configured key; the backfill is handed another.)
     */
    #[Test]
    public function aBackfillWithAKeyNoPasswordSealWasMadeWithIsRefused(): void
    {
        $sealed = $this->seedUnsealed(self::TENANT_A, false);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET password_mac = ? WHERE id = ?::uuid',
            [(new CredentialSeal(self::key()))->seal(self::TENANT_A, $sealed, self::HASH), $sealed]);
        self::assertGreaterThan(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM app_user WHERE password_mac IS NOT NULL'),
            'Control: no password seal exists, so this case proves nothing.');
        $other = base64_encode(str_repeat('o', 32));

        $refusal = $this->refusal(static fn (Connection $owner): int => Version20261023000800::sealIdentities($owner, $other, null));

        self::assertStringContainsString('none was made with this', $refusal);
        self::assertStringNotContainsString($other, $refusal, 'The refusal echoed the key.');
    }

    /** **T-G75-2:** a declared key id that is not the key's → refused before anything is sealed. */
    #[Test]
    public function aDeclaredKeyIdThatDoesNotMatchIsRefused(): void
    {
        $this->seedUnsealed(self::TENANT_A, false);
        $key = self::key();
        $wrong = 'deadbeef' === (new CredentialSeal($key))->keyId() ? 'feedface' : 'deadbeef';

        $owner = $this->owner();
        $owner->beginTransaction();
        try {
            $owner->executeStatement('ALTER TABLE app_user NO FORCE ROW LEVEL SECURITY');
            Version20261023000800::sealIdentities($owner, $key, $wrong);
            self::fail('A declared key id that is not the key\'s was accepted.');
        } catch (CredentialKeyIdRefused $e) {
            self::assertStringContainsString('AUTH_CREDENTIAL_MAC_KEY_ID', $e->getMessage());
        } finally {
            $owner->rollBack();
        }
    }

    private function refusal(callable $backfill): string
    {
        $owner = $this->owner();
        $owner->beginTransaction();
        $refusal = null;
        try {
            $owner->executeStatement('ALTER TABLE app_user NO FORCE ROW LEVEL SECURITY');
            $backfill($owner);
        } catch (\RuntimeException $e) {
            $refusal = $e->getMessage();
        } finally {
            $owner->rollBack();
        }
        self::assertNotNull($refusal, 'The backfill did not refuse.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM app_user WHERE identity_mac IS NOT NULL'), 'A refused backfill sealed a row.');

        return $refusal;
    }

    private function seedUnsealed(string $tenantId, bool $deleted): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO app_user (id, tenant_id, email, password_hash, display_name, deleted_at)
            VALUES (?::uuid, ?::uuid, ?, ?, 'Backfill User', CASE WHEN ? THEN now() END)
            SQL, [$id, $tenantId, 'identity-'.substr($id, 0, 8).'@example.test', self::HASH, $deleted ? 'true' : 'false']);

        return $id;
    }

    private function runMigration(Connection $owner, string $direction): Version20261023000800
    {
        $migration = new Version20261023000800($owner, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $owner->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        return $migration;
    }

    /** The configured key, read the way the migration reads it. Never printed. */
    private static function key(): string
    {
        $key = $_SERVER[Version20261023000800::KEY_NAME] ?? $_ENV[Version20261023000800::KEY_NAME] ?? null;
        self::assertIsString($key);

        return $key;
    }

    private function seal(): SealsIdentities
    {
        $seal = self::getContainer()->get('test.auth.identity_seal');
        \assert($seal instanceof SealsIdentities);

        return $seal;
    }
}
