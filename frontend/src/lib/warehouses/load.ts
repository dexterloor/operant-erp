import type { StorageLocation, Warehouse } from '../api/warehouses';
import type { Read, ReadFailure } from '../bff/reads';

// The warehouse pages' loaders (the lib/people/load.ts shape): pure, run over the server transport on first render and
// the browser one for every re-read.

export type WarehousesLoad = { readonly kind: 'ok'; readonly warehouses: Warehouse[]; readonly tenantHasRows: boolean } | { readonly kind: ReadFailure };
export type LocationsLoad = { readonly kind: 'ok'; readonly locations: StorageLocation[] } | { readonly kind: ReadFailure };
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

export const isStorageLocation = (x: unknown): x is StorageLocation =>
  isObject(x) && typeof x.id === 'string' && typeof x.code === 'string' && typeof x.kind === 'string' && (x.archived_at === null || typeof x.archived_at === 'string');

/**
 * `ListStorageLocations` (§2 step 4.4): one warehouse's live locations, or its Archived ones (`archived=true`), in the
 * server's order (code). Not paged: the whole view in one answer. `tenant_has_rows` is NOT carried: it probes the whole
 * workspace (`StorageLocationReader.php:63`), so the section never reads it (UX B-5 "Empty (live)").
 */
export async function loadLocations(read: Read, warehouseId: string, archived: boolean, signal?: AbortSignal): Promise<LocationsLoad> {
  const query = new URLSearchParams();
  if (archived) query.set('archived', 'true');
  const a = await read('ListStorageLocations', { params: { id: warehouseId }, query, signal });
  if (a.kind !== 'ok') return a;
  const items = (a.body as { items?: unknown } | null)?.items;
  if (!Array.isArray(items) || !items.every(isStorageLocation)) return { kind: 'server-error' };
  return { kind: 'ok', locations: items };
}
