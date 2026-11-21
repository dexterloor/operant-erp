import type { StockLevel } from '../api/stock';
import { isRecordId } from '../bff/paths';
import type { Read, ReadFailure } from '../bff/reads';
import { loadMarkerLocations, loadMarkerWarehouse, loadMarkerWarehouses, type MarkerLocationsLoad, type MarkerWarehouseLoad, type MarkerWarehousesLoad } from './load';
import type { Loaded, LocationFact, MarkerInputs, WarehouseMarkerInputs } from './marker';

// Stock on hand's loaders (stock-st2-plan.md §3.1; UX `stock-st2-ux-copy.md` §B-1): the ONE lib file that names the stock
// read (G-47's pin, rule (b)). Pure, run over the server transport on first render and over the browser one for the
// marker Retry. Everything else Stock on hand reads (locations, warehouses, the item) goes through `./load`, which never
// names the stock read, so the movement pages that import `./load` never reach this file.
// The read is bounded: exactly one of `storage_location_id` / `inventory_item_id`, the whole set in one answer, both
// cursors always null. An answer holding a row of another scope is a server error, so a row is never shown under the
// wrong place's badge (plan D-10, L-10). Nothing here decides who may see rows: the server page does, before any read.

const isObject = (x: unknown): x is Record<string, unknown> => !!x && typeof x === 'object';
const isIdCode = (x: unknown): boolean => isObject(x) && typeof x.id === 'string' && typeof x.code === 'string';

/** A stock-on-hand row, checked member by member as served. */
export const isStockLevel = (x: unknown): x is StockLevel =>
  isObject(x) &&
  typeof x.id === 'string' &&
  isObject(x.inventory_item) &&
  typeof x.inventory_item.id === 'string' &&
  typeof x.inventory_item.sku === 'string' &&
  typeof x.inventory_item.name === 'string' &&
  isIdCode(x.storage_location) &&
  isObject(x.warehouse) &&
  typeof x.warehouse.id === 'string' &&
  typeof x.warehouse.name === 'string' &&
  // A decimal string, as served (QR-1).
  typeof x.qty_on_hand === 'string' &&
  typeof x.version === 'number';

export type StockLevelsLoad = { readonly kind: 'ok'; readonly rows: StockLevel[]; readonly tenantHasRows: boolean } | { readonly kind: ReadFailure };

/** What the address names (UX S-11, L-1, L-2): one of five shapes. Unknown keys are ignored. */
export type AddressedStock =
  | { readonly kind: 'warehouses' }
  | { readonly kind: 'locations'; readonly warehouseId: string }
  | { readonly kind: 'location'; readonly warehouseId: string; readonly locationId: string }
  | { readonly kind: 'item'; readonly itemId: string }
  | { readonly kind: 'bad' };

const SCOPE_KEYS = ['warehouse_id', 'storage_location_id', 'inventory_item_id'] as const;

/**
 * The scope the address names. Every scope value present must be exactly one record id (a repeated key, an empty or a
 * malformed value is `bad`, never a narrower scope). A location needs its warehouse; an item stands alone; anything else
 * is `bad`, and the page shows the picker with `badAddress`, sending nothing.
 */
export function addressedStock(query: Readonly<Record<string, string | string[] | undefined>>): AddressedStock {
  const ids: Partial<Record<(typeof SCOPE_KEYS)[number], string>> = {};
  for (const key of SCOPE_KEYS) {
    const value = query[key];
    if (value === undefined) continue;
    if (typeof value !== 'string' || !isRecordId(value)) return { kind: 'bad' };
    ids[key] = value;
  }
  const w = ids.warehouse_id;
  const l = ids.storage_location_id;
  const i = ids.inventory_item_id;
  if (i !== undefined) return w === undefined && l === undefined ? { kind: 'item', itemId: i } : { kind: 'bad' };
  if (l !== undefined) return w !== undefined ? { kind: 'location', warehouseId: w, locationId: l } : { kind: 'bad' };
  if (w !== undefined) return { kind: 'locations', warehouseId: w };
  return { kind: 'warehouses' };
}

async function loadStockLevels(read: Read, filter: { readonly name: 'storage_location_id' | 'inventory_item_id'; readonly value: string }, belongs: (row: StockLevel) => boolean, signal?: AbortSignal): Promise<StockLevelsLoad> {
  const query = new URLSearchParams();
  query.set(filter.name, filter.value);
  const a = await read('ListStockLevels', { query, signal });
  if (a.kind !== 'ok') return a;
  const body = a.body as { items?: unknown; next_cursor?: unknown; previous_cursor?: unknown; tenant_has_rows?: unknown } | null;
  const items = body?.items;
  if (!Array.isArray(items) || !items.every(isStockLevel) || body?.next_cursor !== null || body?.previous_cursor !== null || typeof body?.tenant_has_rows !== 'boolean') {
    return { kind: 'server-error' };
  }
  if (!items.every(belongs)) return { kind: 'server-error' };
  return { kind: 'ok', rows: items, tenantHasRows: body.tenant_has_rows };
}

/**
 * One location's rows: the read is sent with `storage_location_id` only (the warehouse is page-only, never sent). Every
 * row must name that location AND that warehouse.
 */
export function loadStockForLocation(read: Read, warehouseId: string, locationId: string, signal?: AbortSignal): Promise<StockLevelsLoad> {
  return loadStockLevels(read, { name: 'storage_location_id', value: locationId }, (r) => r.storage_location.id === locationId && r.warehouse.id === warehouseId, signal);
}

