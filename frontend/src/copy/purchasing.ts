// Every purchase-order screen sentence (Revision 8 R8-2; Revision 10 R10-2: one `purchasing` family), from
// `docs/design/purchase-orders-ux-copy.md` Revision 1a (copy per commit, R1a-1) over Revision 1 — FINAL (R1-B) over
// Revision 0 Part B; each entry names its key. Server refusals are never stored here: they render as sent. Each sentence
// holds for the web app in the commit that renders it (`STATES.md:161`). PO-1 (R1a-1 row 1) is reading only: the list,
// the Archived view and the read-only order page. PO-2 (row 2) adds the one action Restore purchase order, on a deleted
// draft's banner (`RESTORE_ORDER.*`, `noneArchived`'s PO-2 form). PO-3 (row 3) adds Create purchase order: the create page
// (`NEW_PURCHASE_ORDER.*`), `newOrder`, `createOrder`, `noneYet` in its final form and `emptyDraft` in its PO-3 form. The
// keys whose final form names a later action (`emptyDraft`'s approving, `draftNote`, `orderedNote`) stay in earlier forms.
// PO-4a (row 4) adds Save on a draft's header: the editor's keys and `draftNote` / `supplierFixed` in their PO-4a forms.
// PO-5 (row 5) adds Add line and Delete line: `ADD_LINE.*`, `DELETE_LINE.*` (`after` without the restore clause: Restore
// line ships at PO-9), `ORDER_LINES.actions` / `emptyNote`; `ADD_LINE.archivedNote` is not here until BK-38.
// PO-6 (row 6) adds Delete purchase order on a live draft: `DELETE_ORDER.*` (B-14), and the three keys that name deleting in
// their PO-6 forms: `supplierFixed` final, `draftNote` = "…It can be changed or deleted.", `noneArchived` final.
// PO-7 (row 7) adds Cancel order on an ordered or partly received order: `CANCEL_ORDER.*` (B-7, both bodies; Rev 1a: the
// reason is single-line) and `orderedNote` in its final form ("…It can still be cancelled.").
// GR-1 (row 8) adds reading receipts: `GOODS_RECEIPTS_LIST.*` (with `noneYet` in its GR-1 form, no link), the register's
// paging words and `GOODS_RECEIPT_PAGE.*`; the order page's Receipts section uses `forOrder` / `forOrderEmpty`.
// The commit that ships each action replaces its key with the next form (R1a-1 rows 7–12).

import type { PurchaseOrderSegment } from '@/lib/api/purchasing';

/** UX B-0 status words: one source for the status column, the page badge and (later) sentences. */
export const PURCHASE_ORDER_STATUS: Readonly<Record<string, { label: string; spoken: string }>> = {
  draft: { label: 'Draft', spoken: 'a draft' },
  ordered: { label: 'Ordered', spoken: 'ordered' },
  partially_received: { label: 'Partly received', spoken: 'partly received' },
  received: { label: 'Received', spoken: 'received in full' },
  cancelled: { label: 'Cancelled', spoken: 'cancelled' },
};
/** A served status's label; an unknown one renders as served (no enum is served: R8-5's raw-value fallback; FE-R10 candidate). */
export const statusLabel = (status: string) => (Object.hasOwn(PURCHASE_ORDER_STATUS, status) ? PURCHASE_ORDER_STATUS[status]!.label : status);
/** A served status's B-0 spoken words, for sentences; an unknown one as served (the label's fallback). */
export const statusSpoken = (status: string) => (Object.hasOwn(PURCHASE_ORDER_STATUS, status) ? PURCHASE_ORDER_STATUS[status]!.spoken : status);

/** UX B-1 segment tabs (CONFIRMS IA TB-4): Open · Draft · Received · Cancelled. */
const SEGMENT_LABELS: Readonly<Record<PurchaseOrderSegment, string>> = { open: 'Open', draft: 'Draft', received: 'Received', cancelled: 'Cancelled' };
/** The segment in lower case, as UX B-1 writes it inside a sentence (`noMatchSupplier`'s `{segment}`: open, draft, received, cancelled). */
const SEGMENT_WORDS: Readonly<Record<PurchaseOrderSegment, string>> = { open: 'open', draft: 'draft', received: 'received', cancelled: 'cancelled' };

