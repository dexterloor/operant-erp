import type { ReactElement } from 'react';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ replace: vi.fn(), push: vi.fn(), refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => nav, usePathname: () => '/stock' }));
import { BffProvider } from '@/app/(app)/BffProvider';
import { LocationLinks } from '@/app/(app)/stock/LocationLinks';
import { StockLevelsView, StockLoadFailed, type ItemRow, type ItemViewProps, type LocationViewProps } from '@/app/(app)/stock/StockLevelsView';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { PAGE_STATES } from '@/copy/shell';
import { STOCK_LEVELS } from '@/copy/stock';
import { TooltipProvider } from '@/components/ui/Tooltip';
import type { StockLevel } from '@/lib/api/stock';
import type { Marker } from '@/lib/stock/marker';
import { json, problem, renderWired, SID, TZ } from './harness';

// Stock ST-2 (stock-st2-plan.md §7.5; UX `stock-st2-ux-copy.md` §B-3…§B-10): the real client view over the REAL marker
// Retry (`loadMarkerInputs` and the narrow loaders) and the browser transport, every request read off the wire. The view
// renders rows that already carry their marker; its one read is the marker Retry, never the rows.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.replace.mockReset();
  nav.push.mockReset();
  nav.refresh.mockReset();
});

const W1 = 'a1000000-0000-4000-8000-000000000001';
const W2 = 'a2000000-0000-4000-8000-000000000002';
const LQ = 'b1000000-0000-4000-8000-000000000001';
const LA = 'b2000000-0000-4000-8000-000000000002';
const LP = 'b3000000-0000-4000-8000-000000000003';
const L2 = 'b4000000-0000-4000-8000-000000000004';
const I = 'c1000000-0000-4000-8000-000000000001';
const NONE: Marker = { quarantine: false, locationArchived: false, warehouseArchived: false, unknown: false };
const lv = (id: string, w: string, l: string, code: string, sku = 'WID-001', name = 'Widget', qty = '1.500000'): StockLevel => ({
  id,
  inventory_item: { id: I, sku, name },
  storage_location: { id: l, code },
  warehouse: { id: w, name: w === W1 ? 'Main' : 'Old' },
  qty_on_hand: qty,
  version: 1,
});
const R_Q = lv('d1000000-0000-4000-8000-000000000001', W1, LQ, 'Q-01');
const R_A = lv('d2000000-0000-4000-8000-000000000002', W1, LA, 'A-01');
const R_P = lv('d3000000-0000-4000-8000-000000000003', W1, LP, 'A-02');
const R_2 = lv('d4000000-0000-4000-8000-000000000004', W2, L2, 'A-01');
const ITEM_ROWS: ItemRow[] = [
  { row: R_Q, marker: { ...NONE, quarantine: true }, warehouseCode: 'WH-1' },
  { row: R_A, marker: { ...NONE, locationArchived: true }, warehouseCode: 'WH-1' },
  { row: R_P, marker: NONE, warehouseCode: 'WH-1' },
  { row: R_2, marker: { ...NONE, warehouseArchived: true }, warehouseCode: 'WH-2' },
];
const itemProps = (over: Partial<ItemViewProps> = {}): ItemViewProps => ({ scope: 'item', itemId: I, item: { sku: 'WID-001', name: 'Widget', unit: 'EA' }, rows: ITEM_ROWS, tenantHasRows: true, canOpenItem: true, canListMovements: true, canListOrders: true, ...over });
const locationProps = (over: Partial<LocationViewProps> = {}): LocationViewProps => ({
  scope: 'location',
  warehouse: { id: W1, code: 'WH-1', name: 'Main' },
  location: { id: LQ, code: 'Q-01' },
  marker: NONE,
  rows: [lv('e1000000-0000-4000-8000-000000000001', W1, LQ, 'Q-01', 'ZED-9', 'Zed'), lv('e2000000-0000-4000-8000-000000000002', W1, LQ, 'Q-01', 'ABC-1', 'Abc')],
  tenantHasRows: true,
  canOpenItem: true,
  canListOrders: true,
  ...over,
});

