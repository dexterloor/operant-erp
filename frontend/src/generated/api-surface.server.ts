// GENERATED from ../contracts/ by scripts/build-api-surface.mjs. Do not edit by hand: run `npm run surface`. `npm run surface:check` (part of `npm run check`) fails on any difference.
// Server only: read by src/lib/bff/server/surface.ts and nothing else (G-2). Imports nothing.

export type SurfacePermission = { readonly kind: 'all_of'; readonly codes: readonly string[] } | { readonly kind: 'none' } | { readonly kind: 'derived' };

export interface SurfaceOperation {
  readonly method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  readonly template: string;
  /** Path variable names in template order; each is a record id (contracts pathParameters.format). */
  readonly pathParams: readonly string[];
  readonly idempotencyKeyRequired: boolean;
  readonly permission: SurfacePermission;
}

export interface ProxyRoute {
  readonly method: SurfaceOperation['method'];
  readonly template: string;
}

/** Every operation in the contract, /api/v1/auth/* included: apiUrl() validates against this. */
export const OPERATIONS: Readonly<Record<string, SurfaceOperation>> = {
  ApprovePurchaseOrder: {
    method: 'POST',
    template: '/api/v1/purchase-orders/{id}/approval',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['purchase_order.approve'],
    },
  },
  AssignRole: {
    method: 'POST',
    template: '/api/v1/users/{id}/roles',
    pathParams: ['id'],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['role.assign'],
    },
  },
  CancelPurchaseOrder: {
    method: 'POST',
    template: '/api/v1/purchase-orders/{id}/cancellation',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['purchase_order.cancel'],
    },
  },
  CancelRoleGrantProposal: {
    method: 'POST',
    template: '/api/v1/role-grant-proposals/{id}/cancellation',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['role.assign'],
    },
  },
  CancelSalesOrder: {
    method: 'POST',
    template: '/api/v1/sales-orders/{id}/cancellation',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['sales_order.cancel'],
    },
  },
  ChangePassword: {
    method: 'POST',
    template: '/api/v1/me/password',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'none',
    },
  },
  ConfirmRoleGrantProposal: {
    method: 'POST',
    template: '/api/v1/role-grant-proposals/{id}/confirmation',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['role.assign'],
    },
  },
  ConfirmSalesOrder: {
    method: 'POST',
    template: '/api/v1/sales-orders/{id}/confirmation',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['sales_order.confirm'],
    },
  },
  CreateAdjustmentDecision: {
    method: 'POST',
    template: '/api/v1/sales-returns/{id}/adjustment-decisions',
    pathParams: ['id'],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['credit_note.issue'],
    },
  },
  CreateBulkArchival: {
    method: 'POST',
    template: '/api/v1/bulk/archivals',
    pathParams: [],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'derived',
    },
  },
  CreateBulkRestoration: {
    method: 'POST',
    template: '/api/v1/bulk/restorations',
    pathParams: [],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'derived',
    },
  },
  CreateBulkRoleAssignment: {
    method: 'POST',
    template: '/api/v1/bulk/role-assignments',
    pathParams: [],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['role.assign'],
    },
  },
  CreateCustomer: {
    method: 'POST',
    template: '/api/v1/customers',
    pathParams: [],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['customer.create'],
    },
  },
  CreateCustomerAddress: {
    method: 'POST',
    template: '/api/v1/customers/{id}/addresses',
    pathParams: ['id'],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['customer.edit'],
    },
  },
  CreateCustomerContact: {
    method: 'POST',
    template: '/api/v1/customers/{id}/contacts',
    pathParams: ['id'],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['customer.edit'],
    },
  },
  CreateExport: {
    method: 'POST',
    template: '/api/v1/exports',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'derived',
    },
  },
  CreateFulfilment: {
    method: 'POST',
    template: '/api/v1/sales-orders/{id}/fulfilments',
    pathParams: ['id'],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['fulfilment.create'],
    },
  },
  CreateGoodsReceipt: {
    method: 'POST',
    template: '/api/v1/purchase-orders/{id}/goods-receipts',
    pathParams: ['id'],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['goods_receipt.create'],
    },
  },
  CreateItem: {
    method: 'POST',
    template: '/api/v1/items',
    pathParams: [],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['inventory_item.create'],
    },
  },
  CreatePasswordReset: {
    method: 'POST',
    template: '/api/v1/auth/password-resets',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'none',
    },
  },
  CreatePasswordResetRedemption: {
    method: 'POST',
    template: '/api/v1/auth/password-resets/redemption',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'none',
    },
  },
  CreatePurchaseOrder: {
    method: 'POST',
    template: '/api/v1/purchase-orders',
    pathParams: [],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['purchase_order.create'],
    },
  },
  CreatePurchaseOrderLine: {
    method: 'POST',
    template: '/api/v1/purchase-orders/{id}/lines',
    pathParams: ['id'],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['purchase_order.edit'],
    },
  },
  CreateRole: {
    method: 'POST',
    template: '/api/v1/roles',
    pathParams: [],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['role.create'],
    },
  },
  CreateRoleGrantProposal: {
    method: 'POST',
    template: '/api/v1/users/{id}/role-grant-proposals',
    pathParams: ['id'],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['role.assign'],
    },
  },
  CreateSalesOrder: {
    method: 'POST',
    template: '/api/v1/sales-orders',
    pathParams: [],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['sales_order.create'],
    },
  },
  CreateSalesOrderLine: {
    method: 'POST',
    template: '/api/v1/sales-orders/{id}/lines',
    pathParams: ['id'],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['sales_order.edit'],
    },
  },
  CreateSalesReturn: {
    method: 'POST',
    template: '/api/v1/fulfilments/{id}/sales-returns',
    pathParams: ['id'],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['sales_return.create'],
    },
  },
  CreateStockAdjustment: {
    method: 'POST',
    template: '/api/v1/stock-movements/adjustments',
    pathParams: [],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['stock_movement.adjust'],
    },
  },
  CreateStockTransfer: {
    method: 'POST',
    template: '/api/v1/stock-movements/transfers',
    pathParams: [],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['stock_movement.adjust'],
    },
  },
  CreateStorageLocation: {
    method: 'POST',
    template: '/api/v1/warehouses/{id}/locations',
    pathParams: ['id'],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['storage_location.create'],
    },
  },
  CreateSupplier: {
    method: 'POST',
    template: '/api/v1/suppliers',
    pathParams: [],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['supplier.create'],
    },
  },
  CreateSupplierProduct: {
    method: 'POST',
    template: '/api/v1/suppliers/{id}/products',
    pathParams: ['id'],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['supplier_product.edit'],
    },
  },
  CreateUser: {
    method: 'POST',
    template: '/api/v1/users',
    pathParams: [],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['app_user.create'],
    },
  },
  CreateWarehouse: {
    method: 'POST',
    template: '/api/v1/warehouses',
    pathParams: [],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['warehouse.create'],
    },
  },
  DeleteCustomer: {
    method: 'DELETE',
    template: '/api/v1/customers/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['customer.delete'],
    },
  },
  DeleteCustomerAddress: {
    method: 'DELETE',
    template: '/api/v1/customers/{id}/addresses/{addressId}',
    pathParams: ['id', 'addressId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['customer.edit'],
    },
  },
  DeleteCustomerContact: {
    method: 'DELETE',
    template: '/api/v1/customers/{id}/contacts/{contactId}',
    pathParams: ['id', 'contactId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['customer.edit'],
    },
  },
  DeleteItem: {
    method: 'DELETE',
    template: '/api/v1/items/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['inventory_item.delete'],
    },
  },
  DeletePurchaseOrder: {
    method: 'DELETE',
    template: '/api/v1/purchase-orders/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['purchase_order.delete'],
    },
  },
  DeletePurchaseOrderLine: {
    method: 'DELETE',
    template: '/api/v1/purchase-orders/{id}/lines/{lineId}',
    pathParams: ['id', 'lineId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['purchase_order.edit'],
    },
  },
  DeleteRole: {
    method: 'DELETE',
    template: '/api/v1/roles/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['role.delete'],
    },
  },
  DeleteSalesOrder: {
    method: 'DELETE',
    template: '/api/v1/sales-orders/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['sales_order.delete'],
    },
  },
  DeleteSalesOrderLine: {
    method: 'DELETE',
    template: '/api/v1/sales-orders/{id}/lines/{lineId}',
    pathParams: ['id', 'lineId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['sales_order.edit'],
    },
  },
  DeleteStorageLocation: {
    method: 'DELETE',
    template: '/api/v1/warehouses/{id}/locations/{locationId}',
    pathParams: ['id', 'locationId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['storage_location.delete'],
    },
  },
  DeleteSupplier: {
    method: 'DELETE',
    template: '/api/v1/suppliers/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['supplier.delete'],
    },
  },
  DeleteSupplierProduct: {
    method: 'DELETE',
    template: '/api/v1/suppliers/{id}/products/{productId}',
    pathParams: ['id', 'productId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['supplier_product.edit'],
    },
  },
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
  DeleteWarehouse: {
    method: 'DELETE',
    template: '/api/v1/warehouses/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['warehouse.delete'],
    },
  },
  GetCreditNote: {
    method: 'GET',
    template: '/api/v1/credit-notes/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['invoice.view'],
    },
  },
  GetCurrentUser: {
    method: 'GET',
    template: '/api/v1/me',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'none',
    },
  },
  GetCustomer: {
    method: 'GET',
    template: '/api/v1/customers/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['customer.view'],
    },
  },
  GetFulfilment: {
    method: 'GET',
    template: '/api/v1/fulfilments/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['fulfilment.view'],
    },
  },
  GetGoodsReceipt: {
    method: 'GET',
    template: '/api/v1/goods-receipts/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['goods_receipt.view'],
    },
  },
  GetInvoice: {
    method: 'GET',
    template: '/api/v1/invoices/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['invoice.view'],
    },
  },
  GetItem: {
    method: 'GET',
    template: '/api/v1/items/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['inventory_item.view'],
    },
  },
  GetPickList: {
    method: 'GET',
    template: '/api/v1/sales-orders/{id}/pick-list',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['fulfilment.view'],
    },
  },
  GetPurchaseOrder: {
    method: 'GET',
    template: '/api/v1/purchase-orders/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['purchase_order.view'],
    },
  },
  GetReorderSummary: {
    method: 'GET',
    template: '/api/v1/forecast/reorder/summary',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['forecast.view'],
    },
  },
  GetRole: {
    method: 'GET',
    template: '/api/v1/roles/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['role.view'],
    },
  },
  GetRoleGrantProposal: {
    method: 'GET',
    template: '/api/v1/role-grant-proposals/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['role.assign'],
    },
  },
  GetSalesOrder: {
    method: 'GET',
    template: '/api/v1/sales-orders/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['sales_order.view'],
    },
  },
  GetSalesReturn: {
    method: 'GET',
    template: '/api/v1/sales-returns/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['sales_return.view'],
    },
  },
  GetSettings: {
    method: 'GET',
    template: '/api/v1/settings',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['tenant_settings.view'],
    },
  },
  GetStockMovement: {
    method: 'GET',
    template: '/api/v1/stock-movements/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['stock_movement.view'],
    },
  },
  GetSupplier: {
    method: 'GET',
    template: '/api/v1/suppliers/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['supplier.view'],
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
  GetWarehouse: {
    method: 'GET',
    template: '/api/v1/warehouses/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['warehouse.view'],
    },
  },
  IssueAdjustmentCreditNote: {
    method: 'POST',
    template: '/api/v1/invoices/{id}/credit-note-adjustments',
    pathParams: ['id'],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['credit_note.adjust'],
    },
  },
  IssueCreditNote: {
    method: 'POST',
    template: '/api/v1/invoices/{id}/credit-notes',
    pathParams: ['id'],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['credit_note.issue'],
    },
  },
  IssueInvoice: {
    method: 'POST',
    template: '/api/v1/fulfilments/{id}/invoices',
    pathParams: ['id'],
    idempotencyKeyRequired: true,
    permission: {
      kind: 'all_of',
      codes: ['invoice.issue'],
    },
  },
  IssueToken: {
    method: 'POST',
    template: '/api/v1/auth/token',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'none',
    },
  },
  ListAuditLogEntries: {
    method: 'GET',
    template: '/api/v1/audit-log',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['audit_log.view'],
    },
  },
  ListAuditRecords: {
    method: 'GET',
    template: '/api/v1/audit-log/records',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['audit_log.view_record'],
    },
  },
  ListCreditNotes: {
    method: 'GET',
    template: '/api/v1/credit-notes',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['invoice.view'],
    },
  },
  ListCustomerAddresses: {
    method: 'GET',
    template: '/api/v1/customers/{id}/addresses',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['customer.view'],
    },
  },
  ListCustomerContacts: {
    method: 'GET',
    template: '/api/v1/customers/{id}/contacts',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['customer.view'],
    },
  },
  ListCustomers: {
    method: 'GET',
    template: '/api/v1/customers',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['customer.view'],
    },
  },
  ListFulfilments: {
    method: 'GET',
    template: '/api/v1/fulfilments',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['fulfilment.view'],
    },
  },
  ListGoodsReceipts: {
    method: 'GET',
    template: '/api/v1/goods-receipts',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['goods_receipt.view'],
    },
  },
  ListInvoiceLines: {
    method: 'GET',
    template: '/api/v1/invoices/{id}/lines',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['invoice.view'],
    },
  },
  ListInvoices: {
    method: 'GET',
    template: '/api/v1/invoices',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['invoice.view'],
    },
  },
  ListItems: {
    method: 'GET',
    template: '/api/v1/items',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['inventory_item.view'],
    },
  },
  ListOfferedRoles: {
    method: 'GET',
    template: '/api/v1/users/{id}/offered-roles',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['role.assign'],
    },
  },
  ListPermissions: {
    method: 'GET',
    template: '/api/v1/permissions',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['role.view'],
    },
  },
  ListPurchaseOrderLines: {
    method: 'GET',
    template: '/api/v1/purchase-orders/{id}/lines',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['purchase_order.view'],
    },
  },
  ListPurchaseOrders: {
    method: 'GET',
    template: '/api/v1/purchase-orders',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['purchase_order.view'],
    },
  },
  ListReorderSuggestions: {
    method: 'GET',
    template: '/api/v1/forecast/reorder',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['forecast.view', 'inventory_item.view', 'purchase_order.view', 'stock_level.view', 'supplier.view', 'supplier_product.view'],
    },
  },
  ListRoleGrantProposals: {
    method: 'GET',
    template: '/api/v1/role-grant-proposals',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['role.assign'],
    },
  },
  ListRoles: {
    method: 'GET',
    template: '/api/v1/roles',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['role.view'],
    },
  },
  ListSalesOrderLines: {
    method: 'GET',
    template: '/api/v1/sales-orders/{id}/lines',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['sales_order.view'],
    },
  },
  ListSalesOrders: {
    method: 'GET',
    template: '/api/v1/sales-orders',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['sales_order.view'],
    },
  },
  ListSalesReturns: {
    method: 'GET',
    template: '/api/v1/sales-returns',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['sales_return.view'],
    },
  },
  ListStockLevels: {
    method: 'GET',
    template: '/api/v1/stock',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['stock_level.view'],
    },
  },
  ListStockMovements: {
    method: 'GET',
    template: '/api/v1/stock-movements',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['stock_movement.view'],
    },
  },
  ListStorageLocations: {
    method: 'GET',
    template: '/api/v1/warehouses/{id}/locations',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['storage_location.view'],
    },
  },
  ListSupplierProducts: {
    method: 'GET',
    template: '/api/v1/suppliers/{id}/products',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['supplier_product.view'],
    },
  },
  ListSuppliers: {
    method: 'GET',
    template: '/api/v1/suppliers',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['supplier.view'],
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
  ListWarehouses: {
    method: 'GET',
    template: '/api/v1/warehouses',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['warehouse.view'],
    },
  },
  PayInvoice: {
    method: 'POST',
    template: '/api/v1/invoices/{id}/payment',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['invoice.pay'],
    },
  },
  RejectRoleGrantProposal: {
    method: 'POST',
    template: '/api/v1/role-grant-proposals/{id}/rejection',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['role.assign'],
    },
  },
  RestoreCustomer: {
    method: 'POST',
    template: '/api/v1/customers/{id}/restoration',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['customer.delete'],
    },
  },
  RestoreItem: {
    method: 'POST',
    template: '/api/v1/items/{id}/restoration',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['inventory_item.delete'],
    },
  },
  RestorePurchaseOrder: {
    method: 'POST',
    template: '/api/v1/purchase-orders/{id}/restoration',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['purchase_order.delete'],
    },
  },
  RestorePurchaseOrderLine: {
    method: 'POST',
    template: '/api/v1/purchase-orders/{id}/lines/{lineId}/restoration',
    pathParams: ['id', 'lineId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['purchase_order.edit'],
    },
  },
  RestoreRole: {
    method: 'POST',
    template: '/api/v1/roles/{id}/restoration',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['role.delete'],
    },
  },
  RestoreSalesOrder: {
    method: 'POST',
    template: '/api/v1/sales-orders/{id}/restoration',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['sales_order.delete'],
    },
  },
  RestoreSalesOrderLine: {
    method: 'POST',
    template: '/api/v1/sales-orders/{id}/lines/{lineId}/restoration',
    pathParams: ['id', 'lineId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['sales_order.edit'],
    },
  },
  RestoreStorageLocation: {
    method: 'POST',
    template: '/api/v1/warehouses/{id}/locations/{locationId}/restoration',
    pathParams: ['id', 'locationId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['storage_location.delete'],
    },
  },
  RestoreSupplier: {
    method: 'POST',
    template: '/api/v1/suppliers/{id}/restoration',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['supplier.delete'],
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
  RestoreWarehouse: {
    method: 'POST',
    template: '/api/v1/warehouses/{id}/restoration',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['warehouse.delete'],
    },
  },
  ReverseInvoicePayment: {
    method: 'POST',
    template: '/api/v1/invoices/{id}/payment-reversal',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['invoice.pay'],
    },
  },
  RevokeRole: {
    method: 'DELETE',
    template: '/api/v1/users/{id}/roles/{roleId}',
    pathParams: ['id', 'roleId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['role.assign'],
    },
  },
  UpdateCustomer: {
    method: 'PATCH',
    template: '/api/v1/customers/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['customer.edit'],
    },
  },
  UpdateCustomerAddress: {
    method: 'PATCH',
    template: '/api/v1/customers/{id}/addresses/{addressId}',
    pathParams: ['id', 'addressId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['customer.edit'],
    },
  },
  UpdateCustomerContact: {
    method: 'PATCH',
    template: '/api/v1/customers/{id}/contacts/{contactId}',
    pathParams: ['id', 'contactId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['customer.edit'],
    },
  },
  UpdateItem: {
    method: 'PATCH',
    template: '/api/v1/items/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['inventory_item.edit'],
    },
  },
  UpdatePurchaseOrder: {
    method: 'PATCH',
    template: '/api/v1/purchase-orders/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['purchase_order.edit'],
    },
  },
  UpdatePurchaseOrderLine: {
    method: 'PATCH',
    template: '/api/v1/purchase-orders/{id}/lines/{lineId}',
    pathParams: ['id', 'lineId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['purchase_order.edit'],
    },
  },
  UpdateRole: {
    method: 'PATCH',
    template: '/api/v1/roles/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['role.edit'],
    },
  },
  UpdateSalesOrder: {
    method: 'PATCH',
    template: '/api/v1/sales-orders/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['sales_order.edit'],
    },
  },
  UpdateSalesOrderLine: {
    method: 'PATCH',
    template: '/api/v1/sales-orders/{id}/lines/{lineId}',
    pathParams: ['id', 'lineId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['sales_order.edit'],
    },
  },
  UpdateSettings: {
    method: 'PATCH',
    template: '/api/v1/settings',
    pathParams: [],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['tenant_settings.edit'],
    },
  },
  UpdateStorageLocation: {
    method: 'PATCH',
    template: '/api/v1/warehouses/{id}/locations/{locationId}',
    pathParams: ['id', 'locationId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['storage_location.edit'],
    },
  },
  UpdateSupplier: {
    method: 'PATCH',
    template: '/api/v1/suppliers/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['supplier.edit'],
    },
  },
  UpdateSupplierProduct: {
    method: 'PATCH',
    template: '/api/v1/suppliers/{id}/products/{productId}',
    pathParams: ['id', 'productId'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['supplier_product.edit'],
    },
  },
  UpdateUser: {
    method: 'PATCH',
    template: '/api/v1/users/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['app_user.edit'],
    },
  },
  UpdateWarehouse: {
    method: 'PATCH',
    template: '/api/v1/warehouses/{id}',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['warehouse.edit'],
    },
  },
  VoidInvoice: {
    method: 'POST',
    template: '/api/v1/invoices/{id}/void',
    pathParams: ['id'],
    idempotencyKeyRequired: false,
    permission: {
      kind: 'all_of',
      codes: ['invoice.void'],
    },
  },
};

