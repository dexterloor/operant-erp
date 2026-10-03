<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Platform\Tenancy\DatabaseRoles;
use App\Platform\Tenancy\SchemaManifest;
use Doctrine\DBAL\ArrayParameterType;
use PHPUnit\Framework\Attributes\Test;

/**
 * Assertions against the live catalogue, never against the migration source.
 *
 * Reading our own migration back would only prove we can read what we wrote.
 * Everything here is answered by pg_class / pg_policy / pg_roles / pg_index.
 */
final class SchemaGuardTest extends TenancyTestCase
{
    /** ADR-002 §C-1 / R-13. A role with BYPASSRLS makes every policy here inert. */
    #[Test]
    public function neitherApplicationRoleCarriesBypassRlsOrSuperuser(): void
    {
        $roles = DatabaseRoles::APPLICATION_ROLES;
        $rows = $this->owner()->fetchAllAssociative(
            'SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname IN (?) ORDER BY rolname',
            [$roles],
            [ArrayParameterType::STRING],
        );

        self::assertCount(\count($roles), $rows,
            'Every role in DatabaseRoles::APPLICATION_ROLES must exist: expected '
            .implode(', ', $roles).', found '.implode(', ', array_column($rows, 'rolname')));
        foreach ($rows as $r) {
            self::assertFalse($r['rolsuper'], $r['rolname'].' is a superuser -- it bypasses row security unconditionally.');
            self::assertFalse($r['rolbypassrls'], $r['rolname'].' carries BYPASSRLS -- every policy is inert for it.');
        }
    }

    /**
     * No principal can create roles or databases (V3-8(a), M-8; V4 batch B6, 2026-09-16).
     *
     * The local owner was created `CREATEDB CREATEROLE`, which no migration, test,
     * `bin/` script or `src/` class uses. CREATEROLE cannot confer BYPASSRLS, but it
     * can mint a login role with a password, which is a new principal nobody
     * reviewed under ARCHITECTURE.md §3.3. `infra/postgres/init/10-roles.sh` now
     * creates the owner `NOCREATEDB NOCREATEROLE`, and migrations run unchanged.
     *
     * The owner is identified by asking the owner connection who it is, never by a
     * literal. One assertion per role and attribute, so a planted
     * `ALTER ROLE … CREATEROLE` / `CREATEDB` on any of the six names its row.
     *
     * **Local only.** On Neon the console owner is believed to hold both through
     * `neon_superuser`; that is measured at G-3 (V3-8(b)), not here.
     */
    #[Test]
    public function noPrincipalCanCreateRolesOrDatabases(): void
    {
        $owner = (string) $this->owner()->fetchOne('SELECT current_user');
        $roles = [...DatabaseRoles::APPLICATION_ROLES, $owner];

        $rows = $this->owner()->fetchAllAssociative(
            'SELECT rolname, rolcreaterole, rolcreatedb FROM pg_roles WHERE rolname IN (?) ORDER BY rolname',
            [$roles],
            [ArrayParameterType::STRING],
        );

        self::assertCount(\count($roles), $rows,
            'Every application role and the owner must exist: expected '.implode(', ', $roles)
            .', found '.implode(', ', array_column($rows, 'rolname')));
        foreach ($rows as $r) {
            self::assertFalse($r['rolcreaterole'], $r['rolname'].' holds CREATEROLE -- it can mint a login role nobody reviewed.');
            self::assertFalse($r['rolcreatedb'], $r['rolname'].' holds CREATEDB -- it can create a database outside every policy in this schema.');
        }
    }

    /**
     * The list is not trusted; it is reconciled.
     *
     * D-9's blocking condition is that the guards above must derive from a role
     * list rather than from `['erp_app', 'erp_ai']`. A list alone only moves the
     * hardcoding one file to the left: a role created in
     * infra/postgres/init/10-roles.sh and never added to DatabaseRoles would be
     * just as invisible as before. So the real set is read from pg_roles and the
     * two must agree exactly.
     *
     * The owner is excluded by name rather than by attribute: it is the one
     * login role that legitimately owns the schema, and it is identified by
     * asking the owner connection who it is rather than by trusting a constant.
     */
    #[Test]
    public function theRoleListCoversEveryLoginRoleInTheDatabase(): void
    {
        $owner = (string) $this->owner()->fetchOne('SELECT current_user');

        $actual = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT rolname FROM pg_roles
            WHERE rolcanlogin
              AND NOT rolsuper
              AND rolname <> ?
              AND rolname NOT LIKE 'pg\_%'
            ORDER BY 1
            SQL, [$owner]);