/** A fetch stub routed by URL (the marker reads run concurrently, so order is not the contract). */
function routedFetch(route: (url: string) => Response | Promise<Response>) {
  const calls: string[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      calls.push(String(input));
      return route(String(input));
    }),
  );
  return calls;
}
const list = (items: unknown[]) => json(200, { items, next_cursor: null, previous_cursor: null, tenant_has_rows: true });
const loc = (id: string, kind: string, code: string) => ({ id, code, kind, archived_at: null });
const W1_LIVE = [loc(LQ, 'quarantine', 'Q-01'), loc(LP, 'bin', 'A-02')];
const W1_ARCHIVED = [loc(LA, 'bin', 'A-01')];

const badgeTexts = (cell: HTMLElement) => [...cell.querySelectorAll('span')].map((s) => s.textContent);
const cellsOf = (row: HTMLElement) => within(row).getAllByRole('cell');

describe('/stock — the one-item view', () => {
  it('7.5-1: cells, the badges as text after the code, the warehouse’s Archived on the warehouse, {code} — {name}; the name alone when its state failed', () => {
    renderWired(<StockLevelsView {...itemProps({ rows: [...ITEM_ROWS.slice(0, 3), { ...ITEM_ROWS[3]!, warehouseCode: null, marker: { ...NONE, unknown: true } }] })} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Stock on hand — WID-001' })).toBeInTheDocument();
    const rows = within(screen.getByRole('table')).getAllByRole('row').slice(1);
    // Order: by location code, then warehouse code, then row id (A-01 of WH-1 before A-01 of the unknown-code warehouse).
    expect(rows.map((r) => cellsOf(r).map((c) => c.textContent?.replace(/\s+/g, ' ').trim()))).toEqual([
      ['Old', 'A-01 Status unknown', '1.5', 'EA'],
      ['WH-1 — Main', 'A-01 Archived', '1.5', 'EA'],
      ['WH-1 — Main', 'A-02', '1.5', 'EA'],
      ['WH-1 — Main', 'Q-01 Quarantine', '1.5', 'EA'],
    ]);
    expect(badgeTexts(cellsOf(rows[3]!)[1]!)).toEqual(['Quarantine']);
    expect(within(cellsOf(rows[2]!)[1]!).getByRole('link', { name: 'A-02' })).toHaveAttribute('href', `/stock?warehouse_id=${W1}&storage_location_id=${LP}`);
  });

  it('7.5-1: the archived warehouse’s badge is on the warehouse cell, never on the location', () => {
    renderWired(<StockLevelsView {...itemProps({ rows: [ITEM_ROWS[3]!] })} />);
    const cells = cellsOf(within(screen.getByRole('table')).getAllByRole('row')[1]!);
    expect(badgeTexts(cells[0]!)).toEqual(['Archived']);
    expect(badgeTexts(cells[1]!)).toEqual([]);
  });

  it('7.5-4: with the item → On hand + a Unit column (EA); without it → the interim heading and no Unit column', () => {
    const { unmount } = renderWired(<StockLevelsView {...itemProps()} />);
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Warehouse', 'Location', 'On hand', 'Unit']);
    unmount();
    renderWired(<StockLevelsView {...itemProps({ item: null })} />);
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Warehouse', 'Location', "On hand (in the item's unit)"]);
  });

  it('UX D-4 (a): the item read answered 404 beside rows → the rows’ SKU and name, the interim heading, no Unit, no backToItem', () => {
    renderWired(<StockLevelsView {...itemProps({ item: null, canOpenItem: false })} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Stock on hand — WID-001' })).toBeInTheDocument();
    expect(screen.getByText('Showing WID-001 — Widget in every location it has been stored in.')).toBeInTheDocument();
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Warehouse', 'Location', "On hand (in the item's unit)"]);
    expect(screen.queryByRole('link', { name: 'Back to the item' })).toBeNull();
    expect(screen.getByRole('link', { name: 'Stock movements of this item' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Choose a warehouse and location instead' })).toBeInTheDocument();
  });

  it('the links: movements (stock_movement.view), back to the item (inventory_item.view), choose instead (always)', () => {
    const { unmount } = renderWired(<StockLevelsView {...itemProps()} />);
    expect(screen.getByRole('link', { name: 'Stock movements of this item' })).toHaveAttribute('href', `/stock-movements?inventory_item_id=${I}`);
    expect(screen.getByRole('link', { name: 'Back to the item' })).toHaveAttribute('href', `/items/${I}`);
    unmount();
    renderWired(<StockLevelsView {...itemProps({ canListMovements: false, canOpenItem: false })} />);
    expect(screen.queryByRole('link', { name: 'Stock movements of this item' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Back to the item' })).toBeNull();
    expect(screen.getByRole('link', { name: 'Choose a warehouse and location instead' })).toHaveAttribute('href', '/stock');
  });

  it('the empty states: noneYet (+ its link only for a purchase-order viewer), noneForItem, noneForThisItem', () => {
    const { unmount } = renderWired(<StockLevelsView {...itemProps({ rows: [], tenantHasRows: false, canListOrders: false })} />);
    expect(screen.getByText(/^No stock has been recorded yet\./)).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Go to purchase orders' })).toBeNull();
    unmount();
    const b = renderWired(<StockLevelsView {...itemProps({ rows: [] })} />);
    expect(screen.getByText("WID-001 hasn't been stored in any location yet.")).toBeInTheDocument();
    expect(screen.queryByText(/isn't picked for a shipment/)).toBeNull(); // markerNote only with rows
    b.unmount();
    renderWired(<StockLevelsView {...itemProps({ rows: [], item: null })} />);
    expect(screen.getByText("This item hasn't been stored in any location yet.")).toBeInTheDocument();
  });
});

describe('/stock — the one-location view', () => {
  it('7.5-1b: the scope line and the caption carry the location’s badges after its code, and the warehouse’s after it', () => {
    for (const [marker, location, warehouse] of [
      [NONE, [], []],
      [{ ...NONE, quarantine: true, locationArchived: true }, ['Quarantine', 'Archived'], []],
      [{ ...NONE, warehouseArchived: true }, [], ['Archived']],
      [{ ...NONE, unknown: true }, ['Status unknown'], []],
    ] as const) {
      const { unmount } = renderWired(<StockLevelsView {...locationProps({ marker })} />);
      const scope = screen.getByText(/^Showing what's stored in/);
      expect(scope.textContent?.replace(/\s+/g, ' ')).toBe(`Showing what's stored in Q-01${location.map((b) => ` ${b}`).join('')} in WH-1 — Main${warehouse.map((b) => ` ${b}`).join('')}.`);
      expect(badgeTexts(scope)).toEqual([...location, ...warehouse]);
      const caption = screen.getByRole('table').querySelector('caption')!;
      expect(caption.textContent?.replace(/\s+/g, ' ')).toBe(`Items in Q-01${location.map((b) => ` ${b}`).join('')}, by SKU`);
      // Copy doc Rev 1c: the location scope's banner is its own sentence (the rows carry no badge here).
      expect(screen.queryByText("We couldn't finish checking Q-01, so it's marked Status unknown.", { selector: 'h2, h3, p, strong, span' }) !== null).toBe(marker.unknown);
      expect(screen.queryByText(/^We couldn't check whether some locations/)).toBeNull();
      unmount();
    }
  });

  it('7.5-4 and 7.5-5: the interim heading always, no Unit column; rows by SKU in code-unit order', () => {
    renderWired(<StockLevelsView {...locationProps()} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Stock on hand — Q-01, Main' })).toBeInTheDocument();
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Item', "On hand (in the item's unit)"]);
    expect(within(screen.getByRole('table')).getAllByRole('row').slice(1).map((r) => cellsOf(r)[0]!.textContent)).toEqual(['ABC-1 — Abc', 'ZED-9 — Zed']);
    expect(screen.getByRole('link', { name: 'Choose another location' })).toHaveAttribute('href', `/stock?warehouse_id=${W1}`);
  });

  it('7.5-5: mixed-case codes sort in code-unit order (upper case first), not by locale', () => {
    renderWired(<StockLevelsView {...locationProps({ rows: [lv('e3000000-0000-4000-8000-000000000003', W1, LQ, 'Q-01', 'abc', 'a'), lv('e4000000-0000-4000-8000-000000000004', W1, LQ, 'Q-01', 'ABD', 'b')] })} />);
    expect(within(screen.getByRole('table')).getAllByRole('row').slice(1).map((r) => cellsOf(r)[0]!.textContent)).toEqual(['ABD — b', 'abc — a']);
  });

  it('the empty states: noneInLocation(code) when the workspace has stock', () => {
    renderWired(<StockLevelsView {...locationProps({ rows: [] })} />);
    expect(screen.getByText('Nothing has been stored in Q-01 yet.')).toBeInTheDocument();
  });
});

describe('/stock — the hint, the note and the banner', () => {
  it('7.5-2: the hint precedes the table and the On hand header points to it; the note shows with rows', () => {
    renderWired(<StockLevelsView {...itemProps()} />);
    const hint = screen.getByText(/^On hand is everything physically in the location/);
    const table = screen.getByRole('table');
    expect(hint.compareDocumentPosition(table) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.getByRole('columnheader', { name: 'On hand' })).toHaveAttribute('aria-describedby', hint.id);
    expect(screen.getByText(/isn't picked for a shipment while it's marked\.$/)).toBeInTheDocument();
  });

  it('7.5-3: no banner while every marker is proven', () => {
    renderWired(<StockLevelsView {...itemProps()} />);
    expect(screen.queryByText(/^We couldn't check whether some locations/)).toBeNull();
    expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull();
  });

  it('7.5-3: Retry re-reads only the marker facts (never the rows); success re-marks the rows and says "Locations checked."', async () => {
    const calls = routedFetch((url) => {
      if (url.includes('/warehouses/') && url.includes('/locations')) return url.includes('archived=true') ? list(W1_ARCHIVED) : list(W1_LIVE);
      if (url.includes(`/warehouses/${W1}`)) return json(200, { id: W1, code: 'WH-1', name: 'Main', country_code: 'GB', archived_at: null });
      return json(500, { unexpected: url });
    });
    renderWired(<StockLevelsView {...locationProps({ marker: { ...NONE, unknown: true } })} />);
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull());
    expect(calls.filter((u) => /\/bff\/api\/stock(?![-\w])/.test(u))).toEqual([]);
    expect(calls.length).toBe(3);
    expect(badgeTexts(screen.getByText(/^Showing what's stored in/))).toEqual(['Quarantine']);
    expect(screen.getByText('Locations checked.')).toBeInTheDocument();
  });

  it('7.5-3: a second failure keeps Status unknown and says the banner again', async () => {
    // The archived list fails again; the live list and both warehouse lists load, so Quarantine is still proven (T-17).
    routedFetch((url) => (url.includes('/locations') ? (url.includes('archived=true') ? json(500, {}) : list(W1_LIVE)) : list([{ id: W1, code: 'WH-1', name: 'Main', country_code: 'GB', archived_at: null }])));
    renderWired(<StockLevelsView {...itemProps({ rows: [{ ...ITEM_ROWS[0]!, marker: { ...NONE, quarantine: true, unknown: true } }] })} />);
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getAllByText(/^We couldn't check whether some locations/).length).toBe(2)); // banner + region
    expect(badgeTexts(cellsOf(within(screen.getByRole('table')).getAllByRole('row')[1]!)[1]!)).toEqual(['Quarantine', 'Status unknown']);
  });

  it('7.5-3: a 403 on Retry asks the server again and shows no row until it answers; a refresh answered with rows shows them', async () => {
    routedFetch((url) => (url.includes('archived=true') ? problem(403, 'permission-denied') : list([])));
    const rows = [{ ...ITEM_ROWS[0]!, marker: { ...NONE, unknown: true } }];
    const view = (r: ItemRow[]) => (
      <TooltipProvider>
        <BffProvider session={{ sid: SID, tz: TZ }} reload={() => {}}>
          <StockLevelsView {...itemProps({ rows: r })} />
        </BffProvider>
      </TooltipProvider>
    );
    const { rerender } = render(view(rows));
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(nav.refresh).toHaveBeenCalledTimes(1));
    expect(screen.queryByRole('table')).toBeNull();
    expect(screen.getByRole('status', { name: 'Loading…' })).toBeInTheDocument();
    act(() => rerender(view([{ ...ITEM_ROWS[0]! }])));
    expect(screen.getByRole('table')).toBeInTheDocument();
  });
});

// Rule R-F (stock-st2-ux-d4.md, as corrected by stock-st2-ux-d4-red.md M-1, M-2, L-1, L-2): focus and the one
// announcement after a marker Retry. Both scopes, each with rows (no banner shows without rows).
const W1_ROW = { id: W1, code: 'WH-1', name: 'Main', country_code: 'GB', archived_at: null };
/** A fetch stub whose answers wait until `release` is called (so focus can move while the read is pending). */
function heldMarkerFetch(resolve: boolean) {
  let release!: () => void;
  const gate = new Promise<void>((r) => (release = r));
  routedFetch(async (url) => {
    await gate;
    if (url.includes('/locations')) return url.includes('archived=true') ? (resolve ? list(W1_ARCHIVED) : json(500, {})) : list(W1_LIVE);
    if (url.includes(`/warehouses/${W1}`)) return json(200, W1_ROW);
    return url.includes('archived=true') ? list([]) : list([W1_ROW]);
  });
  return () => act(() => release());
}
const statusRegion = () => screen.getByRole('heading', { level: 1 }).closest('section')!.querySelector<HTMLElement>(':scope > [role="status"]')!;
const SCOPES: [string, () => ReactElement, string][] = [
  ['location', () => <StockLevelsView {...locationProps({ marker: { ...NONE, unknown: true } })} />, "We couldn't finish checking Q-01, so it's marked Status unknown."],
  ['item', () => <StockLevelsView {...itemProps({ rows: [{ ...ITEM_ROWS[0]!, marker: { ...NONE, quarantine: true, unknown: true } }] })} />, "We couldn't check whether some locations are in quarantine or archived. Their rows say Status unknown."],
];

describe('/stock — rule R-F: focus and the announcement after a marker Retry', () => {
  it.each(SCOPES)('(i) %s scope: a resolving Retry commits, focuses the h1 (focus was lost with the banner), then says markerChecked', async (_scope, ui) => {
    const release = heldMarkerFetch(true);
    renderWired(ui());
    const h1 = screen.getByRole('heading', { level: 1 });
    const atFocus: string[] = [];
    h1.addEventListener('focus', () => atFocus.push(statusRegion().textContent ?? ''));
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await release();
    await waitFor(() => expect(statusRegion().textContent).toBe('Locations checked.'));
    expect(document.activeElement).toBe(h1);
    expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull();
    // The announcement is written after the focus move.
    // Commit RED L-3, recorded (no call-level test added): moving the `announce` statement above the `focus()` call is
    // equivalent at the DOM (mutant RF-announce-reordered, GREEN): `announce` is a state update after an await and outside
    // flushSync, so React commits it after the synchronous focus in either order. RF-announce-in-commit is the counted plant.
    expect(atFocus).toEqual(['']);
  });

  it('(ii) a resolving Retry while the user moved focus elsewhere: focus stays where the user put it', async () => {
    const release = heldMarkerFetch(true);
    renderWired(SCOPES[1]![1]());
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }));
    const elsewhere = screen.getByRole('link', { name: 'Choose a warehouse and location instead' });
    act(() => elsewhere.focus());
    await release();
    await waitFor(() => expect(statusRegion().textContent).toBe('Locations checked.'));
    expect(document.activeElement).toBe(elsewhere);
  });

  it.each(SCOPES)('(iii) %s scope: a Retry leaving Status unknown keeps the banner and focus on Retry; two in a row are each spoken', async (_scope, ui, sentence) => {
    heldMarkerFetch(false)();
    renderWired(ui());
    const retry = screen.getByRole('button', { name: 'Retry' });
    await userEvent.click(retry);
    await waitFor(() => expect(statusRegion().textContent).toBe(sentence));
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Retry' }));
    // The banner is still shown (its sentence, beside the region's copy): one sentence, by scope (R2-M1).
    expect(screen.getAllByText(sentence)).toHaveLength(2);
    // The second Retry: the region is blanked while the read is pending, then holds the banner sentence again.
    const release = heldMarkerFetch(false);
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }));
    expect(statusRegion().textContent).toBe('');
    await release();
    await waitFor(() => expect(statusRegion().textContent).toBe(sentence));
  });

  // Copy doc Rev 1c (RED Round 2): the location scope with no rows shows its banner and Retry too.
  const NO_ROWS_UNKNOWN = () => <StockLevelsView {...locationProps({ rows: [], marker: { ...NONE, quarantine: true, unknown: true } })} />;
  it('Rev 1c: no rows and Status unknown → the location banner + Retry above the empty state; no markerNote', () => {
    renderWired(NO_ROWS_UNKNOWN());
    expect(screen.getAllByText("We couldn't finish checking Q-01, so it's marked Status unknown.")).toHaveLength(1);
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
    expect(screen.getByText('Nothing has been stored in Q-01 yet.')).toBeInTheDocument();
    expect(screen.queryByText(/isn't picked for a shipment/)).toBeNull();
    expect(badgeTexts(screen.getByText(/^Showing what's stored in/))).toEqual(['Quarantine', 'Status unknown']);
  });

  it('Rev 1c: a failing Retry with no rows keeps the banner and focus, and announces the location sentence (not unknownBanner)', async () => {
    heldMarkerFetch(false)();
    renderWired(NO_ROWS_UNKNOWN());
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(statusRegion().textContent).toBe("We couldn't finish checking Q-01, so it's marked Status unknown."));
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Retry' }));
    expect(screen.queryByText(/^We couldn't check whether some locations/)).toBeNull();
  });

  it('Rev 1c: a resolving Retry with no rows clears Status unknown from the scope line, focuses the h1, and says markerChecked', async () => {
    const release = heldMarkerFetch(true);
    renderWired(NO_ROWS_UNKNOWN());
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await release();
    await waitFor(() => expect(statusRegion().textContent).toBe('Locations checked.'));
    expect(badgeTexts(screen.getByText(/^Showing what's stored in/))).toEqual(['Quarantine']);
    expect(document.activeElement).toBe(screen.getByRole('heading', { level: 1 }));
  });

  it('Rev 1c: the item scope keeps unknownBanner', () => {
    renderWired(SCOPES[1]![1]());
    expect(screen.getByText("We couldn't check whether some locations are in quarantine or archived. Their rows say Status unknown.")).toBeInTheDocument();
    expect(screen.queryByText(/^We couldn't finish checking/)).toBeNull();
  });

  it('stale result (RED L-2): rows replaced while the Retry was pending → no focus move and nothing announced', async () => {
    const release = heldMarkerFetch(true);
    const view = (rows: ItemRow[]) => (
      <TooltipProvider>
        <BffProvider session={{ sid: SID, tz: TZ }} reload={() => {}}>
          <StockLevelsView {...itemProps({ rows })} />
        </BffProvider>
      </TooltipProvider>
    );
    const { rerender } = render(view([{ ...ITEM_ROWS[0]!, marker: { ...NONE, unknown: true } }]));
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }));
    act(() => rerender(view([{ ...ITEM_ROWS[0]!, marker: { ...NONE, unknown: true } }])));
    const before = document.activeElement;
    await release();
    await new Promise((r) => setTimeout(r, 50));
    expect(statusRegion().textContent).toBe('');
    expect(document.activeElement).toBe(before);
    expect(document.activeElement).not.toBe(screen.getByRole('heading', { level: 1 }));
  });
});

// Pre-commit RED M-3: the shared error of S-9a, S-12 and S-13 (StockLoadFailed, plan deviation D-1).
describe('/stock — S-9a, S-12, S-13: the shared error and its Retry', () => {
  it('the heading by cause, and Retry asks the server again (router.refresh, once)', async () => {
    const { unmount } = renderWired(<StockLoadFailed body={STOCK_LEVELS.loadFailed} unreachable />);
    expect(screen.getByRole('heading', { name: COULD_NOT_REACH })).toBeInTheDocument();
    expect(screen.getByText(STOCK_LEVELS.loadFailed)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: PAGE_STATES.retry }));
    expect(nav.refresh).toHaveBeenCalledTimes(1);
    unmount();
    renderWired(<StockLoadFailed body={STOCK_LEVELS.loadFailed} unreachable={false} />);
    expect(screen.getByRole('heading', { name: WENT_WRONG })).toBeInTheDocument();
  });
});

describe('/stock — the location links (step 2)', () => {
  const LINKS = [
    { id: LQ, code: 'Q-01', kind: 'quarantine', href: `/stock?warehouse_id=${W1}&storage_location_id=${LQ}` },
    { id: LP, code: 'a-02', kind: 'bin', href: `/stock?warehouse_id=${W1}&storage_location_id=${LP}` },
    { id: LA, code: 'A-10', kind: 'shelf', href: `/stock?warehouse_id=${W1}&storage_location_id=${LA}` },
  ];
  it('7.5-6: a case-insensitive prefix filter in the served order; no match → findNoMatch + Clear search; never the address, never a request', async () => {
    const calls = routedFetch(() => json(500, {}));
    renderWired(<LocationLinks links={LINKS} />);
    expect(screen.getAllByRole('link').map((a) => a.textContent)).toEqual(['Q-01 — Quarantine', 'a-02 — Bin', 'A-10 — Shelf']);
    const box = screen.getByRole('searchbox', { name: 'Search by location code' });
    await userEvent.type(box, 'a');
    expect(screen.getAllByRole('link').map((a) => a.textContent)).toEqual(['a-02 — Bin', 'A-10 — Shelf']);
    await userEvent.type(box, 'zz');
    expect(screen.getByText('No location code starts with “azz”.')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Clear search' }));
    expect(screen.getAllByRole('link')).toHaveLength(3);
    expect(nav.replace).not.toHaveBeenCalled();
    expect(nav.push).not.toHaveBeenCalled();
    expect(calls).toEqual([]);
  });
});

describe('/stock — the word ban (7.5-7)', () => {
  it('no text says available, in stock, out of stock, reserved, pickable or free; no control offers a count, move, transfer, adjustment, record, export or download', () => {
    for (const ui of [<StockLevelsView key="i" {...itemProps()} />, <StockLevelsView key="l" {...locationProps({ marker: { ...NONE, unknown: true } })} />, <LocationLinks key="k" links={[]} />]) {
      const { container, unmount } = renderWired(ui);
      expect(container.textContent ?? '').not.toMatch(/\b(available|in stock|out of stock|reserved|pickable|free)\b/i);
      for (const c of [...screen.queryAllByRole('button'), ...screen.queryAllByRole('link')]) {
        expect(c.textContent ?? '').not.toMatch(/^\s*(count|move|transfer|adjust|record|export|download)\b/i);
      }
      unmount();
    }
  });
});
