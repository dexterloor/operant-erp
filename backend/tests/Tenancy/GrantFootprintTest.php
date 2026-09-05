<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Tenancy\DatabaseRoles;
use App\Tenancy\SchemaManifest;
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

        foreach ($sequences as $sequence) {
            foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
                $expected = DatabaseRoles::APP === $role;
                foreach (['USAGE', 'SELECT'] as $priv) {
                    self::assertSame($expected, (bool) $this->owner()->fetchOne(
                        'SELECT has_sequence_privilege(?, ?, ?)', [$role, $sequence, $priv]),
                        "$role and $priv on $sequence disagree with the queue being erp_app's alone.");
                }
                // Nobody ever needs to reset a sequence.
                self::assertFalse((bool) $this->owner()->fetchOne(
                    'SELECT has_sequence_privilege(?, ?, ?)', [$role, $sequence, 'UPDATE']));
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
     * `RED`'s MAJOR-5, stated as the property that actually holds rather than as
     * the one I would like to claim.
     *
     * V-10/S-13 is closed by grant on the registry: no application role can
     * write `tenant` at all. It is **not** closed on the queue, because
     * `erp_app` legitimately needs full DML there for the worker, and the
     * Symfony EntityManager is pinned to that connection. There is no login path
     * yet, so nothing today binds the pre-authentication route to
     * `erp_ratelimit`; if Output 4 wires it to the default connection, the
     * queue is reachable again. That residual is real and is recorded in
     * docs/SCHEMA.md rather than argued away.
     *
     * What the schema *can* guarantee, and this asserts, is that the mistake is
     * loud rather than silent: `erp_app` holds nothing whatsoever on the two
     * counter tables, so a rate limiter pointed at the wrong connection fails
     * with `permission denied` on its first statement instead of working and
     * quietly leaving the queue exposed.
     */
    #[Test]
    public function aRateLimiterWiredToTheWrongConnectionFailsImmediately(): void
    {
        foreach (['auth_attempt', 'auth_lockout'] as $table) {
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
     * Individual partitions are storage, not schema, and no principal needs a
     * privilege on one: permissions are checked against the table named in the
     * query, and routing an INSERT to a partition does not consult the
     * partition's ACL. They are asserted empty because ALTER DEFAULT PRIVILEGES
     * would have granted them, which is the same defect V-10 describes, wearing
     * a relkind nobody checks.
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
            }
        }
    }
}
