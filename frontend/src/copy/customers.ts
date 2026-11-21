// Every customer screen sentence (Revision 8 R8-2). Customers C-1 (customers-plan.md Revision 1 — FINAL R1-5) ships the
// list, its Archived view and the read-only customer page with its read-only Contacts and Addresses sections. Sources, each
// entry naming its own:
// - `customers-c1-ux-copy.md` Revision 1 — FINAL (R1-9: the interim `noneArchived`, `CONTACTS_SECTION`,
//   `ADDRESSES_SECTION`, `addressKindLabel`);
// - `STATES.md` ST-9a (the Archived control, its heading, Back to customers, the caption, the archived search label and
//   the empty-state table — "Customers read the same, with customer(s) for supplier(s)");
// - `docs/session13/customers-s1-ux.md` §2.5 (the live search label, the filtered-empty sentence);
// - the shipped supplier list and page (`copy/suppliers.ts`, UX S-1), word for word with *customer* for *supplier*, where
//   no customer-specific text exists (the paged list's sentences, the read-only page's labels, the archived banner).
// Each sentence holds for the web app in this commit (`STATES.md:161`): C-1 has no create, edit, restore or delete, so no
// entry here names one. Server refusals are never stored here.

/** `{n} days` / `1 day` / `0 days` (the supplier list's "payment terms cell", UX S-1). */
const days = (n: number) => (n === 1 ? '1 day' : `${n} days`);

/** The live list and the Archived view (`SUPPLIERS_LIST`'s shape; ST-9a). */
export const CUSTOMERS_LIST = {
  /** `heading(archived)`: **Customers** (IA nav label) · **Archived customers** (ST-9a). */
  heading: (archived: boolean) => (archived ? 'Archived customers' : 'Customers'),
  /** ST-4b's `{resource}`: *"You don't have access to customers."* */
  resourcePlural: 'customers',
  /** `archived` · `backToList` (ST-9a). */
  archived: 'Archived',
  backToList: 'Back to customers',
  /** Live columns: Name · Code · Country · Currency · Payment terms. Archived: Name · Code · Archived on (ST-9a). */
  name: 'Name',
  code: 'Code',
  country: 'Country',
  currency: 'Currency',
  paymentTerms: 'Payment terms',
  archivedOn: 'Archived on',
  /** The payment terms cell. */
  days,
  /** `searchFor(archived)`: live = `docs/session13/customers-s1-ux.md` §2.5's text, moved from placeholder to label on the
   *  shipped supplier shape (`customers-c1-ux-copy.md` R1-2); archived ST-9a. `searchPlaceholder`, `searchHint`: the
   *  supplier list's (TB-5a: customer names fold case, codes do not — `IA.md` TB-5a, decided for `customer.name` 2026-10-09). */
  searchFor: (archived: boolean) => (archived ? 'Search archived customers by name or code' : 'Search by name or code'),
  searchPlaceholder: 'Start of a name or code',
  searchHint: "Capitals don't matter for names. For codes they do, and so do spaces.",
  /** The settled announcement (the supplier list's `shown`, the core requires one). */
  shown: (n: number, q: string, archived: boolean) =>
    [archived ? (n === 1 ? '1 archived customer shown.' : `${n} archived customers shown.`) : n === 1 ? '1 customer shown.' : `${n} customers shown.`, q !== '' ? `Name or code starts with “${q}”.` : null].filter(Boolean).join(' '),
  /** `loadFailed`. */
  loadFailed: "The list of customers couldn't be loaded.",
  /**
   * `noneYet`: the supplier list's shape. `customers-s1-ux.md` §2.5's longer first-run sentence (*"…Add the first one to
   * start taking orders."*) names two actions the web app cannot take in C-1 (no create page, no order screens), so it waits
   * for UX at C-3 (`STATES.md:161`).
   */
  noneYet: 'No customers yet.',
  /** `allArchived` · `viewArchived` (ST-9a). */
  allArchived: "No customers to show. Every customer here has been deleted, and they're in the archived customers.",
  viewArchived: 'View archived customers',
  /** `noMatch(q)` (`customers-s1-ux.md` §2.5; ST-9a "unchanged"). */
  noMatch: (q: string) => `No customer's name or code starts with “${q}”.`,
  /**
   * `noneArchived` — INTERIM (`customers-c1-ux-copy.md` Revision 1 — FINAL §1, R1-1, R1-9): ST-9a's sentence without
   * "and can be restored from here", because the web app offers no Restore before C-2b (`STATES.md:161`); the warehouses
   * list shipped the same interim at `434f239`. The swap to ST-9a's full sentence (*"No archived customers. Customers that
   * are deleted appear here and can be restored from here."*) is owed IN C-2b's commit, the one that ships Restore customer.
   */
  noneArchived: 'No archived customers. Customers that are deleted appear here.',
  /** `noArchivedMatch(q)` (ST-9a). */
  noArchivedMatch: (q: string) => `No archived customer's name or code starts with “${q}”.`,
  /** `clearSearch` · `archivedCaption` (ST-9a) · `footer(n)`. */
  clearSearch: 'Clear search',
  archivedCaption: 'Most recently archived first',
  footer: (n: number) => `${n} shown`,
} as const;

