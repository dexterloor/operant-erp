// Every customer screen sentence (Revision 8 R8-2). Customers C-1 (customers-plan.md Revision 1 — FINAL R1-5) ships the
// list, its Archived view and the customer page with its read-only Contacts and Addresses sections; C-2a makes the page an
// editor of Code, Country, Currency and Payment terms. Sources, each entry naming its own:
// - `customers-c1-ux-copy.md` Revision 1 — FINAL (R1-9: the interim `noneArchived`, `CONTACTS_SECTION`,
//   `ADDRESSES_SECTION`, `addressKindLabel`);
// - `STATES.md` ST-9a (the Archived control, its heading, Back to customers, the caption, the archived search label and
//   the empty-state table — "Customers read the same, with customer(s) for supplier(s)");
// - `docs/session13/customers-s1-ux.md` §2.5 (the live search label, the filtered-empty sentence);
// - the shipped supplier list and page (`copy/suppliers.ts`, UX S-1), word for word with *customer* for *supplier*, where
//   no customer-specific text exists (the paged list's sentences, the read-only page's labels, the archived banner);
// - `customers-c2a-ux-copy.md` Revision 1 — FINAL, as corrected by `customers-c2a-ux-copy-final-red.md` (the editor's keys,
//   `CUSTOMER_FORBIDDEN`).
// - `customers-c4c5-ux-copy.md` Revision 1a > Revision 1 > Revision 0 (contacts added and changed, C-4a: the
//   `CONTACTS_SECTION` additions, `ADD_CONTACT`, `EDIT_CONTACT`).
// Each sentence holds for the web app in this commit (`STATES.md:161`). C-2a edits Code, Country, Currency and Payment terms
// only. There is no create, rename, restore or delete of a customer on any web surface, so no entry here offers one;
// `archivedThenRestored` reports a restore made elsewhere as a fact. Contacts can be added and changed (C-4a); every refusal
// that names a restore is replaced by a client sentence while no web surface can restore (copy D-1). Server refusals are
// never stored here.

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

/** The customer page and, from C-2a, its editor (Name stays text until C-2b). Hints show only beside editable fields (B-3). */
export const CUSTOMER_PAGE = {
  /** `title(code, name)`: h1 and `<title>` — the code first, so two namesakes read apart (ST-9a). */
  title: (code: string, name: string) => `${code} — ${name}`,
  /** Labels: Code · Name · Country · Currency · Payment terms (days). Hints show only beside editable fields (B-3). */
  code: 'Code',
  name: 'Name',
  country: 'Country',
  currency: 'Currency',
  paymentTerms: 'Payment terms (days)',
  /** "read-only terms": `{n} days` · `1 day` · `0 days`. */
  days,
  /** The archived banner (ST-9): *"This customer was archived on {date}."* No action (Restore ships at C-2b). */
  archivedOn: 'This customer was archived on ',
  archivedAfter: '.',
  /**
   * The editor's hints (C-2a; `customers-c2a-ux-copy.md` Revision 1 — FINAL R1-1), each beside its editable field only.
   * Name has no hint and no field in C-2a (D-1): it is plain text under its label; `nameHint` (G-38) arrives at C-2b.
   */
  codeHint: "A short code people will recognise, for example ACME. Two customers can't share a code unless one of them is archived, and capitals and spaces count.",
  countryHint: 'Two letters, for example GB. Each address below keeps its own country.',
  /**
   * Sentence 3 holds while nothing reads `customer.currency_code` (`CustomerWriter.php:58`; `CreateSalesOrderInput.php:90-92`).
   * The commit that pre-fills an order's currency from the customer (`customers-s1-ux.md` §3.3 item 4, `FLOWS.md` F-5
   * step 1) must replace it in the same commit.
   */
  currencyHint: 'Three letters, for example GBP. The currency this customer is usually invoiced in. For your records only: nothing else in the app uses it.',
  termsHint: 'Days after the invoice date that payment is due. 0 means due on the invoice date. Invoices already issued keep their due date.',
  /** An emptied field, caught on the press; no request is sent (copy D-7: only an emptied field, never spaces or a range). */
  codeRequired: 'Enter a code.',
  countryRequired: 'Enter a country code.',
  currencyRequired: 'Enter a currency code.',
  termsRequired: 'Enter a number of days, or 0.',
  save: 'Save',
  /** Save's `aria-disabled` reason while nothing differs (D-5: the shipped key, not `nothingChanged`). */
  saveNothing: 'Nothing to save.',
  /** `{code}` is the code as served after the save, so a changed code is spoken in its new form (D-5). */
  saved: (code: string) => `${code} was saved.`,
  /** Under `COULD_NOT_REACH` / `WENT_WRONG`; `{code}` is the code shown before the press. */
  interrupted: (code: string) => `We can't tell whether your changes to ${code} were saved. Reload the page to check.`,
  /** The region error after a write's re-read fails, with the house Retry. */
  rereadFailed: "We couldn't load this customer again, so what's shown may be out of date.",
  /**
   * K-15 (copy Revision 1a R1a-1, D-6): under **Not saved** when a Save is refused (`customer.is_archived` or any other rule)
   * and the re-read is archived; the ST-9 banner follows and the form is gone. Replaces the server's R3, whose "…until it's
   * restored." names a restore no web surface offers in C-2a. C-2b (Restore ships) deletes this key and its branch.
   */
  archivedOnSave: "This customer is archived, so it can't be changed.",
  /**
   * K-16: under **Not saved** when a Save is refused `customer.is_archived` and the re-read is live; the re-read is not
   * committed, so the person's edits stay (the supplier shape). Reports a restore made elsewhere as a fact.
   */
  archivedThenRestored: 'Nothing was saved, because this customer was archived at that moment. It has since been restored, so you can press Save again.',
  /**
   * K-17: under **Not saved** when a Save is refused `customer.is_archived` and the re-read failed; the region shows
   * `rereadFailed` with Retry beside it, and the edits stay. It names no action and claims nothing about the state now.
   */
  archivedAtSave: 'Nothing was saved, because this customer was archived at that moment.',
} as const;

