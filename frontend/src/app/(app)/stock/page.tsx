import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { cache } from 'react';
import { STOCK_LEVELS, STOCK_SCOPE } from '@/copy/stock';
import { serverRead } from '@/lib/bff/server/reads';
import { gateCode, viewerMay } from '@/lib/bff/surface';
import { addressedStock, loadMarkerInputs, loadStockForItem, loadStockForLocation } from '@/lib/stock/levels';
import { loadMarkerLocations, loadMarkerWarehouse, loadMarkerWarehouses, loadStockItem } from '@/lib/stock/load';
import { stockRowMarker } from '@/lib/stock/marker';
import { missingMarkerCodes, stockRowsVisible } from '@/lib/stock/visibility';
import { currentViewer, sessionEndedPath } from '../currentViewer';
import { StockLevelsView } from './StockLevelsView';
import { StockLocationStep, StockWarehouseStep } from './StockPicker';
import { StockDenied, StockFailed, StockUnknownItem, StockUnknownLocation, StockUnknownWarehouse, StockWithheld } from './StockStates';

// `/stock`, Stock on hand (stock-st2-plan.md §2, §3; UX `stock-st2-ux-copy.md` §B-1, Part C; G-47 re-put, g47-ruling.md
// R-3). A SERVER page: it decides, in this order and before any read (UX §B-1, RED L-3),
//   1. the stock read's gate (`viewerMay('ListStockLevels', …)`) → else ST-4b, nothing read (S-14);
//   2. both marker codes (`stockRowsVisible`) → else the withheld page, nothing read (S-7);
//   3. the address names one place (`addressedStock`) → else step 1 with `badAddress` (S-11);
//   4. then the scope's reads: step 1 the live warehouses; step 2 the warehouse and its live locations; one location the
//      warehouse and both location lists, then, only when the location is found, its rows; one item its rows, then both
//      warehouse lists and each named warehouse's two location lists (2N + 3), then the item when the viewer may read it.
// Every row is marked here (`stockRowMarker`) before it reaches the client view, which is keyed by its scope (R12-3).
// 401 → the session-ended notice keeping the address (S-16); a 403 on a marker read → the withheld page naming the gates
// of the reads that answered 403 (S-15). `generateMetadata` applies steps 1–3 the same way and reads only through the
// same per-request `cache()` loaders (React's per-request cache, never a cross-viewer one; primitive arguments only).
// No cross-viewer cache on this page or anything it reaches (Revision 12 R12-6); network access is G-1's two homes.
export const dynamic = 'force-dynamic';

type Query = Record<string, string | string[] | undefined>;
type Props = { searchParams: Promise<Query> };

const sessionRead = async (ended: string) => {
  const { session } = await currentViewer(ended);
  return serverRead(session);
};
const gatesOf = (operations: readonly string[]) => [...new Set(operations.map(gateCode).filter((c): c is string => c !== null))];

/** Step 1: the live warehouses. */
const warehouseStep = cache(async (ended: string) => loadMarkerWarehouses(await sessionRead(ended), false));

/** Step 2: the warehouse and its live locations, together. */
const locationStep = cache(async (ended: string, w: string) => {
  const read = await sessionRead(ended);
  return Promise.all([loadMarkerWarehouse(read, w), loadMarkerLocations(read, w, false)]);
});

/** One location: the marker facts, then (only when the location is found) its rows. */
const locationScope = cache(async (ended: string, w: string, l: string) => {
  const read = await sessionRead(ended);
  const facts = await loadMarkerInputs(read, { kind: 'location', warehouseId: w });
  if (facts.kind !== 'ok') return { facts, location: null, stock: null } as const;
  const lists = facts.lists.get(w)!;
  const hits = [...(lists.live.kind === 'ok' ? lists.live.locations : []), ...(lists.archived.kind === 'ok' ? lists.archived.locations : [])].filter((x) => x.id === l);
  if (facts.warehouse === null || facts.warehouse.kind !== 'ok' || hits.length === 0) return { facts, location: null, stock: null } as const;
  return { facts, location: hits[0]!, stock: await loadStockForLocation(read, w, l) } as const;
});

