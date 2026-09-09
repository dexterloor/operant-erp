<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **E4-1 step 5 — the catalogue extension for `auth.password.changed` (E-23).**
 *
 * ---------------------------------------------------------------------------
 * Why a NEW file rather than an edit to `Version20260907000200`
 * ---------------------------------------------------------------------------
 * That migration re-issued `audit_log_event_type_values` with nine literals and
 * **is recorded in `doctrine_migration_versions`**. Doctrine replays nothing it
 * has already recorded, so an in-place edit to its `CHECK` list is invisible to
 * every database that has run it: the constant would carry ten values, the
 * server would carry nine, and the first `auth.password.changed` insert would
 * raise `23514` in whichever environment nobody rebuilt. E4-1's clause (3) is
 * this defect, written down before it happened. A forward migration is the only
 * shape that reaches an existing database.
 *
 * ---------------------------------------------------------------------------
 * The list is HAND-WRITTEN LITERALS, and generating it is a measured fake-green
 * ---------------------------------------------------------------------------
 * `App\Audit\AuditEvent::ALL` is **not** read here, and E4-1's *fails if* names
 * generating this list from it as a defect in terms. The reason is recorded in
 * `AuditEvent`'s own docblock and was measured rather than reasoned about: a
 * fabricated constant added to the class with no migration was **fully green
 * from a clean volume** while the migration generated its list, because
 * `AuditEventCatalogueTest` reconciles `pg_constraint` against `ALL` and a
 * constraint built from `ALL` cannot disagree with `ALL`. The redundancy between
 * this file and that class **is** the check; removing it removes the check while
 * leaving the test.
 *
 * The literals are kept in `AuditEvent::ALL`'s declaration order so a diff of
 * the two reads straight. The test sorts both sides, so order is not
 * load-bearing — legibility is the only reason.
 *
 * ---------------------------------------------------------------------------
 * What this migration is NOT authorised to do
 * ---------------------------------------------------------------------------
 * E4-1's migration slot is **scoped to the catalogue extension**, and `RED`
 * named the cost of opening it: it is the same slot a session table would use.
 * So, explicitly:
 *
 *   - **No `ip_address` `CHECK`.** §9.1's layer 3 does not exist on this server
 *     (**API-O-23**, verified: `audit_log` carries `audit_log_event_type_values`,
 *     `audit_log_id_is_uuid_v4`, `audit_log_permission_code_scope` and
 *     `audit_log_subject_pair`, and no constraint mentioning `ip_address`).
 *     API-O-18's step 5 presumes it exists and there is nothing to re-issue.
 *     Building it needs its own scoped entry condition; neither E4-1 nor E4-2
 *     authorises it, and adding it here on the grounds that this file is already
 *     touching `audit_log` is exactly how a scope stops meaning anything.
 *   - **No session table** (D-14, E4-1's scoping paragraph).
 *   - **No `permission_code` requirement clause.** `audit_log_permission_code_scope`
 *     is deliberately one-directional and `Version20260909000200` records why.
 *
 * ---------------------------------------------------------------------------
 * Locks, and the `isTransactional()` override
 * ---------------------------------------------------------------------------
 * Identical in shape and reasoning to `Version20260907000200`, which measured
 * both lock levels against `pg_locks` rather than trusting the assigned skill's
 * table:
 *
 *   ADD CONSTRAINT … CHECK … NOT VALID  ->  AccessExclusiveLock, no scan
 *   VALIDATE CONSTRAINT                 ->  ShareUpdateExclusiveLock, scans
 *
 * Without the `isTransactional(): false` override both statements run inside one
 * `BEGIN … COMMIT`, the `AccessExclusiveLock` from the first is held across the
 * validation scan of the second, and the split buys nothing. The override is
 * safe here for the same reason it is safe there: both statements are
 * forward-only, so a failure at `VALIDATE` leaves the table with a constraint
 * enforced for every new row — better than the state before, never worse.
 *
 * **The `DROP … IF EXISTS` is the one statement that can lose ground**, and it
 * is inherited knowingly. Without a wrapping transaction it commits on its own,
 * so between it and the `ADD` the column carries no domain at all. On a first
 * run the `DROP` removes nothing, so the window exists only on a **re-run**,
 * which by construction only happens after this migration has already failed
 * once. `Version20260907000200` records the trade and chooses the same side.
 *
 * **If `VALIDATE` fails, that is a finding.** It means real rows carry an
 * `event_type` outside the ten below. `audit_log` is append-only by grant (S-9),
 * so those rows cannot be quietly rewritten: the event has to be classified and
 * added, which is the correct outcome and is E4-1's process from the top.
 *
 * @see \App\Audit\AuditEvent for the catalogue and the six-step extension process
 * @see SECURITY_MODEL.md §5 E-23 and §5.1.4 for `SEC`'s ruling (E4-1 steps 1 and 2)
 * @see DELIVERY_LEDGER.md — Output 4 entry condition E4-1
 * @see \App\Tests\Tenancy\AuditEventCatalogueTest for the both-directions reconciliation
 */
final class Version20260909000300 extends AbstractMigration
{
    private const CONSTRAINT = 'audit_log_event_type_values';

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
        return 'E4-1: widen audit_log_event_type_values to admit auth.password.changed (E-23).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS %s', self::CONSTRAINT));

        // Written out, not generated from App\Audit\AuditEvent. See the docblock:
        // a constraint built from the constant cannot disagree with the constant,
        // and AuditEventCatalogueTest exists to detect exactly that disagreement.
        $this->addSql(sprintf(
            <<<'SQL'
            ALTER TABLE audit_log ADD CONSTRAINT %s CHECK (event_type IN (
                'auth.login.success',
                'auth.login.failure',
                'auth.login.tenant_inactive',
                'auth.password.changed',
                'authz.permission.denied',
                'data.export.completed',
                'purchase_order.placed',
                'stock_movement.adjusted',
                'role.assigned',
                'role.revoked'
            )) NOT VALID
            SQL,
            self::CONSTRAINT,
        ));

        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::CONSTRAINT));
    }

    /**
     * The reverse is the **nine-value** list `Version20260907000200` installed,
     * not a bare `DROP`.
     *
     * Dropping the constraint would leave `event_type` with no domain at all —
     * the state B-4 exists to end — so a `down()` that "undoes" this migration by
     * removing the check would take the column back past the state this project
     * was in before the catalogue existed, which is a wider hole than the one
     * being closed. It is re-issued at its previous membership instead.
     *
     * It will fail if any `auth.password.changed` row exists, and that is
     * correct: `audit_log` is append-only (S-9) and no role holds `DELETE`, so a
     * rollback that discarded the domain those rows sit outside would be a
     * rollback into an inconsistent state. The failure names the reason.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(sprintf('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS %s', self::CONSTRAINT));

        $this->addSql(sprintf(
            <<<'SQL'
            ALTER TABLE audit_log ADD CONSTRAINT %s CHECK (event_type IN (
                'auth.login.success',
                'auth.login.failure',
                'auth.login.tenant_inactive',
                'authz.permission.denied',
                'data.export.completed',
                'purchase_order.placed',
                'stock_movement.adjusted',
                'role.assigned',
                'role.revoked'
            ))
            SQL,
            self::CONSTRAINT,
        ));
    }
}
