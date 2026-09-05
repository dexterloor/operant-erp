<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Output 2 — the schema: registry, reference data, pre-auth tables and the
 * eight business modules.
 *
 * Additive rather than a rewrite of W0-a. Version20260905000100/000200 are
 * committed history; `supplier`, `inventory_item` and `stock_movement` are
 * altered here into their final shape rather than dropped and recreated.
 *
 * ---------------------------------------------------------------------------
 * Privileges: this migration inverts A1.1's policy, and keeps its mechanism
 * ---------------------------------------------------------------------------
 * A1.1 established that `GRANT … ON ALL TABLES` is point-in-time and ran before
 * any table existed, and that `ALTER DEFAULT PRIVILEGES` is what makes that
 * ordering irrelevant. Both facts stand. The *policy* they were used to
 * implement — every future table is granted to erp_app automatically — is what
 * ADR-006 Condition 2 identifies as the V-10/S-13 exposure: `tenant` and
 * `messenger_messages` inherited full DML precisely because ADP does not know
 * which tables are tenant-scoped.
 *
 * So the default privileges are withdrawn and every grant is written out per
 * table, next to the reason. Deny-by-default has the safer failure: a missing
 * grant is a 500 in CI, an inherited grant is a security hole nobody sees.
 * R-34 does not come back, because forgetting the grant is now caught by
 * GrantFootprintTest, which compares every (role, table, privilege) triple
 * against SchemaManifest and fails in both directions.
 *
 * The grant table below is written out longhand and deliberately not read from
 * SchemaManifest: a migration is frozen history and must not change behaviour
 * when a constant is edited two waves later. The manifest is the intent, this
 * is the act, and the test asserts they agree.
 *
 * ---------------------------------------------------------------------------
 * Lock levels (postgres-database-migration)
 * ---------------------------------------------------------------------------
 * Every statement here takes ACCESS EXCLUSIVE on the table it touches:
 * CREATE TABLE (no contention — the table does not exist yet), ADD COLUMN with
 * a non-volatile DEFAULT (metadata-only since PG11, no rewrite), DROP COLUMN
 * (metadata-only), ALTER COLUMN TYPE integer -> numeric (**full rewrite**), and
 * ADD CONSTRAINT (a validating scan). On a live database the type change and
 * the constraint additions would need NOT VALID + VALIDATE and a
 * CREATE INDEX CONCURRENTLY pass; nothing is deployed, the tables are empty, so
 * they run in one transaction here. Recorded so the pattern is not copied into
 * a migration where it does matter.
 */
final class Version20260906000100 extends AbstractMigration
{
    /**
     * D-12 / A1.8. `PRIMARY KEY (id)` is a bare unique index on a tenant-scoped
     * table and integrity checks bypass row security, so a guessed id is
     * confirmable from a constraint violation.
     *
     * **What this constraint does and does not do**, because A1.8's phrasing
     * ("the residual becomes a database constraint, not a convention") overstates
     * it and `RED` was right to say so. It pins the *shape*: a UUIDv7 — also type
     * `uuid`, time-ordered, and a reasonable-looking choice for index locality —
     * is refused, where a type check would have accepted it. It cannot pin
     * *entropy*: `…-4000-8000-000000000001` and its two successors satisfy this
     * check happily. Unguessability still comes from the generator, so the
     * generator carries its own check —
     * IdentifierGenerationTest forbids every time-ordered or derived UUID source
     * in application code and asserts the database's own generator is random.
     * Two halves; neither is sufficient alone.
     *
     * Position 15 is the version nibble and position 20 the RFC 4122 variant.
     * `uuid_extract_version()` would say this more clearly but arrived in
     * PostgreSQL 18; this runs on 17.
     */
    private const UUID_V4 = "substring(%1\$s::text, 15, 1) = '4' AND substring(%1\$s::text, 20, 1) IN ('8', '9', 'a', 'b')";

    /** ADR-002 §2 as corrected by A1.2. */
    private const POLICY = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid";

    /**
     * Every tenant-scoped table in the finished schema. Drives RLS, the policy,
     * the registry foreign key and the UUIDv4 check, so none of them can be
     * forgotten for one table out of twenty-four.
     *
     * @var list<string>
     */
    private const TENANT_TABLES = [
        'app_user', 'role', 'role_permission', 'user_role',
        'audit_log',
        'warehouse', 'storage_location',
        'inventory_item', 'stock_level', 'stock_movement',
        'supplier', 'supplier_product',
        'purchase_order', 'purchase_order_line', 'goods_receipt', 'goods_receipt_line',
        'customer', 'customer_contact', 'customer_address',
        'sales_order', 'sales_order_line', 'fulfilment', 'fulfilment_line', 'invoice',
    ];

    public function getDescription(): string
    {
        return 'Output 2: tenant registry, reference data, pre-auth tables, eight business modules, deny-by-default grants';
    }

    public function up(Schema $schema): void
    {
        $this->withdrawDefaultPrivileges();
        $this->createRegistryAndReferenceData();
        $this->createPreAuthTables();
        $this->alterW0aTables();
        $this->createUserManagement();
        $this->createAuditLog();
        $this->createWarehouses();
        $this->createInventory();
        $this->createSuppliers();
        $this->createPurchasing();
        $this->createCustomers();
        $this->createSales();
        $this->applyRowLevelSecurity();
        $this->cascadeDeletionToOrderLines();
        $this->applyGrants();
    }

    // -----------------------------------------------------------------------
    // Privileges
    // -----------------------------------------------------------------------

