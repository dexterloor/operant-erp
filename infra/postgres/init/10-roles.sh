#!/bin/bash
# ADR-002 §1 — the three roles.
#
# Created here, in SQL, and never through a provider console/API/CLI. On Neon
# those paths auto-grant neon_superuser, which carries BYPASSRLS, and a role with
# BYPASSRLS makes every policy in this schema inert while the test suite stays
# green (ADR-002 §C-1, risk R-13).
#
# Local stand-in for Neon's default role: DB_OWNER, created NOSUPERUSER on
# purpose. If it were the bootstrap superuser, FORCE ROW LEVEL SECURITY would be
# untestable, because a superuser bypasses row security no matter what.
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE ROLE "${DB_OWNER}"      LOGIN NOSUPERUSER NOBYPASSRLS CREATEDB CREATEROLE PASSWORD '${DB_OWNER_PASSWORD}';
    CREATE ROLE "${ERP_APP_USER}"  LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD '${ERP_APP_PASSWORD}';
    CREATE ROLE "${ERP_AI_USER}"   LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD '${ERP_AI_PASSWORD}';

    -- The owner role owns the database and the schema, so migrations run as a
    -- non-superuser exactly as they will on Neon.
    ALTER DATABASE "${POSTGRES_DB}" OWNER TO "${DB_OWNER}";
    ALTER SCHEMA public OWNER TO "${DB_OWNER}";

    -- Local observability only. PooledConnectionTest has to read the state of a
    -- backend owned by erp_app to prove that a transaction really was open while
    -- a second client waited. Without this the owner sees
    -- "<insufficient privilege>" and the measurement is unfalsifiable.
    -- Not part of ADR-002 §1; do not carry this to staging or production.
    GRANT pg_read_all_stats TO "${DB_OWNER}";

    -- Neither application role may create objects. If they could, they would own
    -- what they create, and ownership plus a missing FORCE would void isolation.
    REVOKE CREATE ON SCHEMA public FROM PUBLIC;
    GRANT USAGE ON SCHEMA public TO "${ERP_APP_USER}", "${ERP_AI_USER}";
EOSQL
