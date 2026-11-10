// Hand-mirrored from `backend/src/Modules/Inventory/ApiResource/ItemResource.php` (served schemas `Item`,
// `SupplierReference`, `Item.CreateItemInput` and `Item.UpdateItemInput.jsonMergePatch` in `contracts/openapi.json`).
// Checked both ways by C-1 (`test/contract/wireParity.test.ts`). This file imports nothing (A-18). Wire field names stay
// snake_case, as served. No cost is served on an item (`unit_cost` / `currency_code` are columns, never on the wire).

/** The served supplier reference: a live join with no `deleted_at` filter, so it can name an archived supplier. */
export interface SupplierReference {
  id: string;
  name: string;
}

/**
 * `GetItem` / `ListItems` item / `UpdateItem` and `RestoreItem`'s 200 body. The three numbers are decimal strings at the
 * column's scale (6, `wire-scale.json`), rendered to a person only through `lib/format/quantity.ts` (QR-2), never as a
 * number (13 + 6 digits exceed a double).
 */
export interface Item {
  id: string;
  sku: string;
  name: string;
  unit_of_measure_code: string;
  reorder_point: string;
  reorder_quantity: string;
  safety_stock: string;
  supplier: SupplierReference | null;
  archived_at: string | null;
  /** BK-27: whether any location still holds a non-zero quantity of this item. Rendered only in the Delete item dialog (I-3, I3-4). */
  holds_stock: boolean;
}

/**
 * `CreateItem`'s body (served `Item.CreateItemInput`). Mirrored at I-1 (Revision 8 R8-2); first sent at I-2. The item
 * forms never send `supplier_id` (S-B′: the supplier is shown, never edited, in this build).
 */
export interface CreateItemInput {
  sku?: string;
  name?: string;
  unit_of_measure_code?: string;
  supplier_id?: string | null;
  reorder_point?: string;
  reorder_quantity?: string;
  safety_stock?: string;
}

/**
 * `UpdateItem`'s merge-patch body (served `Item.UpdateItemInput.jsonMergePatch`). Every member is optional; the item page
 * sends only the changed ones, never `null`, never `supplier_id` (S-B′) and never `unit_of_measure_code` before I-4
 * (R8-2: the Unit is read-only on the item page until BK-22 + IO-3).
 */
export interface UpdateItemInput {
  sku?: string;
  name?: string;
  unit_of_measure_code?: string;
  supplier_id?: string | null;
  reorder_point?: string;
  reorder_quantity?: string;
  safety_stock?: string;
}
