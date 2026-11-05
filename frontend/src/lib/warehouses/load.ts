import type { Warehouse } from '../api/warehouses';
import type { Read, ReadFailure } from '../bff/reads';

// The warehouse pages' loaders (the lib/people/load.ts shape): pure, run over the server transport on first render and
// the browser one for every re-read.

export type WarehousesLoad = { readonly kind: 'ok'; readonly warehouses: Warehouse[]; readonly tenantHasRows: boolean } | { readonly kind: ReadFailure };
export type WarehouseLoad = { readonly kind: 'ok'; readonly warehouse: Warehouse } | { readonly kind: ReadFailure };

const isObject = (x: unknown): x is Record<string, unknown> => !!x && typeof x === 'object';
export const isWarehouse = (x: unknown): x is Warehouse => isObject(x) && typeof x.id === 'string' && typeof x.code === 'string' && typeof x.name === 'string' && typeof x.country_code === 'string';

/** `ListWarehouses`: the live view, or the Archived one (`archived=true`). Not paged: the whole view in one answer. */
export async function loadWarehouses(read: Read, archived: boolean, signal?: AbortSignal): Promise<WarehousesLoad> {
  const query = new URLSearchParams();
  if (archived) query.set('archived', 'true');
  const a = await read('ListWarehouses', { query, signal });
  if (a.kind !== 'ok') return a;
  const body = a.body as { items?: unknown; tenant_has_rows?: unknown } | null;
  const items = body?.items;
  if (!Array.isArray(items) || !items.every(isWarehouse) || typeof body?.tenant_has_rows !== 'boolean') return { kind: 'server-error' };
  return { kind: 'ok', warehouses: items, tenantHasRows: body.tenant_has_rows };
}

/** `GetWarehouse`: the page's first read, and its re-read after a save. */
export async function loadWarehouse(read: Read, id: string, signal?: AbortSignal): Promise<WarehouseLoad> {
  const a = await read('GetWarehouse', { params: { id }, signal });
  if (a.kind !== 'ok') return a;
  return isWarehouse(a.body) ? { kind: 'ok', warehouse: a.body } : { kind: 'server-error' };
}
