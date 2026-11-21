// @vitest-environment node
import { describe, expect, it } from 'vitest';
import { KIND_LABELS } from '@/copy/warehouses';
import { STOCK_LOCATION_MARKER } from '@/copy/stock';
import { gateCode, wireEnum } from '@/lib/bff/surface';
import { PICKABLE_KINDS, stockRowMarker, type Loaded, type LocationFact, type Marker, type MarkerInputs, type WarehouseMarkerInputs } from '@/lib/stock/marker';
import { missingMarkerCodes, stockRowsVisible } from '@/lib/stock/visibility';

// Stock ST-2 (stock-st2-plan.md §7.2; G-47's pin, rule (e)): the two helpers' truth tables, pure. Nothing is mocked or
// spied on: the gates are the contract's own (`viewerMay` over the generated surface), and the kind pin reads the served
// enum, so a new served kind turns this file red instead of becoming silently pickable. Role names appear in comments only.

// --- stockRowsVisible and missingMarkerCodes (V-1…V-7) ------------------------------------------------------------------

const V1 = ['stock_level.view', 'storage_location.view', 'warehouse.view']; // owner, admin, warehouse_operator, viewer
const V2 = ['stock_level.view', 'warehouse.view']; // buyer, purchasing_manager, sales_rep
const V3: string[] = []; // finance
const V4 = ['storage_location.view', 'warehouse.view']; // composed: no stock_level.view
const V5 = ['stock_level.view', 'storage_location.view']; // composed: no warehouse.view

describe('stockRowsVisible — rows only for a viewer who may read the stock and every marker read', () => {
  it('V-1…V-7', () => {
    expect(stockRowsVisible(V1)).toBe(true);
    expect(stockRowsVisible(V2)).toBe(false);
    expect(stockRowsVisible(V3)).toBe(false);
    expect(stockRowsVisible(V4)).toBe(false);
    expect(stockRowsVisible(V5)).toBe(false);
    expect(stockRowsVisible(new Set(V1))).toBe(true);
    expect(stockRowsVisible([...V1])).toBe(true);
    expect(stockRowsVisible([...V1, 'invoice.view', 'role.view'])).toBe(true);
  });

  it('contract pin: the four gates are the three codes (a gate change on the server is red here, not silent)', () => {
    expect(gateCode('ListStockLevels')).toBe('stock_level.view');
    expect(gateCode('ListStorageLocations')).toBe('storage_location.view');
    expect(gateCode('GetWarehouse')).toBe('warehouse.view');
    expect(gateCode('ListWarehouses')).toBe('warehouse.view');
  });

  it('missingMarkerCodes: the marker codes the viewer lacks, deduplicated, in order; empty when all are held', () => {
    expect(missingMarkerCodes(V2)).toEqual(['storage_location.view']);
    expect(missingMarkerCodes(V5)).toEqual(['warehouse.view']);
    expect(missingMarkerCodes(['stock_level.view'])).toEqual(['storage_location.view', 'warehouse.view']);
    expect(missingMarkerCodes(V1)).toEqual([]);
  });
});

// --- stockRowMarker (T-1…T-25) ------------------------------------------------------------------------------------------

const W = 'a0000000-0000-4000-8000-00000000000a';
const W2 = 'b0000000-0000-4000-8000-00000000000b';
const L = 'c0000000-0000-4000-8000-00000000000c';
const PLACE = { warehouse: { id: W }, storage_location: { id: L } };

const loaded = <T,>(value: T): Loaded<T> => ({ kind: 'loaded', value });
const FAILED = { kind: 'failed' } as const;
const at = (id: string, kind: string): LocationFact => ({ id, kind });
const OTHER = at('d0000000-0000-4000-8000-00000000000d', 'bin');
const entry = (over: Partial<WarehouseMarkerInputs> = {}): WarehouseMarkerInputs => ({ warehouseId: W, archivedAt: loaded(null), live: loaded([OTHER]), archived: loaded([]), ...over });
const inputs = (...entries: [string, WarehouseMarkerInputs][]): MarkerInputs => new Map(entries);
const one = (over: Partial<WarehouseMarkerInputs>) => stockRowMarker(PLACE, inputs([W, entry(over)]));
const mark = (flags: Partial<Marker> = {}): Marker => ({ quarantine: false, locationArchived: false, warehouseArchived: false, unknown: false, ...flags });
const ARCHIVED_AT = '2026-11-01T00:00:00Z';

/** The badge texts the view renders for a marker, in the pinned display order (location cell; warehouse cell). */
const badgeTexts = (m: Marker) => ({
  location: [...(m.quarantine ? [STOCK_LOCATION_MARKER.quarantine] : []), ...(m.locationArchived ? [STOCK_LOCATION_MARKER.archived] : []), ...(m.unknown ? [STOCK_LOCATION_MARKER.unknown] : [])],
  warehouse: m.warehouseArchived ? [STOCK_LOCATION_MARKER.archived] : [],
});

