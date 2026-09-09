<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SEC-O-16 — the `(ip_prefix)` budget gets a table, and both pre-auth budgets
 * get one definition of "window".
 *
 * ---------------------------------------------------------------------------
 * The blocker
 * ---------------------------------------------------------------------------
 * `SECURITY_MODEL.md` E-21 sets two ceilings per fixed 15-minute window:
 * `(slug_normalised, ip_prefix)` at 100 and `(ip_prefix)` at 300. The first has
 * storage — `auth_attempt`. The second had none:
 *
 *   - `auth_attempt_pkey` is `PRIMARY KEY (slug_normalised, ip_prefix,
 *     window_start)`, so **every row of that table names a workspace** and no
 *     row of it can answer a workspace-independent question; and
 *   - `DatabaseRoles::RATE_LIMIT` appeared on exactly two `SchemaManifest`
 *     entries, `auth_attempt` and `auth_lockout`, so there was nothing else on
 *     that path to reach.
 *
 * ---------------------------------------------------------------------------
 * Why this is a new table and not a reserved slug in `auth_attempt`
 * ---------------------------------------------------------------------------
 * `SEC` named the sentinel candidate and declined to choose it — correctly. It
 * fails on a fact about the column rather than on taste.
 *
 * **`auth_attempt.slug_normalised` is attacker-supplied and deliberately
 * shape-free.** `tenant.slug` carries `tenant_slug_shape`
 * (`^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$`); `auth_attempt.slug_normalised` carries
 * only `= lower(slug_normalised)`, and that is by design — the whole point of
 * ADR-006 §2.3 is that a slug which resolves to nothing still gets recorded,
 * which is what makes a dictionary scrape *loud* (`SECURITY_MODEL.md` §5.1.2).
 * So a client can send the reserved value as its workspace. The
 * `(slug_normalised, ip_prefix)` upsert of §7.1 step 2 would then land on the
 * **same primary-key row** as the `(ip_prefix)` budget, and the client would be
 * choosing which of the two ceilings — 100 or 300 — its request was metered
 * against, and polluting every other workspace's IP budget from that prefix
 * while doing it. Closing that needs either a shape `CHECK` on
 * `slug_normalised`, which contradicts the reason the column has none, or a
 * rejection in application code — a code rule standing in front of a primary
 * key, which is the shape this project has now been burned by repeatedly.
 *
 * Two further costs, recorded because they are the ones a reader will ask about:
 * the `auth_attempt` rows are the per-workspace evidence §5.1.2 leans on, and a
 * sentinel row silently adds a fictitious workspace carrying the sum of all
 * traffic to every "attempts per workspace" aggregate; and the two budgets have
 * different ceilings, which one table with one column cannot express.
 *
 * ---------------------------------------------------------------------------
 * The other candidates, and why each fails
 * ---------------------------------------------------------------------------
 * **Aggregate it from `auth_attempt`** — `SUM(attempt_count) WHERE ip_prefix = ?
 * AND window_start = ?`, no new table. Fails twice. (a) E-9 and `API.md` §7.1
 * step 2 order the IP budget *before* the `auth_attempt` upsert, and an
 * aggregate is a read: there is nowhere to record that the IP budget was
 * consumed, so a request refused further down never consumes it, and E-21's
 * *"every attempt consumes, successes included"* cannot hold. (b) The row count
 * of that aggregate is **chosen by the attacker** — 10,000 random slugs from one
 * address is 10,000 rows to sum — and `auth_attempt_pkey` leads with
 * `slug_normalised`, so it is a partition scan, not an index scan, on the one
 * unauthenticated endpoint in the system. The gate would get more expensive the
 * harder it is attacked.
 *
 * **`cache.adapter.doctrine_dbal`**, which `ARCHITECTURE.md`'s no-Redis ruling
 * points at. Ruled out rather than left unmentioned. Its table is created by the
 * adapter at runtime, so it arrives outside `SchemaManifest` and outside
 * `backend/config/` — precisely the S-13/V-10 hole; no application role holds
 * `CREATE` on `public`, so it would fail on first use anyway; the row it stores
 * is one opaque serialized blob under a hashed key, so nothing about the key
 * shape can be constrained or asserted — the IPv6 /64 hazard of E-21 would be
 * invisible to the schema; and its expiry is `DELETE`, which would widen
 * `erp_ratelimit` past the `SELECT, INSERT, UPDATE` this schema asserts. The
 * component question stays `BE-LEAD`'s: a Symfony `StorageInterface` over the
 * table below is available, and so is a plain repository. The *storage* is here.
 *
 * ---------------------------------------------------------------------------
 * Partitioned, and that is not decoration
 * ---------------------------------------------------------------------------
 * `SEC-O-1` exists because `auth_lockout` is not partitioned and therefore has
 * no retention path that does not need `DELETE`. A new counter table with the
 * same defect would be a second SEC-O-1 filed on the day it was created. So:
 * `PARTITION BY RANGE (window_start)` with a `DEFAULT` partition, exactly as
 * `auth_attempt` — retention is `DROP PARTITION` by the owner, and
 * `erp_ratelimit` keeps `SELECT, INSERT, UPDATE` and no `DELETE`.
 *
 * The `DEFAULT` partition is for the same reason it exists on `auth_attempt`:
 * without one the single unauthenticated endpoint starts refusing inserts the
 * moment rotation stops. It carries the same cost — attaching a dated partition
 * scans it — and, per `SCHEMA.md` §13, rotation itself is still unbuilt and
 * unowned. This migration does not change that; it declines to make it worse.
 *
 * ---------------------------------------------------------------------------
 * `window_start` is pinned to E-21's grid, on both tables
 * ---------------------------------------------------------------------------
 * E-21's window is **fixed**, 900 seconds, and one duration serves both budgets.
 * "Fixed" was a property of code nobody had written. The obvious wrong
 * implementation — derive the key from `now()` rather than from the grid — gives
 * every request its own row, so no budget ever fills and the limiter is inert
 * while every ceiling test that only checks the refusal still passes. The
 * `CHECK` turns that into `23514` on the first request.
 *
 * It is applied to `auth_attempt` as well as to the new table, deliberately and
 * as a widening of this migration's scope. Two counter tables under one figure
 * that disagree about what a window is would be worse than neither having the
 * constraint, and the guard that enforces it derives its domain from the
 * catalogue — every table with a `window_start` column — rather than from a
 * list, so a third such table inherits the requirement without anyone
 * remembering to add it.
 *
 * **The expression is immutable and timezone-independent, measured rather than
 * assumed.** `date_trunc(text, timestamptz)` is `STABLE` and Postgres refuses it
 * in a `CHECK`. `date_part('epoch', <interval>)` is `IMMUTABLE`, and
 * `'epoch'::timestamptz` folds at parse time — `pg_get_constraintdef` reads back
 * `'1970-01-01 00:00:00+00'::timestamp with time zone`, a constant — so nothing
 * in the stored expression consults `TimeZone`. Probed on PostgreSQL 17.11:
 * `10:15:00+00` accepted, `10:15:01+00` and `10:07:30+00` refused, and the
 * accepted value still accepted with the session at `Asia/Kolkata`.
 *
 * ---------------------------------------------------------------------------
 * Locks (postgres-database-migration), and why there is no NOT VALID split here
 * ---------------------------------------------------------------------------
 * `ADD CONSTRAINT … CHECK` takes `AccessExclusiveLock` and scans the table.
 * `Version20260907000200` split that into `NOT VALID` + `VALIDATE` and had to
 * set `isTransactional()` to `false` for the split to mean anything — measured
 * there: inside one transaction the `AccessExclusiveLock` is held across the
 * validation scan and the split buys nothing.
 *
 * This migration keeps the transaction and takes the one-step form, because the
 * trade runs the other way. It **creates** a table and grants on it; a
 * non-transactional failure between the two leaves `auth_ip_budget` existing and
 * ungranted, which fails `SchemaManifestTest` and `GrantFootprintTest` in a way
 * that reads as a schema defect rather than as a half-applied migration. And the
 * scan it is trading against is empty: `auth_attempt` has **no writer** in any
 * environment (`SCHEMA.md` §14, `API.md` §7.1 — the limiter is Output 4's and
 * does not exist yet), so validation is a scan of zero rows.
 *
 * That condition is the whole argument, so it is written down rather than
 * assumed: **if `auth_attempt` is ever non-empty when this runs, the constraint
 * addition below belongs in its own non-transactional migration with the
 * `NOT VALID` / `VALIDATE` split.** It was verified empty before this migration
 * was written, and `up()` cannot verify it for a future database.
 *
 * @see SECURITY_MODEL.md §5 E-9/E-21, §5.1.2, Appendix A SEC-O-16
 * @see ADR-006 §2.3, §6.4(b); ARCHITECTURE.md S-13; ADR-006 V-10
 * @see \App\Tenancy\SchemaManifest for the allowlist entry S-13 requires
 */