/** One item's rows, in every location it has been stored in. Every row must name that item. */
export function loadStockForItem(read: Read, itemId: string, signal?: AbortSignal): Promise<StockLevelsLoad> {
  return loadStockLevels(read, { name: 'inventory_item_id', value: itemId }, (r) => r.inventory_item.id === itemId, signal);
}

/** Which marker facts to read: one location's warehouse, or every warehouse one item's rows name (in row order, no repeats). */
export type MarkerScope = { readonly kind: 'location'; readonly warehouseId: string } | { readonly kind: 'item'; readonly warehouseIds: readonly string[] };

/** One warehouse's two location lists, as loaded. */
export interface LocationLists {
  readonly live: MarkerLocationsLoad;
  readonly archived: MarkerLocationsLoad;
}

/**
 * The marker facts, or why they cannot be used at all. A 401 on any read ends the session; a 403 on any read means the
 * viewer lost a marker code, and names the operations that answered 403 (S-15's `missing`). Otherwise every failure is
 * kept as `failed` in its entry, never a missing key, so the marker says Status unknown.
 */
export type MarkerInputsLoad =
  | {
      readonly kind: 'ok';
      readonly inputs: MarkerInputs;
      /** Each warehouse's location lists, for the location scope's "is L here?" and the picker-free code. */
      readonly lists: ReadonlyMap<string, LocationLists>;
      /** Location scope: the warehouse's own read. Item scope: null. */
      readonly warehouse: MarkerWarehouseLoad | null;
      /** A warehouse's code, only where its state loaded (item scope: found once across both lists). */
      readonly warehouseCodes: ReadonlyMap<string, string>;
    }
  | { readonly kind: 'session-ended' }
  | { readonly kind: 'forbidden'; readonly operations: readonly string[] };

const facts = (l: MarkerLocationsLoad): Loaded<readonly LocationFact[]> => (l.kind === 'ok' ? { kind: 'loaded', value: l.locations.map(({ id, kind }) => ({ id, kind })) } : { kind: 'failed' });

/**
 * Reads the marker facts for a scope, concurrently: one location's scope reads the warehouse and its two location lists
 * (3 reads); one item's scope reads both warehouse lists and each named warehouse's two location lists (2N + 2 reads).
 * In the item scope a warehouse's state counts as loaded only when both warehouse lists loaded and the warehouse is in
 * exactly one of them (UX §B-5); then its code is known.
 */
export async function loadMarkerInputs(read: Read, scope: MarkerScope, signal?: AbortSignal): Promise<MarkerInputsLoad> {
  const ids = scope.kind === 'location' ? [scope.warehouseId] : [...new Set(scope.warehouseIds)];
  const listsOf = (w: string) => Promise.all([loadMarkerLocations(read, w, false, signal), loadMarkerLocations(read, w, true, signal)]);
  const [warehouse, warehouseLists, locationLists] = await Promise.all([
    scope.kind === 'location' ? loadMarkerWarehouse(read, scope.warehouseId, signal) : Promise.resolve(null),
    scope.kind === 'item' ? Promise.all([loadMarkerWarehouses(read, false, signal), loadMarkerWarehouses(read, true, signal)]) : Promise.resolve(null),
    Promise.all(ids.map(listsOf)),
  ]);

  const outcomes: [string, { readonly kind: string }][] = [];
  if (warehouse !== null) outcomes.push(['GetWarehouse', warehouse]);
  if (warehouseLists !== null) for (const w of warehouseLists) outcomes.push(['ListWarehouses', w]);
  for (const [live, archived] of locationLists) outcomes.push(['ListStorageLocations', live], ['ListStorageLocations', archived]);
  if (outcomes.some(([, o]) => o.kind === 'session-ended')) return { kind: 'session-ended' };
  const forbidden = [...new Set(outcomes.filter(([, o]) => o.kind === 'forbidden').map(([op]) => op))];
  if (forbidden.length > 0) return { kind: 'forbidden', operations: forbidden };

  const inputs = new Map<string, WarehouseMarkerInputs>();
  const lists = new Map<string, LocationLists>();
  const warehouseCodes = new Map<string, string>();
  ids.forEach((w, n) => {
    const [live, archived] = locationLists[n]!;
    lists.set(w, { live, archived });
    let archivedAt: Loaded<string | null> = { kind: 'failed' };
    if (warehouse !== null && warehouse.kind === 'ok' && warehouse.warehouse.id === w) {
      archivedAt = { kind: 'loaded', value: warehouse.warehouse.archived_at };
      warehouseCodes.set(w, warehouse.warehouse.code);
    } else if (warehouseLists !== null) {
      const [liveW, archivedW] = warehouseLists as [MarkerWarehousesLoad, MarkerWarehousesLoad];
      if (liveW.kind === 'ok' && archivedW.kind === 'ok') {
        const found = [...liveW.warehouses, ...archivedW.warehouses].filter((x) => x.id === w);
        if (found.length === 1) {
          archivedAt = { kind: 'loaded', value: found[0]!.archived_at };
          warehouseCodes.set(w, found[0]!.code);
        }
      }
    }
    inputs.set(w, { warehouseId: w, archivedAt, live: facts(live), archived: facts(archived) });
  });
  return { kind: 'ok', inputs, lists, warehouse, warehouseCodes };
}
