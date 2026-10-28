// GENERATED from ../contracts/ by scripts/build-api-surface.mjs. Do not edit by hand: run `npm run surface`. `npm run surface:check` (part of `npm run check`) fails on any difference.
// Client projection: read by src/lib/bff/surface.ts and nothing else (G-2). Imports nothing. Holds only the operations
// listed in scripts/api-surface.client-ops.json; no sign-in or reset (auth) template reaches the browser.

export type SurfacePermission = { readonly kind: 'all_of'; readonly codes: readonly string[] } | { readonly kind: 'none' } | { readonly kind: 'derived' };

export interface SurfaceOperation {
  readonly method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  readonly template: string;
  /** Path variable names in template order; each is a record id (contracts pathParameters.format). */
  readonly pathParams: readonly string[];
  readonly idempotencyKeyRequired: boolean;
  readonly permission: SurfacePermission;
}

export interface BulkResource {
  readonly list: string;
  readonly delete: string;
  readonly restore: string;
  /** The one code the bulk gate checks for this resource. */
  readonly permission: string;
}

/** The wired operations only. */
export const CLIENT_OPERATIONS: Readonly<Record<string, SurfaceOperation>> = {
  DeleteUser: {
    method: 'DELETE',
    template: '/api/v1/users/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['app_user.delete'],
    },
  },
  GetUser: {
    method: 'GET',
    template: '/api/v1/users/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['app_user.view'],
    },
  },
  ListUsers: {
    method: 'GET',
    template: '/api/v1/users',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['app_user.view'],
    },
  },
  RestoreUser: {
    method: 'POST',
    template: '/api/v1/users/{id}/restoration',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['app_user.delete'],
    },
  },
};

/** The resources that can be archived or restored in bulk. */
export const BULK: Readonly<Record<string, BulkResource>> = {
  customer: {
    list: 'ListCustomers',
    delete: 'DeleteCustomer',
    restore: 'RestoreCustomer',
    permission: 'customer.delete',
  },
  inventory_item: {
    list: 'ListItems',
    delete: 'DeleteItem',
    restore: 'RestoreItem',
    permission: 'inventory_item.delete',
  },
  purchase_order: {
    list: 'ListPurchaseOrders',
    delete: 'DeletePurchaseOrder',
    restore: 'RestorePurchaseOrder',
    permission: 'purchase_order.delete',
  },
  sales_order: {
    list: 'ListSalesOrders',
    delete: 'DeleteSalesOrder',
    restore: 'RestoreSalesOrder',
    permission: 'sales_order.delete',
  },
  storage_location: {
    list: 'ListStorageLocations',
    delete: 'DeleteStorageLocation',
    restore: 'RestoreStorageLocation',
    permission: 'storage_location.delete',
  },
  supplier: {
    list: 'ListSuppliers',
    delete: 'DeleteSupplier',
    restore: 'RestoreSupplier',
    permission: 'supplier.delete',
  },
  warehouse: {
    list: 'ListWarehouses',
    delete: 'DeleteWarehouse',
    restore: 'RestoreWarehouse',
    permission: 'warehouse.delete',
  },
};

/** The most ids one bulk request may carry. */
export const BULK_MAX_IDS: number = 50;

/** Sorted served property names of each schema a hand-written lib/api type mirrors (checked by test C-1). */
export const WIRE_FIELDS: Readonly<Record<string, readonly string[]>> = {
  Collection: ['items', 'next_cursor', 'previous_cursor', 'tenant_has_rows'],
  OfferedRole: ['conflicts_with', 'id', 'margin_exposure_with', 'name', 'refusal', 'requires_owner_confirmation'],
  Permission: ['code', 'description', 'module', 'sod_rows'],
  Role: ['archived_at', 'description', 'holder_count', 'id', 'is_system', 'margin_exposure', 'name', 'permission_codes'],
  RoleConflict: ['role', 'sod_row'],
  RoleGrantProposal: ['decided_at', 'decided_by', 'expires_at', 'id', 'is_expired', 'lapse_reason', 'permission_codes', 'proposed_at', 'proposed_by', 'reason', 'role', 'status', 'user'],
  RoleReference: ['id', 'name'],
  SodRowRef: ['half', 'id'],
  User: ['archived_at', 'display_name', 'email', 'id', 'is_active', 'margin_exposure', 'roles'],
  UserDisplayReference: ['display_name', 'id'],
};
