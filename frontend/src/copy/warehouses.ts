// Every warehouse screen sentence (Revision 7 A-18), from `docs/design/warehouses-ux-copy.md` Part B + Revision 1 (as
// attacked by `warehouses-ux-copy-red.md`); each entry names its row. Server refusals are never stored here: they render
// as sent (Part A). Each sentence holds for the web app in the commit that renders it (`STATES.md:140`): the app has
// Restore warehouse (§2 step 4.2), Create (§2 step 4.3), the Locations section with Edit and Restore location (§2 step
// 4.4), Add location with the Kind select (§2 step 4.5), and Delete warehouse / Delete location (§2 step 4.6).

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
  /** B-1 header action (`warehouse.create`, RULED §3 New/Create) → `/warehouses/new`. */
  newWarehouse: 'New warehouse',
  /** B-1 empty-state action (live, no search; with `warehouse.create`). */
  createWarehouse: 'Create warehouse',
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

/** B-7 Delete warehouse (RULED, U-7 / ST-9b): the page action, its dialog, and what it says after. Heading `NOT_DELETED`. */
export const DELETE_WAREHOUSE = {
  action: 'Delete warehouse',
  title: (code: string, name: string) => `Delete ${code} — ${name}?`,
  body: "It moves to the archived warehouses. Its locations can't be used, and returns of goods shipped from it can't be recorded, until it's restored. Orders, receipts and shipments that already name it keep showing it.",
  confirm: 'Delete warehouse',
  cancel: 'Cancel',
  /** ST-9b: after a refusal other than P1 the Delete button is removed and only this remains. */
  close: 'Close',
  after: (code: string) => `${code} was deleted. It's now in the archived warehouses.`,
  interrupted: (code: string) => `We can't tell whether ${code} was deleted. Press Delete warehouse to try again.`,
} as const;

/** B-7 Delete location (RULED, U-7 / ST-9b): the row action, its dialog, and what it says after. Heading `NOT_DELETED`. */
export const DELETE_LOCATION = {
  action: 'Delete',
  title: (code: string) => `Delete location ${code}?`,
  body: "It moves to this warehouse's archived locations and can't be used for receiving, shipping, counting or moving stock until it's restored. Movements and documents that already name it keep showing it.",
  confirm: 'Delete location',
  cancel: 'Cancel',
  close: 'Close',
  after: (code: string) => `${code} was deleted. It's now in this warehouse's archived locations.`,
  interrupted: (code: string) => `We can't tell whether ${code} was deleted. Press Delete location to try again.`,
} as const;

/** B-8: lost permission mid-action (R1-5's form, RULED). The label is read from `permissionLabels.ts` by code (L-9). */
export const WAREHOUSE_FORBIDDEN = {
  before: 'This needs the ',
  after: ' permission, which you no longer have. Your workspace administrator can grant it.',
} as const;

/** B-4: `/warehouses/new`. Fields are B-3's Code and Country hints; no Name hint (G-43: nothing names it yet). */
export const NEW_WAREHOUSE = {
  /** h1 and title (NEW). */
  heading: 'New warehouse',
  /** ST-4b heading noun for `/warehouses/new` without `warehouse.create` (D-3, UX TO CONFIRM). */
  deniedResource: 'new warehouses',
  /** Submit · cancel (RULED §3). */
  submit: 'Create warehouse',
  cancel: 'Cancel',
  /** After (NEW): spoken on the new warehouse's page (FE-LEAD's call, UX TO CONFIRM). */
  after: (code: string) => `${code} was created.`,
  /** Interrupted (NEW): not "press Create to try again" — the create does not replay on its key. */
  interrupted: (code: string) => `We can't tell whether ${code} was created. Check the list of warehouses before you try again.`,
} as const;

/**
 * A-21: one kind's words. From §2 step 4.5 `optionHelp` shows under the Kind select for the chosen kind and `editHint`
 * in Edit location for the served current kind; `sectionNote` is not rendered (R3-7 "Silent": not decided, step 4.5 D-4).
 */
export interface KindCopy {
  label: string;
  optionHelp?: string;
  editHint?: string;
  sectionNote?: string;
}

/**
 * B-5 kind labels, keyed by the served value (`LocationKinds::ALL`). A served value with no entry renders as sent. The
 * keys are held equal to the kinds' list by `test/warehouses/kinds.test.ts` (A-21). Kind-dependent copy is chosen through
 * an entry, never by comparing a kind value in code. The quarantine entry carries B-5's option help, B-6's Edit hint and
 * B-5's under-table note. The parity test holds the keys equal to the served enum (`wireEnum`, §2 step 4.5).
 */
export const KIND_LABELS: Readonly<Record<string, KindCopy>> = {
  bin: { label: 'Bin' },
  shelf: { label: 'Shelf' },
  zone: { label: 'Zone' },
  staging: { label: 'Staging' },
  quarantine: {
    label: 'Quarantine',
    optionHelp: "Stock here is on hold: it's never picked for a shipment.",
    editHint: 'If this location holds stock, only an owner can take it out of quarantine.',
    sectionNote: "Stock in a quarantine location is on hold: it's never picked for a shipment.",
  },
};

