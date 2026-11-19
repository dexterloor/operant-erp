import type { ReactElement } from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ replace: vi.fn(), push: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: nav.replace, push: nav.push }), usePathname: () => '/stock-movements' }));
import { BffProvider } from '@/app/(app)/BffProvider';
import { StockMovementsUnknownItem } from '@/app/(app)/stock-movements/StockMovementStates';
import { StockMovementsList, type StockMovementsListProps } from '@/app/(app)/stock-movements/StockMovementsList';
import { TooltipProvider } from '@/components/ui/Tooltip';
import type { StockMovement } from '@/lib/api/stock';
import { formatDateTime } from '@/lib/format/dates';
import type { MovementsLoad } from '@/lib/stock/load';
import { json, page, renderWired, SID, stubFetch, textsOf, TZ, type Recorded } from './harness';

// Stock ST-1 (stock-plan.md §2.1, §2.3, §6.4 as amended by stock-st1-red.md M-1, L-3, L-8; UX B-3, B-4, Part C;
// b4-architecture.md Revision 11 R11-2, R11-4, R11-5, R11-8). The real `StockMovementsList` over the REAL
// `lib/stock/load.ts` and the browser transport: every request it sends is read off the wire. No Archived view, no search,
// one fixed filter (the item the address names) on every request, and the list never writes the address.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.replace.mockReset();
  nav.push.mockReset();
});

const RECEIPT_ID = 'e1b2c3d4-0000-4000-8000-000000000003';
const WIDGET = { id: 'c1b2c3d4-0000-4000-8000-000000000001', sku: 'WID-001', name: 'Widget' };
const GADGET = { id: 'c1b2c3d4-0000-4000-8000-000000000002', sku: 'GAD-002', name: 'Gadget' };
const A01 = { id: 'c9b2c3d4-0000-4000-8000-000000000001', code: 'A-01' };
const B02 = { id: 'c9b2c3d4-0000-4000-8000-000000000002', code: 'B-02' };
const mv = (n: number, over: Partial<StockMovement> = {}): StockMovement => ({
  id: `a1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`,
  quantity: '12.000000',
  occurred_at: '2026-03-04T11:00:00Z',
  reason: 'receipt',
  source_type: 'goods_receipt',
  source_id: RECEIPT_ID,
  inventory_item: WIDGET,
  storage_location: A01,
  ...over,
});
const M3 = mv(3, { occurred_at: '2026-03-05T09:30:00Z' });
const M2 = mv(2, { occurred_at: '2026-03-04T11:00:00Z', quantity: '-2.500000', reason: 'fulfilment', source_type: 'fulfilment', inventory_item: GADGET, storage_location: B02 });
const M1 = mv(1, { occurred_at: '2026-03-03T08:00:00Z', quantity: '-0.500000', reason: 'adjustment', source_type: null, source_id: null });
const ok = (items: StockMovement[], over: Partial<Extract<MovementsLoad, { kind: 'ok' }>> = {}): MovementsLoad => ({ kind: 'ok', items, nextCursor: null, previousCursor: null, tenantHasRows: items.length > 0, ...over });
const ALL_GATES = { goodsReceipt: true, movement: true };
const base: StockMovementsListProps = { initial: ok([M3, M2, M1]), itemId: null, itemName: null, gates: ALL_GATES, canOpenItem: true, canListOrders: true };
const show = (over: Partial<StockMovementsListProps> = {}) => renderWired(<StockMovementsList {...base} {...over} />);
const region = () => screen.getByRole('heading', { level: 1 }).closest('section')!.querySelector<HTMLElement>(':scope > [role="status"]')!;
const headers = () => screen.getAllByRole('columnheader').map((h) => h.textContent);
const rowOf = (m: StockMovement) => screen.getByRole('link', { name: new RegExp(`^${formatDateTime(m.occurred_at, TZ).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`) }).closest('tr')!;
const cellsOf = (tr: HTMLElement) => within(tr).getAllByRole('cell').map((c) => c.textContent);

/** Every ListStockMovements request the list sent, as its query, after asserting the rules on EACH one (R10-3, A-1, R11-2). */
function listRequests(calls: readonly Recorded[]) {
  const reqs = calls.filter((c) => c.method === 'GET').map((c) => new URL(c.url, 'http://localhost'));
  expect(reqs.length).toBeGreaterThan(0);
  for (const u of reqs) {
    expect(u.pathname, 'every read goes to the movements collection').toBe('/bff/api/stock-movements');
    const keys = [...u.searchParams.keys()];
    for (const k of ['archived', 'status', 'q', 'sort', 'limit']) expect(keys, `never ${k} (${u.search})`).not.toContain(k);
    expect(keys.includes('after') && keys.includes('before'), `never both cursors (${u.search})`).toBe(false);
    expect(keys.every((k) => ['inventory_item_id', 'after', 'before'].includes(k)), `only the filter and one cursor (${u.search})`).toBe(true);
  }
  return reqs.map((u) => Object.fromEntries(u.searchParams));
}

