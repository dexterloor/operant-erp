import { isValidElement, type ReactElement, type ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Stock ST-2 (stock-st2-plan.md §7.4; G-47's pin, rule (d)): the /stock server page, with the viewer's codes and the
// address as its only inputs. It mocks the viewer, next/navigation and the server transport; the two STOCK READS of
// `@/lib/stock/levels` (partially: `loadMarkerInputs` is the real one, so the sequence of reads below is the page's own); and
// the narrow loaders of `@/lib/stock/load`, recording every call. It mocks NEITHER gate helper, nor the contract reader,
// nor the generated surface, and spies on nothing: the gate under test is the real one (a mocked gate would pass case (i)
// by construction). That each read happens once per request is React's server behaviour (`cache`), not visible here
// (Vitest's `cache` is a pass-through), so the call lists below are for the page alone, or generateMetadata alone.

const W1 = 'a1000000-0000-4000-8000-000000000001';
const W2 = 'a2000000-0000-4000-8000-000000000002';
const LQ = 'b1000000-0000-4000-8000-000000000001'; // W1, quarantine, live
const LA = 'b2000000-0000-4000-8000-000000000002'; // W1, bin, archived
const LP = 'b3000000-0000-4000-8000-000000000003'; // W1, bin, live
const L2 = 'b4000000-0000-4000-8000-000000000004'; // W2 (archived warehouse), bin, live
const I = 'c1000000-0000-4000-8000-000000000001';

const v = vi.hoisted(() => ({
  codes: [] as string[],
  calls: [] as unknown[][],
  stock: 'ok' as string,
  stockRows: [] as unknown[],
  tenantHasRows: true,
  warehouse: {} as Record<string, string>, // GetWarehouse by id → kind (default ok)
  locations: {} as Record<string, string>, // `${id}:${archived}` → kind (default ok)
  warehouses: {} as Record<string, string>, // `${archived}` → kind (default ok)
  item: 'ok' as string,
}));

vi.mock('@/app/(app)/currentViewer', async (actual) => ({
  ...(await actual<typeof import('@/app/(app)/currentViewer')>()),
  currentViewer: async () => ({ session: { sid: 's' }, viewer: { id: 'viewer-id-from-the-server', permissionCodes: v.codes } }),
}));
vi.mock('next/navigation', () => ({
  redirect: (to: string) => {
    throw new Error(`redirect ${to}`);
  },
  notFound: () => {
    throw new Error('not found');
  },
  useRouter: () => ({ refresh: () => undefined }),
}));
vi.mock('@/lib/bff/server/reads', () => ({ serverRead: () => 'read' }));
vi.mock('@/lib/stock/levels', async (actual) => {
  const real = await actual<typeof import('@/lib/stock/levels')>();
  const answer = (args: unknown[]) => {
    v.calls.push(args);
    return v.stock === 'ok' ? { kind: 'ok', rows: v.stockRows, tenantHasRows: v.tenantHasRows } : { kind: v.stock };
  };
  return {
    ...real,
    loadStockForLocation: async (_read: unknown, w: string, l: string) => answer(['loadStockForLocation', w, l]),
    loadStockForItem: async (_read: unknown, i: string) => answer(['loadStockForItem', i]),
  };
});

const LOCATIONS: Record<string, { live: unknown[]; archived: unknown[] }> = {
  [W1]: { live: [{ id: LQ, code: 'Q-01', kind: 'quarantine' }, { id: LP, code: 'A-02', kind: 'bin' }], archived: [{ id: LA, code: 'A-01', kind: 'bin' }] },
  [W2]: { live: [{ id: L2, code: 'B-01', kind: 'bin' }], archived: [] },
};
const WAREHOUSES = { live: [{ id: W1, code: 'WH-1', name: 'Main', archived_at: null }], archived: [{ id: W2, code: 'WH-2', name: 'Old', archived_at: '2026-10-01T00:00:00Z' }] };
vi.mock('@/lib/stock/load', async (actual) => {
  const real = await actual<typeof import('@/lib/stock/load')>();
  return {
    ...real,
    loadMarkerLocations: async (_read: unknown, w: string, archived: boolean) => {
      v.calls.push(['loadMarkerLocations', w, archived]);
      const kind = v.locations[`${w}:${archived}`] ?? 'ok';
      return kind === 'ok' ? { kind, locations: LOCATIONS[w]?.[archived ? 'archived' : 'live'] ?? [] } : { kind };
    },
    loadMarkerWarehouse: async (_read: unknown, w: string) => {
      v.calls.push(['loadMarkerWarehouse', w]);
      const kind = v.warehouse[w] ?? 'ok';
      const found = [...WAREHOUSES.live, ...WAREHOUSES.archived].find((x) => x.id === w);
      return kind === 'ok' ? (found ? { kind, warehouse: found } : { kind: 'not-found' }) : { kind };
    },
    loadMarkerWarehouses: async (_read: unknown, archived: boolean) => {
      v.calls.push(['loadMarkerWarehouses', archived]);
      const kind = v.warehouses[`${archived}`] ?? 'ok';
      return kind === 'ok' ? { kind, warehouses: archived ? WAREHOUSES.archived : WAREHOUSES.live, tenantHasRows: true } : { kind };
    },
    loadStockItem: async (_read: unknown, i: string) => {
      v.calls.push(['loadStockItem', i]);
      return v.item === 'ok' ? { kind: 'ok', item: { id: I, sku: 'WID-001', name: 'Widget', unit_of_measure_code: 'EA' } } : { kind: v.item };
    },
  };
});

const { default: Page, generateMetadata, dynamic } = await import('@/app/(app)/stock/page');
const { stockRowsVisible } = await import('@/lib/stock/visibility');
const { StockLevelsView } = await import('@/app/(app)/stock/StockLevelsView');
const { StockLocationStep, StockWarehouseStep } = await import('@/app/(app)/stock/StockPicker');
const { LocationLinks } = await import('@/app/(app)/stock/LocationLinks');
const { StockDenied, StockFailed, StockUnknownItem, StockUnknownLocation, StockUnknownWarehouse, StockWithheld } = await import('@/app/(app)/stock/StockStates');
const { STOCK_LEVELS, STOCK_SCOPE } = await import('@/copy/stock');

const OPERATOR = ['stock_level.view', 'storage_location.view', 'warehouse.view', 'inventory_item.view']; // V-1 (+ the item read)
const BUYER = ['stock_level.view', 'warehouse.view']; // V-2
const NO_STOCK = ['storage_location.view', 'warehouse.view']; // V-4
const NO_WAREHOUSE = ['stock_level.view', 'storage_location.view']; // V-5

const row = (id: string, w: string, l: string, code: string) => ({ id, inventory_item: { id: I, sku: 'WID-001', name: 'Widget' }, storage_location: { id: l, code }, warehouse: { id: w, name: w === W1 ? 'Main' : 'Old' }, qty_on_hand: '1.000000', version: 1 });
const ROWS = [row('d1000000-0000-4000-8000-000000000001', W1, LQ, 'Q-01'), row('d2000000-0000-4000-8000-000000000002', W1, LA, 'A-01'), row('d3000000-0000-4000-8000-000000000003', W1, LP, 'A-02'), row('d4000000-0000-4000-8000-000000000004', W2, L2, 'B-01')];
const NONE = { quarantine: false, locationArchived: false, warehouseArchived: false, unknown: false };

beforeEach(() => {
  v.calls = [];
  v.stock = 'ok';
  v.stockRows = [];
  v.tenantHasRows = true;
  v.warehouse = {};
  v.locations = {};
  v.warehouses = {};
  v.item = 'ok';
});

type Q = Record<string, string | string[] | undefined>;
const page = async (codes: string[], query: Q = {}) => {
  v.codes = codes;
  const el = await Page({ searchParams: Promise.resolve(query) });
  expect(isValidElement(el)).toBe(true);
  return el as ReactElement<Record<string, unknown>>;
};
const title = async (codes: string[], query: Q = {}) => {
  v.codes = codes;
  return (await generateMetadata({ searchParams: Promise.resolve(query) })).title;
};
/** Whether any element of a type sits anywhere in the returned tree (its props' children, walked). */
const contains = (node: ReactNode, type: unknown): boolean => {
  if (Array.isArray(node)) return node.some((n) => contains(n, type));
  if (!isValidElement(node)) return false;
  if (node.type === type) return true;
  return contains((node.props as { children?: ReactNode }).children, type);
};
/** The first element of a type in the returned tree (its props' children, walked), or null. */
const findElement = (node: ReactNode, type: unknown): ReactElement | null => {
  if (Array.isArray(node)) {
    for (const n of node) {
      const hit = findElement(n, type);
      if (hit !== null) return hit;
    }
    return null;
  }
  if (!isValidElement(node)) return null;
  if (node.type === type) return node;
  return findElement((node.props as { children?: ReactNode }).children, type);
};
const called = (name: string) => v.calls.filter((c) => c[0] === name);
const LOCATION_Q = (l: string, w = W1) => ({ warehouse_id: w, storage_location_id: l });
const ADDRESSES: Q[] = [{}, { warehouse_id: W1 }, LOCATION_Q(LP), { inventory_item_id: I }, { warehouse_id: 'nope' }];

describe('/stock — the G-47 gate on the server page', () => {
  it('preconditions: the fixture codes mean what the cases say (the real gate, not a mock)', () => {
    expect(stockRowsVisible(OPERATOR)).toBe(true);
    expect(stockRowsVisible(BUYER)).toBe(false);
    expect(stockRowsVisible(NO_STOCK)).toBe(false);
    expect(dynamic).toBe('force-dynamic');
  });

  it('G-47 gate (i): without a marker code nothing is read, for every address; the withheld page names what is missing; the title reads nothing', async () => {
    v.stockRows = ROWS;
    for (const [codes, missing] of [
      [BUYER, ['storage_location.view']],
      [NO_WAREHOUSE, ['warehouse.view']],
    ] as const) {
      for (const query of ADDRESSES) {
        v.calls = [];
        const el = await page([...codes], query);
        expect(el.type, JSON.stringify(query)).toBe(StockWithheld);
        expect(el.props.missing).toEqual(missing);
        expect(contains(el, StockLevelsView)).toBe(false);
        expect(await title([...codes], query)).toBe(STOCK_LEVELS.heading);
        expect(v.calls, JSON.stringify(query)).toEqual([]);
      }
    }
    for (const query of ADDRESSES) {
      v.calls = [];
      expect((await page(NO_STOCK, query)).type).toBe(StockDenied);
      expect(await title(NO_STOCK, query)).toBe(STOCK_LEVELS.heading);
      expect(v.calls).toEqual([]);
    }
  });

  it('G-47 gate (ii): a failed marker read never shows a row unmarked', async () => {
    v.stockRows = ROWS;
    // 1. item scope, W1's archived location list fails: every W1 row is unknown; W2's keep their proven badge.
    v.locations = { [`${W1}:true`]: 'server-error' };
    let el = await page(OPERATOR, { inventory_item_id: I });
    expect(el.type).toBe(StockLevelsView);
    const rows = el.props.rows as { row: { warehouse: { id: string } }; marker: typeof NONE }[];
    expect(rows.filter((r) => r.row.warehouse.id === W1).every((r) => r.marker.unknown)).toBe(true);
    expect(rows.find((r) => r.row.warehouse.id === W2)!.marker).toEqual({ ...NONE, warehouseArchived: true });
    // 2. the archived warehouse list fails: every row is unknown.
    v.locations = {};
    v.warehouses = { true: 'unreachable' };
    el = await page(OPERATOR, { inventory_item_id: I });
    expect((el.props.rows as { marker: typeof NONE }[]).every((r) => r.marker.unknown)).toBe(true);
    // 3. one location, the archived list fails and L is live: the stock read IS made, and the scope marker is unknown.
    v.warehouses = {};
    v.locations = { [`${W1}:true`]: 'server-error' };
    v.calls = [];
    el = await page(OPERATOR, LOCATION_Q(LP));
    expect(el.type).toBe(StockLevelsView);
    expect(called('loadStockForLocation')).toEqual([['loadStockForLocation', W1, LP]]);
    expect(el.props.marker).toEqual({ ...NONE, unknown: true });
    // 4. one location, the live list fails and L is not archived: S-9a, and the stock read is never made.
    v.locations = { [`${W1}:false`]: 'server-error' };
    v.calls = [];
    el = await page(OPERATOR, LOCATION_Q(LP));
    expect(el.type).toBe(StockFailed);
    expect(el.props.body).toBe(STOCK_SCOPE.pickerLoadFailed.locations);
    expect(called('loadStockForLocation')).toEqual([]);
    // 5. GetWarehouse answers 500 → S-9a (warehouseLoadFailed); 404 → S-8; neither reads stock.
    v.locations = {};
    v.warehouse = { [W1]: 'server-error' };
    v.calls = [];
    el = await page(OPERATOR, LOCATION_Q(LP));
    expect([el.type, el.props.body]).toEqual([StockFailed, STOCK_LEVELS.warehouseLoadFailed]);
    v.warehouse = { [W1]: 'not-found' };
    expect((await page(OPERATOR, LOCATION_Q(LP))).type).toBe(StockUnknownWarehouse);
    expect(called('loadStockForLocation')).toEqual([]);
    // 6. a marker read answering 403 (codes removed mid-session): withheld, never rows; `missing` names the 403's gates.
    v.warehouse = {};
    for (const [setup, query, missing] of [
      [() => (v.locations = { [`${W2}:true`]: 'forbidden' }), { inventory_item_id: I }, ['storage_location.view']],
      [() => (v.warehouses = { false: 'forbidden' }), { inventory_item_id: I }, ['warehouse.view']],
      [() => (v.warehouse = { [W1]: 'forbidden' }), LOCATION_Q(LP), ['warehouse.view']],
    ] as const) {
      v.locations = {};
      v.warehouses = {};
      v.warehouse = {};
      setup();
      el = await page(OPERATOR, query);
      expect(el.type).toBe(StockWithheld);
      expect(el.props.missing).toEqual(missing);
      expect(contains(el, StockLevelsView)).toBe(false);
    }
  });

  it('G-47 gate (iii): everything loaded: each row carries the T-table’s marker; the page’s reads are exactly 2N + 3 plus the item', async () => {
    v.stockRows = ROWS;
    const el = await page(OPERATOR, { inventory_item_id: I });
    expect(el.type).toBe(StockLevelsView);
    expect(el.key).toBe(`item:${I}`);
    expect((el.props.rows as { row: { storage_location: { id: string } }; marker: typeof NONE; warehouseCode: string | null }[]).map((r) => [r.row.storage_location.id, r.marker, r.warehouseCode])).toEqual([
      [LQ, { ...NONE, quarantine: true }, 'WH-1'],
      [LA, { ...NONE, locationArchived: true }, 'WH-1'],
      [LP, NONE, 'WH-1'],
      [L2, { ...NONE, warehouseArchived: true }, 'WH-2'],
    ]);
    expect(el.props.item).toEqual({ sku: 'WID-001', name: 'Widget', unit: 'EA' });
    const reads = v.calls.map((c) => JSON.stringify(c)).sort();
    expect(reads).toEqual(
      [
        ['loadStockForItem', I],
        ['loadMarkerLocations', W1, false],
        ['loadMarkerLocations', W1, true],
        ['loadMarkerLocations', W2, false],
        ['loadMarkerLocations', W2, true],
        ['loadMarkerWarehouses', false],
        ['loadMarkerWarehouses', true],
        ['loadStockItem', I],
      ]
        .map((c) => JSON.stringify(c))
        .sort(),
    );
    // generateMetadata alone makes no read the page does not make.
    const pageReads = new Set(reads);
    v.calls = [];
    expect(await title(OPERATOR, { inventory_item_id: I })).toBe(STOCK_LEVELS.titleItem('WID-001'));
    expect(v.calls.map((c) => JSON.stringify(c)).filter((c) => !pageReads.has(c))).toEqual([]);
    // Without the item read's code: no item read, the interim heading (item null).
    v.calls = [];
    const noItem = await page(OPERATOR.filter((c) => c !== 'inventory_item.view'), { inventory_item_id: I });
    expect(noItem.props.item).toBeNull();
    expect(called('loadStockItem')).toEqual([]);
    // One location: three marker reads, then the stock read; the scope marker is passed; keyed by the scope.
    v.calls = [];
    v.stockRows = [ROWS[0]];
    const one = await page(OPERATOR, LOCATION_Q(LQ));
    expect(one.key).toBe(`location:${W1}:${LQ}`);
    expect(one.props.marker).toEqual({ ...NONE, quarantine: true });
    expect(one.props.location).toEqual({ id: LQ, code: 'Q-01' });
    expect(one.props.warehouse).toEqual({ id: W1, code: 'WH-1', name: 'Main' });
    expect(v.calls.map((c) => c[0]).sort()).toEqual(['loadMarkerLocations', 'loadMarkerLocations', 'loadMarkerWarehouse', 'loadStockForLocation']);
    expect(v.calls[v.calls.length - 1]).toEqual(['loadStockForLocation', W1, LQ]);
    // Commit RED M-3: an archived bin's own address shows its rows marked Archived, never "couldn't find that location".
    v.calls = [];
    v.stockRows = [row('d5000000-0000-4000-8000-000000000005', W1, LA, 'A-01')];
    const archivedBin = await page(OPERATOR, LOCATION_Q(LA));
    expect(archivedBin.type).not.toBe(StockUnknownLocation);
    expect(archivedBin.type).toBe(StockLevelsView);
    expect(called('loadStockForLocation')).toEqual([['loadStockForLocation', W1, LA]]);
    expect(archivedBin.props.marker).toEqual({ ...NONE, locationArchived: true });
    expect((archivedBin.props.marker as typeof NONE).unknown).toBe(false);
  });
});

describe('/stock — (iv) the rest of the check order, the item read and the picker', () => {
  it('S-14 before S-7 before S-11', async () => {
    const bad = { warehouse_id: W1, inventory_item_id: I };
    expect((await page(NO_STOCK, bad)).type).toBe(StockDenied);
    expect((await page(BUYER, bad)).type).toBe(StockWithheld);
    expect(v.calls).toEqual([]);
    const el = await page(OPERATOR, bad);
    expect(el.type).toBe(StockWarehouseStep);
    expect(el.props.badAddress).toBe(true);
    expect(called('loadStockForItem')).toEqual([]);
  });

  it('the item read: none when the page is empty and the workspace has no stock; read when it has (404 → S-10); none without its code', async () => {
    v.tenantHasRows = false;
    let el = await page(OPERATOR, { inventory_item_id: I });
    expect(called('loadStockItem')).toEqual([]);
    expect(el.props).toMatchObject({ item: null, tenantHasRows: false });
    v.tenantHasRows = true;
    v.calls = [];
    el = await page(OPERATOR, { inventory_item_id: I });
    expect(called('loadStockItem')).toEqual([['loadStockItem', I]]);
    v.item = 'not-found';
    expect((await page(OPERATOR, { inventory_item_id: I })).type).toBe(StockUnknownItem);
    // UX D-4 option (a): the item read answers 404 beside rows → the rows under the fallback, no item, no backToItem; the
    // title takes the rows' SKU.
    v.stockRows = ROWS;
    el = await page(OPERATOR, { inventory_item_id: I });
    expect(el.type).toBe(StockLevelsView);
    expect(el.props).toMatchObject({ item: null, canOpenItem: false });
    expect(await title(OPERATOR, { inventory_item_id: I })).toBe(STOCK_LEVELS.titleItem('WID-001'));
    // …and with no rows (the workspace has stock), still S-10.
    v.stockRows = [];
    expect((await page(OPERATOR, { inventory_item_id: I })).type).toBe(StockUnknownItem);
    // Copy doc Rev 1c: a 403 on the item read beside rows → the fallback, no backToItem; with no rows → noneForThisItem, never S-10.
    v.item = 'forbidden';
    v.stockRows = ROWS;
    el = await page(OPERATOR, { inventory_item_id: I });
    expect(el.type).toBe(StockLevelsView);
    expect(el.props).toMatchObject({ item: null, canOpenItem: false });
    v.stockRows = [];
    el = await page(OPERATOR, { inventory_item_id: I });
    expect(el.type).toBe(StockLevelsView);
    expect(el.props).toMatchObject({ item: null, rows: [], tenantHasRows: true });
    // With the item read answering, canOpenItem follows the item read's gate.
    v.item = 'ok';
    v.stockRows = ROWS;
    expect((await page(OPERATOR, { inventory_item_id: I })).props).toMatchObject({ canOpenItem: true });
    v.stockRows = ROWS;
    v.calls = [];
    el = await page(OPERATOR.filter((c) => c !== 'inventory_item.view'), { inventory_item_id: I });
    expect(called('loadStockItem')).toEqual([]);
    expect(el.props.item).toBeNull();
  });

  it('401 → the session-ended notice keeping the address; a 403 on the stock read → ST-4b; any other failure → S-12', async () => {
    v.stock = 'session-ended';
    await expect(page(OPERATOR, LOCATION_Q(LP))).rejects.toThrow(encodeURIComponent(`/stock?warehouse_id=${W1}&storage_location_id=${LP}`));
    v.stock = 'forbidden';
    expect((await page(OPERATOR, { inventory_item_id: I })).type).toBe(StockDenied);
    v.stock = 'server-error';
    const el = await page(OPERATOR, { inventory_item_id: I });
    expect([el.type, el.props.body]).toEqual([StockFailed, STOCK_LEVELS.loadFailed]);
  });

  it('generateMetadata titles by scope; S-9 when the location is in neither loaded list', async () => {
    v.stockRows = [ROWS[2]];
    expect(await title(OPERATOR)).toBe(STOCK_LEVELS.heading);
    expect(await title(OPERATOR, { warehouse_id: W1 })).toBe(STOCK_LEVELS.heading);
    expect(await title(OPERATOR, LOCATION_Q(LP))).toBe(STOCK_LEVELS.titleLocation('A-02', 'Main'));
    expect(await title(OPERATOR, { inventory_item_id: I })).toBe(STOCK_LEVELS.titleItem('WID-001'));
    // Commit RED L-2: when a marker read answers 403 the page is the withheld page, and its title names no SKU.
    v.warehouses = { false: 'forbidden' };
    expect((await page(OPERATOR, { inventory_item_id: I })).type).toBe(StockWithheld);
    expect(await title(OPERATOR, { inventory_item_id: I })).toBe(STOCK_LEVELS.heading);
    v.warehouses = {};
    const missing = await page(OPERATOR, LOCATION_Q(L2));
    expect(missing.type).toBe(StockUnknownLocation);
    expect(missing.props).toEqual({ warehouseId: W1, warehouse: 'Main' });
  });

  it('step 1 reads the live warehouses once; step 2 the warehouse and its live locations once each, never the archived view', async () => {
    let el = await page(OPERATOR);
    expect(el.type).toBe(StockWarehouseStep);
    expect(v.calls).toEqual([['loadMarkerWarehouses', false]]);
    v.calls = [];
    el = await page(OPERATOR, { warehouse_id: W1 });
    expect(el.type).toBe(StockLocationStep);
    expect(v.calls.map((c) => JSON.stringify(c)).sort()).toEqual([JSON.stringify(['loadMarkerLocations', W1, false]), JSON.stringify(['loadMarkerWarehouse', W1])].sort());
    // Revision 12 R12-3: StockLocationStep keys LocationLinks by the warehouse id, on the client element itself (W1 has live
    // locations, so the links render): one level down, the returned tree holds the LocationLinks element keyed W1.
    const stepTree = (el.type as (p: unknown) => ReactElement)(el.props);
    const links = findElement(stepTree, LocationLinks);
    expect(links, 'StockLocationStep renders no LocationLinks element').not.toBeNull();
    expect(links!.key).toBe(W1);
  });

  it('step 2: GetWarehouse 500 → warehouseLoadFailed; 404 → S-8; the location list failing → its sentence; a 403 → withheld', async () => {
    v.warehouse = { [W1]: 'server-error' };
    expect((await page(OPERATOR, { warehouse_id: W1 })).props.body).toBe(STOCK_LEVELS.warehouseLoadFailed);
    v.warehouse = { [W1]: 'not-found' };
    expect((await page(OPERATOR, { warehouse_id: W1 })).type).toBe(StockUnknownWarehouse);
    v.warehouse = {};
    v.locations = { [`${W1}:false`]: 'unreachable' };
    const el = await page(OPERATOR, { warehouse_id: W1 });
    expect([el.type, el.props.body, el.props.unreachable]).toEqual([StockFailed, STOCK_SCOPE.pickerLoadFailed.locations, true]);
    v.locations = { [`${W1}:false`]: 'forbidden' };
    expect((await page(OPERATOR, { warehouse_id: W1 })).props.missing).toEqual(['storage_location.view']);
    v.locations = {};
    v.warehouses = { false: 'forbidden' };
    expect((await page(OPERATOR)).props.missing).toEqual(['warehouse.view']);
  });
});
