<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * E4-3 / E9-1 / `ROADMAP.md` F-11 — `tenant_settings`, and the end of the
 * instance-wide currency constant.
 *
 * ---------------------------------------------------------------------------
 * The defect this closes, which is not the Settings screen
 * ---------------------------------------------------------------------------
 * `backend/config/services.yaml` declared
 * `app.inventory.default_currency_code: 'EUR'`, autowired into `ItemWriter` and
 * bound into every `INSERT INTO inventory_item`. It is **an instance-wide
 * container parameter supplying a per-tenant business value in a multi-tenant
 * application**: every tenant on the deployment was priced in euros by
 * construction, and no existing check could see it, because it never touched
 * the database. RLS, `TenantContext`, the composite-FK work and the whole W0-a
 * isolation slice are irrelevant to a PHP constant — it sits *above* the
 * isolation boundary.
 *
 * It also defeated a guarantee this schema deliberately established.
 * `Version20260906000100` dropped `inventory_item.currency_code`'s default
 * (`:427`), and the comment on the sibling `supplier` drop states the reason:
 * leaving it would mean a row created *"with no stated currency"* and
 * **silently priced in euros**. The database default was removed so that could
 * not happen; a constant one layer up did exactly it.
 *
 * ---------------------------------------------------------------------------
 * Why the column carries NO DEFAULT, and why there is no trigger
 * ---------------------------------------------------------------------------
 * A `DEFAULT 'EUR'` here, or a `?? 'EUR'` in the reader, would be the same
 * defect relocated one layer down and would pass any test that merely checks
 * the table exists. The point of the row is that the currency becomes a
 * **stated** per-tenant value, so an unprovisioned workspace must fail loudly —
 * exactly as `inventory_item.currency_code NOT NULL` with no default already
 * fails.
 *
 * For the same reason there is **no `AFTER INSERT ON tenant` trigger** creating
 * the row: a trigger would have to invent a currency, which is the thing being
 * removed. `erp_app` holds `SELECT` only on `tenant`, so nothing in the
 * application provisions a workspace today; the pass that builds workspace
 * provisioning owes the settings row alongside the registry row, and E4-3's
 * second residual is where that obligation is recorded.
 *
 * ---------------------------------------------------------------------------
 * The backfill is `EUR`, and that is the honest value rather than a chosen one
 * ---------------------------------------------------------------------------
 * Every existing tenant *was* being priced in euros — that is the defect, stated
 * as a fact about the running deployment. Backfilling `EUR` therefore changes no
 * observable behaviour and converts a global constant into a stated, per-tenant,
 * auditable row that a tenant can be given a different value for. Backfilling
 * anything else would silently reprice existing data.
 *
 * The sentinel tenant is excluded. ADR-006 §2.1 step 2's row is a timing decoy
 * that owns no `app_user` and can never be logged into
 * (`DeletionPolicyTest::theSentinelTenantCannotOwnAUser`); giving it business
 * configuration would model it as a workspace, which is exactly what it is not.
 *
 * ---------------------------------------------------------------------------
 * One row per tenant, and the unique index is how that is said
 * ---------------------------------------------------------------------------
 * `UNIQUE (tenant_id)` is both the business rule (*a workspace has one settings
 * record*) and the thing that satisfies R-15: its leading — only — column is
 * `tenant_id`, so it is not an oracle across tenants, and
 * `SchemaGuardTest::noTenantScopedTableHasAUniqueIndexThatDoesNotLeadWithTenantId`
 * has no allow-list to be added to. It is **total**, not partial, because the
 * deletion class is `none`: there is no `deleted_at` to be partial on, and
 * `SchemaManifestTest::businessUniquenessIsPartialOnSoftDeletedTablesAndTotalElsewhere`
 * asserts that correspondence in both directions.
 *
 * `id` is a surrogate the table does not strictly need — `tenant_id` would key
 * it alone. It is here because every tenant-scoped table in this schema carries
 * one and three catalogue-derived guards assume it: the composite-FK target
 * `UNIQUE (tenant_id, id)`, the UUIDv4 `CHECK` on `id`, and the ORM's
 * requirement of a scalar identifier (ADR-002 A1.6). Deviating would buy nothing
 * and would need three exceptions written.
 *
 * ---------------------------------------------------------------------------
 * Grants: `SELECT` to `erp_app`, and deliberately nothing else
 * ---------------------------------------------------------------------------
 *   no INSERT   — the registry's own reasoning (S-10 / V-10). The application
 *                 principal cannot create a workspace, so it must not create a
 *                 workspace's settings.
 *   no UPDATE   — `UpdateSettings` is **not shippable** (`API.md` §4.3b,
 *                 API-O-14). A privilege with no user is the grant-side of an
 *                 index with no query. The pass that ships the operation issues
 *                 the grant and edits the manifest in the same commit.
 *   no DELETE / TRUNCATE — deletion class `none`. Removing a settings row would
 *                 silently restore "no stated currency", which is the state this
 *                 whole migration exists to make unreachable.
 *   erp_ai holds nothing — no NL query needs the tenant's functional currency
 *                 today, and widening is one manifest line plus a migration.
 *
 * @see docs/DELIVERY_LEDGER.md — Output 4 entry condition E4-3, and E9-1's ruling
 * @see docs/SECURITY_MODEL.md E-15b — why withdrawing the permission codes was the costly option
 */
