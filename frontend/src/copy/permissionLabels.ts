// Copy for the permission checklist (§5.15), word for word. Sources, later wins:
//  - docs/design/permission-labels.md Revision 2 > Revision 1 > body: one label per code, the module headings and their
//    order, the three help lines (also round-6 §2b "Labels (final)" / "Help lines", which match it);
//  - ux-copy-frontend.md Revision 3 item 6 (the group checkbox's "Left out" line), R4-1 "Checklist page" (counts, group
//    checkbox name, Built-in, dirty bar), R4-4 L-7 (the built-in line every box is described by);
//  - round-6 §2b (the built-in line; the margin warning, from SEC F-6 via permission-labels.md Revision 1 rule 4);
//  - b4-owed-copy.md Revision 2 (FINAL) > Revision 1: R1-3 (interrupted save), R1-5 (403, fixed label).
// The SoD sentence and its clauses are `copy/sod.ts` (one shared copy). A server refusal sentence is NEVER stored here:
// the checklist renders the result's `message`. No total is typed here: `{total}` counts what `ListPermissions` serves.
//
// This is the label map: the only place in `src` where permission codes are written (architecture.test.ts scans the
// B-4 lib and component dirs, not `copy/`). A code with no entry shows the server's `description` (permission-labels.md
// "How this list was derived"; R1-7 fallbacks), never nothing.

/**
 * Sidebar order (permission-labels.md rule 3); the served `module` value → its heading. An unknown module shows as
 * served.
 */
export const MODULE_HEADINGS: ReadonlyArray<{ module: string; heading: string }> = [
  { module: 'Modules\\Inventory', heading: 'Inventory' },
  { module: 'Modules\\Warehouses', heading: 'Warehouses' },
  { module: 'Modules\\Suppliers', heading: 'Suppliers' },
  { module: 'Modules\\Purchasing', heading: 'Purchasing' },
  { module: 'Modules\\Customers', heading: 'Customers' },
  { module: 'Modules\\Sales', heading: 'Sales' },
  { module: 'Modules\\UserManagement', heading: 'People and roles' },
  { module: 'Modules\\AuditLog', heading: 'Audit log' },
  { module: 'Modules\\Analytics', heading: 'Forecasting' },
  { module: 'Modules\\Ai', heading: 'Assistant' },
  { module: 'Platform\\Tenancy', heading: 'Workspace' },
];

export interface PermissionLabel {
  label: string;
  /** Always shown under the box (round-6 §2b "Help lines"). */
  help?: string;
}