/** One item: its rows, then the marker facts for every warehouse they name, then the item (when allowed and needed). */
const itemScope = cache(async (ended: string, i: string, mayReadItem: boolean) => {
  const read = await sessionRead(ended);
  const stock = await loadStockForItem(read, i);
  if (stock.kind !== 'ok') return { stock, facts: null, item: null } as const;
  const facts = await loadMarkerInputs(read, { kind: 'item', warehouseIds: stock.rows.map((r) => r.warehouse.id) });
  if (facts.kind !== 'ok') return { stock, facts, item: null } as const;
  const item = mayReadItem && (stock.rows.length > 0 || stock.tenantHasRows) ? await loadStockItem(read, i) : null;
  return { stock, facts, item } as const;
});

export async function generateMetadata({ searchParams }: Props): Promise<Metadata> {
  const query = await searchParams;
  const { viewer } = await currentViewer(sessionEndedPath('/stock', query));
  const ended = sessionEndedPath('/stock', query);
  const codes = viewer.permissionCodes;
  const heading = { title: STOCK_LEVELS.heading };
  if (!viewerMay('ListStockLevels', codes) || !stockRowsVisible(codes)) return heading;
  const addressed = addressedStock(query);
  if (addressed.kind === 'location') {
    const s = await locationScope(ended, addressed.warehouseId, addressed.locationId);
    if (s.facts.kind === 'ok' && s.facts.warehouse !== null && s.facts.warehouse.kind === 'ok' && s.location !== null) {
      return { title: STOCK_LEVELS.titleLocation(s.location.code, s.facts.warehouse.warehouse.name) };
    }
  }
  if (addressed.kind === 'item') {
    const s = await itemScope(ended, addressed.itemId, viewerMay('GetItem', codes));
    // The title names the item only when the page will show its rows: never on the withheld page (commit RED L-2).
    if (s.facts === null || s.facts.kind !== 'ok') return heading;
    const sku = s.item !== null && s.item.kind === 'ok' ? s.item.item.sku : s.stock.kind === 'ok' ? s.stock.rows[0]?.inventory_item.sku : undefined;
    if (sku !== undefined) return { title: STOCK_LEVELS.titleItem(sku) };
  }
  return heading;
}

