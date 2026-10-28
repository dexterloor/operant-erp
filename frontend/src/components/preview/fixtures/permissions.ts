export const FIXTURE_ONLY = '__FIXTURE_ONLY__'; // BUILT_IN_ROLE_NAMES: owner, admin, buyer, purchasing_manager, warehouse_operator, sales_rep, finance, viewer
// ^ Line 1 is load-bearing: the marker string is grepped for in `.next/static` after `next build` (RED L-2: it must
// never ship), and the role-name list is read by test/architecture.test.ts's literal backstop (RED H-5 item 4, L-1),
// copied from `backend/src/Modules/UserManagement/Domain/DefaultRoles.php:57-64`.
//
// THE ONE FIXTURE COPY (RED L-1) for the permission checklist, for the dev-only component preview and for tests. Props
// only: no fetch interception, no fake endpoint, so not "mock API data". Facts true of the served API today:
//  - `PERMISSIONS` is what `ListPermissions` serves at `e2180dd`: the 64 seeded rows (`code, module, description` from
//    the `permission` seed migrations, `Version20260918000100` + `…20261001000300/000700/000800`), in the reader's
//    `ORDER BY code` (`PermissionReader.php:54`), each with `sod_rows` as `PermissionView` derives them from
//    `SeparationOfDuty::ROWS` (`SeparationOfDuty.php:66-90`). Nothing in `src` reads this list's size.
//  - Built-in roles: `owner` holds the whole catalogue (`DefaultRoles.php:231`), `purchasing_manager` its listed codes
//    (`DefaultRoles.php:91-110`) — the two built-ins that hold both halves of a split (round-6 §2b). `margin_exposure` is
//    set as `MarginExposure::of()` answers for each code set (owner holds every view code, so false).
//  - Refusal sentences quoted from `backend/src/Modules/UserManagement/Application/RoleUpdate.php` / `RoleCreation.php`
//    with file:line; the client keeps no copy of them.
// Custom roles, their names and ids are neutral placeholders (G-37). Only `components/preview` and `test/` may import
// this (architecture.test.ts).

import type { Permission } from '@/lib/api/permissions';
import type { Role } from '@/lib/api/roles';
import type { Refused } from '@/lib/results';