describe('/stock-movements — the columns and cells (UX B-3; 6.4-1, 6.4-11, 6.4-14)', () => {
  it('Date · What happened · Item · Location · Change (in the item’s unit) · Source, in served order', () => {
    show();
    expect(screen.getByRole('heading', { level: 1, name: 'Stock movements' })).toBeInTheDocument();
    expect(headers()).toEqual(['Date', 'What happened', 'Item', 'Location', "Change (in the item's unit)", 'Source']);
    // D-1: no Unit column until BK-41.
    expect(headers()).not.toContain('Unit');
    expect(cellsOf(rowOf(M3))).toEqual([formatDateTime(M3.occurred_at, TZ), 'Received', 'WID-001 — Widget', 'A-01', '+12', 'Goods receipt']);
    expect(cellsOf(rowOf(M2))).toEqual([formatDateTime(M2.occurred_at, TZ), 'Shipped', 'GAD-002 — Gadget', 'B-02', '−2.5', 'Fulfilment']);
    expect(cellsOf(rowOf(M1))).toEqual([formatDateTime(M1.occurred_at, TZ), 'Count correction', 'WID-001 — Widget', 'A-01', '−0.5', 'None']);
    // Commit RED L-3 (M37): the footer counts the rows on this page, never a total (TB-2b).
    expect(screen.getByText('3 shown', { selector: 'p' })).toBeInTheDocument();
  });

  it('each Date links to its movement page; its name is rowLinkName and starts with the visible date (RED L-3)', () => {
    show();
    const when = formatDateTime(M2.occurred_at, TZ);
    const link = within(rowOf(M2)).getByRole('link', { name: `${when}, shipped −2.5, GAD-002 in B-02` });
    expect(link).toHaveAttribute('href', `/stock-movements/${M2.id}`);
    expect(link.textContent).toBe(when);
    expect(link.querySelector('time')).toHaveAttribute('dateTime', M2.occurred_at);
    expect(link.getAttribute('aria-label')!.startsWith(link.textContent!)).toBe(true);
  });

  it('a Source link’s name is sourceLinkName ({word} for {sku}, {when}) and opens the receipt for a GetGoodsReceipt holder', () => {
    show();
    const when = formatDateTime(M3.occurred_at, TZ);
    expect(within(rowOf(M3)).getByRole('link', { name: `Goods receipt for WID-001, ${when}` })).toHaveAttribute('href', `/goods-receipts/${RECEIPT_ID}`);
    // A shipment and a count are plain words (no page exists for either).
    expect(within(rowOf(M2)).queryByRole('link', { name: /Fulfilment/ })).toBeNull();
  });

  it('without GetGoodsReceipt the receipt is a plain word; without GetItem the item is text; without GetStockMovement the date is text', () => {
    show({ gates: { goodsReceipt: false, movement: false }, canOpenItem: false, initial: ok([M3]) });
    const tr = screen.getByText('A-01').closest('tr')!;
    expect(within(tr).queryAllByRole('link')).toEqual([]);
    expect(cellsOf(tr)).toEqual([formatDateTime(M3.occurred_at, TZ), 'Received', 'WID-001 — Widget', 'A-01', '+12', 'Goods receipt']);
  });

  it('the item links to its page only for a GetItem holder; the location is never a link', () => {
    show({ initial: ok([M3]) });
    const tr = rowOf(M3);
    expect(within(tr).getByRole('link', { name: 'WID-001 — Widget' })).toHaveAttribute('href', `/items/${WIDGET.id}`);
    expect(within(tr).queryByRole('link', { name: 'A-01' })).toBeNull();
  });

  it('an unknown reason reads "Other change", never the raw value; transfer legs say Moved out / Moved in with their partner word', () => {
    const odd = mv(9, { reason: 'zzz_internal', source_type: 'zzz', occurred_at: '2026-03-06T10:00:00Z' });
    const out = mv(8, { reason: 'transfer_out', source_type: 'stock_movement', source_id: 'f1b2c3d4-0000-4000-8000-000000000008', quantity: '-3.000000', occurred_at: '2026-03-07T10:00:00Z' });
    const into = mv(7, { reason: 'transfer_in', source_type: 'stock_movement', source_id: 'f1b2c3d4-0000-4000-8000-000000000007', quantity: '3.000000', occurred_at: '2026-03-08T10:00:00Z', storage_location: B02 });
    show({ initial: ok([into, out, odd]) });
    expect(cellsOf(rowOf(odd)).slice(1)).toEqual(['Other change', 'WID-001 — Widget', 'A-01', '+12', 'Other']);
    expect(document.body.textContent).not.toContain('zzz');
    expect(cellsOf(rowOf(out))[1]).toBe('Moved out');
    expect(within(rowOf(out)).getByRole('link', { name: /^Matching move in for WID-001/ })).toHaveAttribute('href', '/stock-movements/f1b2c3d4-0000-4000-8000-000000000008');
    expect(cellsOf(rowOf(into))[1]).toBe('Moved in');
    expect(within(rowOf(into)).getByRole('link', { name: /^Matching move out for WID-001/ })).toBeInTheDocument();
  });

  it('6.4-11 QR: -0.500000 renders −0.5 (U+2212) and 10.000000 renders +10', () => {
    show({ initial: ok([mv(4, { quantity: '-0.500000' }), mv(5, { quantity: '10.000000', occurred_at: '2026-03-02T08:00:00Z' })]) });
    const changes = screen.getAllByRole('row').slice(1).map((r) => within(r).getAllByRole('cell')[4]!.textContent);
    expect(changes).toEqual(['−0.5', '+10']);
  });

  it('6.4-12: no source_id text anywhere in the table', () => {
    show();
    expect(document.querySelector('table')!.textContent).not.toContain(RECEIPT_ID);
  });
});

