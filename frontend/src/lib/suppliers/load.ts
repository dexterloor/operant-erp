import type { Supplier, SupplierProduct } from '../api/suppliers';
import { pageQuery, type PageRequest, type Read, type ReadFailure } from '../bff/reads';

// The supplier pages' loaders (the lib/items/load.ts shape): pure, run over the server transport on first render and
// the browser one for every re-read. `ListSuppliers` is PAGED (Revision 8 R8-1): its query is built only by `pageQuery()`
// (never `limit`, at most one cursor, `q` as typed only when not empty), and it never sends `sort`: the live view's
// served order is the default one (name), and the Archived view's only order is newest archived first
// (suppliers-plan.md §0, §1.2). Held by behaviour, not by type: `test/wired/suppliers-list.test.tsx` drives this loader
// and asserts each request it sends (Revision 8 amendment 6, limit stated).

export type SuppliersLoad =
  | { readonly kind: 'ok'; readonly items: Supplier[]; readonly nextCursor: string | null; readonly previousCursor: string | null; readonly tenantHasRows: boolean }
  | { readonly kind: ReadFailure };
export type SupplierLoad = { readonly kind: 'ok'; readonly supplier: Supplier } | { readonly kind: ReadFailure };
/** `nextCursor` is non-null only when the answer is not the whole catalogue (G-42: today the read takes no `limit`). */
export type CatalogueLoad = { readonly kind: 'ok'; readonly entries: SupplierProduct[]; readonly nextCursor: string | null } | { readonly kind: ReadFailure };

const isObject = (x: unknown): x is Record<string, unknown> => !!x && typeof x === 'object';
const isCursor = (x: unknown): x is string | null => x === null || typeof x === 'string';
export const isSupplier = (x: unknown): x is Supplier =>
  isObject(x) &&
  typeof x.id === 'string' &&
  typeof x.code === 'string' &&
  typeof x.name === 'string' &&
  typeof x.country_code === 'string' &&
  typeof x.currency_code === 'string' &&
  // Served `integer`: a string, a fraction or a missing member is a malformed body, never a row.
  typeof x.payment_terms_days === 'number' &&
  Number.isInteger(x.payment_terms_days) &&
  (x.archived_at === null || typeof x.archived_at === 'string');

/**
 * `ListSuppliers`: one page of the live view, or of the Archived one (`archived=true`), for a search and a cursor.
 * `tenant_has_rows` is served on every answer but meaningful only on an empty one (A-17 item 5; the core reads it only
 * then); an answer without it is treated as malformed, as for items.
 */
export async function loadSuppliers(read: Read, archived: boolean, page: PageRequest, signal?: AbortSignal): Promise<SuppliersLoad> {
  const a = await read('ListSuppliers', { query: pageQuery(archived, page), signal });
  if (a.kind !== 'ok') return a;
  const body = a.body as { items?: unknown; next_cursor?: unknown; previous_cursor?: unknown; tenant_has_rows?: unknown } | null;
  const items = body?.items;
  if (!Array.isArray(items) || !items.every(isSupplier) || !isCursor(body?.next_cursor) || !isCursor(body?.previous_cursor) || typeof body?.tenant_has_rows !== 'boolean') {
    return { kind: 'server-error' };
  }
  return { kind: 'ok', items, nextCursor: body.next_cursor, previousCursor: body.previous_cursor, tenantHasRows: body.tenant_has_rows };
}

/** `GetSupplier`: the page's first read, and its re-read after a save or a restore. */
export async function loadSupplier(read: Read, id: string, signal?: AbortSignal): Promise<SupplierLoad> {
  const a = await read('GetSupplier', { params: { id }, signal });
  if (a.kind !== 'ok') return a;
  return isSupplier(a.body) ? { kind: 'ok', supplier: a.body } : { kind: 'server-error' };
}

const isWholeNumber = (x: unknown): x is number => typeof x === 'number' && Number.isInteger(x);
export const isSupplierProduct = (x: unknown): x is SupplierProduct =>
  isObject(x) &&
  typeof x.id === 'string' &&
  typeof x.supplier_sku === 'string' &&
  isObject(x.inventory_item) &&
  typeof x.inventory_item.id === 'string' &&
  typeof x.inventory_item.sku === 'string' &&
  typeof x.inventory_item.name === 'string' &&
  // Decimal strings, as served; a number here is a malformed body (QR-1: never a double).
  typeof x.unit_cost === 'string' &&
  typeof x.currency_code === 'string' &&
  isWholeNumber(x.lead_time_days) &&
  typeof x.minimum_order_quantity === 'string';

/**
 * `ListSupplierProducts` (suppliers-plan.md Revision 1 — FINAL §R1-4 S-3; G-42): the whole catalogue in one answer, in
 * served order (supplier SKU). It sends the supplier id and nothing else — no `limit`, no cursor, no `q` — and reports the
 * answer's `next_cursor`, so a later paging change shows the section's partial notice instead of truncating silently
 * (§6 R-1). Run over the server transport on first render and the browser one for the section's Retry.
 */
export async function loadCatalogue(read: Read, supplierId: string, signal?: AbortSignal): Promise<CatalogueLoad> {
  const a = await read('ListSupplierProducts', { params: { id: supplierId }, signal });
  if (a.kind !== 'ok') return a;
  const body = a.body as { items?: unknown; next_cursor?: unknown } | null;
  const items = body?.items;
  if (!Array.isArray(items) || !items.every(isSupplierProduct) || !isCursor(body?.next_cursor)) return { kind: 'server-error' };
  return { kind: 'ok', entries: items, nextCursor: body.next_cursor };
}