        $declared = DatabaseRoles::APPLICATION_ROLES;
        sort($declared);

        self::assertSame($declared, $actual,
            "The database's login roles and DatabaseRoles::APPLICATION_ROLES disagree. "
            .'Every guard in this class iterates that constant, so a role missing from it is '
            .'uncovered by the BYPASSRLS closure, the membership check and the grant manifest '
            .'-- which is exactly the condition ADR-006 attaches to landing new principals.');
    }

    /**
     * The direct attribute is not the whole question, and this is the exact
     * shape of ADR-002 §C-1.
     *
     * Role attributes are not inherited. Granting a BYPASSRLS role to erp_app
     * leaves pg_roles.rolbypassrls false, and erp_app still reads zero rows
     * without context -- so the attribute check stays green throughout.
     *
     * The escalation runs through SET ROLE, and it needs the reachable role to
     * hold BYPASSRLS *and* privileges on the table. Both halves measured, two
     * tenants seeded, one row each:
     *
     *   erp_app, no context                              ->  0 rows
     *   SET ROLE probe_bypass  (BYPASSRLS, no grants)    ->  ERROR: permission denied for table supplier
     *   SET ROLE probe_bypass  (BYPASSRLS + SELECT)      ->  2 rows -- every tenant
     *   pg_roles.rolbypassrls for erp_app, throughout    ->  false
     *
     * BYPASSRLS alone is inert; grants alone are RLS-bound; together they are
     * total. neon_superuser is exactly such a role, which is why ADR-002 §1
     * insists these roles are created in SQL and never through a console.
     *
     * Asserted over the whole membership closure, not the role row.
     */
    #[Test]
    public function neitherApplicationRoleCanReachBypassRlsThroughRoleMembership(): void
    {
        foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
            $reachable = $this->owner()->fetchAllAssociative(<<<'SQL'
                SELECT r.rolname, r.rolsuper, r.rolbypassrls
                FROM pg_roles r
                WHERE pg_has_role(?, r.oid, 'MEMBER')
                  AND (r.rolsuper OR r.rolbypassrls)
                ORDER BY 1
                SQL, [$role]);

            self::assertSame([], $reachable,
                $role.' can SET ROLE to something that bypasses row security: '.json_encode($reachable));
        }
    }

    /**
     * Simpler and stronger than chasing attributes: the roles belong to nothing.
     *
     * This is the assertion that actually holds the line, because the escalation
     * needs SET ROLE, and SET ROLE needs a membership. It is also the one that
     * survives a role whose grants change later: a membership that is harmless
     * today becomes an escalation the moment someone grants that role a table.
     *
     * Worth recording alongside it, for Output 2 rather than for this test:
     * SET ROLE leaks across clients on the pooled endpoint exactly as a bare SET
     * does. Measured -- client 1 issues SET ROLE and disconnects, client 2 opens
     * a brand new connection and lands on the same backend:
     *
     *   client 1: SET ROLE probe_role  ->  current_user=probe_role  pid=977
     *   client 2: (fresh connection)   ->  current_user=probe_role  pid=977
     *
     * and if that role is later dropped, every subsequent client on that backend
     * fails with "invalid role OID" until someone issues RESET ROLE. ADR-002 §C-3
     * names bare SET; this is the same defect with a privilege attached to it.
     */
    #[Test]
    public function theApplicationRolesHoldNoRoleMembershipsAtAll(): void
    {
        foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
            $memberships = $this->owner()->fetchFirstColumn(<<<'SQL'
                SELECT r.rolname FROM pg_roles r
                WHERE pg_has_role(?, r.oid, 'MEMBER') AND r.rolname <> ?
                ORDER BY 1
                SQL, [$role, $role]);

            self::assertSame([], $memberships,
                $role.' is a member of '.implode(', ', $memberships)
                .' -- ADR-002 §1 requires these roles to be created standalone in SQL, never through a '
                .'provider console, precisely because that route grants membership of a privileged role.');
        }
    }

    /**
     * SEC's finding, encoded at source.
     *
     * information_schema.table_privileges and role_table_grants resolve neither
     * PUBLIC grants nor role inheritance -- measured: after
     * GRANT INSERT ON stock_movement TO PUBLIC, both views returned zero rows
     * naming erp_ai while has_table_privilege('erp_ai', ..., 'INSERT') returned
     * true. Every privilege assertion in this suite uses has_table_privilege for
     * that reason; this one additionally forbids the grant that makes the views
     * lie, so the problem is caught where it is introduced.
     */
    #[Test]
    public function noTenantScopedTableGrantsAnythingToPublic(): void
    {
        $granted = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT c.relname AS tbl, a.privilege_type
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            CROSS JOIN LATERAL aclexplode(c.relacl) a
            WHERE n.nspname = 'public'
              AND a.grantee = 0
              AND EXISTS (SELECT 1 FROM information_schema.columns
                          WHERE table_name = c.relname AND column_name = 'tenant_id')
            ORDER BY 1, 2
            SQL);

        self::assertSame([], $granted,
            "PUBLIC holds privileges on a tenant-scoped table:\n".json_encode($granted, JSON_PRETTY_PRINT));
    }

    /**
     * ADR-002 "Verification" #4, the CI drift check. New tables default to RLS
     * off, so without this every future migration can silently ship an
     * unprotected table.
     *
     * One correction to the published query, found by executing it: it filters
     * `relkind='r'`. A partitioned table is relkind 'p', so a tenant-scoped
     * table partitioned for retention or volume would be invisible to the check
     * written to catch exactly that. Widened here and in SchemaManifestTest.
     */
    #[Test]
    public function everyTenantScopedTableHasRowLevelSecurityEnabledAndForced(): void
    {
        $unprotected = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname='public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition
              AND EXISTS (SELECT 1 FROM information_schema.columns
                          WHERE table_name = c.relname AND column_name = 'tenant_id')
              AND (NOT c.relrowsecurity OR NOT c.relforcerowsecurity)
            SQL);

        self::assertSame([], $unprotected, 'Tenant-scoped tables without ENABLE + FORCE row level security.');
    }

    #[Test]
    public function theDriftCheckIsActuallyLookingAtSomething(): void
    {
        // A guard that returns "all clear" against an empty set is not a guard.
        $tenantTables = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname='public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition
              AND EXISTS (SELECT 1 FROM information_schema.columns
                          WHERE table_name = c.relname AND column_name = 'tenant_id')
            ORDER BY 1
            SQL);

        self::assertSame(SchemaManifest::tenantScopedTables(), $tenantTables,
            'The set of tenant-scoped tables the guard actually inspects has drifted from the declared one. '
            .'A guard that returns "all clear" against a set it does not cover is worse than no guard.');
    }

    #[Test]
    public function everyTenantScopedTableHasBothAUsingAndAWithCheckPolicy(): void
    {
        $rows = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT c.relname AS tbl, p.polname,
                   pg_get_expr(p.polqual,      p.polrelid) AS using_expr,
                   pg_get_expr(p.polwithcheck, p.polrelid) AS check_expr
            FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' ORDER BY 1
            SQL);

        self::assertCount(\count(SchemaManifest::tenantScopedTables()), $rows);
        foreach ($rows as $r) {
            self::assertNotNull($r['using_expr'], $r['tbl'].' policy has no USING clause.');
            self::assertNotNull($r['check_expr'], $r['tbl'].' policy has no WITH CHECK clause -- writes are unguarded (ADR-002 §4).');
            // ADR-002 §2: "Never write current_setting(...) IS NULL OR ... -- that fails open."
            self::assertStringNotContainsStringIgnoringCase('is null', (string) $r['using_expr'], $r['tbl'].' USING clause fails open.');
            self::assertStringNotContainsStringIgnoringCase('is null', (string) $r['check_expr'], $r['tbl'].' WITH CHECK clause fails open.');
            self::assertStringNotContainsString('SELECT', (string) $r['using_expr'], 'ADR-002 §5 warns against sub-SELECTs in policies.');
        }
    }

    /**
     * R-15. ADR-002 §5: unique and primary key checks always bypass row security,
     * so any uniqueness that is not scoped by tenant is an oracle for another
     * tenant's data. Asserted over pg_index, with no allow-list: a bare unique
     * anywhere on a tenant-scoped table fails this.
     */
    #[Test]
    public function noTenantScopedTableHasAUniqueIndexThatDoesNotLeadWithTenantId(): void
    {
        $offenders = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT c.relname AS tbl, i.relname AS idx,
                   pg_get_indexdef(x.indexrelid) AS def
            FROM pg_index x
            JOIN pg_class c ON c.oid = x.indrelid
            JOIN pg_class i ON i.oid = x.indexrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND x.indisunique
              AND EXISTS (SELECT 1 FROM information_schema.columns
                          WHERE table_name = c.relname AND column_name = 'tenant_id')
              AND NOT x.indisprimary
              AND (SELECT a.attname FROM pg_attribute a
                   WHERE a.attrelid = c.oid AND a.attnum = x.indkey[0]) IS DISTINCT FROM 'tenant_id'
            ORDER BY 1, 2
            SQL);

        self::assertSame([], $offenders, "Unique index not led by tenant_id:\n".json_encode($offenders, JSON_PRETTY_PRINT));
    }

    /**
     * The single named exception to the rule above, and the reason it is named.
     *
     * ADR-002 §5's own shape -- "UNIQUE (tenant_id, id) on parent, to allow
     * composite FK" -- implies the primary key stays on `id` alone, and the ORM
     * needs a scalar identifier. A primary key is a unique index, so it is a
     * uniqueness that is not scoped by tenant, and integrity checks bypass row
     * security. A caller who can guess another tenant's row id can therefore
     * confirm it exists by attempting an INSERT with that id and reading the
     * constraint violation.
     *
     * That channel costs 122 bits of guessing per probe as long as ids stay
     * random and opaque, which is what this test pins. It stops being negligible
     * the moment any tenant-scoped table takes a sequential or derived key.
     * Flagged for Output 2 rather than silently accepted.
     */
    #[Test]
    public function theOnlyUniquenessNotScopedByTenantIsTheOpaqueSurrogatePrimaryKey(): void
    {
        $pks = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT c.relname AS tbl, i.relname AS idx, pg_get_indexdef(x.indexrelid) AS def,
                   (SELECT t.typname FROM pg_attribute a JOIN pg_type t ON t.oid = a.atttypid
                    WHERE a.attrelid = c.oid AND a.attnum = x.indkey[0]) AS keytype,
                   array_length(x.indkey, 1) AS cols
            FROM pg_index x
            JOIN pg_class c ON c.oid = x.indrelid
            JOIN pg_class i ON i.oid = x.indexrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND x.indisprimary
              AND EXISTS (SELECT 1 FROM information_schema.columns
                          WHERE table_name = c.relname AND column_name = 'tenant_id')
            ORDER BY 1
            SQL);

        self::assertCount(\count(SchemaManifest::tenantScopedTables()), $pks,
            'Derived from the manifest, not written down: bumping a literal here is the path of least '
            .'resistance and it silently stops covering the table that was added.');
        foreach ($pks as $pk) {
            self::assertSame(1, (int) $pk['cols'], $pk['tbl'].' primary key is not a single column.');
            self::assertSame('uuid', $pk['keytype'],
                $pk['tbl'].' primary key is not an opaque UUID -- a guessable key turns this index into an enumerable oracle (R-15).');
        }
    }

    #[Test]
    public function tenantIdLeadsEveryIndexOnATenantScopedTable(): void
    {
        // ADR-002 §5: "tenant_id leads every composite index". Single-column
        // indexes are out of scope by definition; the only one on these tables
        // is the surrogate primary key, covered by the test above.
        $offenders = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT c.relname AS tbl, i.relname AS idx, pg_get_indexdef(x.indexrelid) AS def
            FROM pg_index x
            JOIN pg_class c ON c.oid = x.indrelid
            JOIN pg_class i ON i.oid = x.indexrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND array_length(x.indkey, 1) > 1
              AND EXISTS (SELECT 1 FROM information_schema.columns
                          WHERE table_name = c.relname AND column_name = 'tenant_id')
              AND (SELECT a.attname FROM pg_attribute a
                   WHERE a.attrelid = c.oid AND a.attnum = x.indkey[0]) IS DISTINCT FROM 'tenant_id'
            ORDER BY 1, 2
            SQL);

        self::assertSame([], $offenders, "Index not led by tenant_id:\n".json_encode($offenders, JSON_PRETTY_PRINT));
    }

    /**
     * ADR-002 §5's composite foreign key, read back from the catalogue.
     *
     * W0-a wrote this as a bare `fetchOne` over every foreign key on
     * `inventory_item`, which was unambiguous while there was exactly one. There
     * are now four, `pg_constraint` has no inherent order, and the test passed
     * or failed depending on physical row order -- green three runs in a row and
     * red on the first run against a freshly built database. Fixed by naming the
     * constraint and asserting the whole set, so a foreign key appearing or
     * vanishing is a failure rather than a coin toss.
     */
    #[Test]
    public function theSupplierForeignKeyIsCompositeAndTenantScoped(): void
    {
        $keys = $this->owner()->fetchAllKeyValue(<<<'SQL'
            SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint
            WHERE conrelid = 'inventory_item'::regclass AND contype = 'f'
            ORDER BY conname
            SQL);

        self::assertSame([
            // ADR-002 §5's shape: the tenant travels in the key itself, so a
            // parent from another tenant is refused by Postgres rather than by
            // whatever the ORM believes.
            'inventory_item_currency_fk' => 'FOREIGN KEY (currency_code) REFERENCES currency(code)',
            'inventory_item_supplier_fk' => 'FOREIGN KEY (tenant_id, supplier_id) REFERENCES supplier(tenant_id, id)',
            // Reference data (ADR-002 §6) is never the tenant side of a key.
            'inventory_item_tenant_fk' => 'FOREIGN KEY (tenant_id) REFERENCES tenant(id)',
            'inventory_item_uom_fk' => 'FOREIGN KEY (unit_of_measure_code) REFERENCES unit_of_measure(code)',
        ], $keys);
    }

    /**
     * Extension from RED via LEAD, test 5. An unpinned SECURITY DEFINER function
     * callable by PUBLIC runs with the owner's rights and is a cross-tenant
     * disclosure path. Nothing in this schema needs one, so the strongest and
     * simplest assertion is that none exists.
     *
     * **Widened 2026-10-03 (roles C0, `docs/session13/preauth-derived-check-design.md` §3.6) from `public` to every
     * namespace, functions whose owner is not a superuser** — the owner owns the database and could create a schema, and a
     * definer function there would run with its rights all the same. Measured before widening: 0 such functions anywhere.
     */
    #[Test]
    public function theSchemaContainsNoSecurityDefinerObjects(): void
    {
        $rows = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT n.nspname, p.proname, pg_get_userbyid(p.proowner) AS owner, p.proconfig
            FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            JOIN pg_roles o ON o.oid = p.proowner
            WHERE NOT o.rolsuper AND p.prosecdef
            ORDER BY 1
            SQL);

        self::assertSame([], $rows, "SECURITY DEFINER objects found:\n".json_encode($rows, JSON_PRETTY_PRINT));
    }

    /**
     * **No object runs a write with the owner's rights on an application role's behalf** — no view, no materialized view,
     * no rule, no foreign key with a referential action (roles C0, 2026-10-03; `docs/session13/preauth-derived-check-design.md`
     * §3.6). Each is a route around every grant and every RLS policy on the tables it touches: an owner-owned view without
     * `security_invoker` and a rule's action run as their owner, `REFRESH MATERIALIZED VIEW` runs the defining query as the
     * view's owner (and Postgres 17's `MAINTAIN` lets a non-owner trigger it), and a referential action runs as the
     * referencing table's owner. `PreAuthPrincipalTest`'s grant check on `app_user.deleted_at` cannot see any of them, because
     * the application role's own column privilege stays false; this sweep is what makes that check whole. Its sibling for
     * `SECURITY DEFINER` functions is the method above.
     *
     * **Domain: every namespace, objects whose owner is not a superuser** — so `pg_catalog`, `information_schema` and the
     * bootstrap-owned objects are out, and any schema the owner could create (it owns the database) is in. **Why "none at
     * all", not "none without `security_invoker`":** none exists, forbidding the kind needs no parsing of `reloptions`, and it
     * mirrors the definer sweep. The commit that adds the first view narrows this to `security_invoker` and measures how
     * `reloptions` stores it then. A new referential action must first show it cannot write an owner-only column.
     *
     * **Positive controls in the same test:** five throwaway `zz_*` plants in one owner transaction (no lock on a real table),
     * each named by the sweep, then rolled back and the sweep asserted empty again. **On a hosted cluster** a provider-owned
     * extension schema whose owner is not `rolsuper` may turn this red: that is G-3's measurement, not a reason to narrow it.
     */
    #[Test]
    public function theSchemaContainsNoOwnerPrivilegedIndirection(): void
    {
        $owner = $this->owner();
        $sweep = static fn (): array => [
            'views' => $owner->fetchFirstColumn(<<<'SQL'
                SELECT n.nspname || '.' || c.relname || ' (' || c.relkind::text || ')'
                FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace JOIN pg_roles o ON o.oid = c.relowner
                WHERE NOT o.rolsuper AND c.relkind IN ('v', 'm')
                ORDER BY 1
                SQL),
            'rules' => $owner->fetchFirstColumn(<<<'SQL'
                SELECT n.nspname || '.' || c.relname || ' ' || r.rulename
                FROM pg_rewrite r JOIN pg_class c ON c.oid = r.ev_class JOIN pg_namespace n ON n.oid = c.relnamespace
                JOIN pg_roles o ON o.oid = c.relowner
                WHERE NOT o.rolsuper AND NOT (r.ev_type = '1' AND r.rulename = '_RETURN')
                ORDER BY 1
                SQL),
            'referential_actions' => $owner->fetchFirstColumn(<<<'SQL'
                SELECT n.nspname || '.' || c.relname || ' ' || k.conname
                FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid JOIN pg_namespace n ON n.oid = c.relnamespace
                JOIN pg_roles o ON o.oid = c.relowner
                WHERE k.contype = 'f' AND NOT o.rolsuper AND NOT (k.confdeltype = 'a' AND k.confupdtype = 'a')
                ORDER BY 1
                SQL),
        ];
        $empty = ['views' => [], 'rules' => [], 'referential_actions' => []];

        self::assertSame($empty, $sweep(),
            'An object runs writes or reads with its owner\'s rights, around every grant and RLS policy: a view, a materialized '
            .'view, a rule or a referential action. Narrow this sweep deliberately (security_invoker; a proof the action writes '
            .'no owner-only column) in the commit that adds it.');

        $owner->beginTransaction();
        try {
            foreach ([
                'CREATE VIEW zz_view_probe AS SELECT 1 AS one',
                'CREATE MATERIALIZED VIEW zz_mat_probe AS SELECT 1 AS one',
                'CREATE TABLE zz_rule_probe ()',
                'CREATE RULE zz_r AS ON UPDATE TO zz_rule_probe DO ALSO NOTHING',
                'CREATE TABLE zz_fk_parent (id int PRIMARY KEY)',
                'CREATE TABLE zz_fk_child (p int CONSTRAINT zz_fk_child_p_fk REFERENCES zz_fk_parent ON DELETE CASCADE)',
                'CREATE SCHEMA zz_schema_probe',
                'CREATE VIEW zz_schema_probe.v AS SELECT 1 AS one',
            ] as $plant) {
                $owner->executeStatement($plant);
            }
            $planted = $sweep();
            foreach (['public.zz_view_probe (v)', 'public.zz_mat_probe (m)', 'zz_schema_probe.v (v)'] as $name) {
                self::assertContains($name, $planted['views'], 'A planted '.$name.' was not seen, so the empty sweep proves nothing.');
            }
            self::assertContains('public.zz_rule_probe zz_r', $planted['rules'], 'A planted rule was not seen.');
            self::assertContains('public.zz_fk_child zz_fk_child_p_fk', $planted['referential_actions'], 'A planted ON DELETE CASCADE was not seen.');
        } finally {
            $owner->rollBack();
        }

        self::assertSame($empty, $sweep(), 'A planted object survived the rollback.');
    }

    /**
     * Extension from RED via LEAD, test 2 -- erp_ai must not have gained write
     * rights. Derived over every table in the schema rather than three of them,
     * and stated as the property that survives the schema growing: the query
     * agent writes nothing, anywhere, ever.
     *
     * What it can *read* is deliberately not "everything": app_user and
     * audit_log are withheld outright rather than column-scoped, because a
     * grant that excludes password_hash today is one widening away from a
     * model-driven query reading it. GrantFootprintTest asserts that footprint
     * exactly; this asserts the half that must never change.
     */
    #[Test]
    public function theAgentRoleCanWriteNowhereInTheSchema(): void
    {
        foreach (SchemaManifest::allTables() as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] as $priv) {
                self::assertFalse((bool) $this->owner()->fetchOne(
                    'SELECT has_table_privilege(?, ?, ?)', [DatabaseRoles::AI, $table, $priv]),
                    "erp_ai holds $priv on $table");
            }
            foreach (['INSERT', 'UPDATE', 'REFERENCES'] as $priv) {
                self::assertFalse((bool) $this->owner()->fetchOne(
                    'SELECT has_any_column_privilege(?, ?, ?)', [DatabaseRoles::AI, $table, $priv]),
                    "erp_ai holds a column-level $priv on $table");
            }
        }

        // Not vacuous: it really can read the business tables it exists for.
        self::assertTrue((bool) $this->owner()->fetchOne(
            'SELECT has_table_privilege(?, ?, ?)', [DatabaseRoles::AI, 'inventory_item', 'SELECT']));
    }
}
