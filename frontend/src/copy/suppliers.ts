// Every supplier screen sentence (Revision 8 R8-2), from `docs/design/suppliers-ux-copy.md` "Revision 1 — FINAL" S-1 (as
// corrected by `suppliers-final-red.md`), falling back to Revision 0 Part B where Revision 1 is silent; each entry names
// its row. Server refusals are never stored here: they render as sent (Part A, S1…S16). Each sentence holds for the web
// app in the commit that renders it (`STATES.md:161`): S-1 has the list, the Archived view, the supplier page (edit) and
// Restore supplier; S-2 adds the create page (UX S-2). No catalogue (S-3), no Delete supplier (S-7) — so no sentence here
// names one (suppliers-final-red.md L-1: the create page's Currency hint carries no catalogue sentence until S-3).

/** `{n} days` / `1 day` / `0 days` (UX S-1 "payment terms cell" and "read-only terms"). */
const days = (n: number) => (n === 1 ? '1 day' : `${n} days`);

/** UX S-1 `SUPPLIERS_LIST`: the live list and the Archived view. */
export const SUPPLIERS_LIST = {
  /** `heading(archived)`: **Suppliers** · **Archived suppliers** (IA nav label; ST-9a). */
  heading: (archived: boolean) => (archived ? 'Archived suppliers' : 'Suppliers'),
  /** ST-4b's `{resource}`: *"You don't have access to suppliers."* */
  resourcePlural: 'suppliers',
  /** `archived` · `backToList` (ST-9a). */
  archived: 'Archived',
  backToList: 'Back to suppliers',
  /** Live columns: Name · Code · Country · Currency · Payment terms. Archived: Name · Code · Archived on. */
  name: 'Name',
  code: 'Code',
  country: 'Country',
  currency: 'Currency',
  paymentTerms: 'Payment terms',
  archivedOn: 'Archived on',
  /** The payment terms cell. */
  days,
  /** `searchFor(archived)`, `searchPlaceholder`, `searchHint` (TB-5a: names fold case, codes do not). */
  searchFor: (archived: boolean) => (archived ? 'Search archived suppliers by name or code' : 'Search by name or code'),
  searchPlaceholder: 'Start of a name or code',
  searchHint: "Capitals don't matter for names. For codes they do, and so do spaces.",
  /**
   * The settled announcement (first load, a switch, a settle; the core's `shown`, never called with 0). NOT a UX key:
   * Revision 1 lists none for the supplier list though the core requires one (Deviation D-1 of the S-1 build note, UX TO
   * CONFIRM). Built from UX's own words: `liveRange`'s noun and `archivedRange`'s, then `SUPPLIERS_PAGING.searched(q)`.
   */
  shown: (n: number, q: string, archived: boolean) =>
    [archived ? (n === 1 ? '1 archived supplier shown.' : `${n} archived suppliers shown.`) : n === 1 ? '1 supplier shown.' : `${n} suppliers shown.`, q !== '' ? `Name or code starts with “${q}”.` : null].filter(Boolean).join(' '),
  /** `loadFailed`. */
  loadFailed: "The list of suppliers couldn't be loaded.",
  /** `noneYet` (from S-2 with Create supplier as its action, for a CreateSupplier holder). */
  noneYet: 'No suppliers yet.',
  /** UX S-2 `newSupplier` (live header) · `createSupplier` (the `noneYet` and `allArchived` action), CONFIRMS §3. */
  newSupplier: 'New supplier',
  createSupplier: 'Create supplier',
  /** `allArchived` · `viewArchived`. */
  allArchived: "No suppliers to show. Every supplier here has been deleted, and they're in the archived suppliers.",
  viewArchived: 'View archived suppliers',
  /** `noMatch(q)`. */
  noMatch: (q: string) => `No supplier's name or code starts with “${q}”.`,
  /** `noneArchived` (true from S-1: Restore ships here). */
  noneArchived: 'No archived suppliers. Suppliers that are deleted appear here and can be restored from here.',
  /** `noArchivedMatch(q)`. */
  noArchivedMatch: (q: string) => `No archived supplier's name or code starts with “${q}”.`,
  /** `clearSearch` · `archivedCaption` · `footer(n)`. */
  clearSearch: 'Clear search',
  archivedCaption: 'Most recently archived first',
  footer: (n: number) => `${n} shown`,
} as const;

