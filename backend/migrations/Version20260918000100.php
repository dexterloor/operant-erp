<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Output 16 deliverable A — seed the global `permission` catalogue: `SECURITY_MODEL.md`
 * §3's 60 codes, as reference data.
 *
 * ---------------------------------------------------------------------------
 * Why a migration is the right place, and why only for this table
 * ---------------------------------------------------------------------------
 * `permission` is **global reference data** and not tenant data: `Version20260906000100`
 * gives it `code text` as its whole primary key, **no `tenant_id`**, and therefore no
 * row-level security and no `FORCE`. The same file grants `SELECT` on it to `erp_app`
 * and `erp_ai` under the comment *"Reference data (ADR-002 §6) is owner-maintained"*,
 * and migrations run as the owner (`doctrine_migrations.yaml`, `connection: owner`).
 * So this seed needs no tenant context, no re-grant, no `SchemaManifest` change and no
 * movement of `GrantFootprintTest`'s pin. The `currency`, `country` and
 * `unit_of_measure` seeds in that same migration are the precedent.
 *
 * **None of that carries over to `role`, `role_permission` or `user_role`.** Those are
 * tenant-scoped, uuid-keyed and under `FORCE ROW LEVEL SECURITY`, and on this schema
 * `erp_app` holds `SELECT` only on them. Seeding a role is a different problem with a
 * different set of answers; it is not this file.
 *
 * ---------------------------------------------------------------------------
 * The rows are written out literally, and are NOT generated from `PermissionCatalogue`
 * ---------------------------------------------------------------------------
 * `App\Modules\UserManagement\Domain\PermissionCatalogue` holds the same 60 codes in
 * running code, and this file deliberately does not import it — the same decision, for
 * the same reason, as `Version20260907000200` and `AuditEvent::ALL`.
 *
 * A migration is frozen history. A migration that derived its rows from a class would
 * mean different things on different days: replayed after the class changed, it would
 * write today's catalogue while claiming to be the 2026-09-18 snapshot, and every
 * database that had already run it would disagree with every database that had not.
 * Worse, it would make the reconciliation vacuous. `PermissionCatalogueSeedTest` reads
 * these rows back out of the server and compares them against `PermissionCatalogue`; if
 * the rows were *written from* `PermissionCatalogue`, that test would compare the
 * catalogue to itself through one extra indirection — this project's fake-green shape,
 * one level deeper and harder to see. **A source that cannot drift cannot be
 * reconciled**, and reconciliation is the only thing that test does.
 *
 * Extending the catalogue is therefore a multi-step operation: add the code to §3, add
 * it to `PermissionCatalogue`, and write a **further forward** migration. Editing the
 * list below in place is invisible to every database that has already run it.
 *
 * ---------------------------------------------------------------------------
 * `module` — namespace-shaped, and this is SEC-O-11's live half
 * ---------------------------------------------------------------------------
 * PC-1a makes §3's Module column the `permission.module` value verbatim:
 * `Modules\Purchasing`, `Platform\Tenancy` — capitalised, never bare and never
 * lowercase. `Permission.php`'s `$module` docblock documents exactly that form, and
 * `ROADMAP.md`'s M1 entry criterion for SEC-O-11 fails if *"`permission` rows exist
 * while `Permission.php` documents a format the seeder did not use"*. This is the
 * commit that makes rows exist, so it is the commit that criterion is aimed at.
 *
 * **The domain is §3's eleven modules, and it is not `SchemaManifest`'s.** The manifest
 * carries 36 tables over 13 distinct module values; §3 has eleven, and two of them —
 * `Modules\Analytics` and `Modules\Ai` — own no table at all and appear nowhere in the
 * manifest. A check written against the manifest would fire on `forecast.view` and
 * `ai_assistant.use`, which are correct rows. `PermissionCatalogueSeedTest` takes its
 * domain from `PermissionCatalogue`, which is §3's copy.
 *
 * **In SQL below, `'Modules\Inventory'` is one backslash.** The strings sit in a PHP
 * *nowdoc*, where a backslash is literal, and this server runs with
 * `standard_conforming_strings = on` (measured), where a backslash inside a single-quoted
 * SQL literal is also literal. Doubling it here would seed `Modules\\Inventory` and every
 * comparison against PC-1a's form would fail. In `PermissionCatalogue`, which is ordinary
 * PHP, the same value is spelled `'Modules\\Inventory'` — that doubling is the PHP escape,
 * not part of the value.
 *
 * ---------------------------------------------------------------------------
 * `description` — derived mechanically, on purpose, and reversible
 * ---------------------------------------------------------------------------
 * `permission.description` is `text NOT NULL` and **no source carries descriptions**:
 * §3 publishes a Module column and a Permissions column and nothing else, and nothing
 * in `docs/` or in `backend/src/` defines one. Rather than invent sixty sentences —
 * which would become seeded data that costs a data migration to correct, which is
 * precisely the argument SEC-O-11 is built on — each description is derived from the
 * code by one rule:
 *
 *     description = resource with `_` replaced by ` `, then `": "`, then verb with
 *                   `_` replaced by ` `
 *
 *     `inventory_item.view`     -> `inventory item: view`
 *     `audit_log.view_record`   -> `audit log: view record`
 *     `ai_assistant.use`        -> `ai assistant: use`
 *
 * The rule is **lossless**: no resource and no verb contains a space, so splitting the
 * description on `": "` and replacing ` ` with `_` reconstructs the code exactly.
 * `PermissionCatalogueSeedTest` asserts that round trip over every seeded row, so a
 * description that stopped describing its own code fails rather than sitting there.
 * When a human-authored description is wanted, it is a forward migration and a decision
 * someone signs for — not a guess frozen by this commit.
 *
 * ---------------------------------------------------------------------------
 * Locks and re-runs (postgres-database-migration)
 * ---------------------------------------------------------------------------
 * No DDL here. `INSERT … ON CONFLICT` takes `RowExclusiveLock` on `permission`, which
 * does not block readers and does not rewrite the table; 60 rows, one statement.
 *
 * The upsert is not decoration. This is a **seed into a table that is already
 * non-empty in every long-lived local database**: three test fixtures
 * (`ApiTestCase::seedPrincipal`, `CurrentUserTest::seedPermission`,
 * `PermissionEnforcementTest`) insert the codes they need with a placeholder `module`,
 * because until this commit the migrations left the table empty. Measured on the
 * development database before this file was written: 13 such rows, twelve with
 * `module = 'Fixture'` and one with `module = 'supplier'`. A bare `INSERT` would have
 * failed those databases with 23505 on the first colliding code. `ON CONFLICT (code) DO
 * UPDATE` both makes this migration idempotent and states the ownership: the catalogue
 * is what this file says it is, and a fixture's placeholder loses to it.
 *
 * `down()` deletes exactly these 60 codes. `role_permission.permission_code` is a
 * foreign key with no `ON DELETE` clause, so the delete is `NO ACTION` and **fails**
 * while any role still grants one of them. That is the correct outcome, not a defect to
 * route around: reversing the catalogue out from under live grants is a data loss the
 * database should refuse.
 *
 * @see \App\Modules\UserManagement\Domain\PermissionCatalogue for the runtime copy and how to extend it
 * @see \App\Tests\Tenancy\PermissionCatalogueSeedTest for the reconciliation
 */
