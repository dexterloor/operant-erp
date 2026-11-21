// Every purchase-order screen sentence (Revision 8 R8-2; Revision 10 R10-2: one `purchasing` family), from
// `docs/design/purchase-orders-ux-copy.md` Revision 1a (copy per commit, R1a-1) over Revision 1 — FINAL (R1-B) over
// Revision 0 Part B; each entry names its key. Server refusals are never stored here: they render as sent. Each sentence
// holds for the web app in the commit that renders it (`STATES.md:161`). PO-1 (R1a-1 row 1) is reading only: the list,
// the Archived view and the read-only order page. PO-2 (row 2) adds the one action Restore purchase order, on a deleted
// draft's banner (`RESTORE_ORDER.*`, `noneArchived`'s PO-2 form). PO-3 (row 3) adds Create purchase order: the create page
// (`NEW_PURCHASE_ORDER.*`), `newOrder`, `createOrder`, `noneYet` in its final form and `emptyDraft` in its PO-3 form. The
// keys whose final form names a later action (`emptyDraft`'s approving, `draftNote`, `orderedNote`) stay in earlier forms.
// PO-4a (row 4) adds Save on a draft's header: the editor's keys and `draftNote` / `supplierFixed` in their PO-4a forms.
// PO-5 (row 5) adds Add line and Delete line: `ADD_LINE.*`, `DELETE_LINE.*` (`after` without the restore clause until PO-9),
// `ORDER_LINES.actions` / `emptyNote`; `ADD_LINE.archivedNote` is not here until BK-38.
// PO-9 (row 12) adds Restore line: `ORDER_LINES.deletedLines*`, `RESTORE_LINE.*` and `DELETE_LINE.after` in its final form.
// PO-6 (row 6) adds Delete purchase order on a live draft: `DELETE_ORDER.*` (B-14), and the three keys that name deleting in
// their PO-6 forms: `supplierFixed` final, `draftNote` = "…It can be changed or deleted.", `noneArchived` final.
// PO-7 (row 7) adds Cancel order on an ordered or partly received order: `CANCEL_ORDER.*` (B-7, both bodies; Rev 1a: the
// reason is single-line) and `orderedNote` in its final form ("…It can still be cancelled.").
// GR-1 (row 8) adds reading receipts: `GOODS_RECEIPTS_LIST.*` (with `noneYet` in its GR-1 form, no link), the register's
// paging words and `GOODS_RECEIPT_PAGE.*`; the order page's Receipts section uses `forOrder` / `forOrderEmpty`.
// GR-1b (Revision 1d, `gr-1-followups-ux.md` as corrected by `gr-1-followups-red.md`, which win over the copy file for these
// keys): both `searchHint`s take the six-digit shape of a number; the register's caption and pager follow its served order
// (receipt number: *Highest receipt number first*, Previous · Next); the section gets its own paging copy
// (`GOODS_RECEIPTS_FOR_ORDER_PAGING`, Newer · Older), `forOrderCaption` and `forOrderEmptyCancelled`; both `noMore` bodies
// say only what happened; `GOODS_RECEIPT_PAGE.linesHeading` is the receipt page's own key. `receiptsLink` is struck (§3) and
// `GOODS_RECEIPT_PAGE.loadFailed` withdrawn (D-8): neither is here.
// PO-8 (row 9) adds Approve on a live draft: `APPROVE_ORDER.*` (B-6 / Rev 1a `summary`; `body` and `after` WITHOUT the
// receive clause: Receive ships at GR-2, which adds it), `emptyDraft` in its final form, and `draftNote` in its final form
// WITHOUT its third sentence (the owner clause SEC has not confirmed: final RED L-6; the commit that lands SEC's word adds it).
// GR-2 (row 11) adds Receive: `RECEIVE.*` (B-8 / R1-B, `prefillNote` per D-12 / final RED M-8 (a), `onHandTooLarge` per M-1),
// `APPROVE_ORDER.body` / `after` and `GOODS_RECEIPTS_LIST.noneYet` in their final forms, and `noneYetLink`.
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
  searchHint: 'For example PO-000123. Order numbers are matched as typed, so use capitals.',
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
  /** `emptyDraft`, final form from PO-8 (R1a-1 row 9; R1-B): Approve ships here, so it says a draft stays one until approved. */
  emptyDraft: "No drafts. A new purchase order stays a draft until it's approved.",
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
   * `draftNote`, final form from PO-8 (R1a-1 row 9; R1-B) WITHOUT its third sentence: Save (PO-4a), Delete purchase order
   * (PO-6) and Approve (PO-8) have shipped (passive, R-3: true for every viewer of a draft). The third sentence (*"It has to
   * be approved by someone other than the person who raised it, unless that person is an owner."*) waits for SEC's word on
   * its owner clause (final RED L-6); the commit that lands it adds the sentence.
   */
  draftNote: "This is a draft. It can be changed or deleted until it's approved.",
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

/**
 * UX B-6 `APPROVE_ORDER` (PO-8; Rev 1a with `summary`; R1a-1 row 9): **Approve** on a live draft's page, through its confirm
 * dialog (the plan's "Place order" is a step name, never a label). No expected date while D-4 is unruled. The dismiss is this
 * area's **Go back** (B-0); after a final refusal the dialog shows **Go back** only (B-6: ST-9b's Close shape). Heading
 * `NOT_APPROVED` on a refusal. `body` and `after` are their FINAL forms from GR-2 (R1a-1 row 11), WITH the receive clause
 * (*"…and deliveries can be received against it."* / *"Deliveries can be received against it."*): Receive ships here. `after` and
 * `afterSupplier` (its second sentence, always; strike-able with C-1) are spoken together in the page's region.
 * `alreadyApproved` is UX D-2's, said from the re-read after A2. PO TO CONFIRM `body`'s last sentence (B-6).
 * `summaryWarehouse`, `unsavedHeader`, `totalChanged` and `warehouseChanged` are PLACEHOLDERS (po-8-m1-m2-recommendation.md
 * Revision 1 — FINAL, M-1 / M-2; UX TO CONFIRM): the dialog shows where the order goes; Approve says why it is unavailable
 * while Deliver to holds an unsaved change; after a 200 whose total or warehouse differs from what the dialog opened with,
 * the region message is `after` + `totalChanged` / `warehouseChanged` + `afterSupplier`.
 */
export const APPROVE_ORDER = {
  action: 'Approve',
  confirm: 'Approve',
  dismiss: 'Go back',
  title: (number: string) => `Approve ${number}?`,
  summarySupplier: (supplier: string) => `Supplier: ${supplier}`,
  summaryTotal: (total: string) => `Total: ${total}`,
  summaryWarehouse: (name: string) => `Deliver to: ${name}`,
  unsavedHeader: 'Save or undo your change to Deliver to before approving.',
  /** FINAL (UX, ux-batch C-1; po-4b-red.md H-1): Approve's visible reason while a line row on screen holds a change not yet saved. */
  unsavedLines: 'Save or undo your changes to the lines before approving.',
  /** FINAL (UX, ux-batch C-2; po-4b-red.md S-2): typed line text hidden by a failed lines reading (no row to save or undo). Wins over `unsavedLines`. */
  unsavedLinesHidden: "Your changes to the lines aren't saved, and the lines couldn't be loaded. Press Retry under Lines, then save or undo your changes before approving.",
  body: (supplier: string) => `Once it's approved it can't be changed or deleted, only cancelled, and deliveries can be received against it. You're recorded as the person who approved it. The app doesn't send it to ${supplier}: send it to them yourself.`,
  after: (number: string) => `${number} is approved and now ordered. Deliveries can be received against it.`,
  afterSupplier: (supplier: string) => `Remember to send the order to ${supplier}: the app doesn't.`,
  totalChanged: (total: string) => `It was approved with a total of ${total}: its lines changed after this page last read them.`,
  warehouseChanged: (name: string) => `It was approved for delivery to ${name}: its delivery warehouse changed after this page last read it.`,
  alreadyApproved: (number: string) => `${number} was already approved.`,
  interrupted: (number: string) => `We can't tell whether ${number} was approved. Press Approve to try again.`,
} as const;

/** UX B-4 / R1-B `ORDER_LINES`: the lines on the order page, read-only at PO-1. */
export const ORDER_LINES = {
  heading: 'Lines',
  line: 'Line',
  item: 'Item',
  /**
   * Quantity headings (Rev 0 B-4's words, CONFIRMED by po-4b-ux-unit.md §3). From PO-4b (final RED L-11) the interim
   * *"(item's unit)"* is gone: the unit is the row's own served code in the **Unit** column, after the last quantity and
   * before Unit cost (QR-8; the catalogue's and the item list's Unit column). Also the Deleted lines table's Quantity.
   */
  quantity: 'Quantity',
  ordered: 'Ordered',
  received: 'Received',
  stillToCome: 'Still to come',
  /** D-3: on a cancelled order the same served number, headed for what it is (never recomputed). */
  neverReceived: 'Never received',
  /** `unit` (po-4b-ux-unit.md §3, NEW; the `CATALOGUE.unit` word): the column whose cell is the line's served code, shown bare. */
  unit: 'Unit',
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
  /**
   * `deletedLines` (the subsection's h3; R1-B, PO-9, X-17: not "Removed lines") · `deletedLinesEmpty` · `deletedLinesNote`
   * (UX R1-B `ORDER_LINES`, R1a-1 row 12; Rev 0 B-4: a live draft, `purchase_order.edit`).
   */
  deletedLines: 'Deleted lines',
  deletedLinesEmpty: 'No deleted lines.',
  deletedLinesNote: "Lines deleted from this draft can be restored while it's still a draft.",
  /**
   * The deleted lines' own read failed (first render or its Retry). NOT a UX key: R1-B writes none (FE-LEAD's placeholder,
   * in `loadFailed`'s shape; UX TO CONFIRM).
   */
  deletedLinesLoadFailed: "This draft's deleted lines couldn't be loaded.",
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
 * UX R1a `EDIT_LINE` (PO-4b; R1a-1 row 10; heading `NOT_SAVED`): each live line of a live draft is edited in its own row —
 * Quantity and Unit cost "as Add" (Add's visible labels and hints; the unit cost's hint in its no-prefill form), each box
 * named per line (M-9; UX marks the names I, FE-LEAD's tests make them unique), and **Save line** per row (D-9; its visible
 * text `save`, its name `saveName(n)`). The unit is each box's suffix (po-4b-ux-unit.md §2): Quantity shows the code and reads
 * `unitSuffixSpoken`; Unit cost shows `perUnit(code)` and reads `unitPerSpoken` + "." (R1-D).
 */
export const EDIT_LINE = {
  quantityName: (n: number) => `Quantity, line ${n}`,
  unitCostName: (n: number) => `Unit cost, line ${n}`,
  save: 'Save line',
  saveName: (n: number) => `Save line ${n}`,
  /** `perUnit(code)` (po-4b-ux-unit.md §3: the text of `EDIT_CATALOGUE_ITEM.perUnit`, own key in this family). */
  perUnit: (code: string) => `per ${code}`,
  /**
   * `itemNote` (final form: Delete line and Add line shipped at PO-5). Under the table, once, and only while Edit, Delete and
   * Add are all offered to the viewer (a key renders only where every action it names does, R1-2 / final RED L-5).
   */
  itemNote: 'To order a different item, delete this line and add a new one.',
  saved: (n: number) => `Line ${n} was saved.`,
  /** `savedListStale(n)`: a line Save re-reads the header only (M-9), so "the total" is what can be stale. */
  savedListStale: (n: number) => `Line ${n} was saved. We couldn't load this order again, so the total may be out of date.`,
  interrupted: (n: number) => `We can't tell whether your changes to line ${n} were saved. Check this order's lines before you try again.`,
} as const;

/**
 * UX R1-B `DELETE_LINE` (PO-5; X-4: a direct row action, no dialog; X-17: *Delete*; heading `NOT_DELETED`). `after` is the
 * final form (R1a-1 row 12) — PO-9 ships Restore line and the Deleted lines subsection, so it adds *" It can be restored from
 * Deleted lines."* — but only while the page shows Deleted lines (`restorable`; po-9-red.md L-2: the clause names a place).
 */
export const DELETE_LINE = {
  action: 'Delete',
  deleteName: (n: number) => `Delete line ${n}`,
  after: (n: number, sku: string, restorable: boolean) => (restorable ? `Line ${n} (${sku}) was deleted. It can be restored from Deleted lines.` : `Line ${n} (${sku}) was deleted.`),
  interrupted: (n: number) => `We can't tell whether line ${n} was deleted. Check this order's lines before you try again.`,
} as const;

/**
 * UX R1-B `RESTORE_LINE` (PO-9; B-5 "Restore a line", as Rev 0): **Restore** in each Deleted lines row (its accessible name
 * *Restore line {n}*, M-9), no dialog (the restore precedent); heading `NOT_RESTORED` on a refusal (the server's sentence as
 * sent, W25 / L8 included). `alreadyRestored` replaces the server's W24 (R1-17). `interrupted`: a repeat is W24 → `alreadyRestored`.
 */
export const RESTORE_LINE = {
  action: 'Restore',
  /**
   * po-9-red.md M-3: two deleted lines can share a number (a discarded number is reused), so the name, `after` and
   * `alreadyRestored` carry the SKU, in `DELETE_LINE.after`'s shape. PLACEHOLDER forms of UX's keys: UX TO CONFIRM.
   */
  name: (n: number, sku: string) => `Restore line ${n} (${sku})`,
  after: (n: number, sku: string) => `Line ${n} (${sku}) was restored and counts in the total again.`,
  /**
   * po-9-red.md M-2: a 200 whose re-read failed (the screen still shows the old total and the line struck). NOT a UX key:
   * PLACEHOLDER, UX TO CONFIRM (`ADD_LINE.afterListStale`'s shape).
   */
  afterListStale: (n: number, sku: string) => `Line ${n} (${sku}) was restored. We couldn't load this order again, so its lines and total may be out of date.`,
  alreadyRestored: (n: number, sku: string) => `Line ${n} (${sku}) was already restored.`,
  /** po-9-red.md N-4: with the SKU, as `after` (M-3). PLACEHOLDER form of UX's key: UX TO CONFIRM. */
  interrupted: (n: number, sku: string) => `We can't tell whether line ${n} (${sku}) was restored. Press Restore to try again.`,
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
  /**
   * `caption` (the register's served order, receipt number descending; rendered through the list's `notice`, R10-1). Not
   * "Newest first": it is read against the *Received* column, and `received_at` may be backdated (UX §2).
   */
  caption: 'Highest receipt number first',
  /** `searchFor` · `searchPlaceholder` · `searchHint` (X-2: no upper-casing; the hint says so). */
  searchFor: 'Search by receipt number',
  searchPlaceholder: 'Start of a receipt number',
  searchHint: 'For example GR-000123. Receipt numbers are matched as typed, so use capitals.',
  /** `searched(q)` · `noMatch(q)` · `clearSearch` (the house word). */
  searched: (q: string) => `Receipt number starts with “${q}”.`,
  noMatch: (q: string) => `No receipt number starts with “${q}”.`,
  clearSearch: 'Clear search',
  /** The settled announcement (the core's `shown`, never called with 0; CONFIRMED, UX D-9): `liveRange`'s noun, then `searched(q)`. The section uses it with an empty `q`. */
  shown: (n: number, q: string) => {
    const head = n === 1 ? '1 receipt shown.' : `${n} receipts shown.`;
    return q !== '' ? `${head} Receipt number starts with “${q}”.` : head;
  },
  /** `noneYet`, final form from GR-2 (R1a-1 row 11; B-9 slots 1–2): its reason names Receive, which ships here. */
  noneYet: 'No deliveries recorded yet. A goods receipt appears here when a delivery is received against a purchase order.',
  /** `noneYetLink` (B-9 slot 3, only for a ListPurchaseOrders holder): the upstream flow. */
  noneYetLink: 'Go to purchase orders',
  /** `loadFailed`. */
  loadFailed: "The list of goods receipts couldn't be loaded.",
  /**
   * The order page's Receipts section: `forOrder(number)` (its heading) · `forOrderCaption` (its served order, received
   * time descending, through the list's `notice`) · its empty sentence by the page's CURRENT status (UX §4):
   * `forOrderEmpty` (not cancelled; in practice ordered) · `forOrderEmptyCancelled` (cancelled). Never shown on a draft.
   */
  forOrder: (number: string) => `Receipts for ${number}`,
  forOrderCaption: 'Most recently received first',
  forOrderEmpty: 'Nothing has been received on this order yet.',
  forOrderEmptyCancelled: 'Nothing was received on this order.',
} as const;

/**
 * The register's paging words (UX §2, RED option (a); R8-1 item 3 as amended): served by receipt number, a key, so
 * Previous · Next — `next` sends `next_cursor`. `liveRange` names the first and last receipt numbers (CONFIRMED, D-9).
 */
export const GOODS_RECEIPTS_PAGING = {
  pager: { previous: 'Previous', next: 'Next' },
  liveRange: (n: number, first: string, last: string) => (n === 1 ? `1 receipt shown, ${first}.` : `${n} receipts shown, ${first} to ${last}.`),
  /** `noMore` (D-9): heading and action CONFIRMED; the body states only what happened (receipts are never deleted). */
  noMore: {
    heading: 'There are no more goods receipts here.',
    body: 'The page you asked for came back empty.',
    action: 'Back to the first page',
  },
} as const;

/**
 * The order page's Receipts section paging words (UX §2; RED L-1's name, L-2's sentence): served newest received first, so
 * Newer · Older — Older sends `next_cursor`, Newer `previous_cursor`. The page sentence is positional (the section is not
 * ordered by number, and `received_at` may be backdated), so it names the newest and the oldest receipt shown.
 */
export const GOODS_RECEIPTS_FOR_ORDER_PAGING = {
  pager: { previous: 'Newer', next: 'Older' },
  liveRange: (n: number, first: string, last: string) => (n === 1 ? `1 receipt shown, ${first}.` : `${n} receipts shown, newest received ${first}, oldest ${last}.`),
  noMore: {
    heading: 'There are no more receipts for this order here.',
    body: 'The page you asked for came back empty.',
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
   * `linesHeading` (D-9: the word confirmed, its own key so a later change to the order page's heading cannot silently
   * change this page). Lines columns: Order line · Item · Quantity · Unit · Location — from PO-4b the Quantity heading is
   * bare and the Unit cell is the receipt line's own served code (final RED L-11; po-4b-ux-unit.md §3).
   */
  linesHeading: 'Lines',
  orderLine: 'Order line',
  item: 'Item',
  quantity: 'Quantity',
  /** `unit` (po-4b-ux-unit.md §3, NEW; its own key, the D-9 reason). */
  unit: 'Unit',
  location: 'Location',
} as const;

/**
 * UX B-8 / R1-B `RECEIVE` (GR-2; R1a-1 row 11; heading `NOT_RECORDED` on a refusal): `/purchase-orders/[id]/receive`. One
 * location per order line in this build (S-8); no received-date field (S-9); no cost or total on the page (D-6). Every
 * Receiving-now box starts EMPTY beside *Still to come* (PO D-12), so `prefillNote` is final RED M-8 (a)'s text, not R1-B's.
 * `onHandTooLarge(n)` is final RED M-1's client sentence for R3 (`stock_level.on_hand_fits`; UX BE-P12's text), shown instead
 * of the served one, which names a stock count the app cannot take; deleted when BK-34 lands. `action` is the order page's
 * Receive action (R1a-1 row 11 "Actions this commit adds").
 */
export const RECEIVE = {
  action: 'Receive',
  heading: (number: string) => `Receive a delivery for ${number}`,
  intro: (supplier: string, warehouse: string) => `From ${supplier}, into ${warehouse}.`,
  /** Columns: Line · Item · Ordered · Received so far · Still to come · Receiving now · Location (quantities with the line's unit, BK-33). */
  line: 'Line',
  item: 'Item',
  ordered: 'Ordered',
  receivedSoFar: 'Received so far',
  stillToCome: 'Still to come',
  receivingNow: 'Receiving now',
  prefillNote: "Enter what arrived on each line. A line left empty or at 0 isn't recorded.",
  /** Per-row accessible names (M-9, I until these tests). */
  receivingNowName: (n: number) => `Receiving now, line ${n}`,
  locationName: (n: number) => `Location, line ${n}`,
  location: 'Location',
  /** FINAL (UX, ux-batch-2026-11-19.md A-3): the select's unchosen option (the `warehousePrompt` shape; no preselection). */
  locationPrompt: 'Choose a location',
  /** FINAL (UX, ux-batch B-3; gr-2-red.md L-6): beside a row whose chosen location the re-read after R2 no longer lists, when the server did not name it. */
  locationReset: (n: number) => `The location chosen for line ${n} can no longer be used, so it was cleared. Choose another.`,
  locationHint: (warehouse: string) => `Only locations in ${warehouse} that aren't archived are listed.`,
  locationKind: (code: string, kind: string) => `${code} — ${kind}`,
  doneLinesNote: "Lines already received in full aren't listed.",
  locationRequired: (n: number) => `Choose where line ${n} goes.`,
  nothingToRecord: "Enter what arrived on at least one line. A line left empty or at 0 isn't recorded.",
  submit: 'Record receipt',
  dismiss: 'Go back to the order',
  confirmTitle: 'Record this receipt?',
  confirmBody: (n: number, warehouse: string) => `This records ${n === 1 ? '1 line' : `${n} lines`} into ${warehouse}, in the locations you chose, straight away. A receipt can't be changed or deleted afterwards.`,
  confirmConfirm: 'Record receipt',
  confirmDismiss: 'Go back',
  /** `after(receipt, n, warehouse)` + `afterStatus(statusWords)`: the second sentence only from the order's re-read. */
  after: (receipt: string, n: number, warehouse: string) => `${receipt} recorded: ${n === 1 ? '1 line' : `${n} lines`} received into ${warehouse}, and stock there is updated.`,
  afterStatus: (statusWords: string) => `The order is now ${statusWords}.`,
  orderChanged: (number: string, statusWords: string) => `${number} changed while you were on this page: it's now ${statusWords}, so nothing was recorded.`,
  /** GR-2 H-1 (UX FINAL, gr-2-h1-ux.md §2): true only because the page holds one key for every press (`heldKey`). */
  interrupted: "We can't tell whether the receipt was recorded. Stay on this page and press Record receipt again: it won't be recorded twice.",
  /** FINAL (UX, gr-2-h1-ux.md §1 as amended by ux-batch B-4): a key conflict over a still receivable order (lines re-read, entries kept on the lines still listed). Never the server's detail. */
  earlierRecorded: "Your earlier attempt was recorded, so this one wasn't. What's still to come has been updated, and your entries are kept on the lines still listed, so check them before you record again.",
  /** FINAL (UX, ux-batch A-1; d2): above `nothingToReceive` in the no-form state. */
  earlierRecordedNoForm: "Your earlier attempt was recorded, so this one wasn't.",
  /** FINAL (UX, ux-batch A-2; d3), reordered by copy-batch-red.md L-2 so the guard leads (UX may strike: then the A-2 order). The key is rotated, so the person must reload before pressing. */
  earlierRecordedReload: "Your earlier attempt was recorded, so this one wasn't. Before you record anything else, reload the page to see what's still to come.",
  /** FINAL (UX, ux-batch B-4; gr-2-red.md L-7): a line now received in full drops out with its entry, so "on the lines still listed". */
  stalePrefill: "Another receipt was recorded on this order since you opened this page. What's still to come has been updated, and your entries are kept on the lines still listed, so check them before you record again.",
  nothingToReceive: (number: string, statusWords: string) => `There's nothing to receive on ${number}: it's ${statusWords}.`,
  /** FINAL (UX, ux-batch B-2; gr-2-red.md L-4): `nothingToReceive`'s status words for a deleted draft. */
  deletedDraftWords: 'a deleted draft',
  backToOrder: (number: string) => `Back to ${number}`,
  /** FINAL (UX, ux-batch A-4; gr-2-red.md M-1; D-16): the receive page when the order's warehouse has no live location. */
  noLocations: (warehouse: string) => `${warehouse} has no locations that aren't archived, so nothing can be received into it yet. A location can be added or restored on the warehouse's page.`,
  lineErrorUnplaced: (n: number, sentence: string) => `Line ${n}: ${sentence}`,
  onHandTooLarge: (n: string | number) => `Order line ${n}: receiving this would take the stock of this item in this location past the largest quantity that can be recorded. Nothing was received. The figure on record there may be wrong.`,
  /** FINAL (UX, ux-batch B-1; gr-2-red.md L-3): R3 when the served sentence names no order line (D-1's fallback). */
  onHandTooLargeUnnamed: 'Receiving this would take the stock of one of these items in its location past the largest quantity that can be recorded. Nothing was received. The figure on record there may be wrong.',
} as const;