final class Version20260909000100 extends AbstractMigration
{
    /** E-21's window, in seconds. A change to the figure is a migration, per SCHEMA.md §12's enum reasoning. */
    private const WINDOW_SECONDS = 900;

    public function getDescription(): string
    {
        return 'SEC-O-16: auth_ip_budget for the (ip_prefix) budget, and E-21\'s window grid pinned on both counter tables.';
    }

    public function up(Schema $schema): void
    {
        $this->createIpBudget();
        $this->pinTheWindowGrid();
        $this->applyGrants();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auth_attempt DROP CONSTRAINT IF EXISTS auth_attempt_window_aligned');
        $this->addSql('DROP TABLE IF EXISTS auth_ip_budget CASCADE');
    }

    private function createIpBudget(): void
    {
        // Keyed on the network prefix and the window and on nothing else. That
        // is the ruling, expressed as the only thing a primary key can express:
        // no column here carries workspace identity, so no client-supplied value
        // can select, share or displace the row that meters it.
        //
        // ip_prefix is `cidr` for the same reason auth_attempt.ip_prefix is
        // (SCHEMA.md §12): ADR-006 §6.4(b) keys IPv6 on the /64, and a per-address
        // key over 2^64 addresses is no limiter at all.
        //
        // There is no last_attempt_at, and auth_attempt's presence of one is not
        // a reason to add it: nothing in E-21 reads it, window_start already
        // bounds the row to fifteen minutes, and a column with no reader is a
        // column the next reader has to guess the meaning of.
        $this->addSql(<<<SQL
            CREATE TABLE auth_ip_budget (
                ip_prefix      cidr        NOT NULL,
                window_start   timestamptz NOT NULL,
                attempt_count  integer     NOT NULL DEFAULT 0,
                CONSTRAINT auth_ip_budget_pkey PRIMARY KEY (ip_prefix, window_start),
                CONSTRAINT auth_ip_budget_count_sane CHECK (attempt_count >= 0),
                CONSTRAINT auth_ip_budget_window_aligned CHECK ({$this->alignment('window_start')})
            ) PARTITION BY RANGE (window_start)
            SQL);

        $this->addSql('CREATE TABLE auth_ip_budget_default PARTITION OF auth_ip_budget DEFAULT');
    }