export default async function Page({ searchParams }: Props) {
  const query = await searchParams;
  const { viewer } = await currentViewer(sessionEndedPath('/stock', query));
  const ended = sessionEndedPath('/stock', query);
  const codes = viewer.permissionCodes;
  if (!viewerMay('ListStockLevels', codes)) return <StockDenied />;
  if (!stockRowsVisible(codes)) return <StockWithheld missing={missingMarkerCodes(codes)} />;
  const addressed = addressedStock(query);
  const unreachable = (kind: string) => kind === 'unreachable';

  if (addressed.kind === 'warehouses' || addressed.kind === 'bad') {
    const w = await warehouseStep(ended);
    if (w.kind === 'session-ended') redirect(ended);
    if (w.kind === 'forbidden') return <StockWithheld missing={gatesOf(['ListWarehouses'])} />;
    if (w.kind !== 'ok') return <StockFailed heading={STOCK_LEVELS.heading} body={STOCK_SCOPE.pickerLoadFailed.warehouses} unreachable={unreachable(w.kind)} />;
    return <StockWarehouseStep warehouses={w.warehouses} tenantHasRows={w.tenantHasRows} badAddress={addressed.kind === 'bad'} />;
  }

  if (addressed.kind === 'locations') {
    const [warehouse, locations] = await locationStep(ended, addressed.warehouseId);
    if (warehouse.kind === 'session-ended' || locations.kind === 'session-ended') redirect(ended);
    const forbidden = [...(warehouse.kind === 'forbidden' ? ['GetWarehouse'] : []), ...(locations.kind === 'forbidden' ? ['ListStorageLocations'] : [])];
    if (forbidden.length > 0) return <StockWithheld missing={gatesOf(forbidden)} />;
    if (warehouse.kind === 'not-found') return <StockUnknownWarehouse />;
    if (warehouse.kind !== 'ok') return <StockFailed heading={STOCK_LEVELS.heading} body={STOCK_LEVELS.warehouseLoadFailed} unreachable={unreachable(warehouse.kind)} />;
    if (locations.kind !== 'ok') return <StockFailed heading={STOCK_LEVELS.heading} body={STOCK_SCOPE.pickerLoadFailed.locations} unreachable={unreachable(locations.kind)} />;
    return <StockLocationStep warehouse={warehouse.warehouse} locations={locations.locations} />;
  }

  if (addressed.kind === 'location') {
    const { warehouseId: w, locationId: l } = addressed;
    const s = await locationScope(ended, w, l);
    if (s.facts.kind === 'session-ended') redirect(ended);
    if (s.facts.kind === 'forbidden') return <StockWithheld missing={gatesOf(s.facts.operations)} />;
    const { warehouse, lists } = { warehouse: s.facts.warehouse!, lists: s.facts.lists.get(w)! };
    if (warehouse.kind === 'not-found') return <StockUnknownWarehouse />;
    if (warehouse.kind !== 'ok') return <StockFailed heading={STOCK_LEVELS.heading} body={STOCK_LEVELS.warehouseLoadFailed} unreachable={unreachable(warehouse.kind)} />;
    if (s.location === null) {
      if (lists.live.kind === 'ok' && lists.archived.kind === 'ok') return <StockUnknownLocation warehouseId={w} warehouse={warehouse.warehouse.name} />;
      const failed = lists.live.kind !== 'ok' ? lists.live.kind : lists.archived.kind;
      return <StockFailed heading={STOCK_LEVELS.heading} body={STOCK_SCOPE.pickerLoadFailed.locations} unreachable={unreachable(failed)} />;
    }
    const stock = s.stock!;
    if (stock.kind === 'session-ended') redirect(ended);
    if (stock.kind === 'forbidden') return <StockDenied />;
    if (stock.kind !== 'ok') return <StockFailed heading={STOCK_LEVELS.titleLocation(s.location.code, warehouse.warehouse.name)} body={STOCK_LEVELS.loadFailed} unreachable={unreachable(stock.kind)} />;
    const marker = stockRowMarker({ warehouse: { id: w }, storage_location: { id: l } }, s.facts.inputs);
    return (
      <StockLevelsView
        key={`location:${w}:${l}`}
        scope="location"
        warehouse={{ id: w, code: warehouse.warehouse.code, name: warehouse.warehouse.name }}
        location={{ id: l, code: s.location.code }}
        marker={marker}
        rows={stock.rows}
        tenantHasRows={stock.tenantHasRows}
        canOpenItem={viewerMay('GetItem', codes)}
        canListOrders={viewerMay('ListPurchaseOrders', codes)}
      />
    );
  }

  const i = addressed.itemId;
  const s = await itemScope(ended, i, viewerMay('GetItem', codes));
  if (s.stock.kind === 'session-ended') redirect(ended);
  if (s.stock.kind === 'forbidden') return <StockDenied />;
  if (s.stock.kind !== 'ok') return <StockFailed heading={STOCK_LEVELS.heading} body={STOCK_LEVELS.loadFailed} unreachable={unreachable(s.stock.kind)} />;
  if (s.facts === null || s.facts.kind === 'session-ended') redirect(ended);
  if (s.facts.kind === 'forbidden') return <StockWithheld missing={gatesOf(s.facts.operations)} />;
  if (s.item !== null && s.item.kind === 'session-ended') redirect(ended);
  if (s.item !== null && s.item.kind === 'not-found' && s.stock.rows.length === 0) return <StockUnknownItem />;
  const facts = s.facts;
  const rows = s.stock.rows.map((row) => ({ row, marker: stockRowMarker(row, facts.inputs), warehouseCode: facts.warehouseCodes.get(row.warehouse.id) ?? null }));
  const item = s.item !== null && s.item.kind === 'ok' ? { sku: s.item.item.sku, name: s.item.item.name, unit: s.item.item.unit_of_measure_code } : null;
  return (
    <StockLevelsView
      key={`item:${i}`}
      scope="item"
      itemId={i}
      item={item}
      rows={rows}
      tenantHasRows={s.stock.tenantHasRows}
      // UX D-4 option (a) (stock-st2-ux-d4.md, as corrected by stock-st2-ux-d4-red.md; copy doc Rev 1c): rows beside an
      // item read that answered 404 or 403 show under the fallback, without `backToItem` (no link known to fail).
      canOpenItem={viewerMay('GetItem', codes) && !(s.item !== null && (s.item.kind === 'not-found' || s.item.kind === 'forbidden'))}
      canListMovements={viewerMay('ListStockMovements', codes)}
      canListOrders={viewerMay('ListPurchaseOrders', codes)}
    />
  );
}
