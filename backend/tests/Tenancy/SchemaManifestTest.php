<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Platform\Tenancy\SchemaManifest;
use PHPUnit\Framework\Attributes\Test;

/**
 * The manifest and the catalogue, reconciled in both directions.
 *
 * W0-a's guards read the catalogue and asserted properties of whatever they
 * found. That works while every table is tenant-scoped and stops working the
 * moment one is not: a table with no `tenant_id` gets no RLS, so a
 * catalogue-only check has nothing to say about it, which is exactly the shape
 * of ADR-006 V-10 / ARCHITECTURE S-13. Intent has to be written down somewhere,
 * and then held to.
 *
 * Every test here fails if a migration adds something the manifest does not
 * declare, and fails if the manifest declares something no migration built.
 */
final class SchemaManifestTest extends TenancyTestCase
{
    /**
     * @return list<string> every base and partitioned table in `public`,
     *                      excluding individual partitions, which are storage
     *                      rather than schema
     */
    private function catalogueTables(): array
    {
        return $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relkind IN ('r', 'p')
              AND NOT c.relispartition
            ORDER BY 1
            SQL);
    }

    #[Test]
    public function everyTableInTheDatabaseIsDeclaredInTheManifestAndViceVersa(): void
    {
        self::assertSame(SchemaManifest::allTables(), $this->catalogueTables(),
            'A table exists that SchemaManifest does not declare, or the manifest declares one that does not '
            .'exist. Both matter: an undeclared table has no stated owning module (S-7a) and no stated grants '
            .'(V-10), and a declared-but-absent one means the grant assertions below are passing vacuously.');
    }

    #[Test]
    public function theTenantFlagAgreesWithThePresenceOfATenantIdColumn(): void
    {
        $withTenantColumn = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'tenant_id' AND a.attnum > 0 AND NOT a.attisdropped
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition
            ORDER BY 1
            SQL);

        self::assertSame(SchemaManifest::tenantScopedTables(), $withTenantColumn,
            'A table carries a tenant_id column without being declared tenant-scoped, or the other way round. '
            .'ARCHITECTURE S-8 anticipates the first case for messenger_messages -- M-6 makes tenant_id a real '
            .'column there while the queue stays outside RLS -- and it has to be a declaration, not an accident.');
    }

    /**
     * ADR-002 "Verification" #4, but driven by the manifest rather than by the
     * presence of a column, and covering partitioned tables.
     *
     * The published query filters `relkind='r'`. A partitioned table is
     * relkind 'p', so a tenant-scoped table that was partitioned for retention
     * or volume would be invisible to the check that exists to catch exactly
     * that mistake. `auth_attempt` is partitioned here, which is how it surfaced.
     */
    #[Test]
    public function everyTenantScopedTableHasRowLevelSecurityEnabledAndForced(): void
    {
        $state = $this->owner()->fetchAllKeyValue(<<<'SQL'
            SELECT c.relname, (c.relrowsecurity AND c.relforcerowsecurity)::text
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition
            SQL);

        $unprotected = [];
        foreach (SchemaManifest::tenantScopedTables() as $table) {
            if ('true' !== ($state[$table] ?? null)) {
                $unprotected[] = $table;
            }
        }

        self::assertSame([], $unprotected,
            'Tenant-scoped tables without ENABLE + FORCE row level security: '.implode(', ', $unprotected));
    }

    #[Test]
    public function noNonTenantTableCarriesAPolicyAndEveryTenantScopedOneCarriesExactlyOne(): void
    {
        $policies = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT c.relname AS tbl, p.polname,
                   pg_get_expr(p.polqual,      p.polrelid) AS using_expr,
                   pg_get_expr(p.polwithcheck, p.polrelid) AS check_expr
            FROM pg_policy p
            JOIN pg_class c ON c.oid = p.polrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
            ORDER BY 1
            SQL);

        self::assertSame(SchemaManifest::tenantScopedTables(), array_column($policies, 'tbl'));

        $expected = "(tenant_id = (NULLIF(current_setting('app.tenant_id'::text, true), ''::text))::uuid)";
        foreach ($policies as $p) {
            // Compared as text against the expression Postgres reports, not
            // against a substring: A1.2's correction is the difference between
            // failing closed and raising 22P02 on every query, and a looser
            // assertion would accept either.
            self::assertSame($expected, $p['using_expr'], $p['tbl'].' USING clause is not the A1.2 expression.');
            self::assertSame($expected, $p['check_expr'], $p['tbl'].' WITH CHECK clause is not the A1.2 expression.');
        }
    }

    /**
     * D-12 / A1.8, asserted structurally. The behavioural half -- that a UUIDv7
     * is actually refused -- is DeletionPolicyTest's neighbour in
     * UuidVersionCheckTest, because a constraint that exists and does not fire
     * is the failure mode worth catching.
     */
    #[Test]
    public function everyTenantScopedTablePinsItsPrimaryKeyToUuidVersionFour(): void
    {
        $checks = $this->owner()->fetchAllKeyValue(<<<'SQL'
            SELECT c.relname, pg_get_constraintdef(con.oid)
            FROM pg_constraint con
            JOIN pg_class c ON c.oid = con.conrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND con.contype = 'c' AND con.conname = c.relname || '_id_is_uuid_v4'
            SQL);

        foreach (SchemaManifest::tenantScopedTables() as $table) {
            self::assertArrayHasKey($table, $checks,
                $table.' has no CHECK pinning its id to UUID version 4. Asserting the column type is `uuid` is '
                .'not enough: UUIDv7 is also type uuid, is time-ordered and is a fashionable choice for index '
                .'locality, so a future performance change would reopen the enumeration channel silently.');
            self::assertStringContainsString("'4'::text", $checks[$table]);
        }
    }

    /**
     * ADR-002 §5 and ADR-007 §1 together, and the trap ADR-007 names: the
     * composite foreign key's target must stay total while business uniqueness
     * goes partial.
     */
    #[Test]
    public function theCompositeForeignKeyTargetIsTotalOnEveryTenantScopedTable(): void
    {
        $total = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname
            FROM pg_index x
            JOIN pg_class c ON c.oid = x.indrelid
            JOIN pg_class i ON i.oid = x.indexrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND x.indisunique
              AND x.indpred IS NULL
              AND i.relname = c.relname || '_tenant_id_id_key'
            ORDER BY 1
            SQL);

        self::assertSame(SchemaManifest::tenantScopedTables(), $total,
            'UNIQUE (tenant_id, id) must exist and must be TOTAL on every tenant-scoped table. A composite '
            .'foreign key cannot target a partial index, and a child legitimately references a soft-deleted '
            .'parent -- that is the entire point of soft deletion.');
    }

    #[Test]
    public function everyTenantScopedTableReferencesTheTenantRegistry(): void
    {
        $keys = $this->owner()->fetchAllKeyValue(<<<'SQL'
            SELECT c.relname, pg_get_constraintdef(con.oid)
            FROM pg_constraint con
            JOIN pg_class c ON c.oid = con.conrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND con.contype = 'f' AND con.conname = c.relname || '_tenant_fk'
            SQL);

        foreach (SchemaManifest::tenantScopedTables() as $table) {
            self::assertSame('FOREIGN KEY (tenant_id) REFERENCES tenant(id)', $keys[$table] ?? null,
                $table.' does not reference the tenant registry. Without it, set_config with any well-formed '
                .'UUID creates rows for a tenant that was never provisioned, and a replayed message for a '
                .'closed tenant runs to completion against an empty world (ARCHITECTURE O-8) instead of failing.');
        }
    }

    /** ADR-007: the marker is `deleted_at`, and only where history exists. */
    #[Test]
    public function deletedAtExistsOnExactlyTheSoftDeletedTables(): void
    {
        $withColumn = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'deleted_at' AND a.attnum > 0 AND NOT a.attisdropped
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition
            ORDER BY 1
            SQL);

        self::assertSame(SchemaManifest::softDeletedTables(), $withColumn);
    }

    /**
     * ADR-007 §1's sharp edge, checked as an invariant rather than table by
     * table: on a soft-deletable table every unique index other than the
     * primary key and the composite foreign key target is partial, and on a
     * table that is not soft-deletable none of them is.
     */
    #[Test]
    public function businessUniquenessIsPartialOnSoftDeletedTablesAndTotalElsewhere(): void
    {
        $rows = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT c.relname AS tbl, i.relname AS idx, x.indpred IS NOT NULL AS partial,
                   pg_get_expr(x.indpred, x.indrelid) AS pred
            FROM pg_index x
            JOIN pg_class c ON c.oid = x.indrelid
            JOIN pg_class i ON i.oid = x.indexrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND x.indisunique
              AND NOT x.indisprimary
              AND i.relname <> c.relname || '_tenant_id_id_key'
            ORDER BY 1, 2
            SQL);

        $soft = SchemaManifest::softDeletedTables();
        $wrong = [];

        foreach ($rows as $r) {
            if (!\in_array($r['tbl'], SchemaManifest::tenantScopedTables(), true)) {
                continue;
            }
            $shouldBePartial = \in_array($r['tbl'], $soft, true);
            $isPartial = (bool) $r['partial'];

            if ($shouldBePartial !== $isPartial) {
                $wrong[] = $r['idx'].' on '.$r['tbl'].($isPartial ? ' is partial and should not be' : ' is total and should be partial');
            } elseif ($isPartial) {
                self::assertSame('(deleted_at IS NULL)', $r['pred'],
                    $r['idx'].' is partial on something other than the deletion marker.');
            }
        }

        self::assertSame([], $wrong, implode("\n", $wrong)
            ."\nADR-007 §1: a soft-deleted row still occupies a total unique constraint, so \"delete a product, "
            .'re-create it with the same SKU\" fails -- a routine ERP operation colliding with the constraint '
            .'that carries the tenant-isolation property.');
    }

    /** ARCHITECTURE S-7(a): exactly one module writes each table, and it is named. */
    #[Test]
    public function everyTableNamesAnOwningModuleAndAReason(): void
    {
        foreach (SchemaManifest::TABLES as $table => $spec) {
            self::assertNotSame('', $spec['module'], $table.' declares no owning module.');
            self::assertGreaterThan(40, \strlen($spec['why']),
                $table.' declares no usable reason. V-10 asks for the owning module and the intent, not a label.');
            self::assertContains($spec['deletion'],
                [SchemaManifest::SOFT, SchemaManifest::HARD, SchemaManifest::NONE, SchemaManifest::NA],
                $table.' has an unknown deletion class.');
        }
    }
}
