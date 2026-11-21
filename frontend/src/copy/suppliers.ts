// Every supplier screen sentence (Revision 8 R8-2), from `docs/design/suppliers-ux-copy.md` "Revision 1 — FINAL" S-1 (as
// corrected by `suppliers-final-red.md`), falling back to Revision 0 Part B where Revision 1 is silent; each entry names
// its row. Server refusals are never stored here: they render as sent (Part A, S1…S16). Each sentence holds for the web
// app in the commit that renders it (`STATES.md:161`): S-1 has the list, the Archived view, the supplier page (edit) and
// Restore supplier; S-2 adds the create page (UX S-2); S-3 the catalogue section (read-only); S-4 Edit on a catalogue
// entry (UX S-4, as overridden by bk31-placement-confirm.md Revision 1 — FINAL); S-5 Add to catalogue (UX S-5, as
// overridden by the same FINAL and suppliers-final-red.md M-4); S-6 Remove on a catalogue entry and Currency editable on
// the supplier page (UX S-6, with suppliers-final-red.md M-8's Remove body); S-7 Delete supplier (UX S-7 `DELETE_SUPPLIER`,
// after BK-28: its open-orders refusal is the server's sentence, shown as sent). R1-E's "With cost" unit clause shipped at S-5 without its last sentence, which names Remove; S-6, the
// commit that ships Remove, appends it (ux-r1e-confirm.md).

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
  /**
   * `currencyHint` (UX S-6, REPLACES Revision 0; RED L-13): Currency is editable from S-6 on the supplier page. ONE
   * unconditional hint that states the rule, never a verdict on this supplier's entries (D-4, ST-4f row 1 as amended);
   * edit only (a read-only page shows no hints, B-3). Before S-6 Currency was read-only text with no hint.
   */
  currencyHint: "Three letters, for example GBP. The catalogue's costs are in this currency, so it can't be changed while this supplier's catalogue has any entries.",
  /** `termsHint`. */
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

/**
 * UX S-7 `DELETE_SUPPLIER` (suppliers-ux-copy.md Revision 1 — FINAL S-7 over Revision 0 B-6; CONFIRMS the DELETE_ITEM
 * shape): the page action on a live supplier (`supplier.delete`) and its danger dialog, heading `NOT_DELETED` on a refusal.
 * The open-purchase-orders refusal (BK-28, `SupplierWriter::HAS_OPEN_PURCHASE_ORDERS`) is the server's and is never stored
 * here.
 */
