// Hand-mirrored from `backend/src/Modules/Suppliers/ApiResource/SupplierResource.php` (served schemas `Supplier` and
// `Supplier.UpdateSupplierInput.jsonMergePatch` in `contracts/openapi.json`). Checked both ways by C-1
// (`test/contract/wireParity.test.ts`; names only). This file imports nothing (A-18). Wire field names stay snake_case,
// as served. Suppliers S-1 mirrors the two the editor reads and sends; S-2 the create input; S-3 the catalogue's two read
// shapes, `SupplierProduct` and `SupplierItemReference`; S-4 the catalogue entry's update input; S-5 its create input
// (suppliers-plan.md §R1-3). The Add dialog's item choice is NOT here: it is `Pick<Item, …>` in `lib/suppliers/load.ts`
// (suppliers-final-red.md M-7), so C-1's check of `Item` holds it.

/** `GetSupplier` / `ListSuppliers` item / `UpdateSupplier` and `RestoreSupplier`'s 200 body. */
export interface Supplier {
  id: string;
  code: string;
  name: string;
  country_code: string;
  currency_code: string;
  /** A whole number of days (served `integer`). Shown as `{n} days` / `1 day` / `0 days`; nothing else in the app reads it. */
  payment_terms_days: number;
  archived_at: string | null;
}

/**
 * `UpdateSupplier`'s merge-patch body (served `Supplier.UpdateSupplierInput.jsonMergePatch`). Every member is optional;
 * the supplier page sends only the changed ones and never `null`. `currency_code` is mirrored but never sent in S-1…S-5
 * (read-only until S-6, suppliers-plan.md §R1-10).
 */
export interface UpdateSupplierInput {
  code?: string;
  name?: string;
  country_code?: string;
  currency_code?: string;
  /**
   * A typed non-integer is sent as typed so the server's own sentence shows (suppliers-red M-1); the served schema says
   * integer.
   */
  payment_terms_days?: number | string;
}

/**
 * `CreateSupplier`'s body (served `Supplier.CreateSupplierInput`). The create page sends all five, as typed (Country and
 * Currency upper-cased as typed); the four text members are served nullable but never sent `null`.
 */
export interface CreateSupplierInput {
  code?: string;
  name?: string;
  country_code?: string;
  currency_code?: string;
  /**
   * A typed non-integer is sent as typed so the server's own sentence shows (suppliers-red M-1); the served schema says
   * integer.
   */
  payment_terms_days?: number | string;
}

/**
 * `SupplierProduct`'s `inventory_item` (served `SupplierItemReference`, PC-15 display fields): the item's id, SKU and name.
 * No unit is carried on it: the unit is on the entry itself (`SupplierProduct.unit_of_measure_code`, BK-31), which the
 * catalogue shows in its Unit column and the Edit dialog after Minimum order and Unit cost (from S-4).
 */
export interface SupplierItemReference {
  id: string;
  sku: string;
  name: string;
}

/**
 * One catalogue entry (served `SupplierProduct`, the item of `ListSupplierProducts`' `SupplierProductCollection`).
 * `unit_cost` and `minimum_order_quantity` are decimal strings and are never converted to numbers (QR-1; money via
 * `lib/format/money.ts`, the quantity via `lib/format/quantity.ts`); `lead_time_days` is a whole number of days.
 */
export interface SupplierProduct {
  id: string;
  supplier_sku: string;
  inventory_item: SupplierItemReference;
  unit_cost: string;
  currency_code: string;
  lead_time_days: number;
  minimum_order_quantity: string;
  /**
   * The item's unit, which `minimum_order_quantity` is in and `unit_cost` is the price of one of (BK-31, `0b9f574`; PO,
   * `bk31-cost-basis-po.md`; one of the served `UnitsOfMeasure` codes, never null). From S-4 the catalogue's Unit column
   * and the Edit dialog's suffixes read it from here, never from anywhere else. Never sent: no input carries it.
   */
  unit_of_measure_code: string;
}

/**
 * `UpdateSupplierProduct`'s merge-patch body (served `SupplierProduct.UpdateSupplierProductInput.jsonMergePatch`). Every
 * member is optional; the Edit dialog sends only the changed ones and never `null`. The item and its unit are not members:
 * an entry's item cannot change, and its unit is the item's. `unit_cost` and `minimum_order_quantity` are sent as typed
 * (decimal strings, QR-1).
 */
export interface UpdateSupplierProductInput {
  supplier_sku?: string;
  unit_cost?: string;
  /**
   * A typed non-integer is sent as typed so the server's own sentence shows (suppliers-red M-1); the served schema says
   * integer.
   */
  lead_time_days?: number | string;
  minimum_order_quantity?: string;
}

/**
 * `CreateSupplierProduct`'s body (served `SupplierProduct.CreateSupplierProductInput`; Suppliers S-5). The Add dialog
 * sends all five members, copied by name: the chosen item's id, and the four fields as typed (the cost and the minimum
 * order as decimal strings, QR-1; the lead time a JSON number only when it is digits). The unit is not a member: an
 * entry's unit is its item's.
 */
export interface CreateSupplierProductInput {
  inventory_item_id?: string;
  supplier_sku?: string;
  unit_cost?: string;
  /**
   * A typed non-integer is sent as typed so the server's own sentence shows (suppliers-red M-1); the served schema says
   * integer.
   */
  lead_time_days?: number | string;
  minimum_order_quantity?: string;
}