final class Version20260910000100 extends AbstractMigration
{
    /** ADR-002 §2 as corrected by A1.2 — the empty string, not NULL, is what `DISCARD ALL` leaves. */
    private const POLICY = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid";

    /**
     * D-12, copied in shape from `Version20260906000100::UUID_V4` rather than
     * imported: a migration that reaches into another migration's constant
     * changes meaning when that file is edited, and a recorded migration must
     * mean today what it meant when it ran.
     */
    private const UUID_V4 = "substring(id::text, 15, 1) = '4' AND substring(id::text, 20, 1) IN ('8', '9', 'a', 'b')";

    public function getDescription(): string
    {
        return 'E4-3: tenant_settings — the tenant\'s functional currency, replacing an instance-wide container parameter';
    }

    public function up(Schema $schema): void
    {
        // Create, backfill, THEN secure. The order matters and it is not the
        // obvious one -- see backfillExistingTenants() for why establishing
        // tenant context here would have been the worse trade.
        $this->createTable();
        $this->backfillExistingTenants();
        $this->applyRowLevelSecurity();
        $this->applyGrants();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS tenant_settings CASCADE');
    }

    private function createTable(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_settings (
                id                    uuid        NOT NULL,
                tenant_id             uuid        NOT NULL,
                default_currency_code text        NOT NULL,
                created_at            timestamptz NOT NULL DEFAULT now(),
                updated_at            timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT tenant_settings_pkey             PRIMARY KEY (id),
                CONSTRAINT tenant_settings_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT tenant_settings_tenant_key       UNIQUE (tenant_id),
                CONSTRAINT tenant_settings_currency_fk      FOREIGN KEY (default_currency_code) REFERENCES currency (code)
            )
            SQL);
    }

    /**
     * The same four statements every tenant-scoped table gets, written out
     * rather than looped: there is one table here, and a loop over a
     * single-element list reads as if it were general.
     */
    private function applyRowLevelSecurity(): void
    {
        // ENABLE alone leaves the owner unfiltered (ADR-002 §C-2), and
        // migrations run as the owner.
        $this->addSql('ALTER TABLE tenant_settings ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE tenant_settings FORCE  ROW LEVEL SECURITY');

        $policy = self::POLICY;
        $this->addSql(<<<SQL
            CREATE POLICY tenant_isolation ON tenant_settings
                USING      ($policy)
                WITH CHECK ($policy)
            SQL);

        // Without it, set_config with any random UUID would let a caller create
        // settings for a workspace that was never provisioned.
        $this->addSql('ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)');

        // D-12. Type `uuid` is not enough: a UUIDv7 is also type uuid and is
        // time-ordered, which would reopen the enumeration channel while
        // passing a type check.
        $this->addSql('ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_id_is_uuid_v4 CHECK ('.self::UUID_V4.')');
    }

    /**
     * One statement, and it runs **before** the policy exists.
     *
     * `INSERT ... SELECT` is evaluated per row against a `WITH CHECK` policy,
     * and with no `app.tenant_id` established the policy expression is
     * `tenant_id = NULL::uuid` — NULL, therefore not true, therefore every row
     * refused. There are exactly two ways round that, and this is the cheaper
     * one:
     *
     *   (a) run the backfill before `CREATE POLICY`, inside this migration's
     *       own transaction, which is what happens here. Nothing outside the
     *       transaction ever observes the table, secured or otherwise; the
     *       table is committed with RLS enabled, forced and policed.
     *   (b) loop over the registry in plpgsql, calling
     *       `set_config('app.tenant_id', …, true)` per tenant.
     *
     * (b) reads as the more rigorous option and is the worse trade: it puts a
     * tenant-context writer into `migrations/`, and
     * `TenantContextContainmentTest` scans that directory and treats every
     * writer outside `src/Platform/Tenancy/` as an entry on a named allow-list.
     * That list is a control. Spending an entry on it to buy a formality inside
     * a transaction nobody else can see is not worth it.
     *
     * `gen_random_uuid()` is UUIDv4 on PostgreSQL 13+ — the generator
     * `IdentifierGenerationTest` asserts the database's own to be — so
     * `tenant_settings_id_is_uuid_v4`, added a few statements later, validates
     * against these rows rather than being taken on trust.
     */
    private function backfillExistingTenants(): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO tenant_settings (id, tenant_id, default_currency_code)
            SELECT gen_random_uuid(), t.id, 'EUR'
            FROM tenant t
            WHERE t.status <> 'sentinel'
            SQL);
    }

    private function applyGrants(): void
    {
        // REVOKE first, then GRANT. `ALTER DEFAULT PRIVILEGES` is withdrawn
        // today but `Version20260906000100::down()` restores it, and a table
        // created while it stands is granted to erp_app before anyone decides
        // whether it should be. This makes the footprint of a new table
        // independent of what was standing when it was created.
        $this->addSql('REVOKE ALL ON tenant_settings FROM erp_app, erp_ai, erp_auth, erp_authlog, erp_ratelimit');

        // The whole footprint. See the class docblock for what each omission
        // buys; `GrantFootprintTest::everyRoleHoldsExactlyTheTablePrivilegesTheManifestDeclares`
        // asserts this is exact in both directions.
        $this->addSql('GRANT SELECT ON tenant_settings TO erp_app');
    }
}
