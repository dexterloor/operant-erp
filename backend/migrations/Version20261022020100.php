<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G7b `UpdateSettings` (2026-10-22) — **step 5 of `AuditEvent`'s extension process for `tenant_settings.updated`**, minted
 * with its writer (`SettingsWriter::rename()` → `SettingsAuditWriter`; filed BUSINESS under PC-11 (4a), subject
 * `('tenant_settings', id)`, `{"fields": ["display_name"], "to": …}` — `docs/session13/g3-g7b-reorder-settings-design.md`
 * Revision 1 FINAL R1.2 M-5; `SEC` to confirm). One closed CHECK re-issued (`Version20261022010000`'s shape):
 *  - `audit_log_event_type_values` — the live constraint's sixty-one members (read off `pg_constraint` on the main database
 *    at `2807982`, 118 migrations, by a script, immediately before writing: the same set as `Version20261022010000`'s up
 *    list, kept in its order), plus the one new member: sixty-two. **It supersedes `Version20261022010000`** (SEC S-2:
 *    every re-issue names its base).
 * **Step 6 does not apply** (BUSINESS): `audit_log_ip_address_scope` is unchanged. The superset rule is
 * `AuditEventCatalogueTest`'s (`RESIDUAL_GAPS.md` G-17). **No grant here:** `erp_app` already holds `SELECT, INSERT` on
 * `audit_log`; the `tenant_settings` column UPDATE that makes the writer's `FOR NO KEY UPDATE` legal is
 * `Version20261022020000`'s.
 *
 * The list is written out, never generated from `AuditEvent`. `down()` re-issues the previous list, never a bare `DROP`.
 */
final class Version20261022020100 extends AbstractMigration
{
    private const CONSTRAINT = 'audit_log_event_type_values';

    /** See `Version20260919000400`: without this the `NOT VALID` / `VALIDATE` split buys nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'G7b: admit tenant_settings.updated to the event catalogue.';
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
                'tenant_settings.updated'
            )) NOT VALID
            SQL);
    }

    /** The previous list: `Version20261022010000`'s sixty-one events. */
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
                'inventory_item.restored'
            )) NOT VALID
            SQL);
    }

    private function reissue(string $addConstraint): void
    {
        $this->addSql(sprintf('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS %s', self::CONSTRAINT));
        // Written out, not generated from App\SharedKernel\ValueObject\AuditEvent.
        $this->addSql(sprintf($addConstraint, self::CONSTRAINT));
        $this->addSql(sprintf('ALTER TABLE audit_log VALIDATE CONSTRAINT %s', self::CONSTRAINT));
    }
}
