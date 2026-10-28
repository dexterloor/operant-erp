<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Application\RoleReconciliation;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\SealCheck;
use App\Modules\UserManagement\Domain\SealsIdentities;
use ErpDemoSeed\Locality;
use ErpDemoSeed\Seeder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

require_once \dirname(__DIR__, 2).'/bin/demo-seed.php';

/**
 * **W-3: `bin/demo-seed.php` run as a process, the way `make demo-seed` runs it** — against the suite's own (gate) database,
 * which it accepts only under `APP_ENV=test` with a `demo-test-` workspace (`Locality::afterConnecting()`'s exception).
 *
 * The child gets an EXPLICIT environment (`ServingCredentialGuardProcessTest`'s precedent): `PATH`, `APP_ENV=test` and the
 * case's overrides, never the parent's — which carries `SYMFONY_DOTENV_VARS`, and that would let the child's `Dotenv::load()`
 * overwrite an injected value. The child reads `.env.test` (owner DSN) and `.env` (the seal key) by path, as the test kernel
 * does, so both build the same seal. The password is random per test and goes in on stdin; it is never printed.
 *
 * **Cleanup** (`w2-w3-red.md` spar S-1): `setUp`, after `parent::setUp()`'s truncate, deletes the `demo-test-` tenants (and
 * the one purpose-probe slug) — `AuthTestCase::setUp()`'s precedent; there is no `tearDown` cleanup, because every
 * tenant-scoped table references `tenant` without `ON DELETE` and its rows still exist at `tearDown`. A run killed mid-test
 * therefore leaves at most one orphan `tenant` row, removed by the next run. Extends `AuthTestCase` because the sign-in writes
 * the three non-tenant auth tables, which its `setUp` truncates.
 */
final class DemoSeedProcessTest extends AuthTestCase
{
    /** A slug that is NOT `demo-test-`: the gate database must refuse it (the purpose check's process case). */
    private const NOT_A_TEST_SLUG = 'demo-purpose-probe';

    /** The seeded tables (`DemoSeedWriteSetTest` pins the same set statically). */
    private const SEEDED_TABLES = ['tenant_settings', 'role', 'role_permission', 'app_user', 'user_role', 'audit_log'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner()->executeStatement('DELETE FROM tenant WHERE slug LIKE ? OR slug = ?', [Locality::TEST_SLUG_PREFIX.'%', self::NOT_A_TEST_SLUG]);
    }

