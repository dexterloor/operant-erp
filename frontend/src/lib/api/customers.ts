// Hand-mirrored from `backend/src/Modules/Customers/ApiResource/CustomerResource.php` (served schema `Customer` in
// `contracts/openapi.json`). Checked both ways by C-1 (`test/contract/wireParity.test.ts`; names only). This file imports
// nothing (A-18). Wire field names stay snake_case, as served. Customers C-1 mirrors the read shapes the list, the
// read-only page and its Contacts and Addresses sections show (customers-plan.md Revision 1 R1-8): `Customer`,
// `CustomerContact`, `CustomerAddress` (served schemas of the same names, from `CustomerContactResource.php` and
// `CustomerAddressResource.php`). The four `customer` columns nothing writes (`email`, `phone`,
// `credit_limit`, `is_active`, RESIDUAL_GAPS.md G-36) are not served and are never mirrored here.

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
