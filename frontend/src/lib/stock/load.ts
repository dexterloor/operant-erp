import type { StockMovement, StockMovementItemReference } from '../api/stock';
import { isRecordId } from '../bff/paths';
import { pageQuery, type PageRequest, type Read, type ReadFailure } from '../bff/reads';

// The stock family's loaders (stock-plan.md §3; b4-architecture.md Revision 11 R11-1, R11-2), pure and run over the server
// transport on first render and the browser one for every re-read. `ListStockMovements` is PAGED (Revision 8 R8-1),
// served newest first by business time, with no Archived view (a movement is never deleted, Revision 10 R10-3), no
// search (Revision 10 A-1) and one optional fixed filter, the item the address names (R10-4 / A-2; R11-2). Two narrow
// reads of other families' operations live here, never in another family's `lib/` (R11-1): the item's name for an empty
// filtered page (`GetItem`) and a receipt's number for a movement page (`GetGoodsReceipt`). Nothing here writes.

const isObject = (x: unknown): x is Record<string, unknown> => !!x && typeof x === 'object';
const isCursor = (x: unknown): x is string | null => x === null || typeof x === 'string';
const isNullableString = (x: unknown): x is string | null => x === null || typeof x === 'string';
const isItemReference = (x: unknown): x is StockMovementItemReference => isObject(x) && typeof x.id === 'string' && typeof x.sku === 'string' && typeof x.name === 'string';

export const isStockMovement = (x: unknown): x is StockMovement =>
  isObject(x) &&
  typeof x.id === 'string' &&
  // A signed decimal string, as served (QR-1).
  typeof x.quantity === 'string' &&
  typeof x.occurred_at === 'string' &&
  typeof x.reason === 'string' &&
  isNullableString(x.source_type) &&
  isNullableString(x.source_id) &&
  isItemReference(x.inventory_item) &&
  isObject(x.storage_location) &&
  typeof x.storage_location.id === 'string' &&
  typeof x.storage_location.code === 'string';

/** One page of movements, structurally a `PagedLoad<StockMovement>` (lib imports nothing from app). */
export type MovementsLoad =
  | { readonly kind: 'ok'; readonly items: StockMovement[]; readonly nextCursor: string | null; readonly previousCursor: string | null; readonly tenantHasRows: boolean }
  | { readonly kind: ReadFailure };
export type MovementLoad = { readonly kind: 'ok'; readonly movement: StockMovement } | { readonly kind: ReadFailure };
/** R10-4 / R11-2: the one fixed filter a movements list may carry, the served parameter's own name. */
export type MovementsFilter = { readonly name: 'inventory_item_id'; readonly value: string };

/**
 * `ListStockMovements`: one page for a cursor, its query built only by `pageQuery(false, page, undefined, filter)` — never
 * `archived`, `status`, `sort` or `limit`, at most one cursor, never `q` (the list has no search, so its `q` is always
 * empty). The filter, when given, rides on every request. `tenant_has_rows` is required: on a filtered empty page it says
 * whether the workspace has any movement at all (the server reads it unfiltered).
 */
export async function loadMovements(read: Read, page: PageRequest, signal?: AbortSignal, filter?: MovementsFilter): Promise<MovementsLoad> {
  const a = await read('ListStockMovements', { query: pageQuery(false, page, undefined, filter), signal });
  if (a.kind !== 'ok') return a;
  const body = a.body as { items?: unknown; next_cursor?: unknown; previous_cursor?: unknown; tenant_has_rows?: unknown } | null;
  const items = body?.items;
  if (!Array.isArray(items) || !items.every(isStockMovement) || !isCursor(body?.next_cursor) || !isCursor(body?.previous_cursor) || typeof body?.tenant_has_rows !== 'boolean') {
    return { kind: 'server-error' };
  }
  return { kind: 'ok', items, nextCursor: body.next_cursor, previousCursor: body.previous_cursor, tenantHasRows: body.tenant_has_rows };
}

/** `GetStockMovement`: the movement page's one main read, and a transfer leg's partner (the page's one secondary read). */
export async function loadMovement(read: Read, id: string, signal?: AbortSignal): Promise<MovementLoad> {
  const a = await read('GetStockMovement', { params: { id }, signal });
  if (a.kind !== 'ok') return a;
  return isStockMovement(a.body) ? { kind: 'ok', movement: a.body } : { kind: 'server-error' };
}