describe('/stock-movements — order, views and paging (6.4-2 … 6.4-7, 6.4-13)', () => {
  it('6.4-2: rows render in served order, even out of time order', () => {
    show({ initial: ok([M1, M3, M2]) });
    const dates = screen.getAllByRole('row').slice(1).map((r) => within(r).getAllByRole('cell')[0]!.textContent);
    expect(dates).toEqual([M1, M3, M2].map((m) => formatDateTime(m.occurred_at, TZ)));
  });

  it('6.4-3 (R10-3, A-1): no Archived control, no search box, no view toggle', () => {
    show();
    expect(screen.queryByRole('searchbox')).toBeNull();
    expect(screen.queryByRole('button', { name: /archived|back to/i })).toBeNull();
    expect(document.querySelector('[aria-pressed]')).toBeNull();
  });

  it('6.4-4: Newer · Older; Older sends after=<next_cursor>, Newer before=<previous_cursor>; never limit, q, archived or status', async () => {
    const calls = stubFetch([json(200, page([M1], { previous_cursor: 'p2' })), json(200, page([M3, M2], { next_cursor: 'n1' }))]);
    const u = userEvent.setup();
    show({ initial: ok([M3, M2], { nextCursor: 'n1' }) });
    expect(screen.queryByRole('button', { name: 'Next' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Previous' })).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(screen.queryByText('GAD-002 — Gadget')).toBeNull());
    await u.click(screen.getByRole('button', { name: 'Newer' }));
    await waitFor(() => expect(screen.getByText('GAD-002 — Gadget')).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ after: 'n1' }, { before: 'p2' }]);
    expect(nav.replace).not.toHaveBeenCalled();
    expect(nav.push).not.toHaveBeenCalled();
  });

  it('6.4-6 (R8-1, R11-8): two full pages at the same rendered time with different first rows speak different sentences', async () => {
    const page1 = [mv(11, { occurred_at: '2026-03-04T11:00:05Z' }), mv(10, { occurred_at: '2026-03-04T11:00:01Z' })];
    const page2 = [mv(13, { occurred_at: '2026-03-04T11:00:00Z', inventory_item: GADGET, storage_location: B02 }), mv(12, { occurred_at: '2026-03-04T11:00:00Z' })];
    stubFetch([json(200, page(page2, { previous_cursor: 'p2' })), json(200, page(page1, { next_cursor: 'n1' }))]);
    const u = userEvent.setup();
    show({ initial: ok(page1, { nextCursor: 'n1' }) });
    const said = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(screen.getByText('GAD-002 — Gadget')).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Newer' }));
    await waitFor(() => expect(screen.queryByText('GAD-002 — Gadget')).toBeNull());
    said.stop();
    const when = formatDateTime('2026-03-04T11:00:00Z', TZ);
    expect(said.texts).toContain(`2 movements shown, all at ${when}, starting with GAD-002 in B-02.`);
    expect(said.texts).toContain(`2 movements shown, all at ${when}, starting with WID-001 in A-01.`);
  });

  it('a page whose dates differ names its newest and oldest, then its first row', async () => {
    stubFetch([json(200, page([M3, M2], { previous_cursor: 'p1' }))]);
    const u = userEvent.setup();
    show({ initial: ok([M1], { nextCursor: 'n1' }) });
    const said = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(said.texts).toContain(`2 movements shown, from ${formatDateTime(M3.occurred_at, TZ)} back to ${formatDateTime(M2.occurred_at, TZ)}, starting with WID-001 in A-01.`));
    said.stop();
  });

  it('6.4-7: an empty page under a cursor is "There are no more stock movements here."; Back to the first page sends no cursor and keeps the filter', async () => {
    const calls = stubFetch([json(200, page([], { tenant_has_rows: true })), json(200, page([M3]))]);
    const u = userEvent.setup();
    show({ initial: ok([M3], { nextCursor: 'n1' }), itemId: WIDGET.id, itemName: { sku: WIDGET.sku, name: WIDGET.name } });
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(screen.getByText('There are no more stock movements here.', { selector: 'p' })).toBeInTheDocument());
    expect(screen.getByText('The page you asked for came back empty.')).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Back to the first page' }));
    await waitFor(() => expect(screen.getByText('A-01')).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ inventory_item_id: WIDGET.id, after: 'n1' }, { inventory_item_id: WIDGET.id }]);
    expect(nav.replace).not.toHaveBeenCalled();
    expect(nav.push).not.toHaveBeenCalled();
  });
});