export const DELETE_SUPPLIER = {
  action: 'Delete supplier',
  title: (code: string, name: string) => `Delete ${code} — ${name}?`,
  /** S-7 `.body` (REPLACES Revision 0: the reorder clause, RED L-6; PO TO CONFIRM). */
  body: "It moves to the archived suppliers. Until it's restored, it can't be chosen for new purchase orders or as an item's preferred supplier, its catalogue can't be changed, and reorder suggestions don't name it. Its catalogue entries are kept and come back with it. Items and purchase orders that already name it keep showing it.",
  confirm: 'Delete supplier',
  cancel: 'Cancel',
  /** ST-9b: after a refusal other than P1, a 403 or an interrupted press, the Delete button is removed and only this remains. */
  close: 'Close',
  after: (code: string) => `${code} was deleted. It's now in the archived suppliers.`,
  /** S-2: after a 204 whose re-read commits a LIVE supplier (restored in between). REPLACES `after` for that case only. */
  afterRestored: (code: string) => `${code} was deleted, and has since been restored.`,
  interrupted: (code: string) => `We can't tell whether ${code} was deleted. Press Delete supplier to try again.`,
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
   * `currencyHintNew` (suppliers-ux-copy.md Revision 1 S-2, full text from S-3 per suppliers-final-red.md L-1: S-2 shipped
   * the first sentence only, before the catalogue was on the supplier page).
   */
  currencyHintNew: "Three letters, for example GBP. This supplier's catalogue costs will be in this currency.",
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

/**
 * UX S-3 `CATALOGUE` (suppliers-ux-copy.md Revision 1 — FINAL S-3 over Revision 0 B-7; suppliers-final-red.md M-1, M-2),
 * with S-4's columns (bk31-placement-confirm.md Revision 1 — FINAL R1-C) and row action (UX S-4 `CATALOGUE.edit`,
 * `.actions`), S-5's Add (`addItem`, `emptyNote`) and S-6's row Remove (`remove`): the catalogue section on the supplier
 * page.
 */
export const CATALOGUE = {
  /** `heading` (h2). On the supplier's own page "Catalogue" needs no owner. */
  heading: 'Catalogue',
  /**
   * Columns, in order (R1-C): Supplier SKU · Item · Unit cost · Lead time · Minimum order · Unit · Actions (Actions only
   * when the viewer may Edit or Remove). The minimum order is shown bare; the Unit cell after it is the entry's own served
   * code (`EA`), so the header is back to *Minimum order* (final RED P-2).
   */
  supplierSku: 'Supplier SKU',
  item: 'Item',
  unitCost: 'Unit cost',
  leadTime: 'Lead time',
  minimumOrder: 'Minimum order',
  unit: 'Unit',
  /** `actions` (column header; a header is never empty, `LOCATIONS_SECTION.actions` shape) · `edit` (row action). */
  actions: 'Actions',
  edit: 'Edit',
  /** UX S-6 `CATALOGUE.remove` (row action; its accessible name adds the item's SKU, the `edit` shape). */
  remove: 'Remove',
  /** Item cell: `{sku} — {name}` (the link text when the viewer may open the item). */
  itemCell: (sku: string, name: string) => `${sku} — ${name}`,
  /** Lead time cell: `{n} days` · `1 day` · `0 days`. */
  days,
  /**
   * `count(n)` (final RED M-2): spoken only when the section's first SUCCESSFUL load happens in the browser — a Retry
   * after a failed first load. Never on first paint, never on a re-read.
   */
  count: (n: number) => (n === 1 ? '1 item in this catalogue.' : `${n} items in this catalogue.`),
  empty: "This supplier's catalogue is empty.",
  /** UX S-5 `addItem` (Revision 0 X-5; final RED R-3): the section header's and the empty state's action. */
  addItem: 'Add to catalogue',
  /** UX S-5 `emptyNote` (moved from S-3: it names Add): under `empty`, only for a viewer who may Add. */
  emptyNote: "Add the items this supplier sells you, with what each costs and how long it takes to arrive. Only items in a supplier's catalogue can go on that supplier's purchase orders.",
  /** `partial(n)` (L-11): the read answered with a non-null `next_cursor`; shown above the rows, which stay. */
  partial: (n: number) => `Only the first ${n} items in this catalogue are shown.`,
  loadFailed: "The catalogue couldn't be loaded.",
  denied: "You don't have access to this supplier's catalogue.",
  /**
   * `archivedParent` FROM S-4 (suppliers-final-red.md M-1, REPLACES the S-3 text): Edit ships here, so restoring the
   * supplier does make its catalogue changeable in the app.
   */
  archivedParent: "This catalogue can't be changed while the supplier is archived. It comes back unchanged when the supplier is restored.",
} as const;

/**
 * UX S-4 `EDIT_CATALOGUE_ITEM` (suppliers-ux-copy.md Revision 1 — FINAL S-4 over Revision 0 B-8, as overridden by
 * bk31-placement-confirm.md Revision 1 — FINAL: R1-B `minimumOrderHint`, the ruled per-unit `unitCostHint` (judge ruling 1
 * as applied, C-1), the Unit cost `per {code}` suffix (R1-D, per-unit branch)): the row's Edit dialog. Heading on a
 * refusal: `NOT_SAVED`. `{sku}` in every sentence is the ITEM's SKU (the person's own code), never the supplier SKU.
 */
export const EDIT_CATALOGUE_ITEM = {
  /** `title(sku, name)`. */
  title: (sku: string, name: string) => `Edit ${sku} — ${name} in this catalogue`,
  /** The item line, shown as text (the item cannot change on an entry). */
  itemLine: (sku: string, name: string) => `Item: ${sku} — ${name}`,
  /**
   * UX S-6 `itemNote` (moved from S-4: it names Remove, ux-copy Response M-2): its own paragraph after the item line, in
   * the dialog's description.
   */
  itemNote: 'To list a different item, add it and remove this one.',
  supplierSku: 'Supplier SKU',
  supplierSkuHint: 'The code this supplier uses for the item. If it has none, use your own SKU.',
  /** `unitCost(currency)`: the entry's own currency. */
  unitCost: (currency: string) => `Unit cost (${currency})`,
  /** The Unit cost field's shown suffix (R1-D): `per {code}`, the row's served unit code. */
  perUnit: (code: string) => `per ${code}`,
  /** `unitCostHint`, ruled (cost basis = one of the item's units; judge ruling 1, applied per C-1). */
  unitCostHint: "What this supplier charges for one of the item's units. Use a point for decimals and no commas, for example 12.50.",
  leadTime: 'Lead time (days)',
  leadTimeHint: 'How many days the goods usually take to arrive after you order. New purchase order lines take it from here; lines already on an order keep theirs.',
  minimumOrder: 'Minimum order quantity',
  /** `minimumOrderHint` = R1-B (final RED P-5's last sentence). */
  minimumOrderHint: "The smallest amount this supplier accepts in one order, in the item's unit. If this is the item's preferred supplier, reorder suggestions never suggest less than this. Purchase orders don't check it. Enter 1 if there's no minimum.",
  /** Client sentences for an emptied field on Save; no request is sent. */
  costRequired: 'Enter the unit cost, or 0.',
  leadTimeRequired: 'Enter a number of days, or 0.',
  minimumRequired: "Enter the minimum order, or 1 if there's none.",
  save: 'Save',
  cancel: 'Cancel',
  /** After a refusal that leaves nothing to press (C1, a 404): the dialog's one button (UX S-4 "Close only"). */
  close: 'Close',
  saveNothing: 'Nothing to save.',
  /** `saved(sku)`: the dialog closes, focus back on the row's Edit. */
  saved: (sku: string) => `${sku} was saved.`,
  /** `savedListStale(sku)`: replaces `saved` only when the section's re-read failed and nothing else wrote the region since. */
  savedListStale: (sku: string) => `${sku} was saved. We couldn't load the list again, so it may be out of date.`,
  interrupted: (sku: string) => `We can't tell whether your changes to ${sku} were saved. Close this and check the catalogue before you try again.`,
} as const;

/**
 * UX B-8 "Field words, shared by both dialogs" as they stand after S-4 (the ruled hints): the four fields of Add (S-5)
 * read the SAME words as Edit's, from Edit's table, so the two cannot drift (suppliers-ux-copy.md Revision 1 — FINAL S-5
 * "fields: as S-4"; R1-B: "S-4 Edit; S-5 Add identical").
 */
export const ENTRY_FIELD_WORDS = {
  supplierSku: EDIT_CATALOGUE_ITEM.supplierSku,
  supplierSkuHint: EDIT_CATALOGUE_ITEM.supplierSkuHint,
  unitCost: EDIT_CATALOGUE_ITEM.unitCost,
  perUnit: EDIT_CATALOGUE_ITEM.perUnit,
  unitCostHint: EDIT_CATALOGUE_ITEM.unitCostHint,
  leadTime: EDIT_CATALOGUE_ITEM.leadTime,
  leadTimeHint: EDIT_CATALOGUE_ITEM.leadTimeHint,
  minimumOrder: EDIT_CATALOGUE_ITEM.minimumOrder,
  minimumOrderHint: EDIT_CATALOGUE_ITEM.minimumOrderHint,
  costRequired: EDIT_CATALOGUE_ITEM.costRequired,
  leadTimeRequired: EDIT_CATALOGUE_ITEM.leadTimeRequired,
  minimumRequired: EDIT_CATALOGUE_ITEM.minimumRequired,
} as const;

/**
 * UX S-5 `ITEM_CHOICES` (suppliers-ux-copy.md Revision 1 — FINAL S-5, with suppliers-final-red.md M-4's three keys
 * `shown`, `allArchived`, `denied`): the paged choice list inside the Add dialog. Live items only (no Archived view).
 */
export const ITEM_CHOICES = {
  /** `heading` (the list's own heading inside the dialog). */
  heading: 'Choose an item',
  /** `search` · `.placeholder` · `.hint` (CONFIRMS `ITEMS_LIST`). */
  search: 'Search items by SKU',
  placeholder: 'Start of a SKU',
  hint: "Item names can't be searched. SKUs are matched as typed, so capitals and spaces count.",
  /** `archivedNote`: one line above the rows. */
  archivedNote: "Archived items aren't listed.",
  /** Columns: SKU · Name · Unit · Choose ("Choose" is the header so the header cell is never empty). */
  sku: 'SKU',
  name: 'Name',
  unit: 'Unit',
  choose: 'Choose',
  /** The row button's accessible name. */
  chooseItem: (sku: string) => `Choose ${sku}`,
  /**
   * M-4 `shown(n, q)`: the settled announcement (never called with 0). q empty: "{n} items shown." · "1 item shown."; q
   * not empty: the same, then "SKU starts with “{q}”." (the `ITEMS_PAGING.searched` text).
   */
  shown: (n: number, q: string) => [n === 1 ? '1 item shown.' : `${n} items shown.`, q !== '' ? `SKU starts with “${q}”.` : null].filter(Boolean).join(' '),
  /**
   * `pageShown(n, first, last)` (CONFIRMS `ITEMS_PAGING.liveRange`): after a page read by its cursor. A settled search adds
   * the `searched` sentence after it (the ItemsList precedent, rule 6: a sentence for a non-empty query names it).
   */
  pageShown: (n: number, first: string, last: string) => (n === 1 ? `1 item shown, ${first}.` : `${n} items shown, ${first} to ${last}.`),
  searched: (q: string) => `SKU starts with “${q}”.`,
  /** pager · `noMore` (CONFIRMS). */
  pager: { previous: 'Previous', next: 'Next' },
  noMore: { heading: 'There are no more items here.', body: 'The list changed after the page you were on was loaded.', action: 'Back to the first page' },
  /** `noneYet` (only when `tenant_has_rows` is false) · M-4 `allArchived` (live view empty, no search, `tenant_has_rows` true). */
  noneYet: 'No items yet.',
  allArchived: 'No items to choose from. Every item here is archived.',
  /** `noMatch(q)` (CONFIRMS), with **Clear search** (final RED L-3: "Clear search is the way to empty the search"). */
  noMatch: (q: string) => `No item's SKU starts with “${q}”.`,
  clearSearch: 'Clear search',
  loadFailed: "The items couldn't be loaded.",
  /**
   * The ST-1 skeleton's name while a Change's read is in flight (UX `ux-r1e-confirm.md` DV-8 FIX; NEW,
   * unattacked). Its hidden text is `PAGE_STATES.loading` (*Loading…*, CONFIRMED by UX for this use).
   */
  loading: 'Loading items',
  /** M-4 `denied` (CONFIRMS `SUPPLIER_FORBIDDEN`): the label is ListItems' gate code's, read by the caller. */
  denied: SUPPLIER_FORBIDDEN,
} as const;

/** The four clauses of Add's after-sentence (R1-E's order), each once; `ADD_CATALOGUE_ITEM` names them. */
const ADD_AFTER = (sku: string) => `${sku} was added to the catalogue.`;
const ADD_CURRENCY = (currency: string) => `Its cost is recorded in ${currency}, because this supplier's currency changed.`;
const ADD_UNIT = (label: string) =>
  `The item's unit was changed to ${label} while you were adding it, so the minimum order and unit cost you entered are now in that unit. Check them and edit the entry if they're wrong. If it's the unit that's wrong, remove the entry first: an item's unit can't be changed while a catalogue lists it.`;
const ADD_STALE = "We couldn't load the list again, so it may be out of date.";
const addComposed = (sku: string, { currency, unitLabel, stale }: { currency: string | null; unitLabel: string | null; stale: boolean }) =>
  [ADD_AFTER(sku), currency !== null ? ADD_CURRENCY(currency) : null, unitLabel !== null ? ADD_UNIT(unitLabel) : null, stale ? ADD_STALE : null].filter((p): p is string => p !== null).join(' ');

/**
 * UX S-5 `ADD_CATALOGUE_ITEM` (suppliers-ux-copy.md Revision 1 — FINAL S-5, as overridden by bk31-placement-confirm.md
 * Revision 1 — FINAL (a) R1-E "With cost" and FINAL (e) item 10): the Add dialog. Heading on a refusal: `NOT_ADDED`.
 * `{sku}` is the ITEM's SKU. The after-sentence is ONE composition, in a fixed order: `after(sku)` · `currencyChangedClause`
 * (the 201's currency is not the one the dialog labelled) · `unitChangedClause` (the 201's unit is not the chosen item's) ·
 * `listStaleClause` (the section's re-read failed; written only through `replaceIfCurrent`). `afterCurrencyChanged` and
 * `afterListStale` are compositions of these, their visible words unchanged.
 */
export const ADD_CATALOGUE_ITEM = {
  /** `title(code)`: the supplier's code. */
  title: (code: string) => `Add an item to ${code}'s catalogue`,
  /** The chosen line and its `change` button (accessible name *Change item*). */
  itemLine: (sku: string, name: string) => `Item: ${sku} — ${name}`,
  change: 'Change',
  changeItem: 'Change item',
  /** `itemRequired` (client; no request). */
  itemRequired: 'Choose an item for this catalogue.',
  ...ENTRY_FIELD_WORDS,
  submit: 'Add to catalogue',
  cancel: 'Cancel',
  /** After a refusal that leaves nothing to press (C1, a 404): the dialog's one button (the S-4 "Close only" shape). */
  close: 'Close',
  after: ADD_AFTER,
  /** RED M-9 "recorded in": the same words as `afterCurrencyChanged`'s second sentence. */
  currencyChangedClause: ADD_CURRENCY,
  /**
   * R1-E "With cost" (FINAL (a), C-4), whole from S-6 — S-5 shipped its first two sentences and S-6, which ships Remove,
   * appends the third (r1e-split-decision.md option A, UX CONFIRMED in ux-r1e-confirm.md); `{label}` = `unitLabel(code)`
   * of the 201's unit, given by the caller (a copy file imports no copy file).
   */
  unitChangedClause: ADD_UNIT,
  /** The same words as `afterListStale`'s second sentence (`WAITING.listStale`). */
  listStaleClause: ADD_STALE,
  /** The one composition (FINAL (a) R1-E order). `currency` / `unitLabel` are null when that clause does not apply. */
  afterComposed: addComposed,
  /** UX `afterCurrencyChanged(sku, currency)` and `afterListStale(sku)`, now compositions (bk31-placement-confirm.md §2.3). */
  afterCurrencyChanged: (sku: string, currency: string) => addComposed(sku, { currency, unitLabel: null, stale: false }),
  afterListStale: (sku: string) => addComposed(sku, { currency: null, unitLabel: null, stale: true }),
  interrupted: (sku: string) => `We can't tell whether ${sku} was added. Check this supplier's catalogue before you try again.`,
} as const;

/**
 * UX S-6 `REMOVE_CATALOGUE_ITEM` (suppliers-ux-copy.md Revision 1 — FINAL S-6 over Revision 0 B-9; the body as
 * prescribed by suppliers-final-red.md M-8, sentences 2–3 replaced): the row's Remove dialog (the shared Delete dialog,
 * danger). Heading on a refusal: `NOT_REMOVED`. `{sku}` is the ITEM's SKU. No `afterRestored`: a removed entry is
 * deleted for good and never comes back under its id (UX B-9 "decided").
 */
export const REMOVE_CATALOGUE_ITEM = {
  /** `title(sku, name)`. */
  title: (sku: string, name: string) => `Remove ${sku} — ${name} from this catalogue?`,
  /** `body` (final RED M-8, full text). */
  body: "The entry is deleted for good, not archived, so it can't be restored. Until it's added again, no new purchase-order line for the item can be added for this supplier. Purchase orders that already list it, drafts included, keep their own cost and lead time. If this is the item's preferred supplier, reorder suggestions stop using these terms.",
  confirm: 'Remove',
  cancel: 'Cancel',
  /** After a refusal that leaves nothing to press (ST-9b). */
  close: 'Close',
  /** `after(sku)`: on the 204 (focus on the section heading, spoken at once). */
  after: (sku: string) => `${sku} was removed from the catalogue.`,
  /** `afterListStale(sku)`: replaces `after` only when the section's re-read failed and nothing else wrote the region since. */
  afterListStale: (sku: string) => `${sku} was removed from the catalogue. We couldn't load the list again, so it may be out of date.`,
  /** `interrupted(sku)`: not "press Remove to try again" — a repeat after a removal answers 404. */
  interrupted: (sku: string) => `We can't tell whether ${sku} was removed. Close this and check the catalogue.`,
} as const;
