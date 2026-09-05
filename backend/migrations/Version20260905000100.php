<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-002 -- the tenancy baseline.
 *
 * Runs as the schema owner. The two application roles already exist (created in
 * SQL by infra/postgres/init/10-roles.sh, never through a provider console --
 * ADR-002 §C-1); this grants them their rights and puts the isolation boundary
 * in place.
 *
 * Role names are hardcoded. They are part of the schema contract, not secrets.
 */
final class Version20260905000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-002 tenancy baseline: default privileges, supplier, inventory_item, RLS policies';
    }

    public function up(Schema $schema): void
    {
        // -------------------------------------------------------------------
        // Privileges FIRST, and by default.
        //
        // ADR-002 §1 grants with `GRANT ... ON ALL TABLES IN SCHEMA public`.
        // That is point-in-time and it runs before any table exists, so on its
        // own it grants nothing and every later migration ships a table the
        // application cannot touch (R-34). ALTER DEFAULT PRIVILEGES is what
        // makes the ordering irrelevant: it applies to every table this role
        // creates from here on, in this migration and in all future ones.
        //
        // The sequence grant is not decorative -- messenger_messages uses a
        // BIGSERIAL id, and without USAGE on its sequence erp_app cannot enqueue
        // anything, which would take ADR-002 §7 with it.
        // -------------------------------------------------------------------
        $this->addSql('GRANT USAGE ON SCHEMA public TO erp_app, erp_ai');

        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO erp_app');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO erp_ai');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO erp_app');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON SEQUENCES TO erp_ai');

        // -------------------------------------------------------------------
        // Tables.
        //
        // ADR-002 §5: integrity checks always bypass row security, so a bare
        // UNIQUE (sku) would answer "taken" for a row the caller cannot see.
        // Every business uniqueness is therefore scoped by tenant, and
        // UNIQUE (tenant_id, id) exists so the child's composite FK has a
        // target. tenant_id leads every composite index.
        // -------------------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE supplier (
                id          uuid        NOT NULL,
                tenant_id   uuid        NOT NULL,
                name        text        NOT NULL,
                created_at  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT supplier_pkey             PRIMARY KEY (id),
                CONSTRAINT supplier_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT supplier_tenant_name_key  UNIQUE (tenant_id, name)
            )
            SQL);
        $this->addSql('CREATE INDEX supplier_tenant_created_idx ON supplier (tenant_id, created_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_item (
                id           uuid        NOT NULL,
                tenant_id    uuid        NOT NULL,
                sku          text        NOT NULL,
                qty_on_hand  integer     NOT NULL DEFAULT 0,
                supplier_id  uuid,
                created_at   timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT inventory_item_pkey             PRIMARY KEY (id),
                CONSTRAINT inventory_item_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT inventory_item_tenant_sku_key   UNIQUE (tenant_id, sku),
                CONSTRAINT inventory_item_supplier_fk
                    FOREIGN KEY (tenant_id, supplier_id) REFERENCES supplier (tenant_id, id)
            )
            SQL);
        $this->addSql('CREATE INDEX inventory_item_tenant_supplier_idx ON inventory_item (tenant_id, supplier_id)');
        $this->addSql('CREATE INDEX inventory_item_tenant_created_idx  ON inventory_item (tenant_id, created_at)');

        // -------------------------------------------------------------------
        // The boundary.
        //
        // ENABLE *and* FORCE. ENABLE alone leaves the table owner unfiltered
        // (ADR-002 §C-2), and Doctrine commonly connects as the owner.
        //
        // Unset context -> current_setting returns NULL -> the comparison is
        // NULL -> zero rows. Never `current_setting(...) IS NULL OR ...`, which
        // fails open.
        // -------------------------------------------------------------------
        // NULLIF is not cosmetic. `RESET app.tenant_id` -- and RESET ALL inside
        // DISCARD ALL, which is PgBouncer's own server_reset_query -- leaves a
        // custom GUC set to the empty string, not unset. current_setting() then
        // returns '' rather than NULL and ''::uuid raises 22P02 on every query
        // against the table. Measured, not assumed:
        //   SET app.tenant_id = '...'; RESET app.tenant_id;
        //   -> current_setting(...) IS NULL = f, = '' = t
        //   -> ERROR: invalid input syntax for type uuid: ""
        // NULLIF maps that back to NULL, which is the fail-closed answer
        // ADR-002 §2 intends. It is emphatically NOT the forbidden
        // `current_setting(...) IS NULL OR ...`, which would fail open.
        foreach (['supplier', 'inventory_item'] as $table) {
            $this->addSql("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            $this->addSql("ALTER TABLE $table FORCE  ROW LEVEL SECURITY");
            $this->addSql(<<<SQL
                CREATE POLICY tenant_isolation ON $table
                    USING      (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                    WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                SQL);
        }

        // Belt and braces for anything that predates the default privileges.
        $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO erp_app');
        $this->addSql('GRANT SELECT                         ON ALL TABLES IN SCHEMA public TO erp_ai');
        $this->addSql('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS inventory_item');
        $this->addSql('DROP TABLE IF EXISTS supplier');

        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM erp_app');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT ON TABLES FROM erp_ai');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM erp_app');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT ON SEQUENCES FROM erp_ai');
    }
}