    private function pinTheWindowGrid(): void
    {
        // IF EXISTS so a re-run after a failure lands on the real finding rather
        // than on 42710. Inside this migration's transaction, so unlike
        // Version20260907000200's equivalent it opens no window in which the
        // table carries no constraint.
        $this->addSql('ALTER TABLE auth_attempt DROP CONSTRAINT IF EXISTS auth_attempt_window_aligned');
        $this->addSql(
            'ALTER TABLE auth_attempt ADD CONSTRAINT auth_attempt_window_aligned CHECK ('
            .$this->alignment('window_start').')',
        );
    }

    private function applyGrants(): void
    {
        // REVOKE first, then GRANT, which is Version20260906000100's ordering
        // lesson rather than a flourish: ALTER DEFAULT PRIVILEGES is withdrawn
        // today, but its `down()` restores it, and a table created while it
        // stands is granted to erp_app before anyone decides whether it should
        // be. This makes the footprint of a new table independent of what was
        // standing when it was created.
        $this->addSql('REVOKE ALL ON auth_ip_budget FROM erp_app, erp_ai, erp_auth, erp_authlog, erp_ratelimit');

        // No DELETE and no TRUNCATE. Retention is DROP PARTITION by the owner,
        // and the narrowness is asserted, not merely intended -- see
        // GrantFootprintTest::everyRoleHoldsExactlyTheTablePrivilegesTheManifestDeclares.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON auth_ip_budget TO erp_ratelimit');
    }

    /**
     * `$column` lands on E-21's 900-second UTC grid.
     *
     * date_part over an *interval* is IMMUTABLE, which date_trunc over a
     * timestamptz is not; the 'epoch' literal folds to a UTC constant, so the
     * stored expression never consults the session TimeZone. See the docblock.
     */
    private function alignment(string $column): string
    {
        return sprintf(
            "date_part('epoch', %s - 'epoch'::timestamptz)::bigint %% %d = 0",
            $column,
            self::WINDOW_SECONDS,
        );
    }
}
