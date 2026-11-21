// @vitest-environment node
import { describe, expect, it } from 'vitest';
import type { Read, ReadAnswer, ReadRequest } from '@/lib/bff/reads';
import { addressedStock, loadMarkerInputs, loadStockForItem, loadStockForLocation } from '@/lib/stock/levels';

// Stock ST-2 (stock-st2-plan.md §7.3): the address grammar (UX S-11, L-1, L-2), the stock loaders' guards (a row of
// another scope is a server error, plan L-10), and the marker reads' order and count (2N + 2 reads, 401/403 out of band,
// partial failures kept as `failed`).

const W = 'a0000000-0000-4000-8000-00000000000a';
const W2 = 'b0000000-0000-4000-8000-00000000000b';
const L = 'c0000000-0000-4000-8000-00000000000c';
const I = 'e0000000-0000-4000-8000-00000000000e';

describe('addressedStock — every S-11 shape', () => {
  it('the four good shapes; unknown keys ignored', () => {
    expect(addressedStock({})).toEqual({ kind: 'warehouses' });
    expect(addressedStock({ warehouse_id: W })).toEqual({ kind: 'locations', warehouseId: W });
    expect(addressedStock({ warehouse_id: W, storage_location_id: L })).toEqual({ kind: 'location', warehouseId: W, locationId: L });
    expect(addressedStock({ inventory_item_id: I })).toEqual({ kind: 'item', itemId: I });
    expect(addressedStock({ warehouse_id: W, q: 'A-', utm: 'x' })).toEqual({ kind: 'locations', warehouseId: W });
  });

  it('bad: both scope ids, a bin alone, a warehouse beside an item, a repeated id, a malformed or empty id of each key', () => {
    for (const q of [
      { warehouse_id: W, storage_location_id: L, inventory_item_id: I },
      { storage_location_id: L, inventory_item_id: I },
      { storage_location_id: L },
      { warehouse_id: W, inventory_item_id: I },
      { warehouse_id: [W, W] },
      { warehouse_id: [W, W2], storage_location_id: L },
      { warehouse_id: 'not-a-uuid' },
      { warehouse_id: W.toUpperCase() },
      { warehouse_id: W, storage_location_id: 'x' },
      { inventory_item_id: '' },
      { inventory_item_id: [I] },
    ]) {
      expect(addressedStock(q), JSON.stringify(q)).toEqual({ kind: 'bad' });
    }
  });
});

type Answer = ReadAnswer | ((request: ReadRequest) => ReadAnswer);
const recorder = (answers: Record<string, Answer>) => {
  const calls: [string, ReadRequest][] = [];
  const read: Read = async (op, request = {}) => {
    calls.push([op, request]);
    const view = `${op}${request.query?.get('archived') === 'true' ? ':archived' : ''}`;
    const key = request.params?.id ? `${view}:${request.params.id}` : view;
    const a = answers[key] ?? answers[view] ?? answers[op];
    if (a === undefined) throw new Error(`unexpected read ${key}`);
    return typeof a === 'function' ? a(request) : a;
  };
  return { read, calls };
};
const ok = (body: unknown): ReadAnswer => ({ kind: 'ok', body });
const row = (over: { w?: string; l?: string; i?: string } = {}) => ({
  id: `f0000000-0000-4000-8000-${Math.random().toString(16).slice(2, 14).padEnd(12, '0')}`,
  inventory_item: { id: over.i ?? I, sku: 'WID-001', name: 'Widget' },
  storage_location: { id: over.l ?? L, code: 'A-01' },
  warehouse: { id: over.w ?? W, name: 'Main' },
  qty_on_hand: '3.000000',
  version: 1,
});
const page = (items: unknown[], over: Record<string, unknown> = {}) => ok({ items, next_cursor: null, previous_cursor: null, tenant_has_rows: true, ...over });