/** UX B-1 / R1-B `PURCHASE_ORDERS_LIST`: the live segments and the Archived view. */
export const PURCHASE_ORDERS_LIST = {
  /** `heading(archived)`: **Purchase orders** · **Archived purchase orders** (IA nav label; ST-9a). */
  heading: (archived: boolean) => (archived ? 'Archived purchase orders' : 'Purchase orders'),
  /** ST-4b's `{resource}` (B-0 `resourcePlural`): *"You don't have access to purchase orders."* */
  resourcePlural: 'purchase orders',
  /** `archived` · `backToList` (ST-9a). */
  archived: 'Archived',
  backToList: 'Back to purchase orders',
  /** Segment tabs. */
  segment: (key: PurchaseOrderSegment) => SEGMENT_LABELS[key],
  /**
   * The segment control's group name (po-1-red.md L-3): NOT a UX key yet — the column's own word, as a placeholder;
   * UX TO CONFIRM (an accessibility read is owed with it).
   */
  segmentsName: 'Status',
  /**
   * Columns (R1-B): Open — Order · Supplier · Warehouse · Status · Ordered on · Expected · Total; Draft — Order · Supplier ·
   * Warehouse · Expected · Total; Received, Cancelled — Order · Supplier · Warehouse · Ordered on · Total; Archived —
   * Order · Supplier · Deleted on (L-4; R1-10).
   */
  order: 'Order',
  supplier: 'Supplier',
  warehouse: 'Warehouse',
  status: 'Status',
  orderedOn: 'Ordered on',
  expected: 'Expected',
  total: 'Total',
  deletedOn: 'Deleted on',
  /** `notOrdered` · `notSet` (cells): a word, never a dash, so a screen reader says something. */
  notOrdered: 'Not ordered',
  notSet: 'Not set',
  /**
   * `caption(segment)` (R1-B, X-10): each live segment's served order, rendered above its rows (the live caption goes
   * through the list's `notice`, Revision 10 R10-1). Archived: `archivedCaption`, the table's own caption.
   */
  caption: (segment: PurchaseOrderSegment) => (segment === 'open' ? 'Ordered longest ago first' : 'Lowest order number first'),
  archivedCaption: 'Most recently deleted first',
  /** `searchFor(archived)` · `searchPlaceholder` · `searchHint` (X-2: no upper-casing; the hint stays). */
  searchFor: (archived: boolean) => (archived ? 'Search archived orders by order number' : 'Search by order number'),
  searchPlaceholder: 'Start of an order number',
  searchHint: 'For example PO-0001. Order numbers are matched as typed, so use capitals.',
  /**
   * The settled announcement (first load, a switch, a settle; the core's `shown`, never called with 0). NOT a UX key:
   * Revision 1 lists none for the purchase-order list though the core requires one (the suppliers D-1 precedent; UX TO
   * CONFIRM). Built from UX's own words: `liveRange`'s noun with the segment in lower case (B-1's `{segment}`) — two
   * segments with equal counts never read alike (final RED M-5) — then `searched(q)`.
   */
  shown: (n: number, q: string, archived: boolean, segment?: string) => {
    const kind = archived ? 'archived' : segment !== undefined && Object.hasOwn(SEGMENT_WORDS, segment) ? SEGMENT_WORDS[segment as PurchaseOrderSegment] : null;
    const noun = n === 1 ? 'purchase order' : 'purchase orders';
    const head = `${n} ${kind !== null ? `${kind} ` : ''}${noun} shown.`;
    return q !== '' ? `${head} Order number starts with “${q}”.` : head;
  },
  /** `loadFailed`. */
  loadFailed: "The list of purchase orders couldn't be loaded.",
  /**
   * `noneYet` (`tenant_has_rows` false), final form from PO-3 (R1a-1 row 3; R1-B): it names creating, which ships here.
   * Its action is `createOrder`, for a viewer who reaches the create page.
   */
  noneYet: 'No purchase orders yet. A purchase order is what you ask a supplier for: it appears here as a draft when someone creates it.',
  /** `newOrder` (the live header's link) · `createOrder` (the `noneYet` and `emptyDraft` action), CONFIRMS the New/Create rule. */
  newOrder: 'New purchase order',
  createOrder: 'Create purchase order',
  /** `emptyOpen` (final; a state, names no action). */
  emptyOpen: "No open purchase orders. Open orders are approved orders that haven't been received in full or cancelled.",
  /** `emptyDraft`, PO-3 form (R1a-1 row 3; final RED L-3): names creating, not approving (PO-8 ships the final form). */
  emptyDraft: 'No drafts. A new purchase order starts as a draft.',
  /** `emptyReceived` · `emptyCancelled`. */
  emptyReceived: 'No orders have been received in full yet.',
  emptyCancelled: 'No cancelled orders.',
  /**
   * `noneArchived`, final form from PO-6 (R1a-1 row 6; R1-B): Restore shipped at PO-2 and Delete purchase order ships at
   * PO-6, so it says how a draft gets here and that it can be restored from here.
   */
  noneArchived: 'No archived purchase orders. Drafts that are deleted appear here and can be restored from here.',
  /**
   * `noMatch(q, segment)` · `noArchivedMatch(q)` · `clearSearch` · `footer(n)`. The live form names its segment, as the
   * Archived one names its view (po-1-red.md M-2): the search is sent with the segment, so an empty answer means no order
   * of THAT segment matches, never that no such order number exists. UX may strike it only with another true sentence.
   */
  noMatch: (q: string, segment: PurchaseOrderSegment) => `No ${SEGMENT_WORDS[segment]} order's number starts with “${q}”.`,
  noArchivedMatch: (q: string) => `No archived order's number starts with “${q}”.`,
  clearSearch: 'Clear search',
  footer: (n: number) => `${n} shown`,
} as const;

