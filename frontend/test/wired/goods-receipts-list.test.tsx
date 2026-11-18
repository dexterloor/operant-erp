import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: nav.replace }), usePathname: () => '/goods-receipts' }));
import { SEARCH_SETTLE_MS } from '@/app/(app)/ArchivableList';
import { GoodsReceiptsList } from '@/app/(app)/goods-receipts/GoodsReceiptsList';
import type { GoodsReceipt } from '@/lib/api/purchasing';
import { pageQuery } from '@/lib/bff/reads';
import { formatDateTime } from '@/lib/format/dates';
import { loadReceipts, type ReceiptsLoad } from '@/lib/purchasing/load';
import { json, page, renderWired, stubFetch, textsOf, TZ, type Recorded } from './harness';

// Goods receipts GR-1 (purchase-orders-plan.md §2.7, Revision 0 §4 GR-1, R1-5 GR-1; final RED GR-1 / L-7 / L-3 Note A;
// b4-architecture.md Revision 10 R10-3, R10-4; UX B-9 `GOODS_RECEIPTS_LIST`, R1a-1 row 8). The register is the real
// `GoodsReceiptsList` over the REAL `lib/purchasing/load.ts` and the browser transport: every request it sends is read off
// the wire. It has no Archived view (R10-3: no control, never `archived`) and no fixed filter (R10-4: never
// `purchase_order_id`), and it never shows a cost.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.replace.mockReset();
});

const ORDER = { id: 'd1b2c3d4-0000-4000-8000-000000000001', order_number: 'PO-000001' };
const receipt = (n: number, over: Partial<GoodsReceipt> = {}): GoodsReceipt => ({
  id: `e1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`,
  receipt_number: `GR-${String(n).padStart(6, '0')}`,
  received_at: '2026-03-04T11:00:00Z',
  purchase_order: ORDER,
  received_by: { id: 'f1b2c3d4-0000-4000-8000-000000000001', display_name: 'Wanda Warehouse' },
  lines: [
    { id: `a1b2c3d4-0000-4000-8000-${String(n).padStart(10, '0')}01`, purchase_order_line: { id: 'b1b2c3d4-0000-4000-8000-000000000001', line_number: 1 }, inventory_item: { id: 'c1b2c3d4-0000-4000-8000-000000000001', sku: 'SKU-1', name: 'Widget' }, quantity: '10.500000', storage_location: { id: 'c9b2c3d4-0000-4000-8000-000000000001', code: 'A-01' }, unit_of_measure_code: 'EA' },
    { id: `a1b2c3d4-0000-4000-8000-${String(n).padStart(10, '0')}02`, purchase_order_line: { id: 'b1b2c3d4-0000-4000-8000-000000000002', line_number: 2 }, inventory_item: { id: 'c1b2c3d4-0000-4000-8000-000000000002', sku: 'SKU-2', name: 'Gadget' }, quantity: '3.000000', storage_location: { id: 'c9b2c3d4-0000-4000-8000-000000000002', code: 'A-02' }, unit_of_measure_code: 'EA' },
  ],
  ...over,
});
const G3 = receipt(3);
const G2 = receipt(2, { received_by: null });
const G1 = receipt(1, { lines: [receipt(1).lines[0]!] });
const ok = (items: GoodsReceipt[], over: Partial<Extract<ReceiptsLoad, { kind: 'ok' }>> = {}): ReceiptsLoad => ({ kind: 'ok', items, nextCursor: null, previousCursor: null, tenantHasRows: items.length > 0, ...over });
const region = () => screen.getByRole('heading', { level: 1 }).closest('section')!.querySelector<HTMLElement>(':scope > [role="status"]')!;
const settle = () => act(() => new Promise((r) => setTimeout(r, SEARCH_SETTLE_MS + 100)));
const headers = () => screen.getAllByRole('columnheader').map((h) => h.textContent);
const cells = (text: string) => within(screen.getByText(text).closest('tr')!).getAllByRole('cell').map((c) => c.textContent);

/** Every ListGoodsReceipts request the register sent, as its query, after asserting the rules on EACH one (R10-3, R10-4). */
function registerRequests(calls: readonly Recorded[]) {
  const reqs = calls.filter((c) => c.method === 'GET').map((c) => new URL(c.url, 'http://localhost'));
  expect(reqs.length).toBeGreaterThan(0);
  for (const u of reqs) {
    const p = u.searchParams;
    expect(u.pathname, 'every read goes to the goods-receipts collection').toBe('/bff/api/goods-receipts');
    expect(p.has('archived'), `R10-3: the register never sends archived (${u.search})`).toBe(false);
    expect(p.has('purchase_order_id'), `R10-4: the register has no fixed filter (${u.search})`).toBe(false);
    expect(p.has('after') && p.has('before'), `never both cursors (${u.search})`).toBe(false);
    expect([...p.keys()].every((k) => ['q', 'after', 'before'].includes(k)), `only q and one cursor (${u.search})`).toBe(true);
  }
  return reqs.map((u) => Object.fromEntries(u.searchParams));
}

