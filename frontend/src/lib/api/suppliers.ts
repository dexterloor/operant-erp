// Hand-mirrored from `backend/src/Modules/Suppliers/ApiResource/SupplierResource.php` (served schemas `Supplier` and
// `Supplier.UpdateSupplierInput.jsonMergePatch` in `contracts/openapi.json`). Checked both ways by C-1
// (`test/contract/wireParity.test.ts`; names only). This file imports nothing (A-18). Wire field names stay snake_case,
// as served. Suppliers S-1 mirrors the two the editor reads and sends; the create input follows at S-2, the catalogue's
// shapes at S-3 (suppliers-plan.md §R1-3).

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
