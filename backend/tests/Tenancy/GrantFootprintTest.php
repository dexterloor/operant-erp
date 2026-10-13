<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Platform\Tenancy\DatabaseRoles;
use App\Platform\Tenancy\SchemaManifest;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;

/**
 * Every (role, table, privilege) triple in the schema, asserted against the
 * manifest. Nothing is sampled.
 *
 * This is one test doing the work of ADR-006 V-1, V-2, V-3 and V-10, plus
 * ARCHITECTURE S-9, S-10 and T-7 — not because bundling is tidy, but because
 * the defect those checks all describe is the same one: a privilege arriving
 * somewhere nobody looked. A spot check answers "is audit_log still
 * append-only"; this answers "is anything anywhere wider than declared", which
 * is the question that survives twenty-four more tables.
 *
 * Everything uses has_table_privilege / has_any_column_privilege /
 * has_column_privilege. A1.5: information_schema.role_table_grants resolves
 * neither PUBLIC grants nor role inheritance, so a check written against it
 * returns zero rows while the role holds the privilege.
 */
final class GrantFootprintTest extends TenancyTestCase
{
    /**
     * The one that closes V-10/S-13, stated as its own test because it is the
     * exposure ADR-006 Condition 2 promotes to equal priority with the five-role
     * model — and which five roles do not close.
     *
     * §2.1 step 1 runs the pre-auth rate limiter, and §6.3 establishes that a
     * table with no tenant_id carries no RLS and inherits the blanket grants.
     * `messenger_messages` and `tenant` are both in that set. Joining the two
     * facts: an unauthenticated request reaches a principal that can inject into
     * the message queue — worker code execution by another name — and mutate the
     * tenant registry.
     */
    #[Test]
    public function noPreAuthPrincipalCanReachTheMessageQueueOrTheTenantRegistry(): void
    {
        foreach (DatabaseRoles::PRE_AUTH_ROLES as $role) {
            foreach (['messenger_messages', 'tenant'] as $table) {
                foreach (SchemaManifest::TABLE_PRIVILEGES as $priv) {
                    $held = (bool) $this->owner()->fetchOne(
                        'SELECT has_table_privilege(?, ?, ?)', [$role, $table, $priv]);

                    // erp_auth's column-scoped SELECT on tenant is the one
                    // deliberate exception, and it is a column grant, so
                    // has_table_privilege is false for it too.
                    self::assertFalse($held, "$role holds $priv on $table.");
                }
            }
        }

        // And the positive half, so the test cannot pass because the roles are
        // simply absent: erp_auth really can read the three registry columns.
        foreach (['id', 'slug', 'status'] as $column) {
            self::assertTrue((bool) $this->owner()->fetchOne(
                'SELECT has_column_privilege(?, ?, ?, ?)', [DatabaseRoles::AUTH, 'tenant', $column, 'SELECT']),
                "erp_auth cannot read tenant.$column, so the login path cannot resolve a slug at all.");
        }
    }

    /**
     * B-1's exit criterion, encoded. `ROADMAP.md` states it as a query to run at
     * Output 4's exit; a query someone runs is not a gate, and "a ruling is not
     * a migration" is the exact reason this took three reports to notice was
     * never executed. So it lives here and runs every build.
     *
     * Two domains, intersected separately and never merged.
     * `has_any_column_privilege()` raises `unrecognized privilege type` for
     * DELETE, TRUNCATE and TRIGGER (measured; ADR-006 V-1, and the reason
     * `COLUMN_PRIVILEGES` exists as a second constant), so a merged list makes
     * CI **error** rather than fail -- a different way of not being a check.
     *
     * Both domains are derived from the manifest and neither is restated, and
     * both constants are themselves reconciled against the server by
     * theCoverageConstantsNameEveryPrivilegePostgresHas, which GRANTs ALL on a
     * probe table and reads the ACL back through aclexplode. So
     * COLUMN_PRIVILEGES cannot be shrunk to hide a column grant either.
     *
     * The column half is not decoration. `has_table_privilege()` answers *false*
     * for a column-level grant, so
     * `GRANT INSERT (body) ON messenger_messages TO erp_app` leaves a
     * table-level-only check green while the queue stays writable -- the whole
     * of the V-10 exposure B-1 exists to close, and the third instance in this
     * project of a check that answers about less than you think
     * (`information_schema`, ADR-002 A1.5; the unchecked privilege constant,
     * S-17; this). The second half of this test plants exactly that grant and
     * proves the check fires on it.
     *
     * What this test does NOT catch, stated so it is not mistaken for cover:
     * a `SECURITY DEFINER` function owned by the schema owner that inserts into
     * the queue, with `EXECUTE` granted to `erp_app`. The query below returns
     * zero rows throughout while that holds. It is B-1 action 2 and is unbuilt;
     * `noSecurityDefinerFunctionExistsAnywhere` below is what keeps it from
     * arriving unreviewed.
     *
     * *Correction, measured.* An earlier draft of this docblock said that shape
     * was "already precedent in the tree", citing `cascade_deletion_to_lines`,
     * and `ROADMAP.md` says the same. **It is false**: that function is
     * `prosecdef = f` — owner-owned with `EXECUTE` to `erp_app`, which is a
     * different and much weaker thing than `SECURITY DEFINER`. There is no
     * definer precedent here, so action 2 would be establishing one.
     */
    #[Test]
    public function noApplicationRoleHoldsAnyWritePrivilegeOnTheQueueAtEitherLevel(): void
    {
        // TRUNCATE is in the table domain and deliberately not in the column
        // one: has_any_column_privilege() raises `unrecognized privilege type`
        // for it. On the queue it is message destruction rather than code
        // execution, so it sits outside V-10 proper -- but this test's name says
        // "any write privilege", and a domain that quietly excludes one is the
        // defect class (S-17) this project has already been burned by.
        $tableWrite = ['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'];
        $columnWrite = ['INSERT', 'UPDATE', 'DELETE'];
        $tablePrivs = array_values(array_intersect(SchemaManifest::TABLE_PRIVILEGES, $tableWrite));
        $columnPrivs = array_values(array_intersect(SchemaManifest::COLUMN_PRIVILEGES, $columnWrite));

        self::assertNotSame([], $tablePrivs, 'TABLE_PRIVILEGES names no write privilege, so this test proves nothing.');
        self::assertNotSame([], $columnPrivs, 'COLUMN_PRIVILEGES names no write privilege, so this test proves nothing.');

        // The ::text[] casts are load-bearing: an untyped bind parameter to
        // unnest() raises `could not determine data type of parameter`, which is
        // CI erroring rather than failing -- the same non-check this exists to
        // remove. Both lists are catalogue identifiers, so a literal array
        // constructor is safe and keeps the SQL identical to ROADMAP's form.
        $held = fn (string $role): array => $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT 'table' AS level, p FROM unnest(?::text[]) AS p
             WHERE has_table_privilege(?, 'messenger_messages', p)
            UNION ALL
            SELECT 'column' AS level, p FROM unnest(?::text[]) AS p
             WHERE has_any_column_privilege(?, 'messenger_messages', p)
            SQL, [
                '{'.implode(',', $tablePrivs).'}', $role,
                '{'.implode(',', $columnPrivs).'}', $role,
            ]);

        // Read once per role: evaluating $held() again inside the failure
        // message would issue a second query and could report a different state
        // than the one asserted.
        foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
            $actual = $held($role);

