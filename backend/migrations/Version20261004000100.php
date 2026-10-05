<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Roles slice 3 C1 — **`role_grant_proposal`**: where a pending grant of a role lives (E-15e; `SECURITY_MODEL.md`
 * §5.2.2), so that a pending grant confers nothing by construction — no permission reader reads this table.
 *
 * The DDL is `DATA`'s corrected text (`docs/session13/roles-s3-data.md` §6, reviewing `roles-s3-design.md` §6.1):
 *  - deletion class `none`: a proposal is a record of a security decision and is never removed (§5.2.2);
 *  - `status DEFAULT 'pending'` and OUTSIDE the column INSERT grant, so every row an application writer inserts is
 *    pending; the decision columns are outside it too;
 *  - `proposed_at` / `expires_at` carry no default: both come from ONE post-lock `clock_timestamp()` in the INSERT's own
 *    SQL (S-11), and the window CHECK ties them in hours, never calendar days (RED L-4; `DATA` D-3);
 *  - the five actor CHECKs (E-15a on proposal and on confirmation as narrowed by Q-5 (a); confirmer ≠ proposer;
 *    rejecter ≠ proposer; canceller = proposer), the decided/decider/lapse/reason shape CHECKs, and
 *    `decided_not_before_proposed` — whose ORM writer uses the microsecond type (`DATA` §2);
 *  - the keyset index carries no `DESC` (`SCHEMA.md` §9; `DATA` §1.4);
 *  - FKs carry NO referential action (`SchemaGuardTest::theSchemaContainsNoOwnerPrivilegedIndirection`).
 *
 * **The guard trigger (`DATA` + `SEC`, §3):** a decided proposal is never updated, by any writer below the schema owner.
 * Invoker rights, raise only, predicate on `WHEN` — the `purchase_order_idempotency_key_required` precedent
 * (`Version20260911000200`). It stops SQL as `erp_app` reopening or relabelling a decision, and a future bulk lapse that
 * forgets `AND status = 'pending'`. It does not narrow G-10's bound.
 *
 * **Grants — exactly what C1's callers issue (ADR-002 A4.1; RED M-3; `DATA` §4):** `SELECT` (Get, List, the lock-time
 * reads); column `INSERT` on the eight proposal columns (Create's one `INSERT … SELECT`); column `UPDATE` on
 * `status, decided_at, lapse_reason, updated_at` (Create's lapse-on-touch flush). `decided_by_user_id` arrives with
 * Confirm (C2) and `reason` with Reject (C3). Never `DELETE`. **`erp_ai` gets nothing** (§5.2.2).
 */
final class Version20261004000100 extends AbstractMigration
{
    private const TABLE = 'role_grant_proposal';

    private const POLICY = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid";

    private const UUID_V4 = "substring(id::text, 15, 1) = '4' AND substring(id::text, 20, 1) IN ('8', '9', 'a', 'b')";

    private const TRIGGER = 'role_grant_proposal_decided_is_final';

    public function getDescription(): string
    {
        return 'Roles slice 3 C1: role_grant_proposal (class none, RLS, actor CHECKs, decided-is-final trigger), column-level grants to erp_app only.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE role_grant_proposal (
                id                  uuid        NOT NULL,
                tenant_id           uuid        NOT NULL,
                user_id             uuid        NOT NULL,
                role_id             uuid        NOT NULL,
                permission_codes    jsonb       NOT NULL,
                status              text        NOT NULL DEFAULT 'pending',
                proposed_by_user_id uuid        NOT NULL,
                proposed_at         timestamptz NOT NULL,
                expires_at          timestamptz NOT NULL,
                decided_by_user_id  uuid,
                decided_at          timestamptz,
                reason              text,
                lapse_reason        text,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT role_grant_proposal_pkey                 PRIMARY KEY (id),
                CONSTRAINT role_grant_proposal_tenant_id_id_key     UNIQUE (tenant_id, id),
                CONSTRAINT role_grant_proposal_user_fk              FOREIGN KEY (tenant_id, user_id)             REFERENCES app_user (tenant_id, id),
                CONSTRAINT role_grant_proposal_role_fk              FOREIGN KEY (tenant_id, role_id)             REFERENCES role     (tenant_id, id),
                CONSTRAINT role_grant_proposal_proposer_fk          FOREIGN KEY (tenant_id, proposed_by_user_id) REFERENCES app_user (tenant_id, id),
                CONSTRAINT role_grant_proposal_decider_fk           FOREIGN KEY (tenant_id, decided_by_user_id)  REFERENCES app_user (tenant_id, id),
                CONSTRAINT role_grant_proposal_status_values        CHECK (status IN ('pending', 'confirmed', 'rejected', 'cancelled', 'lapsed')),
                CONSTRAINT role_grant_proposal_codes_shape          CHECK (jsonb_typeof(permission_codes) = 'array' AND jsonb_array_length(permission_codes) > 0),
                CONSTRAINT role_grant_proposal_not_self             CHECK (proposed_by_user_id <> user_id),
                CONSTRAINT role_grant_proposal_confirmer_not_subject  CHECK (status <> 'confirmed' OR decided_by_user_id <> user_id),
                CONSTRAINT role_grant_proposal_confirmer_not_proposer CHECK (status <> 'confirmed' OR decided_by_user_id <> proposed_by_user_id),
                CONSTRAINT role_grant_proposal_rejecter_not_proposer  CHECK (status <> 'rejected'  OR decided_by_user_id <> proposed_by_user_id),
                CONSTRAINT role_grant_proposal_canceller_is_proposer  CHECK (status <> 'cancelled' OR decided_by_user_id =  proposed_by_user_id),
                CONSTRAINT role_grant_proposal_decided_iff_terminal CHECK ((status = 'pending') = (decided_at IS NULL)),
                CONSTRAINT role_grant_proposal_decider_iff_human    CHECK ((status IN ('confirmed', 'rejected', 'cancelled')) = (decided_by_user_id IS NOT NULL)),
                CONSTRAINT role_grant_proposal_lapse_reason         CHECK (((status = 'lapsed') = (lapse_reason IS NOT NULL))
                                                                           AND (lapse_reason IS NULL OR lapse_reason IN ('expired', 'subject_removed', 'proposer_removed'))),
                CONSTRAINT role_grant_proposal_reason_only_on_reject CHECK (reason IS NULL OR (status = 'rejected' AND char_length(reason) BETWEEN 1 AND 500)),
                CONSTRAINT role_grant_proposal_expiry_window        CHECK (expires_at - proposed_at = interval '336 hours'),
                CONSTRAINT role_grant_proposal_decided_not_before_proposed CHECK (decided_at IS NULL OR decided_at >= proposed_at)
            )
            SQL);
        // One pending proposal per (user, role): §4.2's `unique:` mechanism, a lifecycle partial on a class-none table
        // (`IdempotencyContractTest::LIFECYCLE_PARTIALS`). Also Create's same-pair read and `?user=&role=&status=pending`.
        $this->addSql("CREATE UNIQUE INDEX role_grant_proposal_pending_unique  ON role_grant_proposal (tenant_id, user_id, role_id) WHERE status = 'pending'");
        // A role-led pending lookup (`?role=&status=pending`; E-15h's count at DeleteRole, later).
        $this->addSql("CREATE INDEX        role_grant_proposal_pending_by_role ON role_grant_proposal (tenant_id, role_id)          WHERE status = 'pending'");
        // ListRoleGrantProposals' keyset (proposed_at DESC, id DESC) — scanned backwards; no DESC (`SCHEMA.md` §9).
        $this->addSql('CREATE INDEX        role_grant_proposal_tenant_proposed ON role_grant_proposal (tenant_id, proposed_at, id)');

        $table = self::TABLE;
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

        // `DATA` + `SEC` §3: unconditional — reaching this body IS the violation; the predicate is on the trigger's WHEN.
        $this->addSql(sprintf(<<<'SQL'
            CREATE FUNCTION %s() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION
                    'role_grant_proposal_decided_is_final: a decided proposal (status %%) is never updated. Only a pending proposal changes, once.', OLD.status
                    USING ERRCODE = 'check_violation';
                RETURN NEW;
            END;
            $$
            SQL, self::TRIGGER));
        // Deny-by-default reaches functions (`GrantFootprintTest`'s PUBLIC-function check); the precedent's EXECUTE grant.
        $this->addSql(sprintf('REVOKE ALL ON FUNCTION %s() FROM PUBLIC', self::TRIGGER));
        $this->addSql(sprintf('GRANT EXECUTE ON FUNCTION %s() TO erp_app', self::TRIGGER));
        $this->addSql(sprintf(<<<'SQL'
            CREATE TRIGGER %s
            BEFORE UPDATE ON %s
            FOR EACH ROW WHEN (OLD.status <> 'pending')
            EXECUTE FUNCTION %s()
            SQL, self::TRIGGER, self::TABLE, self::TRIGGER));

        // REVOKE first, then GRANT: the footprint is independent of whatever default privileges stand.
        $this->addSql("REVOKE ALL ON $table FROM erp_app, erp_ai, erp_auth, erp_authlog, erp_ratelimit");
        $this->addSql("GRANT SELECT ON $table TO erp_app");
        $this->addSql("GRANT INSERT (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at) ON $table TO erp_app");
        $this->addSql("GRANT UPDATE (status, decided_at, lapse_reason, updated_at) ON $table TO erp_app");
    }

    /** The trigger, the function, then the table: `DROP TABLE` alone would leave the function behind. */
    public function down(Schema $schema): void
    {
        $this->addSql(sprintf('DROP TRIGGER %s ON %s', self::TRIGGER, self::TABLE));
        $this->addSql(sprintf('DROP FUNCTION %s()', self::TRIGGER));
        $this->addSql(sprintf('DROP TABLE %s', self::TABLE));
    }
}
