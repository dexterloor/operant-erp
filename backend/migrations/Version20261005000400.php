<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G-29 / G-30 part 3, C1 — **`adjustment_decision`: which returned units an adjustment already credited**
 * (`docs/session13/g29-g30-identity-design.md` §2, as ruled by the owner 2026-10-05: D1 (a), D1b (s-iii), D2 (b);
 * `SEC` S-1…S-7 in `g29-g30-sec.md`).
 *
 * One row is one decision about one return line and one ADJUSTMENT credit-note line (`sales_return_id IS NULL`) on the
 * same shipped line, or the reversal of one:
 * - `same` + `quantity`: "q of these returned units are units that adjustment already credited";
 * - `distinct`, no quantity: "none of this return line's open units are that adjustment's open units" (owner marker);
 * - `reversal` + `reverses_id`: the owner undoes one earlier row (D2 (b)). A reversal is itself never reversed — the
 *   writer refuses that, and `adjustment_decision_reversed_once` stops a row being reversed twice.
 * Rows move no money and no stock. **Class none, append-only: no UPDATE, no DELETE for any application role** (SEC §1.3
 * condition 4).
 *
 * - **`decision_round`** (design §2.4, RED d1d4's D2 note): the partial unique allows one `distinct` per pair PER ROUND.
 *   A partial index cannot see another row's reversal, so a reopened pair would otherwise never be decided `distinct`
 *   again. Every `distinct` writer writes `1 + count of reversed distincts on the pair`, read under `InvoiceRowLock`
 *   (`SalesReturnReader::decisionRound()`, the one copy). The unique is the backstop for a writer that forgets the lock;
 *   it cannot catch one that miscounts. Reversed `distinct` rows stay in the index, so every round 1…max collides.
 * - **The key columns are nullable** with a both-or-neither CHECK (the V2-R-12 form, `purchase_order_line`): rows the
 *   adjust path writes ride the credit note's own key (one request, several rows); `CreateAdjustmentDecision`'s rows
 *   carry their own. NULLs never collide in `adjustment_decision_idem_unique`.
 * - No `fulfilment_line_id`: "same shipped line", "an adjustment line", "stamped for credit against the adjustment's
 *   invoice" are application rules under the invoice lock (three-column FKs are refused); G-15 widens to name their
 *   forgery (SEC §1.3 item 4).
 *
 * **Grants (ADR-002 A4.1), exactly what the callers issue:** REVOKE ALL, then SELECT to `erp_app` (the readers) and
 * `erp_ai` (`SEC` S-3; PC-8 maps it to `invoice.view`), and a **column-level INSERT** for `erp_app` on the eleven columns
 * the two writers name (`AdjustmentDecisionWriter` names all eleven; `CreditNoteWriter::adjust()`'s `distinct` rows, C2,
 * name nine) — never `created_at`, which defaults, so SQL as `erp_app` cannot backdate a decision (the `credit_note`
 * precedent, not `credit_note_line`'s table-level one).
 */
final class Version20261005000400 extends AbstractMigration
{
    private const POLICY = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid";

    private const UUID_V4 = "substring(id::text, 15, 1) = '4' AND substring(id::text, 20, 1) IN ('8', '9', 'a', 'b')";

    public function getDescription(): string
    {
        return 'G-29/G-30 part 3 C1: adjustment_decision (class none, append-only, round-keyed distinct unique, RLS), SELECT to erp_app/erp_ai, column INSERT to erp_app.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE adjustment_decision (
                id                    uuid          NOT NULL,
                tenant_id             uuid          NOT NULL,
                sales_return_line_id  uuid          NOT NULL,
                credit_note_line_id   uuid          NOT NULL,
                kind                  text          NOT NULL,
                quantity              numeric(19,6),
                reverses_id           uuid,
                decision_round        smallint      NOT NULL DEFAULT 1,
                recorded_by_user_id   uuid          NOT NULL,
                created_at            timestamptz   NOT NULL DEFAULT now(),
                idempotency_key       text,
                request_digest        text,
                CONSTRAINT adjustment_decision_pkey                  PRIMARY KEY (id),
                CONSTRAINT adjustment_decision_tenant_id_id_key      UNIQUE (tenant_id, id),
                CONSTRAINT adjustment_decision_idem_unique           UNIQUE (tenant_id, idempotency_key),
                CONSTRAINT adjustment_decision_reversed_once         UNIQUE (tenant_id, reverses_id),
                CONSTRAINT adjustment_decision_return_line_fk        FOREIGN KEY (tenant_id, sales_return_line_id) REFERENCES sales_return_line   (tenant_id, id),
                CONSTRAINT adjustment_decision_credit_note_line_fk   FOREIGN KEY (tenant_id, credit_note_line_id)  REFERENCES credit_note_line    (tenant_id, id),
                CONSTRAINT adjustment_decision_recorder_fk           FOREIGN KEY (tenant_id, recorded_by_user_id)  REFERENCES app_user            (tenant_id, id),
                CONSTRAINT adjustment_decision_reverses_fk           FOREIGN KEY (tenant_id, reverses_id)          REFERENCES adjustment_decision (tenant_id, id),
                CONSTRAINT adjustment_decision_kind_values           CHECK (kind IN ('same', 'distinct', 'reversal')),
                CONSTRAINT adjustment_decision_quantity_iff_same     CHECK ((kind = 'same') = (quantity IS NOT NULL)),
                CONSTRAINT adjustment_decision_quantity_positive     CHECK (quantity > 0),
                CONSTRAINT adjustment_decision_reverses_iff_reversal CHECK ((kind = 'reversal') = (reverses_id IS NOT NULL)),
                CONSTRAINT adjustment_decision_round_positive        CHECK (decision_round >= 1),
                CONSTRAINT adjustment_decision_idempotency_key_sha256 CHECK (idempotency_key ~ '^[0-9a-f]{64}$'),
                CONSTRAINT adjustment_decision_request_digest_sha256  CHECK (request_digest ~ '^[0-9a-f]{64}$'),
                CONSTRAINT adjustment_decision_key_both_or_neither    CHECK ((idempotency_key IS NULL) = (request_digest IS NULL))
            )
            SQL);
        // One live `distinct` per pair per round (design §2.4): the database backstop behind the lock-serialised check.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX adjustment_decision_one_distinct_per_round
                ON adjustment_decision (tenant_id, sales_return_line_id, credit_note_line_id, decision_round)
             WHERE kind = 'distinct'
            SQL);
        // The named terms read both sides: "decisions on this return line" and "decisions on this adjustment line".
        $this->addSql('CREATE INDEX adjustment_decision_tenant_return_line_idx ON adjustment_decision (tenant_id, sales_return_line_id)');
        $this->addSql('CREATE INDEX adjustment_decision_tenant_credit_note_line_idx ON adjustment_decision (tenant_id, credit_note_line_id)');

        // ENABLE alone leaves the owner unfiltered (ADR-002 §C-2), and migrations run as the owner.
        $this->addSql('ALTER TABLE adjustment_decision ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE adjustment_decision FORCE  ROW LEVEL SECURITY');
        $policy = self::POLICY;
        $this->addSql(<<<SQL
            CREATE POLICY tenant_isolation ON adjustment_decision
                USING      ($policy)
                WITH CHECK ($policy)
            SQL);
        // Every tenant-scoped row names a tenant that exists (ARCHITECTURE O-8).
        $this->addSql('ALTER TABLE adjustment_decision ADD CONSTRAINT adjustment_decision_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        // D-12: a UUIDv7 is also type uuid and is time-ordered.
        $this->addSql('ALTER TABLE adjustment_decision ADD CONSTRAINT adjustment_decision_id_is_uuid_v4 CHECK ('.self::UUID_V4.')');

        // REVOKE first, then GRANT: the footprint is independent of whatever default privileges stand.
        $this->addSql('REVOKE ALL ON adjustment_decision FROM erp_app, erp_ai, erp_auth, erp_authlog, erp_ratelimit');
        $this->addSql('GRANT SELECT ON adjustment_decision TO erp_app, erp_ai');
        $this->addSql(<<<'SQL'
            GRANT INSERT (id, tenant_id, sales_return_line_id, credit_note_line_id, kind, quantity, reverses_id,
                          decision_round, recorded_by_user_id, idempotency_key, request_digest)
               ON adjustment_decision TO erp_app
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE adjustment_decision');
    }
}
