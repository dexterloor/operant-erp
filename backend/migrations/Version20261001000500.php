<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sales step 7 C0 — **the credit-note schema**: `credit_note` and `credit_note_line`, deletion class `none`, with
 * `IssueCreditNote`'s header retry key on `fulfilment`'s reasoning (design §3 F-1, §5). No operation writes them yet:
 * C1 (`IssueCreditNote`) and C1b (`IssueAdjustmentCreditNote`) ship the writers and their INSERT grant. C0's readers
 * are `GetInvoice`/`ListInvoices`' `amount_credited`, `amount_due` and `credited_in_full`, `PayInvoice`'s
 * `invoice.has_an_amount_due`, `ListInvoiceLines` and `SalesReturnReader`'s two credited terms — all vacuous until C1
 * writes a credit (a test seeds them as the schema owner, the G-7 shape).
 *
 * ---------------------------------------------------------------------------
 * `credit_note`
 * ---------------------------------------------------------------------------
 * - `invoice_id` NOT NULL: a credit reduces exactly one invoice (OI-2, `credit_note` is the only reduction of an
 *   issued invoice). `sales_return_id` is NULL only for a credit with no return behind it (owner ruling Q-A = B′,
 *   `IssueAdjustmentCreditNote`, C1b), and `credit_note_return_iff_goods_returned` ties that to the reason.
 * - `reason`: design §12 Q-I's CREDIT reasons, `goods_returned` (return-linked), `damaged_goods_kept`, `short_shipped`
 *   (adjustments). The owner's Q-I ruling of 2026-10-01 is about RETURN reasons; these are unchanged from the design.
 * - `currency_code` is copied from the invoice by the writer (C1) in the same statement; equality is an application
 *   rule (G-15 widened).
 * - `total_amount numeric(19,4)`, **`credit_note_total_positive CHECK (total_amount > 0)`**: no credit note lowers
 *   nothing, and — the reason `CreditedSegmentLapseTest`'s live-grant pin names this
 *   CHECK — no INSERT can LOWER the credited sum of an invoice, so TB-4's Credited segment is monotonic (`IA.md` §5.4 ³).
 * - `CN-` + exactly six digits (`IA.md` D-f shape; G-13's cap).
 * - Header retry key, both NOT NULL, 64 lower-case hex, `credit_note_idem_unique` TOTAL (class none, no `deleted_at`).
 *
 * ---------------------------------------------------------------------------
 * `credit_note_line`
 * ---------------------------------------------------------------------------
 * One line per shipped line per note. `sales_return_line_id` names the return line credited (NULL for an adjustment).
 * `quantity > 0`; `amount >= 0` — a telescoped line amount may be 0 (design §4.4 step 8b, RED L-1); the header keeps
 * the total positive. That a line's shipped line belongs to the invoice's fulfilment, that `total_amount = Σ amount`,
 * and the quantity bounds are application rules (three-column FKs are refused; G-15 widens to name their forgery).
 *
 * ---------------------------------------------------------------------------
 * Grants: SELECT only, explicit, for both application roles (design §8)
 * ---------------------------------------------------------------------------
 * REVOKE first (a table created while a default privilege stands would inherit it), then `GRANT SELECT` to `erp_app`
 * (C0's readers above) and `erp_ai` (`SEC` S-1, table-level; PC-8 maps both tables to `invoice.view`). INSERT ships
 * with its caller in C1 (ADR-002 A4.1). **Never UPDATE, DELETE or TRUNCATE**: nothing edits an issued document (OI-2),
 * and the Credited segment's lapse pin asserts exactly that, for every non-superuser role, at the column level too.
 */
final class Version20261001000500 extends AbstractMigration
{
    private const POLICY = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid";

    private const UUID_V4 = "substring(id::text, 15, 1) = '4' AND substring(id::text, 20, 1) IN ('8', '9', 'a', 'b')";

    private const TABLES = ['credit_note', 'credit_note_line'];

    public function getDescription(): string
    {
        return 'Sales step 7 C0: credit_note and credit_note_line (class none, header retry key, positive totals, RLS), SELECT to erp_app and erp_ai.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE credit_note (
                id                   uuid          NOT NULL,
                tenant_id            uuid          NOT NULL,
                invoice_id           uuid          NOT NULL,
                sales_return_id      uuid,
                credit_note_number   text          NOT NULL,
                reason               text          NOT NULL,
                currency_code        text          NOT NULL,
                total_amount         numeric(19,4) NOT NULL,
                issued_at            timestamptz   NOT NULL DEFAULT now(),
                issued_by_user_id    uuid          NOT NULL,
                idempotency_key      text          NOT NULL,
                request_digest       text          NOT NULL,
                created_at           timestamptz   NOT NULL DEFAULT now(),
                updated_at           timestamptz   NOT NULL DEFAULT now(),
                CONSTRAINT credit_note_pkey                       PRIMARY KEY (id),
                CONSTRAINT credit_note_tenant_id_id_key           UNIQUE (tenant_id, id),
                CONSTRAINT credit_note_number_unique              UNIQUE (tenant_id, credit_note_number),
                CONSTRAINT credit_note_idem_unique                UNIQUE (tenant_id, idempotency_key),
                CONSTRAINT credit_note_invoice_fk                 FOREIGN KEY (tenant_id, invoice_id)        REFERENCES invoice      (tenant_id, id),
                CONSTRAINT credit_note_return_fk                  FOREIGN KEY (tenant_id, sales_return_id)   REFERENCES sales_return (tenant_id, id),
                CONSTRAINT credit_note_issuer_fk                  FOREIGN KEY (tenant_id, issued_by_user_id) REFERENCES app_user     (tenant_id, id),
                CONSTRAINT credit_note_currency_fk                FOREIGN KEY (currency_code)                REFERENCES currency     (code),
                CONSTRAINT credit_note_number_shape               CHECK (credit_note_number ~ '^CN-[0-9]{6}$'),
                CONSTRAINT credit_note_total_positive             CHECK (total_amount > 0),
                CONSTRAINT credit_note_reason_values              CHECK (reason IN ('goods_returned', 'damaged_goods_kept', 'short_shipped')),
                CONSTRAINT credit_note_return_iff_goods_returned  CHECK ((reason = 'goods_returned') = (sales_return_id IS NOT NULL)),
                CONSTRAINT credit_note_idempotency_key_sha256     CHECK (idempotency_key ~ '^[0-9a-f]{64}$'),
                CONSTRAINT credit_note_request_digest_sha256      CHECK (request_digest ~ '^[0-9a-f]{64}$')
            )
            SQL);
        // `amount_credited`, the Credited segment and V2-R-17's `EXISTS` (C1) all read "this invoice's credit notes".
        $this->addSql('CREATE INDEX credit_note_tenant_invoice_idx ON credit_note (tenant_id, invoice_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE credit_note_line (
                id                    uuid          NOT NULL,
                tenant_id             uuid          NOT NULL,
                credit_note_id        uuid          NOT NULL,
                fulfilment_line_id    uuid          NOT NULL,
                sales_return_line_id  uuid,
                quantity              numeric(19,6) NOT NULL,
                amount                numeric(19,4) NOT NULL,
                created_at            timestamptz   NOT NULL DEFAULT now(),
                updated_at            timestamptz   NOT NULL DEFAULT now(),
                CONSTRAINT credit_note_line_pkey             PRIMARY KEY (id),
                CONSTRAINT credit_note_line_tenant_id_id_key UNIQUE (tenant_id, id),
                CONSTRAINT credit_note_line_once_per_line    UNIQUE (tenant_id, credit_note_id, fulfilment_line_id),
                CONSTRAINT credit_note_line_note_fk          FOREIGN KEY (tenant_id, credit_note_id)       REFERENCES credit_note       (tenant_id, id),
                CONSTRAINT credit_note_line_fulfil_fk        FOREIGN KEY (tenant_id, fulfilment_line_id)   REFERENCES fulfilment_line   (tenant_id, id),
                CONSTRAINT credit_note_line_return_line_fk   FOREIGN KEY (tenant_id, sales_return_line_id) REFERENCES sales_return_line (tenant_id, id),
                CONSTRAINT credit_note_line_qty_positive     CHECK (quantity > 0),
                CONSTRAINT credit_note_line_amount_not_negative CHECK (amount >= 0)
            )
            SQL);
        // "Everything credited against this shipped line" — the credited quantity (SalesReturnReader, ListInvoiceLines)
        // and C1's bounds.
        $this->addSql('CREATE INDEX credit_note_line_tenant_fulfilment_line_idx ON credit_note_line (tenant_id, fulfilment_line_id)');

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
        $this->addSql('DROP TABLE credit_note_line');
        $this->addSql('DROP TABLE credit_note');
    }
}
