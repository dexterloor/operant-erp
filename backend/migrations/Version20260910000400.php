<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Step 5 of `AuditEvent`'s six-step extension process — the catalogue
 * extension for `purchase_order.cancelled`.**
 *
 * `ADR-008` **Amendment 1** (2026-09-10) extends DSoD-1 to cancellation, which
 * makes `CancelPurchaseOrder` a controlled transition and puts it on the trail.
 * `SECURITY_MODEL.md` **E-19** is the enforcement rule. `SEC` owns steps 1–2 —
 * the name and the `BUSINESS` filing under PC-11 (4a); this file is step 5 and
 * nothing else.
 *
 * ---------------------------------------------------------------------------
 * Why a NEW file rather than an edit to an existing one
 * ---------------------------------------------------------------------------
 * **Two** migrations have already re-issued `audit_log_event_type_values` and
 * **both are recorded in `doctrine_migration_versions`**:
 * `Version20260907000200` installed it with nine literals and
 * `Version20260909000300` widened it to ten for `auth.password.changed`.
 * Doctrine replays nothing it has already recorded, so an in-place edit to
 * either `CHECK` list is invisible to every database that has run it — the
 * constant would carry eleven values, the server would carry ten, and the first
 * `purchase_order.cancelled` insert would raise `23514` in whichever environment
 * nobody rebuilt. Opening the most recent of the two and adding a literal is the
 * obvious move and it is the forbidden one. A forward migration is the only
 * shape that reaches an existing database.
 *
 * ---------------------------------------------------------------------------
 * The list is HAND-WRITTEN LITERALS, and generating it is a measured fake-green
 * ---------------------------------------------------------------------------
 * `App\SharedKernel\ValueObject\AuditEvent::ALL` is **not** read here. The
 * reason is recorded in that class's docblock and was measured rather than
 * reasoned about: a fabricated constant added to the class with no migration was
 * **fully green from a clean volume** while the migration generated its list,
 * because `AuditEventCatalogueTest` reconciles `pg_constraint` against `ALL` and
 * a constraint built from `ALL` cannot disagree with `ALL`. The redundancy
 * between this file and that class **is** the check; removing it removes the
 * check while leaving the test.
 *
 * Observed again on this pass, in the direction that matters: with the constant
 * and `ALL` edited and this file not yet written,
 * `theServersLegalSetIsExactlyTheCodeCatalogue` and
 * `everyCatalogueValueIsAcceptedByTheDatabase` were both red. A generated list
 * cannot produce that red.
 *
 * The literals are kept in `AuditEvent::ALL`'s declaration order so a diff of
 * the two reads straight. The test sorts both sides, so order is not
 * load-bearing — legibility is the only reason.
 *
 * ---------------------------------------------------------------------------
 * What this migration is NOT authorised to do
 * ---------------------------------------------------------------------------
 *   - **No change to `audit_log_ip_address_scope`.** That is step 6, and step 6
 *     applies to **security** events only. `purchase_order.cancelled` is a
 *     `BUSINESS` event, so it must **not** appear in that constraint's list and
 *     must **not** be pinned in `AuditWriterTest::PINNED_IP_BEARING_EVENTS`.
 *     Adding it there would put a business event inside `API.md` §9.1's layer 1a
 *     and let a colleague-readable row carry a source IP.
 *   - **No `cancel()`, no `CancelPurchaseOrder`, no writer.** Nothing writes this
 *     event yet. `PurchaseOrder` has no cancellation method at all, and X9-1 with
 *     `§8.3`'s reachability clause are what find that. A catalogue member with no
 *     writer is the normal intermediate state of this process, not a defect.
 *   - **No `purchase_order` schema change.** The event's written reason lands in
 *     `audit_log.metadata` (`STOCK_MOVEMENT_ADJUSTED`'s precedent), which needs
 *     no DDL because `metadata` is already `jsonb`.
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
 * validation scan of the second, and the split buys nothing.
 *
 * **The `DROP … IF EXISTS` is the one statement that can lose ground**, and it
 * is inherited knowingly: without a wrapping transaction it commits on its own,
 * so between it and the `ADD` the column carries no domain at all. On a first run
 * the `DROP` removes nothing, so the window exists only on a **re-run**, which by
 * construction only happens after this migration has already failed once.
 *
 * **If `VALIDATE` fails, that is a finding.** It means real rows carry an
 * `event_type` outside the eleven below. `audit_log` is append-only by grant
 * (S-9), so those rows cannot be quietly rewritten: the event has to be
 * classified and added, which is the correct outcome and is this process from the
 * top.
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent for the catalogue and the six-step extension process
 * @see ADR-008 Amendment 1 (2026-09-10) for the ruling that puts cancellation under DSoD-1
 * @see SECURITY_MODEL.md §5 E-19 and §3 PC-11 (4a), (4b)
 * @see \App\Tests\Tenancy\AuditEventCatalogueTest for the both-directions reconciliation
 */
final class Version20260910000400 extends AbstractMigration
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
        return 'ADR-008 A1: widen audit_log_event_type_values to admit purchase_order.cancelled (E-19).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS %s', self::CONSTRAINT));

        // Written out, not generated from App\SharedKernel\ValueObject\AuditEvent.
        // See the docblock: a constraint built from the constant cannot disagree
        // with the constant, and AuditEventCatalogueTest exists to detect exactly
        // that disagreement.
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
                'purchase_order.cancelled',
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
     * The reverse is the **ten-value** list `Version20260909000300` installed,
     * not a bare `DROP`.
     *
     * Dropping the constraint would leave `event_type` with no domain at all —
     * the state B-4 exists to end — so a `down()` that "undoes" this migration by
     * removing the check would take the column back past the state this project
     * was in before the catalogue existed, which is a wider hole than the one
     * being closed. It is re-issued at its previous membership instead.
     *
     * It will fail if any `purchase_order.cancelled` row exists, and that is
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
                'auth.password.changed',
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