export type ItemNameLoad = { readonly kind: 'ok'; readonly item: StockMovementItemReference } | { readonly kind: ReadFailure };

/**
 * A narrow `GetItem` (R11-1; the `loadItemChoices` precedent): only the item's id, SKU and name, to name an EMPTY filtered
 * list (a page with rows is named from its first row, never read). Only the three members are checked.
 */
export async function loadItemName(read: Read, id: string, signal?: AbortSignal): Promise<ItemNameLoad> {
  const a = await read('GetItem', { params: { id }, signal });
  if (a.kind !== 'ok') return a;
  return isItemReference(a.body) ? { kind: 'ok', item: { id: a.body.id, sku: a.body.sku, name: a.body.name } } : { kind: 'server-error' };
}

export type ReceiptNumberLoad = { readonly kind: 'ok'; readonly receiptNumber: string } | { readonly kind: ReadFailure };

/**
 * A narrow `GetGoodsReceipt` (R11-1): only the receipt's number, for a receipt movement's page, read only for a viewer who
 * may open the receipt (stock-reads N-2: a number is shown only after the viewer's own allowed read).
 */
export async function loadReceiptNumber(read: Read, id: string, signal?: AbortSignal): Promise<ReceiptNumberLoad> {
  const a = await read('GetGoodsReceipt', { params: { id }, signal });
  if (a.kind !== 'ok') return a;
  const body = a.body as { receipt_number?: unknown } | null;
  return typeof body?.receipt_number === 'string' ? { kind: 'ok', receiptNumber: body.receipt_number } : { kind: 'server-error' };
}

/** What the address names (R11-2): all movements, one item's, or a value that may never be sent. */
export type AddressedItem = { readonly kind: 'all' } | { readonly kind: 'item'; readonly id: string } | { readonly kind: 'invalid' };

/**
 * The item filter the address holds (`?inventory_item_id=<uuid>`, the served name; R11-2; UX S-9). Absent → all
 * movements. Exactly one value that `isRecordId` accepts → that item. Anything else — not a record id, empty, repeated —
 * is `invalid`: no list read is sent, and the page shows the family's "unknown item" state (never a 422 on screen).
 */
export function addressedItem(query: Readonly<Record<string, string | string[] | undefined>>): AddressedItem {
  const value = query.inventory_item_id;
  if (value === undefined) return { kind: 'all' };
  if (typeof value === 'string' && isRecordId(value)) return { kind: 'item', id: value };
  return { kind: 'invalid' };
}

// Stock ST-2 (stock-st2-plan.md §3.2; Revision 11 R11-1, the `loadLocationChoices` precedent): the narrow reads of the
// location and warehouse facts Stock on hand needs, for its picker and for each row's marker. They name no stock read and
// this file imports no stock-on-hand file, so the movement pages, which import this file, never reach one (G-47).

/** One location as Stock on hand reads it: its id, its code (the picker's links) and its served kind (the marker). */
export interface MarkerLocation {
  readonly id: string;
  readonly code: string;
  readonly kind: string;
}
/** One warehouse as Stock on hand reads it: its id, code, name and whether it is archived. */
export interface MarkerWarehouse {
  readonly id: string;
  readonly code: string;
  readonly name: string;
  readonly archived_at: string | null;
}
export type MarkerLocationsLoad = { readonly kind: 'ok'; readonly locations: MarkerLocation[] } | { readonly kind: ReadFailure };
export type MarkerWarehouseLoad = { readonly kind: 'ok'; readonly warehouse: MarkerWarehouse } | { readonly kind: ReadFailure };
export type MarkerWarehousesLoad = { readonly kind: 'ok'; readonly warehouses: MarkerWarehouse[]; readonly tenantHasRows: boolean } | { readonly kind: ReadFailure };

const isMarkerLocation = (x: unknown): x is MarkerLocation => isObject(x) && typeof x.id === 'string' && typeof x.code === 'string' && typeof x.kind === 'string';
const isMarkerWarehouse = (x: unknown): x is MarkerWarehouse =>
  isObject(x) && typeof x.id === 'string' && typeof x.code === 'string' && typeof x.name === 'string' && isNullableString(x.archived_at);
