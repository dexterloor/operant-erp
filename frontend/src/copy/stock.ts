// Every stock-family screen sentence (Revision 8 R8-2; b4-architecture.md Revision 11 R11-1: one `stock` family), from
// `docs/design/stock-ux-copy.md` Revision 0 as amended by `stock-st1-red.md` (which wins for the keys it names: `clear`,
// `noneForThisItem`, the paging cue, `rowLinkName`, the `inventory_item_id` address). Each entry names its key. Stock ST-1
// is reading only: `/stock-movements` and `/stock-movements/[id]`. Nothing here offers a count or a move of stock (G-51):
// a past count or move is named as a fact, never as an action. No sentence says or implies that stock is available,
// pickable or in stock (G-7, G-47's R-4). The unit is not served on a movement yet (BK-41): until it is, the Change heading
// says the figure is in the item's unit (`changeInItemUnit`, owner Q-ST1).
// Stock ST-2 (`stock-st2-ux-copy.md` Revision 1 + 1a) adds Stock on hand (`/stock`): its badges, its hint and the page
// shown when a marker permission is missing. No stock-on-hand sentence says a location's stock can be picked or is free;
// a badge says what a location IS (quarantine, archived), and Status unknown says a check did not load. This file never
// names an operation: its importers include the movement pages, which must reach no stock-on-hand file (G-47).
// This file imports only lib/api types.

/** One reason's words: `label` is shown, `spoken` goes inside a composed accessible name. */
export interface ReasonWords {
  readonly label: string;
  readonly spoken: string;
}

/**
 * UX B-1 `STOCK_MOVEMENT_REASON`, keyed by the served `reason` (the entity's six; no enum is served). *Shipped*, not
 * "Fulfilled" (that word is the order's segment, TB-4; PO confirmed 2026-11-19). `other` is the fallback for any other value: never
 * the raw value, which is an internal code.
 */
export const STOCK_MOVEMENT_REASON = {
  receipt: { label: 'Received', spoken: 'received' },
  fulfilment: { label: 'Shipped', spoken: 'shipped' },
  return: { label: 'Returned', spoken: 'returned' },
  adjustment: { label: 'Count correction', spoken: 'count correction' },
  transfer_out: { label: 'Moved out', spoken: 'moved out' },
  transfer_in: { label: 'Moved in', spoken: 'moved in' },
  other: { label: 'Other change', spoken: 'other change' },
} as const satisfies Record<string, ReasonWords>;

/** The words for a served `reason`, or `other`'s (never the raw value). */
export function reasonWords(reason: string): ReasonWords {
  return reason !== 'other' && Object.hasOwn(STOCK_MOVEMENT_REASON, reason) ? STOCK_MOVEMENT_REASON[reason as keyof typeof STOCK_MOVEMENT_REASON] : STOCK_MOVEMENT_REASON.other;
}

/**
 * UX B-2 `STOCK_MOVEMENT_SOURCE` (the keys of `lib/stock/source.ts`'s `SourceWord`): a word, never a dash, never a number
 * the viewer has not read, never an id. `receiptWithNumber`, `movedTo` and `movedFrom` are the movement page's, after its
 * one allowed read.
 */
export const STOCK_MOVEMENT_SOURCE = {
  goodsReceipt: 'Goods receipt',
  fulfilment: 'Fulfilment',
  customerReturn: 'Customer return',
  matchingMoveIn: 'Matching move in',
  matchingMoveOut: 'Matching move out',
  none: 'None',
  other: 'Other',
  receiptWithNumber: (number: string) => `Goods receipt ${number}`,
  movedTo: (bin: string) => `Moved to ${bin}`,
  movedFrom: (bin: string) => `Moved from ${bin}`,
} as const;