    /**
     * The whole contract in one run: exit 0; the workspace is active; eight built-in roles whose `default_key`s are
     * `DefaultRoles::names()` and whose codes equal `DefaultRoles::codesFor()` exactly; the owner signs in through
     * `IssueToken` (hash + `password_mac`); `identity_mac` verifies under the kernel's seal (sign-in never reads it, H-W3-2);
     * the hash's Argon2id parameters are the TEST KERNEL's (`app.auth.dummy_password_hash` from the container, not a second
     * parse of `services.yaml` — spar S-4); `app:roles:reconcile` reports nothing (arms A–D, D-system, F, G, H); a second run
     * exits 3 and changes no row count.
     */
    #[Test]
    public function theOwnerCanSignInToTheSeededWorkspaceAndTheRoleCheckFindsItClean(): void
    {
        $slug = self::slug();
        $password = self::password();
        [$code, , $stderr] = $this->seed($slug, $password);
        self::assertSame(Seeder::EXIT_OK, $code, $stderr);
        self::assertStringNotContainsString($password, $stderr);

        $tenant = $this->owner()->fetchAssociative('SELECT id::text AS id, status, name FROM tenant WHERE slug = ?', [$slug]);
        self::assertIsArray($tenant);
        self::assertSame('active', $tenant['status']);
        $tenantId = (string) $tenant['id'];

        $roles = $this->fetchAsOwnerForTenant($tenantId,
            'SELECT r.default_key, r.is_system, coalesce(string_agg(rp.permission_code, \',\' ORDER BY rp.permission_code), \'\') AS codes
               FROM role r LEFT JOIN role_permission rp ON rp.role_id = r.id GROUP BY r.id, r.default_key, r.is_system ORDER BY r.default_key');
        $expected = [];
        foreach (DefaultRoles::names() as $key) {
            $codes = DefaultRoles::codesFor($key);
            sort($codes);
            $expected[$key] = implode(',', $codes);
        }
        ksort($expected);
        $actual = [];
        foreach ($roles as $role) {
            self::assertTrue($role['is_system'], 'A seeded role is not a system role.');
            $actual[(string) $role['default_key']] = (string) $role['codes'];
        }
        self::assertSame($expected, $actual, 'The seeded built-in roles are not exactly DefaultRoles.');

        $owner = $this->fetchAsOwnerForTenant($tenantId, 'SELECT id::text AS id, email, password_hash, identity_mac FROM app_user')[0];
        self::assertSame(Seeder::OWNER_EMAIL, $owner['email']);

        $this->signIn($slug, Seeder::OWNER_EMAIL, $password);
        self::assertSame(200, $this->httpStatus(), 'The seeded owner cannot sign in with the supplied password.');

        $seal = self::getContainer()->get('test.auth.credential_seal');
        self::assertInstanceOf(SealsIdentities::class, $seal);
        self::assertSame(SealCheck::Verified, $seal->checkIdentity($tenantId, (string) $owner['id'], (string) $owner['email'], (string) $owner['identity_mac']),
            'identity_mac does not verify under the kernel\'s seal.');

        $dummy = self::getContainer()->getParameter('app.auth.dummy_password_hash');
        self::assertIsString($dummy);
        self::assertSame(password_get_info($dummy)['options'], password_get_info((string) $owner['password_hash'])['options'],
            'The seeded hash is not at the KDF cost the kernel uses.');

        [$reconcile, $display] = $this->reconcile($tenantId);
        self::assertSame(0, $reconcile, $display);
        self::assertSame(RoleReconciliation::CADENCE, trim($display));

        $before = $this->counts($tenantId);
        self::assertSame(['tenant_settings' => 1, 'role' => 8, 'role_permission' => $this->expectedCodeRows(), 'app_user' => 1, 'user_role' => 1], array_diff_key($before, ['audit_log' => 0]));
        [$again, , $stderr] = $this->seed($slug, $password);
        self::assertSame(Seeder::EXIT_EXISTS, $again, $stderr);
        self::assertSame($before, $this->counts($tenantId), 'A refused second run changed a row count.');
        self::assertSame(1, (int) $this->owner()->fetchOne('SELECT count(*) FROM tenant WHERE slug = ?', [$slug]));
    }

    /**
     * B-3 clause 4's run-time half (W-3 FINAL item 8): two seeded workspaces share no id, and every id the seeder wrote is
     * a version-4 UUID (the static half is `NonHttpIdentifierSourceTest`).
     */
    #[Test]
    public function twoSeededWorkspacesShareNoIdAndEveryIdIsVersionFour(): void
    {
        $ids = [];
        foreach ([self::slug(), self::slug()] as $slug) {
            [$code, , $stderr] = $this->seed($slug, self::password());
            self::assertSame(Seeder::EXIT_OK, $code, $stderr);
            $tenantId = (string) $this->owner()->fetchOne('SELECT id::text FROM tenant WHERE slug = ?', [$slug]);
            $mine = [$tenantId];
            foreach (self::SEEDED_TABLES as $table) {
                foreach ($this->fetchAsOwnerForTenant($tenantId, 'SELECT id::text AS id FROM '.$table) as $row) {
                    $mine[] = (string) $row['id'];
                }
            }
            $ids[] = $mine;
        }

        self::assertGreaterThan(100, \count($ids[0]), 'Anti-vacuity: the first workspace yielded almost no ids.');
        self::assertSame([], array_values(array_intersect($ids[0], $ids[1])), 'Two seeded workspaces share an id.');
        foreach ([...$ids[0], ...$ids[1]] as $id) {
            self::assertSame(['4', true], [$id[14], \in_array($id[19], ['8', '9', 'a', 'b'], true)], 'Not a version-4 id: '.$id);
        }
    }

    /**
     * G-10's "Arm E's gap", closed for the seeder through arm B (no new arm): the seeded grant deleted as the owner, with no
     * `role.revoked`, is reported as `unaccounted_removal`.
     */
    #[Test]
    public function aSeededGrantThatDisappearsUnauditedIsReported(): void
    {
        $slug = self::slug();
        [$code, , $stderr] = $this->seed($slug, self::password());
        self::assertSame(Seeder::EXIT_OK, $code, $stderr);
        $tenantId = (string) $this->owner()->fetchOne('SELECT id::text FROM tenant WHERE slug = ?', [$slug]);
        $this->insertAsOwnerForTenant($tenantId, 'DELETE FROM user_role');

        [$reconcile, $display] = $this->reconcile($tenantId);
        self::assertSame(1, $reconcile, $display);
        self::assertStringContainsString(RoleReconciliation::FINDING_UNACCOUNTED_REMOVAL.',', $display);
    }

    /**
     * The process refuses before writing, with the SPECIFIC line (spar S-2: a refusal for another reason must not pass):
     * `APP_ENV=prod`; an owner DSN on a non-loopback host, injected into the child's environment (Dotenv does not override
     * it); the gate database for a workspace that is not `demo-test-`; a password under the rules.
     */
    #[Test]
    public function theProcessRefusesWithTheReasonAndWritesNothing(): void
    {
        $cases = [
            'APP_ENV=prod' => [self::slug(), ['APP_ENV' => 'prod'], Locality::REFUSED_ENVIRONMENT],
            'a remote owner DSN' => [self::slug(), ['DATABASE_URL' => 'postgresql://someone:x@192.0.2.10:5432/erp?serverVersion=17&charset=utf8'], Locality::REFUSED_HOST],
            'the gate database, not a demo-test- workspace' => [self::NOT_A_TEST_SLUG, [], Locality::REFUSED_PURPOSE],
        ];
        foreach ($cases as $name => [$slug, $env, $line]) {
            [$code, , $stderr] = $this->seed($slug, self::password(), $env);
            self::assertSame(Seeder::EXIT_REFUSED, $code, $name.': '.$stderr);
            self::assertSame($line, trim($stderr), $name);
            self::assertFalse($this->owner()->fetchOne('SELECT 1 FROM tenant WHERE slug = ?', [$slug]), $name.': a tenant was written.');
        }

        [$code, , $stderr] = $this->seed(self::slug(), 'short');
        self::assertSame(Seeder::EXIT_REFUSED, $code, $stderr);
        self::assertStringNotContainsString('short', $stderr);
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function seed(string $slug, string $password, array $env = []): array
    {
        $backend = \dirname(__DIR__, 2);
        $process = proc_open(
            [\PHP_BINARY, $backend.'/bin/demo-seed.php', '--workspace='.$slug, '--password-stdin'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $backend,
            $env + ['PATH' => (string) getenv('PATH'), 'APP_ENV' => 'test'],
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $password);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /** @return array{0: int, 1: string} */
    private function reconcile(string $tenantId): array
    {
        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException('No kernel.')))->find('app:roles:reconcile'));
        $code = $tester->execute(['--tenant' => $tenantId]);

        return [$code, $tester->getDisplay()];
    }

    /** @return array<string, int> */
    private function counts(string $tenantId): array
    {
        $counts = [];
        foreach (self::SEEDED_TABLES as $table) {
            $counts[$table] = (int) $this->fetchAsOwnerForTenant($tenantId, 'SELECT count(*) AS n FROM '.$table)[0]['n'];
        }

        return $counts;
    }

    private function expectedCodeRows(): int
    {
        return array_sum(array_map(static fn (string $key): int => \count(DefaultRoles::codesFor($key)), DefaultRoles::names()));
    }

    private static function slug(): string
    {
        return Locality::TEST_SLUG_PREFIX.substr(bin2hex(random_bytes(6)), 0, 10);
    }

    private static function password(): string
    {
        return bin2hex(random_bytes(12));
    }
}
