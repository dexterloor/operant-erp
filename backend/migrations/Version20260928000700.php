<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Step 5 of `AuditEvent`'s extension process for `invoice.payment_reversed`** — sales step 6, C6
 * (`ReverseInvoicePayment`); OI-2 (owner ruling #2, 2026-09-14: *"no correction ships without its audit event"*).
 * `SEC` filed it **`BUSINESS`**, subject the invoice, actor the reverser, metadata `{"reason": <string>}`, always
 * present, never copying the reversed recorder or `paid_at` (`docs/session13/output9-sales-7-sec.md` §5.1).
 *
 * **A copy of `Version20260928000500`'s shape, exactly**: `DROP … IF EXISTS`, `ADD … NOT VALID`, `VALIDATE`,
 * `isTransactional(): false`; separate from the transactional schema/grant file `Version20260928000600` (F-7).
 *
 * **The literal list is the latest committed re-issue's (`Version20260928000500`, fourteen members) ∪
 * {`invoice.payment_reversed`}**, grepped from that file, never generated from `AuditEvent::ALL`.
 * `AuditEventCatalogueTest::theNewestCatalogueReissueIsTheCodeCatalogueAndASupersetOfEveryEarlierOne` holds it.
 *
 * **Not authorised here:** `audit_log_ip_address_scope` (`SEC` §5.2 rule 2); any grant; any other member.
 * **`down()` re-issues the fourteen-member list** `Version20260928000500` installed.
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::INVOICE_PAYMENT_REVERSED
 * @see \App\Tests\Tenancy\AuditEventCatalogueTest
 */
final class Version20260928000700 extends AbstractMigration
{
    private const CONSTRAINT = 'audit_log_event_type_values';

    /** See `Version20260919000400`: without this the `NOT VALID` / `VALIDATE` split buys nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Sales step 6 C6: widen audit_log_event_type_values to admit invoice.payment_reversed (ReverseInvoicePayment).';
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
                'invoice.payment_reversed',
                'stock_movement.adjusted',
                'role.assigned',
                'role.revoked'
            )) NOT VALID
            SQL,
            self::CONSTRAINT,
        ));

        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::CONSTRAINT));
    }

    /** The fourteen-member list `Version20260928000500` installed. */
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
                'invoice.paid',
                'stock_movement.adjusted',
                'role.assigned',
                'role.revoked'
            ))
            SQL,
            self::CONSTRAINT,
        ));
    }
}
