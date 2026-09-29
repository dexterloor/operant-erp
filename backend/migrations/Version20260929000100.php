<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Step 5 of `AuditEvent`'s extension process for `invoice.voided`** — sales step 6, C7 (`VoidInvoice`); OI-2 (owner
 * ruling #2, 2026-09-14: *"no correction ships without its audit event"*). `SEC` filed it **`BUSINESS`**, subject the
 * invoice, actor the voider, metadata `{"reason": <string>}`, always present (`docs/session13/output9-sales-7-sec.md`
 * §5.1).
 *
 * **A copy of `Version20260928000700`'s shape, exactly**: `DROP … IF EXISTS`, `ADD … NOT VALID`, `VALIDATE`,
 * `isTransactional(): false`. **It stands alone** (design §19 C7): C7 adds no column and no grant — `VoidInvoice`'s
 * flush sets `status` and `updated_at`, both column-granted since C5 (`Version20260928000400`) — and no role-data
 * migration is owed, because the holder listing found no SYSTEM role conferring `invoice.void` (M-18/M-20, recorded in
 * `docs/session13/output9-sales-7-progress.md`, C7).
 *
 * **The literal list is the latest committed re-issue's (`Version20260928000700`, fifteen members) ∪
 * {`invoice.voided`}**, grepped from that file, never generated from `AuditEvent::ALL`.
 * `AuditEventCatalogueTest::theNewestCatalogueReissueIsTheCodeCatalogueAndASupersetOfEveryEarlierOne` holds it.
 *
 * **Not authorised here:** `audit_log_ip_address_scope` (`SEC` §5.2 rule 2); any grant; any other member.
 * **`down()` re-issues the fifteen-member list** `Version20260928000700` installed.
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::INVOICE_VOIDED
 * @see \App\Tests\Tenancy\AuditEventCatalogueTest
 */
final class Version20260929000100 extends AbstractMigration
{
    private const CONSTRAINT = 'audit_log_event_type_values';

    /** See `Version20260919000400`: without this the `NOT VALID` / `VALIDATE` split buys nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Sales step 6 C7: widen audit_log_event_type_values to admit invoice.voided (VoidInvoice).';
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
                'invoice.voided',
                'stock_movement.adjusted',
                'role.assigned',
                'role.revoked'
            )) NOT VALID
            SQL,
            self::CONSTRAINT,
        ));

        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::CONSTRAINT));
    }

    /** The fifteen-member list `Version20260928000700` installed. */
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
                'invoice.payment_reversed',
                'stock_movement.adjusted',
                'role.assigned',
                'role.revoked'
            ))
            SQL,
            self::CONSTRAINT,
        ));
    }
}