    private function withdrawDefaultPrivileges(): void
    {
        // Withdrawn, not left in place and worked around: while these stand,
        // every table a future migration creates is granted to erp_app before
        // anyone decides whether it should be.
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM erp_app');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT ON TABLES FROM erp_ai');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM erp_app');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT ON SEQUENCES FROM erp_ai');

        $this->addSql('GRANT USAGE ON SCHEMA public TO erp_app, erp_ai, erp_auth, erp_authlog, erp_ratelimit');
    }

    /**
     * Runs last, after every table exists.
     *
     * The order is REVOKE-everything-then-GRANT-what-is-declared, which is
     * S-9's ordering lesson applied to the whole schema instead of to one table.
     * S-9 required `REVOKE UPDATE, DELETE ON audit_log` to run after every grant
     * or default privileges would silently re-grant it; generalising that is
     * cheaper than remembering which tables are special.
     */
    private function applyGrants(): void
    {
        $this->addSql('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM erp_app, erp_ai, erp_auth, erp_authlog, erp_ratelimit');
        $this->addSql('REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM erp_app, erp_ai, erp_auth, erp_authlog, erp_ratelimit');

        $crud = 'SELECT, INSERT, UPDATE, DELETE';

        // ---- Platform, no tenant_id: this block is V-10/S-13 -------------
        // The registry is readable and never writable by an application role.
        // Nothing that serves an unauthenticated request may mutate it.
        $this->addSql('GRANT SELECT ON tenant TO erp_app');
        $this->addSql('GRANT SELECT (id, slug, status) ON tenant TO erp_auth');

        // The queue. erp_app only, and no pre-authentication principal: an
        // INSERT here is worker code execution by another name.
        $this->addSql("GRANT $crud ON messenger_messages TO erp_app");
        $this->addSql('GRANT USAGE, SELECT ON SEQUENCE messenger_messages_id_seq TO erp_app');

        // Pre-auth counters. The rate limiter runs here and nowhere else, which
        // is what keeps it off erp_app and therefore away from the queue.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON auth_attempt  TO erp_ratelimit');
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON auth_lockout  TO erp_ratelimit');

        // Reference data (ADR-002 §6) is owner-maintained.
        foreach (['currency', 'country', 'unit_of_measure', 'permission'] as $table) {
            $this->addSql("GRANT SELECT ON $table TO erp_app, erp_ai");
        }

        // ---- Tenant-scoped -----------------------------------------------
        $default = array_values(array_diff(self::TENANT_TABLES, [
            'app_user', 'audit_log', 'stock_movement',
            'goods_receipt', 'goods_receipt_line', 'fulfilment', 'fulfilment_line', 'invoice',
        ]));

        foreach ($default as $table) {
            $this->addSql("GRANT $crud ON $table TO erp_app");
            $this->addSql("GRANT SELECT ON $table TO erp_ai");
        }

        // Credentials. erp_ai gets nothing at all rather than a column-scoped
        // SELECT: a grant that excludes password_hash today is one widening away
        // from a model-driven query reading it, and the agent has no question
        // that needs this table.
        $this->addSql("GRANT $crud ON app_user TO erp_app");
        $this->addSql('GRANT SELECT (id, tenant_id, email, password_hash, is_active) ON app_user TO erp_auth');

        // S-9, and stronger: erp_authlog can write the audit log and cannot read
        // it back; erp_app can read and append and can neither edit nor remove.
        $this->addSql('GRANT SELECT, INSERT ON audit_log TO erp_app');
        $this->addSql('GRANT INSERT         ON audit_log TO erp_authlog');

        // An immutable fact. A corrected movement is a compensating movement.
        $this->addSql('GRANT SELECT, INSERT ON stock_movement TO erp_app');
        $this->addSql('GRANT SELECT         ON stock_movement TO erp_ai');

        // Documents that are voided or corrected, never removed.
        foreach (['goods_receipt', 'goods_receipt_line', 'fulfilment', 'fulfilment_line', 'invoice'] as $table) {
            $this->addSql("GRANT SELECT, INSERT, UPDATE ON $table TO erp_app");
            $this->addSql("GRANT SELECT ON $table TO erp_ai");
        }

        // doctrine_migration_versions is deliberately absent: W0-a's
        // `GRANT … ON ALL TABLES` had handed erp_app full DML over the migration
        // ledger, which the REVOKE above removes.
    }

    // -----------------------------------------------------------------------
    // Registry and reference data
    // -----------------------------------------------------------------------

    private function createRegistryAndReferenceData(): void
    {
        // ADR-006 V-6: `tenant` stays non-RLS and `slug` is CHECK-constrained.
        // ADR-002 §4's drift query only inspects tables that have a tenant_id,
        // so the registry is exempt from every check written before this one.
        //
        // `status` is ADR-006 V-11's missing column and ADR-007's open item.
        // Tenant closure is a status transition rather than a row delete: a
        // deleted registry row strands queue payloads outside the tenant
        // boundary (O-7) and makes a replayed message run to completion against
        // an empty world (O-8). A status leaves both queryable.
        $this->addSql(<<<SQL
            CREATE TABLE tenant (
                id                 uuid        NOT NULL,
                slug               text        NOT NULL,
                name               text        NOT NULL,
                status             text        NOT NULL DEFAULT 'pending',
                status_changed_at  timestamptz NOT NULL DEFAULT now(),
                created_at         timestamptz NOT NULL DEFAULT now(),
                updated_at         timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT tenant_pkey          PRIMARY KEY (id),
                CONSTRAINT tenant_slug_key      UNIQUE (slug),
                CONSTRAINT tenant_slug_shape    CHECK (slug ~ '^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]\$'),
                CONSTRAINT tenant_status_values CHECK (status IN ('pending', 'active', 'suspended', 'closed', 'sentinel')),
                CONSTRAINT tenant_id_is_uuid_v4 CHECK ({$this->uuidV4('id')})
            )
            SQL);

        // ADR-006 §2.1 step 2. A slug miss still runs the app_user SELECT
        // against a tenant that exists and owns no users, so the statement
        // sequence is identical on every miss path (V-8). Its status is
        // `sentinel`, so V-11 rejects a login against it without a special case.
        $this->addSql(<<<SQL
            INSERT INTO tenant (id, slug, name, status)
            VALUES ('00000000-0000-4000-8000-000000000000', 'sentinel-no-such-tenant', 'Sentinel', 'sentinel')
            SQL);

        // ADR-002 §6: no tenant_id, no RLS, never the tenant side of a
        // composite foreign key.
        $this->addSql(<<<'SQL'
            CREATE TABLE currency (
                code        text     NOT NULL,
                name        text     NOT NULL,
                minor_unit  smallint NOT NULL,
                CONSTRAINT currency_pkey  PRIMARY KEY (code),
                CONSTRAINT currency_shape CHECK (code ~ '^[A-Z]{3}$')
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE country (
                code text NOT NULL,
                name text NOT NULL,
                CONSTRAINT country_pkey  PRIMARY KEY (code),
                CONSTRAINT country_shape CHECK (code ~ '^[A-Z]{2}$')
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE unit_of_measure (
                code       text NOT NULL,
                name       text NOT NULL,
                dimension  text NOT NULL,
                CONSTRAINT unit_of_measure_pkey            PRIMARY KEY (code),
                CONSTRAINT unit_of_measure_dimension_values CHECK (dimension IN ('count', 'mass', 'volume', 'length', 'time'))
            )
            SQL);

        // A permission is a code constant. Duplicating the catalogue per tenant
        // would let two tenants disagree about what `purchase_order.approve`
        // means; the tenant-specific half is role_permission.
        $this->addSql(<<<'SQL'
            CREATE TABLE permission (
                code         text NOT NULL,
                module       text NOT NULL,
                description  text NOT NULL,
                CONSTRAINT permission_pkey PRIMARY KEY (code)
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO currency (code, name, minor_unit) VALUES
                ('EUR', 'Euro', 2), ('GBP', 'Pound Sterling', 2), ('USD', 'US Dollar', 2), ('JPY', 'Yen', 0)
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO country (code, name) VALUES
                ('DE', 'Germany'), ('GB', 'United Kingdom'), ('US', 'United States'), ('PH', 'Philippines')
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO unit_of_measure (code, name, dimension) VALUES
                ('EA', 'Each', 'count'), ('BOX', 'Box', 'count'), ('KG', 'Kilogram', 'mass'),
                ('L', 'Litre', 'volume'), ('M', 'Metre', 'length')
            SQL);
    }

    // -----------------------------------------------------------------------
    // Pre-authentication (ADR-006)
    // -----------------------------------------------------------------------

    private function createPreAuthTables(): void
    {
        // ADR-006 §2.3. One row per key per window, never one row per attempt.
        // The attacker controls slug_normalised, which is part of the primary
        // key, so row creation is gated by the (ip_prefix) limiter and not the
        // (slug, ip) one — 10,000 random slugs from one address would otherwise
        // be 10,000 fresh buckets.
        //
        // Range-partitioned so retention is DROP PARTITION by the maintenance
        // principal and no application role ever needs DELETE (S-9's posture,
        // schema-wide). The partition key must be part of the primary key, which
        // is why window_start is in it.
        $this->addSql(<<<'SQL'
            CREATE TABLE auth_attempt (
                slug_normalised  text        NOT NULL,
                ip_prefix        cidr        NOT NULL,
                window_start     timestamptz NOT NULL,
                attempt_count    integer     NOT NULL DEFAULT 0,
                last_attempt_at  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT auth_attempt_pkey PRIMARY KEY (slug_normalised, ip_prefix, window_start),
                CONSTRAINT auth_attempt_slug_normalised CHECK (slug_normalised = lower(slug_normalised)),
                CONSTRAINT auth_attempt_count_sane      CHECK (attempt_count >= 0)
            ) PARTITION BY RANGE (window_start)
            SQL);

        // A DEFAULT partition so the one unauthenticated endpoint in the system
        // cannot start refusing inserts because a maintenance job stopped. The
        // cost is real and is the reason it must stay near-empty: attaching a
        // new daily partition scans the default partition to prove no row
        // belongs in the new range.
        $this->addSql('CREATE TABLE auth_attempt_default PARTITION OF auth_attempt DEFAULT');

        // ADR-006 §6.6 / S-14. Lockout state lives here and never on app_user.
        // If the pre-auth path incremented a counter on a tenant-owned row, an
        // unauthenticated attacker who knows one valid email could lock a real
        // user out — O-9 with a denial of service attached. Under erp_ratelimit,
        // which holds nothing on any tenant table, that is not code-gated, it is
        // permission denied.
        //
        // email_hash is a keyed hash, never the address: the table would
        // otherwise be an enumerable directory of who has ever tried to log in.
        $this->addSql(<<<'SQL'
            CREATE TABLE auth_lockout (
                slug_normalised   text        NOT NULL,
                email_hash        bytea       NOT NULL,
                failure_count     integer     NOT NULL DEFAULT 0,
                first_failure_at  timestamptz NOT NULL DEFAULT now(),
                last_failure_at   timestamptz NOT NULL DEFAULT now(),
                locked_until      timestamptz,
                CONSTRAINT auth_lockout_pkey PRIMARY KEY (slug_normalised, email_hash),
                CONSTRAINT auth_lockout_slug_normalised CHECK (slug_normalised = lower(slug_normalised)),
                CONSTRAINT auth_lockout_count_sane      CHECK (failure_count >= 0)
            )
            SQL);
        $this->addSql('CREATE INDEX auth_lockout_locked_until_idx ON auth_lockout (locked_until) WHERE locked_until IS NOT NULL');
    }

    // -----------------------------------------------------------------------
    // W0-a's three tables, brought to their final shape
    // -----------------------------------------------------------------------

    private function alterW0aTables(): void
    {
        // --- supplier -----------------------------------------------------
        $this->addSql(<<<'SQL'
            ALTER TABLE supplier
                ADD COLUMN code                text        NOT NULL DEFAULT '',
                ADD COLUMN email               text,
                ADD COLUMN phone               text,
                ADD COLUMN payment_terms_days  integer     NOT NULL DEFAULT 30,
                ADD COLUMN currency_code       text        NOT NULL DEFAULT 'EUR',
                ADD COLUMN is_active           boolean     NOT NULL DEFAULT true,
                ADD COLUMN updated_at          timestamptz NOT NULL DEFAULT now(),
                ADD COLUMN deleted_at          timestamptz
            SQL);
        // The DEFAULTs above exist only to backfill the existing rows; leaving
        // them in place would mean a supplier could be created with no stated
        // currency and be silently priced in euros.
        $this->addSql('ALTER TABLE supplier ALTER COLUMN code DROP DEFAULT, ALTER COLUMN currency_code DROP DEFAULT');
        $this->addSql('ALTER TABLE supplier ADD CONSTRAINT supplier_currency_fk FOREIGN KEY (currency_code) REFERENCES currency (code)');

        // ADR-007 §1: business uniqueness becomes partial the moment a row can
        // be soft-deleted, or "delete a supplier, re-create it with the same
        // code" collides with a row the caller cannot see. The constraint that
        // ADR-002 §5 needs — UNIQUE (tenant_id, id) — is left alone below,
        // because a composite foreign key cannot target a partial index and a
        // child legitimately references a deleted parent.
        $this->addSql('ALTER TABLE supplier DROP CONSTRAINT supplier_tenant_name_key');
        $this->addSql('CREATE UNIQUE INDEX supplier_tenant_name_live ON supplier (tenant_id, name) WHERE deleted_at IS NULL');
        $this->addSql('CREATE UNIQUE INDEX supplier_tenant_code_live ON supplier (tenant_id, code) WHERE deleted_at IS NULL');

        // --- inventory_item ------------------------------------------------
        // qty_on_hand is removed. Stock is held at a location (ARCHITECTURE
        // §5.2, Inventory -> Warehouses), so the quantity belongs on
        // stock_level; a second copy on the product row is a denormalisation
        // with two writers and no owner.
        $this->addSql(<<<'SQL'
            ALTER TABLE inventory_item
                DROP COLUMN qty_on_hand,
                ADD COLUMN name                  text          NOT NULL DEFAULT '',
                ADD COLUMN unit_of_measure_code  text          NOT NULL DEFAULT 'EA',
                ADD COLUMN unit_cost             numeric(19,4) NOT NULL DEFAULT 0,
                ADD COLUMN currency_code         text          NOT NULL DEFAULT 'EUR',
                ADD COLUMN reorder_point         numeric(19,6) NOT NULL DEFAULT 0,
                ADD COLUMN reorder_quantity      numeric(19,6) NOT NULL DEFAULT 0,
                ADD COLUMN safety_stock          numeric(19,6) NOT NULL DEFAULT 0,
                ADD COLUMN is_active             boolean       NOT NULL DEFAULT true,
                ADD COLUMN updated_at            timestamptz   NOT NULL DEFAULT now(),
                ADD COLUMN deleted_at            timestamptz
            SQL);
        $this->addSql('ALTER TABLE inventory_item ALTER COLUMN name DROP DEFAULT, ALTER COLUMN unit_of_measure_code DROP DEFAULT, ALTER COLUMN currency_code DROP DEFAULT');
        $this->addSql('ALTER TABLE inventory_item ADD CONSTRAINT inventory_item_uom_fk      FOREIGN KEY (unit_of_measure_code) REFERENCES unit_of_measure (code)');
        $this->addSql('ALTER TABLE inventory_item ADD CONSTRAINT inventory_item_currency_fk FOREIGN KEY (currency_code)        REFERENCES currency (code)');
        $this->addSql('ALTER TABLE inventory_item DROP CONSTRAINT inventory_item_tenant_sku_key');
        $this->addSql('CREATE UNIQUE INDEX inventory_item_tenant_sku_live ON inventory_item (tenant_id, sku) WHERE deleted_at IS NULL');

        // --- stock_movement -------------------------------------------------
        // integer -> numeric(19,6) is a full table rewrite. S-12 asks for
        // numeric because a movement of 0.5 kg is not an edge case in an ERP.
        $this->addSql('ALTER TABLE stock_movement ALTER COLUMN quantity TYPE numeric(19,6)');
        $this->addSql(<<<'SQL'
            ALTER TABLE stock_movement
                ADD COLUMN inventory_item_id    uuid NOT NULL,
                ADD COLUMN storage_location_id  uuid NOT NULL,
                ADD COLUMN reason               text NOT NULL DEFAULT 'adjustment',
                ADD COLUMN source_type          text,
                ADD COLUMN source_id            uuid
            SQL);
        $this->addSql('ALTER TABLE stock_movement ALTER COLUMN reason DROP DEFAULT');
        $this->addSql(<<<'SQL'
            ALTER TABLE stock_movement ADD CONSTRAINT stock_movement_reason_values
                CHECK (reason IN ('receipt', 'fulfilment', 'transfer_in', 'transfer_out', 'adjustment', 'return'))
            SQL);
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT stock_movement_quantity_nonzero CHECK (quantity <> 0)');
    }

    // -----------------------------------------------------------------------
    // Modules\UserManagement
    // -----------------------------------------------------------------------

    private function createUserManagement(): void
    {
        // The email is stored already normalised and a CHECK holds it that way.
        // ADR-006 §6.4(a) requires the limiter key and the lookup key to agree;
        // normalising once at the edge and pinning the shape in the database is
        // what stops `ACME` buying a fresh rate-limit bucket for `acme`.
        $this->addSql(<<<'SQL'
            CREATE TABLE app_user (
                id             uuid        NOT NULL,
                tenant_id      uuid        NOT NULL,
                email          text        NOT NULL,
                password_hash  text        NOT NULL,
                display_name   text        NOT NULL,
                is_active      boolean     NOT NULL DEFAULT true,
                last_login_at  timestamptz,
                created_at     timestamptz NOT NULL DEFAULT now(),
                updated_at     timestamptz NOT NULL DEFAULT now(),
                deleted_at     timestamptz,
                CONSTRAINT app_user_pkey             PRIMARY KEY (id),
                CONSTRAINT app_user_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT app_user_email_normalised CHECK (email = lower(email)),
                -- ADR-006 §2.1 step 2's sentinel exists so an unresolved slug
                -- still runs this SELECT and finds nothing, which is what makes
                -- the statement sequence identical on every miss path (V-8).
                -- "Owns no app_user rows" is the property the whole equalisation
                -- rests on, and until now nothing stopped one being created.
                CONSTRAINT app_user_not_sentinel_tenant CHECK (tenant_id <> '00000000-0000-4000-8000-000000000000')
            )
            SQL);
        // ADR-002 §5 / §7 of ADR-006: UNIQUE (tenant_id, email) keeps "does this
        // user exist" out of constraint errors, and the live-row predicate is
        // what lets a deactivated address be reissued.
        $this->addSql('CREATE UNIQUE INDEX app_user_tenant_email_live ON app_user (tenant_id, email) WHERE deleted_at IS NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE role (
                id           uuid        NOT NULL,
                tenant_id    uuid        NOT NULL,
                name         text        NOT NULL,
                description  text        NOT NULL DEFAULT '',
                is_system    boolean     NOT NULL DEFAULT false,
                created_at   timestamptz NOT NULL DEFAULT now(),
                updated_at   timestamptz NOT NULL DEFAULT now(),
                deleted_at   timestamptz,
                CONSTRAINT role_pkey             PRIMARY KEY (id),
                CONSTRAINT role_tenant_id_id_key UNIQUE (tenant_id, id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX role_tenant_name_live ON role (tenant_id, name) WHERE deleted_at IS NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE role_permission (
                id               uuid        NOT NULL,
                tenant_id        uuid        NOT NULL,
                role_id          uuid        NOT NULL,
                permission_code  text        NOT NULL,
                created_at       timestamptz NOT NULL DEFAULT now(),
                updated_at       timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT role_permission_pkey             PRIMARY KEY (id),
                CONSTRAINT role_permission_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT role_permission_unique           UNIQUE (tenant_id, role_id, permission_code),
                CONSTRAINT role_permission_role_fk          FOREIGN KEY (tenant_id, role_id) REFERENCES role (tenant_id, id),
                CONSTRAINT role_permission_permission_fk    FOREIGN KEY (permission_code) REFERENCES permission (code)
            )
            SQL);

        // Two tenant-scoped parents on one mapped entity — the shape that
        // disqualified the composite identifier (A1.6) and that W0-a never
        // exercised, because its second composite key was unmapped.
        $this->addSql(<<<'SQL'
            CREATE TABLE user_role (
                id          uuid        NOT NULL,
                tenant_id   uuid        NOT NULL,
                user_id     uuid        NOT NULL,
                role_id     uuid        NOT NULL,
                created_at  timestamptz NOT NULL DEFAULT now(),
                updated_at  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT user_role_pkey             PRIMARY KEY (id),
                CONSTRAINT user_role_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT user_role_unique           UNIQUE (tenant_id, user_id, role_id),
                CONSTRAINT user_role_user_fk          FOREIGN KEY (tenant_id, user_id) REFERENCES app_user (tenant_id, id),
                CONSTRAINT user_role_role_fk          FOREIGN KEY (tenant_id, role_id) REFERENCES role     (tenant_id, id)
            )
            SQL);
        // "Who holds this role" — the reverse of the unique index's prefix.
        $this->addSql('CREATE INDEX user_role_tenant_role_idx ON user_role (tenant_id, role_id)');
    }

    // -----------------------------------------------------------------------
    // Modules\AuditLog
    // -----------------------------------------------------------------------

    private function createAuditLog(): void
    {
        // Every field is server-generated (ADR-006 §2.2), which is what retires
        // audit-log injection as a category rather than mitigating it.
        //
        // actor_user_id is nullable: a system action has no actor, and
        // actor_label carries the display name as it was, so a renamed or
        // soft-deleted user does not rewrite history.
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_log (
                id             uuid        NOT NULL,
                tenant_id      uuid        NOT NULL,
                occurred_at    timestamptz NOT NULL DEFAULT now(),
                event_type     text        NOT NULL,
                actor_user_id  uuid,
                actor_label    text        NOT NULL,
                subject_type   text,
                subject_id     uuid,
                ip_address     inet,
                metadata       jsonb       NOT NULL DEFAULT '{}'::jsonb,
                CONSTRAINT audit_log_pkey             PRIMARY KEY (id),
                CONSTRAINT audit_log_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT audit_log_actor_fk         FOREIGN KEY (tenant_id, actor_user_id) REFERENCES app_user (tenant_id, id),
                CONSTRAINT audit_log_subject_pair     CHECK ((subject_type IS NULL) = (subject_id IS NULL))
            )
            SQL);
        // No index here declares DESC. A B-tree is scanned backwards at the same
        // cost, so `ORDER BY occurred_at DESC` within one tenant is served by the
        // ascending index; declaring a direction would buy nothing and would put
        // a shape into the catalogue that the ORM attributes cannot express,
        // which is diff noise for no gain.
        //
        // "The tenant's audit trail, newest first" — the default screen.
        $this->addSql('CREATE INDEX audit_log_tenant_occurred_idx ON audit_log (tenant_id, occurred_at)');
        // "What did this user do" — the access review.
        $this->addSql('CREATE INDEX audit_log_tenant_actor_idx    ON audit_log (tenant_id, actor_user_id, occurred_at)');
        // "The history of this purchase order" — the per-record timeline.
        $this->addSql('CREATE INDEX audit_log_tenant_subject_idx  ON audit_log (tenant_id, subject_type, subject_id, occurred_at)');
        // "Every failed login in the last hour" — ADR-006 §2.2's whole point.
        $this->addSql('CREATE INDEX audit_log_tenant_event_idx    ON audit_log (tenant_id, event_type, occurred_at)');
    }

    // -----------------------------------------------------------------------
    // Modules\Warehouses
    // -----------------------------------------------------------------------

    private function createWarehouses(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE warehouse (
                id            uuid        NOT NULL,
                tenant_id     uuid        NOT NULL,
                code          text        NOT NULL,
                name          text        NOT NULL,
                address_line  text        NOT NULL DEFAULT '',
                city          text        NOT NULL DEFAULT '',
                postal_code   text        NOT NULL DEFAULT '',
                country_code  text        NOT NULL,
                is_active     boolean     NOT NULL DEFAULT true,
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now(),
                deleted_at    timestamptz,
                CONSTRAINT warehouse_pkey             PRIMARY KEY (id),
                CONSTRAINT warehouse_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT warehouse_country_fk       FOREIGN KEY (country_code) REFERENCES country (code)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX warehouse_tenant_code_live ON warehouse (tenant_id, code) WHERE deleted_at IS NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE storage_location (
                id            uuid        NOT NULL,
                tenant_id     uuid        NOT NULL,
                warehouse_id  uuid        NOT NULL,
                code          text        NOT NULL,
                kind          text        NOT NULL DEFAULT 'bin',
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now(),
                deleted_at    timestamptz,
                CONSTRAINT storage_location_pkey             PRIMARY KEY (id),
                CONSTRAINT storage_location_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT storage_location_warehouse_fk     FOREIGN KEY (tenant_id, warehouse_id) REFERENCES warehouse (tenant_id, id),
                CONSTRAINT storage_location_kind_values      CHECK (kind IN ('bin', 'shelf', 'zone', 'staging', 'quarantine'))
            )
            SQL);
        // "The bins in this warehouse, by code" — and the uniqueness that stops
        // two bins sharing a label while one of them is deleted.
        $this->addSql('CREATE UNIQUE INDEX storage_location_tenant_warehouse_code_live ON storage_location (tenant_id, warehouse_id, code) WHERE deleted_at IS NULL');
    }

    // -----------------------------------------------------------------------
    // Modules\Inventory
    // -----------------------------------------------------------------------

    private function createInventory(): void
    {
        // The contended row in this system (ARCHITECTURE W-5, S-12). `version`
        // is the optimistic lock; the unique key is also the upsert key.
        $this->addSql(<<<'SQL'
            CREATE TABLE stock_level (
                id                   uuid          NOT NULL,
                tenant_id            uuid          NOT NULL,
                inventory_item_id    uuid          NOT NULL,
                storage_location_id  uuid          NOT NULL,
                qty_on_hand          numeric(19,6) NOT NULL DEFAULT 0,
                qty_reserved         numeric(19,6) NOT NULL DEFAULT 0,
                version              integer       NOT NULL DEFAULT 1,
                created_at           timestamptz   NOT NULL DEFAULT now(),
                updated_at           timestamptz   NOT NULL DEFAULT now(),
                CONSTRAINT stock_level_pkey             PRIMARY KEY (id),
                CONSTRAINT stock_level_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT stock_level_unique           UNIQUE (tenant_id, inventory_item_id, storage_location_id),
                CONSTRAINT stock_level_item_fk          FOREIGN KEY (tenant_id, inventory_item_id)   REFERENCES inventory_item   (tenant_id, id),
                CONSTRAINT stock_level_location_fk      FOREIGN KEY (tenant_id, storage_location_id) REFERENCES storage_location (tenant_id, id),
                CONSTRAINT stock_level_reserved_sane    CHECK (qty_reserved >= 0 AND qty_reserved <= qty_on_hand)
            )
            SQL);
        // "What is physically in this bin" — the pick list, and the reverse of
        // the unique key's prefix.
        $this->addSql('CREATE INDEX stock_level_tenant_location_idx ON stock_level (tenant_id, storage_location_id, inventory_item_id)');

        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT stock_movement_item_fk     FOREIGN KEY (tenant_id, inventory_item_id)   REFERENCES inventory_item   (tenant_id, id)');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT stock_movement_location_fk FOREIGN KEY (tenant_id, storage_location_id) REFERENCES storage_location (tenant_id, id)');
        // "The movement history of this SKU" — ADR-004's forecasting input, and
        // the most-run query in the system after the order lists.
        $this->addSql('CREATE INDEX stock_movement_tenant_item_idx   ON stock_movement (tenant_id, inventory_item_id, occurred_at)');
        // "Did this goods receipt already post its movements" — the idempotency
        // check a retried message needs (ARCHITECTURE O-6).
        $this->addSql('CREATE INDEX stock_movement_tenant_source_idx ON stock_movement (tenant_id, source_type, source_id) WHERE source_id IS NOT NULL');
        // Two W0-a indexes are dropped here rather than inherited. The ledger's
        // requirement for Output 2 is that indexes come from query shapes, and
        // neither of these has one: nothing sorts a product or supplier list by
        // creation date, and ordering either list by its business key is already
        // served by the live unique index on (tenant_id, sku) / (tenant_id, name).
        // An index nobody can name a query for is a write cost with no reader,
        // and inheriting it because it was already there is how index bloat
        // starts.
        $this->addSql('DROP INDEX inventory_item_tenant_created_idx');
        $this->addSql('DROP INDEX supplier_tenant_created_idx');
    }

    // -----------------------------------------------------------------------
    // Modules\Suppliers
    // -----------------------------------------------------------------------

    private function createSuppliers(): void
    {
        // lead_time_days carries no DEFAULT on purpose. A hard-coded lead time
        // is the risk the ledger moved into Output 9's acceptance criteria
        // (was R-24); refusing a default makes "we did not know it" a failed
        // INSERT rather than a silently plausible forecast.
        $this->addSql(<<<'SQL'
            CREATE TABLE supplier_product (
                id                  uuid          NOT NULL,
                tenant_id           uuid          NOT NULL,
                supplier_id         uuid          NOT NULL,
                inventory_item_id   uuid          NOT NULL,
                supplier_sku        text          NOT NULL,
                lead_time_days      integer       NOT NULL,
                min_order_quantity  numeric(19,6) NOT NULL DEFAULT 1,
                unit_cost           numeric(19,4) NOT NULL,
                currency_code       text          NOT NULL,
                created_at          timestamptz   NOT NULL DEFAULT now(),
                updated_at          timestamptz   NOT NULL DEFAULT now(),
                CONSTRAINT supplier_product_pkey             PRIMARY KEY (id),
                CONSTRAINT supplier_product_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT supplier_product_unique           UNIQUE (tenant_id, supplier_id, inventory_item_id),
                CONSTRAINT supplier_product_supplier_fk      FOREIGN KEY (tenant_id, supplier_id)       REFERENCES supplier       (tenant_id, id),
                CONSTRAINT supplier_product_item_fk          FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_item (tenant_id, id),
                CONSTRAINT supplier_product_currency_fk      FOREIGN KEY (currency_code)                REFERENCES currency       (code),
                CONSTRAINT supplier_product_lead_time_sane   CHECK (lead_time_days >= 0)
            )
            SQL);
        // "Which suppliers can provide this item, and which is fastest" —
        // ADR-004's named dependency, and the reverse of the unique key.
        $this->addSql('CREATE INDEX supplier_product_tenant_item_idx ON supplier_product (tenant_id, inventory_item_id, lead_time_days)');
    }

    // -----------------------------------------------------------------------
    // Modules\Purchasing
    // -----------------------------------------------------------------------

    private function createPurchasing(): void
    {
        // Three tenant-scoped parents, all mapped: supplier, warehouse and the
        // ordering user. This is the entity Step 3 exists to test.
        $this->addSql(<<<'SQL'
            CREATE TABLE purchase_order (
                id                  uuid          NOT NULL,
                tenant_id           uuid          NOT NULL,
                order_number        text          NOT NULL,
                supplier_id         uuid          NOT NULL,
                warehouse_id        uuid          NOT NULL,
                created_by_user_id  uuid,
                status              text          NOT NULL DEFAULT 'draft',
                currency_code       text          NOT NULL,
                total_amount        numeric(19,4) NOT NULL DEFAULT 0,
                ordered_at          timestamptz,
                expected_at         timestamptz,
                created_at          timestamptz   NOT NULL DEFAULT now(),
                updated_at          timestamptz   NOT NULL DEFAULT now(),
                deleted_at          timestamptz,
                CONSTRAINT purchase_order_pkey             PRIMARY KEY (id),
                CONSTRAINT purchase_order_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT purchase_order_supplier_fk      FOREIGN KEY (tenant_id, supplier_id)        REFERENCES supplier  (tenant_id, id),
                CONSTRAINT purchase_order_warehouse_fk     FOREIGN KEY (tenant_id, warehouse_id)       REFERENCES warehouse (tenant_id, id),
                CONSTRAINT purchase_order_creator_fk       FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES app_user  (tenant_id, id),
                CONSTRAINT purchase_order_currency_fk      FOREIGN KEY (currency_code)                 REFERENCES currency  (code),
                CONSTRAINT purchase_order_status_values    CHECK (status IN ('draft', 'ordered', 'partially_received', 'received', 'cancelled')),
                CONSTRAINT purchase_order_ordered_at_set   CHECK (status = 'draft' OR ordered_at IS NOT NULL)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX purchase_order_tenant_number_live ON purchase_order (tenant_id, order_number) WHERE deleted_at IS NULL');
        // "Open purchase orders, oldest first" — the buyer's work queue.
        $this->addSql("CREATE INDEX purchase_order_tenant_open_idx ON purchase_order (tenant_id, ordered_at) WHERE deleted_at IS NULL AND status IN ('ordered', 'partially_received')");
        // "Everything we have ordered from this supplier" — the supplier screen.
        $this->addSql('CREATE INDEX purchase_order_tenant_supplier_idx ON purchase_order (tenant_id, supplier_id, ordered_at) WHERE deleted_at IS NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE purchase_order_line (
                id                 uuid          NOT NULL,
                tenant_id          uuid          NOT NULL,
                purchase_order_id  uuid          NOT NULL,
                inventory_item_id  uuid          NOT NULL,
                line_number        integer       NOT NULL,
                quantity_ordered   numeric(19,6) NOT NULL,
                quantity_received  numeric(19,6) NOT NULL DEFAULT 0,
                unit_cost          numeric(19,4) NOT NULL,
                lead_time_days     integer       NOT NULL,
                created_at         timestamptz   NOT NULL DEFAULT now(),
                updated_at         timestamptz   NOT NULL DEFAULT now(),
                deleted_at         timestamptz,
                CONSTRAINT purchase_order_line_pkey             PRIMARY KEY (id),
                CONSTRAINT purchase_order_line_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT purchase_order_line_order_fk         FOREIGN KEY (tenant_id, purchase_order_id) REFERENCES purchase_order (tenant_id, id),
                CONSTRAINT purchase_order_line_item_fk          FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_item (tenant_id, id),
                CONSTRAINT purchase_order_line_qty_sane         CHECK (quantity_ordered > 0 AND quantity_received >= 0)
            )
            SQL);
        // A discarded line number can be reused; a live one cannot be taken twice.
        $this->addSql('CREATE UNIQUE INDEX purchase_order_line_unique ON purchase_order_line (tenant_id, purchase_order_id, line_number) WHERE deleted_at IS NULL');
        // "How much of this item is already on order" — the reorder-point
        // calculation's other input (ADR-004), and the reason a reorder run does
        // not raise a second purchase order for stock already coming.
        //
        // Partial, and that is the whole of the fix `RED` demanded: without the
        // predicate this index serves a query that counts supply from discarded
        // purchase orders, suppresses a legitimate reorder, and shows up weeks
        // later as a stockout nobody can explain.
        $this->addSql('CREATE INDEX purchase_order_line_tenant_item_idx ON purchase_order_line (tenant_id, inventory_item_id) WHERE deleted_at IS NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE goods_receipt (
                id                    uuid        NOT NULL,
                tenant_id             uuid        NOT NULL,
                purchase_order_id     uuid        NOT NULL,
                warehouse_id          uuid        NOT NULL,
                received_by_user_id   uuid,
                receipt_number        text        NOT NULL,
                received_at           timestamptz NOT NULL DEFAULT now(),
                created_at            timestamptz NOT NULL DEFAULT now(),
                updated_at            timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT goods_receipt_pkey             PRIMARY KEY (id),
                CONSTRAINT goods_receipt_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT goods_receipt_number_unique    UNIQUE (tenant_id, receipt_number),
                CONSTRAINT goods_receipt_order_fk         FOREIGN KEY (tenant_id, purchase_order_id)   REFERENCES purchase_order (tenant_id, id),
                CONSTRAINT goods_receipt_warehouse_fk     FOREIGN KEY (tenant_id, warehouse_id)        REFERENCES warehouse      (tenant_id, id),
                CONSTRAINT goods_receipt_receiver_fk      FOREIGN KEY (tenant_id, received_by_user_id) REFERENCES app_user       (tenant_id, id)
            )
            SQL);
        // "What has been received against this order" — the receiving screen.
        $this->addSql('CREATE INDEX goods_receipt_tenant_order_idx ON goods_receipt (tenant_id, purchase_order_id, received_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE goods_receipt_line (
                id                      uuid          NOT NULL,
                tenant_id               uuid          NOT NULL,
                goods_receipt_id        uuid          NOT NULL,
                purchase_order_line_id  uuid          NOT NULL,
                storage_location_id     uuid          NOT NULL,
                quantity_received       numeric(19,6) NOT NULL,
                created_at              timestamptz   NOT NULL DEFAULT now(),
                updated_at              timestamptz   NOT NULL DEFAULT now(),
                CONSTRAINT goods_receipt_line_pkey             PRIMARY KEY (id),
                CONSTRAINT goods_receipt_line_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT goods_receipt_line_receipt_fk       FOREIGN KEY (tenant_id, goods_receipt_id)       REFERENCES goods_receipt       (tenant_id, id),
                CONSTRAINT goods_receipt_line_po_line_fk       FOREIGN KEY (tenant_id, purchase_order_line_id) REFERENCES purchase_order_line (tenant_id, id),
                CONSTRAINT goods_receipt_line_location_fk      FOREIGN KEY (tenant_id, storage_location_id)    REFERENCES storage_location    (tenant_id, id),
                CONSTRAINT goods_receipt_line_qty_positive     CHECK (quantity_received > 0)
            )
            SQL);
        $this->addSql('CREATE INDEX goods_receipt_line_tenant_receipt_idx ON goods_receipt_line (tenant_id, goods_receipt_id)');
        // "How much of this order line has arrived" — drives the PO status.
        $this->addSql('CREATE INDEX goods_receipt_line_tenant_po_line_idx ON goods_receipt_line (tenant_id, purchase_order_line_id)');
    }

    // -----------------------------------------------------------------------
    // Modules\Customers
    // -----------------------------------------------------------------------

    private function createCustomers(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE customer (
                id                  uuid          NOT NULL,
                tenant_id           uuid          NOT NULL,
                code                text          NOT NULL,
                name                text          NOT NULL,
                email               text,
                phone               text,
                credit_limit        numeric(19,4) NOT NULL DEFAULT 0,
                currency_code       text          NOT NULL,
                payment_terms_days  integer       NOT NULL DEFAULT 30,
                is_active           boolean       NOT NULL DEFAULT true,
                created_at          timestamptz   NOT NULL DEFAULT now(),
                updated_at          timestamptz   NOT NULL DEFAULT now(),
                deleted_at          timestamptz,
                CONSTRAINT customer_pkey             PRIMARY KEY (id),
                CONSTRAINT customer_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT customer_currency_fk      FOREIGN KEY (currency_code) REFERENCES currency (code),
                CONSTRAINT customer_credit_sane      CHECK (credit_limit >= 0)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX customer_tenant_code_live ON customer (tenant_id, code) WHERE deleted_at IS NULL');
        // "The customer list, alphabetical" — the module's landing screen, and
        // the only sort a user ever asks for on it.
        $this->addSql('CREATE INDEX customer_tenant_name_live ON customer (tenant_id, name) WHERE deleted_at IS NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE customer_contact (
                id           uuid        NOT NULL,
                tenant_id    uuid        NOT NULL,
                customer_id  uuid        NOT NULL,
                name         text        NOT NULL,
                email        text,
                phone        text,
                is_primary   boolean     NOT NULL DEFAULT false,
                created_at   timestamptz NOT NULL DEFAULT now(),
                updated_at   timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT customer_contact_pkey             PRIMARY KEY (id),
                CONSTRAINT customer_contact_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT customer_contact_customer_fk      FOREIGN KEY (tenant_id, customer_id) REFERENCES customer (tenant_id, id)
            )
            SQL);
        // "The people at this account" — always reached from the customer.
        $this->addSql('CREATE INDEX customer_contact_tenant_customer_idx ON customer_contact (tenant_id, customer_id)');

        // Hard-deletable precisely because sales_order snapshots the address it
        // shipped to. An order pointing at a live address row would rewrite
        // where a past shipment went every time the customer moves.
        $this->addSql(<<<'SQL'
            CREATE TABLE customer_address (
                id            uuid        NOT NULL,
                tenant_id     uuid        NOT NULL,
                customer_id   uuid        NOT NULL,
                kind          text        NOT NULL,
                address_line  text        NOT NULL,
                city          text        NOT NULL,
                region        text        NOT NULL DEFAULT '',
                postal_code   text        NOT NULL,
                country_code  text        NOT NULL,
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT customer_address_pkey             PRIMARY KEY (id),
                CONSTRAINT customer_address_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT customer_address_customer_fk      FOREIGN KEY (tenant_id, customer_id) REFERENCES customer (tenant_id, id),
                CONSTRAINT customer_address_country_fk       FOREIGN KEY (country_code)           REFERENCES country  (code),
                CONSTRAINT customer_address_kind_values      CHECK (kind IN ('billing', 'shipping'))
            )
            SQL);
        $this->addSql('CREATE INDEX customer_address_tenant_customer_idx ON customer_address (tenant_id, customer_id, kind)');
    }

    // -----------------------------------------------------------------------
    // Modules\Sales
    // -----------------------------------------------------------------------

    private function createSales(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sales_order (
                id                  uuid          NOT NULL,
                tenant_id           uuid          NOT NULL,
                order_number        text          NOT NULL,
                customer_id         uuid          NOT NULL,
                warehouse_id        uuid          NOT NULL,
                created_by_user_id  uuid,
                status              text          NOT NULL DEFAULT 'draft',
                currency_code       text          NOT NULL,
                total_amount        numeric(19,4) NOT NULL DEFAULT 0,
                ordered_at          timestamptz,
                ship_to_line        text          NOT NULL DEFAULT '',
                ship_to_city        text          NOT NULL DEFAULT '',
                ship_to_postal_code text          NOT NULL DEFAULT '',
                ship_to_country_code text         NOT NULL,
                created_at          timestamptz   NOT NULL DEFAULT now(),
                updated_at          timestamptz   NOT NULL DEFAULT now(),
                deleted_at          timestamptz,
                CONSTRAINT sales_order_pkey             PRIMARY KEY (id),
                CONSTRAINT sales_order_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT sales_order_customer_fk      FOREIGN KEY (tenant_id, customer_id)        REFERENCES customer  (tenant_id, id),
                CONSTRAINT sales_order_warehouse_fk     FOREIGN KEY (tenant_id, warehouse_id)       REFERENCES warehouse (tenant_id, id),
                CONSTRAINT sales_order_creator_fk       FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES app_user  (tenant_id, id),
                CONSTRAINT sales_order_currency_fk      FOREIGN KEY (currency_code)                 REFERENCES currency  (code),
                CONSTRAINT sales_order_country_fk       FOREIGN KEY (ship_to_country_code)          REFERENCES country   (code),
                CONSTRAINT sales_order_status_values    CHECK (status IN ('draft', 'confirmed', 'partially_fulfilled', 'fulfilled', 'cancelled'))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX sales_order_tenant_number_live ON sales_order (tenant_id, order_number) WHERE deleted_at IS NULL');
        // "Orders waiting to ship" — the warehouse's work queue.
        $this->addSql("CREATE INDEX sales_order_tenant_open_idx ON sales_order (tenant_id, ordered_at) WHERE deleted_at IS NULL AND status IN ('confirmed', 'partially_fulfilled')");
        // "This customer's order history" — the account screen.
        $this->addSql('CREATE INDEX sales_order_tenant_customer_idx ON sales_order (tenant_id, customer_id, ordered_at) WHERE deleted_at IS NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE sales_order_line (
                id                  uuid          NOT NULL,
                tenant_id           uuid          NOT NULL,
                sales_order_id      uuid          NOT NULL,
                inventory_item_id   uuid          NOT NULL,
                line_number         integer       NOT NULL,
                quantity            numeric(19,6) NOT NULL,
                quantity_fulfilled  numeric(19,6) NOT NULL DEFAULT 0,
                unit_price          numeric(19,4) NOT NULL,
                created_at          timestamptz   NOT NULL DEFAULT now(),
                updated_at          timestamptz   NOT NULL DEFAULT now(),
                deleted_at          timestamptz,
                CONSTRAINT sales_order_line_pkey             PRIMARY KEY (id),
                CONSTRAINT sales_order_line_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT sales_order_line_order_fk         FOREIGN KEY (tenant_id, sales_order_id)    REFERENCES sales_order    (tenant_id, id),
                CONSTRAINT sales_order_line_item_fk          FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_item (tenant_id, id),
                CONSTRAINT sales_order_line_qty_sane         CHECK (quantity > 0 AND quantity_fulfilled >= 0 AND quantity_fulfilled <= quantity)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX sales_order_line_unique ON sales_order_line (tenant_id, sales_order_id, line_number) WHERE deleted_at IS NULL');
        // "Demand history for this SKU" — ADR-004's forecast input, and partial
        // for the same reason as the purchasing side: demand from a discarded
        // order is not demand.
        $this->addSql('CREATE INDEX sales_order_line_tenant_item_idx ON sales_order_line (tenant_id, inventory_item_id) WHERE deleted_at IS NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE fulfilment (
                id                  uuid        NOT NULL,
                tenant_id           uuid        NOT NULL,
                sales_order_id      uuid        NOT NULL,
                warehouse_id        uuid        NOT NULL,
                fulfilment_number   text        NOT NULL,
                shipped_at          timestamptz NOT NULL DEFAULT now(),
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fulfilment_pkey             PRIMARY KEY (id),
                CONSTRAINT fulfilment_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT fulfilment_number_unique    UNIQUE (tenant_id, fulfilment_number),
                CONSTRAINT fulfilment_order_fk         FOREIGN KEY (tenant_id, sales_order_id) REFERENCES sales_order (tenant_id, id),
                CONSTRAINT fulfilment_warehouse_fk     FOREIGN KEY (tenant_id, warehouse_id)   REFERENCES warehouse   (tenant_id, id)
            )
            SQL);
        $this->addSql('CREATE INDEX fulfilment_tenant_order_idx ON fulfilment (tenant_id, sales_order_id, shipped_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE fulfilment_line (
                id                   uuid          NOT NULL,
                tenant_id            uuid          NOT NULL,
                fulfilment_id        uuid          NOT NULL,
                sales_order_line_id  uuid          NOT NULL,
                storage_location_id  uuid          NOT NULL,
                quantity             numeric(19,6) NOT NULL,
                created_at           timestamptz   NOT NULL DEFAULT now(),
                updated_at           timestamptz   NOT NULL DEFAULT now(),
                CONSTRAINT fulfilment_line_pkey             PRIMARY KEY (id),
                CONSTRAINT fulfilment_line_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT fulfilment_line_fulfilment_fk    FOREIGN KEY (tenant_id, fulfilment_id)       REFERENCES fulfilment       (tenant_id, id),
                CONSTRAINT fulfilment_line_order_line_fk    FOREIGN KEY (tenant_id, sales_order_line_id) REFERENCES sales_order_line (tenant_id, id),
                CONSTRAINT fulfilment_line_location_fk      FOREIGN KEY (tenant_id, storage_location_id) REFERENCES storage_location (tenant_id, id),
                CONSTRAINT fulfilment_line_qty_positive     CHECK (quantity > 0)
            )
            SQL);
        $this->addSql('CREATE INDEX fulfilment_line_tenant_fulfilment_idx ON fulfilment_line (tenant_id, fulfilment_id)');
        $this->addSql('CREATE INDEX fulfilment_line_tenant_order_line_idx ON fulfilment_line (tenant_id, sales_order_line_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE invoice (
                id              uuid          NOT NULL,
                tenant_id       uuid          NOT NULL,
                sales_order_id  uuid          NOT NULL,
                customer_id     uuid          NOT NULL,
                invoice_number  text          NOT NULL,
                status          text          NOT NULL DEFAULT 'issued',
                currency_code   text          NOT NULL,
                total_amount    numeric(19,4) NOT NULL,
                issued_at       timestamptz   NOT NULL DEFAULT now(),
                due_at          timestamptz   NOT NULL,
                paid_at         timestamptz,
                created_at      timestamptz   NOT NULL DEFAULT now(),
                updated_at      timestamptz   NOT NULL DEFAULT now(),
                CONSTRAINT invoice_pkey             PRIMARY KEY (id),
                CONSTRAINT invoice_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT invoice_number_unique    UNIQUE (tenant_id, invoice_number),
                CONSTRAINT invoice_order_fk         FOREIGN KEY (tenant_id, sales_order_id) REFERENCES sales_order (tenant_id, id),
                CONSTRAINT invoice_customer_fk      FOREIGN KEY (tenant_id, customer_id)    REFERENCES customer    (tenant_id, id),
                CONSTRAINT invoice_currency_fk      FOREIGN KEY (currency_code)             REFERENCES currency    (code),
                CONSTRAINT invoice_status_values    CHECK (status IN ('issued', 'paid', 'overdue', 'void')),
                CONSTRAINT invoice_paid_at_set      CHECK (status <> 'paid' OR paid_at IS NOT NULL)
            )
            SQL);
        // "Overdue invoices, oldest first" — ADR-004's at-risk-account signal
        // and the collections screen, in one index.
        $this->addSql("CREATE INDEX invoice_tenant_due_idx ON invoice (tenant_id, due_at) WHERE status IN ('issued', 'overdue')");
        // "This account's invoices" — the customer screen.
        $this->addSql('CREATE INDEX invoice_tenant_customer_idx ON invoice (tenant_id, customer_id, issued_at)');
    }

    // -----------------------------------------------------------------------
    // The boundary, applied uniformly
    // -----------------------------------------------------------------------

    private function applyRowLevelSecurity(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            // ENABLE alone leaves the owner unfiltered (§C-2), and migrations
            // run as the owner.
            $this->addSql("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            $this->addSql("ALTER TABLE $table FORCE  ROW LEVEL SECURITY");

            // supplier, inventory_item and stock_movement already carry one
            // from W0-a; the others are new.
            if (!\in_array($table, ['supplier', 'inventory_item', 'stock_movement'], true)) {
                $this->addSql(<<<SQL
                    CREATE POLICY tenant_isolation ON $table
                        USING      ({$this->policy()})
                        WITH CHECK ({$this->policy()})
                    SQL);
            }

            // Every tenant-scoped row now has to name a tenant that exists.
            // Without it, set_config with any random UUID lets a caller create
            // rows in a tenant that was never provisioned, and a replayed
            // message for a closed tenant runs to completion against an empty
            // world instead of failing (ARCHITECTURE O-8).
            $this->addSql("ALTER TABLE $table ADD CONSTRAINT {$table}_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)");

            // D-12. Type `uuid` is not enough: UUIDv7 is also type uuid, and it
            // is time-ordered, so a future change made for index locality would
            // reopen the enumeration channel while passing a type check.
            $this->addSql("ALTER TABLE $table ADD CONSTRAINT {$table}_id_is_uuid_v4 CHECK ({$this->uuidV4('id')})");
        }
    }

    /**
     * Soft-deleting an order soft-deletes its lines, in the database.
     *
     * `RED` found this and it is the finding it would have rejected the wave
     * on. Before it, `purchase_order` was soft-deleted and
     * `purchase_order_line` was not, so a discarded order left live lines
     * behind -- and `purchase_order_line_tenant_item_idx` exists precisely to
     * answer "how much of this item is already on order", which is the query
     * that then counts supply that will never arrive, suppresses a legitimate
     * reorder, and surfaces weeks later as a stockout nobody can explain.
     *
     * "The deletion filter applies at the aggregate root" was the answer, and
     * it was a convention. ADR-007's own thesis is that a control enforced by a
     * grant is a control and one enforced by a convention is a hope, so this is
     * a trigger: the line follows the order whatever the caller does, including
     * a raw DBAL UPDATE that never touches the ORM.
     *
     * SECURITY INVOKER (the default) on purpose. The function runs as erp_app
     * under RLS like anything else, so it cannot reach another tenant's lines
     * even though it is the database doing the writing -- and T-5's "no
     * SECURITY DEFINER objects" assertion stays true.
     *
     * Restoration is symmetric: clearing the order's deleted_at clears its
     * lines'. ADR-007 §4 leaves whether restore is *offered* to Output 17; this
     * only makes sure that if it is, the aggregate comes back whole.
     */
    private function cascadeDeletionToOrderLines(): void
    {
        $this->addSql(<<<'SQL'
            CREATE FUNCTION cascade_deletion_to_lines() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                EXECUTE format(
                    'UPDATE %I SET deleted_at = $1, updated_at = now() '
                    'WHERE tenant_id = $2 AND %I = $3 AND deleted_at IS DISTINCT FROM $1',
                    TG_ARGV[0], TG_ARGV[1]
                ) USING NEW.deleted_at, NEW.tenant_id, NEW.id;

                RETURN NULL;
            END;
            $$
            SQL);

        // Deny-by-default reaches functions too: EXECUTE is granted to PUBLIC
        // by default, which is the same shape as the table grants this
        // migration spent its first statements withdrawing.
        $this->addSql('REVOKE ALL ON FUNCTION cascade_deletion_to_lines() FROM PUBLIC');
        $this->addSql('GRANT EXECUTE ON FUNCTION cascade_deletion_to_lines() TO erp_app');

        foreach ([
            'purchase_order' => ['purchase_order_line', 'purchase_order_id'],
            'sales_order' => ['sales_order_line', 'sales_order_id'],
        ] as $parent => [$child, $column]) {
            // AFTER UPDATE OF deleted_at, and guarded by WHEN, so an ordinary
            // status change on a thousand-line order costs nothing.
            $this->addSql(<<<SQL
                CREATE TRIGGER {$parent}_cascade_deletion
                AFTER UPDATE OF deleted_at ON $parent
                FOR EACH ROW WHEN (OLD.deleted_at IS DISTINCT FROM NEW.deleted_at)
                EXECUTE FUNCTION cascade_deletion_to_lines('$child', '$column')
                SQL);
        }
    }

    private function uuidV4(string $column): string
    {
        return sprintf(self::UUID_V4, $column);
    }

    private function policy(): string
    {
        return self::POLICY;
    }

    public function down(Schema $schema): void
    {
        foreach (array_reverse(self::TENANT_TABLES) as $table) {
            $this->addSql("ALTER TABLE $table DROP CONSTRAINT IF EXISTS {$table}_tenant_fk");
            $this->addSql("ALTER TABLE $table DROP CONSTRAINT IF EXISTS {$table}_id_is_uuid_v4");
        }

        foreach ([
            'invoice', 'fulfilment_line', 'fulfilment', 'sales_order_line', 'sales_order',
            'customer_address', 'customer_contact', 'customer',
            'goods_receipt_line', 'goods_receipt', 'purchase_order_line', 'purchase_order',
            'supplier_product', 'stock_level', 'storage_location', 'warehouse',
            'audit_log', 'user_role', 'role_permission', 'role', 'app_user',
            'auth_lockout', 'auth_attempt', 'permission', 'unit_of_measure', 'country', 'currency',
        ] as $table) {
            $this->addSql("DROP TABLE IF EXISTS $table CASCADE");
        }

        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT IF EXISTS stock_movement_reason_values');
        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT IF EXISTS stock_movement_quantity_nonzero');
        $this->addSql('ALTER TABLE stock_movement DROP COLUMN IF EXISTS inventory_item_id, DROP COLUMN IF EXISTS storage_location_id, DROP COLUMN IF EXISTS reason, DROP COLUMN IF EXISTS source_type, DROP COLUMN IF EXISTS source_id');
        $this->addSql('ALTER TABLE stock_movement ALTER COLUMN quantity TYPE integer');

        // Reversing an index *drop* is the half that is easy to forget, and it
        // is the half that breaks a down/up round trip: up() removes two W0-a
        // indexes it judged unjustified, so down() has to put them back or the
        // next up() fails on `index ... does not exist`. Found by running the
        // round trip, not by reading it.
        $this->addSql('CREATE INDEX inventory_item_tenant_created_idx ON inventory_item (tenant_id, created_at)');
        $this->addSql('CREATE INDEX supplier_tenant_created_idx       ON supplier       (tenant_id, created_at)');

        $this->addSql('DROP INDEX IF EXISTS inventory_item_tenant_sku_live');
        $this->addSql('ALTER TABLE inventory_item DROP COLUMN IF EXISTS name, DROP COLUMN IF EXISTS unit_of_measure_code, DROP COLUMN IF EXISTS unit_cost, DROP COLUMN IF EXISTS currency_code, DROP COLUMN IF EXISTS reorder_point, DROP COLUMN IF EXISTS reorder_quantity, DROP COLUMN IF EXISTS safety_stock, DROP COLUMN IF EXISTS is_active, DROP COLUMN IF EXISTS updated_at, DROP COLUMN IF EXISTS deleted_at');
        $this->addSql('ALTER TABLE inventory_item ADD COLUMN qty_on_hand integer NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE inventory_item ADD CONSTRAINT inventory_item_tenant_sku_key UNIQUE (tenant_id, sku)');

        $this->addSql('DROP INDEX IF EXISTS supplier_tenant_name_live, supplier_tenant_code_live');
        $this->addSql('ALTER TABLE supplier DROP COLUMN IF EXISTS code, DROP COLUMN IF EXISTS email, DROP COLUMN IF EXISTS phone, DROP COLUMN IF EXISTS payment_terms_days, DROP COLUMN IF EXISTS currency_code, DROP COLUMN IF EXISTS is_active, DROP COLUMN IF EXISTS updated_at, DROP COLUMN IF EXISTS deleted_at');
        $this->addSql('ALTER TABLE supplier ADD CONSTRAINT supplier_tenant_name_key UNIQUE (tenant_id, name)');

        $this->addSql('DROP TRIGGER IF EXISTS purchase_order_cascade_deletion ON purchase_order');
        $this->addSql('DROP TRIGGER IF EXISTS sales_order_cascade_deletion ON sales_order');
        $this->addSql('DROP FUNCTION IF EXISTS cascade_deletion_to_lines()');
        $this->addSql('DROP TABLE IF EXISTS tenant CASCADE');

        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO erp_app');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO erp_ai');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO erp_app');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON SEQUENCES TO erp_ai');
        $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO erp_app');
        $this->addSql('GRANT SELECT                         ON ALL TABLES IN SCHEMA public TO erp_ai');
    }
}
