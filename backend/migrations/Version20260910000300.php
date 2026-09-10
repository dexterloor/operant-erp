<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * E4-5 / API-O-23 — `API.md` §9.1's **layer 3**: `audit_log.ip_address` may
 * appear only on a security event.
 *
 * ---------------------------------------------------------------------------
 * The blocker
 * ---------------------------------------------------------------------------
 * §9.1 (API-16) specifies **three** enforcement layers *"in increasing strength,
 * and all three are specified because the weakest is the one an implementer
 * reaches for"*: the writer's predicate, the entity constructor's guard, and
 * this `CHECK`. Layers 1 and 1a shipped with `AuditWriterTest`; layer 2 was
 * absent and lands in the same pass as this file; **layer 3 did not exist at
 * all** — verified against the server on 2026-09-09 and re-verified against the
 * committed migrations on 2026-09-10: `audit_log` carried
 * `audit_log_event_type_values`, `audit_log_id_is_uuid_v4`,
 * `audit_log_permission_code_scope` and `audit_log_subject_pair`, and **no
 * constraint mentioning `ip_address` at all**. `Version20260909000300`'s own
 * docblock records the same absence independently.
 *
 * The consequence is API-O-23's sentence and is not a hypothetical: *the writer
 * is guarded and the database is not*. `AuditWriterTest::
 * everyClassThatCanWriteTheTrailIsClassifiedHere` derives the set of files that
 * can write the trail from a scan of `src/`, so a fourth PHP writer cannot
 * arrive unnoticed — but a `psql` session, a fixture, a future `COPY`, or any
 * writer outside `backend/src/` is unconstrained, and `ip_address` is personal
 * data that §9.1 says has *"no forensic value on the bulk business event, which
 * the project's own committed fixture makes the overwhelming majority of the
 * table"*.
 *
 * ---------------------------------------------------------------------------
 * The literals are written out, and that is the whole mechanism
 * ---------------------------------------------------------------------------
 * The eight values below are **hand-written and never generated from
 * `App\SharedKernel\ValueObject\AuditEvent::SECURITY`** — E4-5's clause, on
 * E4-1's clause (4) and for the reason `AuditEvent`'s own docblock states: a
 * constraint built from the constant the reconciliation compares it against
 * cannot disagree with that constant, so the reconciliation becomes a tautology.
 * That fake-green was **measured** on `audit_log_event_type_values`, where a
 * fabricated constant with no migration was fully green from a clean volume
 * while the migration generated its list.
 *
 * `AuditWriterTest::theCheckConstraintAndTheConstantAgreeInBothDirections` reads
 * this constraint back out of `pg_constraint` and reconciles it with
 * `AuditEvent::SECURITY` **in both directions**: a literal the constant lacks
 * fails, and a constant member the literal lacks fails. Editing this list and
 * editing `AuditEvent::SECURITY` are therefore two deliberate acts in two files
 * and neither can quiet the other — the same shape as `PINNED_IP_BEARING_EVENTS`
 * one layer up.
 *
 * **So a new security event needs this migration re-issued at a wider list.**
 * That is `AuditEvent`'s extension step 6, whose second half had no subject
 * until this file existed. It is now executable: a forward migration dropping
 * and re-adding this constraint, never an in-place edit of this file, which is
 * recorded in `doctrine_migration_versions` the moment it runs.
 *
 * ---------------------------------------------------------------------------
 * One-directional, on `Version20260909000200`'s recorded reasoning
 * ---------------------------------------------------------------------------
 * The `CHECK` says *an `ip_address` may appear only on a security event*. It is
 * deliberately **not** the biconditional
 * `(ip_address IS NOT NULL) = (event_type IN (…))`, for the two reasons
 * `Version20260909000200` gives for making `audit_log_permission_code_scope`
 * one-directional, both of which hold verbatim here:
 *
 * 1. **A biconditional can refuse an audit write on the authentication path.** A
 *    security event written with no request in scope — a console command, a
 *    fixture, a writer behind a queue — would raise `23514` instead of writing
 *    the row. **Losing the audit record is worse than losing the field**, and
 *    §9.1's rule is about what an IP may be *attached to*, not about a field
 *    every security writer is obliged to populate.
 * 2. **Scope.** `AuditEventCatalogueTest::everyCatalogueValueIsAcceptedByThe`
 *    `Database` inserts one row for every value in `AuditEvent::ALL` with no
 *    `ip_address`, and it is right to: it is testing `event_type`'s domain. A
 *    biconditional turns that green test red for **eight of the ten values**,
 *    and the only non-weakening repair is a per-event "requires an IP"
 *    declaration on `AuditEvent` — a catalogue extension, which is **E4-1's**
 *    and not E4-5's.
 *
 * What that leaves open, stated rather than left to be discovered: a security
 * event carrying a `NULL` `ip_address` is accepted by the database. §9.1's layer
 * 1 is what puts the IP there, and `AuditWriterTest::
 * onlySecurityEventsCarryAnIpAddress` is what checks that it does.
 *
 * ---------------------------------------------------------------------------
 * Grants: none. Deliberately.
 * ---------------------------------------------------------------------------
 * No column is added and no privilege changes. `erp_app` holds `SELECT, INSERT`
 * on `audit_log` and `erp_authlog` holds `INSERT` (S-9); a `CHECK` is enforced
 * against every writer regardless of role, which is the entire reason this layer
 * is stronger than the two above it.
 *
 * ---------------------------------------------------------------------------
 * Locks (postgres-database-migration)
 * ---------------------------------------------------------------------------
 * Non-transactional, for the same reason `Version20260907000200` and
 * `Version20260909000200` are: inside one transaction the `NOT VALID` /
 * `VALIDATE` split holds `AccessExclusiveLock` across the validation scan and
 * buys nothing.
 *
 *   - `DROP CONSTRAINT IF EXISTS` — `AccessExclusiveLock`, no scan. A no-op on
 *     a first run; it is unconditional rather than conditional so that
 *     re-issuing this constraint at a wider list (step 6) is the same three
 *     statements and not a different shape.
 *   - `ADD CONSTRAINT … NOT VALID` — `AccessExclusiveLock`, no scan.
 *   - `VALIDATE CONSTRAINT` — `ShareUpdateExclusiveLock`, scans, concurrent with
 *     `INSERT`.
 *
 * **The `VALIDATE` is load-bearing and is asserted, not assumed.** Because this
 * migration is non-transactional the three statements commit independently: a
 * `VALIDATE` that fails, or is dropped from `up()`, leaves the table carrying a
 * `NOT VALID` constraint — enforced for new rows and **silent about every row
 * already there** — whose `pg_get_constraintdef` text is byte-identical to the
 * validated one. `AuditEventCatalogueTest` records that exact mutation being run
 * on `audit_log_event_type_values` and the whole class staying green, which is
 * why the reconciliation below asserts `convalidated` as well as the text.
 *
 * `audit_log` is append-only and unpartitioned, so the validation scan is over a
 * table designed to grow without bound; it is empty on every database this
 * project has today, and the `ShareUpdateExclusiveLock` form is used anyway so
 * that the statement is in its live-database shape rather than one that would
 * have to be rewritten before deployment.
 *
 * @see API.md §9.1 API-16, §14.1 API-O-23 and API-O-18
 * @see SECURITY_MODEL.md §5.1.4; §5 E-3, E-13, E-23
 * @see DELIVERY_LEDGER.md Output 4 entry condition E4-5; Output 7 entry condition E7-1
 * @see \App\SharedKernel\ValueObject\AuditEvent the extension process, step 6
 * @see \App\Modules\AuditLog\Infrastructure\Entity\AuditLogEntry §9.1's layer 2
 * @see \App\Tests\Audit\AuditWriterTest for the two-directional reconciliation and the refusal probe
 */
