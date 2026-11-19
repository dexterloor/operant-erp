// Every stock-family screen sentence (Revision 8 R8-2; b4-architecture.md Revision 11 R11-1: one `stock` family), from
// `docs/design/stock-ux-copy.md` Revision 0 as amended by `stock-st1-red.md` (which wins for the keys it names: `clear`,
// `noneForThisItem`, the paging cue, `rowLinkName`, the `inventory_item_id` address). Each entry names its key. Stock ST-1
// is reading only: `/stock-movements` and `/stock-movements/[id]`. Nothing here offers a count or a move of stock (G-51):
// a past count or move is named as a fact, never as an action. No sentence says or implies that stock is available,
// pickable or in stock (G-7, G-47's R-4). The unit is not served on a movement yet (BK-41): until it is, the Change heading
// says the figure is in the item's unit (`changeInItemUnit`, owner Q-ST1). This file imports only lib/api types.

/** One reason's words: `label` is shown, `spoken` goes inside a composed accessible name. */
export interface ReasonWords {
  readonly label: string;
  readonly spoken: string;
}

/**
 * UX B-1 `STOCK_MOVEMENT_REASON`, keyed by the served `reason` (the entity's six; no enum is served). *Shipped*, not
 * "Fulfilled" (that word is the order's segment, TB-4; PO TO CONFIRM). `other` is the fallback for any other value: never
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
