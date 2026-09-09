<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B-4 — give `audit_log.event_type` a domain.
 *
 * ---------------------------------------------------------------------------
 * What was wrong
 * ---------------------------------------------------------------------------
 * `Version20260906000100` created the column as `text NOT NULL` with no
 * `CHECK`, no enum and no domain, and `AuditLogEntry` mapped it as bare
 * `Types::TEXT`. Every other enumerated text column in this schema is
 * `CHECK (col IN (...))` — `tenant.status`, `stock_movement.reason`,
 * `purchase_order.status`, `sales_order.status`, `invoice.status`,
 * `storage_location.kind`, `customer_address.kind`,
 * `unit_of_measure.dimension`. `event_type` was the ninth and the only one
 * without.
 *
 * That was not cosmetic. **PC-11 (`SECURITY_MODEL.md` §3) could not fire.** It requires
 * `audit_log.view_record` to filter on an `IN` over "an event type added to
 * §6.1's catalogue" — but §6.1 is the 60-permission catalogue and contains zero
 * event types, so the clause watched a set that did not exist. E-12 records the
 * same gap from the other side: "`event_type` is `text NOT NULL` with no `CHECK`
 * to fall back on."
 *
 * ---------------------------------------------------------------------------
 * A forward migration, not an edit
 * ---------------------------------------------------------------------------
 * `Version20260906000100` is recorded in `doctrine_migration_versions`, so
 * amending it in place would be invisible to every database that already ran it
 * — the reasoning `Version20260907000100` used, and that migration's own
 * docblock's rule that a migration is frozen history.
 *
 * ---------------------------------------------------------------------------
 * The list is written out by hand, and that is the whole point
 * ---------------------------------------------------------------------------
 * **This file deliberately does not import `App\SharedKernel\ValueObject\AuditEvent`.**
 *
 * An earlier draft built the `IN` list from `AuditEvent::ALL`, arguing that
 * generating it "removes the transcription error; it does not remove the need
 * for the guard". That was wrong, and it was wrong in the direction this project
 * has been burned by before. `AuditEventCatalogueTest::theServersLegalSetIsExactlyTheCodeCatalogue`
 * reads the constraint back out of `pg_constraint` and reconciles it against
 * `AuditEvent::ALL`. If the constraint was *written from* `AuditEvent::ALL`, the
 * test compares the catalogue to itself through one extra indirection — the
 * `SchemaManifest` fake-green shape, one level deeper and therefore harder to
 * see. **A source that cannot drift cannot be reconciled**, and reconciliation
 * is the only thing that guard does.
 *
 * Measured, not argued. With a fabricated `MUTANT_EVENT` added to `AuditEvent`
 * and to `ALL` and no migration written for it, the generated form was green on
 * the catalogue test and green on the full suite **from a clean volume** — an
 * invented event type, fully accepted. (The test and assertion counts that
 * stood here have been dropped rather than updated: the class has grown twice
 * since, and a stale figure is a worse record than none — Rule 15.) The literal form below fails that mutation, which is
 * the behaviour the guard was always claimed to have.
 *
 * So the two sources are independent by construction, they can disagree, and
 * when they disagree the catalogue test says so. The transcription risk the
 * earlier draft was avoiding is real and is exactly what the guard is for: a
 * mistyped literal here fails `theServersLegalSetIsExactlyTheCodeCatalogue`
 * with both sides printed.
 *
 * Extending the catalogue is a multi-step operation and this migration is the
 * step nothing else can stand in for: add the constant to `AuditEvent`, add it
 * to `ALL`, file it in `SECURITY` or `BUSINESS`, declare its subject, and write
 * a further forward migration that drops and re-adds this constraint with the
 * wider literal list. `AuditEvent`'s own docblock is the authority on that list;
 * it is not restated here in a form that can drift.
 *
 * ---------------------------------------------------------------------------
 * Locks (postgres-database-migration)
 * ---------------------------------------------------------------------------
 * `ALTER TABLE … ADD CONSTRAINT … CHECK` takes `AccessExclusiveLock` **and scans
 * the whole table** to validate, so on a large table it blocks reads and writes
 * for the duration of the scan. The two-step form the skill prescribes:
 *
 *   1. `ADD CONSTRAINT … NOT VALID` — `AccessExclusiveLock`, catalogue-only, no
 *      scan. Enforced for every **new** row from this point.
 *   2. `VALIDATE CONSTRAINT` — `ShareUpdateExclusiveLock`, which does not block
 *      reads or writes, and scans concurrently with normal traffic.
 *
 * **Both lock levels were measured, and the assigned skill is wrong about the
 * first one.** `postgres-database-migration`'s lock table gives
 * `ADD CONSTRAINT … NOT VALID` as `ShareUpdateExclusiveLock`. Read back from
 * `pg_locks` inside each statement's own transaction, on a throwaway database:
 *
 *   ADD CONSTRAINT … CHECK … NOT VALID  ->  AccessExclusiveLock
 *   VALIDATE CONSTRAINT                 ->  ShareUpdateExclusiveLock
 *
 * Row 44 (`VALIDATE`) is right; row 43 is not. This is not pedantry — it is the
 * whole reason the transaction wrapping below matters. If step 1 really took
 * `ShareUpdateExclusiveLock`, sharing a transaction with step 2 would cost
 * nothing and the defect described next would not exist.
 *
 * **`isTransactional()` is overridden to `false`, and without that override the
 * split above is decorative.** `AbstractMigration::isTransactional()` returns
 * `true` and `DbalExecutor` honours it in `startMigration()` and
 * `migrationEnd()`, so by default both statements run
 * inside one `BEGIN … COMMIT` on one backend. Measured with
 * `log_statement='all'`: `BEGIN` → `ADD CONSTRAINT … NOT VALID` →
 * `VALIDATE CONSTRAINT` → `COMMIT`. The `AccessExclusiveLock` taken by step 1 is
 * held to the `COMMIT`, so it covers the entire validation scan and the table is
 * blocked exactly as it would be by the one-step form. The split bought nothing
 * and the docblock that described its benefits was teaching the broken shape.
 *
 * **When to copy this and when not to.** The override is safe *for the two
 * constraint statements* because both are forward-only: if `VALIDATE` fails,
 * `ADD … NOT VALID` has committed and the table is left with a constraint that
 * is enforced for every new row — better than the state before, never worse. Do
 * **not** copy the override onto a migration whose statements need to land
 * together; it disables atomicity for the whole file, not just for the pair you
 * were thinking about. And if the validation is meant to happen in a separate,
 * later deploy window rather than seconds after the guard goes in, that is two
 * migrations, not one non-transactional one.
 *
 * **The re-run hazard, and the window the answer to it opens.** A
 * non-transactional migration that dies at `VALIDATE` is not recorded in
 * `doctrine_migration_versions` while the `ADD` has already committed, so the
 * next `doctrine:migrations:migrate` would fail on `ADD CONSTRAINT` with 42710
 * rather than on the real problem. `up()` therefore issues
 * `DROP CONSTRAINT IF EXISTS` first, which makes it idempotent and makes a
 * re-run fail at `VALIDATE` again — at the actual finding.
 *
 * **That `DROP` is a third statement, and it is the one statement here that can
 * lose ground.** Without a wrapping transaction it commits on its own, so
 * between it and the `ADD` the table carries **no domain at all** and a
 * concurrent writer could land an `event_type` nobody classified — which then
 * fails the `VALIDATE` two statements later. The window is real and it is
 * narrow: on a first run the `DROP` removes nothing, so the window exists only
 * on a **re-run**, which by construction only happens after this migration has
 * already failed once. It is named here rather than left to be discovered,
 * because the paragraph above says "forward-only" and this statement is not.
 * If that window is ever unacceptable — a busy `audit_log`, a real re-run — drop
 * the `IF EXISTS` line and accept the 42710 instead; the trade is between a
 * confusing error and an unguarded instant, and this file chooses the error.
 *
 * **If step 2 fails, that is a finding and not a bug in this migration:** it
 * means real rows carry an `event_type` nobody has classified. `audit_log` is
 * append-only by grant (S-9) and no application role holds `DELETE`, so those
 * rows cannot be quietly rewritten — the event has to be added to `AuditEvent`
 * and to a further forward migration, which is the correct outcome. `audit_log`
 * is empty in every environment today, so this is a shape argument, not a
 * prediction.
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent for the catalogue itself and the process for extending it
 * @see security-audit-log-design, Instruction 2
 */
final class Version20260907000200 extends AbstractMigration
{
    private const CONSTRAINT = 'audit_log_event_type_values';

    /**
     * See the "Locks" section above. Without this the `NOT VALID` / `VALIDATE`
     * split runs inside one transaction and holds `AccessExclusiveLock` across
     * the validation scan, which is the thing the split exists to avoid.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'B-4: constrain audit_log.event_type to the enumerated AuditEvent catalogue.';
    }

    public function up(Schema $schema): void
    {
        // Written out, not generated from App\SharedKernel\ValueObject\AuditEvent. See the docblock:
        // a constraint built from the constant cannot disagree with the constant,
        // and AuditEventCatalogueTest exists to detect exactly that disagreement.
        // Keep in the order AuditEvent::ALL declares them so a diff of the two is
        // readable; the test sorts both sides, so order is not load-bearing.
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
            )) NOT VALID
            SQL,
            self::CONSTRAINT,
        ));

        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::CONSTRAINT));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(sprintf('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS %s', self::CONSTRAINT));
    }
}