describe('the stock loaders — a row of another scope is a server error', () => {
  it('one location: sends storage_location_id only (never warehouse_id); accepts its own rows', async () => {
    const { read, calls } = recorder({ ListStockLevels: page([row()]) });
    expect(await loadStockForLocation(read, W, L)).toMatchObject({ kind: 'ok', tenantHasRows: true });
    expect(calls).toHaveLength(1);
    expect([...calls[0]![1].query!.entries()]).toEqual([['storage_location_id', L]]);
  });

  it('one location: a row of another bin, or of another warehouse, is refused (L-10); so is a cursor or a bad body', async () => {
    for (const answer of [page([row(), row({ l: W2 })]), page([row({ w: W2 })]), page([row()], { next_cursor: 'x' }), page([row()], { tenant_has_rows: undefined }), page([{ ...row(), qty_on_hand: 3 }]), ok(null)]) {
      expect(await loadStockForLocation(recorder({ ListStockLevels: answer }).read, W, L)).toEqual({ kind: 'server-error' });
    }
  });

  it('one item: sends inventory_item_id only; a row of another item is refused', async () => {
    const { read, calls } = recorder({ ListStockLevels: page([row({ w: W2 })]) });
    expect(await loadStockForItem(read, I)).toMatchObject({ kind: 'ok' });
    expect([...calls[0]![1].query!.entries()]).toEqual([['inventory_item_id', I]]);
    expect(await loadStockForItem(recorder({ ListStockLevels: page([row({ i: W2 })]) }).read, I)).toEqual({ kind: 'server-error' });
  });

  it('a failed read passes through', async () => {
    expect(await loadStockForItem(recorder({ ListStockLevels: { kind: 'forbidden' } }).read, I)).toEqual({ kind: 'forbidden' });
  });
});

const loc = (id: string, kind = 'bin', code = 'A-01') => ({ id, code, kind, archived_at: null });
const wh = (id: string, archived_at: string | null = null, code = 'WH-1') => ({ id, code, name: 'Main', country_code: 'GB', archived_at });
const locs = (items: unknown[]) => ok({ items, next_cursor: null, previous_cursor: null, tenant_has_rows: true });
const whs = (items: unknown[]) => ok({ items, next_cursor: null, previous_cursor: null, tenant_has_rows: true });

