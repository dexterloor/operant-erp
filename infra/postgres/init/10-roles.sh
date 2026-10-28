#!/bin/bash
# ADR-002 §1 as amended by A1.3 / ADR-006 — the five principals, plus a sixth, plus the mail worker's seventh login
# (Mail-C1; owner Q-2, Move 671: "Q-2 seventh login: yes, `erp_worker`").
#
# The two ADR-006 roles (erp_auth, erp_authlog) bound the authority of the
# pre-authentication path by making it a grant fact rather than a code rule.
# They do NOT close ADR-006's Condition 2: §2.1 step 1 runs the rate limiter on
# erp_app, which holds DML on every table with no tenant_id — including
# messenger_messages, where an INSERT is worker code execution by another name.
# erp_ratelimit exists to take the limiter off erp_app entirely. Its whole
# footprint is two counter tables.
#
# Created here, in SQL, and never through a provider console/API/CLI. On Neon
# those paths auto-grant neon_superuser, which carries BYPASSRLS, and a role with
# BYPASSRLS makes every policy in this schema inert while the test suite stays
# green (ADR-002 §C-1, risk R-13).
#
# Local stand-in for Neon's default role: DB_OWNER, created NOSUPERUSER on
# purpose. If it were the bootstrap superuser, FORCE ROW LEVEL SECURITY would be
# untestable, because a superuser bypasses row security no matter what.
#
# The owner is also NOCREATEDB NOCREATEROLE (V3-8(a), M-8; V4 batch B6,
# 2026-09-16). It held both and nothing used them: migrations need ownership of
# the database and the schema (granted below by the superuser), not the right to
# mint roles or databases. SchemaGuardTest::noPrincipalCanCreateRolesOrDatabases
# asserts both attributes false for the owner and every application role.
# Neon's console owner differs (neon_superuser); measured at G-3, not here.
set -euo pipefail

# What this database is for (W-3): `gate` unless compose passes another DB_PURPOSE. Checked against a fixed shape BEFORE
# it is interpolated into SQL below. bin/demo-seed.php reads the comment and refuses anything but `erp-local-demo`; it is
# accident prevention, not a security control (the owner, which owns the database, can rewrite it).
DB_PURPOSE="${DB_PURPOSE:-gate}"
if ! [[ "${DB_PURPOSE}" =~ ^[a-z][a-z0-9-]{0,30}$ ]]; then
    echo "10-roles.sh: DB_PURPOSE must match ^[a-z][a-z0-9-]{0,30}\$" >&2
    exit 1
fi

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE ROLE "${DB_OWNER}"      LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD '${DB_OWNER_PASSWORD}';
    CREATE ROLE "${ERP_APP_USER}"  LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD '${ERP_APP_PASSWORD}';
    CREATE ROLE "${ERP_AI_USER}"   LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD '${ERP_AI_PASSWORD}';

    -- ADR-006 §2. Neither is ever named by ALTER DEFAULT PRIVILEGES, so no
    -- future migration can widen them the way S-11(c) widens erp_app.
    CREATE ROLE "${ERP_AUTH_USER}"      LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD '${ERP_AUTH_PASSWORD}';
    CREATE ROLE "${ERP_AUTHLOG_USER}"   LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD '${ERP_AUTHLOG_PASSWORD}';
    CREATE ROLE "${ERP_RATELIMIT_USER}" LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD '${ERP_RATELIMIT_PASSWORD}';

    -- Mail-C1: the mail worker. Never reached by an HTTP request; never named by ALTER DEFAULT PRIVILEGES. Its
    -- footprint is granted column by column by the migration that ships its caller (ADR-002 A4.1).
    CREATE ROLE "${ERP_WORKER_USER}"    LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD '${ERP_WORKER_PASSWORD}';

    -- The owner role owns the database and the schema, so migrations run as a
    -- non-superuser exactly as they will on Neon.
    ALTER DATABASE "${POSTGRES_DB}" OWNER TO "${DB_OWNER}";
    COMMENT ON DATABASE "${POSTGRES_DB}" IS 'erp-local-${DB_PURPOSE}';
    ALTER SCHEMA public OWNER TO "${DB_OWNER}";

    -- Local observability only. PooledConnectionTest has to read the state of a
    -- backend owned by erp_app to prove that a transaction really was open while
    -- a second client waited. Without this the owner sees
    -- "<insufficient privilege>" and the measurement is unfalsifiable.
    -- Not part of ADR-002 §1; do not carry this to staging or production.
    GRANT pg_read_all_stats TO "${DB_OWNER}";

    -- No application role may create objects in public. If one could, it would own
    -- what it creates, and ownership plus a missing FORCE would void isolation.
    -- This line covers schema CREATE only. Temporary tables are a database
    -- privilege that PUBLIC holds by default; migration Version20260916000100
    -- revokes TEMPORARY from PUBLIC (V3-3(i)), because on a transaction-pooled
    -- backend a temp table shadows a public table for every later client.
    REVOKE CREATE ON SCHEMA public FROM PUBLIC;
    GRANT USAGE ON SCHEMA public TO "${ERP_APP_USER}", "${ERP_AI_USER}",
                                    "${ERP_AUTH_USER}", "${ERP_AUTHLOG_USER}", "${ERP_RATELIMIT_USER}",
                                    "${ERP_WORKER_USER}";
EOSQL
