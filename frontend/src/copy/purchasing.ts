// Every purchase-order screen sentence (Revision 8 R8-2; Revision 10 R10-2: one `purchasing` family), from
// `docs/design/purchase-orders-ux-copy.md` Revision 1a (copy per commit, R1a-1) over Revision 1 — FINAL (R1-B) over
// Revision 0 Part B; each entry names its key. Server refusals are never stored here: they render as sent. Each sentence
// holds for the web app in the commit that renders it (`STATES.md:161`). PO-1 (R1a-1 row 1) is reading only: the list,
// the Archived view and the read-only order page — so no sentence here names an action, and the keys whose final form
// names one (`noneYet`, `emptyDraft`, `noneArchived`, `draftNote`, `orderedNote`) are in their PO-1 forms. The commit
// that ships each action replaces its key with the next form (R1a-1 rows 2–12).

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
  /** `noneYet` (`tenant_has_rows` false), PO-1 form (R1a-1 row 1): the final form names creating, which ships at PO-3. */
  noneYet: 'No purchase orders yet.',
  /** `emptyOpen` (final; a state, names no action). */
  emptyOpen: "No open purchase orders. Open orders are approved orders that haven't been received in full or cancelled.",
  /** `emptyDraft`, PO-1 form (R1a-1 row 1): the later forms name creating (PO-3) and approving (PO-8). */
  emptyDraft: 'No drafts.',
  /** `emptyReceived` · `emptyCancelled`. */
  emptyReceived: 'No orders have been received in full yet.',
  emptyCancelled: 'No cancelled orders.',
  /** `noneArchived`, PO-1 form (R1a-1 row 1): the later forms name restoring (PO-2) and deleting (PO-6). */
  noneArchived: 'No archived purchase orders.',
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

/** UX B-3 / R1-B `PURCHASE_ORDER_PAGE`: the order page, read-only at PO-1 (IA L-5: the editor while draft arrives at PO-4a). */
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
  /** `draftNote`, PO-1 form (R1a-1 row 1); later forms name changing (PO-4a), deleting (PO-6) and approving (PO-8). */
  draftNote: 'This is a draft.',
  /** `orderedNote`, PO-1 form (R1a-1 row 1); the final form names cancelling (PO-7). */
  orderedNote: "This order has been approved, so it can't be changed.",
  /** `closedNote` (received / cancelled). */
  closedReceived: 'This order is closed: it was received in full.',
  closedCancelled: 'This order is closed: it was cancelled.',
  /** `noSupplierContact` (ordered and partly received, once; owner notice C-1). */
  noSupplierContact: "The app doesn't send purchase orders to suppliers.",
  /** `deletedOn(date)` (the archived banner; R1-B, R1-10): *"This draft was deleted on {date}."* No Restore until PO-2. */
  deletedBefore: 'This draft was deleted on ',
  deletedAfter: '.',
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
} as const;

/** UX B-12 `PURCHASING_FORBIDDEN` (CONFIRMS R1-5): the label is read from `permissionLabels.ts` by the operation's code. */
export const PURCHASING_FORBIDDEN = {
  before: 'This needs the ',
  after: ' permission, which you no longer have. Your workspace administrator can grant it.',
} as const;
