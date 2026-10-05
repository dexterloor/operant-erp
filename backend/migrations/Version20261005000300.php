<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Roles slice 3 C3 — **steps 5 and 6 of `AuditEvent`'s extension process for `role.assignment_rejected` and
 * `role.assignment_cancelled`**, each minted with its writer (`RejectRoleGrantProposal`, `CancelRoleGrantProposal`).
 * Security events (`SECURITY_MODEL.md` §5.2.8), so this file re-issues TWO closed CHECKs (`roles-s3-design.md` §2 F-2):
 *  - `audit_log_event_type_values` — the live constraint's twenty-five members read off `pg_constraint` immediately
 *    before this file was written (2026-10-05, 55 migrations), plus the two new members;
 *  - `audit_log_ip_address_scope` — the live ten security members (`Version20261004000200`), plus the two new members.
 *
 * Both lists are written out, never generated from `AuditEvent`. `down()` re-issues `Version20261004000200`'s lists,
 * never a bare `DROP`.
 */
final class Version20261005000300 extends AbstractMigration
{
    private const CONSTRAINT = 'audit_log_event_type_values';

    private const IP_CONSTRAINT = 'audit_log_ip_address_scope';

    /** See `Version20260919000400`: without this the `NOT VALID` / `VALIDATE` split buys nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Roles slice 3 C3: admit role.assignment_rejected and role.assignment_cancelled to the event catalogue and the IP scope.';
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
                'purchase_order.deleted',
                'sales_return.created',
                'credit_note.issued',
                'role.assignment_proposed',
                'role.assignment_lapsed',
                'role.assignment_rejected',
                'role.assignment_cancelled'
            )) NOT VALID
            SQL,
            self::CONSTRAINT,
        ));
        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::CONSTRAINT));

        $this->addSql(sprintf('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS %s', self::IP_CONSTRAINT));
        // Written out, not generated from App\SharedKernel\ValueObject\AuditEvent::SECURITY.
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
                'role.revoked',
                'role.assignment_proposed',
                'role.assignment_lapsed',
                'role.assignment_rejected',
                'role.assignment_cancelled'
            )) NOT VALID
            SQL,
            self::IP_CONSTRAINT,
        ));
        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::IP_CONSTRAINT));
    }

    /** `Version20261004000200`'s twenty-five-member list and ten-member scope. */
    public function down(Schema $schema): void
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
                'purchase_order.deleted',
                'sales_return.created',
                'credit_note.issued',
                'role.assignment_proposed',
                'role.assignment_lapsed'
            )) NOT VALID
            SQL,
            self::CONSTRAINT,
        ));
        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::CONSTRAINT));

        $this->addSql(sprintf('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS %s', self::IP_CONSTRAINT));
        // Written out, not generated from App\SharedKernel\ValueObject\AuditEvent::SECURITY.
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
                'role.revoked',
                'role.assignment_proposed',
                'role.assignment_lapsed'
            )) NOT VALID
            SQL,
            self::IP_CONSTRAINT,
        ));
        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::IP_CONSTRAINT));
    }
}