/**
 * The lost-permission sentence on Save (copy Revision 1 — FINAL R1-1): `SUPPLIER_FORBIDDEN` word for word, around the
 * permission label read from `permissionLabels.ts` by `UpdateCustomer`'s code.
 */
export const CUSTOMER_FORBIDDEN = {
  before: 'This needs the ',
  after: ' permission, which you no longer have. Your workspace administrator can grant it.',
} as const;

/**
 * The customer page's Contacts section (`customers-c1-ux-copy.md` Revision 1 — FINAL §2, R1-9; from C-4a
 * `customers-c4c5-ux-copy.md` §2.1 + R1-1 + R1-2). The email and phone words live ONLY in this group, under the
 * **Contacts** heading, so they read as the contact's, never the customer's (customers-plan.md §4, G-36): the dialogs' field
 * words are top-level `field…` members here, never in a dialog group (copy R1-1). C-4a offers Add and Edit; Remove (and
 * `remove` / `removeFor`) waits for C-4b, so no sentence here names it.
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
  /** The empty table on a LIVE customer (copy R1-2 row 7); Add contact follows it for those who may add. */
  empty: 'No contacts for this customer yet.',
  /** The empty table on an ARCHIVED customer, under `archivedParent` (copy R1-2 row 101: C-1's text, kept). */
  emptyArchived: 'This customer has no contacts.',
  /** A failed read, a 404 or a non-null cursor: the region error with the house Retry (copy R1-5 L-3). */
  loadFailed: "The contacts couldn't be loaded.",
  denied: "You don't have access to this customer's contacts.",
  /** The section header's action and the empty state's, for an Add holder on a live customer (copy row 1). */
  add: 'Add contact',
  /** The column after Primary, only when the viewer may Edit on a live customer (copy row 2). */
  actions: 'Actions',
  /** The row action, and its accessible name `editFor(name)` with the served name unquoted (copy rows 3–4). */
  edit: 'Edit',
  editFor: (name: string) => `Edit ${name}`,
  /** Above the table on an archived customer's page, for everyone; no actions (copy row 8, D-5). */
  archivedParent: "Contacts can't be added, changed or removed while the customer is archived.",
  /** The Add and Edit dialogs' field words (copy R1-1 rows 9–15). */
  fieldName: 'Name',
  fieldEmail: 'Email (optional)',
  fieldPhone: 'Phone (optional)',
  fieldPrimary: 'Primary contact',
  /** Under the checkbox: Add always; Edit when the served contact is NOT primary. */
  primaryHint: "Only one contact can be primary, and it's listed first. Marking this one takes the mark off any other.",
  /** Under the checkbox in Edit when the served contact IS primary. */
  primaryClearHint: 'This is the primary contact. If you clear the mark, this customer has no primary contact until another one is marked.',
  /** Beside an empty Name on Add or Edit; no request is sent (copy row 15, T-C6). */
  nameRequired: 'Enter a name.',
} as const;

/** The composed clauses (copy R1-1, the `ADD_AFTER` shape): module-private, exposed only as each dialog group's members. */
const CONTACT_PRIMARY_NOW = "It's now the primary contact.";
const CONTACT_PRIMARY_NO_LONGER = "It's no longer the primary contact.";
/** `WAITING.listStale` (`roles.ts`) word for word. */
const LIST_STALE = "We couldn't load the list again, so it may be out of date.";
const CONTACT_ADDED = (name: string) => `“${name}” was added to the contacts.`;
const CONTACT_SAVED = (name: string) => `“${name}” was saved.`;