final class Version20260918000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Output 16 deliverable A: seed the 60-code permission catalogue (SECURITY_MODEL.md section 3).';
    }

    public function up(Schema $schema): void
    {
        // Written out, not generated from PermissionCatalogue. See the docblock.
        // Kept in section 3's order, grouped by module, so a diff of the two sides is
        // readable; the reconciliation sorts both, so order is not load-bearing.
        $this->addSql(<<<'SQL'
            INSERT INTO permission (code, module, description) VALUES
                ('inventory_item.view', 'Modules\Inventory', 'inventory item: view'),
                ('inventory_item.create', 'Modules\Inventory', 'inventory item: create'),
                ('inventory_item.edit', 'Modules\Inventory', 'inventory item: edit'),
                ('inventory_item.delete', 'Modules\Inventory', 'inventory item: delete'),
                ('stock_level.view', 'Modules\Inventory', 'stock level: view'),
                ('stock_movement.view', 'Modules\Inventory', 'stock movement: view'),
                ('stock_movement.adjust', 'Modules\Inventory', 'stock movement: adjust'),

                ('warehouse.view', 'Modules\Warehouses', 'warehouse: view'),
                ('warehouse.create', 'Modules\Warehouses', 'warehouse: create'),
                ('warehouse.edit', 'Modules\Warehouses', 'warehouse: edit'),
                ('warehouse.delete', 'Modules\Warehouses', 'warehouse: delete'),
                ('storage_location.view', 'Modules\Warehouses', 'storage location: view'),
                ('storage_location.create', 'Modules\Warehouses', 'storage location: create'),
                ('storage_location.edit', 'Modules\Warehouses', 'storage location: edit'),
                ('storage_location.delete', 'Modules\Warehouses', 'storage location: delete'),

                ('supplier.view', 'Modules\Suppliers', 'supplier: view'),
                ('supplier.create', 'Modules\Suppliers', 'supplier: create'),
                ('supplier.edit', 'Modules\Suppliers', 'supplier: edit'),
                ('supplier.delete', 'Modules\Suppliers', 'supplier: delete'),
                ('supplier_product.view', 'Modules\Suppliers', 'supplier product: view'),
                ('supplier_product.edit', 'Modules\Suppliers', 'supplier product: edit'),

                ('purchase_order.view', 'Modules\Purchasing', 'purchase order: view'),
                ('purchase_order.create', 'Modules\Purchasing', 'purchase order: create'),
                ('purchase_order.edit', 'Modules\Purchasing', 'purchase order: edit'),
                ('purchase_order.delete', 'Modules\Purchasing', 'purchase order: delete'),
                ('purchase_order.approve', 'Modules\Purchasing', 'purchase order: approve'),
                ('purchase_order.cancel', 'Modules\Purchasing', 'purchase order: cancel'),
                ('goods_receipt.view', 'Modules\Purchasing', 'goods receipt: view'),
                ('goods_receipt.create', 'Modules\Purchasing', 'goods receipt: create'),

                ('customer.view', 'Modules\Customers', 'customer: view'),
                ('customer.create', 'Modules\Customers', 'customer: create'),
                ('customer.edit', 'Modules\Customers', 'customer: edit'),
                ('customer.delete', 'Modules\Customers', 'customer: delete'),

                ('sales_order.view', 'Modules\Sales', 'sales order: view'),
                ('sales_order.create', 'Modules\Sales', 'sales order: create'),
                ('sales_order.edit', 'Modules\Sales', 'sales order: edit'),
                ('sales_order.delete', 'Modules\Sales', 'sales order: delete'),
                ('sales_order.confirm', 'Modules\Sales', 'sales order: confirm'),
                ('sales_order.cancel', 'Modules\Sales', 'sales order: cancel'),
                ('fulfilment.view', 'Modules\Sales', 'fulfilment: view'),
                ('fulfilment.create', 'Modules\Sales', 'fulfilment: create'),
                ('invoice.view', 'Modules\Sales', 'invoice: view'),
                ('invoice.issue', 'Modules\Sales', 'invoice: issue'),
                ('invoice.pay', 'Modules\Sales', 'invoice: pay'),
                ('invoice.void', 'Modules\Sales', 'invoice: void'),

                ('app_user.view', 'Modules\UserManagement', 'app user: view'),
                ('app_user.create', 'Modules\UserManagement', 'app user: create'),
                ('app_user.edit', 'Modules\UserManagement', 'app user: edit'),
                ('app_user.delete', 'Modules\UserManagement', 'app user: delete'),
                ('role.view', 'Modules\UserManagement', 'role: view'),
                ('role.create', 'Modules\UserManagement', 'role: create'),
                ('role.edit', 'Modules\UserManagement', 'role: edit'),
                ('role.delete', 'Modules\UserManagement', 'role: delete'),
                ('role.assign', 'Modules\UserManagement', 'role: assign'),

                ('audit_log.view', 'Modules\AuditLog', 'audit log: view'),
                ('audit_log.view_record', 'Modules\AuditLog', 'audit log: view record'),

                ('forecast.view', 'Modules\Analytics', 'forecast: view'),

                ('ai_assistant.use', 'Modules\Ai', 'ai assistant: use'),

                ('tenant_settings.view', 'Platform\Tenancy', 'tenant settings: view'),
                ('tenant_settings.edit', 'Platform\Tenancy', 'tenant settings: edit')
            ON CONFLICT (code) DO UPDATE
                SET module = EXCLUDED.module, description = EXCLUDED.description
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Fails while any role_permission row still references one of these codes,
        // which is what the foreign key is for. See the docblock.
        $this->addSql(<<<'SQL'
            DELETE FROM permission WHERE code IN (
                'inventory_item.view', 'inventory_item.create', 'inventory_item.edit', 'inventory_item.delete', 'stock_level.view', 'stock_movement.view', 'stock_movement.adjust',
                'warehouse.view', 'warehouse.create', 'warehouse.edit', 'warehouse.delete', 'storage_location.view', 'storage_location.create', 'storage_location.edit', 'storage_location.delete',
                'supplier.view', 'supplier.create', 'supplier.edit', 'supplier.delete', 'supplier_product.view', 'supplier_product.edit',
                'purchase_order.view', 'purchase_order.create', 'purchase_order.edit', 'purchase_order.delete', 'purchase_order.approve', 'purchase_order.cancel', 'goods_receipt.view', 'goods_receipt.create',
                'customer.view', 'customer.create', 'customer.edit', 'customer.delete',
                'sales_order.view', 'sales_order.create', 'sales_order.edit', 'sales_order.delete', 'sales_order.confirm', 'sales_order.cancel', 'fulfilment.view', 'fulfilment.create', 'invoice.view', 'invoice.issue', 'invoice.pay', 'invoice.void',
                'app_user.view', 'app_user.create', 'app_user.edit', 'app_user.delete', 'role.view', 'role.create', 'role.edit', 'role.delete', 'role.assign',
                'audit_log.view', 'audit_log.view_record',
                'forecast.view',
                'ai_assistant.use',
                'tenant_settings.view', 'tenant_settings.edit'
            )
            SQL);
    }
}
