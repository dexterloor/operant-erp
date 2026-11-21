import type { Customer, CustomerAddress, CustomerContact } from '../api/customers';
import { pageQuery, type PageRequest, type Read, type ReadFailure } from '../bff/reads';

// The customer pages' loaders (the lib/suppliers/load.ts shape): pure, run over the server transport on first render and
// the browser one for every re-read. `ListCustomers` is PAGED (Revision 8 R8-1): its query is built only by `pageQuery()`
// (never `limit`, at most one cursor, `q` as typed only when not empty), and it never sends `sort`: the live view's served
// order is the default one (name), and the Archived view's only order is newest archived first (customers-plan.md §2.1).
// Held by behaviour: `test/wired/customers-list.test.tsx` drives this loader and asserts each request it sends.

export type CustomersLoad =
  | { readonly kind: 'ok'; readonly items: Customer[]; readonly nextCursor: string | null; readonly previousCursor: string | null; readonly tenantHasRows: boolean }
  | { readonly kind: ReadFailure };
export type CustomerLoad = { readonly kind: 'ok'; readonly customer: Customer } | { readonly kind: ReadFailure };
/** A whole set or a failure: a non-null cursor is `server-error`, never a partial table (customers-plan.md §5). */
export type ContactsLoad = { readonly kind: 'ok'; readonly contacts: CustomerContact[] } | { readonly kind: ReadFailure };
/** `addresses` are in the page's order (`orderAddresses`), not the served one. */
export type AddressesLoad = { readonly kind: 'ok'; readonly addresses: CustomerAddress[] } | { readonly kind: ReadFailure };

const isObject = (x: unknown): x is Record<string, unknown> => !!x && typeof x === 'object';
const isCursor = (x: unknown): x is string | null => x === null || typeof x === 'string';
export const isCustomer = (x: unknown): x is Customer =>
  isObject(x) &&
  typeof x.id === 'string' &&
  typeof x.code === 'string' &&
  typeof x.name === 'string' &&
  typeof x.country_code === 'string' &&
  typeof x.currency_code === 'string' &&
  // Served `integer`: a string, a fraction or a missing member is a malformed body, never a row.
  typeof x.payment_terms_days === 'number' &&
  Number.isInteger(x.payment_terms_days) &&
  (x.archived_at === null || typeof x.archived_at === 'string');

/**
 * `ListCustomers`: one page of the live view, or of the Archived one (`archived=true`), for a search and a cursor.
 * `tenant_has_rows` is served on every answer but meaningful only on an empty one (A-17 item 5); an answer without it is
 * treated as malformed, as for suppliers.
 */
export async function loadCustomers(read: Read, archived: boolean, page: PageRequest, signal?: AbortSignal): Promise<CustomersLoad> {
  const a = await read('ListCustomers', { query: pageQuery(archived, page), signal });
  if (a.kind !== 'ok') return a;
  const body = a.body as { items?: unknown; next_cursor?: unknown; previous_cursor?: unknown; tenant_has_rows?: unknown } | null;
  const items = body?.items;
  if (!Array.isArray(items) || !items.every(isCustomer) || !isCursor(body?.next_cursor) || !isCursor(body?.previous_cursor) || typeof body?.tenant_has_rows !== 'boolean') {
    return { kind: 'server-error' };
  }
  return { kind: 'ok', items, nextCursor: body.next_cursor, previousCursor: body.previous_cursor, tenantHasRows: body.tenant_has_rows };
}

/** `GetCustomer`: the page's first read. */
export async function loadCustomer(read: Read, id: string, signal?: AbortSignal): Promise<CustomerLoad> {
  const a = await read('GetCustomer', { params: { id }, signal });
  if (a.kind !== 'ok') return a;
  return isCustomer(a.body) ? { kind: 'ok', customer: a.body } : { kind: 'server-error' };
}

const isNullableString = (x: unknown): x is string | null => x === null || typeof x === 'string';
export const isCustomerContact = (x: unknown): x is CustomerContact =>
  isObject(x) && typeof x.id === 'string' && typeof x.name === 'string' && isNullableString(x.email) && isNullableString(x.phone) && typeof x.is_primary === 'boolean';
export const isCustomerAddress = (x: unknown): x is CustomerAddress =>
  isObject(x) &&
  typeof x.id === 'string' &&
  typeof x.kind === 'string' &&
  typeof x.line === 'string' &&
  typeof x.city === 'string' &&
  typeof x.postal_code === 'string' &&
  typeof x.country_code === 'string';

/**
 * The items of a bounded child answer (customers-plan.md §5, Revision 1 — FINAL R1-6 test 8): both cursors must be null,
 * because the section claims the whole set; a non-null cursor, a missing array or a malformed row is `null` (the caller's
 * `server-error`). `tenant_has_rows` is ignored (`customers-c1-ux-copy.md` R1-5 L-2: a section has one empty state).
 */
function wholeSet<T>(body: unknown, isRow: (x: unknown) => x is T): T[] | null {
  const b = body as { items?: unknown; next_cursor?: unknown; previous_cursor?: unknown } | null;
  const items = b?.items;
  if (!Array.isArray(items) || !items.every(isRow)) return null;
  return b?.next_cursor === null && b?.previous_cursor === null ? items : null;
}

/**
 * `ListCustomerContacts`: the customer's contacts in one answer, in served order (primary first, then oldest first — §15.1;
 * `IA.md:358`). It sends the customer id and nothing else. Run over the server transport on first render and the browser
 * one for the section's Retry.
 */
export async function loadContacts(read: Read, customerId: string, signal?: AbortSignal): Promise<ContactsLoad> {
  const a = await read('ListCustomerContacts', { params: { id: customerId }, signal });
  if (a.kind !== 'ok') return a;
  const contacts = wholeSet(a.body, isCustomerContact);
  return contacts === null ? { kind: 'server-error' } : { kind: 'ok', contacts };
}

/**
 * The page's address order (customers-plan.md §5.2; `IA.md:358`, UX 2026-10-12): Shipping first, then Billing, NEWEST FIRST
 * within each; any other kind after both, shown as sent, each such kind newest first, those kinds in the order served.
 * The read carries no `created_at`, so "newest first" is the served order reversed within a kind: the server lists
 * addresses by `kind, created_at, id` (`CustomerAddressReader.php:62`), pinned by the backend's
 * `CustomerAddressListTest::aCustomersAddressesAreListedWholeInKindThenAgeOrder` — not by the OpenAPI document. A change to
 * the served order must move that pin, and its commit tells the frontend (`owed-to-backend.md`). A bounded whole set is not
 * a page, so R8-1 item 7's ban on re-sorting a page does not reach it (customers-plan.md R1-9 R13-2 note).
 */
export function orderAddresses(served: readonly CustomerAddress[]): CustomerAddress[] {
  const kinds = ['shipping', 'billing', ...served.map((a) => a.kind).filter((k, i, all) => k !== 'shipping' && k !== 'billing' && all.indexOf(k) === i)];
  return kinds.flatMap((k) => served.filter((a) => a.kind === k).reverse());
}

/**
 * `ListCustomerAddresses`: the customer's addresses in one answer, returned in the page's order (`orderAddresses`). It sends
 * the customer id and nothing else.
 */
export async function loadAddresses(read: Read, customerId: string, signal?: AbortSignal): Promise<AddressesLoad> {
  const a = await read('ListCustomerAddresses', { params: { id: customerId }, signal });
  if (a.kind !== 'ok') return a;
  const addresses = wholeSet(a.body, isCustomerAddress);
  return addresses === null ? { kind: 'server-error' } : { kind: 'ok', addresses: orderAddresses(addresses) };
}
