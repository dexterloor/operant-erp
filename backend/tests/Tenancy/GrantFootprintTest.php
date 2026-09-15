<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Platform\Tenancy\DatabaseRoles;
use App\Platform\Tenancy\SchemaManifest;
use PHPUnit\Framework\Attributes\Test;

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
     * **Planted control:** `GRANT UPDATE ON sales_order TO erp_app` inside a rolled-back transaction must produce
     * exactly one violation naming `sales_order_line`.
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

        $this->owner()->beginTransaction();
        try {
            $this->owner()->executeStatement(sprintf('GRANT UPDATE ON sales_order TO %s', DatabaseRoles::APP));
            self::assertSame([DatabaseRoles::APP.' can UPDATE sales_order but lacks UPDATE on sales_order_line'], $violations(),
                'A planted UPDATE on sales_order alone did not make this check fail, so it is not a check.');
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
}