/** `ListPermissions`' items, as served. */
export const PERMISSIONS: Permission[] = [
  { code: 'ai_assistant.use', module: 'Modules\\Ai', description: 'ai assistant: use', sod_rows: [] },
  { code: 'app_user.create', module: 'Modules\\UserManagement', description: 'app user: create', sod_rows: [] },
  { code: 'app_user.delete', module: 'Modules\\UserManagement', description: 'app user: delete', sod_rows: [] },
  { code: 'app_user.edit', module: 'Modules\\UserManagement', description: 'app user: edit', sod_rows: [] },
  { code: 'app_user.view', module: 'Modules\\UserManagement', description: 'app user: view', sod_rows: [] },
  { code: 'audit_log.view', module: 'Modules\\AuditLog', description: 'audit log: view', sod_rows: [] },
  { code: 'audit_log.view_record', module: 'Modules\\AuditLog', description: 'audit log: view record', sod_rows: [] },
  { code: 'credit_note.adjust', module: 'Modules\\Sales', description: 'credit note: adjust', sod_rows: [{ id: 'SoD-3', half: 'controlled' }] },
  { code: 'credit_note.issue', module: 'Modules\\Sales', description: 'credit note: issue', sod_rows: [{ id: 'SoD-3', half: 'controlled' }, { id: 'SoD-5', half: 'controlled' }] },
  { code: 'customer.create', module: 'Modules\\Customers', description: 'customer: create', sod_rows: [] },
  { code: 'customer.delete', module: 'Modules\\Customers', description: 'customer: delete', sod_rows: [] },
  { code: 'customer.edit', module: 'Modules\\Customers', description: 'customer: edit', sod_rows: [] },
  { code: 'customer.view', module: 'Modules\\Customers', description: 'customer: view', sod_rows: [] },
  { code: 'forecast.view', module: 'Modules\\Analytics', description: 'forecast: view', sod_rows: [] },
  { code: 'fulfilment.create', module: 'Modules\\Sales', description: 'fulfilment: create', sod_rows: [{ id: 'SoD-4', half: 'controlled' }] },
  { code: 'fulfilment.view', module: 'Modules\\Sales', description: 'fulfilment: view', sod_rows: [] },
  { code: 'goods_receipt.create', module: 'Modules\\Purchasing', description: 'goods receipt: create', sod_rows: [{ id: 'SoD-2', half: 'controlled' }] },
  { code: 'goods_receipt.view', module: 'Modules\\Purchasing', description: 'goods receipt: view', sod_rows: [] },
  { code: 'inventory_item.create', module: 'Modules\\Inventory', description: 'inventory item: create', sod_rows: [] },
  { code: 'inventory_item.delete', module: 'Modules\\Inventory', description: 'inventory item: delete', sod_rows: [] },
  { code: 'inventory_item.edit', module: 'Modules\\Inventory', description: 'inventory item: edit', sod_rows: [] },
  { code: 'inventory_item.view', module: 'Modules\\Inventory', description: 'inventory item: view', sod_rows: [] },
  { code: 'invoice.issue', module: 'Modules\\Sales', description: 'invoice: issue', sod_rows: [{ id: 'SoD-3', half: 'preparing' }, { id: 'SoD-4', half: 'preparing' }] },
  { code: 'invoice.pay', module: 'Modules\\Sales', description: 'invoice: pay', sod_rows: [{ id: 'SoD-3', half: 'controlled' }] },
  { code: 'invoice.view', module: 'Modules\\Sales', description: 'invoice: view', sod_rows: [] },
  { code: 'invoice.void', module: 'Modules\\Sales', description: 'invoice: void', sod_rows: [{ id: 'SoD-3', half: 'controlled' }] },
  { code: 'purchase_order.approve', module: 'Modules\\Purchasing', description: 'purchase order: approve', sod_rows: [{ id: 'SoD-1', half: 'controlled' }, { id: 'SoD-2', half: 'preparing' }] },
  { code: 'purchase_order.cancel', module: 'Modules\\Purchasing', description: 'purchase order: cancel', sod_rows: [{ id: 'SoD-1', half: 'controlled' }] },
  { code: 'purchase_order.create', module: 'Modules\\Purchasing', description: 'purchase order: create', sod_rows: [{ id: 'SoD-1', half: 'preparing' }, { id: 'SoD-2', half: 'preparing' }] },
  { code: 'purchase_order.delete', module: 'Modules\\Purchasing', description: 'purchase order: delete', sod_rows: [] },
  { code: 'purchase_order.edit', module: 'Modules\\Purchasing', description: 'purchase order: edit', sod_rows: [] },
  { code: 'purchase_order.view', module: 'Modules\\Purchasing', description: 'purchase order: view', sod_rows: [] },
  { code: 'role.assign', module: 'Modules\\UserManagement', description: 'role: assign', sod_rows: [] },
  { code: 'role.create', module: 'Modules\\UserManagement', description: 'role: create', sod_rows: [] },
  { code: 'role.delete', module: 'Modules\\UserManagement', description: 'role: delete', sod_rows: [] },
  { code: 'role.edit', module: 'Modules\\UserManagement', description: 'role: edit', sod_rows: [] },
  { code: 'role.view', module: 'Modules\\UserManagement', description: 'role: view', sod_rows: [] },
  { code: 'sales_order.cancel', module: 'Modules\\Sales', description: 'sales order: cancel', sod_rows: [] },
  { code: 'sales_order.confirm', module: 'Modules\\Sales', description: 'sales order: confirm', sod_rows: [{ id: 'SoD-4', half: 'preparing' }] },
  { code: 'sales_order.create', module: 'Modules\\Sales', description: 'sales order: create', sod_rows: [] },
  { code: 'sales_order.delete', module: 'Modules\\Sales', description: 'sales order: delete', sod_rows: [] },
  { code: 'sales_order.edit', module: 'Modules\\Sales', description: 'sales order: edit', sod_rows: [] },
  { code: 'sales_order.view', module: 'Modules\\Sales', description: 'sales order: view', sod_rows: [] },
  { code: 'sales_return.create', module: 'Modules\\Sales', description: 'sales return: create', sod_rows: [{ id: 'SoD-5', half: 'preparing' }] },
  { code: 'sales_return.view', module: 'Modules\\Sales', description: 'sales return: view', sod_rows: [] },
  { code: 'stock_level.view', module: 'Modules\\Inventory', description: 'stock level: view', sod_rows: [] },
  { code: 'stock_movement.adjust', module: 'Modules\\Inventory', description: 'stock movement: adjust', sod_rows: [] },
  { code: 'stock_movement.view', module: 'Modules\\Inventory', description: 'stock movement: view', sod_rows: [] },
  { code: 'storage_location.create', module: 'Modules\\Warehouses', description: 'storage location: create', sod_rows: [] },
  { code: 'storage_location.delete', module: 'Modules\\Warehouses', description: 'storage location: delete', sod_rows: [] },
  { code: 'storage_location.edit', module: 'Modules\\Warehouses', description: 'storage location: edit', sod_rows: [] },
  { code: 'storage_location.view', module: 'Modules\\Warehouses', description: 'storage location: view', sod_rows: [] },
  { code: 'supplier.create', module: 'Modules\\Suppliers', description: 'supplier: create', sod_rows: [] },
  { code: 'supplier.delete', module: 'Modules\\Suppliers', description: 'supplier: delete', sod_rows: [] },
  { code: 'supplier.edit', module: 'Modules\\Suppliers', description: 'supplier: edit', sod_rows: [] },
  { code: 'supplier.view', module: 'Modules\\Suppliers', description: 'supplier: view', sod_rows: [] },
  { code: 'supplier_product.edit', module: 'Modules\\Suppliers', description: 'supplier product: edit', sod_rows: [] },
  { code: 'supplier_product.view', module: 'Modules\\Suppliers', description: 'supplier product: view', sod_rows: [] },
  { code: 'tenant_settings.edit', module: 'Platform\\Tenancy', description: 'tenant settings: edit', sod_rows: [] },
  { code: 'tenant_settings.view', module: 'Platform\\Tenancy', description: 'tenant settings: view', sod_rows: [] },
  { code: 'warehouse.create', module: 'Modules\\Warehouses', description: 'warehouse: create', sod_rows: [] },
  { code: 'warehouse.delete', module: 'Modules\\Warehouses', description: 'warehouse: delete', sod_rows: [] },
  { code: 'warehouse.edit', module: 'Modules\\Warehouses', description: 'warehouse: edit', sod_rows: [] },
  { code: 'warehouse.view', module: 'Modules\\Warehouses', description: 'warehouse: view', sod_rows: [] },
];