/** Every /api/v1/ operation except /api/v1/auth/* and the ones a named handler owns: what the proxy may forward. */
export const PROXY_ALLOWLIST: readonly ProxyRoute[] = [
  {
    method: 'GET',
    template: '/api/v1/audit-log',
  },
  {
    method: 'GET',
    template: '/api/v1/audit-log/records',
  },
  {
    method: 'POST',
    template: '/api/v1/bulk/archivals',
  },
  {
    method: 'POST',
    template: '/api/v1/bulk/restorations',
  },
  {
    method: 'POST',
    template: '/api/v1/bulk/role-assignments',
  },
  {
    method: 'GET',
    template: '/api/v1/credit-notes',
  },
  {
    method: 'GET',
    template: '/api/v1/credit-notes/{id}',
  },
  {
    method: 'GET',
    template: '/api/v1/customers',
  },
  {
    method: 'POST',
    template: '/api/v1/customers',
  },
  {
    method: 'DELETE',
    template: '/api/v1/customers/{id}',
  },
  {
    method: 'GET',
    template: '/api/v1/customers/{id}',
  },
  {
    method: 'PATCH',
    template: '/api/v1/customers/{id}',
  },
  {
    method: 'GET',
    template: '/api/v1/customers/{id}/addresses',
  },
  {
    method: 'POST',
    template: '/api/v1/customers/{id}/addresses',
  },
  {
    method: 'DELETE',
    template: '/api/v1/customers/{id}/addresses/{addressId}',
  },
  {
    method: 'PATCH',
    template: '/api/v1/customers/{id}/addresses/{addressId}',
  },
  {
    method: 'GET',
    template: '/api/v1/customers/{id}/contacts',
  },
  {
    method: 'POST',
    template: '/api/v1/customers/{id}/contacts',
  },
  {
    method: 'DELETE',
    template: '/api/v1/customers/{id}/contacts/{contactId}',
  },
  {
    method: 'PATCH',
    template: '/api/v1/customers/{id}/contacts/{contactId}',
  },
  {
    method: 'POST',
    template: '/api/v1/customers/{id}/restoration',
  },
  {
    method: 'POST',
    template: '/api/v1/exports',
  },
  {
    method: 'GET',
    template: '/api/v1/forecast/reorder',
  },
  {
    method: 'GET',
    template: '/api/v1/forecast/reorder/summary',
  },
  {
    method: 'GET',
    template: '/api/v1/fulfilments',
  },
  {
    method: 'GET',
    template: '/api/v1/fulfilments/{id}',
  },
  {
    method: 'POST',
    template: '/api/v1/fulfilments/{id}/invoices',
  },
  {
    method: 'POST',
    template: '/api/v1/fulfilments/{id}/sales-returns',
  },
  {
    method: 'GET',
    template: '/api/v1/goods-receipts',
  },
  {
    method: 'GET',
    template: '/api/v1/goods-receipts/{id}',
  },
  {
    method: 'GET',
    template: '/api/v1/invoices',
  },
  {
    method: 'GET',
    template: '/api/v1/invoices/{id}',
  },
  {
    method: 'POST',
    template: '/api/v1/invoices/{id}/credit-note-adjustments',
  },
  {
    method: 'POST',
    template: '/api/v1/invoices/{id}/credit-notes',
  },
  {
    method: 'GET',
    template: '/api/v1/invoices/{id}/lines',
  },
  {
    method: 'POST',
    template: '/api/v1/invoices/{id}/payment',
  },
  {
    method: 'POST',
    template: '/api/v1/invoices/{id}/payment-reversal',
  },
  {
    method: 'POST',
    template: '/api/v1/invoices/{id}/void',
  },
  {
    method: 'GET',
    template: '/api/v1/items',
  },
  {
    method: 'POST',
    template: '/api/v1/items',
  },
  {
    method: 'DELETE',
    template: '/api/v1/items/{id}',
  },
  {
    method: 'GET',
    template: '/api/v1/items/{id}',
  },
  {
    method: 'PATCH',
    template: '/api/v1/items/{id}',
  },
  {
    method: 'POST',
    template: '/api/v1/items/{id}/restoration',
  },
  {
    method: 'GET',
    template: '/api/v1/me',
  },
  {
    method: 'GET',
    template: '/api/v1/permissions',
  },
  {
    method: 'GET',
    template: '/api/v1/purchase-orders',
  },
  {
    method: 'POST',
    template: '/api/v1/purchase-orders',
  },
  {
    method: 'DELETE',
    template: '/api/v1/purchase-orders/{id}',
  },
  {
    method: 'GET',
    template: '/api/v1/purchase-orders/{id}',
  },
  {
    method: 'PATCH',
    template: '/api/v1/purchase-orders/{id}',
  },
  {
    method: 'POST',
    template: '/api/v1/purchase-orders/{id}/approval',
  },
  {
    method: 'POST',
    template: '/api/v1/purchase-orders/{id}/cancellation',
  },
  {
    method: 'POST',
    template: '/api/v1/purchase-orders/{id}/goods-receipts',
  },
  {
    method: 'GET',
    template: '/api/v1/purchase-orders/{id}/lines',
  },
  {
    method: 'POST',
    template: '/api/v1/purchase-orders/{id}/lines',
  },
  {
    method: 'DELETE',
    template: '/api/v1/purchase-orders/{id}/lines/{lineId}',
  },
  {
    method: 'PATCH',
    template: '/api/v1/purchase-orders/{id}/lines/{lineId}',
  },
  {
    method: 'POST',
    template: '/api/v1/purchase-orders/{id}/lines/{lineId}/restoration',
  },
  {
    method: 'POST',
    template: '/api/v1/purchase-orders/{id}/restoration',
  },
  {
    method: 'GET',
    template: '/api/v1/role-grant-proposals',
  },
  {
    method: 'GET',
    template: '/api/v1/role-grant-proposals/{id}',
  },
  {
    method: 'POST',
    template: '/api/v1/role-grant-proposals/{id}/cancellation',
  },
  {
    method: 'POST',
    template: '/api/v1/role-grant-proposals/{id}/confirmation',
  },
  {
    method: 'POST',
    template: '/api/v1/role-grant-proposals/{id}/rejection',
  },
  {
    method: 'GET',
    template: '/api/v1/roles',
  },
  {
    method: 'POST',
    template: '/api/v1/roles',
  },
  {
    method: 'DELETE',
    template: '/api/v1/roles/{id}',
  },
  {
    method: 'GET',
    template: '/api/v1/roles/{id}',
  },
  {
    method: 'PATCH',
    template: '/api/v1/roles/{id}',
  },
  {
    method: 'POST',
    template: '/api/v1/roles/{id}/restoration',
  },
  {
    method: 'GET',
    template: '/api/v1/sales-orders',
  },
  {
    method: 'POST',
    template: '/api/v1/sales-orders',
  },
  {
    method: 'DELETE',
    template: '/api/v1/sales-orders/{id}',
  },
  {
    method: 'GET',
    template: '/api/v1/sales-orders/{id}',
  },
  {
    method: 'PATCH',
    template: '/api/v1/sales-orders/{id}',
  },
  {
    method: 'POST',
    template: '/api/v1/sales-orders/{id}/cancellation',
  },
  {
    method: 'POST',
    template: '/api/v1/sales-orders/{id}/confirmation',
  },
  {
    method: 'POST',
    template: '/api/v1/sales-orders/{id}/fulfilments',
  },
  {
    method: 'GET',
    template: '/api/v1/sales-orders/{id}/lines',
  },
  {
    method: 'POST',
    template: '/api/v1/sales-orders/{id}/lines',
  },
  {
    method: 'DELETE',
    template: '/api/v1/sales-orders/{id}/lines/{lineId}',
  },
  {
    method: 'PATCH',
    template: '/api/v1/sales-orders/{id}/lines/{lineId}',
  },
  {
    method: 'POST',
    template: '/api/v1/sales-orders/{id}/lines/{lineId}/restoration',
  },
  {
    method: 'GET',
    template: '/api/v1/sales-orders/{id}/pick-list',
  },
  {
    method: 'POST',
    template: '/api/v1/sales-orders/{id}/restoration',
  },
  {
    method: 'GET',
    template: '/api/v1/sales-returns',
  },
  {
    method: 'GET',
    template: '/api/v1/sales-returns/{id}',
  },
  {
    method: 'POST',
    template: '/api/v1/sales-returns/{id}/adjustment-decisions',
  },
  {
    method: 'GET',
    template: '/api/v1/settings',
  },
  {
    method: 'PATCH',
    template: '/api/v1/settings',
  },
  {
    method: 'GET',
    template: '/api/v1/stock',
  },
  {
    method: 'GET',
    template: '/api/v1/stock-movements',
  },
  {
    method: 'POST',
    template: '/api/v1/stock-movements/adjustments',
  },
  {
    method: 'POST',
    template: '/api/v1/stock-movements/transfers',
  },
  {
    method: 'GET',
    template: '/api/v1/stock-movements/{id}',
  },
  {
    method: 'GET',
    template: '/api/v1/suppliers',
  },
  {
    method: 'POST',
    template: '/api/v1/suppliers',
  },
  {
    method: 'DELETE',
    template: '/api/v1/suppliers/{id}',
  },
  {
    method: 'GET',
    template: '/api/v1/suppliers/{id}',
  },
  {
    method: 'PATCH',
    template: '/api/v1/suppliers/{id}',
  },
  {
    method: 'GET',
    template: '/api/v1/suppliers/{id}/products',
  },
  {
    method: 'POST',
    template: '/api/v1/suppliers/{id}/products',
  },
  {
    method: 'DELETE',
    template: '/api/v1/suppliers/{id}/products/{productId}',
  },
  {
    method: 'PATCH',
    template: '/api/v1/suppliers/{id}/products/{productId}',
  },
  {
    method: 'POST',
    template: '/api/v1/suppliers/{id}/restoration',
  },
  {
    method: 'GET',
    template: '/api/v1/users',
  },
  {
    method: 'POST',
    template: '/api/v1/users',
  },
  {
    method: 'DELETE',
    template: '/api/v1/users/{id}',
  },
  {
    method: 'GET',
    template: '/api/v1/users/{id}',
  },
  {
    method: 'PATCH',
    template: '/api/v1/users/{id}',
  },
  {
    method: 'GET',
    template: '/api/v1/users/{id}/offered-roles',
  },
  {
    method: 'POST',
    template: '/api/v1/users/{id}/restoration',
  },
  {
    method: 'POST',
    template: '/api/v1/users/{id}/role-grant-proposals',
  },
  {
    method: 'POST',
    template: '/api/v1/users/{id}/roles',
  },
  {
    method: 'DELETE',
    template: '/api/v1/users/{id}/roles/{roleId}',
  },
  {
    method: 'GET',
    template: '/api/v1/warehouses',
  },
  {
    method: 'POST',
    template: '/api/v1/warehouses',
  },
  {
    method: 'DELETE',
    template: '/api/v1/warehouses/{id}',
  },
  {
    method: 'GET',
    template: '/api/v1/warehouses/{id}',
  },
  {
    method: 'PATCH',
    template: '/api/v1/warehouses/{id}',
  },
  {
    method: 'GET',
    template: '/api/v1/warehouses/{id}/locations',
  },
  {
    method: 'POST',
    template: '/api/v1/warehouses/{id}/locations',
  },
  {
    method: 'DELETE',
    template: '/api/v1/warehouses/{id}/locations/{locationId}',
  },
  {
    method: 'PATCH',
    template: '/api/v1/warehouses/{id}/locations/{locationId}',
  },
  {
    method: 'POST',
    template: '/api/v1/warehouses/{id}/locations/{locationId}/restoration',
  },
  {
    method: 'POST',
    template: '/api/v1/warehouses/{id}/restoration',
  },
];

/** The sha256 of each contract input this module was generated from. */
export const SOURCE_SHA256: Readonly<Record<string, string>> = {
  'openapi.json': '14d200ddd25917e8495cb78d27a20017fc0f720fb7efef397efe644fcfe89a84',
  'api-surface.json': 'a90a00ad221259b69fcc033683b48560fe3c89f4623cd8fa6eac8122cbad7ef2',
};