/** permission-labels.md "The labels", in its table order (which is also each group's display order). */
export const PERMISSION_LABELS: Readonly<Record<string, PermissionLabel>> = {
  'inventory_item.view': { label: 'View items' },
  'inventory_item.create': { label: 'Create items' },
  'inventory_item.edit': { label: 'Edit items' },
  'inventory_item.delete': { label: 'Delete and restore items' },
  'stock_level.view': { label: 'View stock on hand' },
  'stock_movement.view': { label: 'View stock movements' },
  'stock_movement.adjust': { label: 'Correct stock levels by a count, and move stock between locations' },
  'warehouse.view': { label: 'View warehouses' },
  'warehouse.create': { label: 'Create warehouses' },
  'warehouse.edit': { label: 'Edit warehouses' },
  'warehouse.delete': { label: 'Delete and restore warehouses' },
  'storage_location.view': { label: 'View locations' },
  'storage_location.create': { label: 'Create locations' },
  'storage_location.edit': { label: 'Edit locations' },
  'storage_location.delete': { label: 'Delete and restore locations' },
  'supplier.view': { label: 'View suppliers' },
  'supplier.create': { label: 'Create suppliers' },
  'supplier.edit': { label: 'Edit suppliers' },
  'supplier.delete': { label: 'Delete and restore suppliers' },
  'supplier_product.view': { label: 'View supplier catalogues, including what goods cost' },
  'supplier_product.edit': { label: 'Edit supplier catalogues' },
  'purchase_order.view': { label: 'View purchase orders, including what goods cost' },
  'purchase_order.create': { label: 'Create purchase orders' },
  'purchase_order.edit': { label: 'Edit draft purchase orders' },
  'purchase_order.delete': { label: 'Delete and restore draft purchase orders' },
  'purchase_order.approve': { label: 'Approve and place purchase orders' },
  'purchase_order.cancel': { label: 'Cancel purchase orders' },
  'goods_receipt.view': { label: 'View goods receipts' },
  'goods_receipt.create': { label: 'Receive deliveries' },
  'customer.view': { label: 'View customers' },
  'customer.create': { label: 'Create customers' },
  'customer.edit': { label: 'Edit customers, their addresses and contacts' },
  'customer.delete': { label: 'Delete and restore customers' },
  'sales_order.view': { label: 'View sales orders' },
  'sales_order.create': { label: 'Create sales orders' },
  'sales_order.edit': { label: 'Edit draft sales orders' },
  'sales_order.delete': { label: 'Delete and restore draft sales orders' },
  'sales_order.confirm': { label: 'Confirm sales orders' },
  'sales_order.cancel': { label: 'Cancel sales orders' },
  'fulfilment.view': { label: 'View fulfilments and pick lists' },
  'fulfilment.create': { label: 'Pick and ship orders' },
  'invoice.view': { label: 'View invoices and credit notes' },
  'invoice.issue': { label: 'Issue invoices' },
  'invoice.pay': { label: 'Record and reverse payments' },
  'invoice.void': { label: 'Void invoices' },
  'sales_return.view': { label: 'View sales returns' },
  'sales_return.create': { label: 'Record sales returns' },
  'credit_note.issue': { label: 'Issue credit notes for returns' },
  'credit_note.adjust': { label: 'Issue credit notes without a return', help: 'Gives money back to a customer when no goods come back.' },
  'app_user.view': { label: 'View people' },
  'app_user.create': { label: 'Add people' },
  'app_user.edit': { label: 'Rename people and switch their accounts off and on (switching off takes away all their roles)' },
  'app_user.delete': { label: 'Delete and restore people (deleting takes away all their roles and signs them out)' },
  'role.view': { label: 'View roles and their permissions' },
  'role.create': { label: 'Create roles' },
  'role.edit': { label: 'Edit roles' },
  'role.delete': { label: 'Delete and restore roles' },
  'role.assign': { label: 'Give and take away roles', help: 'Lets them give any built-in role except owner, even one with more access than they have. A role with owner-level permissions takes effect only once an owner confirms it.' },
  'audit_log.view': { label: 'View the whole audit log, including sign-in attempts, refused actions and IP addresses' },
  'audit_log.view_record': { label: 'See the history of records they can view, including deleted ones, and who made each change' },
  'forecast.view': { label: 'View the reorder forecast' },
  'ai_assistant.use': { label: 'Use the assistant' },
  'tenant_settings.view': { label: 'View workspace settings' },
  'tenant_settings.edit': { label: 'Edit workspace settings, and count as an owner', help: "Anyone with this counts as an owner. Wherever they also have the permission for an action, they get an owner's exceptions: for example confirming role proposals, approving their own purchase orders, or releasing held stock. Only an owner can give, change or restore a role with this, and only an owner can switch off or delete someone who has it." },
};

/** R4-1 "Checklist page" + round-6 §2b + R4-4 L-7, L-10. */
export const CHECKLIST = {
  /** R4-1: `{total}` is the served catalogue's size, never typed. */
  headerCount: (n: number, total: number) => `${n} of ${total} permissions`,
  groupCount: (n: number, m: number) => `${n} of ${m}`,
  /** R4-1: the group checkbox's accessible name ("All Sales permissions, checkbox, mixed"). */
  groupCheckbox: (group: string) => `All ${group} permissions`,
  builtInChip: 'Built-in',
  /** Round-6 §2b; every box and group box on a built-in role is described by it (R4-4 L-7). */
  builtInLine: "Built-in role. It can't be changed or deleted.",
  /** R4-1 dirty bar (handoff L-4); the singular is NEW. */
  changes: { one: () => '1 change', other: (n: number) => `${n} changes` },
  discard: 'Discard',
  save: 'Save changes',
  /** Rev 3 item 6 (NEW): lists only the boxes the group rule left out; `labels` is already a sentence list. */
  leftOut: (labels: string) => `Left out: ${labels}, because each would complete a separation-of-duty split.`,
  /** SEC F-6 / round-6 §2b: a warning banner, never a refusal — only from the served `margin_exposure` (L-10). */
  margin: 'People with this role can see both what goods cost and what they sell for, so they can work out your margins.',
} as const;

/**
 * PROPOSED (b4-owed-copy.md Rev 2 R1-3), checklist row: lost connection and 5xx alike, under the R1-1 heading. The
 * dirty bar and its count stay.
 */
export const CHECKLIST_INTERRUPTED = {
  body: "Your changes may not have been saved. They're still here: press Save changes to send them again.",
} as const;

/**
 * PROPOSED (b4-owed-copy.md Rev 2 R1-5), checklist row (heading Not saved), split so the permission's name renders in
 * `<em>` (ST-4b's form).
 */
export const CHECKLIST_FORBIDDEN = {
  before: 'This needs the ',
  label: 'Edit roles',
  after: ' permission, which you no longer have. Your workspace administrator can grant it.',
} as const;
