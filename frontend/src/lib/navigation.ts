// The sidebar's declared map (review L-5; ruling item 9): each item → the operation behind its screen → that
// operation's permission code, read from docs/API.md §4.2 and docs/ux/IA.md §2/§3. The sidebar is derived from the
// caller's permission codes through this map only — never from role names, never from per-role lists.
//
// IA §2: groups Overview · Purchasing · Inventory · Sales · Administration, at most two levels. A sub-item renders only
// if the user holds its `.view` code; a group renders only if one of its sub-items does (brief §3). Labels are in
// sentence case (Q-7, ruling item 6). Routes are IA §3's.
//
// Two items have no List operation, so the map names the Get that backs the screen instead:
//   Overview → GetReorderSummary (forecast.view; IA §2's "Overview → forecast.view")
//   Settings → GetSettings (tenant_settings.view)

export type NavIconName =
  | 'layout-dashboard'
  | 'file-text'
  | 'package-check'
  | 'truck'
  | 'box'
  | 'boxes'
  | 'arrow-left-right'
  | 'warehouse'
  | 'receipt'
  | 'package-open'
  | 'file-badge'
  | 'building-2'
  | 'users'
  | 'shield'
  | 'scroll-text'
  | 'settings';

export interface NavItemDef {
  readonly label: string;
  readonly href: string;
  readonly icon: NavIconName;
  /** The §4.2 operation behind the screen (a List, except Overview and Settings). */
  readonly operation: string;
  /** That operation's permission code in §4.2. */
  readonly permission: string;
}

export interface NavGroupDef {
  /** null for Overview, which is a single screen rendered at the top level. */
  readonly label: string | null;
  readonly items: readonly NavItemDef[];
}

export const NAVIGATION: readonly NavGroupDef[] = [
  {
    label: null,
    items: [{ label: 'Overview', href: '/overview', icon: 'layout-dashboard', operation: 'GetReorderSummary', permission: 'forecast.view' }],
  },
  {
    label: 'Purchasing',
    items: [
      { label: 'Purchase orders', href: '/purchase-orders', icon: 'file-text', operation: 'ListPurchaseOrders', permission: 'purchase_order.view' },
      { label: 'Goods receipts', href: '/goods-receipts', icon: 'package-check', operation: 'ListGoodsReceipts', permission: 'goods_receipt.view' },
      { label: 'Suppliers', href: '/suppliers', icon: 'truck', operation: 'ListSuppliers', permission: 'supplier.view' },
    ],
  },
  {
    label: 'Inventory',
    items: [
      { label: 'Items', href: '/items', icon: 'box', operation: 'ListItems', permission: 'inventory_item.view' },
      { label: 'Stock on hand', href: '/stock', icon: 'boxes', operation: 'ListStockLevels', permission: 'stock_level.view' },
      { label: 'Stock movements', href: '/stock-movements', icon: 'arrow-left-right', operation: 'ListStockMovements', permission: 'stock_movement.view' },
      { label: 'Warehouses', href: '/warehouses', icon: 'warehouse', operation: 'ListWarehouses', permission: 'warehouse.view' },
    ],
  },
  {
    label: 'Sales',
    items: [
      { label: 'Sales orders', href: '/sales-orders', icon: 'receipt', operation: 'ListSalesOrders', permission: 'sales_order.view' },
      { label: 'Fulfilments', href: '/fulfilments', icon: 'package-open', operation: 'ListFulfilments', permission: 'fulfilment.view' },
      { label: 'Invoices', href: '/invoices', icon: 'file-badge', operation: 'ListInvoices', permission: 'invoice.view' },
      { label: 'Customers', href: '/customers', icon: 'building-2', operation: 'ListCustomers', permission: 'customer.view' },
    ],
  },
  {
    label: 'Administration',
    items: [
      { label: 'Users', href: '/admin/users', icon: 'users', operation: 'ListUsers', permission: 'app_user.view' },
      { label: 'Roles', href: '/admin/roles', icon: 'shield', operation: 'ListRoles', permission: 'role.view' },
      { label: 'Audit log', href: '/admin/audit-log', icon: 'scroll-text', operation: 'ListAuditLogEntries', permission: 'audit_log.view' },
      { label: 'Settings', href: '/admin/settings', icon: 'settings', operation: 'GetSettings', permission: 'tenant_settings.view' },
    ],
  },
];

export type PermissionCodes = ReadonlySet<string> | readonly string[];

const asSet = (codes: PermissionCodes): ReadonlySet<string> => (codes instanceof Set ? codes : new Set(codes as readonly string[]));

/** The groups and items this person may see. Groups with no visible item are absent, never greyed. */
export function deriveNavigation(codes: PermissionCodes, nav: readonly NavGroupDef[] = NAVIGATION): NavGroupDef[] {
  const held = asSet(codes);
  return nav
    .map((g) => ({ label: g.label, items: g.items.filter((i) => held.has(i.permission)) }))
    .filter((g) => g.items.length > 0);
}

/** The item whose route is the current path or an ancestor of it (`/purchase-orders/42` → Purchase orders). */
export function activeHref(pathname: string, nav: readonly NavGroupDef[] = NAVIGATION): string | null {
  let best: string | null = null;
  for (const g of nav) {
    for (const i of g.items) {
      if ((pathname === i.href || pathname.startsWith(`${i.href}/`)) && (best === null || i.href.length > best.length)) best = i.href;
    }
  }
  return best;
}