/** UX B-3 `STOCK_MOVEMENTS_LIST` (`/stock-movements`). */
export const STOCK_MOVEMENTS_LIST = {
  /** `heading` (CONFIRMS nav): h1 and `<title>`. */
  heading: 'Stock movements',
  /** ST-4b's `{resource}`: *"You don't have access to stock movements."* */
  resourcePlural: 'stock movements',
  /** Columns: Date · What happened · Item · Location · Change · Source (no Unit column until BK-41; owner Q-ST1). */
  date: 'Date',
  whatHappened: 'What happened',
  item: 'Item',
  location: 'Location',
  /** `changeInItemUnit` (the interim heading, list and page alike, until BK-41 serves the unit; owner Q-ST1). */
  changeInItemUnit: "Change (in the item's unit)",
  source: 'Source',
  /** `itemCell(sku, name)`: the purchasing shape, redefined here (A-3 forbids importing another family's copy). */
  itemCell: (sku: string, name: string) => `${sku} — ${name}`,
  /** `caption` (the served order, through the list's one `notice` slot). */
  caption: 'Most recent first',
  /** `locationNote` (always shown above the table; M-4 no warehouse, R-4 no quarantine marker). */
  locationNote: "Locations are shown by their code only. A code doesn't say which warehouse the location is in, or whether it's a quarantine location, where stock is on hold.",
  /**
   * `rowLinkName(when, spoken, change, sku, bin)`: the Date link's accessible name, starting with its visible text
   * (stock-st1-red.md L-3). Limit, stated: two rows in the same rendered minute with the same item,
   * location and change read alike.
   */
  rowLinkName: (when: string, spoken: string, change: string, sku: string, bin: string) => `${when}, ${spoken} ${change}, ${sku} in ${bin}`,
  /**
   * `sourceLinkName(word, sku, when)`: a Source link's accessible name (many rows say "Goods receipt"). Limit, stated (commit
   * RED L-8; UX `stock-st1-ux-confirm.md` ruling 7): two Source links read alike when their rows share the source word, the
   * SKU and the rendered minute. They may then open different records: two goods receipts (the list carries no receipt
   * number), or the matching legs of two transfers of the same item. Two lines of one receipt sharing a name is fine: both
   * open the same page.
   */
  sourceLinkName: (word: string, sku: string, when: string) => `${word} for ${sku}, ${when}`,
  /** `loadFailed`. */
  loadFailed: "The list of stock movements couldn't be loaded.",
  /** `noneYet` (`tenant_has_rows` false; IA H-1 slots 1–2): names only the receive flow the web app offers (G-51). */
  noneYet: 'No stock movements yet. A stock movement is recorded each time stock comes into or goes out of a location, for example when a delivery is received against a purchase order.',
  /** `noneYetLink` (H-1 slot 3, only for a ListPurchaseOrders holder). */
  noneYetLink: 'Go to purchase orders',
  /** `footer(n)`: the rows on this page, never a total (TB-2b). */
  footer: (n: number) => `${n} shown`,
  /** `shown(n)`: the settled announcement (never called with 0); a filtered list appends `filteredTo` or `filteredUnnamed`. */
  shown: (n: number) => (n === 1 ? '1 stock movement shown.' : `${n} stock movements shown.`),
} as const;

/** UX B-4 `STOCK_MOVEMENTS_FILTER`: the one item filter, held in the address (`?inventory_item_id=`, RED D-4), chosen by a link. */
export const STOCK_MOVEMENTS_FILTER = {
  /** `clear` (RED D-3 item 3): the link back to every movement. */
  clear: 'Show all stock movements',
  /** `filteredTo(sku, name)`: one line above the table while filtered and the item is named; also follows `shown`. */
  filteredTo: (sku: string, name: string) => `Showing only ${sku} — ${name}.`,
  /** `filteredUnnamed`: filtered, rows on screen, the item's name not read (fail-soft; the rows name it). */
  filteredUnnamed: 'Showing only one item.',
  /** `noneForItem(sku)`: filtered, empty, the workspace has movements, the item named (UX `stock-st1-ux-confirm.md` ruling 1). */
  noneForItem: (sku: string) => `No stock movements for ${sku} yet.`,
  /**
   * `noneForThisItem` (RED D-3 item 1; UX `stock-st1-ux-confirm.md` ruling 2): the same state when the item cannot be named.
   * Limit, stated: without `GetItem`, an address naming an item that does not exist reads the same (the server answers an
   * unknown item with an empty page, never a 404).
   */
  noneForThisItem: 'No stock movements for this item yet.',
  /** `unknownItem`: the address names no item this workspace can find (or a malformed one); ST-5's second sentence. */
  unknownItem: "We couldn't find the item this list is filtered to. It may have been deleted, or you may not have access.",
} as const;

