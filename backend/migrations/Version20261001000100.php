<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sales step 7, commit R1 — the return schema: `sales_return` and `sales_return_line`. **No operation, no writer.**
 * Design `docs/session13/output9-sales-8-design.md` §5 and §8; owner rulings `output9-sales-8-ruling.md` (2026-09-30).
 *
 * ---------------------------------------------------------------------------
 * The retry key follows `fulfilment`, not `purchase_order_line` (design §3 F-1)
 * ---------------------------------------------------------------------------
 * Each `CreateSalesReturn` request writes ONE header row and N unkeyed lines, exactly as `CreateFulfilment` does
 * (`Version20260922000100`). So `idempotency_key` and `request_digest` are NOT NULL on the header, CHECKed to 64
 * lower-case hex, and `sales_return_idem_unique (tenant_id, idempotency_key)` is TOTAL: the table has no `deleted_at`
 * (deletion class `none`, OI-2 R3), and `IdempotencyContractTest::everyUniqueIndexIsLiveRowPartialExactlyWhenItsTableSoftDeletes`
 * demands total there. No row predates the columns -- the table is new -- so there is no history for a NULL to describe,
 * and the `85c08fb` worked-example trap (NOT NULL on a table that already holds rows) cannot fire. The index has no §4.2
 * cell until R2 builds `CreateSalesReturn`, and a unique with no cell reddens nothing (design §9, RED's reading of
 * `IdempotencyContractTest`).
 *
 * ---------------------------------------------------------------------------
 * What the columns record
 * ---------------------------------------------------------------------------
 * - `invoice_id` (nullable): the fulfilment's LIVE invoice at the moment the return was recorded, read under the order
 *   lock (design §3 F-2). "Returned before issue" cannot be decided from `created_at` vs `issued_at`, which are both
 *   transaction-start instants; the stamp removes the clock.
 * - `deducts` (NOT NULL, no default): owner ruling Q-L (a′), decided ONCE at record time and never re-derived -- a return
 *   lowers the next invoice only if recorded before any invoice by a `fulfilment.create` holder other than the shipment's
 *   recorder, or by an owner. `sales_return_deducts_only_before_invoice` makes "a deducting return names no invoice" a
 *   database fact; the rest of the classification is the writer's (R2), because it depends on the caller.
 * - `recorded_by_user_id` (NOT NULL): SoD-5's evidence -- who recorded the return, the authenticated caller, never a body
 *   field; `fulfilment.shipped_by_user_id`'s precedent (owner ruling R6).
 * - `reason`: the RETURN DOCUMENT's reason, not `stock_movement.reason` (E-27 governs the latter and is untouched here).
 *   ⚠ The value set is design §12 Q-I's PROPOSAL (`PO` + `UX`'s to rule, not yet ruled); `recorded_in_error` is OI-2 R3's
 *   and fixed. Changing a member before the first push is an edit to this migration, nothing more.
 * - `sales_return_number`: `RET-` + exactly six digits (CD-7 / owner Q12 house style; `IA.md` D-f's
 *   `^[A-Z]{2,4}-[0-9]{6}$` shape). `{6}`, never `{6,}`.
 *
 * ---------------------------------------------------------------------------
 * What the database cannot hold (application rules, R2's read-back tests and G-15's widening)
 * ---------------------------------------------------------------------------
 * A line's fulfilment line belongs to its return's fulfilment (a three-column FK is refused: every composite FK here is
 * `(tenant_id, col) -> (tenant_id, id)`, `DoctrineMappingTest`); the per-line quantity bound (returned <= shipped); and
 * `invoice_id` being the live invoice at the time. SQL as `erp_app` could break each -- once R2 grants it INSERT.
 *
 * ---------------------------------------------------------------------------
 * Grants: SELECT only, explicit, for both application roles (design §8)
 * ---------------------------------------------------------------------------
 * A new table receives NOTHING by default: `Version20260906000100` withdrew the default privileges, so a schema commit
 * that granted nothing would leave `GrantFootprintTest` red against the manifest and the first reader at 42501. REVOKE
 * first (a `down()` of the first migration restores the defaults, and a table created while they stand would inherit
 * them), then GRANT exactly:
 * - `erp_app` SELECT -- `GetSalesReturn`, the 201 read-back and `IssueInvoice`'s deduction read them (R2).
 * - `erp_ai` SELECT -- `SEC` ruled S-1 "yes, table-level" (`output9-sales-8-sec.md` §1, 2026-09-30); declared by an
 *   explicit manifest `grants` entry, never by the read-only default, so nobody can mistake it for an inheritance.
 * INSERT ships with its caller in R2 (ADR-002 A4.1). Never UPDATE, never DELETE: nothing edits an issued document (OI-2).
 */
final class Version20261001000100 extends AbstractMigration
{
    private const POLICY = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid";

    private const UUID_V4 = "substring(id::text, 15, 1) = '4' AND substring(id::text, 20, 1) IN ('8', '9', 'a', 'b')";

    private const TABLES = ['sales_return', 'sales_return_line'];