/** UX B-1 `PURCHASE_ORDERS_PAGING` (R1-3 pager, F-2 page sentences with the order number, R1-7 / F-3; R1-B "deleted"). */
export const PURCHASE_ORDERS_PAGING = {
  /** `pager(archived)`: Previous / Next · Newer / Older. */
  pager: (archived: boolean) => (archived ? { previous: 'Newer', next: 'Older' } : { previous: 'Previous', next: 'Next' }),
  /** `liveRange(n, first, last)`: the first and last rows' order numbers, in the order shown. */
  liveRange: (n: number, first: string, last: string) => (n === 1 ? `1 purchase order shown, ${first}.` : `${n} purchase orders shown, ${first} to ${last}.`),
  /** `searched(q)`: follows the range sentence. */
  searched: (q: string) => `Order number starts with “${q}”.`,
  /** `archivedRange` (R1-B) · `archivedOneDate` · `archivedOne` (F-2 with the order number; R1-10's "deleted"). Dates DT-1, formatted by the caller. */
  archivedRange: (n: number, newest: string, oldest: string, first: string) => `${n} archived purchase orders shown, deleted ${newest} to ${oldest}, starting with ${first}.`,
  archivedOneDate: (n: number, date: string, first: string) => `${n} archived purchase orders shown, deleted on ${date}, starting with ${first}.`,
  archivedOne: (first: string, date: string) => `1 archived purchase order shown, ${first}, deleted on ${date}.`,
  /** `noMore(archived)`: heading · body · action (R1-7). */
  noMore: (archived: boolean) => ({
    heading: archived ? 'There are no more archived purchase orders here.' : 'There are no more purchase orders here.',
    body: 'The list changed after the page you were on was loaded.',
    action: 'Back to the first page',
  }),
} as const;

/**
 * UX B-3 / R1-B `PURCHASE_ORDER_PAGE`: the order page, read-only at PO-1; from PO-4a a draft's page is its editor (IA L-5):
 * Deliver to with B-2's hint (`NEW_PURCHASE_ORDER.warehouse` / `warehouseHint` / `warehouseOption`), Supplier and
 * Currency as text, Save.
 */
