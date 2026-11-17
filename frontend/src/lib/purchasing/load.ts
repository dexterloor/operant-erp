import { isPurchaseOrderSegment, PURCHASE_ORDER_SEGMENTS, type ActorReference, type NamedReference, type PurchaseOrder, type PurchaseOrderLine, type PurchaseOrderSegment } from '../api/purchasing';
import type { Supplier, SupplierProduct } from '../api/suppliers';
import type { Warehouse } from '../api/warehouses';
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


/**
 * PO-3: one row of the create page's supplier choice list (purchase-orders-plan.md Revision 0 §4 PO-3). A `Pick` of the
 * mirrored `Supplier` — never a hand-written projection — so C-1's check of `Supplier` holds it (the suppliers M-7 rule
 * for `ItemChoice`). `currency_code` is what the new order is sent in (PO D-5 (a)).
 */
export type SupplierChoice = Pick<Supplier, 'id' | 'code' | 'name' | 'currency_code' | 'archived_at'>;
/** A `PagedLoad<SupplierChoice>` for the paged list core (structurally; `lib` imports nothing from `app`). */
export type SupplierChoicesLoad =
  | { readonly kind: 'ok'; readonly items: SupplierChoice[]; readonly nextCursor: string | null; readonly previousCursor: string | null; readonly tenantHasRows: boolean }
  | { readonly kind: ReadFailure };

const isSupplierChoice = (x: unknown): x is SupplierChoice =>
  isObject(x) && typeof x.id === 'string' && typeof x.code === 'string' && typeof x.name === 'string' && typeof x.currency_code === 'string' && isNullableString(x.archived_at);

/**
 * `ListSuppliers` for the create page's supplier choice (the `loadItemChoices` shape, suppliers-final-red.md M-3/M-4): the
 * LIVE view only, one page for a search and a cursor, its query built only by `pageQuery(false, …)` — never `archived`,
 * `limit`, `sort` or `status`, at most one cursor, `q` as typed only when not empty. Each row must carry the five chosen
 * members, the answer both cursors and `tenant_has_rows` (without it a workspace whose suppliers are all archived would
 * read "No suppliers yet."). Run over the browser transport only (the dialog opens on a press).
 */
export async function loadSupplierChoices(read: Read, page: PageRequest, signal?: AbortSignal): Promise<SupplierChoicesLoad> {
  const a = await read('ListSuppliers', { query: pageQuery(false, page), signal });
  if (a.kind !== 'ok') return a;
  const body = a.body as { items?: unknown; next_cursor?: unknown; previous_cursor?: unknown; tenant_has_rows?: unknown } | null;
  const items = body?.items;
  if (!Array.isArray(items) || !items.every(isSupplierChoice) || !isCursor(body?.next_cursor) || !isCursor(body?.previous_cursor) || typeof body?.tenant_has_rows !== 'boolean') {
    return { kind: 'server-error' };
  }
  return { kind: 'ok', items, nextCursor: body.next_cursor, previousCursor: body.previous_cursor, tenantHasRows: body.tenant_has_rows };
}

/** PO-3: one option of the create page's warehouse select (Revision 10 R10-5): a `Pick` of the mirrored `Warehouse`. */
export type WarehouseChoice = Pick<Warehouse, 'id' | 'code' | 'name'>;
export type WarehouseChoicesLoad = { readonly kind: 'ok'; readonly warehouses: WarehouseChoice[] } | { readonly kind: ReadFailure };

const isWarehouseChoice = (x: unknown): x is WarehouseChoice => isObject(x) && typeof x.id === 'string' && typeof x.code === 'string' && typeof x.name === 'string';

/**
 * `ListWarehouses` for the create page's Deliver to select (plan §2.4): the LIVE view, the whole set in one answer (the
 * read is not paged), in served order. It sends no query at all — never `archived`, never `q` — so an archived warehouse is
 * never offered. Run over the server transport on first render, the browser one for the field's Retry.
 */
export async function loadWarehouseChoices(read: Read, signal?: AbortSignal): Promise<WarehouseChoicesLoad> {
  const a = await read('ListWarehouses', { query: new URLSearchParams(), signal });
  if (a.kind !== 'ok') return a;
  const body = a.body as { items?: unknown } | null;
  const items = body?.items;
  if (!Array.isArray(items) || !items.every(isWarehouseChoice)) return { kind: 'server-error' };
  return { kind: 'ok', warehouses: items.map(({ id, code, name }) => ({ id, code, name })) };
}

/**
 * PO-5: one option of the new line row's Item select (purchase-orders-plan.md §2.3, Revision 0 §4 PO-5): the mirrored
 * `SupplierProduct` less the three members the row never reads — never a hand-written projection — so C-1's check of
 * `SupplierProduct` holds it (an `Omit`, not a `Pick`: the G-3 scan reads the item reference's member name as a typed
 * resource key; a member added to `SupplierProduct` then fails the loader's projection below, at the typecheck). The item
 * reference is the option's value and label (`{sku} — {name}`); the unit is shown beside Quantity; the cost and its currency
 * decide D-7's prefill (only when the entry's currency is the order's).
 */
export type CatalogueChoice = Omit<SupplierProduct, 'supplier_sku' | 'lead_time_days' | 'minimum_order_quantity'>;
export type CatalogueChoicesLoad = { readonly kind: 'ok'; readonly entries: CatalogueChoice[] } | { readonly kind: ReadFailure };

const isCatalogueChoice = (x: unknown): x is CatalogueChoice =>
  isObject(x) &&
  typeof x.id === 'string' &&
  isObject(x.inventory_item) &&
  typeof x.inventory_item.id === 'string' &&
  typeof x.inventory_item.sku === 'string' &&
  typeof x.inventory_item.name === 'string' &&
  // A decimal string, as served (QR-1); prefilled untrimmed (PO D-7).
  typeof x.unit_cost === 'string' &&
  typeof x.currency_code === 'string' &&
  typeof x.unit_of_measure_code === 'string';

/**
 * `ListSupplierProducts` for the new line row's Item (plan Revision 0 §4 PO-5: `loadCatalogue`'s validation, re-implemented
 * here over the contract's `SupplierProduct`, not imported — R9-4 stays withdrawn): the order's supplier's whole catalogue
 * in one answer (G-42), in served order (supplier SKU). It sends the supplier id and nothing else. Each entry must carry the
 * five chosen members; the members beyond them are not read, so they are not checked here. A non-null `next_cursor` is never
 * served today; the row offers the entries it was given. Run over the server transport on first render, the browser one for
 * the row's Retry and its re-read after L1 / H-2.
 */
export async function loadCatalogueChoices(read: Read, supplierId: string, signal?: AbortSignal): Promise<CatalogueChoicesLoad> {
  const a = await read('ListSupplierProducts', { params: { id: supplierId }, signal });
  if (a.kind !== 'ok') return a;
  const body = a.body as { items?: unknown } | null;
  const items = body?.items;
  if (!Array.isArray(items) || !items.every(isCatalogueChoice)) return { kind: 'server-error' };
  return { kind: 'ok', entries: items.map(({ id, inventory_item, unit_cost, currency_code, unit_of_measure_code }) => ({ id, inventory_item: { id: inventory_item.id, sku: inventory_item.sku, name: inventory_item.name }, unit_cost, currency_code, unit_of_measure_code })) };
}
