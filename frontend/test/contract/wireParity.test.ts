import { describe, expect, it } from 'vitest';
import type { Collection } from '@/lib/api/envelope';
import type { Permission, SodRowRef } from '@/lib/api/permissions';
import type { RoleGrantProposal, UserDisplayReference } from '@/lib/api/proposals';
import type { OfferedRole, Role, RoleConflict } from '@/lib/api/roles';
import type { RoleReference, User } from '@/lib/api/users';
import type { CreateItemInput, Item, SupplierReference, UpdateItemInput } from '@/lib/api/items';
import type { CreateSupplierInput, CreateSupplierProductInput, Supplier, SupplierItemReference, SupplierProduct, UpdateSupplierInput, UpdateSupplierProductInput } from '@/lib/api/suppliers';
import type { ActorReference, CancelPurchaseOrderInput, CreatePurchaseOrderInput, CreatePurchaseOrderLineInput, GoodsReceipt, GoodsReceiptLine, GoodsReceiptLocationReference, GoodsReceiptOrderLineReference, GoodsReceiptOrderReference, NamedReference, PurchaseOrder, PurchaseOrderItemReference, PurchaseOrderLine, UpdatePurchaseOrderInput } from '@/lib/api/purchasing';
import type { CreateStorageLocationInput, CreateWarehouseInput, StorageLocation, UpdateStorageLocationInput, UpdateWarehouseInput, Warehouse } from '@/lib/api/warehouses';
import { mirroredSchemas, wireFields } from '@/lib/bff/surface';

// C-1 (b3b §14): every hand-written wire type in lib/api has exactly the served property names. The literal is a
// `Record<keyof T, true>`, so typecheck fails when the type gains or loses a member the literal does not list, and this
// test fails when the served schema and the type disagree. No codegen dependency (b3b §4).

const keysOf =
  <T>() =>
  (r: Record<keyof T, true>): string[] =>
    Object.keys(r).sort();