describe('R10-4 — pageQuery carries one fixed filter', () => {
  it('pageQuery(false, page, undefined, fixed) sends the filter with the search and the cursor; without it, none', () => {
    const fixed = { name: 'purchase_order_id', value: ORDER.id };
    expect(pageQuery(false, { q: '', cursor: null }, undefined, fixed).toString()).toBe(`purchase_order_id=${ORDER.id}`);
    expect(pageQuery(false, { q: 'GR', cursor: { after: 'n1' } }, undefined, fixed).toString()).toBe(`purchase_order_id=${ORDER.id}&q=GR&after=n1`);
    expect(pageQuery(false, { q: 'GR', cursor: { before: 'p1' } }).toString()).toBe('q=GR&before=p1');
    // R10-1 is unchanged by it.
    expect(pageQuery(false, { q: '', cursor: null }, 'draft').toString()).toBe('status=draft');
  });

  it('loadReceipts sends the fixed filter only when given one, and refuses a malformed answer', async () => {
    const calls = stubFetch([json(200, page([G3])), json(200, page([G3])), json(200, { items: [{ ...G3, lines: 'x' }], next_cursor: null, previous_cursor: null, tenant_has_rows: true }), json(200, { items: [G3], next_cursor: null, previous_cursor: null })]);
    const { readOf } = await import('@/lib/bff/reads');
    const { testCaller } = await import('./harness');
    const read = readOf(testCaller());
    expect((await loadReceipts(read, { q: '', cursor: null })).kind).toBe('ok');
    expect((await loadReceipts(read, { q: '', cursor: null }, undefined, { name: 'purchase_order_id', value: ORDER.id })).kind).toBe('ok');
    expect((await loadReceipts(read, { q: '', cursor: null })).kind).toBe('server-error');
    expect((await loadReceipts(read, { q: '', cursor: null })).kind).toBe('server-error');
    const queries = calls.map((c) => new URL(c.url, 'http://localhost').search);
    expect(queries.slice(0, 2)).toEqual(['', `?purchase_order_id=${ORDER.id}`]);
  });
});

