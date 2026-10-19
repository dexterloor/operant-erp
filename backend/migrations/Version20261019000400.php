<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Customers slice 2 C2 — **step 5 of `AuditEvent`'s extension process for `customer_contact.deleted`**, minted with its
 * writer (`CustomerContactWriter::delete()` → `CustomerContactAuditWriter::recordDeleted()`; owner Q-2 "yes",
 * `docs/session13/s35-ruling.md`; filed BUSINESS with `{"customer_id"}` by `SEC`, `customers-s2-sec-arch.md` S-4). One
 * closed CHECK re-issued:
 *  - `audit_log_event_type_values` — the live constraint's forty-seven members read off `pg_constraint` immediately before
 *    this file was written (2026-10-19, 92 migrations, after `Version20261019000300`), plus the one new member.
 *    **It supersedes `Version20261019000200`** (C1, `customer_contact.created` / `.updated`), the last re-issue (SEC S-4:
 *    every re-issue names its base). The superset rule is `AuditEventCatalogueTest`'s (`RESIDUAL_GAPS.md` G-17).
 *
 * `audit_log_ip_address_scope` is **not** touched (step 6 does not apply).
 *
 * The list is written out, never generated from `AuditEvent`. `down()` re-issues the previous list
 * (`Version20261019000200`'s), never a bare `DROP`.
 */
final class Version20261019000400 extends AbstractMigration
{
    private const CONSTRAINT = 'audit_log_event_type_values';

    /** See `Version20260919000400`: without this the `NOT VALID` / `VALIDATE` split buys nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Customers slice 2 C2: admit customer_contact.deleted to the event catalogue.';
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
                'role.assignment_cancelled',
                'credit_note.return_decided',
                'role.permissions_changed',
                'role.updated',
                'role.deleted',
                'customer.created',
                'customer.updated',
                'supplier.created',
                'supplier.updated',
                'customer_address.created',
                'customer_address.updated',
                'warehouse.created',
                'warehouse.updated',
                'storage_location.created',
                'storage_location.updated',
                'supplier.restored',
                'supplier.deleted',
                'customer.restored',
                'customer.deleted',
                'customer_contact.created',
                'customer_contact.updated',
                'customer_contact.deleted'
            )) NOT VALID
            SQL,
            self::CONSTRAINT,
        ));
        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::CONSTRAINT));
    }

    /** The previous list: `Version20261019000200`'s forty-seven events. */
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
                'role.assignment_lapsed',
                'role.assignment_rejected',
                'role.assignment_cancelled',
                'credit_note.return_decided',
                'role.permissions_changed',
                'role.updated',
                'role.deleted',
                'customer.created',
                'customer.updated',
                'supplier.created',
                'supplier.updated',
                'customer_address.created',
                'customer_address.updated',
                'warehouse.created',
                'warehouse.updated',
                'storage_location.created',
                'storage_location.updated',
                'supplier.restored',
                'supplier.deleted',
                'customer.restored',
                'customer.deleted',
                'customer_contact.created',
                'customer_contact.updated'
            )) NOT VALID
            SQL,
            self::CONSTRAINT,
        ));
        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::CONSTRAINT));
    }
}
