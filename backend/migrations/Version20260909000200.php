<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * E4-2 / API-O-10 — `audit_log.permission_code`, and the index that makes
 * E-3a's read-back a bounded probe.
 *
 * ---------------------------------------------------------------------------
 * The blocker
 * ---------------------------------------------------------------------------
 * `SECURITY_MODEL.md` E-3 requires **every** permission denial reaching the API
 * to be written to `audit_log` as `authz.permission.denied`. E-3a caps that at
 * two rows per `(tenant_id, actor_user_id, permission_code)` per UTC day, and
 * the cap is enforced by reading back **at most two matching rows** for that
 * key before writing — never a `COUNT(*)`, never unbounded in time.
 *
 * The key's third column had no column. `audit_log` was
 * `id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
 * subject_type, subject_id, ip_address, metadata`, and `permission_code`
 * existed in this schema on exactly one table — `role_permission` — which is
 * not this one. `audit_log_tenant_actor_idx` is
 * `(tenant_id, actor_user_id, occurred_at)`: **two** of the key's three
 * columns. So the read-back was an index scan of the actor's entire UTC day
 * with a `metadata->>'permission_code'` filter applied afterwards — "at most
 * two matching rows" as a property of the **result**, not of the **work**, and
 * a bound on work is E-3a's entire reason for existing.
 *
 * Without the cap, E-3 hands any authenticated user an unbounded write into a
 * table that is not partitioned and on which no application role holds `DELETE`
 * (S-9), reachable with a `curl` loop. ADR-006 §2.2 closed exactly this
 * flooding vector before authentication; E-3 without E-3a reopens it after.
 *
 * ---------------------------------------------------------------------------
 * Why not `metadata`, so it is not proposed again
 * ---------------------------------------------------------------------------
 * An expression index on `(metadata->>'permission_code')` would make the probe
 * bounded, so this is not a straw man. `SEC` rejected it at `API.md` §9.4a
 * revision 3 on two grounds and both still hold: it indexes a value with no
 * type, no `NOT NULL` and no `CHECK`, which makes the cap's key **the one part
 * of the key any writer can spell differently**; and PC-11 reads `metadata` as
 * colleague-visible content, so the cap's key would sit inside the field the
 * `.view_record` allowlist governs. A key column belongs in the schema.
 *
 * ---------------------------------------------------------------------------
 * `NULL`, not a sentinel — and the `CHECK` is one-directional on purpose
 * ---------------------------------------------------------------------------
 * The column is nullable and carries `NULL` on every event type that is not a
 * denial. **A sentinel — `''`, `'-'`, `'n/a'` — was rejected**: it is a value,
 * so it is a value some writer spells differently, which is the same defect
 * that disqualified `metadata` one paragraph up; it would make
 * `permission_code IS NOT NULL` stop meaning "this row is a denial"; and it
 * would put a meaningless string on all ~98% of the table the project's own
 * fixture models as business events (`SchemaQueryPlanTest`), while `NULL` is
 * exactly PostgreSQL's word for "not applicable here". A `NOT NULL` column
 * would additionally require every existing row and every non-denial writer to
 * supply that meaningless value.
 *
 * The `CHECK` below is the **one-directional** form — a permission code may
 * appear *only* on a denial row — and not the biconditional
 * `(permission_code IS NOT NULL) = (event_type = 'authz.permission.denied')`
 * that `audit_log_subject_pair` might suggest. Two reasons, in order of weight:
 *
 * 1. **A biconditional can refuse an audit write on the enforcement path.** A
 *    denial reaching the API with no code would raise `23514` instead of
 *    writing E-3's row: the record is lost *and* a request that was already
 *    being refused becomes a 500. The failure the one-directional form permits
 *    — a denial row with a `NULL` code — loses the cap for that row, which is a
 *    growth bug. Losing the audit record is worse than losing the bound, and
 *    E-3 is the rule E-3a exists to make affordable, not the other way round.
 * 2. **Scope.** `AuditEventCatalogueTest::everyCatalogueValueIsAcceptedByThe`
 *    `Database` inserts one row for every value in `AuditEvent::ALL` —
 *    `authz.permission.denied` included — with no `permission_code`, and it is
 *    right to: it is testing `event_type`'s domain. A biconditional turns that
 *    green test red, and the only non-weakening repair is a per-event
 *    "requires a permission code" declaration on `App\Audit\AuditEvent`. That
 *    is a catalogue extension, and **E4-1 owns catalogue extensions, in five
 *    ordered steps.** E4-2 is scoped to this column and its index.
 *
 * **What that leaves open, stated rather than left to be discovered:** the
 * *requirement* half of API-O-10's "non-null exactly for
 * `authz.permission.denied` rows" is **not enforced by the database**. A denial
 * row with a `NULL` code is accepted and is invisible to the cap's key. The
 * enforcement of that half belongs to the denial writer and to E4-1's
 * catalogue declaration; `App\Entity\AuditLogEntry` refuses the direction this
 * migration constrains, for the same reason it already refuses a half-populated
 * subject pair, and it cannot refuse the other direction either without that
 * declaration.
 *
 * ---------------------------------------------------------------------------
 * No foreign key to `permission.code`, and that is a ruling, not an omission
 * ---------------------------------------------------------------------------
 * `permission` is `PRIMARY KEY (code)` on a `text` column, non-tenant reference
 * data, seeded and re-seeded by migrations. `REFERENCES permission (code)` is
 * therefore expressible. It is refused:
 *
 *   - **`audit_log` has to outlive the rows it describes.** That is not a new
 *     argument here — `subject_type`/`subject_id` are a deliberate polymorphic
 *     reference with no foreign key for precisely this reason. A retired or
 *     renamed permission code would make its own denial history either
 *     un-deletable or, with `ON UPDATE CASCADE`, silently rewritten. An audit
 *     row whose truth depends on mutable reference data is not an audit row.
 *   - **It would give `permission` a deletion class nobody declared.** The
 *     table is `SchemaManifest::HARD`; a foreign key from an append-only table
 *     turns every code that has ever been denied into a permanent row, which is
 *     a real policy and belongs in ADR-007 and `SCHEMA.md` §7, not in a side
 *     effect of an index.
 *   - **It would be a write-path dependency on the enforcement path.** Every
 *     denial insert would take a `KEY SHARE` lock on a `permission` row, on the
 *     one code path that runs when the system is being probed.
 *   - **It buys nothing the cap needs.** The key is an opaque grouping value;
 *     the code's membership in the catalogue is `role_permission`'s business
 *     and is checked before the denial, not after it.
 *
 * The cost is recorded: a typo in a denial writer's permission code produces a
 * row nothing rejects, and it splits that actor's cap key. Catching that is
 * `SECURITY_MODEL.md` §3's 60-permission catalogue and Output 4's voter, both
 * of which name the code before the denial exists.
 *
 * ---------------------------------------------------------------------------
 * The index: partial, and four columns in this order
 * ---------------------------------------------------------------------------
 * `(tenant_id, actor_user_id, permission_code, occurred_at)
 *  WHERE event_type = 'authz.permission.denied'`.
 *
 * `tenant_id` leads because ADR-002 §5 requires it of every composite index and
 * because RLS evaluates its predicate per row before any user condition.
 * `occurred_at` is last because it is the only inequality in the probe, and a
 * range column ahead of an equality column ends the usable index prefix.
 *
 * **Partial on `event_type`, not on `permission_code IS NOT NULL`.** Both
 * predicates select the same rows today — the `CHECK` above makes the second a
 * subset of the first — but the index predicate has to be *provable from the
 * query*, and the read-back's natural shape names the event type. The partial
 * predicate keeps the index proportional to the denial rows rather than to a
 * table the project's own fixture models as 98% business events.
 *
 * **The predicate must be written into the read-back's `WHERE` clause.**
 * PostgreSQL proves a partial index usable from the query's own restriction
 * clauses; it does not consult table `CHECK` constraints to do it. A read-back
 * that omits `event_type = 'authz.permission.denied'` cannot use this index and
 * falls back to `audit_log_tenant_actor_idx` — which is the fallback plan the
 * §9.4a assertion exists to reject, so the omission fails loudly rather than
 * quietly. Measured on PostgreSQL 17: with the predicate present, it does
 * **not** appear as a `Filter:` line, because a qual the index predicate
 * implies is dropped from the plan. That is the property §9.4a's "no `Filter:`
 * line at all" clause depends on, and it is verified on this server by
 * `DenialCapStorageTest` rather than assumed from the documentation.
 *
 * ---------------------------------------------------------------------------
 * Grants: none. Deliberately.
 * ---------------------------------------------------------------------------
 * `erp_app` holds table-level `SELECT, INSERT` on `audit_log` and a new column
 * inherits both; `erp_authlog` holds `INSERT` and no `SELECT`; no role holds
 * `UPDATE` or `DELETE` (S-9). E-3a's read-back is a `SELECT` and its write is
 * an `INSERT`, so **the cap needs no privilege that does not already exist**
 * and this migration adds none. That is also why the attempt count is not
 * carried on the row: incrementing it would need `UPDATE`, which S-9 refuses —
 * SEC-O-5(b). `GrantFootprintTest::everyColumnLevelPrivilegeIsExactlyThe`
 * `DeclaredSet` derives its column list from `pg_attribute` per table, so
 * `permission_code` is inside the exhaustive check the moment it exists,
 * without anything being added to a list.
 *
 * ---------------------------------------------------------------------------
 * Locks (postgres-database-migration)
 * ---------------------------------------------------------------------------
 * Non-transactional, for the same reason `Version20260907000200` is: inside one
 * transaction the `NOT VALID` / `VALIDATE` split holds `AccessExclusiveLock`
 * across the validation scan and buys nothing, and `CREATE INDEX CONCURRENTLY`
 * is refused outright.
 *
 * `audit_log` is the one table in this schema designed to grow without bound
 * and never be pruned — it is unpartitioned and `DELETE`-less by grant (S-9,
 * SEC-O-3) — so the "the tables are empty, run it in one transaction" argument
 * `Version20260906000100` records for the initial migration is exactly the
 * argument not to copy here. Every statement is therefore in its live-database
 * form:
 *
 *   - `ADD COLUMN` nullable with no `DEFAULT` — `AccessExclusiveLock`, but
 *     metadata-only since PG11, no rewrite;
 *   - `ADD CONSTRAINT … NOT VALID` — `AccessExclusiveLock`, no scan;
 *   - `VALIDATE CONSTRAINT` — `ShareUpdateExclusiveLock`, scans, concurrent
 *     with `INSERT`;
 *   - `CREATE INDEX CONCURRENTLY` — `ShareUpdateExclusiveLock`, two passes,
 *     concurrent with `INSERT`. A plain `CREATE INDEX` would take `ShareLock`
 *     and block audit writes for the length of the build, i.e. block the
 *     enforcement path this index exists to serve.
 *
 * **Re-entrancy, which non-transactional makes the migration's own problem.** A
 * failed `CREATE INDEX CONCURRENTLY` leaves an `indisvalid = false` index
 * behind that a later `IF NOT EXISTS` would silently accept — a fake-green in
 * DDL form: the name is present, the plans are not. So the index is dropped
 * first, unconditionally, and `DROP INDEX IF EXISTS` on a first run is a no-op.
 * Same for the constraint, matching `Version20260907000200`.
 *
 * @see SECURITY_MODEL.md §5 E-3/E-3a, Appendix A SEC-O-3/SEC-O-5
 * @see API.md §9.4, §9.4a, API-O-10; DELIVERY_LEDGER.md Output 4 entry condition E4-2
 * @see ARCHITECTURE.md S-9; ADR-002 §5; ADR-007 §1
 * @see \App\Tests\Tenancy\DenialCapStorageTest for the plan-shape proof
 */
