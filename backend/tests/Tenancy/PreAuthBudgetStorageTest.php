<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Platform\Tenancy\DatabaseRoles;
use App\Platform\Tenancy\SchemaManifest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;

/**
 * SEC-O-16's storage, asserted as schema facts rather than as intentions.
 *
 * `SECURITY_MODEL.md` E-21 sets two pre-auth budgets on one fifteen-minute
 * window; E-9 and `API.md` §7.1 step 2 order the `(ip_prefix)` one first. The
 * ruling that answers it is *"a table whose key carries no workspace identity"*,
 * and a ruling is not a migration — so the two load-bearing halves are checked
 * here: what the key is made of, and that the window is a grid and not whatever
 * `now()` returned.
 *
 * Everything reads the catalogue. The one place a constant appears — the SQL
 * type map in the filler — is a map of *types*, not of tables, so the domain of
 * every assertion below is still the server's.
 */
final class PreAuthBudgetStorageTest extends TenancyTestCase
{
    /**
     * E-21's window, restated once so the test can build a misaligned value.
     * It is **not** the source of the assertion: the constraint text is read
     * back from `pg_constraint` and this test would fail, not adapt, if the
     * migration used a different figure.
     */
    private const WINDOW_SECONDS = 900;

    protected function setUp(): void
    {
        parent::setUp();

        // The base class truncates tenant-scoped tables only, and these are not.
        // As the owner, because no application role holds TRUNCATE on either --
        // which is the property under test two methods down.
        $this->owner()->executeStatement('TRUNCATE auth_ip_budget, auth_attempt');
    }

    /**
     * The ruling, in the only form a primary key can state it.
     *
     * `auth_attempt_pkey` leads with `slug_normalised`, which is client-supplied
     * and carries no shape constraint by design (ADR-006 §2.3 — an unresolvable
     * slug must still be recorded, which is what makes a dictionary scrape
     * loud). Any storage for the `(ip_prefix)` budget whose key contains a
     * client-supplied value lets the client choose which of E-21's two ceilings
     * -- 100 or 300 -- meters its request.
     *
     * *Mutation:* add `slug_normalised` to `auth_ip_budget_pkey` in
     * `Version20260909000100`. Red.
     */
    #[Test]
    public function theIpBudgetIsKeyedOnTheNetworkPrefixAndTheWindowAndNothingElse(): void
    {
        $key = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT a.attname
            FROM pg_constraint con
            JOIN pg_class c ON c.oid = con.conrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            CROSS JOIN LATERAL unnest(con.conkey) WITH ORDINALITY AS k(attnum, ord)
            JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum
            WHERE n.nspname = 'public' AND c.relname = 'auth_ip_budget' AND con.contype = 'p'
            ORDER BY k.ord
            SQL);

        self::assertSame(['ip_prefix', 'window_start'], $key,
            'The (ip_prefix) budget is keyed on something other than the network prefix and the window. '
            .'E-9 orders this budget before the auth_attempt upsert precisely because both of that table\'s '
            .'key columns are attacker-controlled; a key here that admits a client-supplied value reproduces '
            .'the defect one table along.');

        // And the negative half stated directly, because the assertion above
        // would also pass on a key that happened to be spelled differently:
        // no column of this table carries workspace identity at all.
        $columns = $this->owner()->fetchFirstColumn(
            'SELECT attname FROM pg_attribute WHERE attrelid = ?::regclass AND attnum > 0 AND NOT attisdropped',
            ['auth_ip_budget']);