    public function getDescription(): string
    {
        return 'Sales step 7 R1: sales_return and sales_return_line (class none, header retry key, deducts, RLS), SELECT to erp_app and erp_ai.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sales_return (
                id                   uuid        NOT NULL,
                tenant_id            uuid        NOT NULL,
                fulfilment_id        uuid        NOT NULL,
                invoice_id           uuid,
                sales_return_number  text        NOT NULL,
                reason               text        NOT NULL,
                returned_at          timestamptz NOT NULL,
                recorded_by_user_id  uuid        NOT NULL,
                deducts              boolean     NOT NULL,
                idempotency_key      text        NOT NULL,
                request_digest       text        NOT NULL,
                created_at           timestamptz NOT NULL DEFAULT now(),
                updated_at           timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT sales_return_pkey                         PRIMARY KEY (id),
                CONSTRAINT sales_return_tenant_id_id_key             UNIQUE (tenant_id, id),
                CONSTRAINT sales_return_number_unique                UNIQUE (tenant_id, sales_return_number),
                CONSTRAINT sales_return_idem_unique                  UNIQUE (tenant_id, idempotency_key),
                CONSTRAINT sales_return_fulfilment_fk                FOREIGN KEY (tenant_id, fulfilment_id)       REFERENCES fulfilment (tenant_id, id),
                CONSTRAINT sales_return_invoice_fk                   FOREIGN KEY (tenant_id, invoice_id)          REFERENCES invoice    (tenant_id, id),
                CONSTRAINT sales_return_recorder_fk                  FOREIGN KEY (tenant_id, recorded_by_user_id) REFERENCES app_user   (tenant_id, id),
                CONSTRAINT sales_return_number_shape                 CHECK (sales_return_number ~ '^RET-[0-9]{6}$'),
                CONSTRAINT sales_return_deducts_only_before_invoice  CHECK (NOT deducts OR invoice_id IS NULL),
                CONSTRAINT sales_return_reason_values                CHECK (reason IN ('customer_returned', 'damaged_in_transit', 'wrong_item_shipped', 'recorded_in_error')),
                CONSTRAINT sales_return_idempotency_key_sha256       CHECK (idempotency_key ~ '^[0-9a-f]{64}$'),
                CONSTRAINT sales_return_request_digest_sha256        CHECK (request_digest ~ '^[0-9a-f]{64}$')
            )
            SQL);
        // "This shipment's returns" -- the quantity bound and the deduction read (R2), both under the order lock.
        $this->addSql('CREATE INDEX sales_return_tenant_fulfilment_idx ON sales_return (tenant_id, fulfilment_id)');
        // "Returns recorded against this invoice" -- the credit queue (R3, C1). Partial: most returns name none.
        $this->addSql('CREATE INDEX sales_return_tenant_invoice_idx ON sales_return (tenant_id, invoice_id) WHERE invoice_id IS NOT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE sales_return_line (
                id                   uuid          NOT NULL,
                tenant_id            uuid          NOT NULL,
                sales_return_id      uuid          NOT NULL,
                fulfilment_line_id   uuid          NOT NULL,
                storage_location_id  uuid          NOT NULL,
                quantity             numeric(19,6) NOT NULL,
                created_at           timestamptz   NOT NULL DEFAULT now(),
                updated_at           timestamptz   NOT NULL DEFAULT now(),
                CONSTRAINT sales_return_line_pkey             PRIMARY KEY (id),
                CONSTRAINT sales_return_line_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT sales_return_line_once_per_line    UNIQUE (tenant_id, sales_return_id, fulfilment_line_id),
                CONSTRAINT sales_return_line_return_fk        FOREIGN KEY (tenant_id, sales_return_id)     REFERENCES sales_return     (tenant_id, id),
                CONSTRAINT sales_return_line_fulfil_fk        FOREIGN KEY (tenant_id, fulfilment_line_id)  REFERENCES fulfilment_line  (tenant_id, id),
                CONSTRAINT sales_return_line_location_fk      FOREIGN KEY (tenant_id, storage_location_id) REFERENCES storage_location (tenant_id, id),
                CONSTRAINT sales_return_line_qty_positive     CHECK (quantity > 0)
            )
            SQL);
        // "Everything returned against this shipped line" -- the per-line bound (R2) and the credited quantity (C0/C1).
        $this->addSql('CREATE INDEX sales_return_line_tenant_fulfilment_line_idx ON sales_return_line (tenant_id, fulfilment_line_id)');

        foreach (self::TABLES as $table) {
            // ENABLE alone leaves the owner unfiltered (ADR-002 §C-2), and migrations run as the owner.
            $this->addSql("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            $this->addSql("ALTER TABLE $table FORCE  ROW LEVEL SECURITY");
            $policy = self::POLICY;
            $this->addSql(<<<SQL
                CREATE POLICY tenant_isolation ON $table
                    USING      ($policy)
                    WITH CHECK ($policy)
                SQL);
            // Every tenant-scoped row names a tenant that exists (ARCHITECTURE O-8).
            $this->addSql("ALTER TABLE $table ADD CONSTRAINT {$table}_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)");
            // D-12: a UUIDv7 is also type uuid and is time-ordered.
            $this->addSql("ALTER TABLE $table ADD CONSTRAINT {$table}_id_is_uuid_v4 CHECK (".self::UUID_V4.')');

            // REVOKE first, then GRANT: the footprint is independent of whatever default privileges stand.
            $this->addSql("REVOKE ALL ON $table FROM erp_app, erp_ai, erp_auth, erp_authlog, erp_ratelimit");
            $this->addSql("GRANT SELECT ON $table TO erp_app, erp_ai");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sales_return_line');
        $this->addSql('DROP TABLE sales_return');
    }
}
