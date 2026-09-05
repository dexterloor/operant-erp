<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

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
        $rows = $this->owner()->fetchAllAssociative(
            'SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname IN (?, ?) ORDER BY rolname',
            ['erp_ai', 'erp_app'],
        );

        self::assertCount(2, $rows, 'Both ADR-002 §1 application roles must exist.');
        foreach ($rows as $r) {
            self::assertFalse($r['rolsuper'], $r['rolname'].' is a superuser -- it bypasses row security unconditionally.');
            self::assertFalse($r['rolbypassrls'], $r['rolname'].' carries BYPASSRLS -- every policy is inert for it.');
        }
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
        foreach (['erp_app', 'erp_ai'] as $role) {
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
        foreach (['erp_app', 'erp_ai'] as $role) {
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
     * ADR-002 "Verification" #4, the CI drift check, verbatim. New tables default
     * to RLS off, so without this every future migration can silently ship an
     * unprotected table.
     */
    #[Test]
    public function everyTenantScopedTableHasRowLevelSecurityEnabledAndForced(): void
    {
        $unprotected = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname='public' AND c.relkind='r'
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
            WHERE n.nspname='public' AND c.relkind='r'
              AND EXISTS (SELECT 1 FROM information_schema.columns
                          WHERE table_name = c.relname AND column_name = 'tenant_id')
            ORDER BY 1
            SQL);

        self::assertSame(['inventory_item', 'stock_movement', 'supplier'], $tenantTables);
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

        self::assertCount(3, $rows);
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

        self::assertCount(3, $pks);
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

    /** ADR-002 §5's composite foreign key, read back from the catalogue. */
    #[Test]
    public function theSupplierForeignKeyIsCompositeAndTenantScoped(): void
    {
        $def = $this->owner()->fetchOne(<<<'SQL'
            SELECT pg_get_constraintdef(oid) FROM pg_constraint
            WHERE conrelid = 'inventory_item'::regclass AND contype = 'f'
            SQL);

        self::assertSame(
            'FOREIGN KEY (tenant_id, supplier_id) REFERENCES supplier(tenant_id, id)',
            $def,
        );
    }

    /**
     * Extension from RED via LEAD, test 5. An unpinned SECURITY DEFINER function
     * callable by PUBLIC runs with the owner's rights and is a cross-tenant
     * disclosure path. Nothing in this schema needs one, so the strongest and
     * simplest assertion is that none exists.
     */
    #[Test]
    public function theSchemaContainsNoSecurityDefinerObjects(): void
    {
        $rows = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT p.proname, pg_get_userbyid(p.proowner) AS owner, p.proconfig
            FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.prosecdef
            ORDER BY 1
            SQL);

        self::assertSame([], $rows, "SECURITY DEFINER objects found:\n".json_encode($rows, JSON_PRETTY_PRINT));
    }

    /** Extension from RED via LEAD, test 2 -- erp_ai must not have gained write rights. */
    #[Test]
    public function theAgentRoleHoldsSelectOnlyOnTenantTables(): void
    {
        foreach (['supplier', 'inventory_item', 'stock_movement'] as $table) {
            self::assertTrue((bool) $this->owner()->fetchOne(
                'SELECT has_table_privilege(?, ?, ?)', ['erp_ai', $table, 'SELECT']), "erp_ai cannot SELECT $table");
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $priv) {
                self::assertFalse((bool) $this->owner()->fetchOne(
                    'SELECT has_table_privilege(?, ?, ?)', ['erp_ai', $table, $priv]), "erp_ai holds $priv on $table");
            }
        }
    }
}
