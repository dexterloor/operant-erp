<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Platform\Tenancy\DatabaseRoles;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Test;

/**
 * The pooling conditions R-28 rests on, asserted against the running proxy.
 *
 * Everything else in this suite would still pass if `pgbouncer.ini` were edited
 * to `pool_mode = session`: the leak test would go green *because* the hazard
 * was gone, which is the most expensive kind of green there is. ADR-002 §C-3 is
 * a statement about transaction pooling, and until now nothing checked that
 * transaction pooling was actually in force.
 *
 * `max_db_connections` is the specific thing the `RED` gate asked for. It is a
 * whole-database ceiling across every pool, while `max_user_connections = 1`
 * caps each identity at one server connection -- so the ceiling has to be at
 * least the number of pooled identities or a role queues **silently** rather
 * than failing. It was 8 against six identities, which is a near miss dressed
 * as headroom, and a magic number tied to the role count by nothing but a
 * comment. Now it is a relationship.
 *
 * **Corrected 2026-09-16 (V3-6, V3-12; V4 batch B6).** This docblock said "the
 * seventh principal fails here". It did not: `max_db_connections (16) >= count`
 * passes up to sixteen identities, and a seventh name added to both the list and
 * `pg_roles` passed `SchemaGuardTest` too. Two things now make the set loud:
 * {@see self::fiveApplicationRolesIsTheCeiling} pins the count (a sixth
 * application role needs the user, ARCHITECTURE.md §3.3 PR-5), and
 * {@see self::theDatabaseConnectionCeilingLeavesRoomForEveryPrincipal} compares
 * the running proxy's user list with `DatabaseRoles::APPLICATION_ROLES`, so an
 * identity in PgBouncer's userlist that is not an application role -- the schema
 * owner, until B6 removed it -- fails.
 *
 * Read from PgBouncer's admin console, not from the file, because the file and
 * the container can disagree -- a mounted volume, a stale image, a hand-edited
 * running instance. The console speaks only the simple query protocol, hence
 * the emulated prepares.
 */
final class PoolConfigurationTest extends TenancyTestCase
{
    /** @return array<string, string> */
    private function runningPgBouncerConfig(): array
    {
        $config = [];
        foreach ($this->pgBouncerConsole()->query('SHOW CONFIG')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $config[$row['key']] = $row['value'];
        }

        self::assertNotSame([], $config, 'The admin console returned nothing, so this test proves nothing.');

        return $config;
    }

    private function pgBouncerConsole(): \PDO
    {
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))
            ->parse($_ENV['APP_DATABASE_URL']);

        $pdo = new \PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=pgbouncer', $params['host'], $params['port']),
            $params['user'],
            $params['password'],
            // The admin console rejects the extended query protocol outright.
            [\PDO::ATTR_EMULATE_PREPARES => true, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        return $pdo;
    }

    /**
     * ADR-002 §C-3's precondition. If this ever reads `session`, every isolation
     * test in the suite is green against a condition production does not have.
     */
    #[Test]
    public function theEndpointTheSuiteConnectsToIsTransactionPooled(): void
    {
        $config = $this->runningPgBouncerConfig();

        self::assertSame('transaction', $config['pool_mode'],
            'The suite is not running against a transaction-pooled endpoint, so R-28 is untested and the '
            .'bare-SET leak demonstration proves nothing about Neon.');

        // A pool of exactly one is what forces two clients onto the same backend.
        // At a larger size the leak test would pass or fail by luck (R-3).
        self::assertSame('1', $config['default_pool_size']);
        self::assertSame('1', $config['max_user_connections']);
        self::assertSame('DISCARD ALL', $config['server_reset_query'],
            'PgBouncer\'s own reset query is what leaves a custom GUC at the empty string rather than unset, '
            .'which is the whole reason A1.2\'s NULLIF exists.');
    }

    /**
     * Five application roles is the ceiling (V3-6). A sixth is a new database
     * principal, which ARCHITECTURE.md §3.3 PR-5 gives to the user, not to a code
     * change. Mutation, run at build: a sixth name in `APPLICATION_ROLES` -> red.
     */
    #[Test]
    public function fiveApplicationRolesIsTheCeiling(): void
    {
        self::assertCount(5, DatabaseRoles::APPLICATION_ROLES,
            'A sixth application role is a new database principal and requires the user -- ARCHITECTURE.md §3.3 PR-5.');
    }

    /**
     * The number, as a relationship rather than a constant.
     *
     * Pooled identities are exactly the application roles. **Changed 2026-09-16
     * (V3-12, V4 batch B6):** the relation was `count(APPLICATION_ROLES) + 1`,
     * because the schema owner had a userlist entry "so that migrations can be
     * run through the proxy if anyone ever needs to". Nobody did, migrations under
     * transaction pooling are unsafe anyway, and the entry put the owner's
     * password in the service that serves every pooled request (C-4). The entry
     * and the owner's variables are gone from the pgbouncer service.
     *
     * The identity set is read from the running proxy (`SHOW USERS`, minus the
     * console's own `pgbouncer` pseudo-user), so the relation is compared with
     * something that can disagree: the owner back in the userlist -> red; the old
     * `+ 1` -> red.
     */
    #[Test]
    public function theDatabaseConnectionCeilingLeavesRoomForEveryPrincipal(): void
    {
        $config = $this->runningPgBouncerConfig();
        $identities = \count(DatabaseRoles::APPLICATION_ROLES);

        $pooled = $this->runningPgBouncerUsers();
        $declared = DatabaseRoles::APPLICATION_ROLES;
        sort($declared);

        self::assertSame($declared, $pooled,
            'PgBouncer\'s userlist and DatabaseRoles::APPLICATION_ROLES disagree. The pooler must know the application '
            .'roles and nothing else: an owner or superuser entry puts that credential in the service that serves every '
            .'pooled request (ARCHITECTURE.md C-4).');
        self::assertSame($identities, \count($pooled),
            'The pooled identity count is not the application role count.');

        self::assertGreaterThanOrEqual($identities, (int) $config['max_db_connections'],
            sprintf(
                'max_db_connections is %s and there are %d pooled identities, each capped at one server '
                .'connection by max_user_connections. Below that count a principal does not fail, it queues '
                .'behind another role\'s transaction -- and a silent queue on a pre-authentication path is a '
                .'denial of service that looks like slowness.',
                $config['max_db_connections'], $identities));
    }

    /** @return list<string> sorted user names PgBouncer knows, without the console pseudo-user */
    private function runningPgBouncerUsers(): array
    {
        $names = [];
        foreach ($this->pgBouncerConsole()->query('SHOW USERS')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ('pgbouncer' !== $row['name']) {
                $names[] = (string) $row['name'];
            }
        }
        sort($names);

        self::assertNotSame([], $names, 'SHOW USERS returned no user, so this test proves nothing.');

        return $names;
    }
}
