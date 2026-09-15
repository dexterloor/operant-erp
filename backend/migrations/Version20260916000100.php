<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **V3-3(i): no application role may create a temporary table** (V4 batch B2c, 2026-09-16).
 *
 * `pg_database.datacl` was NULL for this database, so PostgreSQL's defaults applied and PUBLIC — every role, all five
 * application principals included — held `CONNECT` **and `TEMPORARY`**. Nothing asserted it, and
 * `infra/postgres/init/10-roles.sh` claimed "No application role may create objects".
 *
 * **Why it matters here.** PgBouncer runs `pool_mode=transaction` with `server_reset_query_always=0`, so no reset runs
 * between clients and a temp table outlives the client that created it on the pooled server connection. `pg_temp` is
 * searched before `public` for relations, and the application's SQL uses unqualified names. M-3, measured 2026-09-16 on a
 * destroyed volume: an `erp_app` client created `TEMP TABLE audit_log (LIKE public.audit_log)`; a fresh `erp_app` client
 * on the same pool (same backend pid) inserted tenant B's audit row with the application's statement shape and read it
 * back; the owner, in tenant B's context, found 0 rows in `public.audit_log`. Later requests from any tenant would write
 * append-only rows into the shadow (lost), skip CHECKs, foreign keys and triggers, and read forged rows — R-14's class:
 * session state on a pooled backend persisting into the next tenant's request.
 *
 * **What this does.** `REVOKE TEMPORARY ON DATABASE <current database> FROM PUBLIC`. PUBLIC keeps `CONNECT`, so the
 * application roles still connect; the owner (the database's owner, `10-roles.sh`) keeps its own privileges. The database
 * name is taken from `current_database()` through `format('%I')`, never typed, so the same migration runs locally and on
 * Neon. Postgres records the revoke in `pg_database.datacl`; `GrantFootprintTest::everyApplicationRoleHoldsConnectAndNoOtherDatabasePrivilege`
 * asserts the outcome with `has_database_privilege()` over the server-derived privilege domain, with a planted grant.
 *
 * **Not closed by this, named** (V3-3(ii), owner question O-2): bare `SET`, `SET ROLE`, session `pg_advisory_lock`,
 * `PREPARE`, `LISTEN`, `WITH HOLD` cursors and `lo_create` storage remain available to an application role that runs SQL
 * of its own choosing. Whether Neon permits this revoke, and how its pooler resets, is measured at G-3.
 *
 * **Re-grant rule (M-4):** only a `src/` caller justifies giving `TEMPORARY` back, in that caller's own migration, to that
 * role alone — never to PUBLIC.
 *
 * **Locks:** a catalogue update on `pg_database`; no table is touched. `down()` restores PostgreSQL's default.
 */
final class Version20260916000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'V3-3(i): REVOKE TEMPORARY ON DATABASE <current database> FROM PUBLIC — no application role can shadow a table on a pooled backend';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                EXECUTE format('REVOKE TEMPORARY ON DATABASE %I FROM PUBLIC', current_database());
            END
            $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                EXECUTE format('GRANT TEMPORARY ON DATABASE %I TO PUBLIC', current_database());
            END
            $$
            SQL);
    }
}