/**
 * NOT SERVED — an illustrative code the client has no label for, so the preview and tests can show the fallback row
 * (round-6 §2b: "draw one such row"; R4-1: the row shows only the server's `description`). Never in `PERMISSIONS`.
 */
export const UNLABELLED_PERMISSION: Permission = {
  code: 'example_report.view',
  module: 'Modules\\Analytics',
  description: 'example report: view',
  sod_rows: [],
};

const role = (n: number, fields: Omit<Role, 'id' | 'holder_count' | 'archived_at'> & Partial<Pick<Role, 'holder_count'>>): Role => ({
  id: `40000000-0000-4000-8000-${String(n).padStart(12, '0')}`,
  holder_count: 0,
  archived_at: null,
  ...fields,
});

/**
 * A custom role being edited. It holds the preparing half of SoD-1/SoD-2 (`purchase_order.create`) and of SoD-3/SoD-4
 * (`invoice.issue`), so the controlled boxes of those rows load refused with their reason lines.
 */
export const CUSTOM_ROLE: Role = role(1, {
  name: 'Sample role',
  description: 'Custom role. Raises purchase orders and issues invoices.',
  permission_codes: ['inventory_item.view', 'invoice.issue', 'invoice.view', 'purchase_order.create', 'purchase_order.view', 'supplier.view'],
  is_system: false,
  margin_exposure: false,
});

/** A custom role whose served `margin_exposure` is true (a cost source + a sale source, not every view code). */
export const MARGIN_ROLE: Role = role(2, {
  name: 'Sample analyst',
  description: 'Custom role. Reads purchase orders and sales orders.',
  permission_codes: ['forecast.view', 'invoice.view', 'purchase_order.view', 'sales_order.view', 'supplier.view', 'supplier_product.view'],
  is_system: false,
  margin_exposure: true,
});

/** `DefaultRoles.php:231`: every code. Holds both halves of all five rows. */
export const OWNER_ROLE: Role = role(3, {
  name: 'owner',
  description: 'Built-in role.',
  permission_codes: PERMISSIONS.map((p) => p.code),
  is_system: true,
  holder_count: 1,
  margin_exposure: false,
});

/** `DefaultRoles.php:91-110`: holds both halves of SoD-1 (`purchase_order.create` + `purchase_order.approve`). */
export const PURCHASING_MANAGER_ROLE: Role = role(4, {
  name: 'purchasing_manager',
  description: 'Built-in role.',
  permission_codes: [
    'ai_assistant.use', 'audit_log.view_record', 'forecast.view', 'goods_receipt.view', 'inventory_item.view', 'purchase_order.approve',
    'purchase_order.create', 'purchase_order.delete', 'purchase_order.edit', 'purchase_order.view', 'stock_level.view', 'supplier.create',
    'supplier.delete', 'supplier.edit', 'supplier.view', 'supplier_product.edit', 'supplier_product.view', 'warehouse.view',
  ],
  is_system: true,
  holder_count: 1,
  margin_exposure: false,
});

/** Save refusals quoted from shipped code (`rule` + `message` as `ApiProblem::businessRuleViolated` serves them). */
export const SAVE_REFUSALS: Refused[] = [
  {
    kind: 'refused',
    rule: 'role.missing_companion_view',
    // `RoleCreation.php:88` (`MISSING_COMPANION`) with `COMPANION_CLAUSES` `stock_movement.adjust` (`RoleCreation.php:106`).
    message: "Some permissions in this role only work alongside others it doesn't have: counting stock needs seeing stock levels. Add the missing ones, or leave out the ones that need them.",
  },
  {
    kind: 'refused',
    rule: 'role.exceeds_actor_permissions',
    // `RoleUpdate.php:91` (`EXCEEDS`).
    message: "Some of the permissions you're adding are ones you don't have yourself, and you can only add permissions you hold. Leave those out, or ask an owner to add them.",
  },
  {
    kind: 'refused',
    rule: 'role.marker_needs_owner',
    // `RoleUpdate.php:88` (`MARKER_NEEDS_OWNER`): a non-owner adding `tenant_settings.edit`, refused after the click.
    message: 'Only an owner can change this role, because it makes, or would make, the people given it owners.',
  },
];