            self::assertSame([], $actual, sprintf(
                "%s can write the message queue: %s\nAn INSERT into messenger_messages is worker code execution "
                ."by another name (ADR-006 V-10 / ARCHITECTURE S-13).", $role,
                implode(', ', array_map(static fn (array $r): string => "{$r['level']}/{$r['p']}", $actual))));
        }

        // The mutation. Without this the assertion above is a check nobody has
        // shown can fail -- and a column grant is precisely the shape that
        // slipped past its previous form. Rolled back, never committed.
        $this->owner()->beginTransaction();

        try {
            $this->owner()->executeStatement(
                sprintf('GRANT INSERT (body) ON messenger_messages TO %s', DatabaseRoles::APP));

            // Flattened to strings rather than compared as associative rows:
            // assertSame on arrays is sensitive to both row order (the UNION ALL
            // has no ORDER BY) and key order, neither of which is the property
            // under test.
            $planted = array_map(static fn (array $r): string => "{$r['level']}/{$r['p']}", $held(DatabaseRoles::APP));
            sort($planted);

            self::assertSame(['column/INSERT'], $planted,
                'A column-level INSERT on the queue did not make this check fail, so it is not a check. '
                .'Expected exactly the column row: has_table_privilege() answers false for a column grant, '
                .'which is the defect the column half exists to close.');
        } finally {
            $this->owner()->rollBack();
        }

        // And the rollback left nothing behind, on a connection later tests share.
        self::assertSame([], $held(DatabaseRoles::APP),
            'The planted column grant survived the rollback.');
    }

    /**
     * The coverage of every other test in this class, checked against the
     * server rather than against itself.
     *
     * `RED`'s MAJOR-1: `SchemaManifest::TABLE_PRIVILEGES` decides which
     * privileges the exhaustive check iterates, and nothing asserted it was
     * complete. Cutting it to `['SELECT','INSERT','UPDATE']` and granting
     * `DELETE, TRUNCATE ON invoice TO erp_app` -- a table declared "never
     * deleted" -- left the suite green. A constant that silently narrows what a
     * check looks at is the same defect class as a check written against
     * `information_schema` (A1.5): it answers, and it answers about less than
     * you think.
     *
     * Derived by granting everything on a throwaway table and reading back what
     * Postgres recorded, so a version that adds a privilege type fails here
     * instead of widening the blind spot.
     */
    #[Test]
    public function theCoverageConstantsNameEveryPrivilegePostgresHas(): void
    {
        $this->owner()->executeStatement('CREATE TABLE privilege_probe (id uuid NOT NULL PRIMARY KEY, payload text)');

        try {
            $this->owner()->executeStatement('GRANT ALL ON privilege_probe TO '.DatabaseRoles::APP);
            $this->owner()->executeStatement('GRANT ALL (id, payload) ON privilege_probe TO '.DatabaseRoles::AI);

            $tableLevel = $this->owner()->fetchFirstColumn(<<<'SQL'
                SELECT DISTINCT a.privilege_type
                FROM pg_class c CROSS JOIN LATERAL aclexplode(c.relacl) a
                WHERE c.oid = 'privilege_probe'::regclass AND a.grantee = ?::regrole::oid
                ORDER BY 1
                SQL, [DatabaseRoles::APP]);

            $columnLevel = $this->owner()->fetchFirstColumn(<<<'SQL'
                SELECT DISTINCT x.privilege_type
                FROM pg_attribute att CROSS JOIN LATERAL aclexplode(att.attacl) x
                WHERE att.attrelid = 'privilege_probe'::regclass AND x.grantee = ?::regrole::oid
                ORDER BY 1
                SQL, [DatabaseRoles::AI]);

            $declaredTable = SchemaManifest::TABLE_PRIVILEGES;
            sort($declaredTable);
            $declaredColumn = SchemaManifest::COLUMN_PRIVILEGES;
            sort($declaredColumn);

            self::assertSame($declaredTable, $tableLevel,
                'SchemaManifest::TABLE_PRIVILEGES does not name every table privilege this PostgreSQL has. '
                .'Every exhaustive check in this class iterates that constant, so anything missing from it is '
                .'a privilege no test in the project would ever notice being granted.');
            self::assertSame($declaredColumn, $columnLevel,
                'SchemaManifest::COLUMN_PRIVILEGES does not name every column privilege this PostgreSQL has.');
        } finally {
            $this->owner()->executeStatement('DROP TABLE privilege_probe');
        }
    }

    /**
     * `RED`'s MINOR: the deletion class was decorative. Declaring `DELETE` for
     * `invoice` -- class `none`, "never deleted" -- and granting it passed
     * everything, because only `audit_log` and `stock_movement` had a check and
     * that check named them by hand. The class now has to mean something.
     */
    #[Test]
    public function theDeletionClassDeterminesTheGrantFootprint(): void
    {
        $wrong = [];

        foreach (SchemaManifest::TABLES as $table => $spec) {
            if (SchemaManifest::NONE !== $spec['deletion']) {
                continue;
            }

            foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
                foreach (['DELETE', 'TRUNCATE'] as $priv) {
                    if ((bool) $this->owner()->fetchOne('SELECT has_table_privilege(?, ?, ?)', [$role, $table, $priv])) {
                        $wrong[] = "$role holds $priv on $table, which is declared never-deleted";
                    }
                }
            }
        }

        self::assertSame([], $wrong, implode("\n", $wrong));
        self::assertNotSame([], array_filter(SchemaManifest::TABLES,
            static fn (array $spec): bool => SchemaManifest::NONE === $spec['deletion']),
            'No table is declared never-deleted, so this test proves nothing.');
    }

    /**
     * Sequences and functions, which `RED` found unmodelled: two spot checks
     * covered one sequence, and `GRANT USAGE ON SEQUENCE … TO erp_ai` would
     * have passed everything. Functions arrived with the deletion cascade
     * trigger and default to `EXECUTE` for `PUBLIC`, which is the same shape as
     * the table grants this schema spends its first statements withdrawing.
     */
    #[Test]
    public function sequenceAndFunctionPrivilegesAreAsNarrowAsTheTableOnes(): void
    {
        $sequences = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind = 'S' ORDER BY 1
            SQL);

        self::assertSame(['messenger_messages_id_seq'], $sequences,
            'A sequence appeared that nothing accounts for. Every other identifier in this schema is a UUID; '
            .'the only sequence is the one Symfony Messenger\'s own transport creates.');

        // B-1. This loop previously expected erp_app to hold USAGE and SELECT on
        // the queue sequence, because erp_app held INSERT on the queue. It no
        // longer does, and the sequence went with it: the grant existed only to
        // serve nextval() on an INSERT that is now denied, so what remains is a
        // live privilege whose justification has been deleted.
        foreach ($sequences as $sequence) {
            foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
                foreach (['USAGE', 'SELECT', 'UPDATE'] as $priv) {
                    self::assertFalse((bool) $this->owner()->fetchOne(
                        'SELECT has_sequence_privilege(?, ?, ?)', [$role, $sequence, $priv]),
                        "$role holds $priv on $sequence. No application role writes the queue, so none needs its sequence.");
                }
            }
        }

        $publicFunctions = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT p.proname
            FROM pg_proc p
            JOIN pg_namespace n ON n.oid = p.pronamespace
            CROSS JOIN LATERAL aclexplode(COALESCE(p.proacl, acldefault('f', p.proowner))) a
            WHERE n.nspname = 'public' AND a.grantee = 0
            ORDER BY 1
            SQL);

        self::assertSame([], $publicFunctions,
            'PUBLIC can execute: '.implode(', ', $publicFunctions).'. Function privileges default to PUBLIC, '
            .'so every function added from here on has to revoke that explicitly.');
    }

    /**
     * ADR-001 check 3 (T-5), which was specified and only partly implemented.
     *
     * Stated precisely, because the first version of this docblock said "nothing
     * in this suite read `prosecdef` before now" and that is false:
     * `SchemaGuardTest` has read it since `ba33403`. What was missing is
     * narrower — that check is scoped to `nspname = 'public'` and carries no
     * mutation proof, so T-5 *as ADR-001 documents it* (any schema, proven able
     * to fail) was unasserted. `prosecdef` itself was not unread.
     *
     * This also bounds the B-1 coupling honestly: a definer function written the
     * obvious way lands in `public`, where the older check already saw it. What
     * this method adds is the un-scoped domain and the mutation proof, not first
     * coverage.
     *
     * It becomes load-bearing with B-1. Version20260907000100 revokes the
     * queue's DML from `erp_app` and its docblock claims that Messenger cannot
     * now be installed without B-1 action 2 also being done. That claim is only
     * true if the definer route is itself watched: a `SECURITY DEFINER` function
     * owned by the schema owner, inserting into `messenger_messages`, with
     * `EXECUTE` to `erp_app`, restores V-10 in full while every privilege
     * assertion in this class stays green. The revoke makes the loud path loud
     * and leaves the quiet path quiet; this is the difference.
     *
     * Deliberately a blanket refusal rather than a queue-specific one. A definer
     * function is a privilege-escalation primitive whatever it touches, and a
     * check scoped to the queue would be satisfied by a definer function that
     * writes `tenant`. When action 2 lands, this test is where its allowlist
     * entry and its reason go — which is the point: it cannot land silently.
     *
     * ADR-006 §5.2 (a): not filtered to `public`. A definer function in any
     * other schema is the blind spot that finding names.
     */
    #[Test]
    public function noSecurityDefinerFunctionExistsInAnyNonSystemSchema(): void
    {
        $definers = <<<'SQL'
            SELECT n.nspname||'.'||p.proname
            FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE p.prosecdef AND n.nspname NOT IN ('pg_catalog', 'information_schema')
            ORDER BY 1
            SQL;

        self::assertSame([], $this->owner()->fetchFirstColumn($definers),
            'A SECURITY DEFINER function exists. It runs with its owner\'s privileges, so it can write anything '
            .'the owner can — including messenger_messages, which B-1 just closed to erp_app. If this is B-1 '
            .'action 2, it needs an allowlist entry here naming its reason and proving it takes typed parameters '
            .'rather than a caller-supplied message body.');

        // The mutation, for the same reason the queue check carries one: an
        // assertion over an empty catalogue passes whether or not it works.
        $this->owner()->beginTransaction();

        try {
            $this->owner()->executeStatement(
                'CREATE FUNCTION definer_probe() RETURNS int LANGUAGE sql SECURITY DEFINER AS $$ SELECT 1 $$');

            self::assertSame(['public.definer_probe'], $this->owner()->fetchFirstColumn($definers),
                'A SECURITY DEFINER function was created and this check did not see it.');
        } finally {
            $this->owner()->rollBack();
        }

        self::assertSame([], $this->owner()->fetchFirstColumn($definers),
            'The probe function survived the rollback.');
    }

    /**
     * `RED`'s MAJOR-5, stated as the property that actually holds rather than as
     * the one I would like to claim.
     *
     * V-10/S-13 is closed by grant on the registry: no application role can
     * write `tenant` at all.
     *
     * **Superseded on the queue by B-1 (Version20260907000100).** This docblock
     * previously read: *"It is not closed on the queue, because `erp_app`
     * legitimately needs full DML there for the worker … if Output 4 wires the
     * pre-auth route to the default connection, the queue is reachable again."*
     * That premise is gone. `erp_app` holds `SELECT` and nothing else on
     * `messenger_messages`, so wiring the pre-auth route to the `app`
     * connection no longer makes the queue *writable* — it would still be
     * wrong, and this test is still the thing that makes it loud, but the
     * consequence is now a cross-tenant payload **read** rather than queue
     * injection. The write residual this paragraph described no longer exists;
     * the read residual is declared in `SchemaManifest` and owned by B-1
     * action 4.
     *
     * What the schema *can* guarantee, and this asserts, is that the mistake is
     * loud rather than silent: `erp_app` holds nothing whatsoever on the
     * counter tables, so a rate limiter pointed at the wrong connection fails
     * with `permission denied` on its first statement instead of working and
     * quietly leaving the queue exposed.
     *
     * **⚠ Corrected 2026-09-14 — `erp_app` is NOT the default connection.** The
     * quoted paragraph above, and this docblock until today, called the `app`
     * connection "the default connection". It is not, and it was measured:
     * `config/packages/doctrine.yaml` sets `default_connection: owner`, so an
     * autowired `Connection` with no binding runs as the **schema owner**
     * (`tests/Architecture/ConnectionBindingTest.php` pins that). The "wrong
     * connection" this test makes loud is therefore any application principal
     * (`erp_app`, `erp_ai`, `erp_auth`, `erp_authlog`) — **not** an unbound
     * default, which holds every privilege and would not be refused here. That
     * mistake is `ConnectionBindingTest`'s to catch, not this test's.
     *
     * **The table list was `['auth_attempt', 'auth_lockout']` until SEC-O-16
     * (2026-09-09) and that was the fake-green shape this project keeps
     * finding.** `auth_ip_budget` is a third counter table on the same path
     * under the same principal, and a hardcoded pair would have said nothing
     * about it — the check that exists to catch a limiter on the wrong
     * connection would have been silent about the newest thing that limiter
     * touches. The domain is now derived from the manifest: every table
     * declared to `erp_ratelimit`. Adding a fourth needs no edit here.
     */
    #[Test]
    public function aRateLimiterWiredToTheWrongConnectionFailsImmediately(): void
    {
        $counters = array_values(array_filter(
            SchemaManifest::allTables(),
            static fn (string $t): bool => [] !== SchemaManifest::grantsFor($t, DatabaseRoles::RATE_LIMIT),
        ));

        self::assertNotSame([], $counters,
            'No table is declared to erp_ratelimit, so this test proves nothing.');

        foreach ($counters as $table) {
            foreach ([DatabaseRoles::APP, DatabaseRoles::AI, DatabaseRoles::AUTH, DatabaseRoles::AUTH_LOG] as $role) {
                foreach (SchemaManifest::TABLE_PRIVILEGES as $priv) {
                    self::assertFalse((bool) $this->owner()->fetchOne(
                        'SELECT has_table_privilege(?, ?, ?)', [$role, $table, $priv]),
                        "$role holds $priv on $table, so a limiter wired to that connection would silently work "
                        .'and the pre-authentication path would keep whatever else that role can reach.');
                }
            }

            self::assertTrue((bool) $this->owner()->fetchOne(
                'SELECT has_table_privilege(?, ?, ?)', [DatabaseRoles::RATE_LIMIT, $table, 'INSERT']),
                'erp_ratelimit cannot write the table it exists for.');
        }
    }

    /**
     * **E4-4 as amended — E-12's suppression storage is reachable by
     * `erp_ratelimit` alone.** *fails if:* *"the new storage is reachable by any
     * principal but `erp_ratelimit`"* (`DELIVERY_LEDGER.md` E4-4; plan v3 C1).
     *
     * `everyColumnLevelPrivilegeIsExactlyTheDeclaredSet` already walks these two
     * columns, because it derives its columns from the catalogue. This names them,
     * so a rename or a drop is red rather than silently leaving the walk with
     * nothing of E-12's to check, and it carries its own planted grant.
     *
     * *Mutation, run:* `GRANT SELECT (suppression_count) ON auth_lockout TO erp_app`
     * appended to `Version20260914000100`; red here and in the column walk.
     */
    #[Test]
    public function theLoginFailureCapStorageIsReachableByTheRateLimiterAlone(): void
    {
        $columns = ['suppression_count', 'suppression_day'];

        $present = $this->owner()->fetchFirstColumn(
            "SELECT attname FROM pg_attribute WHERE attrelid = 'auth_lockout'::regclass AND attnum > 0 AND NOT attisdropped AND attname = ANY(?::text[]) ORDER BY attname",
            ['{'.implode(',', $columns).'}']);
        self::assertSame($columns, $present, 'E-12\'s suppression storage is not on auth_lockout, so this test has no subject.');

        $wrong = function () use ($columns): array {
            $found = [];
            foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
                $declared = SchemaManifest::grantsFor('auth_lockout', $role);
                foreach (SchemaManifest::COLUMN_PRIVILEGES as $priv) {
                    foreach ($columns as $column) {
                        $expected = DatabaseRoles::RATE_LIMIT === $role && \in_array($priv, $declared, true);
                        $actual = (bool) $this->owner()->fetchOne(
                            'SELECT has_column_privilege(?, ?, ?, ?)', [$role, 'auth_lockout', $column, $priv]);
                        if ($expected !== $actual) {
                            $found[] = "$role $priv auth_lockout.$column";
                        }
                    }
                }
            }

            return $found;
        };

        self::assertSame(['SELECT', 'INSERT', 'UPDATE'], SchemaManifest::grantsFor('auth_lockout', DatabaseRoles::RATE_LIMIT),
            'The manifest no longer declares erp_ratelimit\'s footprint on auth_lockout, so the expectation below is not E4-4\'s.');
        self::assertSame([], $wrong(), 'E-12\'s suppression storage is reachable by a principal other than erp_ratelimit, or not by it.');

        $this->owner()->beginTransaction();
        try {
            $this->owner()->executeStatement(sprintf('GRANT SELECT (suppression_count) ON auth_lockout TO %s', DatabaseRoles::APP));
            self::assertSame([DatabaseRoles::APP.' SELECT auth_lockout.suppression_count'], $wrong(),
                'A planted column grant on the suppression storage did not make this check fail, so it is not a check.');
        } finally {
            $this->owner()->rollBack();
        }

        self::assertSame([], $wrong(), 'The planted grant survived the rollback.');
    }

    #[Test]
    public function everyRoleHoldsExactlyTheTablePrivilegesTheManifestDeclares(): void
    {
        $wrong = [];

        foreach (SchemaManifest::allTables() as $table) {
            foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
                $declared = SchemaManifest::grantsFor($table, $role);

                foreach (SchemaManifest::TABLE_PRIVILEGES as $priv) {
                    $expected = \in_array($priv, $declared, true);
                    $actual = (bool) $this->owner()->fetchOne(
                        'SELECT has_table_privilege(?, ?, ?)', [$role, $table, $priv]);

                    if ($expected !== $actual) {
                        $wrong[] = sprintf('%s %s %s: declared %s, actual %s',
                            $role, $priv, $table,
                            $expected ? 'granted' : 'not granted',
                            $actual ? 'granted' : 'not granted');
                    }
                }
            }
        }

        self::assertSame([], $wrong, "Grants do not match SchemaManifest:\n  ".implode("\n  ", $wrong));
    }

    /**
     * ADR-006 V-3, generalised. "Identity columns only" was previously unchecked
     * in any form; here it is checked for every role on every column of every
     * table, which also catches a column grant nobody declared.
     */
    #[Test]
    public function everyColumnLevelPrivilegeIsExactlyTheDeclaredSet(): void
    {
        $wrong = [];

        foreach (SchemaManifest::allTables() as $table) {
            $columns = $this->owner()->fetchFirstColumn(<<<'SQL'
                SELECT a.attname FROM pg_attribute a
                WHERE a.attrelid = ?::regclass AND a.attnum > 0 AND NOT a.attisdropped
                ORDER BY a.attnum
                SQL, [$table]);

            foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
                $tableLevel = SchemaManifest::grantsFor($table, $role);

                foreach (SchemaManifest::COLUMN_PRIVILEGES as $priv) {
                    $declaredColumns = \in_array($priv, $tableLevel, true)
                        ? $columns
                        : SchemaManifest::columnGrantsFor($table, $role, $priv);

                    foreach ($columns as $column) {
                        $expected = \in_array($column, $declaredColumns, true);
                        $actual = (bool) $this->owner()->fetchOne(
                            'SELECT has_column_privilege(?, ?, ?, ?)', [$role, $table, $column, $priv]);

                        if ($expected !== $actual) {
                            $wrong[] = sprintf('%s %s %s.%s: declared %s, actual %s',
                                $role, $priv, $table, $column,
                                $expected ? 'granted' : 'not granted',
                                $actual ? 'granted' : 'not granted');
                        }
                    }
                }
            }
        }

        self::assertSame([], $wrong, "Column privileges do not match SchemaManifest:\n  ".implode("\n  ", $wrong));
    }

    /**
     * The columns of `app_user` that `sign-in` and a password change write, and no others (H0, ADR-002 A4.0,
     * 2026-09-22, `Version20260922000300`). Written here by hand from the callers, **never** read from
     * `SchemaManifest` — see the method's docblock for why that matters.
     *
     *   LoginRecorder  -> AppUser::recordLogin()         -> last_login_at, updated_at
     *   ChangePassword -> AppUser::replacePasswordHash() -> password_hash, updated_at
     *
     * Both SET lists were measured from the server log at build, not inferred.
     */
    private const APP_USER_COLUMNS_THE_CALLERS_WRITE = ['last_login_at', 'password_hash', 'updated_at'];

    /**
     * **H0: `erp_app` can UPDATE exactly the `app_user` columns its callers write — proved by running the
     * statement as `erp_app`, not by reading the catalogue.**
     *
     * The two pins above compare the catalogue with `SchemaManifest`. If the manifest and a migration widen
     * **together** (say `email` added to both), both stay green. This method's expected set is the callers',
     * written as a literal in this class, so it fails when the grant widens even though the manifest agrees.
     * **That is the only reason it exists, and it holds only while the literal stays a literal:** a later hand
     * that "tidies" the constant into `SchemaManifest::columnGrantsFor('app_user', …)` leaves every assertion
     * and the planted control below green while the check goes blind to a joint widening. No plant can catch
     * that; only re-running the joint-widening mutation (M6 in `docs/session12/h0-progress.md`) can.
     *
     * **Domain, server-derived:** every live column of `app_user` from `pg_attribute`, never a list.
     * **Per column, one connection and one transaction** (never a loop inside one transaction: a refused
     * statement aborts it, and every later column would read `25P02`). Each runs
     * `UPDATE app_user SET <col> = <col> WHERE id = ?` as `erp_app` in tenant A's context, set through
     * `TenantContext::establishOn` (TenantContextContainmentTest scans this directory), then rolls back.
     * `SET col = col` satisfies every CHECK and unique, so the only refusal it can meet is the privilege.
     *
     * **A refusal is SQLSTATE `42501` AND `permission denied for table app_user`** — ADR-002 A4.3 R3 forbids
     * identifying one by `42501` alone, because RLS raises it too. Anything else is reported verbatim as a
     * third outcome and fails, so an environment fault cannot pass as a refusal.
     *
     * **What H0 does not buy** (RED H-1): `password_hash` stays writable, so lockout and the credential takeover
     * both remain through it (`RESIDUAL_GAPS.md` G-12, `(e)-cred`), and `last_login_at` / `updated_at` stay
     * forgeable. This method pins the routes H0 closes; it does not certify the table as safe.
     *
     * **Planted control, committed then undone in `finally`** — the precedent is
     * `theCoverageConstantsNameEveryPrivilegePostgresHas`. A grant on the owner's *uncommitted* transaction is
     * invisible to `erp_app`'s separate session, and the owner cannot `SET ROLE erp_app` (it is not a member,
     * measured), so the plant has to be real to reach the probe. `display_name` is chosen because its `attacl`
     * is otherwise empty. A crash that skips `finally` leaves the grant behind, and
     * `everyColumnLevelPrivilegeIsExactlyTheDeclaredSet` fails loudly on the next run.
     */
    #[Test]
    public function theApplicationCanUpdateOnlyTheUserColumnsItsCallersWrite(): void
    {
        $userId = '11111111-1111-4111-8111-0000000000a1';
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO app_user (id, tenant_id, email, password_hash, display_name)
            VALUES (?::uuid, ?::uuid, 'h0-probe@example.com', 'x', 'H0 Probe')
            SQL, [$userId, self::TENANT_A]);

        $columns = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT a.attname FROM pg_attribute a
            WHERE a.attrelid = 'app_user'::regclass AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY a.attnum
            SQL);

        $probe = function () use ($columns, $userId): array {
            $outcomes = [];
            foreach ($columns as $column) {
                $connection = $this->secondClient();
                $connection->beginTransaction();
                try {
                    $this->tenantContext()->establishOn($connection, self::TENANT_A);
                    $quoted = $connection->getDatabasePlatform()->quoteSingleIdentifier($column);
                    $rows = $connection->executeStatement(
                        sprintf('UPDATE app_user SET %1$s = %1$s WHERE id = ?::uuid', $quoted), [$userId]);
                    $outcomes[$column] = 'accepted:'.$rows;
                } catch (DriverException $e) {
                    $outcomes[$column] = '42501' === $e->getSQLState()
                        && str_contains($e->getMessage(), 'permission denied for table app_user')
                        ? 'refused'
                        : 'other: '.($e->getSQLState() ?? '?').' '.$e->getMessage();
                } finally {
                    $connection->rollBack();
                    $connection->close();
                }
            }

            return $outcomes;
        };

        $violations = static function (array $outcomes): array {
            $wrong = [];
            foreach ($outcomes as $column => $outcome) {
                $callerWrites = \in_array($column, self::APP_USER_COLUMNS_THE_CALLERS_WRITE, true);
                if ($callerWrites && 'accepted:1' !== $outcome) {
                    $wrong[] = sprintf('app_user.%s: a caller writes it, but erp_app got "%s"', $column, $outcome);
                } elseif (!$callerWrites && 'refused' !== $outcome) {
                    $wrong[] = sprintf('app_user.%s: no caller writes it, but erp_app got "%s"', $column, $outcome);
                }
            }

            return $wrong;
        };

        $outcomes = $probe();

        // Positive control: the connection, the tenant context and the fixture are real, so a
        // refusal below is the privilege, not RLS or an empty table.
        self::assertSame('accepted:1', $outcomes['last_login_at'] ?? null,
            'The positive control failed: erp_app could not update last_login_at on its own tenant\'s user, '
            .'so the refusals in this test prove nothing about the privilege.');

        // Anti-vacuity: the routes H0 closes are in the domain and are refused.
        foreach (['email', 'is_active', 'deleted_at', 'display_name'] as $closed) {
            self::assertSame('refused', $outcomes[$closed] ?? null,
                "H0 closes erp_app writing app_user.{$closed}, and the probe did not see it refused.");
        }
        foreach (self::APP_USER_COLUMNS_THE_CALLERS_WRITE as $written) {
            self::assertArrayHasKey($written, $outcomes, "A column the callers write, {$written}, is not in app_user.");
        }

        self::assertSame([], $violations($outcomes),
            "erp_app's UPDATE on app_user is not exactly the columns its callers write (H0). A 42501 from a new "
            ."writer is fixed by granting that column with its caller, never by restoring table-level UPDATE:\n  "
            .implode("\n  ", $violations($outcomes)));

        try {
            $this->owner()->executeStatement(sprintf('GRANT UPDATE (display_name) ON app_user TO %s', DatabaseRoles::APP));
            self::assertSame(
                ['app_user.display_name: no caller writes it, but erp_app got "accepted:1"'],
                $violations($probe()),
                'A committed UPDATE (display_name) grant to erp_app did not make this check fail, so it is not a check.');
        } finally {
            $this->owner()->executeStatement(sprintf('REVOKE UPDATE (display_name) ON app_user FROM %s', DatabaseRoles::APP));
        }

        self::assertSame([], $violations($probe()), 'The planted display_name grant survived its REVOKE.');
    }

    /**
     * The `invoice` columns `IssueInvoice` inserts, and no others (sales step 6, C3, `Version20260928000200`).
     * **Typed by hand from `InvoiceIssuer`'s one `INSERT INTO invoice (…)` statement, never read from
     * `SchemaManifest`** — `APP_USER_COLUMNS_THE_CALLERS_WRITE`'s reason: a copy derived from the manifest cannot
     * see the manifest and the migration widen together.
     */
    private const INVOICE_COLUMNS_THE_ISSUER_INSERTS = [
        'currency_code', 'customer_id', 'due_at', 'fulfilment_id', 'id', 'invoice_number', 'issued_by_user_id',
        'sales_order_id', 'tenant_id', 'total_amount',
    ];

    /**
     * **C3: `erp_app` can INSERT exactly the `invoice` columns `IssueInvoice` names — proved by running the statement
     * as `erp_app`** (design §19 C3; M-10). H0's method's shape, for INSERT.
     *
     * **Domain, server-derived:** every live column of `invoice` from `pg_attribute`. **One connection and one
     * transaction per column**, each running the zero-row probe `INSERT INTO invoice (<c>) SELECT <c> FROM invoice
     * WHERE false` in tenant A's context and rolling back. Zero rows, so no CHECK, NOT NULL, unique or RLS policy can
     * refuse it — only the privilege can. A refusal is SQLSTATE `42501` **and** `permission denied for table invoice`
     * (ADR-002 A4.3 R3); anything else is reported verbatim and fails.
     *
     * **Positive control:** `total_amount` is accepted. **Anti-vacuity:** `status`, `paid_at` and `issued_at` are in
     * the domain and refused. **Planted control, committed then undone in `finally`** (RED-3 F-3): `GRANT INSERT
     * (status)` — a column OUTSIDE the ten — must produce exactly one violation naming `invoice.status`.
     */
    #[Test]
    public function theIssuerCanInsertOnlyTheInvoiceColumnsItWrites(): void
    {
        $columns = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT a.attname FROM pg_attribute a
            WHERE a.attrelid = 'invoice'::regclass AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY a.attnum
            SQL);

        $probe = function () use ($columns): array {
            $outcomes = [];
            foreach ($columns as $column) {
                $connection = $this->secondClient();
                $connection->beginTransaction();
                try {
                    $this->tenantContext()->establishOn($connection, self::TENANT_A);
                    $quoted = $connection->getDatabasePlatform()->quoteSingleIdentifier($column);
                    $connection->executeStatement(sprintf('INSERT INTO invoice (%1$s) SELECT %1$s FROM invoice WHERE false', $quoted));
                    $outcomes[$column] = 'accepted';
                } catch (DriverException $e) {
                    $outcomes[$column] = '42501' === $e->getSQLState()
                        && str_contains($e->getMessage(), 'permission denied for table invoice')
                        ? 'refused'
                        : 'other: '.($e->getSQLState() ?? '?').' '.$e->getMessage();
                } finally {
                    $connection->rollBack();
                    $connection->close();
                }
            }

            return $outcomes;
        };

        $violations = static function (array $outcomes): array {
            $wrong = [];
            foreach ($outcomes as $column => $outcome) {
                $inserted = \in_array($column, self::INVOICE_COLUMNS_THE_ISSUER_INSERTS, true);
                if ($inserted && 'accepted' !== $outcome) {
                    $wrong[] = sprintf('invoice.%s: IssueInvoice inserts it, but erp_app got "%s"', $column, $outcome);
                } elseif (!$inserted && 'refused' !== $outcome) {
                    $wrong[] = sprintf('invoice.%s: IssueInvoice does not insert it, but erp_app got "%s"', $column, $outcome);
                }
            }

            return $wrong;
        };

        $outcomes = $probe();

        self::assertSame('accepted', $outcomes['total_amount'] ?? null,
            'The positive control failed: erp_app could not insert invoice.total_amount, so the refusals below prove nothing.');
        foreach (['status', 'paid_at', 'issued_at'] as $closed) {
            self::assertSame('refused', $outcomes[$closed] ?? null, "erp_app must not insert invoice.{$closed}, and the probe did not see it refused.");
        }
        foreach (self::INVOICE_COLUMNS_THE_ISSUER_INSERTS as $inserted) {
            self::assertArrayHasKey($inserted, $outcomes, "A column IssueInvoice inserts, {$inserted}, is not in invoice.");
        }

        self::assertSame([], $violations($outcomes),
            "erp_app's INSERT on invoice is not exactly the columns IssueInvoice names. A 42501 from the writer is fixed by "
            ."removing the column from its statement, never by widening the grant:\n  ".implode("\n  ", $violations($outcomes)));

        try {
            $this->owner()->executeStatement(sprintf('GRANT INSERT (status) ON invoice TO %s', DatabaseRoles::APP));
            self::assertSame(
                ['invoice.status: IssueInvoice does not insert it, but erp_app got "accepted"'],
                $violations($probe()),
                'A committed INSERT (status) grant to erp_app did not make this check fail, so it is not a check.');
        } finally {
            $this->owner()->executeStatement(sprintf('REVOKE INSERT (status) ON invoice FROM %s', DatabaseRoles::APP));
        }

        self::assertSame([], $violations($probe()), 'The planted status grant survived its REVOKE.');
    }

    /**
     * The `user_role` columns `AssignRole` inserts, and no others (roles slice 2, C3, `Version20260930000100`; H1). **Typed
     * by hand from `RoleAssignment`'s one `INSERT INTO user_role (…)` statement, never read from `SchemaManifest`** — the
     * reason above: a copy derived from the manifest cannot see the manifest and the migration widen together.
     */
    private const USER_ROLE_COLUMNS_THE_GRANT_INSERTS = ['id', 'role_id', 'tenant_id', 'user_id'];

    /**
     * **H1: `erp_app` can INSERT exactly the four `user_role` columns `AssignRole` names — proved by running the statement as
     * `erp_app`** (design §5.2's `theApplicationCanInsertOnlyTheGrantColumnsItWrites`; the invoice issuer's shape). Domain
     * server-derived (`pg_attribute`); one connection and one rolled-back transaction per column; zero-row probe.
     * **Positive control:** `role_id` accepted. **Anti-vacuity:** `inserted_by` and `created_at` are in the domain and
     * refused — `inserted_by` is the database's record of the login (`DEFAULT session_user`), so the application must not be
     * able to write it at all. **Planted control, committed then undone:** `GRANT INSERT (inserted_by)` must produce exactly
     * one violation. Mutation A3 (table-level `INSERT` in the migration) → red here and in the footprint test.
     */
    #[Test]
    public function theApplicationCanInsertOnlyTheGrantColumnsItWrites(): void
    {
        $columns = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT a.attname FROM pg_attribute a
            WHERE a.attrelid = 'user_role'::regclass AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY a.attnum
            SQL);

        $probe = function () use ($columns): array {
            $outcomes = [];
            foreach ($columns as $column) {
                $connection = $this->secondClient();
                $connection->beginTransaction();
                try {
                    $this->tenantContext()->establishOn($connection, self::TENANT_A);
                    $quoted = $connection->getDatabasePlatform()->quoteSingleIdentifier($column);
                    $connection->executeStatement(sprintf('INSERT INTO user_role (%1$s) SELECT %1$s FROM user_role WHERE false', $quoted));
                    $outcomes[$column] = 'accepted';
                } catch (DriverException $e) {
                    $outcomes[$column] = '42501' === $e->getSQLState()
                        && str_contains($e->getMessage(), 'permission denied for table user_role')
                        ? 'refused'
                        : 'other: '.($e->getSQLState() ?? '?').' '.$e->getMessage();
                } finally {
                    $connection->rollBack();
                    $connection->close();
                }
            }

            return $outcomes;
        };

        $violations = static function (array $outcomes): array {
            $wrong = [];
            foreach ($outcomes as $column => $outcome) {
                $inserted = \in_array($column, self::USER_ROLE_COLUMNS_THE_GRANT_INSERTS, true);
                if ($inserted && 'accepted' !== $outcome) {
                    $wrong[] = sprintf('user_role.%s: AssignRole inserts it, but erp_app got "%s"', $column, $outcome);
                } elseif (!$inserted && 'refused' !== $outcome) {
                    $wrong[] = sprintf('user_role.%s: AssignRole does not insert it, but erp_app got "%s"', $column, $outcome);
                }
            }

            return $wrong;
        };

        $outcomes = $probe();

        self::assertSame('accepted', $outcomes['role_id'] ?? null,
            'The positive control failed: erp_app could not insert user_role.role_id, so the refusals below prove nothing.');
        foreach (['inserted_by', 'created_at'] as $closed) {
            self::assertSame('refused', $outcomes[$closed] ?? null, "erp_app must not insert user_role.{$closed}, and the probe did not see it refused.");
        }
        foreach (self::USER_ROLE_COLUMNS_THE_GRANT_INSERTS as $inserted) {
            self::assertArrayHasKey($inserted, $outcomes, "A column AssignRole inserts, {$inserted}, is not in user_role.");
        }

        self::assertSame([], $violations($outcomes),
            "erp_app's INSERT on user_role is not exactly the columns AssignRole names (H1). A 42501 from the writer is fixed by "
            ."removing the column from its statement, never by widening the grant:\n  ".implode("\n  ", $violations($outcomes)));

        try {
            $this->owner()->executeStatement(sprintf('GRANT INSERT (inserted_by) ON user_role TO %s', DatabaseRoles::APP));
            self::assertSame(
                ['user_role.inserted_by: AssignRole does not insert it, but erp_app got "accepted"'],
                $violations($probe()),
                'A committed INSERT (inserted_by) grant to erp_app did not make this check fail, so it is not a check.');
        } finally {
            $this->owner()->executeStatement(sprintf('REVOKE INSERT (inserted_by) ON user_role FROM %s', DatabaseRoles::APP));
        }

        self::assertSame([], $violations($probe()), 'The planted inserted_by grant survived its REVOKE.');
    }

    /**
     * The columns the role-definition writers name, per privilege (roles slice 4: C1 `Version20261007000100`, C2
     * `Version20261007000300`, C3 `Version20261007000500`). **Typed by hand from `RoleCreation::INSERT_ROLE` / `INSERT_CODES`,
     * `RoleUpdate::UPDATE_ROLE` / `INSERT_CODES` and `RoleDeletion::DELETE_ROLE`, never read from `SchemaManifest`** (`USER_ROLE_COLUMNS_THE_GRANT_INSERTS`' reason). `DELETE` is
     * table-level (Postgres has no column DELETE): `RoleUpdate::DELETE_CODES` on `role_permission` only.
     *
     * @var array<string, array<string, list<string>>> privilege => table => columns
     */
    private const ROLE_DEFINITION_COLUMN_WRITES = [
        'INSERT' => [
            'role' => ['description', 'id', 'name', 'tenant_id'],
            'role_permission' => ['id', 'permission_code', 'role_id', 'tenant_id'],
        ],
        'UPDATE' => [
            // `deleted_at` joined at C3 (`Version20261007000500`): `RoleDeletion::DELETE_ROLE`'s soft delete.
            'role' => ['deleted_at', 'description', 'name', 'updated_at'],
            'role_permission' => [],
        ],
    ];

    /** @var list<string> the tables `RoleUpdate` deletes from (table-level) */
    private const ROLE_DEFINITION_DELETES = ['role_permission'];

    /**
     * **Roles slice 4: `erp_app` can write exactly the columns `CreateRole` (C1), `UpdateRole` (C2) and `DeleteRole` (C3) name on `role` and
     * `role_permission`, and DELETE from `role_permission` only — proved by running the statements as `erp_app`** (design
     * §8.1; E-15h: *"`is_system` set by any path other than provisioning"* is closed at the PRIVILEGE level).
     * `theApplicationCanInsertOnlyTheGrantColumnsItWrites`' shape: domain from `pg_attribute`, one connection and one
     * rolled-back transaction per (table, column, privilege), zero-row probes; one zero-row `DELETE` per table.
     * **Positive controls:** `role.name` INSERT and UPDATE accepted, `role_permission` DELETE accepted. **Anti-vacuity:**
     * `role.is_system` refused for INSERT and UPDATE; `role` DELETE refused. **Planted control, committed then undone:**
     * `GRANT UPDATE (is_system) ON role` must produce exactly one violation. Moved deliberately at C2 (UPDATE on three
     * `role` columns, DELETE on `role_permission`), and again at C3 (`deleted_at` joins the UPDATE list — `DeleteRole` is a
     * soft delete, so `role` DELETE stays refused). The anti-vacuity `is_system` assertion and the planted grant are unmoved.
     */
    #[Test]
    public function theApplicationCanWriteOnlyTheRoleColumnsTheRoleDefinitionWritersName(): void
    {
        $columns = [];
        foreach (array_keys(self::ROLE_DEFINITION_COLUMN_WRITES['INSERT']) as $table) {
            $columns[$table] = $this->owner()->fetchFirstColumn(
                'SELECT a.attname FROM pg_attribute a WHERE a.attrelid = ?::regclass AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum', [$table]);
        }

        $run = function (string $table, string $key, string $sql) use (&$outcomes): void {
            $connection = $this->secondClient();
            $connection->beginTransaction();
            try {
                $this->tenantContext()->establishOn($connection, self::TENANT_A);
                $connection->executeStatement($sql);
                $outcomes[$key] = 'accepted';
            } catch (DriverException $e) {
                $outcomes[$key] = '42501' === $e->getSQLState() && str_contains($e->getMessage(), 'permission denied for table '.$table)
                    ? 'refused'
                    : 'other: '.($e->getSQLState() ?? '?').' '.$e->getMessage();
            } finally {
                $connection->rollBack();
                $connection->close();
            }
        };

        $probe = function () use ($columns, $run, &$outcomes): array {
            $outcomes = [];
            foreach ($columns as $table => $tableColumns) {
                foreach ($tableColumns as $column) {
                    foreach (['INSERT' => 'INSERT INTO %2$s (%1$s) SELECT %1$s FROM %2$s WHERE false', 'UPDATE' => 'UPDATE %2$s SET %1$s = %1$s WHERE false'] as $privilege => $sql) {
                        $run($table, $table.'.'.$column.' '.$privilege, sprintf($sql, '"'.$column.'"', $table));
                    }
                }
                $run($table, $table.'.* DELETE', sprintf('DELETE FROM %s WHERE false', $table));
            }

            return $outcomes;
        };

        $violations = static function (array $outcomes): array {
            $wrong = [];
            foreach ($outcomes as $key => $outcome) {
                [$tableColumn, $privilege] = explode(' ', $key);
                [$table, $column] = explode('.', $tableColumn);
                $granted = 'DELETE' === $privilege
                    ? \in_array($table, self::ROLE_DEFINITION_DELETES, true)
                    : \in_array($column, self::ROLE_DEFINITION_COLUMN_WRITES[$privilege][$table], true);
                if ($granted && 'accepted' !== $outcome) {
                    $wrong[] = sprintf('%s: a role-definition writer issues it, but erp_app got "%s"', $key, $outcome);
                } elseif (!$granted && 'refused' !== $outcome) {
                    $wrong[] = sprintf('%s: no role-definition writer issues it, but erp_app got "%s"', $key, $outcome);
                }
            }

            return $wrong;
        };

        $outcomes = $probe();
        self::assertSame(['accepted', 'accepted', 'accepted'], [$outcomes['role.name INSERT'] ?? null, $outcomes['role.name UPDATE'] ?? null, $outcomes['role_permission.* DELETE'] ?? null],
            'The positive controls failed: erp_app could not insert or update role.name, or delete from role_permission.');
        self::assertSame(['refused', 'refused', 'refused'], [$outcomes['role.is_system INSERT'] ?? null, $outcomes['role.is_system UPDATE'] ?? null, $outcomes['role.* DELETE'] ?? null],
            'is_system must be unwritable by erp_app (E-15h) and role undeletable, and the probe did not see them refused.');
        self::assertSame([], $violations($outcomes),
            "erp_app's writes on role / role_permission are not exactly the role-definition writers':\n  ".implode("\n  ", $violations($outcomes)));

        try {
            $this->owner()->executeStatement(sprintf('GRANT UPDATE (is_system) ON role TO %s', DatabaseRoles::APP));
            self::assertSame(['role.is_system UPDATE: no role-definition writer issues it, but erp_app got "accepted"'], $violations($probe()),
                'A committed UPDATE (is_system) grant to erp_app did not make this check fail, so it is not a check.');
        } finally {
            $this->owner()->executeStatement(sprintf('REVOKE UPDATE (is_system) ON role FROM %s', DatabaseRoles::APP));
        }
        self::assertSame([], $violations($probe()), 'The planted is_system grant survived its REVOKE.');
    }

    /**
     * The columns the customer writers name, per privilege (customers slice 1: C2 `Version20261009000300`, C3
     * `Version20261009000500`). **Typed by hand from `CustomerWriter`'s and `CustomerAddressWriter`'s INSERT and UPDATE,
     * never read from `SchemaManifest`** (`USER_ROLE_COLUMNS_THE_GRANT_INSERTS`'
     * reason: a joint widening of the manifest and a migration must still fail here). No DELETE on either table.
     *
     * @var array<string, array<string, list<string>>> privilege => table => columns
     */
    private const CUSTOMER_COLUMN_WRITES = [
        'INSERT' => [
            'customer' => ['code', 'country_code', 'currency_code', 'id', 'name', 'payment_terms_days', 'tenant_id'],
            'customer_address' => ['address_line', 'city', 'country_code', 'customer_id', 'id', 'idempotency_key', 'kind', 'postal_code',
                'request_digest', 'tenant_id'],
        ],
        'UPDATE' => [
            'customer' => ['code', 'country_code', 'currency_code', 'name', 'payment_terms_days', 'updated_at'],
            'customer_address' => ['address_line', 'city', 'country_code', 'kind', 'postal_code', 'updated_at'],
        ],
    ];

    /**
     * **Customers slice 1: `erp_app` can write exactly the columns `CreateCustomer` and `UpdateCustomer` name on `customer`,
     * and delete nothing — proved by running the statements as `erp_app`** (design §4.4; ADR-002 A4.4's *"exactly"*).
     * `theApplicationCanWriteOnlyTheRoleColumnsTheRoleDefinitionWritersName`' shape: domain from `pg_attribute`, one
     * connection and one rolled-back transaction per (table, column, privilege), zero-row probes. **Positive controls:**
     * `customer.name` INSERT and UPDATE accepted. **Anti-vacuity:** `customer.deleted_at` UPDATE (archiving by SQL),
     * `customer.credit_limit` UPDATE (owner Q-C2) and `customer` DELETE refused. **C3 (`customer_address`):**
     * `address_line` INSERT and UPDATE accepted; `region` INSERT (not accepted, defaulted), `idempotency_key` UPDATE
     * (a stored key re-pointed by SQL) and `customer_address` DELETE refused. **Planted control, committed then undone:**
     * `GRANT UPDATE (deleted_at) ON customer` must produce exactly one violation (design mutation C2-6's twin). The UPDATE
     * columns also make `UpdateCustomer`'s `FOR NO KEY UPDATE` legal (A4.3 constraint 1).
     */
    #[Test]
    public function theApplicationCanWriteOnlyTheCustomerColumnsTheCustomerWritersName(): void
    {
        $columns = [];
        foreach (array_keys(self::CUSTOMER_COLUMN_WRITES['INSERT']) as $table) {
            $columns[$table] = $this->owner()->fetchFirstColumn(
                'SELECT a.attname FROM pg_attribute a WHERE a.attrelid = ?::regclass AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum', [$table]);
        }

        $run = function (string $table, string $key, string $sql) use (&$outcomes): void {
            $connection = $this->secondClient();
            $connection->beginTransaction();
            try {
                $this->tenantContext()->establishOn($connection, self::TENANT_A);
                $connection->executeStatement($sql);
                $outcomes[$key] = 'accepted';
            } catch (DriverException $e) {
                $outcomes[$key] = '42501' === $e->getSQLState() && str_contains($e->getMessage(), 'permission denied for table '.$table)
                    ? 'refused'
                    : 'other: '.($e->getSQLState() ?? '?').' '.$e->getMessage();
            } finally {
                $connection->rollBack();
                $connection->close();
            }
        };

        $probe = function () use ($columns, $run, &$outcomes): array {
            $outcomes = [];
            foreach ($columns as $table => $tableColumns) {
                foreach ($tableColumns as $column) {
                    foreach (['INSERT' => 'INSERT INTO %2$s (%1$s) SELECT %1$s FROM %2$s WHERE false', 'UPDATE' => 'UPDATE %2$s SET %1$s = %1$s WHERE false'] as $privilege => $sql) {
                        $run($table, $table.'.'.$column.' '.$privilege, sprintf($sql, '"'.$column.'"', $table));
                    }
                }
                $run($table, $table.'.* DELETE', sprintf('DELETE FROM %s WHERE false', $table));
            }

            return $outcomes;
        };

        $violations = static function (array $outcomes): array {
            $wrong = [];
            foreach ($outcomes as $key => $outcome) {
                [$tableColumn, $privilege] = explode(' ', $key);
                [$table, $column] = explode('.', $tableColumn);
                $granted = 'DELETE' !== $privilege && \in_array($column, self::CUSTOMER_COLUMN_WRITES[$privilege][$table], true);
                if ($granted && 'accepted' !== $outcome) {
                    $wrong[] = sprintf('%s: a customer writer issues it, but erp_app got "%s"', $key, $outcome);
                } elseif (!$granted && 'refused' !== $outcome) {
                    $wrong[] = sprintf('%s: no customer writer issues it, but erp_app got "%s"', $key, $outcome);
                }
            }

            return $wrong;
        };

        $outcomes = $probe();
        self::assertSame(['accepted', 'accepted'], [$outcomes['customer.name INSERT'] ?? null, $outcomes['customer.name UPDATE'] ?? null],
            'The positive controls failed: erp_app could not insert or update customer.name.');
        self::assertSame(['refused', 'refused', 'refused'], [$outcomes['customer.deleted_at UPDATE'] ?? null, $outcomes['customer.credit_limit UPDATE'] ?? null, $outcomes['customer.* DELETE'] ?? null],
            'erp_app must not archive a customer, set its credit limit or delete it, and the probe did not see them refused.');
        self::assertSame(['accepted', 'accepted'], [$outcomes['customer_address.address_line INSERT'] ?? null, $outcomes['customer_address.address_line UPDATE'] ?? null],
            'The positive controls failed: erp_app could not insert or update customer_address.address_line.');
        self::assertSame(['refused', 'refused', 'refused'], [$outcomes['customer_address.region INSERT'] ?? null, $outcomes['customer_address.idempotency_key UPDATE'] ?? null, $outcomes['customer_address.* DELETE'] ?? null],
            'erp_app must not write an address region, re-point a stored retry key or delete an address, and the probe did not see them refused.');
        self::assertSame([], $violations($outcomes),
            "erp_app's writes on the customer tables are not exactly the customer writers':\n  ".implode("\n  ", $violations($outcomes)));

        try {
            $this->owner()->executeStatement(sprintf('GRANT UPDATE (deleted_at) ON customer TO %s', DatabaseRoles::APP));
            self::assertSame(['customer.deleted_at UPDATE: no customer writer issues it, but erp_app got "accepted"'], $violations($probe()),
                'A committed UPDATE (deleted_at) grant to erp_app did not make this check fail, so it is not a check.');
        } finally {
            $this->owner()->executeStatement(sprintf('REVOKE UPDATE (deleted_at) ON customer FROM %s', DatabaseRoles::APP));
        }
        self::assertSame([], $violations($probe()), 'The planted deleted_at grant survived its REVOKE.');
    }

    /**
     * The columns `SupplierWriter` names, per privilege (suppliers slice 1 C2, `Version20261012000200`; `deleted_at` C5 C2a,
     * `Version20261013000500`, `restore()`). **Typed by hand from its INSERT and UPDATEs, never read from `SchemaManifest`**
     * (a joint widening of the manifest and a migration must still fail here). No DELETE.
     *
     * @var array<string, list<string>> privilege => columns
     */
    private const SUPPLIER_COLUMN_WRITES = [
        'INSERT' => ['code', 'country_code', 'currency_code', 'id', 'name', 'payment_terms_days', 'tenant_id'],
        'UPDATE' => ['code', 'country_code', 'currency_code', 'deleted_at', 'name', 'payment_terms_days', 'updated_at'],
    ];

    /**
     * **Suppliers slice 1 C2: `erp_app` can write exactly the columns `CreateSupplier`, `UpdateSupplier`, (C5 C2a)
     * `RestoreSupplier` and (C5 C2b) `DeleteSupplier` name on `supplier`,
     * and delete nothing — proved by running the statements as `erp_app`** (design §4.5; ADR-002 A4.4's *"exactly"*). The
     * customer method's shape. **Positive controls:** `supplier.name` INSERT and UPDATE, and (C5 C2a) `supplier.deleted_at`
     * UPDATE, accepted. **Anti-vacuity:** `supplier.deleted_at` INSERT, `supplier.email` UPDATE and `supplier.is_active` UPDATE
     * (owner Q-S6, G-36) and `supplier` DELETE refused. **Planted control, committed then undone:** `GRANT UPDATE (is_active)
     * ON supplier` (moved off `deleted_at` at C5 C2a, which is now written) must produce exactly one violation. The UPDATE columns also make
     * `UpdateSupplier`'s `FOR NO KEY UPDATE` legal (A4.3 constraint 1).
     */
    #[Test]
    public function theApplicationCanWriteOnlyTheSupplierColumnsTheSupplierWriterNames(): void
    {
        $columns = $this->owner()->fetchFirstColumn(
            "SELECT a.attname FROM pg_attribute a WHERE a.attrelid = 'supplier'::regclass AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum");
        self::assertContains('country_code', $columns, 'The column domain was not read from the server.');

        $run = function (string $key, string $sql) use (&$outcomes): void {
            $connection = $this->secondClient();
            $connection->beginTransaction();
            try {
                $this->tenantContext()->establishOn($connection, self::TENANT_A);
                $connection->executeStatement($sql);
                $outcomes[$key] = 'accepted';
            } catch (DriverException $e) {
                $outcomes[$key] = '42501' === $e->getSQLState() && str_contains($e->getMessage(), 'permission denied for table supplier')
                    ? 'refused'
                    : 'other: '.($e->getSQLState() ?? '?').' '.$e->getMessage();
            } finally {
                $connection->rollBack();
                $connection->close();
            }
        };

        $probe = function () use ($columns, $run, &$outcomes): array {
            $outcomes = [];
            foreach ($columns as $column) {
                foreach (['INSERT' => 'INSERT INTO supplier (%1$s) SELECT %1$s FROM supplier WHERE false', 'UPDATE' => 'UPDATE supplier SET %1$s = %1$s WHERE false'] as $privilege => $sql) {
                    $run($column.' '.$privilege, sprintf($sql, '"'.$column.'"'));
                }
            }
            $run('* DELETE', 'DELETE FROM supplier WHERE false');

            return $outcomes;
        };

        $violations = static function (array $outcomes): array {
            $wrong = [];
            foreach ($outcomes as $key => $outcome) {
                [$column, $privilege] = explode(' ', $key);
                $granted = 'DELETE' !== $privilege && \in_array($column, self::SUPPLIER_COLUMN_WRITES[$privilege], true);
                if ($granted && 'accepted' !== $outcome) {
                    $wrong[] = sprintf('supplier.%s: SupplierWriter issues it, but erp_app got "%s"', $key, $outcome);
                } elseif (!$granted && 'refused' !== $outcome) {
                    $wrong[] = sprintf('supplier.%s: SupplierWriter does not issue it, but erp_app got "%s"', $key, $outcome);
                }
            }

            return $wrong;
        };

        $outcomes = $probe();
        self::assertSame(['accepted', 'accepted', 'accepted'], [$outcomes['name INSERT'] ?? null, $outcomes['name UPDATE'] ?? null, $outcomes['deleted_at UPDATE'] ?? null],
            'The positive controls failed: erp_app could not insert or update supplier.name, or restore (deleted_at) a supplier.');
        self::assertSame(['refused', 'refused', 'refused', 'refused'],
            [$outcomes['deleted_at INSERT'] ?? null, $outcomes['email UPDATE'] ?? null, $outcomes['is_active UPDATE'] ?? null, $outcomes['* DELETE'] ?? null],
            'erp_app must not insert an archived supplier, set its email or is_active, or delete it, and the probe did not see them refused.');
        self::assertSame([], $violations($outcomes),
            "erp_app's writes on supplier are not exactly SupplierWriter's:\n  ".implode("\n  ", $violations($outcomes)));

        try {
            $this->owner()->executeStatement(sprintf('GRANT UPDATE (is_active) ON supplier TO %s', DatabaseRoles::APP));
            self::assertSame(['supplier.is_active UPDATE: SupplierWriter does not issue it, but erp_app got "accepted"'], $violations($probe()),
                'A committed UPDATE (is_active) grant to erp_app did not make this check fail, so it is not a check.');
        } finally {
            $this->owner()->executeStatement(sprintf('REVOKE UPDATE (is_active) ON supplier FROM %s', DatabaseRoles::APP));
        }
        self::assertSame([], $violations($probe()), 'The planted supplier is_active grant survived its REVOKE.');
    }

    /**
     * **C5 C1 (owner Q-S2): the row locks Purchasing and the party writers take on `supplier` are legal for `erp_app`.**
     * `PurchaseOrderWriter` and (C5 C2b) the catalogue writers read the supplier `FOR KEY SHARE`; `DeleteSupplier` (C5 C2b)
     * takes `FOR UPDATE` (`SupplierWriter::DELETE_LOCK`). Postgres
     * allows either only to a role holding UPDATE on at least one column (ADR-002 A4.3 constraint 1), so narrowing
     * `supplier`'s column UPDATE grant to none would 42501 every purchase-order create — this is the probe that says so,
     * run as `erp_app` under the tenant. **Negative control, same test:** the same two locks on `currency` (reference data,
     * SELECT only) are refused, so a probe that cannot see a refusal fails here.
     *
     * **Mutation:** revoke every `supplier` column UPDATE from `erp_app` → red here (and every PO create 500s).
     */
    #[Test]
    public function theApplicationMayTakeThePartyRowLocksOnSupplier(): void
    {
        $outcome = function (string $sql): string {
            $connection = $this->secondClient();
            $connection->beginTransaction();
            try {
                $this->tenantContext()->establishOn($connection, self::TENANT_A);
                $connection->executeQuery($sql)->fetchAllAssociative();

                return 'accepted';
            } catch (DriverException $e) {
                return '42501' === $e->getSQLState() ? 'refused' : 'other: '.($e->getSQLState() ?? '?').' '.$e->getMessage();
            } finally {
                $connection->rollBack();
                $connection->close();
            }
        };

        self::assertSame(
            ['FOR KEY SHARE' => 'accepted', 'FOR UPDATE' => 'accepted'],
            ['FOR KEY SHARE' => $outcome('SELECT id FROM supplier WHERE false FOR KEY SHARE'), 'FOR UPDATE' => $outcome('SELECT id FROM supplier WHERE false FOR UPDATE')],
            'erp_app cannot take the supplier row locks PurchaseOrderWriter and the supplier writers rely on.',
        );
        self::assertSame(
            ['FOR KEY SHARE' => 'refused', 'FOR UPDATE' => 'refused'],
            ['FOR KEY SHARE' => $outcome('SELECT code FROM currency WHERE false FOR KEY SHARE'), 'FOR UPDATE' => $outcome('SELECT code FROM currency WHERE false FOR UPDATE')],
            'The negative control failed: a row lock on a table erp_app may only read was not refused, so this probe cannot see a refusal.',
        );
    }

    /**
     * **C5 C3 (owner Q-2): the row locks Sales and the customer writers take on `customer` are legal for `erp_app`.**
     * The twin of `theApplicationMayTakeThePartyRowLocksOnSupplier`. `SalesOrderWriter` reads the customer
     * `FOR KEY SHARE` (`PARENT_LIVENESS_READS`); `DeleteCustomer` (C5 C4b) will take `FOR UPDATE`. Postgres allows
     * either only to a role holding UPDATE on at least one column (ADR-002 A4.3 constraint 1), so narrowing `customer`'s
     * column UPDATE grant to none would 42501 every sales-order create. **Negative control, same test:** the same two
     * locks on `currency` (SELECT only) are refused.
     *
     * **Mutation:** revoke every `customer` column UPDATE from `erp_app` → red here (and every SO create 500s).
     */
    #[Test]
    public function theApplicationMayTakeThePartyRowLocksOnCustomer(): void
    {
        $outcome = function (string $sql): string {
            $connection = $this->secondClient();
            $connection->beginTransaction();
            try {
                $this->tenantContext()->establishOn($connection, self::TENANT_A);
                $connection->executeQuery($sql)->fetchAllAssociative();

                return 'accepted';
            } catch (DriverException $e) {
                return '42501' === $e->getSQLState() ? 'refused' : 'other: '.($e->getSQLState() ?? '?').' '.$e->getMessage();
            } finally {
                $connection->rollBack();
                $connection->close();
            }
        };

        self::assertSame(
            ['FOR KEY SHARE' => 'accepted', 'FOR UPDATE' => 'accepted'],
            ['FOR KEY SHARE' => $outcome('SELECT id FROM customer WHERE false FOR KEY SHARE'), 'FOR UPDATE' => $outcome('SELECT id FROM customer WHERE false FOR UPDATE')],
            'erp_app cannot take the customer row locks SalesOrderWriter and the customer writers rely on.',
        );
        self::assertSame(
            ['FOR KEY SHARE' => 'refused', 'FOR UPDATE' => 'refused'],
            ['FOR KEY SHARE' => $outcome('SELECT code FROM currency WHERE false FOR KEY SHARE'), 'FOR UPDATE' => $outcome('SELECT code FROM currency WHERE false FOR UPDATE')],
            'The negative control failed: a row lock on a table erp_app may only read was not refused, so this probe cannot see a refusal.',
        );
    }

    /**
     * The columns `WarehouseWriter` names, per privilege (warehouses slice 1 C2, `Version20261013000100`). **Typed by hand from
     * its INSERT and UPDATE, never read from `SchemaManifest`** (a joint widening of the manifest and a migration must still
     * fail here). No DELETE.
     *
     * @var array<string, list<string>> privilege => columns
     */
    private const WAREHOUSE_COLUMN_WRITES = [
        'INSERT' => ['code', 'country_code', 'id', 'name', 'tenant_id'],
        'UPDATE' => ['code', 'country_code', 'name', 'updated_at'],
    ];

    /**
     * **Warehouses slice 1 C2: `erp_app` can write exactly the columns `CreateWarehouse` and `UpdateWarehouse` name on
     * `warehouse`, and delete nothing — proved by running the statements as `erp_app`** (design §3.3; ADR-002 A4.4's
     * *"exactly"*). The supplier method's shape. **Positive controls:** `warehouse.name` INSERT and UPDATE accepted.
     * **Anti-vacuity:** `warehouse.deleted_at` UPDATE (archiving by SQL; `DeleteWarehouse` is not built), `warehouse.is_active`
     * and `warehouse.address_line` UPDATE (Q-W4, G-36) and `warehouse` DELETE refused. **Planted control, committed then
     * undone:** `GRANT UPDATE (deleted_at) ON warehouse` must produce exactly one violation. The UPDATE columns also make
     * `UpdateWarehouse`'s `FOR NO KEY UPDATE` and C3's parent `FOR SHARE` legal (A4.3 constraint 1).
     */
    #[Test]
    public function theApplicationCanWriteOnlyTheWarehouseColumnsTheWarehouseWriterNames(): void
    {
        $columns = $this->owner()->fetchFirstColumn(
            "SELECT a.attname FROM pg_attribute a WHERE a.attrelid = 'warehouse'::regclass AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum");
        self::assertContains('address_line', $columns, 'The column domain was not read from the server.');

        $run = function (string $key, string $sql) use (&$outcomes): void {
            $connection = $this->secondClient();
            $connection->beginTransaction();
            try {
                $this->tenantContext()->establishOn($connection, self::TENANT_A);
                $connection->executeStatement($sql);
                $outcomes[$key] = 'accepted';
            } catch (DriverException $e) {
                $outcomes[$key] = '42501' === $e->getSQLState() && str_contains($e->getMessage(), 'permission denied for table warehouse')
                    ? 'refused'
                    : 'other: '.($e->getSQLState() ?? '?').' '.$e->getMessage();
            } finally {
                $connection->rollBack();
                $connection->close();
            }
        };

        $probe = function () use ($columns, $run, &$outcomes): array {
            $outcomes = [];
            foreach ($columns as $column) {
                foreach (['INSERT' => 'INSERT INTO warehouse (%1$s) SELECT %1$s FROM warehouse WHERE false', 'UPDATE' => 'UPDATE warehouse SET %1$s = %1$s WHERE false'] as $privilege => $sql) {
                    $run($column.' '.$privilege, sprintf($sql, '"'.$column.'"'));
                }
            }
            $run('* DELETE', 'DELETE FROM warehouse WHERE false');

            return $outcomes;
        };

        $violations = static function (array $outcomes): array {
            $wrong = [];
            foreach ($outcomes as $key => $outcome) {
                [$column, $privilege] = explode(' ', $key);
                $granted = 'DELETE' !== $privilege && \in_array($column, self::WAREHOUSE_COLUMN_WRITES[$privilege], true);
                if ($granted && 'accepted' !== $outcome) {
                    $wrong[] = sprintf('warehouse.%s: WarehouseWriter issues it, but erp_app got "%s"', $key, $outcome);
                } elseif (!$granted && 'refused' !== $outcome) {
                    $wrong[] = sprintf('warehouse.%s: WarehouseWriter does not issue it, but erp_app got "%s"', $key, $outcome);
                }
            }

            return $wrong;
        };

        $outcomes = $probe();
        self::assertSame(['accepted', 'accepted'], [$outcomes['name INSERT'] ?? null, $outcomes['name UPDATE'] ?? null],
            'The positive controls failed: erp_app could not insert or update warehouse.name.');
        self::assertSame(['refused', 'refused', 'refused', 'refused'],
            [$outcomes['deleted_at UPDATE'] ?? null, $outcomes['address_line UPDATE'] ?? null, $outcomes['is_active UPDATE'] ?? null, $outcomes['* DELETE'] ?? null],
            'erp_app must not archive a warehouse, set its address or is_active, or delete it, and the probe did not see them refused.');
        self::assertSame([], $violations($outcomes),
            "erp_app's writes on warehouse are not exactly WarehouseWriter's:\n  ".implode("\n  ", $violations($outcomes)));

        try {
            $this->owner()->executeStatement(sprintf('GRANT UPDATE (deleted_at) ON warehouse TO %s', DatabaseRoles::APP));
            self::assertSame(['warehouse.deleted_at UPDATE: WarehouseWriter does not issue it, but erp_app got "accepted"'], $violations($probe()),
                'A committed UPDATE (deleted_at) grant to erp_app did not make this check fail, so it is not a check.');
        } finally {
            $this->owner()->executeStatement(sprintf('REVOKE UPDATE (deleted_at) ON warehouse FROM %s', DatabaseRoles::APP));
        }
        self::assertSame([], $violations($probe()), 'The planted warehouse deleted_at grant survived its REVOKE.');
    }

    /**
     * The columns `StorageLocationWriter` names, per privilege (warehouses slice 1 C3, `Version20261013000300`). **Typed by hand from
     * its INSERT and UPDATE, never read from `SchemaManifest`** (a joint widening of the manifest and a migration must still
     * fail here). No DELETE.
     *
     * @var array<string, list<string>> privilege => columns
     */
    private const STORAGE_LOCATION_COLUMN_WRITES = [
        'INSERT' => ['code', 'id', 'kind', 'tenant_id', 'warehouse_id'],
        'UPDATE' => ['code', 'kind', 'updated_at'],
    ];

    /**
     * **Warehouses slice 1 C3: `erp_app` can write exactly the columns `CreateStorageLocation` and `UpdateStorageLocation`
     * name on `storage_location`, and delete nothing — proved by running the statements as `erp_app`** (design §4.6;
     * ADR-002 A4.4's *"exactly"*). The warehouse method's shape. **Positive controls:** `code` INSERT and UPDATE accepted,
     * and the row locks the grant makes legal (A4.3 constraint 1; 42501 before it, measured): `FOR KEY SHARE`
     * (`StockDepleter`) and `FOR UPDATE` (`UpdateStorageLocation`). **Anti-vacuity:** `warehouse_id` UPDATE (moving a
     * location's stock between warehouses with no movement), `deleted_at` UPDATE (archiving by SQL; `DeleteStorageLocation`
     * is not built) and DELETE refused. **Planted control, committed then undone:** `GRANT UPDATE (deleted_at) ON
     * storage_location` must produce exactly one violation.
     */
    #[Test]
    public function theApplicationCanWriteOnlyTheStorageLocationColumnsTheStorageLocationWriterNames(): void
    {
        $columns = $this->owner()->fetchFirstColumn(
            "SELECT a.attname FROM pg_attribute a WHERE a.attrelid = 'storage_location'::regclass AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum");
        self::assertContains('warehouse_id', $columns, 'The column domain was not read from the server.');

        $run = function (string $key, string $sql) use (&$outcomes): void {
            $connection = $this->secondClient();
            $connection->beginTransaction();
            try {
                $this->tenantContext()->establishOn($connection, self::TENANT_A);
                $connection->executeStatement($sql);
                $outcomes[$key] = 'accepted';
            } catch (DriverException $e) {
                $outcomes[$key] = '42501' === $e->getSQLState() && str_contains($e->getMessage(), 'permission denied for table storage_location')
                    ? 'refused'
                    : 'other: '.($e->getSQLState() ?? '?').' '.$e->getMessage();
            } finally {
                $connection->rollBack();
                $connection->close();
            }
        };

        $probe = function () use ($columns, $run, &$outcomes): array {
            $outcomes = [];
            foreach ($columns as $column) {
                foreach (['INSERT' => 'INSERT INTO storage_location (%1$s) SELECT %1$s FROM storage_location WHERE false', 'UPDATE' => 'UPDATE storage_location SET %1$s = %1$s WHERE false'] as $privilege => $sql) {
                    $run($column.' '.$privilege, sprintf($sql, '"'.$column.'"'));
                }
            }
            $run('* DELETE', 'DELETE FROM storage_location WHERE false');

            return $outcomes;
        };

        $violations = static function (array $outcomes): array {
            $wrong = [];
            foreach ($outcomes as $key => $outcome) {
                [$column, $privilege] = explode(' ', $key);
                $granted = 'DELETE' !== $privilege && \in_array($column, self::STORAGE_LOCATION_COLUMN_WRITES[$privilege], true);
                if ($granted && 'accepted' !== $outcome) {
                    $wrong[] = sprintf('storage_location.%s: StorageLocationWriter issues it, but erp_app got "%s"', $key, $outcome);
                } elseif (!$granted && 'refused' !== $outcome) {
                    $wrong[] = sprintf('storage_location.%s: StorageLocationWriter does not issue it, but erp_app got "%s"', $key, $outcome);
                }
            }

            return $wrong;
        };

        $outcomes = $probe();
        self::assertSame(['accepted', 'accepted'], [$outcomes['code INSERT'] ?? null, $outcomes['code UPDATE'] ?? null],
            'The positive controls failed: erp_app could not insert or update storage_location.code.');
        self::assertSame(['refused', 'refused', 'refused'],
            [$outcomes['warehouse_id UPDATE'] ?? null, $outcomes['deleted_at UPDATE'] ?? null, $outcomes['* DELETE'] ?? null],
            'erp_app must not move a location to another warehouse, archive it, or delete it, and the probe did not see them refused.');
        self::assertSame([], $violations($outcomes),
            "erp_app's writes on storage_location are not exactly StorageLocationWriter's:\n  ".implode("\n  ", $violations($outcomes)));
        $outcomes = [];
        $run('lock KEY SHARE', 'SELECT 1 FROM storage_location WHERE false FOR KEY SHARE');
        $run('lock UPDATE', 'SELECT 1 FROM storage_location WHERE false FOR UPDATE');
        self::assertSame(['lock KEY SHARE' => 'accepted', 'lock UPDATE' => 'accepted'], $outcomes,
            'erp_app cannot take the row locks StockDepleter and UpdateStorageLocation take: the column UPDATE grant is what makes them legal.');

        try {
            $this->owner()->executeStatement(sprintf('GRANT UPDATE (deleted_at) ON storage_location TO %s', DatabaseRoles::APP));
            self::assertSame(['storage_location.deleted_at UPDATE: StorageLocationWriter does not issue it, but erp_app got "accepted"'], $violations($probe()),
                'A committed UPDATE (deleted_at) grant to erp_app did not make this check fail, so it is not a check.');
        } finally {
            $this->owner()->executeStatement(sprintf('REVOKE UPDATE (deleted_at) ON storage_location FROM %s', DatabaseRoles::APP));
        }
        self::assertSame([], $violations($probe()), 'The planted storage_location deleted_at grant survived its REVOKE.');
    }

    /**
     * The columns `SupplierProductWriter` names, per privilege (suppliers slice 1 C3, `Version20261012000500`). **Typed by
     * hand from its INSERT and UPDATE, never read from `SchemaManifest`** (a joint widening of the manifest and a migration
     * must still fail here). DELETE is table-level and separate: {@see self::SUPPLIER_PRODUCT_DELETES}.
     *
     * @var array<string, list<string>> privilege => columns
     */
    private const SUPPLIER_PRODUCT_COLUMN_WRITES = [
        'INSERT' => ['currency_code', 'id', 'inventory_item_id', 'lead_time_days', 'min_order_quantity', 'supplier_id', 'supplier_sku', 'tenant_id', 'unit_cost'],
        'UPDATE' => ['lead_time_days', 'min_order_quantity', 'supplier_sku', 'unit_cost', 'updated_at'],
    ];

    /**
     * Whether `SupplierProductWriter` issues a `DELETE` on `supplier_product` — **yes since C4** (`DeleteSupplierProduct`,
     * `Version20261012000600`, `SupplierProductWriter::DELETE`). Typed by hand for the same reason as the column list.
     */
    private const SUPPLIER_PRODUCT_DELETES = true;

    /**
     * **Suppliers slice 1 C3: `erp_app` can write exactly the columns `CreateSupplierProduct` and `UpdateSupplierProduct`
     * name on `supplier_product`, and — since C4 — delete from it (`DeleteSupplierProduct`) — proved by running the statements as `erp_app`** (design §5.4; ADR-002
     * A4.4's *"exactly"*). The supplier method's shape. **Positive controls:** `unit_cost` INSERT and UPDATE accepted, and
     * from C4 the zero-row `DELETE` accepted (design §6's per-feature probe, FINAL F-5 / RED L-4 — the DELETE domain is this
     * method's, not a shared list). **Anti-vacuity:** `currency_code` UPDATE (a price relabelled without being converted,
     * owner Q-S4), `supplier_id` and `inventory_item_id` UPDATE (an entry re-pointed) refused. **Planted controls, committed
     * then undone:** `GRANT UPDATE (currency_code) ON supplier_product` must produce exactly one violation; and
     * `REVOKE DELETE` must produce exactly one (the C4 grant is seen in both directions).
     */
    #[Test]
    public function theApplicationCanWriteOnlyTheCatalogueColumnsTheCatalogueWriterNames(): void
    {
        $columns = $this->owner()->fetchFirstColumn(
            "SELECT a.attname FROM pg_attribute a WHERE a.attrelid = 'supplier_product'::regclass AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum");
        self::assertContains('min_order_quantity', $columns, 'The column domain was not read from the server.');

        $run = function (string $key, string $sql) use (&$outcomes): void {
            $connection = $this->secondClient();
            $connection->beginTransaction();
            try {
                $this->tenantContext()->establishOn($connection, self::TENANT_A);
                $connection->executeStatement($sql);
                $outcomes[$key] = 'accepted';
            } catch (DriverException $e) {
                $outcomes[$key] = '42501' === $e->getSQLState() && str_contains($e->getMessage(), 'permission denied for table supplier_product')
                    ? 'refused'
                    : 'other: '.($e->getSQLState() ?? '?').' '.$e->getMessage();
            } finally {
                $connection->rollBack();
                $connection->close();
            }
        };

        $probe = function () use ($columns, $run, &$outcomes): array {
            $outcomes = [];
            foreach ($columns as $column) {
                foreach (['INSERT' => 'INSERT INTO supplier_product (%1$s) SELECT %1$s FROM supplier_product WHERE false', 'UPDATE' => 'UPDATE supplier_product SET %1$s = %1$s WHERE false'] as $privilege => $sql) {
                    $run($column.' '.$privilege, sprintf($sql, '"'.$column.'"'));
                }
            }
            $run('* DELETE', 'DELETE FROM supplier_product WHERE false');

            return $outcomes;
        };

        $violations = static function (array $outcomes): array {
            $wrong = [];
            foreach ($outcomes as $key => $outcome) {
                [$column, $privilege] = explode(' ', $key);
                $granted = 'DELETE' === $privilege
                    ? self::SUPPLIER_PRODUCT_DELETES
                    : \in_array($column, self::SUPPLIER_PRODUCT_COLUMN_WRITES[$privilege], true);
                if ($granted && 'accepted' !== $outcome) {
                    $wrong[] = sprintf('supplier_product.%s: SupplierProductWriter issues it, but erp_app got "%s"', $key, $outcome);
                } elseif (!$granted && 'refused' !== $outcome) {
                    $wrong[] = sprintf('supplier_product.%s: SupplierProductWriter does not issue it, but erp_app got "%s"', $key, $outcome);
                }
            }

            return $wrong;
        };

        $outcomes = $probe();
        self::assertSame(['accepted', 'accepted', 'accepted'], [$outcomes['unit_cost INSERT'] ?? null, $outcomes['unit_cost UPDATE'] ?? null, $outcomes['* DELETE'] ?? null],
            'The positive controls failed: erp_app could not insert or update supplier_product.unit_cost, or delete an entry (C4).');
        self::assertSame(['refused', 'refused', 'refused'],
            [$outcomes['currency_code UPDATE'] ?? null, $outcomes['supplier_id UPDATE'] ?? null, $outcomes['inventory_item_id UPDATE'] ?? null],
            'erp_app must not relabel an entry\'s currency or re-point it, and the probe did not see them refused.');
        self::assertSame([], $violations($outcomes),
            "erp_app's writes on supplier_product are not exactly SupplierProductWriter's:\n  ".implode("\n  ", $violations($outcomes)));

        try {
            $this->owner()->executeStatement(sprintf('GRANT UPDATE (currency_code) ON supplier_product TO %s', DatabaseRoles::APP));
            self::assertSame(['supplier_product.currency_code UPDATE: SupplierProductWriter does not issue it, but erp_app got "accepted"'], $violations($probe()),
                'A committed UPDATE (currency_code) grant to erp_app did not make this check fail, so it is not a check.');
        } finally {
            $this->owner()->executeStatement(sprintf('REVOKE UPDATE (currency_code) ON supplier_product FROM %s', DatabaseRoles::APP));
        }
        self::assertSame([], $violations($probe()), 'The planted supplier_product currency_code grant survived its REVOKE.');

        try {
            $this->owner()->executeStatement(sprintf('REVOKE DELETE ON supplier_product FROM %s', DatabaseRoles::APP));
            self::assertSame(['supplier_product.* DELETE: SupplierProductWriter issues it, but erp_app got "refused"'], $violations($probe()),
                'A committed REVOKE DELETE from erp_app did not make this check fail, so the C4 grant is not checked.');
        } finally {
            $this->owner()->executeStatement(sprintf('GRANT DELETE ON supplier_product TO %s', DatabaseRoles::APP));
        }
        self::assertSame([], $violations($probe()), 'The revoked supplier_product DELETE grant did not come back.');
    }

    /**
     * **`DATA` D-10.1's backstop CHECKs refuse, as `erp_app`, what the application refuses** (roles slice 4 C1): a zero-width
     * space, a trailing space, a bidi override, an empty name and a 101-character name → `23514 role_name_shape`; a control
     * character in the description → `role_description_shape`. **Positive control:** a plain name (and a description with a
     * tab and a newline) is accepted — every probe rolled back. G-10's SQL writer is what these stop; the API never reaches them.
     */
    #[Test]
    public function theRoleShapeChecksRefuseInvisibleOrBlankNamesWrittenAsTheApplication(): void
    {
        $insert = function (string $name, string $description): string {
            $connection = $this->secondClient();
            $connection->beginTransaction();
            try {
                $this->tenantContext()->establishOn($connection, self::TENANT_A);
                $connection->executeStatement('INSERT INTO role (id, tenant_id, name, description) VALUES (?::uuid, ?::uuid, ?, ?)',
                    [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), self::TENANT_A, $name, $description]);

                return 'accepted';
            } catch (DriverException $e) {
                return ($e->getSQLState() ?? '?').(str_contains($e->getMessage(), 'role_name_shape') ? ' role_name_shape' : (str_contains($e->getMessage(), 'role_description_shape') ? ' role_description_shape' : ' '.$e->getMessage()));
            } finally {
                $connection->rollBack();
                $connection->close();
            }
        };

        self::assertSame('accepted', $insert('Night shift', "Two lines,\tone tab\nhere"), 'Positive control: a plain role was refused.');
        foreach (["Own\u{200B}er", 'Clerk ', "Clerk\u{202E}", '', str_repeat('n', 101), "\u{FEFF}Owner"] as $name) {
            self::assertSame('23514 role_name_shape', $insert($name, ''), 'role_name_shape admitted '.json_encode($name));
        }
        self::assertSame('23514 role_description_shape', $insert('Clerk', "a\u{0001}b"));
        self::assertSame('23514 role_description_shape', $insert('Clerk', str_repeat('d', 501)));
    }

    /**
     * The `role_grant_proposal` columns `CreateRoleGrantProposal`'s one `INSERT … SELECT` names, and the columns its
     * lapse-on-touch flush (`RoleGrantProposal::lapse()`) and Confirm's flush (`RoleGrantProposal::confirm()`) set (roles
     * slice 3, C1 and C2; design §7; `DATA` §4). **Typed by hand from the statements, never read from `SchemaManifest`**
     * (`USER_ROLE_COLUMNS_THE_GRANT_INSERTS`' reason). `decided_by_user_id` joined the UPDATE set at C2 (Confirm writes the
     * confirmer); `reason` joins at C3 (Reject) — RED M-3: a write no `src/` path issues is not granted.
     */
    private const PROPOSAL_COLUMNS_THE_CREATE_INSERTS = ['expires_at', 'id', 'permission_codes', 'proposed_at', 'proposed_by_user_id', 'role_id', 'tenant_id', 'user_id'];

    private const PROPOSAL_COLUMNS_THE_DECISIONS_UPDATE = ['decided_at', 'decided_by_user_id', 'lapse_reason', 'reason', 'status', 'updated_at'];

    /**
     * **Roles slice 3 C1/C2: `erp_app` can INSERT exactly the eight proposal columns Create names and UPDATE exactly the
     * six the lapse, the confirmation and the rejection write — proved by running the statements as `erp_app`** (design §7 "New: a
     * column probe"; `DATA` §4).
     * `theApplicationCanInsertOnlyTheGrantColumnsItWrites`' shape, for both privileges: the domain is server-derived
     * (`pg_attribute`), one connection and one rolled-back transaction per column and privilege, zero-row probes
     * (`INSERT … SELECT … WHERE false`, `UPDATE … SET c = c WHERE false`), so only the privilege can refuse.
     *
     * **Positive controls:** `role_id` INSERT and `status` UPDATE accepted. **Anti-vacuity:** `status` and `created_at`
     * refused on INSERT (every inserted row is `pending`); `permission_codes`, `user_id` and `expires_at`
     * refused on UPDATE (the snapshot and the subject are immutable after insert). `decided_by_user_id` flipped to
     * accepted at C2 (Confirm) and `reason` at C3 (Reject). **Planted control, committed then undone:** `GRANT UPDATE (permission_codes)` must
     * produce exactly one violation.
     */
    #[Test]
    public function theApplicationCanWriteOnlyTheProposalColumnsItsCallersWrite(): void
    {
        $columns = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT a.attname FROM pg_attribute a
            WHERE a.attrelid = 'role_grant_proposal'::regclass AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY a.attnum
            SQL);

        $probe = function () use ($columns): array {
            $outcomes = [];
            foreach (['INSERT', 'UPDATE'] as $privilege) {
                foreach ($columns as $column) {
                    $connection = $this->secondClient();
                    $connection->beginTransaction();
                    try {
                        $this->tenantContext()->establishOn($connection, self::TENANT_A);
                        $quoted = $connection->getDatabasePlatform()->quoteSingleIdentifier($column);
                        $connection->executeStatement('INSERT' === $privilege
                            ? sprintf('INSERT INTO role_grant_proposal (%1$s) SELECT %1$s FROM role_grant_proposal WHERE false', $quoted)
                            : sprintf('UPDATE role_grant_proposal SET %1$s = %1$s WHERE false', $quoted));
                        $outcomes[$privilege.' '.$column] = 'accepted';
                    } catch (DriverException $e) {
                        $outcomes[$privilege.' '.$column] = '42501' === $e->getSQLState()
                            && str_contains($e->getMessage(), 'permission denied for table role_grant_proposal')
                            ? 'refused'
                            : 'other: '.($e->getSQLState() ?? '?').' '.$e->getMessage();
                    } finally {
                        $connection->rollBack();
                        $connection->close();
                    }
                }
            }

            return $outcomes;
        };

        $violations = static function (array $outcomes): array {
            $wrong = [];
            foreach ($outcomes as $key => $outcome) {
                [$privilege, $column] = explode(' ', $key, 2);
                $written = \in_array($column, 'INSERT' === $privilege ? self::PROPOSAL_COLUMNS_THE_CREATE_INSERTS : self::PROPOSAL_COLUMNS_THE_DECISIONS_UPDATE, true);
                if ($written && 'accepted' !== $outcome) {
                    $wrong[] = sprintf('%s role_grant_proposal.%s: a C1–C3 caller writes it, but erp_app got "%s"', $privilege, $column, $outcome);
                } elseif (!$written && 'refused' !== $outcome) {
                    $wrong[] = sprintf('%s role_grant_proposal.%s: no C1–C3 caller writes it, but erp_app got "%s"', $privilege, $column, $outcome);
                }
            }

            return $wrong;
        };

        $outcomes = $probe();

        self::assertSame('accepted', $outcomes['INSERT role_id'] ?? null, 'Positive control: erp_app could not insert role_grant_proposal.role_id.');
        self::assertSame('accepted', $outcomes['UPDATE status'] ?? null, 'Positive control: erp_app could not update role_grant_proposal.status.');
        self::assertSame('accepted', $outcomes['UPDATE decided_by_user_id'] ?? null, 'C2: Confirm writes the confirmer, and erp_app could not.');
        self::assertSame('accepted', $outcomes['UPDATE reason'] ?? null, 'C3: Reject writes the reason, and erp_app could not.');
        foreach (['INSERT status', 'INSERT created_at', 'UPDATE permission_codes', 'UPDATE user_id', 'UPDATE expires_at'] as $closed) {
            self::assertSame('refused', $outcomes[$closed] ?? null, "erp_app must not {$closed} on role_grant_proposal, and the probe did not see it refused.");
        }

        self::assertSame([], $violations($outcomes),
            "erp_app's column grants on role_grant_proposal are not exactly what C1's, C2's and C3's callers write. A 42501 from a writer is "
            ."fixed in the writer (or the entity's changeset, M-26), never by widening the grant:\n  ".implode("\n  ", $violations($outcomes)));

        try {
            $this->owner()->executeStatement(sprintf('GRANT UPDATE (permission_codes) ON role_grant_proposal TO %s', DatabaseRoles::APP));
            self::assertSame(
                ['UPDATE role_grant_proposal.permission_codes: no C1–C3 caller writes it, but erp_app got "accepted"'],
                $violations($probe()),
                'A committed UPDATE (permission_codes) grant to erp_app did not make this check fail, so it is not a check.');
        } finally {
            $this->owner()->executeStatement(sprintf('REVOKE UPDATE (permission_codes) ON role_grant_proposal FROM %s', DatabaseRoles::APP));
        }

        self::assertSame([], $violations($probe()), 'The planted permission_codes grant survived its REVOKE.');
    }

    /**
     * The `invoice` columns the invoice's UPDATE writers set, and no others (sales step 6: C5 `PayInvoice`,
     * `Version20260928000400`; C6 `ReverseInvoicePayment` adds `payment_reversed_at`, `Version20260928000600`). **Typed by hand from the SET lists measured in the statement log (M-11), never read from
     * `SchemaManifest`** — `APP_USER_COLUMNS_THE_CALLERS_WRITE`'s reason: a copy derived from the manifest cannot see the
     * manifest and the migration widen together.
     */
    private const INVOICE_COLUMNS_THE_CALLERS_WRITE = ['paid_at', 'paid_by_user_id', 'payment_reversed_at', 'status', 'updated_at'];

    /**
     * **C5: `erp_app` can UPDATE exactly the `invoice` columns its writers set — proved by running the statement as
     * `erp_app`** (design §19 C5; `ARCH` §4; M-10). `theIssuerCanInsertOnlyTheInvoiceColumnsItWrites`' shape, for
     * UPDATE: one connection and one transaction per column, each running the zero-row probe `UPDATE invoice SET <c> =
     * <c> WHERE false` in tenant A's context and rolling back. Zero rows, so no CHECK, FK or RLS can refuse it — only
     * the privilege, which PostgreSQL checks at executor start regardless of row count.
     *
     * **Positive control:** `paid_at` is accepted. **Anti-vacuity:** the columns no writer may change once issued —
     * `total_amount`, `fulfilment_id`, `sales_order_id`, `customer_id`, `currency_code`, `due_at`, `invoice_number`,
     * `issued_by_user_id` — are in the domain and refused. **Planted control, committed then undone in `finally`:**
     * `GRANT UPDATE (total_amount)` must produce exactly one violation naming `invoice.total_amount` — so the JOINT
     * widening (the migration's grant and the manifest together) is what this list catches.
     */
    #[Test]
    public function theCallersCanUpdateOnlyTheInvoiceColumnsTheyWrite(): void
    {
        $columns = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT a.attname FROM pg_attribute a
            WHERE a.attrelid = 'invoice'::regclass AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY a.attnum
            SQL);

        $probe = function () use ($columns): array {
            $outcomes = [];
            foreach ($columns as $column) {
                $connection = $this->secondClient();
                $connection->beginTransaction();
                try {
                    $this->tenantContext()->establishOn($connection, self::TENANT_A);
                    $quoted = $connection->getDatabasePlatform()->quoteSingleIdentifier($column);
                    $connection->executeStatement(sprintf('UPDATE invoice SET %1$s = %1$s WHERE false', $quoted));
                    $outcomes[$column] = 'accepted';
                } catch (DriverException $e) {
                    $outcomes[$column] = '42501' === $e->getSQLState()
                        && str_contains($e->getMessage(), 'permission denied for table invoice')
                        ? 'refused'
                        : 'other: '.($e->getSQLState() ?? '?').' '.$e->getMessage();
                } finally {
                    $connection->rollBack();
                    $connection->close();
                }
            }

            return $outcomes;
        };

        $violations = static function (array $outcomes): array {
            $wrong = [];
            foreach ($outcomes as $column => $outcome) {
                $written = \in_array($column, self::INVOICE_COLUMNS_THE_CALLERS_WRITE, true);
                if ($written && 'accepted' !== $outcome) {
                    $wrong[] = sprintf('invoice.%s: a writer sets it, but erp_app got "%s"', $column, $outcome);
                } elseif (!$written && 'refused' !== $outcome) {
                    $wrong[] = sprintf('invoice.%s: no writer sets it, but erp_app got "%s"', $column, $outcome);
                }
            }

            return $wrong;
        };

        $outcomes = $probe();

        self::assertSame('accepted', $outcomes['paid_at'] ?? null,
            'The positive control failed: erp_app could not update invoice.paid_at, so the refusals below prove nothing.');
        foreach (['total_amount', 'fulfilment_id', 'sales_order_id', 'customer_id', 'currency_code', 'due_at', 'invoice_number', 'issued_by_user_id'] as $closed) {
            self::assertSame('refused', $outcomes[$closed] ?? null, "erp_app must not update invoice.{$closed}, and the probe did not see it refused.");
        }
        foreach (self::INVOICE_COLUMNS_THE_CALLERS_WRITE as $written) {
            self::assertArrayHasKey($written, $outcomes, "A column a writer sets, {$written}, is not in invoice.");
        }

        self::assertSame([], $violations($outcomes),
            "erp_app's UPDATE on invoice is not exactly the columns its writers set. A 42501 from a new writer is fixed by "
            ."granting that column with its caller, never by a table-level UPDATE:\n  ".implode("\n  ", $violations($outcomes)));

        try {
            $this->owner()->executeStatement(sprintf('GRANT UPDATE (total_amount) ON invoice TO %s', DatabaseRoles::APP));
            self::assertSame(
                ['invoice.total_amount: no writer sets it, but erp_app got "accepted"'],
                $violations($probe()),
                'A committed UPDATE (total_amount) grant to erp_app did not make this check fail, so it is not a check.');
        } finally {
            $this->owner()->executeStatement(sprintf('REVOKE UPDATE (total_amount) ON invoice FROM %s', DatabaseRoles::APP));
        }

        self::assertSame([], $violations($probe()), 'The planted total_amount grant survived its REVOKE.');
    }

    /**
     * **ADR-002 Amendment 4, constraint 2 — the invoker cascade trigger pairs its parent's UPDATE with its child's**
     * (V4 batch B7, 2026-09-16; RED `red-adr002-a4-attack.md` R7).
     *
     * `purchase_order_cascade_deletion` and `sales_order_cascade_deletion` run `cascade_deletion_to_lines`, which is
     * SECURITY INVOKER and issues `UPDATE <tgargs[0]> SET deleted_at … WHERE tenant_id = … AND <tgargs[1]> = …`. So
     * any role that can UPDATE a parent can fire an UPDATE of the child **as itself**: without UPDATE and SELECT on the
     * child, a soft delete of the parent is a `42501` at the line table. Narrowing grants to callers makes that easy to
     * get wrong — the first sales build to grant UPDATE on `sales_order` for a confirm or cancel makes `SET deleted_at`
     * reachable whether or not it soft-deletes anything. Once `DeletionPolicyTest`'s sales cascade test moved to the
     * owner (it proves the function body), nothing else exercised this for sales.
     *
     * **Domain derived from the catalogue, never listed:** every non-internal trigger calling
     * `cascade_deletion_to_lines`, its parent from `tgrelid` and its child from `tgargs[0]`; every application role.
     * The parent side is `has_any_column_privilege(…, 'UPDATE')`, which is true for a table-level grant and for a
     * column-level one (an `UPDATE (deleted_at)` column grant fires the trigger too). The child side is table-level
     * UPDATE and SELECT.
     *
     * **Positive control, same test:** `purchase_order` → `purchase_order_line` must be in the derived set and
     * `erp_app` must hold UPDATE on `purchase_order`, so the implication is exercised, not vacuous.
     * **Planted control:** a rolled-back `REVOKE UPDATE ON sales_order_line FROM erp_app`, leaving
     * `sales_order`'s `UPDATE` in place, must produce exactly one violation naming `sales_order_line`.
     * *(It was `GRANT UPDATE ON sales_order TO erp_app` until 2026-09-19. That plant depended on `erp_app`
     * holding `UPDATE` on neither sales table, and `ConfirmSalesOrder`'s `Version20260919000300` — written
     * parent-only first, and reddened here by exactly the violation this control asserts — granted both.
     * A plant that can no longer reach the forbidden state is not a control, so it was moved to the other
     * side of the same implication rather than deleted.)*
     */
    #[Test]
    public function aRoleThatCanUpdateACascadeParentCanUpdateAndReadItsChild(): void
    {
        $pairs = $this->owner()->fetchAllKeyValue(<<<'SQL'
            SELECT t.tgrelid::regclass::text AS parent,
                   (string_to_array(encode(t.tgargs, 'escape'), '\000'))[1] AS child
            FROM pg_trigger t
            JOIN pg_proc p ON p.oid = t.tgfoid
            WHERE p.proname = 'cascade_deletion_to_lines' AND NOT t.tgisinternal
            ORDER BY 1
            SQL);

        self::assertSame('purchase_order_line', $pairs['purchase_order'] ?? null,
            'The purchase_order cascade trigger is not in the derived set, so this test has lost its positive control.');
        self::assertArrayHasKey('sales_order', $pairs, 'The sales_order cascade trigger is not in the derived set.');
        self::assertTrue((bool) $this->owner()->fetchOne('SELECT has_table_privilege(?, ?, ?)',
            [DatabaseRoles::APP, 'purchase_order', 'UPDATE']),
            'erp_app cannot UPDATE purchase_order, so the implication below is never exercised and proves nothing.');

        $violations = function () use ($pairs): array {
            $found = [];
            foreach ($pairs as $parent => $child) {
                foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
                    if (!(bool) $this->owner()->fetchOne('SELECT has_any_column_privilege(?, ?, ?)', [$role, $parent, 'UPDATE'])) {
                        continue;
                    }
                    foreach (['UPDATE', 'SELECT'] as $priv) {
                        if (!(bool) $this->owner()->fetchOne('SELECT has_table_privilege(?, ?, ?)', [$role, $child, $priv])) {
                            $found[] = "$role can UPDATE $parent but lacks $priv on $child";
                        }
                    }
                }
            }

            return $found;
        };

        self::assertSame([], $violations(),
            "A role can soft-delete a cascade parent but cannot run the cascade it fires as the invoker. Grant the child's "
            .'UPDATE in the same migration that grants the parent\'s (ADR-002 A4.4 item 3).');

        // ⚠ **The plant CHANGED SIDES on 2026-09-19 with `ConfirmSalesOrder`, and the reason is the whole
        // point of this test.** It used to be `GRANT UPDATE ON sales_order TO erp_app`, which worked only
        // while `erp_app` held `UPDATE` on neither sales table. `Version20260919000300` granted both — and
        // it did so *because this check demanded it*: the migration was written parent-only first and this
        // method answered with exactly the violation asserted below (P-4, measured, not predicted). With
        // both grants in place that plant now produces **no** violation, so keeping it would have turned
        // the control into a test that passes forever. The plant is therefore the same state reached from
        // the other side: **revoke the child's `UPDATE`** and leave the parent's, which is precisely the
        // condition A4.4 duty 3 forbids. The asserted string is unchanged, so what the control proves is
        // unchanged too.
        $this->owner()->beginTransaction();
        try {
            $this->owner()->executeStatement(sprintf('REVOKE UPDATE ON sales_order_line FROM %s', DatabaseRoles::APP));
            self::assertSame([DatabaseRoles::APP.' can UPDATE sales_order but lacks UPDATE on sales_order_line'], $violations(),
                'A planted revocation of sales_order_line\'s UPDATE, with sales_order\'s left in place, did not make this '
                .'check fail, so it is not a check.');
        } finally {
            $this->owner()->rollBack();
        }

        self::assertSame([], $violations(), 'The planted grant survived the rollback.');
    }

    /**
     * ADR-006 V-1, in the form the probe forced.
     *
     * has_any_column_privilege raises `unrecognized privilege type` for DELETE,
     * TRUNCATE and TRIGGER, so the two privilege lists must differ or the check
     * errors in CI instead of failing. That is why SchemaManifest carries two
     * constants rather than one.
     */
    #[Test]
    public function thePreAuthReadRoleCanWriteNowhereInTheSchema(): void
    {
        foreach (SchemaManifest::allTables() as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] as $priv) {
                self::assertFalse((bool) $this->owner()->fetchOne(
                    'SELECT has_table_privilege(?, ?, ?)', [DatabaseRoles::AUTH, $table, $priv]),
                    'erp_auth holds '.$priv.' on '.$table.'. T-1a bound 2 is meant to be a grant fact, not a code rule.');
            }

            foreach (['INSERT', 'UPDATE', 'REFERENCES'] as $priv) {
                self::assertFalse((bool) $this->owner()->fetchOne(
                    'SELECT has_any_column_privilege(?, ?, ?)', [DatabaseRoles::AUTH, $table, $priv]),
                    'erp_auth holds a column-level '.$priv.' on '.$table.'.');
            }
        }
    }

    /** ADR-006 V-2: the audit writer cannot read back what it wrote. */
    #[Test]
    public function theAuditWriterCanInsertAndCannotSelect(): void
    {
        self::assertTrue((bool) $this->owner()->fetchOne(
            'SELECT has_table_privilege(?, ?, ?)', [DatabaseRoles::AUTH_LOG, 'audit_log', 'INSERT']));
        self::assertFalse((bool) $this->owner()->fetchOne(
            'SELECT has_table_privilege(?, ?, ?)', [DatabaseRoles::AUTH_LOG, 'audit_log', 'SELECT']),
            'erp_authlog can read the audit log. ADR-006 §2 is deliberately stronger than S-9 here.');
        self::assertFalse((bool) $this->owner()->fetchOne(
            'SELECT has_any_column_privilege(?, ?, ?)', [DatabaseRoles::AUTH_LOG, 'audit_log', 'SELECT']));
    }

    /**
     * S-9, and the ordering that `RED` caught: the revoke fights the blanket
     * grant and any default privileges, so it has to run last and be re-asserted
     * rather than assumed.
     */
    #[Test]
    public function theAuditLogAndStockMovementAreAppendOnlyForTheApplication(): void
    {
        foreach (['audit_log', 'stock_movement'] as $table) {
            foreach (['SELECT', 'INSERT'] as $priv) {
                self::assertTrue((bool) $this->owner()->fetchOne(
                    'SELECT has_table_privilege(?, ?, ?)', [DatabaseRoles::APP, $table, $priv]));
            }
            foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $priv) {
                self::assertFalse((bool) $this->owner()->fetchOne(
                    'SELECT has_table_privilege(?, ?, ?)', [DatabaseRoles::APP, $table, $priv]),
                    "erp_app holds $priv on $table -- append-only enforced by a grant is a control, enforced by a "
                    .'convention is a hope.');

                // has_table_privilege() answers false for a column-level grant
                // (see noRoleHoldsAnyPrivilegeOnAnIndividualPartition), so the
                // three assertions above would stay green under
                // `GRANT UPDATE (metadata) ON audit_log TO erp_app` -- append-only
                // broken, S-9 still reporting a control. Only UPDATE is
                // column-grantable of the three; the domain comes from
                // COLUMN_PRIVILEGES rather than a restated list because
                // has_any_column_privilege() errors on DELETE and TRUNCATE.
                if (\in_array($priv, SchemaManifest::COLUMN_PRIVILEGES, true)) {
                    self::assertFalse((bool) $this->owner()->fetchOne(
                        'SELECT has_any_column_privilege(?, ?, ?)', [DatabaseRoles::APP, $table, $priv]),
                        "erp_app holds a column-level $priv on $table, so the table-level assertion above is "
                        .'fake-green and the append-only guarantee is not a guarantee.');
                }
            }
        }
    }

    /**
     * A1.5's finding, kept at the point where it is introduced and widened from
     * tenant-scoped tables to all of them: a single GRANT … TO PUBLIC makes
     * every information_schema-based privilege check return zero rows while the
     * privilege is held.
     */
    #[Test]
    public function nothingInTheSchemaGrantsAnythingToPublic(): void
    {
        $granted = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT c.relname AS tbl, a.privilege_type
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            CROSS JOIN LATERAL aclexplode(c.relacl) a
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'S') AND a.grantee = 0
            ORDER BY 1, 2
            SQL);

        self::assertSame([], $granted,
            "PUBLIC holds privileges in this schema:\n".json_encode($granted, JSON_PRETTY_PRINT));

        $columnGrants = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT c.relname AS tbl, a.attname AS col, x.privilege_type
            FROM pg_attribute a
            JOIN pg_class c ON c.oid = a.attrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            CROSS JOIN LATERAL aclexplode(a.attacl) x
            WHERE n.nspname = 'public' AND x.grantee = 0
            ORDER BY 1, 2
            SQL);

        self::assertSame([], $columnGrants,
            "PUBLIC holds column privileges in this schema:\n".json_encode($columnGrants, JSON_PRETTY_PRINT));
    }

    /**
     * **Database-level privileges: every application role holds `CONNECT` and nothing else** (V3-3(i), V4 batch B2c,
     * 2026-09-16; `docs/session10/v3-principals.md` S-g).
     *
     * `pg_database.datacl` was NULL, so PostgreSQL's defaults applied and PUBLIC held `CONNECT` **and `TEMPORARY`**. Under
     * PgBouncer's `pool_mode=transaction` with `server_reset_query_always=0` no reset runs between clients, so a temp table
     * outlives the client that made it on the pooled backend, and `pg_temp` is searched before `public` for the unqualified
     * names application SQL uses. M-3, measured 2026-09-16: an `erp_app` client ran `CREATE TEMP TABLE audit_log (LIKE
     * public.audit_log)`; a fresh `erp_app` client on the same pool (same backend pid) inserted tenant B's audit row with the
     * application's own statement shape and read it back; the owner, in tenant B's context, found **0** rows in
     * `public.audit_log`. Append-only audit rows silently lost, CHECKs, foreign keys and triggers skipped, forged reads —
     * persistence into later requests from any tenant, R-14's class. `Version20260916000100` revokes `TEMPORARY` from PUBLIC.
     *
     * **Domain derived from the server, never listed:** the database privilege types are read from
     * `aclexplode(acldefault('d', owner))`, so a privilege type PostgreSQL adds is covered without an edit here.
     * `has_database_privilege()` resolves PUBLIC grants and role membership, which `information_schema` does not (A1.5).
     *
     * **Positive control in the same test:** a `GRANT TEMPORARY … TO erp_app` inside a transaction must show up, and must be
     * gone after the rollback.
     *
     * **Not closed by this, named so "TEMP revoked" is not read as "session state closed"** (V3-3(ii), owner question O-2):
     * bare `SET`, `SET ROLE`, session `pg_advisory_lock`, `PREPARE`, `LISTEN`, `WITH HOLD` cursors, and `lo_create` storage.
     */
    #[Test]
    public function everyApplicationRoleHoldsConnectAndNoOtherDatabasePrivilege(): void
    {
        $types = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT DISTINCT a.privilege_type
            FROM pg_database d CROSS JOIN LATERAL aclexplode(acldefault('d', d.datdba)) a
            WHERE d.datname = current_database()
            ORDER BY 1
            SQL);
        self::assertContains('TEMPORARY', $types, 'The server-derived database privilege domain does not include TEMPORARY, so it is not being read.');
        self::assertContains('CONNECT', $types);

        $held = function () use ($types): array {
            $map = [];
            foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
                $map[$role] = array_values(array_filter($types, fn (string $type): bool => (bool) $this->owner()->fetchOne(
                    'SELECT has_database_privilege(?, current_database(), ?)', [$role, $type])));
            }

            return $map;
        };
        $expected = array_fill_keys(DatabaseRoles::APPLICATION_ROLES, ['CONNECT']);

        self::assertSame($expected, $held(),
            'An application role holds a database privilege other than CONNECT. TEMPORARY lets a temp table shadow a '
            .'public table for every later client on a pooled backend (V3-3, R-14); CREATE lets a role own a schema.');

        $this->owner()->beginTransaction();
        try {
            $this->owner()->executeStatement(sprintf('GRANT TEMPORARY ON DATABASE %s TO %s',
                $this->owner()->quoteSingleIdentifier((string) $this->owner()->fetchOne('SELECT current_database()')),
                $this->owner()->quoteSingleIdentifier(DatabaseRoles::APP)));

            self::assertContains('TEMPORARY', $held()[DatabaseRoles::APP], 'A planted GRANT TEMPORARY was not seen, so the empty result above proves nothing.');
        } finally {
            $this->owner()->rollBack();
        }

        self::assertSame($expected, $held(), 'The planted grant survived the rollback.');
    }

    /**
     * Individual partitions are storage, not schema, and no principal needs a
     * privilege on one: permissions are checked against the table named in the
     * query, and routing an INSERT to a partition does not consult the
     * partition's ACL. They are asserted empty because ALTER DEFAULT PRIVILEGES
     * would have granted them, which is the same defect V-10 describes, wearing
     * a relkind nobody checks.
     *
     * **Why this test is about to become load-bearing, and why the table-level
     * loop alone was not enough.** Measured while scoping SEC-O-3: a partition
     * does *not* inherit its parent's row security. `ALTER TABLE … ENABLE/FORCE
     * ROW LEVEL SECURITY` on a partitioned parent leaves every child at
     * `relrowsecurity = false`, and policies are checked against the table named
     * in the query. So a privilege on a partition is not merely surplus — on a
     * *tenant-scoped* partitioned table it is a total bypass of tenant
     * isolation. Reproduced: one `GRANT SELECT` on one partition, and `erp_app`
     * holding tenant A's context read both tenants' rows.
     *
     * `auth_attempt` is partitioned already but carries no policy to bypass, so
     * nothing here was load-bearing yet. It becomes so the moment any
     * tenant-scoped table is partitioned, which is what SEC-O-3 proposes for
     * `audit_log`. This test is that design's whole safety argument, so it has
     * to actually hold.
     *
     * It did not. `has_table_privilege()` answers *false* for a column-level
     * grant, so `GRANT SELECT (tenant_id, occurred_at, event_type) ON <partition>`
     * was invisible to every one of the eight table privileges above while still
     * returning other tenants' rows. Same defect class the project has already
     * been burned by twice — a check that answers, and answers about less than
     * you think (A1.5's `information_schema`, S-17's unchecked constant). The
     * column loop below closes it; `COLUMN_PRIVILEGES` is the correct domain
     * because `has_any_column_privilege()` raises `unrecognized privilege type`
     * for DELETE, TRUNCATE and TRIGGER, and it is itself reconciled against the
     * server by theCoverageConstantsNameEveryPrivilegePostgresHas.
     */
    #[Test]
    public function noRoleHoldsAnyPrivilegeOnAnIndividualPartition(): void
    {
        $partitions = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relispartition
            ORDER BY 1
            SQL);

        self::assertNotSame([], $partitions, 'No partitions exist, so this test proves nothing.');

        foreach ($partitions as $partition) {
            foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
                foreach (SchemaManifest::TABLE_PRIVILEGES as $priv) {
                    self::assertFalse((bool) $this->owner()->fetchOne(
                        'SELECT has_table_privilege(?, ?, ?)', [$role, $partition, $priv]),
                        "$role holds $priv directly on the partition $partition.");
                }

                foreach (SchemaManifest::COLUMN_PRIVILEGES as $priv) {
                    self::assertFalse((bool) $this->owner()->fetchOne(
                        'SELECT has_any_column_privilege(?, ?, ?)', [$role, $partition, $priv]),
                        "$role holds a column-level $priv on the partition $partition. A partition does not "
                        .'inherit its parent\'s row security, so on a tenant-scoped partitioned table this '
                        .'reads every tenant\'s rows regardless of app.tenant_id.');
                }
            }
        }
    }

    // -----------------------------------------------------------------------
    // H5 — the "left the laptop" tripwire (roles slice 2, C2; q7-spar §2.2; `API.md` §15.1 `RevokeRole` cell;
    // `RESIDUAL_GAPS.md` G-10 and G-12).
    // -----------------------------------------------------------------------

    /** The compose services the local stack is pinned to; any other service is a deploy artefact by this rule. */
    private const LOCAL_COMPOSE_SERVICES = ['pgbouncer', 'postgres'];

    /**
     * Platform-config and container file names, disclosed — **name-shaped by design**: a guess about the future, and a
     * dashboard-configured deploy leaves no file at all.
     */
    private const DEPLOY_ARTEFACT_NAMES = [
        '/^Dockerfile/', '/^fly\.toml$/', '/^render\.yaml$/', '/^railway\.json$/', '/^vercel\.json$/', '/^app\.yaml$/',
        '/^Procfile$/', '/^netlify\.toml$/', '/^heroku\.yml$/', '/^\.platform\.app\.yaml$/', '#^\.github/workflows/.*deploy#',
    ];

    /**
     * **H5: no role or credential write may leave the laptop before its closing work.** Red while BOTH hold:
     *
     *  (i) `erp_app` holds any write on `user_role`, `role_permission` or `role` (table **or** column level), **or** can
     *      update `app_user.password_hash` with no `app_user.password_mac` column (G-12 — `has_column_privilege`, because
     *      after H0 a table-level check never fires);
     *  (ii) the tree carries a deploy artefact (a `Dockerfile*`, a compose service outside the local pair, a file named in
     *      {@see self::DEPLOY_ARTEFACT_NAMES}), **or** `SchemaManifest` gives `erp_ai` any write.
     *
     * (i) became true through `password_hash` at H0 and through `DELETE ON user_role` at C2 — asserted below, so the
     * tripwire is armed rather than green over nothing. (ii) is false today (measured: no Dockerfile or platform config,
     * compose holds `postgres` and `pgbouncer`), so it ships green.
     *
     * **Name-shaped, and these have no code signal at all (spar T-3):** a first non-demo tenant, hosted (Neon) credentials
     * and a dashboard-configured deploy. Those triggers stay prose in G-10. Planted controls, same test: a file list with a
     * `Dockerfile` → red; a manifest giving `erp_ai` `INSERT` → red; the real tree → green.
     */
    #[Test]
    public function noRoleOrCredentialWriteLeavesTheLaptopBeforeItsClosingWork(): void
    {
        $roleWrite = $this->roleOrCredentialWrites();
        self::assertNotSame([], $roleWrite, 'Operand (i) is false, so this tripwire can never fire. Since C2 erp_app holds '
            .'DELETE on user_role and (G-12) UPDATE on app_user.password_hash; if both closed, re-read G-10/G-12 and retire H5 on purpose.');

        $files = self::filesUnderTheRepository();
        $services = array_keys(self::composeServices());

        // Planted controls through the same decision.
        self::assertNotSame([], self::leavesTheLaptop($roleWrite, ['backend/Dockerfile'], self::LOCAL_COMPOSE_SERVICES, false), 'A Dockerfile did not arm H5.');
        self::assertNotSame([], self::leavesTheLaptop($roleWrite, [], [...self::LOCAL_COMPOSE_SERVICES, 'app'], false), 'A compose app service did not arm H5.');
        self::assertNotSame([], self::leavesTheLaptop($roleWrite, [], self::LOCAL_COMPOSE_SERVICES, true), 'erp_ai gaining a write did not arm H5.');
        self::assertSame([], self::leavesTheLaptop([], ['backend/Dockerfile'], self::LOCAL_COMPOSE_SERVICES, true), 'H5 fired with operand (i) false.');
        self::assertContains('infra/docker-compose.yml', $files, 'The tree walk does not see the compose file, so it sees nothing.');

        $fired = self::leavesTheLaptop($roleWrite, $files, $services, self::manifestGivesTheAgentAWrite());
        self::assertSame([], $fired,
            "H5 (G-10, G-12): a role or credential write is about to leave the laptop.\n  ".implode("\n  ", $fired)
            ."\nBefore this ships: run `bin/console app:roles:reconcile --tenant=<id>` for every tenant and paste the output into "
            ."the record (G-10 condition (2)); the owner chooses (b) erp_roleadmin or (e)-roles, or re-accepts G-10 in so many "
            ."words (condition (3)); and G-12's (e)-cred (a password_mac the application cannot forge) is owed.");
    }

    /** @return list<string> operand (i)'s evidence, empty when false */
    private function roleOrCredentialWrites(): array
    {
        $found = [];
        foreach (['user_role', 'role_permission', 'role'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $privilege) {
                if ((bool) $this->owner()->fetchOne('SELECT has_table_privilege(?, ?, ?)', [DatabaseRoles::APP, $table, $privilege])
                    || ('DELETE' !== $privilege && (bool) $this->owner()->fetchOne('SELECT has_any_column_privilege(?, ?, ?)', [DatabaseRoles::APP, $table, $privilege]))) {
                    $found[] = DatabaseRoles::APP." holds $privilege on $table";
                }
            }
        }
        $mac = (bool) $this->owner()->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = 'app_user'::regclass AND attname = 'password_mac' AND NOT attisdropped)");
        if (!$mac && (bool) $this->owner()->fetchOne("SELECT has_column_privilege(?, 'app_user', 'password_hash', 'UPDATE')", [DatabaseRoles::APP])) {
            $found[] = DatabaseRoles::APP.' can update app_user.password_hash and no password_mac exists (G-12)';
        }

        return $found;
    }

    /**
     * @param list<string> $roleWrite
     * @param list<string> $files     paths relative to the repository root
     * @param list<string> $services  compose service names
     *
     * @return list<string> why H5 fires, empty when it does not
     */
    private static function leavesTheLaptop(array $roleWrite, array $files, array $services, bool $agentWrites): array
    {
        if ([] === $roleWrite) {
            return [];
        }
        $artefacts = [];
        foreach ($files as $file) {
            foreach (self::DEPLOY_ARTEFACT_NAMES as $pattern) {
                if (1 === preg_match($pattern, basename($file)) || 1 === preg_match($pattern, $file)) {
                    $artefacts[] = 'deploy artefact: '.$file;
                }
            }
        }
        foreach (array_diff($services, self::LOCAL_COMPOSE_SERVICES) as $service) {
            $artefacts[] = 'compose service outside the local pair: '.$service;
        }
        if ($agentWrites) {
            $artefacts[] = 'SchemaManifest gives erp_ai a write';
        }

        return [] === $artefacts ? [] : [...$roleWrite, ...array_values(array_unique($artefacts))];
    }

    private static function manifestGivesTheAgentAWrite(): bool
    {
        foreach (SchemaManifest::allTables() as $table) {
            if ([] !== array_diff(SchemaManifest::grantsFor($table, DatabaseRoles::AI), ['SELECT'])) {
                return true;
            }
            foreach (['INSERT', 'UPDATE'] as $privilege) {
                if ([] !== SchemaManifest::columnGrantsFor($table, DatabaseRoles::AI, $privilege)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> every file under the repository root, relative, skipping vendored and generated trees */
    private static function filesUnderTheRepository(): array
    {
        $root = \dirname(__DIR__, 3);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f): bool => !($f->isDir() && \in_array($f->getFilename(), ['.git', 'vendor', 'var', 'node_modules', 'docs'], true)),
        ));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $files[] = ltrim(substr($file->getPathname(), \strlen($root)), '/');
        }

        return $files;
    }

    /** @return array<string, mixed> */
    private static function composeServices(): array
    {
        $parsed = Yaml::parseFile(\dirname(__DIR__, 3).'/infra/docker-compose.yml');
        self::assertIsArray($parsed);
        self::assertIsArray($parsed['services'] ?? null);

        return $parsed['services'];
    }
}
