// Every warehouse screen sentence (Revision 7 A-18), from `docs/design/warehouses-ux-copy.md` Part B + Revision 1 (as
// attacked by `warehouses-ux-copy-red.md`); each entry names its row. Server refusals are never stored here: they render
// as sent (Part A). Each sentence holds for the web app in the commit that renders it (`STATES.md:140`): the app has
// Restore warehouse (§2 step 4.2) but no Create, Delete or Locations yet, so no sentence here offers those.

/** B-1 (live list), B-2 (Archived view). */
export const WAREHOUSES_LIST = {
  /** B-1 nav label / h1 / title (RULED); B-2 heading **Archived warehouses** (RULED, ST-9b). */
  heading: (archived: boolean) => (archived ? 'Archived warehouses' : 'Warehouses'),
  /** ST-4b's `{resource}` (B-1 403 row). */
  resourcePlural: 'warehouses',
  /** B-1 header control (RULED, ST-9b). */
  archived: 'Archived',
  /** B-2: replaces the header action in the Archived view (RULED, ST-9b). */
  backToList: 'Back to warehouses',
  /** B-1 columns (NEW); B-2 adds **Archived on**. */
  code: 'Code',
  name: 'Name',
  country: 'Country',
  archivedOn: 'Archived on',
  /** B-1 search (NEW) · B-2 archived label. Client-side, case-insensitive prefix on code or name; `q` is not sent (L-2). */
  searchFor: (archived: boolean) => (archived ? 'Search archived warehouses by code or name' : 'Search by code or name'),
  searchPlaceholder: 'Start of a code or name',
  /** B-1 settled announcement (NEW, the USERS_LIST.shown shape). Never called with 0: the empty heading is spoken instead. */
  shown: (n: number, q: string, archived: boolean) =>
    [n === 1 ? '1 warehouse shown.' : `${n} warehouses shown.`, q !== '' ? `Code or name starts with “${q}”.` : null, archived ? 'Archived.' : null].filter(Boolean).join(' '),
  /** B-1 load failed (NEW). */
  loadFailed: "The list of warehouses couldn't be loaded.",
  /** B-1 empty states (ST-2). */
  noneYet: 'No warehouses yet.',
  allArchived: "No warehouses to show. Every warehouse here has been deleted, and they're in the archived warehouses.",
  viewArchived: 'View archived warehouses',
  noMatch: (q: string) => `No warehouse's code or name starts with “${q}”.`,
  /** B-1 archived/no-search row (RULED, U-6). True from §2 step 4.2, which gives the archived page Restore warehouse. */
  noneArchived: 'No archived warehouses. Warehouses that are deleted appear here and can be restored from here.',
  noArchivedMatch: (q: string) => `No archived warehouse's code or name starts with “${q}”.`,
  clearSearch: 'Clear search',
} as const;

/** B-3: the warehouse page (the detail page is the editor, IA L-4). */
export const WAREHOUSE_PAGE = {
  /** h1 and title: **{code} — {name}** (NEW, U-7's dialog form). */
  title: (code: string, name: string) => `${code} — ${name}`,
  code: 'Code',
  name: 'Name',
  country: 'Country',
  /** Code hint (edit; NEW). */
  codeHint: 'A short code people will recognise, for example MAIN. Each warehouse needs its own.',
  /** Name hint (edit only; G-43 half (i); NEW, recommended, PO TO CONFIRM). */
  nameHint: 'Renaming changes the name shown on orders and pick lists that already name this warehouse.',
  /** Country hint (NEW, M-2 default). */
  countryHint: 'Two letters, for example GB.',
  save: 'Save',
  /** `PICKER.saveNothing`'s RULED reason, reused (B-3). */
  saveNothing: 'Nothing to save.',
  saved: (code: string) => `${code} was saved.`,
  /** Interrupted save (NEW, the INTERRUPTED shape R1-3). */
  interrupted: (code: string) => `We can't tell whether your changes to ${code} were saved. Reload the page to check.`,
  /** Re-read failed (NEW, the ROLE_PAGE.rereadFailed shape). */
  rereadFailed: "We couldn't load this warehouse again, so what's shown may be out of date.",
  /** Archived warehouse page banner (RULED, ST-9b). Its **Restore warehouse** action is `RESTORE_WAREHOUSE.action`. */
  archivedOn: 'This warehouse was archived on ',
  archivedAfter: '.',
} as const;

/** B-7 restore rows (RULED, U-7 / ST-9b): in the archived banner, no dialog. */
export const RESTORE_WAREHOUSE = {
  action: 'Restore warehouse',
  after: (code: string) => `${code} was restored.`,
  interrupted: (code: string) => `We can't tell whether ${code} was restored. Press Restore warehouse to try again.`,
} as const;

/** B-8: lost permission mid-action (R1-5's form, RULED). The label is read from `permissionLabels.ts` by code (L-9). */
export const WAREHOUSE_FORBIDDEN = {
  before: 'This needs the ',
  after: ' permission, which you no longer have. Your workspace administrator can grant it.',
} as const;