export const PURCHASE_ORDER_PAGE = {
  /** `title(number, supplier)`: h1 and `<title>`. */
  title: (number: string, supplier: string) => `${number} — ${supplier}`,
  /** Field labels: Supplier · Deliver to · Currency · Expected delivery · Ordered on · Raised by · Total. */
  supplier: 'Supplier',
  deliverTo: 'Deliver to',
  currency: 'Currency',
  expected: 'Expected delivery',
  orderedOn: 'Ordered on',
  raisedBy: 'Raised by',
  total: 'Total',
  /** An expected date not set: the list's cell word (`notSet`). */
  notSet: 'Not set',
  /** `raisedByYou(name)` (display only; it pre-empts nothing, ST-4f) · `raisedByUnknown` (`created_by` null). */
  raisedByYou: (name: string) => `${name} (you)`,
  raisedByUnknown: 'Not recorded',
  /**
   * `draftNote`, PO-6 form (R1a-1 row 6; final RED apply list PO-6): Save (PO-4a) and Delete purchase order (PO-6) have
   * shipped, so it names changing and deleting (passive, R-3: true for every viewer of a draft). The final form names
   * approving (PO-8).
   */
  draftNote: 'This is a draft. It can be changed or deleted.',
  /** `orderedNote`, final form from PO-7 (R1a-1 row 7; R1-B): Cancel order has shipped (passive, R-3: true for every viewer). */
  orderedNote: "This order has been approved, so it can't be changed. It can still be cancelled.",
  /** `closedNote` (received / cancelled). */
  closedReceived: 'This order is closed: it was received in full.',
  closedCancelled: 'This order is closed: it was cancelled.',
  /** `noSupplierContact` (ordered and partly received, once; owner notice C-1). */
  noSupplierContact: "The app doesn't send purchase orders to suppliers.",
  /** `deletedOn(date)` (the archived banner; R1-B, R1-10): *"This draft was deleted on {date}."* Its action is `RESTORE_ORDER.action` (PO-2). */
  deletedBefore: 'This draft was deleted on ',
  deletedAfter: '.',
  /** `rereadFailed` (UX B-3, CONFIRMS the shape): a re-read after a write did not answer. */
  rereadFailed: "We couldn't load this purchase order again, so what's shown may be out of date.",
  /**
   * `supplierFixed` (the draft editor), final form from PO-6 (R1a-1 row 6; R1-B): Delete purchase order (PO-6) and Create
   * purchase order (PO-3) have both shipped, so it says a draft can be deleted and a new one created. Passive (R-3).
   */
  supplierFixed: "The supplier can't be changed. To order from another supplier, this draft can be deleted and a new one created.",
  /** `save` · `saveNothing` · `saved(number)` (UX B-3 / R1-B, CONFIRMS the shapes). */
  save: 'Save',
  saveNothing: 'Nothing to save.',
  saved: (number: string) => `${number} was saved.`,
  /** `interrupted(number)` (UX B-3 / R1-B): a Save whose answer was lost. */
  interrupted: (number: string) => `We can't tell whether your changes to ${number} were saved. Reload the page to check.`,
  /**
   * `noLongerDraft(number, status)` (UX D-2 / R1-B): an edit refused because the order is no longer a draft, said from
   * the re-read (`{status words}` = the B-0 spoken words) — never the server's P9 sentence (false for a draft cancelled
   * elsewhere; developer words).
   */
  noLongerDraft: (number: string, status: string) => `${number} is no longer a draft: it's ${statusSpoken(status)}, so it can't be changed.`,
  /** `changedSinceLoaded(number)` (UX D-2 / R1-B): the same refusal when the re-read failed. */
  changedSinceLoaded: (number: string) => `${number} changed since this page was loaded, so this wasn't done. Reload the page to see where it stands.`,
} as const;

/**
 * UX B-13 `RESTORE_ORDER` (PO-2; the `RESTORE_SUPPLIER` shape): in the deleted draft's banner, no dialog, heading **Not
 * restored** on a refusal (the server's sentence as sent, W12/W13 and the rest). `after` says what comes back: the
 * order, as a draft, with the lines deleted with it; lines deleted on their own before stay deleted (owner Q3, IA §4.2).
 */
export const RESTORE_ORDER = {
  action: 'Restore purchase order',
  after: (number: string) => `${number} was restored as a draft. Lines that were deleted on their own before it was deleted stay deleted.`,
  alreadyRestored: (number: string) => `${number} was already restored.`,
  interrupted: (number: string) => `We can't tell whether ${number} was restored. Press Restore purchase order to try again.`,
} as const;

/**
 * UX B-14 `DELETE_ORDER` (PO-6; R1-B "as Rev 0"; R1a-1 row 6): **Delete purchase order** on a live draft's page, through the
 * shared Delete dialog (danger; initial focus on the dismiss). Heading `NOT_DELETED` on a refusal. The dismiss is this area's
 * **Go back** (B-0); `close` is ST-9b's word for the one button left after a refusal (B-14 writes none; the house `Close`).
 * `after` / `afterRestored` (S-2: the 204's re-read read the order live, restored in between) are spoken in the page's region.
 */
export const DELETE_ORDER = {
  action: 'Delete purchase order',
  confirm: 'Delete purchase order',
  dismiss: 'Go back',
  close: 'Close',
  title: (number: string) => `Delete ${number}?`,
  body: 'It moves to the archived purchase orders and can be restored from there, with its lines.',
  after: (number: string) => `${number} was deleted. It's now in the archived purchase orders.`,
  afterRestored: (number: string) => `${number} was deleted, and has since been restored.`,
  interrupted: (number: string) => `We can't tell whether ${number} was deleted. Press Delete purchase order to try again.`,
} as const;

/**
 * UX B-7 `CANCEL_ORDER` (PO-7; Rev 1a "as Rev 0, with no change"; R1a-1 row 7): **Cancel order** on an ordered or partly
 * received order's page (§D-3), through its reason dialog (danger; initial focus on the dismiss). Never a bare "Cancel" on
 * this dialog: the dismiss is **Keep order**. `close` is ST-9b's word for the one button left after a final refusal (B-7
 * writes none; the house `Close`, as `DELETE_ORDER.close`). Heading `NOT_CANCELLED` on a refusal. The body is chosen from
 * the status shown when the dialog opens; `after` / `afterPartly` from the status the page held before the request; both are
 * spoken in the page's region. `alreadyCancelled` / `alreadyReceived` are UX D-2's, said from the re-read after C2. The
 * Reason is single-line (Rev 1a, X-5) with no length cap until SEC sets one (plan R-11; owed).
 */