describe('/goods-receipts — the register (UX B-9)', () => {
  it('Receipt · Purchase order · Received · Received by · Lines; highest receipt number first; the number links to the receipt page', () => {
    renderWired(<GoodsReceiptsList initial={ok([G3, G2, G1])} canOpenReceipt canOpenOrder />);
    expect(screen.getByRole('heading', { level: 1, name: 'Goods receipts' })).toBeInTheDocument();
    expect(headers()).toEqual(['Receipt', 'Purchase order', 'Received', 'Received by', 'Lines']);
    expect(within(screen.getByText('GR-000003').closest('tr')!).getByRole('link', { name: 'GR-000003' })).toHaveAttribute('href', `/goods-receipts/${G3.id}`);
    expect(within(screen.getByText('GR-000003').closest('tr')!).getByRole('link', { name: 'PO-000001' })).toHaveAttribute('href', `/purchase-orders/${ORDER.id}`);
    // The caption names the served order, receipt number descending (gr-1-followups-ux.md §2: "Newest first" was false
    // against the Received column, which the sender may backdate).
    expect(screen.getByText('Highest receipt number first', { selector: 'p' })).toBeInTheDocument();
    expect(screen.queryByText('Newest first')).toBeNull();
    // Received: DT-1's date-and-time form in the viewer's zone (UX D-7; gr-1-red L-5).
    expect(cells('GR-000003')[2]).toBe(formatDateTime(G3.received_at, TZ));
    expect(cells('GR-000003')[3]).toBe('Wanda Warehouse');
    // Lines is a count of the served lines, not a quantity sum.
    expect(cells('GR-000003')[4]).toBe('2');
    expect(cells('GR-000001')[4]).toBe('1');
  });

  it('Received by null reads "Not recorded", never a dash or "null"', () => {
    renderWired(<GoodsReceiptsList initial={ok([G2])} canOpenReceipt canOpenOrder />);
    expect(cells('GR-000002')[3]).toBe('Not recorded');
  });

  // Plan §4 GR-1: the order link only with GetPurchaseOrder (the viewer shape: goods_receipt.view without purchase_order.view).
  it('the order is its number, linked only for a GetPurchaseOrder holder; the receipt number linked only for a GetGoodsReceipt holder', () => {
    renderWired(<GoodsReceiptsList initial={ok([G3])} canOpenReceipt={false} canOpenOrder={false} />);
    const row = screen.getByText('GR-000003').closest('tr')!;
    expect(within(row).queryAllByRole('link')).toEqual([]);
    expect(cells('GR-000003')[1]).toBe('PO-000001');
  });

  it('R10-3 — no Archived control, no Back to…, no view toggle of any kind is rendered', () => {
    renderWired(<GoodsReceiptsList initial={ok([G3])} canOpenReceipt canOpenOrder />);
    expect(screen.queryByRole('button', { name: /archived/i })).toBeNull();
    expect(screen.queryByRole('button', { name: /back to/i })).toBeNull();
    expect(document.querySelector('[aria-pressed]')).toBeNull();
    expect(document.querySelector('caption')).toBeNull();
  });

  it('R10-3 / R10-4 — a search, a page press and Clear search never send archived or purchase_order_id; q is sent as typed', async () => {
    const calls = stubFetch([json(200, page([], { tenant_has_rows: true })), json(200, page([G3, G2], { next_cursor: 'n2' })), json(200, page([G1], { previous_cursor: 'p1' }))]);
    const u = userEvent.setup();
    renderWired(<GoodsReceiptsList initial={ok([G3, G2], { nextCursor: 'n1' })} canOpenReceipt canOpenOrder />);
    await u.type(screen.getByRole('searchbox', { name: 'Search by receipt number' }), 'gr-0');
    await settle();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Clear search' })).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Clear search' }));
    await waitFor(() => expect(screen.getByText('GR-000002')).toBeInTheDocument());
    // A page press reads the next page under the settled (empty) search.
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(screen.getByText('GR-000001')).toBeInTheDocument());
    expect(registerRequests(calls)).toEqual([{ q: 'gr-0' }, {}, { after: 'n2' }]);
    // The register never writes the address (plan Rev 1 GR-1; gr-1-red L-8): a reload opens page 1.
    expect(nav.replace).not.toHaveBeenCalled();
  });

  // R8-1 item 3 as amended (gr-1-followups-red.md H-1): a view ordered by a key says Previous / Next — the register is
  // served by receipt number, so never Newer / Older — and the `next` label's button sends `after=<next_cursor>`.
  it('paging: Previous · Next (key-ordered); Next sends after=<next_cursor>, Previous before=<previous_cursor>; the page sentence names the first and last receipt numbers', async () => {
    const calls = stubFetch([json(200, page([G2, G1], { previous_cursor: 'p2' })), json(200, page([G3], { next_cursor: 'n1' }))]);
    const u = userEvent.setup();
    renderWired(<GoodsReceiptsList initial={ok([G3], { nextCursor: 'n1' })} canOpenReceipt canOpenOrder />);
    expect(screen.queryByRole('button', { name: 'Older' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Newer' })).toBeNull();
    const said = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(screen.getByText('GR-000001')).toBeInTheDocument());
    await waitFor(() => expect(said.texts).toContain('2 receipts shown, GR-000002 to GR-000001.'));
    await u.click(screen.getByRole('button', { name: 'Previous' }));
    await waitFor(() => expect(screen.getByText('GR-000003')).toBeInTheDocument());
    said.stop();
    expect(registerRequests(calls)).toEqual([{ after: 'n1' }, { before: 'p2' }]);
    expect(nav.replace).not.toHaveBeenCalled();
  });

  // UX D-9: heading and action confirmed; the body says only what happened (receipts are never deleted).
  it('a page press that comes back empty: "There are no more goods receipts here." · "The page you asked for came back empty." · Back to the first page', async () => {
    stubFetch([json(200, page([], { tenant_has_rows: true }))]);
    const u = userEvent.setup();
    renderWired(<GoodsReceiptsList initial={ok([G3], { nextCursor: 'n1' })} canOpenReceipt canOpenOrder />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(screen.getByText('There are no more goods receipts here.', { selector: 'p' })).toBeInTheDocument());
    expect(screen.getByText('The page you asked for came back empty.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Back to the first page' })).toBeInTheDocument();
  });

  it('a settled search speaks a sentence naming the search', async () => {
    stubFetch([json(200, page([G3]))]);
    const u = userEvent.setup();
    renderWired(<GoodsReceiptsList initial={ok([G3, G2])} canOpenReceipt canOpenOrder />);
    const said = textsOf(region());
    await u.type(screen.getByRole('searchbox'), 'GR-0');
    await settle();
    await waitFor(() => expect(said.texts).toContain('1 receipt shown. Receipt number starts with “GR-0”.'));
    said.stop();
  });

  // UX R1a-1 row 8 / final RED L-3 Note A: GR-1's noneYet is slot 1 only, with no link — even for a ListPurchaseOrders holder.
  it('R1a-1 row 8 — an empty workspace reads exactly "No deliveries recorded yet.", with no link; the GR-2 form is not rendered', () => {
    renderWired(<GoodsReceiptsList initial={ok([], { tenantHasRows: false })} canOpenReceipt canOpenOrder />);
    expect(screen.getByText('No deliveries recorded yet.')).toBeInTheDocument();
    expect(screen.queryByText(/appears here when a delivery is received/)).toBeNull();
    expect(screen.queryByRole('link', { name: 'Go to purchase orders' })).toBeNull();
    expect(screen.queryAllByRole('link')).toEqual([]);
  });

  it('a search with no match says so, naming the search, with Clear search', async () => {
    stubFetch([json(200, page([], { tenant_has_rows: true }))]);
    const u = userEvent.setup();
    renderWired(<GoodsReceiptsList initial={ok([G3])} canOpenReceipt canOpenOrder />);
    await u.type(screen.getByRole('searchbox'), 'GR-9');
    await settle();
    await waitFor(() => expect(screen.getByText('No receipt number starts with “GR-9”.', { selector: 'p' })).toBeInTheDocument());
    expect(screen.getByRole('button', { name: 'Clear search' })).toBeInTheDocument();
  });

  it('the search box says what it matches (UX B-9 searchHint)', () => {
    renderWired(<GoodsReceiptsList initial={ok([G3])} canOpenReceipt canOpenOrder />);
    expect(screen.getByRole('searchbox', { name: 'Search by receipt number' })).toHaveAccessibleDescription('For example GR-000123. Receipt numbers are matched as typed, so use capitals.');
    expect(screen.getByRole('searchbox')).toHaveAttribute('placeholder', 'Start of a receipt number');
  });

  it('a failed first load is the region error with the list’s loadFailed sentence', () => {
    renderWired(<GoodsReceiptsList initial={{ kind: 'unreachable' }} canOpenReceipt canOpenOrder />);
    expect(screen.getByText("The list of goods receipts couldn't be loaded.")).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /archived/i })).toBeNull();
  });
});