/**
 * UX Part C `STOCK_MOVEMENTS_PAGING`: served newest first by time, so Newer · Older (Older sends `next_cursor`). Each page
 * sentence ends with the first row's item and location (RED D-3 item 2; R8-1's "two full equal pages" rule). Limit,
 * stated (R11-8, FE-R14): two full pages inside one rendered minute that start with the same item and location read alike.
 * Dates are DT-1's date-and-time form, formatted by the caller.
 */
export const STOCK_MOVEMENTS_PAGING = {
  pager: { previous: 'Newer', next: 'Older' },
  liveRange: (n: number, newest: string, oldest: string, sku: string, bin: string) => `${n} movements shown, from ${newest} back to ${oldest}, starting with ${sku} in ${bin}.`,
  liveRangeSameTime: (n: number, when: string, sku: string, bin: string) => `${n} movements shown, all at ${when}, starting with ${sku} in ${bin}.`,
  /** `liveOne(when, sku, bin)` (UX `stock-st1-ux-confirm.md` ruling 5): the one-row form names its row, as every house one-row form does. */
  liveOne: (when: string, sku: string, bin: string) => `1 movement shown, ${when}, ${sku} in ${bin}.`,
  noMore: {
    heading: 'There are no more stock movements here.',
    body: 'The page you asked for came back empty.',
    action: 'Back to the first page',
  },
} as const;

/** UX B-6 `STOCK_MOVEMENT_PAGE` (`/stock-movements/[id]`; read-only, deletion class none). */
export const STOCK_MOVEMENT_PAGE = {
  /** `title(label, sku)`: h1 and `<title>` (a movement has no number). */
  title: (label: string, sku: string) => `${label} — ${sku}`,
  /** `locationHint` (always under the Location value; also under Source beside a moved-to/-from location, RED L-10). */
  locationHint: "Shown by its code only. The warehouse it's in, and whether it's a quarantine location, aren't shown here.",
  /** `readOnlyNote`: a state; names no remedy (a further count or a mirror move is not in the web app, G-51). */
  readOnlyNote: "A stock movement can't be changed or deleted.",
  /** `itemMovementsLink(sku)`: this item's movements (the filter, R11-2). */
  itemMovementsLink: (sku: string) => `All movements of ${sku}`,
  /** `backToList`. */
  backToList: 'Back to stock movements',
} as const;

/**
 * UX B-3, B-4, B-7, B-8, B-9 `STOCK_LEVELS` (`/stock`, the one-location and the one-item views). A phrase that carries a
 * badge inside it (`scopeLocation`, `captionLocation`) is given as its parts, so a badge is its own text element after
 * the code it qualifies (§B-5 rule 3); joined with no badge they read exactly as UX wrote them.
 */
