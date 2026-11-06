// Every item screen sentence (Revision 8 R8-2), from `docs/design/items-ux-copy.md`: Revision 0 Part B, as amended by its
// "Response to RED — Revision 1" (R1-n) and "Revision 1 — FINAL" (F-n); each entry names its row. Server refusals are
// never stored here: they render as sent (Part A, UX-I1…UX-I12). Each sentence holds for the web app in the commit that
// renders it (the ST-3 action rule): I-1 has the list, the Archived view, the item page (edit) and Restore item, and no
// create page, so nothing here names **New item** or **Create item** until I-2 (R1-16).

/** B-1 (live list) and B-2 (Archived view), as amended by R1-3, R1-5, R1-6, R1-8, R1-14, R1-16, F-2. */
export const ITEMS_LIST = {
  /** B-1 nav label / h1 / title (RULED); B-2 heading **Archived items** (ST-9a). */
  heading: (archived: boolean) => (archived ? 'Archived items' : 'Items'),
  /** ST-4b's `{resource}` (B-1 403 row, RULED shape). */
  resourcePlural: 'items',
  /** B-1 header control (RULED, ST-9a) · B-2's replacement for it. */
  archived: 'Archived',
  backToList: 'Back to items',
  /** Columns, live (R1-5): SKU · Name · Unit · Preferred supplier. Archived adds **Archived on** in place of the last two. */
  sku: 'SKU',
  name: 'Name',
  unit: 'Unit',
  supplier: 'Preferred supplier',
  archivedOn: 'Archived on',
  /** R1-6: the one word for no supplier, in the list cell and on the page. */
  noSupplier: 'None',
  /** B-1 / B-2 search label, placeholder (NEW) and R1-14's hint (visible, tied to the box). */
  searchFor: (archived: boolean) => (archived ? 'Search archived items by SKU' : 'Search by SKU'),
  searchPlaceholder: 'Start of a SKU',
  searchHint: "Item names can't be searched. SKUs are matched as typed, so capitals and spaces count.",
  /** B-1 settled announcement as amended by R1-8 (the Archived view says "archived items"). Never called with 0. */
  shown: (n: number, q: string, archived: boolean) =>
    [archived ? (n === 1 ? '1 archived item shown.' : `${n} archived items shown.`) : n === 1 ? '1 item shown.' : `${n} items shown.`, q !== '' ? `SKU starts with “${q}”.` : null].filter(Boolean).join(' '),
  /** B-1 load failed (NEW). */
  loadFailed: "The list of items couldn't be loaded.",
  /** R1-16 empty states, as I-1 offers them (no Create item until I-2). */
  noneYet: 'No items yet.',
  allArchived: "No items to show. Every item here has been deleted, and they're in the archived items.",
  viewArchived: 'View archived items',
  noMatch: (q: string) => `No item's SKU starts with “${q}”.`,
  noMatchNote: "Item names can't be searched.",
  noneArchived: 'No archived items. Items that are deleted appear here and can be restored from here.',
  noArchivedMatch: (q: string) => `No archived item's SKU starts with “${q}”.`,
  clearSearch: 'Clear search',
  /** B-2 caption (ST-9a RULED with "archived"). */
  archivedCaption: 'Most recently archived first',
  /** R1-3 footer **{n} shown** (RULED): the rows on this page, never a total. */
  footer: (n: number) => `${n} shown`,
} as const;

/** The paged list's own words (R1-3 pager, R1-8 / F-2 page sentences, R1-7 / F-3 the empty page a cursor reached). */
export const ITEMS_PAGING = {
  /** R1-3: live (ordered by SKU) Previous / Next; Archived (newest archived first) Newer / Older (RULED). */
  pager: (archived: boolean) => (archived ? { previous: 'Newer', next: 'Older' } : { previous: 'Previous', next: 'Next' }),
  /** R1-8, live: **{n} items shown, {firstSku} to {lastSku}.** / **1 item shown, {sku}.** */
  liveRange: (n: number, firstSku: string, lastSku: string) => (n === 1 ? `1 item shown, ${firstSku}.` : `${n} items shown, ${firstSku} to ${lastSku}.`),
  /** F-2, Archived: dates differ / one date / one row. Dates are DT-1, formatted by the caller. */
  archivedRange: (n: number, newest: string, oldest: string, firstSku: string) => `${n} archived items shown, archived ${newest} to ${oldest}, starting with ${firstSku}.`,
  archivedOneDate: (n: number, date: string, firstSku: string) => `${n} archived items shown, archived on ${date}, starting with ${firstSku}.`,
  archivedOne: (sku: string, date: string) => `1 archived item shown, ${sku}, archived on ${date}.`,
  /** R1-8: a settled search adds B-1's sentence after the page sentence. */
  searched: (q: string) => `SKU starts with “${q}”.`,
  /** R1-7 heading and action, F-3 body. */
  noMore: (archived: boolean) => ({
    heading: archived ? 'There are no more archived items here.' : 'There are no more items here.',
    body: 'The list changed after the page you were on was loaded.',
    action: 'Back to the first page',
  }),
} as const;