describe('/stock-movements — the item filter (R11-2; 6.4-5, 6.4-8, 6.4-9, 6.4-10, M-1, L-8)', () => {
  const named = { itemId: WIDGET.id, itemName: { sku: WIDGET.sku, name: WIDGET.name } };

  it('6.4-5: a page press each way carries inventory_item_id', async () => {
    const calls = stubFetch([json(200, page([M1], { previous_cursor: 'p1' })), json(200, page([M3], { next_cursor: 'n1' }))]);
    const u = userEvent.setup();
    show({ initial: ok([M3], { nextCursor: 'n1' }), ...named });
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(screen.getByText('Count correction')).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Newer' }));
    await waitFor(() => expect(screen.getByText('Received')).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ inventory_item_id: WIDGET.id, after: 'n1' }, { inventory_item_id: WIDGET.id, before: 'p1' }]);
  });

  it('6.4-5: the region Retry after a failed first read carries inventory_item_id', async () => {
    const calls = stubFetch([json(200, page([M3]))]);
    const u = userEvent.setup();
    show({ initial: { kind: 'unreachable' }, ...named, itemName: null });
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByText('A-01')).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ inventory_item_id: WIDGET.id }]);
  });

  it('6.4-5: an unfiltered list never sends inventory_item_id', async () => {
    const calls = stubFetch([json(200, page([M1]))]);
    const u = userEvent.setup();
    show({ initial: ok([M3], { nextCursor: 'n1' }) });
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(screen.getByText('Count correction')).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ after: 'n1' }]);
  });

  it('6.4-9: the caption and the location note always show; the filter line only while filtered', () => {
    const { unmount } = show();
    expect(screen.getByText('Most recent first', { selector: 'p' })).toBeInTheDocument();
    expect(screen.getByText(/^Locations are shown by their code only\. A code doesn't say which warehouse/)).toBeInTheDocument();
    expect(screen.queryByText(/^Showing only/)).toBeNull();
    expect(screen.queryByRole('link', { name: 'Show all stock movements' })).toBeNull();
    unmount();
    show({ initial: ok([M3]), ...named });
    expect(screen.getByText('Most recent first', { selector: 'p' })).toBeInTheDocument();
    expect(screen.getByText(/^Locations are shown by their code only\./)).toBeInTheDocument();
    expect(screen.getByText(/^Showing only WID-001 — Widget\./)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Show all stock movements' })).toHaveAttribute('href', '/stock-movements');
  });

  it('6.4-8 / M-1 step 4: an empty workspace reads noneYet, filtered or not; its link only for a ListPurchaseOrders holder; filtered keeps Show all stock movements', () => {
    const noneYet = 'No stock movements yet. A stock movement is recorded each time stock comes into or goes out of a location, for example when a delivery is received against a purchase order.';
    const { unmount } = show({ initial: ok([], { tenantHasRows: false }) });
    expect(screen.getByText(noneYet)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Go to purchase orders' })).toHaveAttribute('href', '/purchase-orders');
    unmount();
    const r2 = show({ initial: ok([], { tenantHasRows: false }), canListOrders: false });
    expect(screen.getByText(noneYet)).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Go to purchase orders' })).toBeNull();
    r2.unmount();
    show({ initial: ok([], { tenantHasRows: false }), itemId: WIDGET.id, itemName: null });
    expect(screen.getByText(noneYet)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Show all stock movements' })).toHaveAttribute('href', '/stock-movements');
  });

  it('6.4-8 / M-1 steps 5–6: filtered and empty while the workspace has movements → noneForItem(sku) when named, noneForThisItem when not; each with Show all stock movements', () => {
    const { unmount } = show({ initial: ok([], { tenantHasRows: true }), ...named });
    expect(screen.getByText('No stock movements for WID-001 yet.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Show all stock movements' })).toBeInTheDocument();
    expect(screen.queryByText(/^No stock movements yet/)).toBeNull();
    unmount();
    show({ initial: ok([], { tenantHasRows: true }), itemId: WIDGET.id, itemName: null });
    expect(screen.getByText('No stock movements for this item yet.')).toBeInTheDocument();
    expect(screen.getByText('Showing only one item.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Show all stock movements' })).toBeInTheDocument();
  });

  it('M-1 client rule: a Retry after a failed first read that lands empty renders noneForThisItem, never noneYet', async () => {
    stubFetch([json(200, page([], { tenant_has_rows: true }))]);
    const u = userEvent.setup();
    show({ initial: { kind: 'server-error' }, itemId: WIDGET.id, itemName: null });
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByText('No stock movements for this item yet.', { selector: 'p' })).toBeInTheDocument());
    expect(screen.queryByText(/^No stock movements yet/)).toBeNull();
  });

  it('L-8: a successful Retry with rows after a failed first read shows "Showing only one item." (accepted fail-soft)', async () => {
    stubFetch([json(200, page([M3]))]);
    const u = userEvent.setup();
    show({ initial: { kind: 'unreachable' }, itemId: WIDGET.id, itemName: null });
    const said = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByText('A-01')).toBeInTheDocument());
    expect(screen.getByText(/^Showing only one item\./)).toBeInTheDocument();
    // Commit RED L-3 (M36): the settled announcement after the Retry names the filter.
    await waitFor(() => expect(said.texts).toContain('1 stock movement shown. Showing only one item.'));
    said.stop();
  });

  // Commit RED L-1 (M33): the unknown-item state (RED M-1 steps 1 and 5) — the heading, the sentence and the way back, no table.
  it('the unknown-item state: h1 Stock movements, the unknownItem sentence, Show all stock movements → /stock-movements, no table', () => {
    renderWired(<StockMovementsUnknownItem />);
    expect(screen.getByRole('heading', { level: 1, name: 'Stock movements' })).toBeInTheDocument();
    expect(screen.getByText("We couldn't find the item this list is filtered to. It may have been deleted, or you may not have access.")).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Show all stock movements' })).toHaveAttribute('href', '/stock-movements');
    expect(document.querySelector('table')).toBeNull();
  });

  it('a filtered page sentence ends with the filter line', async () => {
    stubFetch([json(200, page([M1], { previous_cursor: 'p1' }))]);
    const u = userEvent.setup();
    show({ initial: ok([M3], { nextCursor: 'n1' }), ...named });
    const said = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(said.texts).toContain(`1 movement shown, ${formatDateTime(M1.occurred_at, TZ)}, ${M1.inventory_item.sku} in ${M1.storage_location.code}. Showing only WID-001 — Widget.`));
    said.stop();
  });

  it('6.4-10 (R11-2): the page keys the list by the filter — a new key mounts the second filter’s rows, not the first’s', () => {
    const wrap = (ui: ReactElement) => (
      <TooltipProvider>
        <BffProvider session={{ sid: SID, tz: TZ }} reload={() => {}}>
          {ui}
        </BffProvider>
      </TooltipProvider>
    );
    const { rerender } = render(wrap(<StockMovementsList key={WIDGET.id} {...base} initial={ok([M3])} {...named} />));
    expect(screen.getByText('WID-001 — Widget')).toBeInTheDocument();
    rerender(wrap(<StockMovementsList key={GADGET.id} {...base} initial={ok([M2])} itemId={GADGET.id} itemName={{ sku: GADGET.sku, name: GADGET.name }} />));
    expect(screen.getByText('GAD-002 — Gadget')).toBeInTheDocument();
    expect(screen.queryByText('WID-001 — Widget')).toBeNull();
  });
});