export const STOCK_LEVELS = {
  /** Nav label · h1 (unscoped) · `<title>` (CONFIRMS nav). */
  heading: 'Stock on hand',
  /** ST-4b's `{resource}`: *"You don't have access to stock on hand."* */
  resourcePlural: 'stock on hand',
  /** `titleLocation(code, warehouse)`: h1 and `<title>`; `{warehouse}` is the warehouse's name. */
  titleLocation: (code: string, warehouse: string) => `Stock on hand — ${code}, ${warehouse}`,
  /** `titleItem(sku)`: h1 and `<title>`. */
  titleItem: (sku: string) => `Stock on hand — ${sku}`,
  /**
   * `scopeLocation(code, warehouseCode, warehouse)`: *"Showing what's stored in {code} in {warehouseCode} — {warehouse}."*
   * The location's badges follow `{code}`; the warehouse's follow `{warehouse}`.
   */
  scopeLocation: {
    lead: "Showing what's stored in ",
    in: ' in ',
    warehouse: (warehouseCode: string, warehouse: string) => `${warehouseCode} — ${warehouse}`,
    end: '.',
  },
  /** `scopeItem(sku, name)`. */
  scopeItem: (sku: string, name: string) => `Showing ${sku} — ${name} in every location it has been stored in.`,
  /** `changeLocation` (→ the warehouse's step 2). */
  changeLocation: 'Choose another location',
  /** `captionLocation(code)`: *"Items in {code}, by SKU"*; the location's badges follow `{code}`. */
  captionLocation: { lead: 'Items in ', end: ', by SKU' },
  /** `captionItem(sku)`. */
  captionItem: (sku: string) => `Locations for ${sku}, by location code`,
  /** Column headings. */
  item: 'Item',
  warehouse: 'Warehouse',
  location: 'Location',
  /** `onHand`: the one-item view with the item's unit known. */
  onHand: 'On hand',
  /** `onHandInItemUnit`: the interim heading (the one-location view until BK-42; the one-item fallback). */
  onHandInItemUnit: "On hand (in the item's unit)",
  /** `unit`: the one-item view's Unit column, from the item's own record. */
  unit: 'Unit',
  /** `onHandHint` (IA H-3a; PO TO CONFIRM the words, UX Q-2): one line directly above the table, always shown. */
  onHandHint: 'On hand is everything physically in the location, including stock already promised on confirmed sales orders.',
  /** `warehouseCell(code, name)`. */
  warehouseCell: (code: string, name: string) => `${code} — ${name}`,
  /** `itemCell(sku, name)` (the ST-1 shape, redefined here as `STOCK_MOVEMENTS_LIST.itemCell` is). */
  itemCell: (sku: string, name: string) => `${sku} — ${name}`,
  /** `noneInLocation(code)` (no rows, `tenant_has_rows` true). */
  noneInLocation: (code: string) => `Nothing has been stored in ${code} yet.`,
  /** `noneForItem(sku)` (no rows, `tenant_has_rows` true, the item named). */
  noneForItem: (sku: string) => `${sku} hasn't been stored in any location yet.`,
  /** `noneForThisItem` (no rows, the item not named). */
  noneForThisItem: "This item hasn't been stored in any location yet.",
  /** `noneYet` (`tenant_has_rows` false; H-1 slots 1–2): names only the receive flow the web app offers (G-51). */
  noneYet: 'No stock has been recorded yet. In this app, stock is recorded in a location when a delivery is received against a purchase order.',
  /** `noneYetLink` (only with `purchase_order.view`). */
  noneYetLink: 'Go to purchase orders',
  /** `loadFailed` (S-12). */
  loadFailed: "Stock on hand couldn't be loaded.",
  /** `warehouseLoadFailed` (S-9a). */
  warehouseLoadFailed: "The warehouse couldn't be loaded.",
  /** `itemMovementsLink` (item scope, `stock_movement.view` only). */
  itemMovementsLink: 'Stock movements of this item',
  /** `backToItem` (item scope, `inventory_item.view` only). */
  backToItem: 'Back to the item',
  /** `chooseLocationInstead` (item scope; S-10). */
  chooseLocationInstead: 'Choose a warehouse and location instead',
} as const;