/**
 * Add contact's dialog (copy §2.3 + R1-1 + R1-2 + R1a-3 row 24; refusal heading `NOT_ADDED`). `{name}` in a sentence is in
 * curly quotes; the email and phone never appear in a sentence (copy D-8).
 */
export const ADD_CONTACT = {
  /** `{code}` = the customer's served code. */
  title: (code: string) => `Add a contact to ${code}`,
  submit: 'Add contact',
  cancel: 'Cancel',
  /** The one button after a refusal that leaves nothing to press (an archived re-read, or a 404). */
  close: 'Close',
  /** Polite status after the 201; `{name}` = the 201's name. */
  after: CONTACT_ADDED,
  primaryNowClause: CONTACT_PRIMARY_NOW,
  listStaleClause: LIST_STALE,
  /**
   * `after` · `primaryNowClause` (when `is_primary: true` was sent AND the 201 has `is_primary: true`, decided by the
   * caller) · `listStaleClause` (the section's re-read failed; written only through `replaceIfCurrent`).
   */
  afterComposed: (name: string, { primary, stale }: { primary: boolean; stale: boolean }) =>
    [CONTACT_ADDED(name), primary ? CONTACT_PRIMARY_NOW : null, stale ? LIST_STALE : null].filter((p): p is string => p !== null).join(' '),
  /** Under `COULD_NOT_REACH` / `WENT_WRONG`; `{name}` = as typed. Add stays. */
  interrupted: (name: string) => `We can't tell whether “${name}” was added. Check this customer's contacts before you try again.`,
  /** INTERIM until C-2b: any refusal whose customer re-read is archived; Close only. */
  archived: 'This customer is archived, so no contact can be added to it.',
  /** Refused `customer.is_archived`, re-read live: the typed values and Add stay. */
  archivedThenRestored: 'Nothing was added, because this customer was archived at that moment. It has since been restored, so you can press Add contact again.',
  /** Refused `customer.is_archived`, re-read failed: the typed values and Add stay (D-3). */
  archivedAtAdd: "Nothing was added, because this customer was archived at that moment. We couldn't check whether it still is.",
} as const;

/** A contact row's Edit dialog (copy §2.4 + R1-1 + R1-2 row 35; refusal heading `NOT_SAVED`). */
export const EDIT_CONTACT = {
  /** `{name}` = the served name at open. */
  title: (name: string) => `Edit contact “${name}”`,
  save: 'Save',
  cancel: 'Cancel',
  /** After an archived re-read or a 404. */
  close: 'Close',
  /** Save's `aria-disabled` reason while nothing differs. */
  saveNothing: 'Nothing to save.',
  /** Polite status; `{name}` = the 200's name. */
  saved: CONTACT_SAVED,
  primaryNowClause: CONTACT_PRIMARY_NOW,
  primaryNoLongerClause: CONTACT_PRIMARY_NO_LONGER,
  listStaleClause: LIST_STALE,
  /**
   * `saved` · (`primaryNowClause` when `is_primary: true` was sent AND the 200 has `is_primary: true`;
   * `primaryNoLongerClause` when `is_primary: false` was sent — decided by the caller) · `listStaleClause`.
   */
  afterComposed: (name: string, { primary, stale }: { primary: 'now' | 'no-longer' | null; stale: boolean }) =>
    [CONTACT_SAVED(name), primary === 'now' ? CONTACT_PRIMARY_NOW : primary === 'no-longer' ? CONTACT_PRIMARY_NO_LONGER : null, stale ? LIST_STALE : null].filter((p): p is string => p !== null).join(' '),
  /** Under `COULD_NOT_REACH` / `WENT_WRONG`; `{name}` = shown before the press. Save stays. */
  interrupted: (name: string) => `We can't tell whether your changes to “${name}” were saved. Close this and check the contacts before you try again.`,
  /** INTERIM until C-2b: any refusal whose customer re-read is archived; Close only. */
  archived: "This customer is archived, so its contacts can't be changed.",
  /** Refused `customer.is_archived`, re-read live (= C-2a K-16 word for word): the edits and Save stay. */
  archivedThenRestored: 'Nothing was saved, because this customer was archived at that moment. It has since been restored, so you can press Save again.',
  /** Refused `customer.is_archived`, re-read failed: the edits and Save stay (D-3). */
  archivedAtSave: "Nothing was saved, because this customer was archived at that moment. We couldn't check whether it still is.",
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
