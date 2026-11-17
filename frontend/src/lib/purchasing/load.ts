import { isPurchaseOrderSegment, PURCHASE_ORDER_SEGMENTS, type ActorReference, type NamedReference, type PurchaseOrder, type PurchaseOrderLine, type PurchaseOrderSegment } from '../api/purchasing';
import { pageQuery, type PageRequest, type Read, type ReadFailure } from '../bff/reads';

// The purchasing family's loaders (the lib/suppliers/load.ts shape; b4-architecture.md Revision 10 R10-2: one family
// for `/purchase-orders` and, from GR-1, `/goods-receipts`): pure, run over the server transport on first render and the
// browser one for every re-read. `ListPurchaseOrders` is PAGED (Revision 8 R8-1) with named live segments (Revision 10
// R10-1): its query is built only by `pageQuery(archived, page, segment)` — `status=<segment>` on a live read,
// `archived=true` and no `status` on an Archived one, never `limit`, at most one cursor, `q` as typed only when not
// empty — and it never sends `sort` (each segment's served default is the order; purchase-orders-plan.md §0) or
// `supplier_id` (out of scope, §0). Held by behaviour, not by type: `test/wired/purchase-orders-list.test.tsx` drives
// this loader and asserts each request it sends.

export type PurchaseOrdersLoad =
  | { readonly kind: 'ok'; readonly items: PurchaseOrder[]; readonly nextCursor: string | null; readonly previousCursor: string | null; readonly tenantHasRows: boolean }
  | { readonly kind: ReadFailure };
export type PurchaseOrderLoad = { readonly kind: 'ok'; readonly order: PurchaseOrder } | { readonly kind: ReadFailure };
/** `nextCursor` is served null always (a bounded list); a non-null one shows the section's partial notice, never a silent cut. */
export type LinesLoad = { readonly kind: 'ok'; readonly lines: PurchaseOrderLine[]; readonly nextCursor: string | null } | { readonly kind: ReadFailure };

const isObject = (x: unknown): x is Record<string, unknown> => !!x && typeof x === 'object';
const isCursor = (x: unknown): x is string | null => x === null || typeof x === 'string';
const isNullableString = (x: unknown): x is string | null => x === null || typeof x === 'string';
const isNamed = (x: unknown): x is NamedReference => isObject(x) && typeof x.id === 'string' && typeof x.name === 'string';
const isActor = (x: unknown): x is ActorReference => isObject(x) && typeof x.id === 'string' && typeof x.display_name === 'string';

export const isPurchaseOrder = (x: unknown): x is PurchaseOrder =>
  isObject(x) &&
  typeof x.id === 'string' &&
  typeof x.order_number === 'string' &&
  typeof x.status === 'string' &&
  isNamed(x.supplier) &&
  isNamed(x.warehouse) &&
  typeof x.currency_code === 'string' &&
  // A decimal string, as served; a number here is a malformed body (QR-1: never a double).
  typeof x.total_amount === 'string' &&
  isNullableString(x.ordered_at) &&
  isNullableString(x.expected_at) &&
  (x.created_by === null || isActor(x.created_by)) &&
  isNullableString(x.archived_at);

export const isPurchaseOrderLine = (x: unknown): x is PurchaseOrderLine =>
  isObject(x) &&
  typeof x.id === 'string' &&
  typeof x.line_number === 'number' &&
  Number.isInteger(x.line_number) &&
  isObject(x.inventory_item) &&
  typeof x.inventory_item.id === 'string' &&
  typeof x.inventory_item.sku === 'string' &&
  typeof x.inventory_item.name === 'string' &&
  // Decimal strings, as served (QR-1).
  typeof x.quantity === 'string' &&
  typeof x.quantity_received === 'string' &&
  typeof x.quantity_outstanding === 'string' &&
  typeof x.unit_cost === 'string' &&
  isNullableString(x.archived_at);

/**
 * `ListPurchaseOrders`: one page of a live segment (`status=<segment>`), or of the Archived view (`archived=true`), for a
 * search and a cursor. Every live read is given its segment by the list core (and the first one by the page), so the
 * unlisted "every live status" view (no `status`) is never read (IA TB-4 has none); the loader adds no default of its
 * own, so a caller that forgets the segment is seen on the wire. `tenant_has_rows` is required, as for suppliers.
 */
export async function loadPurchaseOrders(read: Read, archived: boolean, page: PageRequest, signal?: AbortSignal, segment?: PurchaseOrderSegment): Promise<PurchaseOrdersLoad> {
  const a = await read('ListPurchaseOrders', { query: pageQuery(archived, page, segment), signal });
  if (a.kind !== 'ok') return a;
  const body = a.body as { items?: unknown; next_cursor?: unknown; previous_cursor?: unknown; tenant_has_rows?: unknown } | null;
  const items = body?.items;
  if (!Array.isArray(items) || !items.every(isPurchaseOrder) || !isCursor(body?.next_cursor) || !isCursor(body?.previous_cursor) || typeof body?.tenant_has_rows !== 'boolean') {
    return { kind: 'server-error' };
  }
  return { kind: 'ok', items, nextCursor: body.next_cursor, previousCursor: body.previous_cursor, tenantHasRows: body.tenant_has_rows };
}

/** `GetPurchaseOrder`: the order page's first read. */
export async function loadPurchaseOrder(read: Read, id: string, signal?: AbortSignal): Promise<PurchaseOrderLoad> {
  const a = await read('GetPurchaseOrder', { params: { id }, signal });
  if (a.kind !== 'ok') return a;
  return isPurchaseOrder(a.body) ? { kind: 'ok', order: a.body } : { kind: 'server-error' };
}

/**
 * `ListPurchaseOrderLines`: the order's LIVE lines, the whole set in one answer, in served order (line number). It sends
 * the order id and nothing else — no `archived`, no `limit`, no cursor — and reports the answer's `next_cursor`, so a
 * later paging change shows the section's partial notice instead of truncating silently.
 */
export async function loadLines(read: Read, orderId: string, signal?: AbortSignal): Promise<LinesLoad> {
  const a = await read('ListPurchaseOrderLines', { params: { id: orderId }, signal });
  if (a.kind !== 'ok') return a;
  const body = a.body as { items?: unknown; next_cursor?: unknown } | null;
  const items = body?.items;
  if (!Array.isArray(items) || !items.every(isPurchaseOrderLine) || !isCursor(body?.next_cursor)) return { kind: 'server-error' };
  return { kind: 'ok', lines: items, nextCursor: body.next_cursor };
}

/**
 * The address names the view (IA TB-9): exactly `?archived=true` is the Archived view (and wins over a `status`, which the
 * address writer never writes beside it); otherwise `?status=` naming a served segment other than the default opens that
 * segment, and anything else opens the default one (Open).
 */
export function addressedView(query: Readonly<Record<string, string | string[] | undefined>>): { archived: boolean; segment: PurchaseOrderSegment } {
  const archived = query.archived === 'true';
  const segment = !archived && isPurchaseOrderSegment(query.status) ? query.status : PURCHASE_ORDER_SEGMENTS[0];
  return { archived, segment };
}