/** UX B-2 and Part C `STOCK_SCOPE`: the picker (step 1, step 2), the bad address, and the not-found headings. */
export const STOCK_SCOPE = {
  /** `intro` (under the h1 on `/stock`). */
  intro: "Choose a warehouse, then a location, to see what's stored there. To see one item in every location, open it from the item's page.",
  /** `chooseWarehouse` (h2, step 1). */
  chooseWarehouse: 'Choose a warehouse',
  /** `warehouseOption(code, name)`: one link per live warehouse, served order. */
  warehouseOption: (code: string, name: string) => `${code} — ${name}`,
  /** `noWarehousesYet` (`tenant_has_rows` false). */
  noWarehousesYet: "No warehouses yet. Stock is kept in a warehouse's locations, so there is nothing to show here until one exists.",
  /** `noWarehousesYetLink` (→ /warehouses). */
  noWarehousesYetLink: 'Go to warehouses',
  /** `allWarehousesArchived` (live view empty, `tenant_has_rows` true); followed by `noWarehousesYetLink`. */
  allWarehousesArchived: 'All warehouses are archived.',
  /** `chooseLocation(warehouse)` (h2, step 2); followed by the warehouse's Archived badge when it is archived. */
  chooseLocation: (warehouse: string) => `Choose a location in ${warehouse}`,
  /** `locationOption(code, kind)`: `{kind}` = the kind's label. */
  locationOption: (code: string, kind: string) => `${code} — ${kind}`,
  /** `archivedNote` (under step 2's list, always). */
  archivedNote: "Archived locations aren't listed.",
  /** `noLiveLocations(warehouse)`. */
  noLiveLocations: (warehouse: string) => `${warehouse} has no locations that aren't archived.`,
  /** `changeWarehouse` (→ /stock). */
  changeWarehouse: 'Choose another warehouse',
  /** `pickerLoadFailed`: the warehouse list's sentence (S-13) and the location list's (S-13, S-9a). */
  pickerLoadFailed: {
    warehouses: "The list of warehouses couldn't be loaded.",
    locations: "The list of locations couldn't be loaded.",
  },
  /** `findLocation`: the label of step 2's client-side search box (never in the address, never sent). */
  findLocation: 'Search by location code',
  /** `findNoMatch(q)`: `{q}` as typed. */
  findNoMatch: (q: string) => `No location code starts with “${q}”.`,
  /** `clearFind`. */
  clearFind: 'Clear search',
  /** `badAddress` (S-11): one line above step 1. */
  badAddress: "That link didn't name one location or one item, so nothing is shown. Choose a warehouse to start.",
  /** S-8's heading; body `PAGE_STATES.notFoundBody`; link `changeWarehouse`. */
  unknownWarehouse: "We couldn't find that warehouse.",
  /** S-9's heading; body `PAGE_STATES.notFoundBody`; link `STOCK_LEVELS.changeLocation`. */
  unknownLocation: (warehouse: string) => `We couldn't find that location in ${warehouse}.`,
  /** S-10's heading; body `PAGE_STATES.notFoundBody`; link `STOCK_LEVELS.chooseLocationInstead`. */
  unknownItem: "We couldn't find that item.",
} as const;

/**
 * UX B-5 `STOCK_LOCATION_MARKER` (G-47's badge copy key, C2). Each badge is its own text element after the code it
 * qualifies, never colour only. Display order: Quarantine, then Archived, then Status unknown; a warehouse's Archived
 * goes on the warehouse. `quarantine` equals the location kind's label (held equal by test, UX rule 4).
 */
export const STOCK_LOCATION_MARKER = {
  quarantine: 'Quarantine',
  archived: 'Archived',
  unknown: 'Status unknown',
  /** `markerNote` (one line under the hint, always shown when rows show). */
  markerNote: "Stock in a location marked Quarantine or Archived, or in an archived warehouse, isn't picked for a shipment while it's marked.",
  /** `unknownBanner` (item scope, above the table when any row says Status unknown), with Retry, which re-runs the location checks only. */
  unknownBanner: "We couldn't check whether some locations are in quarantine or archived. Their rows say Status unknown.",
  /**
   * `unknownBannerLocation(code)` (location scope, above the table or the empty state, whenever the location says Status
   * unknown; Rev 1c), with Retry, which re-runs the location checks only. True beside any proven badge: it claims only that
   * a check did not finish. Also the sentence a failing Retry announces in that scope.
   */
  unknownBannerLocation: (code: string) => `We couldn't finish checking ${code}, so it's marked Status unknown.`,
  /** `markerChecked`: announced once when a marker Retry resolves every Status unknown (copy doc Part C, Revision 1b). */
  markerChecked: 'Locations checked.',
} as const;

/**
 * UX B-6 `STOCK_WITHHELD`: a viewer who may read stock on hand but not the location lists or the warehouse sees no rows
 * and this page. `labels` are the missing permissions' labels (`permissionLabels`), in order; there are one or two.
 */
export const STOCK_WITHHELD = {
  /** `heading(n)` (h1; `<title>` stays *Stock on hand*). */
  heading: (n: number) => (n === 1 ? 'Stock on hand needs one more permission.' : 'Stock on hand needs two more permissions.'),
  /** `body(labels)`. */
  body: (labels: readonly string[]) =>
    labels.length === 1 ? `To see stock here you also need the ${labels[0]} permission.` : `To see stock here you also need the ${labels.slice(0, -1).join(', ')} and ${labels[labels.length - 1]} permissions.`,
  /** `why`. */
  why: "It's needed so that each location can be marked when its stock is in quarantine or archived. Your workspace administrator can grant it.",
} as const;
