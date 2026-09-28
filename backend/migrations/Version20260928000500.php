<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Step 5 of `AuditEvent`'s extension process for `invoice.paid`** — sales step 6, C5 (`PayInvoice`); OI-2 (owner
 * ruling #2, 2026-09-14: *"no correction ships without its audit event"*, and the payment is the act a reversal later
 * undoes). `SEC` filed it **`BUSINESS`**, subject the invoice, actor the payer, metadata `{"paid_at": <the value
 * written>}` (`docs/session13/output9-sales-7-sec.md` §5.1). Steps 1–4 are in `AuditEvent`; this file is step 5 and
 * nothing else.
 *
 * **A copy of `Version20260928000300`'s shape, exactly** (itself `Version20260919000400`'s): `DROP … IF EXISTS`,
 * `ADD … NOT VALID`, `VALIDATE`, and `isTransactional(): false` so the `AccessExclusiveLock` of the `ADD` is not held
 * across the validation scan (design §19.6 F-7: the catalogue file is separate from the transactional schema/grant file,
 * `Version20260928000400`).
 *
 * **The literal list is the latest committed re-issue's (`Version20260928000300`, thirteen members) ∪
 * {`invoice.paid`}**, grepped from that file and **never generated from `AuditEvent::ALL`** — a constraint built from
 * the constant cannot disagree with it, and `AuditEventCatalogueTest` exists to detect exactly that disagreement.
 * `theNewestCatalogueReissueIsTheCodeCatalogueAndASupersetOfEveryEarlierOne` holds the history monotone.
 *
 * **Not authorised here:** `audit_log_ip_address_scope` (a business event carries no IP; invoice commits never
 * re-issue that constraint — `SEC` §5.2 rule 2); any grant (`erp_app` already holds INSERT on `audit_log`); any other
 * member.
 *
 * **`down()` re-issues the thirteen-member list** `Version20260928000300` installed, never a bare `DROP`. It fails if
 * an `invoice.paid` row exists, which is correct: `audit_log` is append-only (S-9).
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::INVOICE_PAID
 * @see \App\Tests\Tenancy\AuditEventCatalogueTest
 */
final class Version20260928000500 extends AbstractMigration
{
    private const CONSTRAINT = 'audit_log_event_type_values';

    /** See `Version20260919000400`: without this the `NOT VALID` / `VALIDATE` split buys nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Sales step 6 C5: widen audit_log_event_type_values to admit invoice.paid (PayInvoice).';
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
                'invoice.paid',
                'stock_movement.adjusted',
                'role.assigned',
                'role.revoked'
            )) NOT VALID
            SQL,
            self::CONSTRAINT,
        ));

        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::CONSTRAINT));
    }

    /** The thirteen-member list `Version20260928000300` installed. */
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
                'invoice.issued',
                'stock_movement.adjusted',
                'role.assigned',
                'role.revoked'
            ))
            SQL,
            self::CONSTRAINT,
        ));
    }
}