describe('R10-3 — a paged list without archivedView never reads the Archived view, whichever slot asks', () => {
  it('a header that calls switchTo(true) sends nothing and the list stays live', async () => {
    const { ArchivableList } = await import('@/app/(app)/ArchivableList');
    const calls = stubFetch([]);
    const u = userEvent.setup();
    renderWired(
      <ArchivableList<GoodsReceipt>
        paging={{ pager: () => ({ previous: 'Newer', next: 'Older' }), noMore: () => ({ heading: 'h', body: 'b', action: 'a' }), pageShown: () => 's' }}
        initial={ok([G3])}
        initialArchived
        load={async () => ({ kind: 'ok' as const, items: [], nextCursor: null, previousCursor: null })}
        search={false}
        columns={() => [{ header: 'Receipt', cell: (r) => r.receipt_number }]}
        copy={{ heading: 'Goods receipts', archived: 'Archived', loadFailed: 'x', shown: () => 's' }}
        header={({ switchTo }) => <button onClick={() => switchTo(true)}>Planted archived switch</button>}
        empty={() => ({ heading: 'none' })}
        denied={<p>denied</p>}
      />,
    );
    await u.click(screen.getByRole('button', { name: 'Planted archived switch' }));
    await settle();
    expect(calls).toEqual([]);
    expect(screen.getByText('GR-000003')).toBeInTheDocument();
    expect(screen.queryByRole('searchbox')).toBeNull();
  });
  // M16 (initialArchived honoured without an Archived view) cannot reach the wire through the receipts loader, which never
  // sends `archived`: this test reads the core's own call to `load`, so the view a page press reads is seen.
  it('initialArchived is ignored: a page press asks the loader for the live view', async () => {
    const { ArchivableList } = await import('@/app/(app)/ArchivableList');
    const seen: boolean[] = [];
    const u = userEvent.setup();
    renderWired(
      <ArchivableList<GoodsReceipt>
        paging={{ pager: () => ({ previous: 'Newer', next: 'Older' }), noMore: () => ({ heading: 'h', body: 'b', action: 'a' }), pageShown: () => 's' }}
        initial={ok([G3], { nextCursor: 'n1' })}
        initialArchived
        load={async (_read: unknown, archived: boolean) => {
          seen.push(archived);
          return { kind: 'ok' as const, items: [G2], nextCursor: null, previousCursor: null };
        }}
        search={false}
        columns={() => [{ header: 'Receipt', cell: (r) => r.receipt_number }]}
        copy={{ heading: 'Goods receipts', archived: 'Archived', loadFailed: 'x', shown: () => 's' }}
        empty={() => ({ heading: 'none' })}
        denied={<p>denied</p>}
      />,
    );
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(screen.getByText('GR-000002')).toBeInTheDocument());
    expect(seen).toEqual([false]);
  });
});