        self::assertNotContains('slug_normalised', $columns);
        self::assertNotContains('tenant_id', $columns);
    }

    /**
     * E-21's window is **fixed** at 900 seconds and one duration serves both
     * budgets. The domain is derived from the catalogue -- every table carrying
     * a `window_start` -- rather than from a list, so a third counter table
     * inherits the requirement without anybody remembering it.
     *
     * *Mutation:* drop `auth_attempt_window_aligned` from
     * `Version20260909000100`. Red on `auth_attempt`, which is the case the
     * derived domain exists to catch -- a list would have named only the new
     * table.
     */
    #[Test]
    public function everyFixedWindowCounterTablePinsItsWindowToOneGrid(): void
    {
        $tables = $this->windowedTables();

        self::assertNotSame([], $tables, 'No table carries a window_start column, so this test proves nothing.');

        // Compared as the exact expression Postgres reports, the way
        // SchemaManifestTest compares the RLS policy text: a substring match
        // would accept a constraint that named a different number of seconds.
        $expected = "CHECK ((((date_part('epoch'::text, (window_start - '1970-01-01 00:00:00+00'::timestamp with time zone)))"
            ."::bigint % (900)::bigint) = 0))";

        foreach ($tables as $table) {
            $row = $this->owner()->fetchAssociative(<<<'SQL'
                SELECT pg_get_constraintdef(con.oid) AS def, con.convalidated
                FROM pg_constraint con
                JOIN pg_class c ON c.oid = con.conrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relname = ? AND con.conname = ? AND con.contype = 'c'
                SQL, [$table, $table.'_window_aligned']);

            self::assertNotFalse($row, $table.' carries a window_start and no '.$table.'_window_aligned constraint. '
                .'A window derived from now() rather than from the grid gives every request its own row, so no '
                .'budget ever fills and every ceiling test that checks only the refusal still passes.');
            self::assertSame($expected, $row['def'], $table.' pins its window to a different grid.');
            self::assertTrue((bool) $row['convalidated'],
                $table.'_window_aligned is NOT VALID, so it does not constrain the rows already there.');
        }
    }

    /**
     * The behavioural half, because a constraint that exists and does not fire
     * is the failure mode worth catching -- and because the structural test
     * above would pass on an expression that is syntactically right and
     * semantically inert.
     *
     * *Mutation:* change `%% 900` to `%% 1` in `Version20260909000100::alignment()`.
     * Every instant is then aligned, the misaligned insert succeeds, and this is
     * red on both tables. (`everyFixedWindowCounterTablePinsItsWindowToOneGrid`
     * is red too, which is the point of having both.)
     */
    #[Test]
    public function theGridConstraintActuallyRefusesAMisalignedWindow(): void
    {
        $aligned = '2026-09-09 10:15:00+00';
        $misaligned = '2026-09-09 10:15:01+00';

        foreach ($this->windowedTables() as $table) {
            // The aligned value first. Without it a filler that produced an
            // unusable row would make the refusal below pass for the wrong
            // reason -- and the SQLSTATE assertion alone cannot see that.
            $this->owner()->executeStatement(...$this->insertWithWindow($table, $aligned));
            self::assertSame(1, (int) $this->owner()->fetchOne("SELECT count(*) FROM $table"),
                "The aligned insert into $table did not land, so the refusal below proves nothing.");

            try {
                $this->owner()->executeStatement(...$this->insertWithWindow($table, $misaligned));
                self::fail("$table accepted a window_start one second off E-21's 900-second grid.");
            } catch (DriverException $e) {
                self::assertSame('23514', $e->getSQLState(),
                    "$table refused the misaligned window with $e, which is not a check-constraint violation.");
            }

            $this->owner()->executeStatement("TRUNCATE $table");
        }
    }

    /**
     * The grant is narrow -- `everyRoleHoldsExactlyTheTablePrivilegesTheManifestDeclares`
     * says so for every role and every privilege. This says it is also
     * **sufficient**: the consume-and-read the budget actually needs is one
     * statement, and `erp_ratelimit` can run it.
     *
     * A declared footprint that is one privilege short is not caught by any
     * narrowness assertion -- it is caught here, on the first request, which is
     * the difference between finding it now and finding it in Output 4.
     *
     * *Mutation:* drop `UPDATE` from the migration's GRANT and from the manifest
     * entry, so the two still agree. `everyRoleHoldsExactlyTheTablePrivileges…`
     * stays green; the second consume below raises `permission denied` and this
     * is red.
     */
    #[Test]
    public function theRateLimiterRoleCanConsumeTheIpBudgetInOneStatement(): void
    {
        $consume = <<<'SQL'
            INSERT INTO auth_ip_budget (ip_prefix, window_start, attempt_count)
            VALUES (?::cidr, ?::timestamptz, 1)
            ON CONFLICT (ip_prefix, window_start)
            DO UPDATE SET attempt_count = auth_ip_budget.attempt_count + 1
            RETURNING attempt_count
            SQL;

        $args = ['198.51.100.0/32', '2026-09-09 10:15:00+00'];

        self::assertSame(1, (int) $this->rateLimit()->fetchOne($consume, $args));
        self::assertSame(2, (int) $this->rateLimit()->fetchOne($consume, $args),
            'The second consume did not increment. RETURNING needs SELECT and the conflict path needs UPDATE; '
            .'a limiter that cannot increment admits every attempt after the first.');

        // And the one negative that B-1 is about: the application principal
        // cannot reach it, so a limiter wired to the default connection fails on
        // its first statement instead of working while the pre-auth path keeps
        // whatever else erp_app can reach.
        try {
            $this->app()->fetchOne($consume, $args);
            self::fail('erp_app consumed the IP budget.');
        } catch (DriverException $e) {
            self::assertSame('42501', $e->getSQLState());
        }
    }

    /** @return list<string> every non-partition table in `public` carrying a `window_start` column */
    private function windowedTables(): array
    {
        return $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'window_start'
                                AND a.attnum > 0 AND NOT a.attisdropped
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition
            ORDER BY 1
            SQL);
    }

    /**
     * The narrowest INSERT `$table` will accept, with `window_start` set to
     * `$window`: every NOT NULL column that has no default, filled by type.
     *
     * Derived per table from `pg_attribute`, so it is not a restatement of
     * either table's shape -- adding a NOT NULL column to a counter table does
     * not silently make these tests insert an invalid row.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function insertWithWindow(string $table, string $window): array
    {
        $columns = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT a.attname, format_type(a.atttypid, a.atttypmod) AS typ
            FROM pg_attribute a
            LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
            WHERE a.attrelid = ?::regclass AND a.attnum > 0 AND NOT a.attisdropped
              AND a.attnotnull AND d.adbin IS NULL
            ORDER BY a.attnum
            SQL, [$table]);

        $names = [];
        $values = [];

        foreach ($columns as $column) {
            $names[] = $column['attname'];
            $values[] = 'window_start' === $column['attname']
                ? $window
                : match ($column['typ']) {
                    'text' => 'x',
                    'cidr', 'inet' => '203.0.113.0/32',
                    'integer', 'bigint' => 1,
                    // Deliberately fatal rather than defaulted: a filler that
                    // guesses at an unknown type is how a test starts inserting
                    // a row that means nothing.
                    default => self::fail("No filler for $table.{$column['attname']} of type {$column['typ']}."),
                };
        }

        return [
            sprintf('INSERT INTO %s (%s) VALUES (%s)', $table,
                implode(', ', $names), implode(', ', array_fill(0, \count($names), '?'))),
            $values,
        ];
    }

    private function rateLimit(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.ratelimit_connection');
    }

    /**
     * Guards the guard: if the manifest ever stops granting this table to
     * erp_ratelimit, every positive assertion above becomes a test of a
     * principal that holds nothing, and would fail loudly rather than pass.
     */
    #[Test]
    public function theIpBudgetIsDeclaredToTheRateLimiterAndToNoOtherRole(): void
    {
        $declared = [];
        foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
            if ([] !== SchemaManifest::grantsFor('auth_ip_budget', $role)) {
                $declared[] = $role;
            }
        }

        self::assertSame([DatabaseRoles::RATE_LIMIT], $declared);
        self::assertSame(['SELECT', 'INSERT', 'UPDATE'],
            SchemaManifest::grantsFor('auth_ip_budget', DatabaseRoles::RATE_LIMIT),
            'The budget table declares DELETE or TRUNCATE. Retention is DROP PARTITION by the owner; widening '
            .'the one principal whose narrowness is the reason it exists needs an argument, not a grant.');
    }
}
