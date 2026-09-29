<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Step 5 of `AuditEvent`'s extension process for the five B-4a carries** — roles slice 2, C1 (2026-09-29):
 * `goods_receipt.created`, `sales_order.confirmed`, `fulfilment.created`, `sales_order.deleted`,
 * `purchase_order.deleted`. `SEC` filed all five **`BUSINESS`**, subject the document, `metadata` `{}`
 * (`docs/session13/roles-s2-sec.md` §3), before any code existed.
 *
 * **A copy of `Version20260929000100`'s shape, exactly**: `DROP … IF EXISTS`, `ADD … NOT VALID`, `VALIDATE`,
 * `isTransactional(): false`. **It stands alone**: C1 adds no column and no grant — `erp_app` already holds `INSERT` on
 * `audit_log` (measured with `has_table_privilege()` before and after this commit).
 *
 * **The literal list is the LIVE constraint's sixteen members, read off `pg_get_constraintdef()` on 2026-09-29 (equal
 * to `Version20260929000100`'s `up()` list, `invoice.voided` included), ∪ the five** — hand-written, never generated
 * from `AuditEvent::ALL`. `AuditEventCatalogueTest::theNewestCatalogueReissueIsTheCodeCatalogueAndASupersetOfEveryEarlierOne`
 * holds it monotone; this is the "second catalogue migration to land" the B-4a overlap note warned about, and it is a
 * superset of the invoice track's.
 *
 * **Not authorised here:** `audit_log_ip_address_scope` (no security event is minted); any grant; any other member.
 * **`down()` re-issues the sixteen-member list** `Version20260929000100` installed.
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::GOODS_RECEIPT_CREATED
 * @see \App\Tests\Tenancy\AuditEventCatalogueTest
 */
final class Version20260929000200 extends AbstractMigration
{
    private const CONSTRAINT = 'audit_log_event_type_values';

    /** See `Version20260919000400`: without this the `NOT VALID` / `VALIDATE` split buys nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Roles slice 2 C1 (B-4a): widen audit_log_event_type_values to admit the five B-4a business events.';
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
                'role.revoked',
                'goods_receipt.created',
                'sales_order.confirmed',
                'fulfilment.created',
                'sales_order.deleted',
                'purchase_order.deleted'
            )) NOT VALID
            SQL,
            self::CONSTRAINT,
        ));

        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::CONSTRAINT));
    }

    /** The sixteen-member list `Version20260929000100` installed. */
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
                'invoice.voided',
                'stock_movement.adjusted',
                'role.assigned',
                'role.revoked'
            ))
            SQL,
            self::CONSTRAINT,
        ));
    }
}