/** UX S-1 `SUPPLIERS_PAGING`: the paged list's own words (R1-3 pager, R1-8 / F-2 page sentences, R1-7 / F-3). */
export const SUPPLIERS_PAGING = {
  /** `pager(archived)`: Previous / Next · Newer / Older. */
  pager: (archived: boolean) => (archived ? { previous: 'Newer', next: 'Older' } : { previous: 'Previous', next: 'Next' }),
  /** `liveRange(n, firstName, lastName)` (names only: no sort control). */
  liveRange: (n: number, firstName: string, lastName: string) => (n === 1 ? `1 supplier shown, ${firstName}.` : `${n} suppliers shown, ${firstName} to ${lastName}.`),
  /** `searched(q)`: follows the range sentence. */
  searched: (q: string) => `Name or code starts with “${q}”.`,
  /** `archivedRange` · `archivedOneDate` · `archivedOne`. Dates are DT-1, formatted by the caller. */
  archivedRange: (n: number, newest: string, oldest: string, firstName: string) => `${n} archived suppliers shown, archived ${newest} to ${oldest}, starting with ${firstName}.`,
  archivedOneDate: (n: number, date: string, firstName: string) => `${n} archived suppliers shown, archived on ${date}, starting with ${firstName}.`,
  archivedOne: (name: string, date: string) => `1 archived supplier shown, ${name}, archived on ${date}.`,
  /** `noMore(archived)`: heading · body · action. */
  noMore: (archived: boolean) => ({
    heading: archived ? 'There are no more archived suppliers here.' : 'There are no more suppliers here.',
    body: 'The list changed after the page you were on was loaded.',
    action: 'Back to the first page',
  }),
} as const;

/** UX S-1 `SUPPLIER_PAGE`: the supplier page (the detail page is the editor, IA L-4). */
export const SUPPLIER_PAGE = {
  /** `title(code, name)`: h1 and `<title>`. */
  title: (code: string, name: string) => `${code} — ${name}`,
  /** Labels: Code · Name · Country · Currency · Payment terms (days). */
  code: 'Code',
  name: 'Name',
  country: 'Country',
  currency: 'Currency',
  paymentTerms: 'Payment terms (days)',
  /** `codeHint` (REPLACES Revision 0's, RED L-5 refined). Edit only: a read-only page shows no hints (B-3). */
  codeHint: "A short code people will recognise, for example ACME. Two suppliers can't share a code unless one of them is archived, and capitals and spaces count.",
  /** `nameHint` (edit only; G-40). */
  nameHint: 'Renaming also changes the name on every purchase order and item that already names this supplier.',
  /** `countryHint` (CONFIRMS `WAREHOUSE_PAGE.countryHint`). */
  countryHint: 'Two letters, for example GB.',
  /** `termsHint`. Currency in S-1…S-5: read-only text, no hint (UX "For UX" 5). */
  termsHint: 'How many days you have to pay this supplier. For your records only: nothing else in the app uses it.',
  /** `termsRequired`: an emptied field on Save, beside it; no request is sent. */
  termsRequired: 'Enter a number of days, or 0.',
  /** "read-only terms": `{n} days` · `1 day` · `0 days`. */
  days,
  save: 'Save',
  saveNothing: 'Nothing to save.',
  saved: (code: string) => `${code} was saved.`,
  interrupted: (code: string) => `We can't tell whether your changes to ${code} were saved. Reload the page to check.`,
  rereadFailed: "We couldn't load this supplier again, so what's shown may be out of date.",
  /** The archived banner (ST-9): *"This supplier was archived on {date}."* Its action is `RESTORE_SUPPLIER.action`. */
  archivedOn: 'This supplier was archived on ',
  archivedAfter: '.',
} as const;

/** UX S-1 `RESTORE_SUPPLIER` (CONFIRMS R1-17 / ST-9): in the archived banner, no dialog. */
export const RESTORE_SUPPLIER = {
  action: 'Restore supplier',
  after: (code: string) => `${code} was restored.`,
  alreadyRestored: (code: string) => `${code} was already restored.`,
  interrupted: (code: string) => `We can't tell whether ${code} was restored. Press Restore supplier to try again.`,
} as const;

/** UX S-1 `SUPPLIER_FORBIDDEN` (CONFIRMS R1-5): the label is read from `permissionLabels.ts` by the operation's code. */
export const SUPPLIER_FORBIDDEN = {
  before: 'This needs the ',
  after: ' permission, which you no longer have. Your workspace administrator can grant it.',
} as const;

/** UX S-2 `NEW_SUPPLIER`: `/suppliers/new`. Fields as S-1 with `codeHint`, `countryHint`, `termsHint` and no `nameHint`. */
export const NEW_SUPPLIER = {
  /** `heading`: h1 and `<title>`. */
  heading: 'New supplier',
  /** `deniedResource`: ST-4b's noun for `/suppliers/new` without `CreateSupplier`'s codes. */
  deniedResource: 'new suppliers',
  /**
   * `currencyHintNew` AT S-2 (suppliers-final-red.md L-1): the first sentence only. The second ("This supplier's
   * catalogue costs will be in this currency.") joins at S-3, when the catalogue is on the supplier page.
   */
  currencyHintNew: 'Three letters, for example GBP.',
  /** `submit` · `cancel` (CONFIRMS). */
  submit: 'Create supplier',
  cancel: 'Cancel',
  /** `after(code)`: spoken once on the new supplier's page, focus on its h1. */
  after: (code: string) => `${code} was created.`,
  /** `interrupted(code)`: not "press Create supplier again" — the create is not replayed on its key. */
  interrupted: (code: string) => `We can't tell whether ${code} was created. Search the list for ${code} before you try again.`,
  /** `interruptedNoCode`: the code as sent is empty after trimming. */
  interruptedNoCode: "We can't tell whether the new supplier was created. Check the list of suppliers before you try again.",
} as const;