export const CANCEL_ORDER = {
  action: 'Cancel order',
  confirm: 'Cancel order',
  dismiss: 'Keep order',
  close: 'Close',
  title: (number: string) => `Cancel ${number}?`,
  bodyOrdered: (supplier: string) => `Nothing more will be expected on it, and it can't be reopened. The app doesn't tell ${supplier}: let them know yourself.`,
  bodyPartly: (supplier: string) => `Goods already received stay in stock, and their receipts stay as they are. Nothing more will be expected on it, and it can't be reopened. The app doesn't tell ${supplier}: let them know yourself.`,
  reason: 'Reason (optional)',
  reasonHint: 'Kept with the record of the cancellation. Nothing in the app shows it yet.',
  after: (number: string) => `${number} was cancelled. Nothing more is expected on it.`,
  afterPartly: (number: string) => `${number} was cancelled. Goods already received stay in stock.`,
  alreadyCancelled: (number: string) => `${number} was already cancelled.`,
  alreadyReceived: (number: string) => `${number} was received in full meanwhile, so it can't be cancelled.`,
  interrupted: (number: string) => `We can't tell whether ${number} was cancelled. Press Cancel order to try again.`,
} as const;

/** UX B-4 / R1-B `ORDER_LINES`: the lines on the order page, read-only at PO-1. */
export const ORDER_LINES = {
  heading: 'Lines',
  line: 'Line',
  item: 'Item',
  /**
   * Quantity headings. Interim unit rule (QR-8, R1-B; PO-4b replaces it with the served unit, final RED L-11): every
   * quantity heading reads *"… (item's unit)"* until BK-33 serves the line's unit.
   */
  quantity: "Quantity (item's unit)",
  ordered: "Ordered (item's unit)",
  received: "Received (item's unit)",
  stillToCome: "Still to come (item's unit)",
  /** D-3: on a cancelled order the same served number, headed for what it is (never recomputed). */
  neverReceived: "Never received (item's unit)",
  unitCost: (currency: string) => `Unit cost (${currency})`,
  /** CONFIRMS `CATALOGUE.itemCell`. */
  itemCell: (sku: string, name: string) => `${sku} — ${name}`,
  /** `count(n)`: spoken only after a load in the browser (the section's Retry), never on first paint (M-2). */
  count: (n: number) => (n === 1 ? '1 line.' : `${n} lines.`),
  /** `empty` (draft). */
  empty: 'This draft has no lines yet.',
  loadFailed: "This order's lines couldn't be loaded.",
  /** `partial(n)` (a non-null `next_cursor`). */
  partial: (n: number) => `Only the first ${n} lines of this order are shown.`,
  /** `totalNote` (draft, under the table; R1-B REPLACES: names no action). */
  totalNote: 'The total is worked out from the lines.',
  /** The draft columns' last header (UX B-4): only when Add line or Delete line is offered (PO-5). */
  actions: 'Actions',
  /**
   * `emptyNote(supplier)` (R1-B, from PO-5): under `empty`, rendered exactly when Add line renders (final RED L-5: never
   * gated on `purchase_order.edit` alone — a seat without the catalogue read gets no Add, so no note).
   */
  emptyNote: (supplier: string) => `Add what you're ordering, one item per line. Only items in ${supplier}'s catalogue can be added.`,
} as const;

/**
 * UX R1-B `ADD_LINE` (PO-5; R1a-1 row 5; IA §4.1: an inline empty row at the bottom of the lines table; heading `NOT_ADDED`).
 * The row's names carry *new line* (M-9; UX marks them I, FE-LEAD's tests make them unique). `archivedNote` is not here: it
 * ships only once BK-38 lets the list omit archived items (until then an archived item's entry is offered and refused after
 * the press with `itemUnavailable`, notice N-15).
 */