final class Version20260909000200 extends AbstractMigration
{
    private const CONSTRAINT = 'audit_log_permission_code_scope';
    private const INDEX = 'audit_log_denial_cap_idx';

    /** E-3's event type. The cap's partial predicate and the CHECK both name it. */
    private const DENIAL = 'authz.permission.denied';

    /**
     * See "Locks" above. Without this the split holds AccessExclusiveLock across
     * the validation scan and CREATE INDEX CONCURRENTLY is refused.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'E4-2/API-O-10: audit_log.permission_code and the partial index that bounds E-3a\'s read-back.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS permission_code text');

        $this->addSql(sprintf('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS %s', self::CONSTRAINT));
        $this->addSql(sprintf(
            "ALTER TABLE audit_log ADD CONSTRAINT %s CHECK (permission_code IS NULL OR event_type = '%s') NOT VALID",
            self::CONSTRAINT,
            self::DENIAL,
        ));
        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::CONSTRAINT));

        // Dropped first rather than IF NOT EXISTS: see "Re-entrancy" above.
        $this->addSql(sprintf('DROP INDEX IF EXISTS %s', self::INDEX));
        $this->addSql(sprintf(
            "CREATE INDEX CONCURRENTLY %s ON audit_log (tenant_id, actor_user_id, permission_code, occurred_at) WHERE event_type = '%s'",
            self::INDEX,
            self::DENIAL,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(sprintf('DROP INDEX IF EXISTS %s', self::INDEX));
        $this->addSql(sprintf('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS %s', self::CONSTRAINT));
        $this->addSql('ALTER TABLE audit_log DROP COLUMN IF EXISTS permission_code');
    }
}
