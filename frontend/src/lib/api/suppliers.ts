// Hand-mirrored from `backend/src/Modules/Suppliers/ApiResource/SupplierResource.php` (served schemas `Supplier` and
// `Supplier.UpdateSupplierInput.jsonMergePatch` in `contracts/openapi.json`). Checked both ways by C-1
// (`test/contract/wireParity.test.ts`; names only). This file imports nothing (A-18). Wire field names stay snake_case,
// as served. Suppliers S-1 mirrors the two the editor reads and sends; S-2 the create input; S-3 the catalogue's two read
// shapes, `SupplierProduct` and `SupplierItemReference` (suppliers-plan.md §R1-3).

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
 * No unit is carried on it: the unit is on the entry, served (BK-31) but not shown until S-4 (M-8 interim), so the catalogue shows the minimum order without one (QR-8 interim).
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
  /** The unit `minimum_order_quantity` is in (BK-31, `0b9f574`; one of the served `UnitsOfMeasure` codes, never null). Mirrored only: no screen reads it before S-4/S-5. */
  unit_of_measure_code: string;
}