describe('loadMarkerInputs', () => {
  it('the item scope makes exactly 2N + 2 reads for N distinct warehouses (repeats and order kept once)', async () => {
    const { read, calls } = recorder({ ListStorageLocations: locs([]), ListWarehouses: whs([wh(W), wh(W2)]) });
    const r = await loadMarkerInputs(read, { kind: 'item', warehouseIds: [W, W2, W] });
    expect(r.kind).toBe('ok');
    expect(calls).toHaveLength(2 * 2 + 2);
    expect(calls.filter(([op]) => op === 'ListWarehouses').map(([, q]) => q.query!.toString()).sort()).toEqual(['', 'archived=true']);
    expect(calls.filter(([op]) => op === 'ListStorageLocations').map(([, q]) => `${q.params!.id}?${q.query!.toString()}`).sort()).toEqual([`${W2}?`, `${W2}?archived=true`, `${W}?`, `${W}?archived=true`].sort());
  });

  it('the location scope reads the warehouse and its two lists (3 reads)', async () => {
    const { read, calls } = recorder({ GetWarehouse: ok(wh(W)), ListStorageLocations: locs([loc(L)]) });
    const r = await loadMarkerInputs(read, { kind: 'location', warehouseId: W });
    expect(calls.map(([op]) => op).sort()).toEqual(['GetWarehouse', 'ListStorageLocations', 'ListStorageLocations']);
    expect(r.kind === 'ok' && r.warehouseCodes.get(W)).toBe('WH-1');
  });

  it('a 403 on any read → forbidden, naming exactly the operations that answered 403; a 401 → session-ended, which wins', async () => {
    let r = await loadMarkerInputs(recorder({ GetWarehouse: ok(wh(W)), 'ListStorageLocations:archived': { kind: 'forbidden' }, ListStorageLocations: locs([]) }).read, { kind: 'location', warehouseId: W });
    expect(r).toEqual({ kind: 'forbidden', operations: ['ListStorageLocations'] });
    r = await loadMarkerInputs(recorder({ ListWarehouses: { kind: 'forbidden' }, ListStorageLocations: locs([]) }).read, { kind: 'item', warehouseIds: [W] });
    expect(r).toEqual({ kind: 'forbidden', operations: ['ListWarehouses'] });
    r = await loadMarkerInputs(recorder({ GetWarehouse: { kind: 'forbidden' }, ListStorageLocations: { kind: 'session-ended' } }).read, { kind: 'location', warehouseId: W });
    expect(r).toEqual({ kind: 'session-ended' });
  });

  it('partial failures are failed entries, never a missing key; W in neither warehouse list → its state failed, no code', async () => {
    const r = await loadMarkerInputs(recorder({ 'ListStorageLocations:archived': { kind: 'server-error' }, ListStorageLocations: locs([loc(L)]), ListWarehouses: whs([wh(W2)]) }).read, { kind: 'item', warehouseIds: [W, W2] });
    if (r.kind !== 'ok') throw new Error(r.kind);
    expect(r.inputs.get(W)).toEqual({ warehouseId: W, archivedAt: { kind: 'failed' }, live: { kind: 'loaded', value: [{ id: L, kind: 'bin' }] }, archived: { kind: 'failed' } });
    expect(r.inputs.get(W2)!.archivedAt).toEqual({ kind: 'failed' }); // in both lists (the stub answers both views alike)
    expect(r.warehouseCodes.has(W)).toBe(false);
  });

  it('the item scope: the warehouse state is loaded only when both lists loaded and W is in exactly one of them', async () => {
    const answers = { ListStorageLocations: locs([]), ListWarehouses: whs([wh(W)]), 'ListWarehouses:archived': whs([wh(W2, '2026-11-01T00:00:00Z', 'WH-2')]) };
    const r = await loadMarkerInputs(recorder(answers).read, { kind: 'item', warehouseIds: [W, W2] });
    if (r.kind !== 'ok') throw new Error(r.kind);
    expect(r.inputs.get(W)!.archivedAt).toEqual({ kind: 'loaded', value: null });
    expect(r.inputs.get(W2)!.archivedAt).toEqual({ kind: 'loaded', value: '2026-11-01T00:00:00Z' });
    expect(r.warehouseCodes.get(W2)).toBe('WH-2');
    const failed = await loadMarkerInputs(recorder({ ...answers, 'ListWarehouses:archived': { kind: 'unreachable' } }).read, { kind: 'item', warehouseIds: [W] });
    if (failed.kind !== 'ok') throw new Error(failed.kind);
    expect(failed.inputs.get(W)!.archivedAt).toEqual({ kind: 'failed' });
  });
});

describe('loadStockItem — the one-item view’s item read (pre-commit RED L-6)', () => {
  it('keeps the id, SKU, name and unit; an answer naming another item, or without a unit, is a server error', async () => {
    const { loadStockItem } = await import('@/lib/stock/load');
    const item = { id: I, sku: 'WID-001', name: 'Widget', unit_of_measure_code: 'EA', reorder_point: '0.000000' };
    expect(await loadStockItem(recorder({ GetItem: ok(item) }).read, I)).toEqual({ kind: 'ok', item: { id: I, sku: 'WID-001', name: 'Widget', unit_of_measure_code: 'EA' } });
    expect(await loadStockItem(recorder({ GetItem: ok({ ...item, id: W }) }).read, I)).toEqual({ kind: 'server-error' });
    expect(await loadStockItem(recorder({ GetItem: ok({ ...item, unit_of_measure_code: undefined }) }).read, I)).toEqual({ kind: 'server-error' });
    expect(await loadStockItem(recorder({ GetItem: { kind: 'not-found' } }).read, I)).toEqual({ kind: 'not-found' });
  });
});
