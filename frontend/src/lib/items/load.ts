import type { Item } from '../api/items';
import { pageQuery, type PageRequest, type Read, type ReadFailure } from '../bff/reads';

// The item pages' loaders (the lib/warehouses/load.ts shape): pure, run over the server transport on first render and
// the browser one for every re-read. `ListItems` is PAGED (Revision 8 R8-1): its query is built only by `pageQuery()`
// (never `limit`, at most one cursor, `q` as typed only when not empty), and it never sends `supplier_id` (the filter is
// out of this build; with `archived` it is refused). Held by behaviour, not by type: `test/wired/items-list.test.tsx`
// drives this loader and asserts each request it sends (Revision 8 amendment 6, limit stated).

export type ItemsLoad =
  | { readonly kind: 'ok'; readonly items: Item[]; readonly nextCursor: string | null; readonly previousCursor: string | null; readonly tenantHasRows: boolean }
  | { readonly kind: ReadFailure };
export type ItemLoad = { readonly kind: 'ok'; readonly item: Item } | { readonly kind: ReadFailure };

const isObject = (x: unknown): x is Record<string, unknown> => !!x && typeof x === 'object';
const isCursor = (x: unknown): x is string | null => x === null || typeof x === 'string';
const isSupplier = (x: unknown) => x === null || (isObject(x) && typeof x.id === 'string' && typeof x.name === 'string');
export const isItem = (x: unknown): x is Item =>
  isObject(x) &&
  typeof x.id === 'string' &&
  typeof x.sku === 'string' &&
  typeof x.name === 'string' &&
  typeof x.unit_of_measure_code === 'string' &&
  typeof x.reorder_point === 'string' &&
  typeof x.reorder_quantity === 'string' &&
  typeof x.safety_stock === 'string' &&
  isSupplier(x.supplier) &&
  (x.archived_at === null || typeof x.archived_at === 'string');

/**
 * `ListItems`: one page of the live view, or of the Archived one (`archived=true`), for a search and a cursor.
 * `tenant_has_rows` is served on every answer but meaningful only on an empty one (A-17 item 5; the core reads it only
 * then); an answer without it is treated as malformed, as for warehouses.
 */
export async function loadItems(read: Read, archived: boolean, page: PageRequest, signal?: AbortSignal): Promise<ItemsLoad> {
  const a = await read('ListItems', { query: pageQuery(archived, page), signal });
  if (a.kind !== 'ok') return a;
  const body = a.body as { items?: unknown; next_cursor?: unknown; previous_cursor?: unknown; tenant_has_rows?: unknown } | null;
  const items = body?.items;
  if (!Array.isArray(items) || !items.every(isItem) || !isCursor(body?.next_cursor) || !isCursor(body?.previous_cursor) || typeof body?.tenant_has_rows !== 'boolean') {
    return { kind: 'server-error' };
  }
  return { kind: 'ok', items, nextCursor: body.next_cursor, previousCursor: body.previous_cursor, tenantHasRows: body.tenant_has_rows };
}

/** `GetItem`: the page's first read, and its re-read after a save or a restore. */
export async function loadItem(read: Read, id: string, signal?: AbortSignal): Promise<ItemLoad> {
  const a = await read('GetItem', { params: { id }, signal });
  if (a.kind !== 'ok') return a;
  return isItem(a.body) ? { kind: 'ok', item: a.body } : { kind: 'server-error' };
}