export const ADD_LINE = {
  /** `rowName` (the row's name) · `itemName` · `quantityName` · `unitCostName` (accessible names). */
  rowName: 'New line',
  itemName: 'Item, new line',
  quantityName: 'Quantity, new line',
  unitCostName: 'Unit cost, new line',
  /** `item` (visible label) · `itemPlaceholderOption` (no preselection) · `itemOption(sku, name)` (CONFIRMS). */
  item: 'Item',
  itemPlaceholderOption: 'Choose an item',
  itemOption: (sku: string, name: string) => `${sku} — ${name}`,
  /** `emptyCatalogue(supplier)` (L-7, passive) and its link (*Open {supplier}*, only for a GetSupplier holder). */
  emptyCatalogue: (supplier: string) => `${supplier}'s catalogue lists no items, so no lines can be added yet. Items can be added to it on the supplier's page.`,
  openSupplier: (supplier: string) => `Open ${supplier}`,
  /**
   * The row's own catalogue read failed (first render or its Retry). NOT a UX key: R1-B writes none (FE-LEAD's placeholder,
   * in UX's `emptyCatalogue` / `loadFailed` shapes; UX TO CONFIRM).
   */
  catalogueLoadFailed: (supplier: string) => `${supplier}'s catalogue couldn't be loaded, so no line can be added yet.`,
  /** `quantity` (visible label) · `quantityHint` (the chosen entry's unit beside the field). */
  quantity: 'Quantity',
  quantityHint: "In the item's unit. Use a point for decimals and no commas, for example 2.5.",
  /**
   * `unitCost(currency)` (the order's currency) · `unitCostHint`: the second sentence only under D-7's prefill (the chosen
   * entry's currency is the order's); otherwise the first alone.
   */
  unitCost: (currency: string) => `Unit cost (${currency})`,
  unitCostHint: (prefilled: boolean) =>
    prefilled ? "What one of the item's units costs on this order. It starts at the supplier's catalogue cost; changing it here changes only this order." : "What one of the item's units costs on this order.",
  /** Client refusals (no request). */
  itemRequired: 'Choose an item for this line.',
  quantityRequired: 'Enter a quantity greater than 0.',
  costRequired: 'Enter the unit cost, or 0.',
  submit: 'Add line',
  after: (n: number, sku: string) => `Line ${n} added: ${sku}.`,
  afterListStale: (n: number, sku: string) => `Line ${n} added: ${sku}. We couldn't load the lines again, so they may be out of date.`,
  interrupted: (sku: string) => `We can't tell whether ${sku} was added. Check this order's lines before you try again.`,
  /**
   * `itemUnavailable` (final RED H-2; UX R1a-2 BE-P6, verbatim): beside Item for a 422 `unknown_reference` at
   * `/inventory_item_id`, never the server's developer words. Deleted in the commit that merges BK-34 (BE-P6) back.
   */
  itemUnavailable: "This item is archived or isn't in this workspace, so it can't go on a purchase order.",
} as const;

/**
 * UX R1-B `DELETE_LINE` (PO-5; X-4: a direct row action, no dialog; X-17: *Delete*; heading `NOT_DELETED`). `after` is the
 * PO-5 form, without *" It can be restored from Deleted lines."*: Restore line and the Deleted lines view ship at PO-9.
 */
export const DELETE_LINE = {
  action: 'Delete',
  deleteName: (n: number) => `Delete line ${n}`,
  after: (n: number, sku: string) => `Line ${n} (${sku}) was deleted.`,
  interrupted: (n: number) => `We can't tell whether line ${n} was deleted. Check this order's lines before you try again.`,
} as const;

/** UX B-12 `PURCHASING_FORBIDDEN` (CONFIRMS R1-5): the label is read from `permissionLabels.ts` by the operation's code. */
export const PURCHASING_FORBIDDEN = {
  before: 'This needs the ',
  after: ' permission, which you no longer have. Your workspace administrator can grant it.',
} as const;

/**
 * UX B-2 `NEW_PURCHASE_ORDER` (R1-B "as Rev 0"; R1a-1 row 3: no line table; the expected date only once D-4 is ruled, so
 * `expected` and `expectedHint` are not here). The create page of a DRAFT, header first: its lines are added on the
 * draft's own page.
 */
export const NEW_PURCHASE_ORDER = {
  /** `heading` (h1, `<title>`). */
  heading: 'New purchase order',
  /** `deniedResource`: ST-4b's noun for `/purchase-orders/new` without one of its operations. */
  deniedResource: 'new purchase orders',
  /** `supplier` · `supplierHint` (archived suppliers are never listed: the choice reads the live view only). */
  supplier: 'Supplier',
  supplierHint: "Only suppliers that aren't archived can be chosen.",
  /** `warehouse` · `warehouseHint`. */
  warehouse: 'Deliver to',
  warehouseHint: 'The warehouse the goods are delivered to and received into.',
  /** `currency(code)` (read-only, after a supplier is chosen; PO D-5 (a)) · `currencyBeforeSupplier`. */
  currency: (code: string) => `Currency: ${code}, this supplier's. Costs on this order are in it, and it can't be changed later.`,
  currencyBeforeSupplier: "The currency is the supplier's, once you choose one.",
  /** `supplierRequired` · `warehouseRequired` (client; no request). */
  supplierRequired: 'Choose a supplier.',
  warehouseRequired: "Choose where it's delivered to.",
  /** `submit` · `cancel`. */
  submit: 'Create purchase order',
  cancel: 'Go back',
  /** `after(number)`: spoken once on the new order's page, focus on its h1. */
  after: (number: string) => `${number} was created as a draft.`,
  /** `interrupted`: the number is minted by the server, so it cannot be named; "press again" is not offered (a new key). */
  interrupted: "We can't tell whether the purchase order was created. Check the Draft list before you try again.",
  /**
   * UX Revision 1b R1b-2: the select's unselected option and option label (CONFIRMS), the sentence under it when no live
   * warehouse exists (REPLACES: it names both causes, since the live view cannot tell them apart, and where the cure is —
   * passive, R-3) and the field's own load failure (CONFIRMS).
   */
  warehousePrompt: 'Choose a warehouse',
  warehouseOption: (code: string, name: string) => `${code} — ${name}`,
  noWarehouses: 'No warehouse can be chosen: none has been created yet, or every one is archived. Warehouses are added and restored on the Warehouses page.',
  warehousesLoadFailed: "The warehouses couldn't be loaded.",
} as const;