/** The label for a served kind (B-5: a value with no entry renders as sent). */
export const kindLabel = (kind: string): string => KIND_LABELS[kind]?.label ?? kind;

/** B-5: the Locations section on the warehouse page (§2 steps 4.4, 4.5). */
export const LOCATIONS_SECTION = {
  /** h2 (NEW) · Archived view heading (NEW). */
  heading: (archived: boolean) => (archived ? 'Archived locations' : 'Locations'),
  /** Section control (RULED, ST-9b) · Back to… (RULED). */
  archived: 'Archived',
  backToList: 'Back to locations',
  /** Columns (NEW). */
  code: 'Code',
  kind: 'Kind',
  /** The row-action column's header (NEW, placeholder: UX TO CONFIRM; a header cell is never empty). */
  actions: 'Actions',
  /** Section action and live empty-state action (`storage_location.create`, RULED ST-9b), from §2 step 4.5. */
  addLocation: 'Add location',
  /** Row actions: Edit (`storage_location.edit`, NEW) · Archived view: Restore location (RULED). */
  edit: 'Edit',
  /** Empty (live), RULED: the heading **+ Add location** (if held; step 4.5 ends 4.4's heading-alone interim). */
  noneLive: 'No locations in this warehouse yet.',
  /** Empty (Archived), RULED. */
  noneArchived: 'No archived locations in this warehouse.',
  /** Section load failed (NEW). */
  loadFailed: "The locations couldn't be loaded.",
  /** Settled announcement (NEW; FE-LEAD: the section announces only on its own switch, A-17 item 10). */
  shown: (n: number, q: string, archived: boolean): string => {
    void q; // the section has no search (B-5)
    return [n === 1 ? '1 location shown.' : `${n} locations shown.`, archived ? 'Archived.' : null].filter(Boolean).join(' ');
  },
  /** ST-4b sized for the section, for a 403 on its read (A-20 L-7). NEW, placeholder: UX TO CONFIRM. */
  denied: "You don't have access to this warehouse's locations.",
  /** ST-9b: the archived warehouse's section, live view (RULED). */
  archivedParentLive: "These locations can't be used while the warehouse is archived. They'll be back in use as soon as it's restored.",
  /** ST-9b: the archived warehouse's section, Archived view (RULED). */
  archivedParentArchived: 'These can be restored once the warehouse is restored.',
} as const;

/** B-5 Edit location (dialog): Code and Kind (§2 step 4.5); the Kind hint comes from the served current kind's entry. */
export const EDIT_LOCATION = {
  /** Title (NEW). */
  title: (code: string) => `Edit location ${code}`,
  code: 'Code',
  /** Code hint, G-43 half (ii) (NEW, recommended; PO TO CONFIRM). */
  codeHint: 'A new code also changes it everywhere this location is already named, including receipts, shipments, returns and its stock history.',
  kind: 'Kind',
  save: 'Save',
  cancel: 'Cancel',
  saveNothing: 'Nothing to save.',
  /** After (NEW). */
  saved: (code: string) => `${code} was saved.`,
  /** Interrupted (NEW): closing the dialog re-reads the section (L-8). */
  interrupted: (code: string) => `We can't tell whether your changes to ${code} were saved. Close this and check the location before you try again.`,
} as const;

/** ST-9b Restore location (RULED action and after; interrupted sentence NEW on RESTORE_WAREHOUSE's shape, UX TO CONFIRM). */
export const RESTORE_LOCATION = {
  action: 'Restore location',
  after: (code: string) => `${code} was restored.`,
  interrupted: (code: string) => `We can't tell whether ${code} was restored. If it's still listed here, press Restore location to try again.`,
  /** On every other row's Restore location while one restore is in flight (NEW, placeholder: UX TO CONFIRM; RED L-6). */
  busyOther: 'Another location is being restored. Wait for it to finish.',
} as const;

/** B-5 Add location (dialog, §2 step 4.5). All NEW. The refusal heading is `NOT_ADDED` (copy/headings.ts). */
export const ADD_LOCATION = {
  /** Title (NEW). */
  title: (warehouseCode: string) => `Add a location to ${warehouseCode}`,
  code: 'Code',
  /** Code hint (NEW). */
  codeHint: 'Each location in this warehouse needs its own code.',
  kind: 'Kind',
  /** The Kind select's empty first option (UX FINAL, `kind-preselection-ux.md`): no kind is preselected (A-21, S-7). */
  kindPrompt: 'Choose a kind',
  /** Client refusal beside Kind when Add location is pressed with nothing chosen; no request is sent (UX FINAL, same file). */
  kindRequired: 'Choose a kind for this location.',
  /** Submit · cancel (NEW). */
  submit: 'Add location',
  cancel: 'Cancel',
  /** After (NEW). */
  after: (code: string) => `${code} was added.`,
  /** Interrupted (NEW, B-4's reason: the create does not replay). Closing the dialog re-reads the section. */
  interrupted: (code: string) => `We can't tell whether ${code} was added. Check this warehouse's locations before you try again.`,
} as const;