const MIRRORED: Record<string, string[]> = {
  User: keysOf<User>()({ id: true, email: true, display_name: true, is_active: true, archived_at: true, roles: true, margin_exposure: true }),
  RoleReference: keysOf<RoleReference>()({ id: true, name: true }),
  // The item of `User.roles` itself, not only the component of the same name: the served description now types it as
  // `{id, name}` (it was a list of nullable strings before d95d475), so the person's roles are checked from the
  // description rather than taken from the backend source by hand.
  'User.roles[]': keysOf<RoleReference>()({ id: true, name: true }),
  Role: keysOf<Role>()({
    id: true,
    name: true,
    description: true,
    permission_codes: true,
    is_system: true,
    holder_count: true,
    margin_exposure: true,
    archived_at: true,
  }),
  RoleGrantProposal: keysOf<RoleGrantProposal>()({
    id: true,
    user: true,
    role: true,
    permission_codes: true,
    status: true,
    is_expired: true,
    proposed_by: true,
    proposed_at: true,
    expires_at: true,
    decided_by: true,
    decided_at: true,
    reason: true,
    lapse_reason: true,
  }),
  UserDisplayReference: keysOf<UserDisplayReference>()({ id: true, display_name: true }),
  Permission: keysOf<Permission>()({ code: true, module: true, description: true, sod_rows: true }),
  SodRowRef: keysOf<SodRowRef>()({ id: true, half: true }),
  OfferedRole: keysOf<OfferedRole>()({
    id: true,
    name: true,
    conflicts_with: true,
    margin_exposure_with: true,
    refusal: true,
    requires_owner_confirmation: true,
  }),
  RoleConflict: keysOf<RoleConflict>()({ role: true, sod_row: true }),
  Collection: keysOf<Collection<unknown>>()({ items: true, next_cursor: true, previous_cursor: true, tenant_has_rows: true }),
  // Revision 7 A-18 (step 4.1): the warehouse and its update input; the create input and the location shapes join with
  // the commits that wire them.
  Warehouse: keysOf<Warehouse>()({ id: true, code: true, name: true, country_code: true, archived_at: true }),
  'Warehouse.CreateWarehouseInput': keysOf<CreateWarehouseInput>()({ code: true, name: true, country_code: true }),
  'Warehouse.UpdateWarehouseInput.jsonMergePatch': keysOf<UpdateWarehouseInput>()({ code: true, name: true, country_code: true }),
  StorageLocation: keysOf<StorageLocation>()({ id: true, code: true, kind: true, archived_at: true }),
  'StorageLocation.CreateStorageLocationInput': keysOf<CreateStorageLocationInput>()({ code: true, kind: true }),
  'StorageLocation.UpdateStorageLocationInput.jsonMergePatch': keysOf<UpdateStorageLocationInput>()({ code: true, kind: true }),
  // Items I-1 (Revision 8 R8-2): the item, its supplier reference, and both inputs (the create input is first sent at I-2).
  Item: keysOf<Item>()({ id: true, sku: true, name: true, unit_of_measure_code: true, reorder_point: true, reorder_quantity: true, safety_stock: true, supplier: true, archived_at: true, holds_stock: true }),
  SupplierReference: keysOf<SupplierReference>()({ id: true, name: true }),
  'Item.CreateItemInput': keysOf<CreateItemInput>()({ sku: true, name: true, unit_of_measure_code: true, supplier_id: true, reorder_point: true, reorder_quantity: true, safety_stock: true }),
  'Item.UpdateItemInput.jsonMergePatch': keysOf<UpdateItemInput>()({ sku: true, name: true, unit_of_measure_code: true, supplier_id: true, reorder_point: true, reorder_quantity: true, safety_stock: true }),
  // Suppliers S-1 (suppliers-plan.md §R1-3): the supplier and its update input; S-2 adds the create input.
  Supplier: keysOf<Supplier>()({ id: true, code: true, name: true, country_code: true, currency_code: true, payment_terms_days: true, archived_at: true }),
  'Supplier.UpdateSupplierInput.jsonMergePatch': keysOf<UpdateSupplierInput>()({ code: true, name: true, country_code: true, currency_code: true, payment_terms_days: true }),
  // S-2: the create input.
  'Supplier.CreateSupplierInput': keysOf<CreateSupplierInput>()({ code: true, name: true, country_code: true, currency_code: true, payment_terms_days: true }),
  // Suppliers S-3: the catalogue's two read shapes (suppliers-plan.md §R1-3).
  SupplierProduct: keysOf<SupplierProduct>()({ id: true, supplier_sku: true, inventory_item: true, unit_cost: true, currency_code: true, lead_time_days: true, minimum_order_quantity: true, unit_of_measure_code: true }),
  SupplierItemReference: keysOf<SupplierItemReference>()({ id: true, sku: true, name: true }),
  // Suppliers S-4: Edit a catalogue entry's merge-patch body (suppliers-plan.md §R1-3).
  'SupplierProduct.UpdateSupplierProductInput.jsonMergePatch': keysOf<UpdateSupplierProductInput>()({ supplier_sku: true, unit_cost: true, lead_time_days: true, minimum_order_quantity: true }),
  // Suppliers S-5: Add to catalogue's body (suppliers-plan.md §R1-3).
  'SupplierProduct.CreateSupplierProductInput': keysOf<CreateSupplierProductInput>()({ inventory_item_id: true, supplier_sku: true, unit_cost: true, lead_time_days: true, minimum_order_quantity: true }),
  // Purchase orders PO-1 (purchase-orders-plan.md §3; Revision 10 R10-2): the order, its line, the line's inline item
  // reference and the two references the order carries; each later commit adds the input it first sends.
  PurchaseOrder: keysOf<PurchaseOrder>()({ id: true, order_number: true, status: true, supplier: true, warehouse: true, currency_code: true, total_amount: true, ordered_at: true, expected_at: true, created_by: true, archived_at: true }),
  PurchaseOrderLine: keysOf<PurchaseOrderLine>()({ id: true, line_number: true, inventory_item: true, quantity: true, quantity_received: true, quantity_outstanding: true, unit_cost: true, archived_at: true }),
  'PurchaseOrderLine.inventory_item': keysOf<PurchaseOrderItemReference>()({ id: true, sku: true, name: true }),
  // Purchase orders PO-5: Add line's body (the new line row sends the chosen item and the two fields as typed).
  'PurchaseOrderLine.CreatePurchaseOrderLineInput': keysOf<CreatePurchaseOrderLineInput>()({ inventory_item_id: true, quantity: true, unit_cost: true }),
  NamedReference: keysOf<NamedReference>()({ id: true, name: true }),
  // Purchase orders PO-3: Create purchase order's body (the page sends supplier, warehouse and the supplier's currency only).
  'PurchaseOrder.CreatePurchaseOrderInput': keysOf<CreatePurchaseOrderInput>()({ supplier_id: true, warehouse_id: true, currency_code: true, expected_at: true, lines: true }),
  // Purchase orders PO-4a: the draft header's merge-patch body (the editor sends the changed warehouse only).
  'PurchaseOrder.UpdatePurchaseOrderInput.jsonMergePatch': keysOf<UpdatePurchaseOrderInput>()({ supplier_id: true, warehouse_id: true, expected_at: true }),
  ActorReference: keysOf<ActorReference>()({ id: true, display_name: true }),
  // Purchase orders PO-7: Cancel order's body (the dialog sends the reason as typed, or nothing).
  'PurchaseOrder.CancelPurchaseOrderInput': keysOf<CancelPurchaseOrderInput>()({ reason: true }),
  // Goods receipts GR-1: the receipt (register, receipt page, the order page's section) and its inline references.
  GoodsReceipt: keysOf<GoodsReceipt>()({ id: true, receipt_number: true, received_at: true, purchase_order: true, received_by: true, lines: true }),
  'GoodsReceipt.purchase_order': keysOf<GoodsReceiptOrderReference>()({ id: true, order_number: true }),
  'GoodsReceipt.lines[]': keysOf<GoodsReceiptLine>()({ id: true, purchase_order_line: true, inventory_item: true, quantity: true, storage_location: true }),
  'GoodsReceipt.lines[].purchase_order_line': keysOf<GoodsReceiptOrderLineReference>()({ id: true, line_number: true }),
  'GoodsReceipt.lines[].inventory_item': keysOf<PurchaseOrderItemReference>()({ id: true, sku: true, name: true }),
  'GoodsReceipt.lines[].storage_location': keysOf<GoodsReceiptLocationReference>()({ id: true, code: true }),
};

describe('C-1 — wire parity of the hand-written lib/api types', () => {
  it('the generated surface mirrors exactly the types this test checks', () => {
    expect([...mirroredSchemas()].sort()).toEqual(Object.keys(MIRRORED).sort());
  });

  it.each(Object.keys(MIRRORED))('%s has exactly the served property names', (name) => {
    expect(MIRRORED[name]).toEqual(wireFields(name));
  });

  it('an unknown name is an error, not an empty list', () => {
    expect(() => wireFields('NoSuchSchema')).toThrow(/NoSuchSchema/);
  });
});