final class Version20260910000300 extends AbstractMigration
{
    private const CONSTRAINT = 'audit_log_ip_address_scope';

    /**
     * See "Locks" above. Without this the `NOT VALID` / `VALIDATE` split runs
     * inside one transaction and holds `AccessExclusiveLock` across the
     * validation scan, which is the thing the split exists to avoid.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'E4-5/API-O-23: audit_log.ip_address may appear only on a security event (API.md §9.1 layer 3).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS %s', self::CONSTRAINT));

        // Written out, not generated from App\SharedKernel\ValueObject\AuditEvent::SECURITY.
        // See the docblock: a constraint built from the constant cannot disagree
        // with the constant, and the reconciliation exists to detect exactly that
        // disagreement.
        $this->addSql(sprintf(
            <<<'SQL'
            ALTER TABLE audit_log ADD CONSTRAINT %s CHECK (ip_address IS NULL OR event_type IN (
                'auth.login.success',
                'auth.login.failure',
                'auth.login.tenant_inactive',
                'auth.password.changed',
                'authz.permission.denied',
                'data.export.completed',
                'role.assigned',
                'role.revoked'
            )) NOT VALID
            SQL,
            self::CONSTRAINT,
        ));

        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::CONSTRAINT));
    }

    /**
     * A bare `DROP`, and unlike `Version20260909000300`'s `down()` that is the
     * honest reverse rather than a hole.
     *
     * That migration re-issues `audit_log_event_type_values` at its previous
     * membership because dropping it would leave `event_type` with **no domain
     * at all** — a wider hole than the one being closed. There is no previous
     * membership here: before this file `audit_log` carried no constraint
     * mentioning `ip_address`, so the state this returns to is the state
     * API-O-23 describes, with §9.1's layers 1, 1a and 2 still standing in PHP.
     *
     * It cannot fail on existing data. Dropping a `CHECK` never scans.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(sprintf('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS %s', self::CONSTRAINT));
    }
}