/**
 * UX Revision 1b R1b-1 `SUPPLIER_CHOICES`: the create page's supplier choice (plan §2.4: the suppliers S-5
 * choice-list-in-a-dialog shape). Every key CONFIRMS the shipped string except `noneYet` and `allArchived` (REPLACES: each
 * says where the cure is, passive, R-3) and `archivedNote` (NEW). The search box and paging CONFIRM `SUPPLIERS_LIST` /
 * `SUPPLIERS_PAGING`; the dialog, columns and Choose follow `ITEM_CHOICES`; the dismiss is this area's **Go back** (R1-B).
 */
export const SUPPLIER_CHOICES = {
  /** The field's button while nothing is chosen; also the dialog's title (the action it completes). */
  open: 'Choose a supplier',
  title: 'Choose a supplier',
  /** The chosen supplier, then Change (its accessible name says what changes). */
  chosen: (code: string, name: string) => `${code} — ${name}`,
  change: 'Change',
  changeName: 'Change supplier',
  /** Go back: the dialog's one dismiss. */
  dismiss: 'Go back',
  /** The list's own heading inside the dialog (the core's focus target after a Retry). */
  heading: 'Suppliers',
  /** `archivedNote` (NEW, R1b-1): one line above the rows (the `ITEM_CHOICES.archivedNote` shape). */
  archivedNote: "Archived suppliers aren't listed.",
  /** CONFIRMS `SUPPLIERS_LIST.searchFor(false)` · `searchPlaceholder` · `searchHint`. */
  search: 'Search by name or code',
  placeholder: 'Start of a name or code',
  hint: "Capitals don't matter for names. For codes they do, and so do spaces.",
  /** Columns: Code · Name · Currency · Choose (a header, so no header cell is empty). */
  code: 'Code',
  name: 'Name',
  currency: 'Currency',
  choose: 'Choose',
  chooseSupplier: (code: string) => `Choose ${code}`,
  /** The settled announcement (never called with 0) and the page sentences (CONFIRM `SUPPLIERS_PAGING`). */
  shown: (n: number, q: string) => [n === 1 ? '1 supplier shown.' : `${n} suppliers shown.`, q !== '' ? `Name or code starts with “${q}”.` : null].filter(Boolean).join(' '),
  pageShown: (n: number, first: string, last: string) => (n === 1 ? `1 supplier shown, ${first}.` : `${n} suppliers shown, ${first} to ${last}.`),
  searched: (q: string) => `Name or code starts with “${q}”.`,
  pager: { previous: 'Previous', next: 'Next' },
  noMore: { heading: 'There are no more suppliers here.', body: 'The list changed after the page you were on was loaded.', action: 'Back to the first page' },
  /** `noneYet` (`tenant_has_rows` false) · `allArchived` (REPLACES, R1b-1: true at PO-3 — New supplier S-2, Restore supplier S-1). */
  noneYet: 'No suppliers yet. A purchase order needs a supplier, and suppliers are added on the Suppliers page.',
  allArchived: 'No suppliers to choose from. Every supplier here is archived, and an archived supplier can be restored from the archived suppliers.',
  /** `noMatch(q)` (CONFIRMS `SUPPLIERS_LIST.noMatch`) · Clear search. */
  noMatch: (q: string) => `No supplier's name or code starts with “${q}”.`,
  clearSearch: 'Clear search',
  loadFailed: "The suppliers couldn't be loaded.",
} as const;