/** B-3: the item page (the detail page is the editor, IA L-4), as amended by R1-1, R1-2, R1-6, F-4. */
export const ITEM_PAGE = {
  /** h1 and title: **{sku} — {name}** (NEW). */
  title: (sku: string, name: string) => `${sku} — ${name}`,
  sku: 'SKU',
  name: 'Name',
  unit: 'Unit of measure',
  supplier: 'Preferred supplier',
  reorderPoint: 'Reorder point',
  reorderQuantity: 'Reorder quantity',
  safetyStock: 'Safety stock',
  /**
   * SKU hint (NEW) + its edit-only relabel sentence (NEW, recommended; PO TO CONFIRM, IO-2). Shown only in edit: an
   * archived or read-only page shows no edit hints (B-3 archived row).
   */
  skuHint: "The code your team uses for this item. Each item needs its own, and capitals and spaces count. A new SKU also changes it on every order, receipt, shipment, invoice and stock record that already names this item.",
  /** Name hint, edit only (NEW, recommended; PO TO CONFIRM, IO-2). */
  nameHint: 'Renaming also changes the name on every order, receipt, shipment and invoice that already names this item.',
  /** Reorder point help (NEW). */
  reorderPointHint: "When the stock on hand plus what's on order falls to this number or below, the item is due to be reordered. Stock in quarantine doesn't count.",
  /** Reorder quantity help (NEW; PO TO CONFIRM the wording). */
  reorderQuantityHint: "How much to order when it's due. A larger amount is suggested if stock has fallen further than this below the reorder point.",
  /** Safety stock help (NEW; changes with PO's D-C1-7 ruling). */
  safetyStockHint: "For your records only: it doesn't change when the item is due. Include it in the reorder point.",
  /** The shared line under the three numbers (NEW). */
  numbersNote: "Leave both reorder numbers at 0 if this item isn't reordered. Use a point for decimals, for example 2.5, and no commas.",
  /** R1-9: an emptied number field on Save, beside the field; no request is sent. */
  numberRequired: 'Enter a number, or 0 for none.',
  save: 'Save',
  /** `PICKER.saveNothing`'s RULED reason, reused (B-3). */
  saveNothing: 'Nothing to save.',
  saved: (sku: string) => `${sku} was saved.`,
  /** Interrupted save (NEW, the warehouse B-3 shape). */
  interrupted: (sku: string) => `We can't tell whether your changes to ${sku} were saved. Reload the page to check.`,
  /** Re-read failed (NEW, the `rereadFailed` shape). */
  rereadFailed: "We couldn't load this item again, so what's shown may be out of date.",
  /** Archived item page banner (ST-9, RULED shape): "This item was archived on {date}." Its action is `RESTORE_ITEM.action`. */
  archivedOn: 'This item was archived on ',
  archivedAfter: '.',
} as const;

/** B-5 Restore rows + R1-17 "already live" (the W4 treatment). In the archived banner, no dialog. */
export const RESTORE_ITEM = {
  action: 'Restore item',
  after: (sku: string) => `${sku} was restored.`,
  alreadyRestored: (sku: string) => `${sku} was already restored.`,
  interrupted: (sku: string) => `We can't tell whether ${sku} was restored. Press Restore item to try again.`,
} as const;

/** B-9: lost permission mid-action (R1-5's form, RULED). The label is read from `permissionLabels.ts` by code. */
export const ITEM_FORBIDDEN = {
  before: 'This needs the ',
  after: ' permission, which you no longer have. Your workspace administrator can grant it.',
} as const;
