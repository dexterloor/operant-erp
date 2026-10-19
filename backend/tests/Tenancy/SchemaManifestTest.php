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
     *
     * **One ruled exception, allowed BY NAME and BY EXACT PREDICATE** (sales step 6,
     * C2): `invoice_tenant_fulfilment_not_void` — at most one LIVE invoice per
     * shipment (D-A; `API.md` §4.2 `IssueInvoice`). Its predicate is a *lifecycle*
     * predicate on a deletion-class-`none` table, which ADR-007 §1 (written for
     * soft-deleted tables) does not address; this test is stricter than the ADR.
     * **The allowance is this test's own literal**, deliberately not a constant
     * shared with `IdempotencyContractTest`'s: one constant read by both checks is
     * one source, and a wrong entry would pass both (`ARCH` §7.1). A second partial
     * unique on `invoice`, or this one with any other predicate, is still red.
     *
     * **A second ruled exception, on the same terms** (roles slice 3, C1; design F-10, R-7's by-name exception):
     * `role_grant_proposal_pending_unique` — at most one PENDING proposal per (user, role) on the class-`none`
     * `role_grant_proposal`, predicate `(status = 'pending'::text)` measured off the catalogue (M-3).
     *
     * **A third, on the same terms** (G-29 / G-30 part 3, C1, 2026-10-05): `adjustment_decision_one_distinct_per_round`,
     * predicate `(kind = 'distinct'::text)` — the database backstop for "one live `distinct` per pair" (design §2.4).
     *
     * **A fourth, on the same terms** (customers slice 2 C0, 2026-10-19; owner ruling Q-1 (a), `s35-ruling.md`):
     * `customer_contact_one_primary`, predicate `is_primary` (measured off the catalogue: a bare boolean column prints
     * without parentheses) — at most one primary contact per customer on the class-`hard` `customer_contact`. A business
     * rule, not a live-row filter: the table has no `deleted_at`.
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
        // index => its exact normalised predicate (`pg_get_expr(indpred, indrelid)`, measured at C2: M-5).
        $lifecyclePartials = [
            'invoice_tenant_fulfilment_not_void' => "(status <> 'void'::text)",
            // Roles slice 3 C1 (`roles-s3-design.md` F-10; `DATA` §1.4): one PENDING proposal per (user, role).
            'role_grant_proposal_pending_unique' => "(status = 'pending'::text)",
            // G-29 / G-30 part 3 C1 (design §2.4, RED d1d4's D2 note): one `distinct` per pair per decision round on the
            // class-`none`, append-only `adjustment_decision`; a reversed `distinct` keeps its round, so the next is round + 1.
            'adjustment_decision_one_distinct_per_round' => "(kind = 'distinct'::text)",
            // Customers slice 2 C0 (owner Q-1 (a)): one primary contact per customer on the class-`hard` customer_contact.
            'customer_contact_one_primary' => 'is_primary',
        ];

        foreach ($rows as $r) {
            if (!\in_array($r['tbl'], SchemaManifest::tenantScopedTables(), true)) {
                continue;
            }
            if (\array_key_exists($r['idx'], $lifecyclePartials) && !\in_array($r['tbl'], $soft, true)) {
                self::assertSame($lifecyclePartials[$r['idx']], $r['pred'],
                    $r['idx'].' is allowed partial on exactly one lifecycle predicate, and carries another.');
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

    /**
     * ADR-007 §A1.3 — a declared `retention_column` resolves to a real
     * `timestamptz NOT NULL` column on the declaring table. E16-1 clause 1's
     * second half: *"or the declared column is absent from that table"*.
     *
     * **Domain from the server**, via `pg_attribute`, mirroring
     * {@see self::deletedAtExistsOnExactlyTheSoftDeletedTables} — and
     * `pg_attribute` rather than `information_schema` for a measured reason:
     * `information_schema` has no `relispartition` equivalent, and **two of the
     * three declaring tables are partitioned**, so the whole domain of this check
     * is tables `information_schema` reports differently. (The project rule that
     * `information_schema` is not an authority is about *privileges*; column
     * existence is uncontested there. This is a partitioning concern, not that.)
     *
     * **Mutations, ALL FIVE RUN 2026-09-18 (Move 219), not predicted:**
     *  1. `auth_lockout` → `'last_failure'` (absent column) → **RED**.
     *  2. `auth_attempt` → `'attempt_count'` (integer, NOT NULL) → **RED**, and
     *     the message was read, not assumed: *"auth_attempt.attempt_count is
     *     integer, and ADR-007 A1.3 requires timestamptz."*
     *  3. `audit_log` given `occurred_at` → **RED** on the sibling tripwire.
     *  4. the key mistyped `retention_colum` → **RED** on the unknown-key method.
     *  5. all three declarations deleted → **RED on the anti-vacuity control**,
     *     message read: *"No table declares a retention_column, so this test
     *     proves nothing."* This is the mutation that matters most, because it is
     *     the one a fake-green version of this check would survive.
     * Restored → **GREEN**. *(Why every one was executed: the tripwire built
     * earlier the same day was FAKE-GREEN on its first run while its docblock
     * already claimed a mutation proof. A predicted mutation is not a mutation.)*
     *
     * **What this deliberately does NOT catch:** declaring the *wrong but real*
     * column — `auth_lockout` → `locked_until` resolves and passes. This check
     * proves resolution, never that the choice is the right predicate. Nothing
     * today can prove the latter, because no purge exists to predicate.
     */
    #[Test]
    public function everyDeclaredRetentionColumnIsATimestamptzNotNullColumnOnItsTable(): void
    {
        $declared = SchemaManifest::retentionColumns();

        self::assertNotSame([], $declared,
            'No table declares a retention_column, so this test proves nothing. '
            .'If that is deliberate, delete this method; if a key was mistyped, the declaring set is silently empty '
            .'and every assertion below is vacuous. Nothing else enforces the manifest key shape -- there is no PHPStan in this repo.');

        $wrong = [];
        foreach ($declared as $table => $column) {
            $found = $this->owner()->fetchAllAssociative(<<<'SQL'
                SELECT a.atttypid::regtype::text AS type, a.attnotnull AS notnull
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = ? AND a.attnum > 0 AND NOT a.attisdropped
                WHERE n.nspname = 'public' AND c.relname = ? AND c.relkind IN ('r', 'p') AND NOT c.relispartition
                SQL, [$column, $table]);

            if ([] === $found) {
                $wrong[] = $table.'.'.$column.' is declared as its retention_column and does not exist on the table.';
                continue;
            }

            if ('timestamp with time zone' !== $found[0]['type']) {
                $wrong[] = $table.'.'.$column.' is '.$found[0]['type'].', and ADR-007 A1.3 requires timestamptz.';
            }

            if (!$found[0]['notnull']) {
                $wrong[] = $table.'.'.$column.' is nullable; a purge predicate on a nullable column silently skips rows.';
            }
        }

        self::assertSame([], $wrong, implode("\n", $wrong));
    }

    /**
     * The other half of E16-1, and the reason the key exists at all: two tables
     * are **affirmatively refused** a `retention_column`, and until the key
     * existed that refusal could not be checked.
     *
     * - **`audit_log`** — `PO`'s ruling of 2026-09-07: no age-based purge, ever.
     *   `ADR-007` §A1.3's table still declares `occurred_at` for it; that row was
     *   superseded the same day (the ruling *"adopts §A1.3's loop and supersedes
     *   §A1.3's predicate"*) and its removal is **F-18, owed by `ARCH`**. The
     *   lifecycle purge predicates on `tenant_id`, a `uuid`, which cannot be the
     *   `timestamptz NOT NULL` column this key is defined as naming.
     * - **`idempotency_key`** — same refusal, already written in its own `why`:
     *   *"a retention_column on a table with no authorised age purge is a
     *   declaration with no catalogue referent"*.
     *
     * This method IS E16-1's `audit_log` tripwire — *"a tripwire against silent
     * reversal, not against a decision"* — and it is non-vacuous for the first
     * time now that the key is real.
     */
    #[Test]
    public function theTablesRefusedAnAgePurgeDeclareNoRetentionColumn(): void
    {
        foreach (['audit_log', 'idempotency_key'] as $refused) {
            self::assertArrayNotHasKey($refused, SchemaManifest::retentionColumns(),
                $refused.' declares a retention_column. It is affirmatively refused an age-based purge: PO ruled it '
                .'2026-09-07 for audit_log, and idempotency_key carries the same refusal in its own why cell. If a ruling '
                .'has superseded PO\'s, record it and change this method deliberately -- do not delete the assertion to get green. '
                .'ADR-007 A1.3 still carries the stale audit_log row; removing it is F-18, owed by ARCH.');
        }
    }

    /**
     * Nothing else enforces the manifest's key shape: there is no PHPStan in this
     * repo, so the `@var` annotation on `SchemaManifest::TABLES` is documentation.
     * A mistyped optional key — `retention_colum` — is silently ignored by every
     * accessor, which is how the check above would go vacuously green.
     */
    #[Test]
    public function noTableDeclaresAnUnknownManifestKey(): void
    {
        $known = ['module', 'tenant', 'deletion', 'why', 'retention_column', 'retention_why', 'grants', 'column_grants', 'rls_exempt'];

        foreach (SchemaManifest::TABLES as $table => $spec) {
            $unknown = array_diff(array_keys($spec), $known);
            self::assertSame([], $unknown,
                $table.' declares unknown manifest key(s): '.implode(', ', $unknown)
                .'. Either it is a typo -- in which case every check reading that key is silently vacuous -- or the key is new '
                .'and belongs in this list and in the @var shape on SchemaManifest::TABLES.');
        }
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
