<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Mail-C1 "Let anyone ask for a password link without learning who has an account" — the audit catalogue and the IP
 * scope for the mail worker's two security events** (`docs/session13/mail-c1-build-plan.md` Revision 1 FINAL R1.2 M-B):
 * `auth.password_reset.issued` (a reset link was minted and mailed) and `auth.credential.identity_mismatch` (a reset was
 * asked for an account whose stored email no longer matches its seal). Both SECURITY, subjectless, IP-bearing (the request
 * IP the message carried), written by `erp_worker` (the restrictive policy of `Version20261023000800`).
 *  - `audit_log_event_type_values` — the live constraint's 68 members (read off `pg_constraint` on the main database
 *    immediately before writing: the same set and order as `Version20261023000700`'s up list), plus the two: 70.
 *    **It supersedes `Version20261023000700`** (SEC S-2: every re-issue names its base).
 *  - `audit_log_ip_address_scope` — the live 22 security members (the same set as `Version20261023000700`'s IP up list),
 *    plus the two: 24. **It supersedes `Version20261023000700`'s IP list.**
 * The superset rule is `AuditEventCatalogueTest`'s (`RESIDUAL_GAPS.md` G-17). Both lists are written out, never generated
 * from `AuditEvent`. `down()` re-issues the previous lists, never a bare `DROP`.
 */
final class Version20261023000900 extends AbstractMigration
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
        return 'Mail-C1: add auth.password_reset.issued and auth.credential.identity_mismatch to the audit catalogue and the IP scope (68 to 70, 22 to 24)';
    }

    public function up(Schema $schema): void
    {
        $this->reissue(<<<'SQL'
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
                'customer_contact.deleted',
                'customer_address.deleted',
                'warehouse.restored',
                'storage_location.restored',
                'storage_location.deleted',
                'warehouse.deleted',
                'sales_order.restored',
                'purchase_order.restored',
                'role.restored',
                'supplier_product.created',
                'supplier_product.updated',
                'supplier_product.deleted',
                'inventory_item.deleted',
                'inventory_item.restored',
                'tenant_settings.updated',
                'user.deactivated',
                'user.reactivated',
                'user.updated',
                'user.deleted',
                'user.restored',
                'auth.login.credential_mismatch',
                'auth.password_reset.issued',
                'auth.credential.identity_mismatch'
            )) NOT VALID
            SQL, <<<'SQL'
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
                'role.assignment_cancelled',
                'role.permissions_changed',
                'role.updated',
                'role.deleted',
                'role.restored',
                'user.deactivated',
                'user.reactivated',
                'user.updated',
                'user.deleted',
                'user.restored',
                'auth.login.credential_mismatch',
                'auth.password_reset.issued',
                'auth.credential.identity_mismatch'
            )) NOT VALID
            SQL);
    }

    /** The previous lists: `Version20261023000700`'s 68 events and 22 IP members. */
    public function down(Schema $schema): void
    {
        $this->reissue(<<<'SQL'
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
                'customer_contact.deleted',
                'customer_address.deleted',
                'warehouse.restored',
                'storage_location.restored',
                'storage_location.deleted',
                'warehouse.deleted',
                'sales_order.restored',
                'purchase_order.restored',
                'role.restored',
                'supplier_product.created',
                'supplier_product.updated',
                'supplier_product.deleted',
                'inventory_item.deleted',
                'inventory_item.restored',
                'tenant_settings.updated',
                'user.deactivated',
                'user.reactivated',
                'user.updated',
                'user.deleted',
                'user.restored',
                'auth.login.credential_mismatch'
            )) NOT VALID
            SQL, <<<'SQL'
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
                'role.assignment_cancelled',
                'role.permissions_changed',
                'role.updated',
                'role.deleted',
                'role.restored',
                'user.deactivated',
                'user.reactivated',
                'user.updated',
                'user.deleted',
                'user.restored',
                'auth.login.credential_mismatch'
            )) NOT VALID
            SQL);
    }

    private function reissue(string $events, string $ipEvents): void
    {
        $this->addSql(sprintf('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS %s', self::CONSTRAINT));
        // Written out, not generated from App\SharedKernel\ValueObject\AuditEvent.
        $this->addSql(sprintf($events, self::CONSTRAINT));
        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::CONSTRAINT));

        $this->addSql(sprintf('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS %s', self::IP_CONSTRAINT));
        // Written out, not generated from App\SharedKernel\ValueObject\AuditEvent::SECURITY.
        $this->addSql(sprintf($ipEvents, self::IP_CONSTRAINT));
        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::IP_CONSTRAINT));
    }
}
