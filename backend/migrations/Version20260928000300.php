<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Step 5 of `AuditEvent`'s extension process for `invoice.issued`** — owner ruling Q5 of sales step 6
 * (*"Column + audit event"*, 2026-09-23); `SEC` filed it **`BUSINESS`**, subject the invoice, metadata `{}`
 * (`docs/session13/output9-sales-7-sec.md` §5.1). Steps 1–4 are in `AuditEvent`; this file is step 5 and nothing else.
 *
 * **A copy of `Version20260919000400`'s shape, exactly** (design §19 C3, revised after RED-3 F-7): `DROP … IF EXISTS`,
 * `ADD … NOT VALID`, `VALIDATE`, and `isTransactional(): false` so the `AccessExclusiveLock` of the `ADD` is not held
 * across the validation scan. That file's docblock carries the lock measurements and the one inherited window (a
 * re-run after a failed `DROP`/`ADD` leaves `audit_log` briefly without the CHECK).
 *
 * **The literal list is the latest committed re-issue's ∪ {`invoice.issued`}**, grepped from `Version20260919000400`
 * and **never generated from `AuditEvent::ALL`** — a constraint built from the constant cannot disagree with it, and
 * `AuditEventCatalogueTest` exists to detect exactly that disagreement (measured fake-green, recorded on `AuditEvent`).
 * M-27 (2026-09-28): the four event-type re-issues so far are monotone — no member was ever removed — and
 * `AuditEventCatalogueTest::theNewestCatalogueReissueIsTheCodeCatalogueAndASupersetOfEveryEarlierOne` now holds that
 * property at source level for every later file, B-4a's included (`SEC` §5.2 rule 4).
 *
 * **Not authorised here:** `audit_log_ip_address_scope` (`invoice.issued` is a business event, so it carries no IP
 * and step 6 does not apply; invoice commits never re-issue that constraint — `SEC` §5.2 rule 2); any grant (`erp_app`
 * already holds INSERT on `audit_log`, measured); any other member.
 *
 * **`down()` re-issues the twelve-member list** `Version20260919000400` installed, never a bare `DROP`. It fails if an
 * `invoice.issued` row exists, which is correct: `audit_log` is append-only (S-9).
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::INVOICE_ISSUED
 * @see \App\Tests\Tenancy\AuditEventCatalogueTest
 */
final class Version20260928000300 extends AbstractMigration
{
    private const CONSTRAINT = 'audit_log_event_type_values';

    /** See `Version20260919000400`: without this the `NOT VALID` / `VALIDATE` split buys nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Sales step 6 C3 (Q5): widen audit_log_event_type_values to admit invoice.issued (IssueInvoice).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS %s', self::CONSTRAINT));

        // Written out, not generated from App\SharedKernel\ValueObject\AuditEvent.
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
                'sales_order.cancelled',
                'invoice.issued',
                'stock_movement.adjusted',
                'role.assigned',
                'role.revoked'
            )) NOT VALID
            SQL,
            self::CONSTRAINT,
        ));

        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::CONSTRAINT));
    }

    /** The twelve-member list `Version20260919000400` installed. */
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
                'purchase_order.cancelled',
                'sales_order.cancelled',
                'stock_movement.adjusted',
                'role.assigned',
                'role.revoked'
            ))
            SQL,
            self::CONSTRAINT,
        ));
    }
}
