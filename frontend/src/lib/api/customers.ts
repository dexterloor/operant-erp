// Hand-mirrored from `backend/src/Modules/Customers/ApiResource/CustomerResource.php` (served schema `Customer` in
// `contracts/openapi.json`). Checked both ways by C-1 (`test/contract/wireParity.test.ts`; names only). This file imports
// nothing (A-18). Wire field names stay snake_case, as served. Customers C-1 mirrors the read shapes the list, the
// read-only page and its Contacts and Addresses sections show (customers-plan.md Revision 1 R1-8): `Customer`,
// `CustomerContact`, `CustomerAddress` (served schemas of the same names, from `CustomerContactResource.php` and
// `CustomerAddressResource.php`). The four `customer` columns nothing writes (`email`, `phone`,
// `credit_limit`, `is_active`, RESIDUAL_GAPS.md G-36) are not served and are never mirrored here. Customers C-2a adds
// `UpdateCustomerInput` (served `Customer.UpdateCustomerInput.jsonMergePatch`), and, from C-2a, the one Save refusal code
// the family branches on (`CUSTOMER_SAVE_REFUSAL`).

/** `GetCustomer` / `ListCustomers` item. */
export interface Customer {
  id: string;
  code: string;
  name: string;
  country_code: string;
  currency_code: string;
  /** A whole number of days (served `integer`). Shown as `{n} days` / `1 day` / `0 days`. */
  payment_terms_days: number;
  archived_at: string | null;
}

/** `ListCustomerContacts` item (served order: primary first, then oldest first). */
export interface CustomerContact {
  id: string;
  name: string;
  /** The contact's, never the customer's (G-36). Null when not given. */
  email: string | null;
  phone: string | null;
  is_primary: boolean;
}

/** `ListCustomerAddresses` item (served order: `kind, created_at, id`; the page re-orders it, see `orderAddresses`). */
export interface CustomerAddress {
  id: string;
  /** Served as a plain string: `shipping` and `billing` today; any other value is shown as sent. */
  kind: string;
  line: string;
  city: string;
  postal_code: string;
  country_code: string;
}

/**
 * `UpdateCustomer`'s merge-patch body (served `Customer.UpdateCustomerInput.jsonMergePatch`). Every member is optional.
 * `name` is mirrored because the served schema has it, but C-2a never sends it: the editor's fields are Code, Country,
 * Currency and Payment terms (customers-plan.md Revision 1 — FINAL R1-5; a rename ships at C-2b with the shared-name
 * dialog). The customer page sends only the changed ones and never `null`.
 */
export interface UpdateCustomerInput {
  code?: string;
  name?: string;
  country_code?: string;
  currency_code?: string;
  /**
   * A typed non-integer is sent as typed so the server's own sentence shows (Revision 9 R9-2); the served schema says
   * integer.
   */
  payment_terms_days?: number | string;
}

/**
 * The one `UpdateCustomer` refusal code the customer page branches on (customers-c2a-ux-copy.md Revision 1a D-6; the
 * `ROLE_RESTORE_REFUSAL` shape). A Save refused `customer.is_archived` re-reads the customer, and the re-read decides which
 * client sentence shows: archived → `archivedOnSave` (K-15, then the ST-9 banner), live → `archivedThenRestored` (K-16),
 * failed → `archivedAtSave` (K-17). The server's own sentence (R3, "…until it's restored.") names a restore no web surface
 * offers in C-2a, so it is never rendered. At C-2b, which ships Restore customer, only K-15's branch is removed (an archived
 * re-read then renders R3 as sent); K-16 and K-17 stay while a Save can meet a customer that is no longer, or not provably,
 * archived. Only the Save result branches on it, compared in `lib/customers/wired.ts` only: `DeleteCustomer` (C-6) raises
 * the same rule with another sentence (R7), so no operation-independent rule→copy table may hold this key. Not pinned
 * against other importers (customers-plan.md Revision 1c R1c-2 item 5; ARCH decides whether to pin it).
 */
export const CUSTOMER_SAVE_REFUSAL = {
  archived: 'customer.is_archived',
} as const;