/**
 * UX B-9 `GOODS_RECEIPTS_LIST` (GR-1; R1-B "as Rev 0, except that `noneYet` follows R1-C"; R1a-1 row 8): the register at
 * `/goods-receipts` and the order page's Receipts section (`forOrder`, `forOrderEmpty`). No cost on any receipt surface
 * (UX D-6). `noneYet` is GR-1's form, slot 1 only and with **no link** (final RED L-3 Note A): its reason names Receive,
 * which ships at GR-2 with the full sentence and `noneYetLink`.
 */
export const GOODS_RECEIPTS_LIST = {
  /** `heading` (CONFIRMS nav). */
  heading: 'Goods receipts',
  /** ST-4b's `{resource}` (B-0 `resourcePlural`): *"You don't have access to goods receipts."* */
  resourcePlural: 'goods receipts',
  /** Columns: Receipt · Purchase order · Received · Received by · Lines (*Received* DT date and time; *Lines* a count). */
  receipt: 'Receipt',
  purchaseOrder: 'Purchase order',
  received: 'Received',
  receivedBy: 'Received by',
  lines: 'Lines',
  /** `receivedByUnknown` (`received_by` nullable, PC-11 (4a)). */
  receivedByUnknown: 'Not recorded',
  /** `caption` (the served order; rendered through the list's `notice`, R10-1). */
  caption: 'Newest first',
  /** `searchFor` · `searchPlaceholder` · `searchHint` (X-2: no upper-casing; the hint says so). */
  searchFor: 'Search by receipt number',
  searchPlaceholder: 'Start of a receipt number',
  searchHint: 'For example GR-0001. Receipt numbers are matched as typed, so use capitals.',
  /** `searched(q)` · `noMatch(q)` · `clearSearch` (the house word). */
  searched: (q: string) => `Receipt number starts with “${q}”.`,
  noMatch: (q: string) => `No receipt number starts with “${q}”.`,
  clearSearch: 'Clear search',
  /**
   * The settled announcement (the core's `shown`, never called with 0). NOT a UX key (the PO-1 D-5 precedent; UX TO
   * CONFIRM): `liveRange`'s noun, then `searched(q)`.
   */
  shown: (n: number, q: string) => {
    const head = n === 1 ? '1 receipt shown.' : `${n} receipts shown.`;
    return q !== '' ? `${head} Receipt number starts with “${q}”.` : head;
  },
  /** `noneYet`, GR-1 form (R1a-1 row 8): slot 1 only, no link (Note A). GR-2 ships the full form and `noneYetLink`. */
  noneYet: 'No deliveries recorded yet.',
  /** `loadFailed`. */
  loadFailed: "The list of goods receipts couldn't be loaded.",
  /** `forOrder(number)` (the order page's Receipts section heading) · `forOrderEmpty`. */
  forOrder: (number: string) => `Receipts for ${number}`,
  forOrderEmpty: 'Nothing has been received on this order yet.',
} as const;

/** UX B-9 paging (CONFIRMS R1-3/R1-8 shapes): Newer · Older; `liveRange` with receipt numbers. */
export const GOODS_RECEIPTS_PAGING = {
  /** Newest first, so the previous page is newer and the next one older. */
  pager: { previous: 'Newer', next: 'Older' },
  /** `liveRange(n, first, last)`; the one-row form is the PO list's shape (NOT a UX key; UX TO CONFIRM). */
  liveRange: (n: number, first: string, last: string) => (n === 1 ? `1 receipt shown, ${first}.` : `${n} receipts shown, ${first} to ${last}.`),
  /** `noMore`: NOT a UX key for receipts — the PO list's R1-7 words with the receipt noun (PLACEHOLDER, UX TO CONFIRM). */
  noMore: {
    heading: 'There are no more goods receipts here.',
    body: 'The list changed after the page you were on was loaded.',
    action: 'Back to the first page',
  },
} as const;

/** UX B-10 `GOODS_RECEIPT_PAGE` (GR-1; read-only, IA L-5a). */
export const GOODS_RECEIPT_PAGE = {
  /** `title(receipt)`: h1 and `<title>`. */
  title: (receipt: string) => receipt,
  /** Field labels: Purchase order · Received · Received by. */
  purchaseOrder: 'Purchase order',
  received: 'Received',
  receivedBy: 'Received by',
  /** `readOnlyNote` (deletion class none: a state, names no action). */
  readOnlyNote: "A receipt can't be changed or deleted.",
  /**
   * Lines columns: Order line · Item · Quantity · Location (interim unit heading until BK-33, final RED L-11). The table's
   * heading is `ORDER_LINES.heading` (*Lines*; B-10 writes none of its own).
   */
  orderLine: 'Order line',
  item: 'Item',
  quantity: "Quantity (item's unit)",
  location: 'Location',
} as const;
