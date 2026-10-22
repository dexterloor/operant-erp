<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Keep the audit trail in the order things were recorded when the database clock steps back** — one `BEFORE INSERT`
 * trigger on `audit_log`, no column, index, cursor, API or grant change. A fix to shipped behaviour (standing rule of
 * 2026-09-13; owner told the same day). Authority: `docs/session13/audit-order-clock-final-red.md` > `audit-order-clock-design.md`
 * Revision 1 FINAL (R1.1) > Response to RED.
 *
 * **The defect (Move 702).** Every audit writer stamps `occurred_at` from the database wall clock (`now()`, the transaction
 * start; `RoleAuditWriter` `clock_timestamp()` after its lock), and nothing made that monotonic. When the clock stepped back
 * between two transactions — erp3's Lima host agent does it every few seconds, and a production NTP step after a VM resume
 * can too — a later event carried an earlier stamp, so History and the audit trail served it BELOW the event it followed,
 * and `app:roles:reconcile` (arms A/B and D fold `role.*` events in `occurred_at` order) could report a removal or a phantom
 * permission that was only two events in reversed order. Keyset paging stayed total (no skip, no repeat): order was wrong.
 *
 * **The new meaning (H-2).** `occurred_at` is the writer's transaction start, raised to 1 µs after the newest row in the
 * tenant's trail that is visible when the row is inserted — ignoring rows dated more than 60 s ahead. Read newest first that
 * is insert order for causally related writes, and transaction-start order otherwise; it also re-orders two concurrent
 * transactions when the slower one inserts after the quicker one committed (no clock movement needed). One transaction's
 * rows stay a tie group unless the transaction was raised, when they split.
 *
 *  - **The bound sits INSIDE the probe (M-1)**, so a row planted 2099 cannot switch the raise off, and a planted row can
 *    shift later stamps by at most a minute (`RESIDUAL_GAPS.md` G-54 (iii)).
 *  - **`WHEN NEW.occurred_at >= now()`:** a deliberately dated write (a fixture, a back-dated seed) is never moved. It is also
 *    an opt-out — SQL as `erp_app` can stamp `now() - 1 µs` and skip it — which is A-3's class (`erp_app` can already write
 *    any `occurred_at`); not a technique a builder may use without review (G-54 (iii)).
 *  - **`WHEN has_table_privilege('public.audit_log', 'SELECT')` (H-1):** the principal check, never the event type. A
 *    principal that may not read the trail (`erp_authlog`, the pre-authentication sign-in writer; `erp_worker` from Mail-C1)
 *    is skipped — never refused with 42501 — and its rows keep their plain stamp (G-54 (i)).
 *    `GrantFootprintTest::everyAuditWriterIsClampedOrNamedUnclamped` derives every INSERT holder from `pg_roles`.
 *  - **`a.tenant_id = NEW.tenant_id`:** locally RLS already hides other tenants from every principal (the owner is
 *    NOBYPASSRLS under FORCE RLS), so no test can observe this predicate; on a BYPASSRLS owner (Neon) it is the ONLY isolation
 *    of owner-process audit writes. `AuditLogOrderTest::theTriggerAndItsFunctionAreAsDeclared` pins its text (final RED F-1).
 *  - **`VOLATILE`:** each row of one multi-row INSERT sees the rows processed earlier in the same command, so a raised
 *    statement gives each row its own place (T-E′, mutation M13).
 *  - **`SECURITY INVOKER`** (no house definer function — `GrantFootprintTest`), and **`SET search_path = pg_catalog, public,
 *    pg_temp`** (final RED F-8): `pg_temp` LAST pins operator and function resolution to `pg_catalog`; what stops a temp
 *    table shadowing the trail is the schema qualification `public.audit_log` in the body and the `WHEN` (and `TEMPORARY`
 *    is revoked, `Version20260916000100`). The clause adds a GUC save/restore per row, inside the measured cost.
 *  - **Cost (measured 2026-10-22, `docs/session13/audit-order-progress.md`):** the probe is one backward probe of
 *    `audit_log_tenant_occurred_id_idx` (generic plan, `SchemaQueryPlanTest::theAuditOrderProbeIsOneBackwardIndexProbe`);
 *    100 single writes behind 100 uncommitted raised rows cost nothing measurable, behind 10 000 about +0.6 ms each.
 *  - **EXECUTE is not checked at fire time** (measured); `REVOKE … FROM PUBLIC` is the house deny-by-default and
 *    `GRANT EXECUTE … TO erp_app` names the intended caller, as the precedents do.
 *
 * Transactional; rewrites no row. Rows written before this keep their stamps (G-54 (v)).
 */
final class Version20261022004000 extends AbstractMigration
{
    private const FUNCTION = 'public.audit_log_monotonic_occurred_at';

    private const TRIGGER = 'audit_log_monotonic_occurred_at';

    public function getDescription(): string
    {
        return 'Audit order under a backward clock step: BEFORE INSERT trigger raising a now-stamped audit_log row to 1 µs after the tenant\'s newest visible row (≤ 60 s ahead), for principals that can read the trail';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf(<<<'SQL'
            CREATE FUNCTION %s() RETURNS trigger
            LANGUAGE plpgsql VOLATILE SECURITY INVOKER
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
                latest timestamptz;
            BEGIN
                -- The newest row this transaction can see in the tenant's trail, ignoring any row dated more than 60 s
                -- ahead (a planted far-future row must not switch this off). One backward probe of
                -- audit_log_tenant_occurred_id_idx.
                SELECT max(a.occurred_at) INTO latest
                  FROM public.audit_log a
                 WHERE a.tenant_id = NEW.tenant_id
                   AND a.occurred_at <= NEW.occurred_at + interval '60 seconds';

                -- Strictly ahead only: a transaction's own rows (same now()) stay tied unless it was raised.
                IF latest > NEW.occurred_at THEN
                    NEW.occurred_at := latest + interval '1 microsecond';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL, self::FUNCTION));

        // Deny-by-default reaches functions (`GrantFootprintTest`'s PUBLIC-function check); the precedents' EXECUTE grant.
        $this->addSql(sprintf('REVOKE ALL ON FUNCTION %s() FROM PUBLIC', self::FUNCTION));
        $this->addSql(sprintf('GRANT EXECUTE ON FUNCTION %s() TO erp_app', self::FUNCTION));

        // Fires only for a row stamped "now" or later (a deliberately dated write is never moved), and only for an inserting
        // role that can read the trail (an INSERT-only principal is skipped, never 42501).
        $this->addSql(sprintf(<<<'SQL'
            CREATE TRIGGER %s
            BEFORE INSERT ON public.audit_log
            FOR EACH ROW
            WHEN (NEW.occurred_at >= pg_catalog.now()
                  AND pg_catalog.has_table_privilege('public.audit_log', 'SELECT'))
            EXECUTE FUNCTION %s()
            SQL, self::TRIGGER, self::FUNCTION));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(sprintf('DROP TRIGGER %s ON public.audit_log', self::TRIGGER));
        $this->addSql(sprintf('DROP FUNCTION %s()', self::FUNCTION));
    }
}