const asMarkerWarehouse = ({ id, code, name, archived_at }: MarkerWarehouse): MarkerWarehouse => ({ id, code, name, archived_at });

/**
 * `ListStorageLocations` for one warehouse, the live view (no query) or the Archived view (`archived=true`), the whole set
 * in one answer: an answer that says there is more is not the whole set, so it is a server error (gr-2-red.md L-2).
 * Only each location's id, code and kind are kept.
 */
export async function loadMarkerLocations(read: Read, warehouseId: string, archived: boolean, signal?: AbortSignal): Promise<MarkerLocationsLoad> {
  const query = new URLSearchParams();
  if (archived) query.set('archived', 'true');
  const a = await read('ListStorageLocations', { params: { id: warehouseId }, query, signal });
  if (a.kind !== 'ok') return a;
  const body = a.body as { items?: unknown; next_cursor?: unknown } | null;
  const items = body?.items;
  if (!Array.isArray(items) || !items.every(isMarkerLocation) || (body?.next_cursor !== undefined && body.next_cursor !== null)) return { kind: 'server-error' };
  return { kind: 'ok', locations: items.map(({ id, code, kind }) => ({ id, code, kind })) };
}

/** `GetWarehouse`: one warehouse's id, code, name and archived state (it answers an archived warehouse too). */
export async function loadMarkerWarehouse(read: Read, id: string, signal?: AbortSignal): Promise<MarkerWarehouseLoad> {
  const a = await read('GetWarehouse', { params: { id }, signal });
  if (a.kind !== 'ok') return a;
  return isMarkerWarehouse(a.body) ? { kind: 'ok', warehouse: asMarkerWarehouse(a.body) } : { kind: 'server-error' };
}

/**
 * `ListWarehouses`, the live view (no query) or the Archived view (`archived=true`), the whole set in one answer (the read
 * is bounded, not paged). `tenant_has_rows` is required: on an empty live view it says whether any warehouse exists.
 */
export async function loadMarkerWarehouses(read: Read, archived: boolean, signal?: AbortSignal): Promise<MarkerWarehousesLoad> {
  const query = new URLSearchParams();
  if (archived) query.set('archived', 'true');
  const a = await read('ListWarehouses', { query, signal });
  if (a.kind !== 'ok') return a;
  const body = a.body as { items?: unknown; next_cursor?: unknown; tenant_has_rows?: unknown } | null;
  const items = body?.items;
  if (!Array.isArray(items) || !items.every(isMarkerWarehouse) || (body?.next_cursor !== undefined && body.next_cursor !== null) || typeof body?.tenant_has_rows !== 'boolean') {
    return { kind: 'server-error' };
  }
  return { kind: 'ok', warehouses: items.map(asMarkerWarehouse), tenantHasRows: body.tenant_has_rows };
}

/** One item as Stock on hand's one-item view reads it: its id, SKU, name and unit (the Unit column; UX §B-4). */
export interface StockItem {
  readonly id: string;
  readonly sku: string;
  readonly name: string;
  readonly unit_of_measure_code: string;
}
export type StockItemLoad = { readonly kind: 'ok'; readonly item: StockItem } | { readonly kind: ReadFailure };

/**
 * A narrow `GetItem` (R11-1) for the one-item view: the item's id, SKU, name and unit. An item's unit cannot change once
 * any stock row names it, so it is exactly the unit the rows' figures are in (UX A-2). Only the four members are checked,
 * and an answer naming another item is a server error (never another item's unit beside these rows).
 */
export async function loadStockItem(read: Read, id: string, signal?: AbortSignal): Promise<StockItemLoad> {
  const a = await read('GetItem', { params: { id }, signal });
  if (a.kind !== 'ok') return a;
  const b = a.body;
  const unit = isObject(b) ? b.unit_of_measure_code : undefined;
  return isItemReference(b) && b.id === id && typeof unit === 'string' ? { kind: 'ok', item: { id: b.id, sku: b.sku, name: b.name, unit_of_measure_code: unit } } : { kind: 'server-error' };
}