describe('stockRowMarker — fails closed: no badge only when all three facts loaded and one live pickable location matched', () => {
  it('T-1 no entry for W → unknown', () => expect(stockRowMarker(PLACE, inputs())).toEqual(mark({ unknown: true })));
  it('T-2 an entry keyed W made for another warehouse → unknown', () => expect(stockRowMarker(PLACE, inputs([W, entry({ warehouseId: W2, live: loaded([at(L, 'bin')]) })]))).toEqual(mark({ unknown: true })));
  it('T-3 L is only in another warehouse’s lists → unknown (never found in a merged list)', () =>
    expect(stockRowMarker(PLACE, inputs([W, entry()], [W2, { ...entry({ live: loaded([at(L, 'bin')]) }), warehouseId: W2 }]))).toEqual(mark({ unknown: true })));
  it('T-4 loaded; L live once, bin; W live → no badge (the only unmarked result)', () => expect(one({ live: loaded([at(L, 'bin'), OTHER]) })).toEqual(mark()));
  it.each(['shelf', 'zone', 'staging'])('T-5 L live, %s → no badge', (kind) => expect(one({ live: loaded([at(L, kind)]) })).toEqual(mark()));
  it('T-6 L live, quarantine → Quarantine', () => expect(one({ live: loaded([at(L, 'quarantine')]) })).toEqual(mark({ quarantine: true })));
  it('T-7 L archived, bin → Archived', () => expect(one({ archived: loaded([at(L, 'bin')]) })).toEqual(mark({ locationArchived: true })));
  it('T-8 L archived, quarantine → Quarantine · Archived', () => {
    const m = one({ archived: loaded([at(L, 'quarantine')]) });
    expect(m).toEqual(mark({ quarantine: true, locationArchived: true }));
    expect(badgeTexts(m).location).toEqual(['Quarantine', 'Archived']);
  });
  it('T-9 W archived; L live bin → warehouse Archived', () => expect(one({ archivedAt: loaded(ARCHIVED_AT), live: loaded([at(L, 'bin')]) })).toEqual(mark({ warehouseArchived: true })));
  it('T-10 W archived; L archived quarantine → Quarantine · Archived, and the warehouse Archived', () => {
    const m = one({ archivedAt: loaded(ARCHIVED_AT), archived: loaded([at(L, 'quarantine')]) });
    expect(m).toEqual(mark({ quarantine: true, locationArchived: true, warehouseArchived: true }));
    expect(badgeTexts(m)).toEqual({ location: ['Quarantine', 'Archived'], warehouse: ['Archived'] });
  });
  it('T-11 L in both lists, bin → unknown', () => expect(one({ live: loaded([at(L, 'bin')]), archived: loaded([at(L, 'bin')]) })).toEqual(mark({ unknown: true })));
  it('T-12 L in both lists, both quarantine → Quarantine and unknown', () => expect(one({ live: loaded([at(L, 'quarantine')]), archived: loaded([at(L, 'quarantine')]) })).toEqual(mark({ quarantine: true, unknown: true })));
  it('T-13 L in neither list (both loaded) → unknown', () => expect(one({})).toEqual(mark({ unknown: true })));
  it('T-14 live failed; L archived bin → Archived and unknown', () => expect(one({ live: FAILED, archived: loaded([at(L, 'bin')]) })).toEqual(mark({ locationArchived: true, unknown: true })));
  it('T-15 live failed; L not in archived → unknown', () => expect(one({ live: FAILED })).toEqual(mark({ unknown: true })));
  it('T-16 archived failed; L live bin → unknown (never no badge)', () => expect(one({ archived: FAILED, live: loaded([at(L, 'bin')]) })).toEqual(mark({ unknown: true })));
  it('T-17 archived failed; L live quarantine → Quarantine and unknown (the proven badge is kept)', () => {
    const m = one({ archived: FAILED, live: loaded([at(L, 'quarantine')]) });
    expect(m).toEqual(mark({ quarantine: true, unknown: true }));
    expect(badgeTexts(m).location).toEqual(['Quarantine', 'Status unknown']);
  });
  it('T-18 warehouse state failed; L live bin → unknown', () => expect(one({ archivedAt: FAILED, live: loaded([at(L, 'bin')]) })).toEqual(mark({ unknown: true })));
  it('T-19 warehouse state failed; L archived bin → Archived and unknown', () => expect(one({ archivedAt: FAILED, archived: loaded([at(L, 'bin')]) })).toEqual(mark({ locationArchived: true, unknown: true })));
  it('T-20 L live, kind dock (not served today) → unknown', () => expect(one({ live: loaded([at(L, 'dock')]) })).toEqual(mark({ unknown: true })));
  it('T-21 L live, kind Quarantine (wrong case) → unknown', () => expect(one({ live: loaded([at(L, 'Quarantine')]) })).toEqual(mark({ unknown: true })));
  it('T-22 L listed twice in live, bin → unknown', () => expect(one({ live: loaded([at(L, 'bin'), at(L, 'bin')]) })).toEqual(mark({ unknown: true })));
  it('T-23 all three reads failed → unknown', () => expect(one({ archivedAt: FAILED, live: FAILED, archived: FAILED })).toEqual(mark({ unknown: true })));
  it('T-24 W archived; archived list failed; L live bin → warehouse Archived and unknown', () =>
    expect(one({ archivedAt: loaded(ARCHIVED_AT), archived: FAILED, live: loaded([at(L, 'bin')]) })).toEqual(mark({ warehouseArchived: true, unknown: true })));
  it('T-25 L live twice, kinds bin and quarantine → unknown', () => expect(one({ live: loaded([at(L, 'bin'), at(L, 'quarantine')]) })).toEqual(mark({ unknown: true })));
});

describe('the kind pin and the badge word', () => {
  it("PICKABLE_KINDS plus quarantine is exactly the served enum, wireEnum('StorageLocation', 'kind'); quarantine is never pickable", () => {
    expect(new Set([...PICKABLE_KINDS, 'quarantine'])).toEqual(new Set(wireEnum('StorageLocation', 'kind')));
    expect(PICKABLE_KINDS).not.toContain('quarantine');
  });

  it('the Quarantine badge is the location kind’s label (UX rule 4: one spelling)', () => {
    expect(STOCK_LOCATION_MARKER.quarantine).toBe(KIND_LABELS.quarantine!.label);
  });
});