/** The paged list's own words (`SUPPLIERS_PAGING`'s shape). */
export const CUSTOMERS_PAGING = {
  /** `pager(archived)`: Previous / Next · Newer / Older. */
  pager: (archived: boolean) => (archived ? { previous: 'Newer', next: 'Older' } : { previous: 'Previous', next: 'Next' }),
  /** `liveRange(n, firstName, lastName)` (names only: no sort control). */
  liveRange: (n: number, firstName: string, lastName: string) => (n === 1 ? `1 customer shown, ${firstName}.` : `${n} customers shown, ${firstName} to ${lastName}.`),
  /** `searched(q)`: follows the range sentence. */
  searched: (q: string) => `Name or code starts with “${q}”.`,
  /** `archivedRange` · `archivedOneDate` · `archivedOne`. Dates are DT-1, formatted by the caller. */
  archivedRange: (n: number, newest: string, oldest: string, firstName: string) => `${n} archived customers shown, archived ${newest} to ${oldest}, starting with ${firstName}.`,
  archivedOneDate: (n: number, date: string, firstName: string) => `${n} archived customers shown, archived on ${date}, starting with ${firstName}.`,
  archivedOne: (name: string, date: string) => `1 archived customer shown, ${name}, archived on ${date}.`,
  /** `noMore(archived)`: heading · body · action. */
  noMore: (archived: boolean) => ({
    heading: archived ? 'There are no more archived customers here.' : 'There are no more customers here.',
    body: 'The list changed after the page you were on was loaded.',
    action: 'Back to the first page',
  }),
} as const;

/** The read-only customer page (`SUPPLIER_PAGE`'s read-only half; IA L-4: the detail page is the editor from C-2a). */
export const CUSTOMER_PAGE = {
  /** `title(code, name)`: h1 and `<title>` — the code first, so two namesakes read apart (ST-9a). */
  title: (code: string, name: string) => `${code} — ${name}`,
  /** Labels: Code · Name · Country · Currency · Payment terms (days). A read-only page shows no hints (B-3). */
  code: 'Code',
  name: 'Name',
  country: 'Country',
  currency: 'Currency',
  paymentTerms: 'Payment terms (days)',
  /** "read-only terms": `{n} days` · `1 day` · `0 days`. */
  days,
  /** The archived banner (ST-9): *"This customer was archived on {date}."* No action in C-1 (Restore ships at C-2b). */
  archivedOn: 'This customer was archived on ',
  archivedAfter: '.',
} as const;

/**
 * The customer page's read-only Contacts section (`customers-c1-ux-copy.md` Revision 1 — FINAL §2, R1-9). The email and
 * phone words live ONLY in this group, under the **Contacts** heading, so they read as the contact's, never the customer's
 * (customers-plan.md §4, G-36). C-1 offers no Add, Edit or Remove, so no sentence here names one (`STATES.md:161`), and
 * there is no archived-parent note before C-2b / C-4 (copy R1-4 matrix).
 */
export const CONTACTS_SECTION = {
  heading: 'Contacts',
  /** Columns: Name · Email · Phone · Primary. */
  name: 'Name',
  email: 'Email',
  phone: 'Phone',
  primary: 'Primary',
  /** The Primary cell, a word on every row (never a colour, a blank or a dash). */
  primaryYes: 'Yes',
  primaryNo: 'No',
  /** A null email or phone. */
  none: 'None',
  /** Spoken only on the section's first successful load after a failed one (the `CATALOGUE.count` rule). */
  count: (n: number) => (n === 1 ? '1 contact for this customer.' : `${n} contacts for this customer.`),
  empty: 'This customer has no contacts.',
  /** A failed read, a 404 or a non-null cursor: the region error with the house Retry (copy R1-5 L-3). */
  loadFailed: "The contacts couldn't be loaded.",
  denied: "You don't have access to this customer's contacts.",
} as const;

/**
 * The served address kinds' words (`customers-c1-ux-copy.md` R1-9; named so that `warehouses.ts`' `kindLabel` cannot be
 * imported in its place, R1-7). Any other served value is shown as sent.
 */
export const addressKindLabel = (kind: string): string => (kind === 'shipping' ? 'Shipping' : kind === 'billing' ? 'Billing' : kind);

/** The customer page's read-only Addresses section (`customers-c1-ux-copy.md` Revision 1 — FINAL §3, R1-9). */
export const ADDRESSES_SECTION = {
  heading: 'Addresses',
  /** Columns: Kind · Address · City · Postal code · Country (the cell is the served code as sent). */
  kind: 'Kind',
  line: 'Address',
  city: 'City',
  postalCode: 'Postal code',
  country: 'Country',
  /** Spoken only on the section's first successful load after a failed one (the `CATALOGUE.count` rule). */
  count: (n: number) => (n === 1 ? '1 address for this customer.' : `${n} addresses for this customer.`),
  /**
   * Not `customers-s1-ux.md` §2.5's "No addresses yet. Add a shipping address to use this customer on an order.": it names
   * two actions C-1 cannot take (copy §3); its replacement is owed at C-5.
   */
  empty: 'This customer has no addresses.',
  loadFailed: "The addresses couldn't be loaded.",
  denied: "You don't have access to this customer's addresses.",
} as const;
