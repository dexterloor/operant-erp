import { readFileSync } from 'node:fs';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: nav.replace }), usePathname: () => '/purchase-orders' }));
import { SEARCH_SETTLE_MS } from '@/app/(app)/ArchivableList';
import { PurchaseOrdersList } from '@/app/(app)/purchase-orders/PurchaseOrdersList';
import { PURCHASE_ORDER_SEGMENTS, type PurchaseOrder } from '@/lib/api/purchasing';
import { pageQuery } from '@/lib/bff/reads';
import { addressedView, type PurchaseOrdersLoad } from '@/lib/purchasing/load';
import { json, page, renderWired, stubFetch, textsOf, type Recorded } from './harness';
import styles from '@/app/(app)/Lists.module.css';

// Purchase orders PO-1 (purchase-orders-plan.md Revision 0 §4 PO-1 tests 1–8, 13–15; R1-5 PO-1 15, 17; final RED M-5's
// two fails-if tests and L-4; b4-architecture.md Revision 10 R10-1 as amended by final RED M-5). The list is the real
// `PurchaseOrdersList` over the REAL `lib/purchasing/load.ts` and the browser transport: every request it sends is read
// off the wire and checked — a live read names its segment as `status`, an Archived one sends `archived=true` and no
// `status`, never `limit`, `sort` or `supplier_id`, never `after` and `before` together, `q` only when not empty. The
// paging core's own rules stay pinned in `paged-list.test.tsx` (unedited); here the segments, the family's wiring,
// columns, copy and empty states.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.replace.mockReset();
});

const SUP = { id: 'c1b2c3d4-0000-4000-8000-0000000000a1', name: 'Acme Supplies' };
const WH = { id: 'c1b2c3d4-0000-4000-8000-0000000000b1', name: 'Main warehouse' };
const po = (n: number, status: string, over: Partial<PurchaseOrder> = {}): PurchaseOrder => ({
  id: `d1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`,
  order_number: `PO-${String(n).padStart(6, '0')}`,
  status,
  supplier: SUP,
  warehouse: WH,
  currency_code: 'GBP',
  total_amount: '1250.5000',
  ordered_at: status === 'draft' ? null : '2026-03-04T11:00:00Z',
  expected_at: null,
  created_by: { id: 'e1b2c3d4-0000-4000-8000-000000000001', display_name: 'Bea Buyer' },
  archived_at: null,
  ...over,
});
const O1 = po(1, 'ordered');
const O2 = po(2, 'partially_received', { total_amount: '123456789012345.0000', currency_code: 'EUR', expected_at: '2026-04-01T00:00:00Z' });
const O3 = po(3, 'ordered', { total_amount: '250.0000', currency_code: 'JPY' });
const D1 = po(11, 'draft');
const D2 = po(12, 'draft');
const D3 = po(13, 'draft');
const R1 = po(21, 'received');
const C1 = po(31, 'cancelled', { ordered_at: null, total_amount: '0.3333' });
const X1 = po(41, 'draft', { archived_at: '2026-03-04T11:00:00Z' });
const ok = (items: PurchaseOrder[], over: Partial<Extract<PurchaseOrdersLoad, { kind: 'ok' }>> = {}): PurchaseOrdersLoad => ({ kind: 'ok', items, nextCursor: null, previousCursor: null, tenantHasRows: items.length > 0, ...over });
const region = () => screen.getByRole('heading', { level: 1 }).closest('section')!.querySelector<HTMLElement>(':scope > [role="status"]')!;
const settle = () => act(() => new Promise((r) => setTimeout(r, SEARCH_SETTLE_MS + 100)));
const headers = () => screen.getAllByRole('columnheader').map((h) => h.textContent);
const cells = (text: string) => within(screen.getByText(text).closest('tr')!).getAllByRole('cell').map((c) => c.textContent);
const segmentButton = (name: string) => screen.getByRole('button', { name });
const pressed = () => ['Open', 'Draft', 'Received', 'Cancelled'].filter((n) => screen.queryByRole('button', { name: n })?.getAttribute('aria-pressed') === 'true');

/**
 * Every ListPurchaseOrders request the real loader sent, as its query, after asserting the rules on EACH one (test 1 and
 * test 3; Revision 8 amendment 6, limit stated: a loader building a correct query by hand is not detected; a wrong one is).
 */
function listRequests(calls: readonly Recorded[]) {
  const reqs = calls.filter((c) => c.method === 'GET').map((c) => new URL(c.url, 'http://localhost'));
  expect(reqs.length).toBeGreaterThan(0);
  for (const u of reqs) {
    const p = u.searchParams;
    expect(u.pathname, 'every list read goes to the purchase-orders collection').toBe('/bff/api/purchase-orders');
    for (const never of ['limit', 'sort', 'supplier_id']) expect(p.has(never), `${never} is never sent (${u.search})`).toBe(false);
    expect(p.has('after') && p.has('before'), `never both cursors (${u.search})`).toBe(false);
    expect(p.has('archived') && p.has('status'), `never archived with status (${u.search})`).toBe(false);
    // Test 3: the unlisted "every live status" view (no status, not archived) is never read.
    expect(p.get('archived') === 'true' || p.has('status'), `every read names a segment or the Archived view (${u.search})`).toBe(true);
    expect(p.has('q') && p.get('q') === '', `an empty search sends no q (${u.search})`).toBe(false);
    expect([...p.keys()].every((k) => ['archived', 'status', 'q', 'after', 'before'].includes(k)), `only declared, wired parameters (${u.search})`).toBe(true);
  }
  return reqs.map((u) => Object.fromEntries(u.searchParams));
}

describe('PO-1 — the segments are the served ones', () => {
  it('PURCHASE_ORDER_SEGMENTS equals the served enum of ListPurchaseOrders’ status parameter, in order (no unknown_segment is reachable)', () => {
    const openapi = JSON.parse(readFileSync('../contracts/openapi.json', 'utf8')) as { paths: Record<string, Record<string, { operationId?: string; parameters?: { name: string; schema?: { enum?: string[] } }[] }>> };
    const op = Object.values(openapi.paths).flatMap((p) => Object.values(p)).find((o) => o.operationId === 'ListPurchaseOrders')!;
    expect(op.parameters!.find((p) => p.name === 'status')!.schema!.enum).toEqual([...PURCHASE_ORDER_SEGMENTS]);
  });

  it('pageQuery: a live read with a segment sends status; Archived ignores it; no segment sends none (R10-1)', () => {
    expect(pageQuery(false, { q: '', cursor: null }, 'draft').toString()).toBe('status=draft');
    expect(pageQuery(true, { q: '', cursor: null }, 'draft').toString()).toBe('archived=true');
    expect(pageQuery(false, { q: 'PO', cursor: { after: 'x' } }).toString()).toBe('q=PO&after=x');
  });

  it('the address names the view: ?status= a served segment, ?archived=true wins, anything else is Open (test 2, reload half)', () => {
    expect(addressedView({})).toEqual({ archived: false, segment: 'open' });
    expect(addressedView({ status: 'draft' })).toEqual({ archived: false, segment: 'draft' });
    expect(addressedView({ status: 'cancelled' })).toEqual({ archived: false, segment: 'cancelled' });
    expect(addressedView({ status: 'partially_received' })).toEqual({ archived: false, segment: 'open' });
    expect(addressedView({ status: ['draft', 'received'] })).toEqual({ archived: false, segment: 'open' });
    expect(addressedView({ archived: 'true', status: 'draft' })).toEqual({ archived: true, segment: 'open' });
    expect(addressedView({ archived: 'false', status: 'received' })).toEqual({ archived: false, segment: 'received' });
  });
});

describe('/purchase-orders — the Open segment (UX B-1 / R1-B)', () => {
  it('Order · Supplier · Warehouse · Status · Ordered on · Expected · Total; the number links to /purchase-orders/[id]; Open is pressed and captioned', () => {
    renderWired(<PurchaseOrdersList initial={ok([O1, O2, O3])} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Purchase orders' })).toBeInTheDocument();
    expect(headers()).toEqual(['Order', 'Supplier', 'Warehouse', 'Status', 'Ordered on', 'Expected', 'Total']);
    expect(within(screen.getByText('PO-000001').closest('tr')!).getByRole('link', { name: 'PO-000001' })).toHaveAttribute('href', `/purchase-orders/${O1.id}`);
    expect(pressed()).toEqual(['Open']);
    expect(screen.getByText('Ordered longest ago first', { selector: 'p' })).toBeInTheDocument();
    expect(document.querySelector('caption')).toBeNull();
    for (const r of document.querySelectorAll('tbody tr')) expect(r.className).not.toBe(styles.struck);
    expect(screen.getByText('3 shown')).toBeInTheDocument();
  });

  // Test 6 (Q-3, money.ts): the total in D1's form by dropping only zeros, every digit kept, never through a number.
  it('test 6 — money: 1250.5000 GBP → 1250.50 GBP; 123456789012345.0000 EUR → 123456789012345.00 EUR; 250.0000 JPY → 250 JPY', () => {
    renderWired(<PurchaseOrdersList initial={ok([O1, O2, O3])} />);
    expect(cells('PO-000001')[6]).toBe('1250.50 GBP');
    expect(cells('PO-000002')[6]).toBe('123456789012345.00 EUR');
    expect(cells('PO-000003')[6]).toBe('250 JPY');
  });

  // Test 8.
  it('test 8 — status from the copy map (Ordered, Partly received); an unknown served status renders as served', () => {
    renderWired(<PurchaseOrdersList initial={ok([O1, O2, po(9, 'on_hold')])} />);
    expect(cells('PO-000001')[3]).toBe('Ordered');
    expect(cells('PO-000002')[3]).toBe('Partly received');
    expect(cells('PO-000009')[3]).toBe('on_hold');
  });

  it('dates are DT-1; an expected date not set reads "Not set", never a dash', () => {
    renderWired(<PurchaseOrdersList initial={ok([O1, O2])} />);
    expect(cells('PO-000001')[5]).toBe('Not set');
    expect(cells('PO-000002')[5]).not.toBe('Not set');
    expect(cells('PO-000001')[4]).not.toBe('Not ordered');
  });

  it('the search box says what it matches, tied to it (TB-5a: order numbers as typed)', () => {
    renderWired(<PurchaseOrdersList initial={ok([O1])} />);
    const box = screen.getByRole('searchbox', { name: 'Search by order number' });
    expect(box).toHaveAttribute('placeholder', 'Start of an order number');
    expect(box).toHaveAccessibleDescription('For example PO-0001. Order numbers are matched as typed, so use capitals.');
  });

  it('PO-1 ships no action: no New purchase order, no create link, no sort control, no supplier filter', () => {
    for (const initial of [ok([O1]), ok([], { tenantHasRows: false }), ok([], { tenantHasRows: true })]) {
      const { unmount } = renderWired(<PurchaseOrdersList initial={initial} />);
      expect(document.querySelector('a[href="/purchase-orders/new"]')).toBeNull();
      expect(screen.queryByRole('link', { name: /new purchase order|create purchase order/i })).toBeNull();
      expect(screen.queryByRole('combobox')).toBeNull();
      unmount();
    }
  });
});

describe('/purchase-orders — segment switches (R10-1 as amended by final RED M-5)', () => {
  // Test 1 + the address half of test 2 + M-5's first fails-if (a live → live switch writes ?status=).
  it('test 1/2 — Draft sends status=draft and no cursor, writes ?status=draft; Open again writes no parameter; Draft and Received columns', async () => {
    const calls = stubFetch([json(200, page([D1, D2])), json(200, page([R1])), json(200, page([O1]))]);
    const u = userEvent.setup();
    renderWired(<PurchaseOrdersList initial={ok([O1])} />);
    await u.click(segmentButton('Draft'));
    await waitFor(() => expect(pressed()).toEqual(['Draft']));
    expect(headers()).toEqual(['Order', 'Supplier', 'Warehouse', 'Expected', 'Total']);
    expect(screen.getByText('Lowest order number first', { selector: 'p' })).toBeInTheDocument();
    expect(nav.replace).toHaveBeenLastCalledWith('/purchase-orders?status=draft', { scroll: false });
    await u.click(segmentButton('Received'));
    await waitFor(() => expect(pressed()).toEqual(['Received']));
    expect(headers()).toEqual(['Order', 'Supplier', 'Warehouse', 'Ordered on', 'Total']);
    expect(nav.replace).toHaveBeenLastCalledWith('/purchase-orders?status=received', { scroll: false });
    await u.click(segmentButton('Open'));
    await waitFor(() => expect(pressed()).toEqual(['Open']));
    expect(nav.replace).toHaveBeenLastCalledWith('/purchase-orders', { scroll: false });
    expect(nav.replace).toHaveBeenCalledTimes(3);
    expect(listRequests(calls)).toEqual([{ status: 'draft' }, { status: 'received' }, { status: 'open' }]);
  });

  it('Cancelled: Order · Supplier · Warehouse · Ordered on · Total; an order cancelled as a draft reads "Not ordered"; a finer total shows as stored', async () => {
    const calls = stubFetch([json(200, page([C1]))]);
    const u = userEvent.setup();
    renderWired(<PurchaseOrdersList initial={ok([O1])} />);
    await u.click(segmentButton('Cancelled'));
    await waitFor(() => expect(pressed()).toEqual(['Cancelled']));
    expect(headers()).toEqual(['Order', 'Supplier', 'Warehouse', 'Ordered on', 'Total']);
    expect(cells('PO-000031')).toEqual(['PO-000031', 'Acme Supplies', 'Main warehouse', 'Not ordered', '0.3333 GBP']);
    expect(nav.replace).toHaveBeenLastCalledWith('/purchase-orders?status=cancelled', { scroll: false });
    expect(listRequests(calls)).toEqual([{ status: 'cancelled' }]);
  });

  it('pressing the segment on screen reads nothing and writes nothing', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    renderWired(<PurchaseOrdersList initial={ok([D1])} initialSegment="draft" />);
    await u.click(segmentButton('Draft'));
    await settle();
    expect(calls).toEqual([]);
    expect(nav.replace).not.toHaveBeenCalled();
  });

  // Test 1's mutation target: the switch to Archived keeps no status (the server's 422 would be the only guard).
  it('test 1 — after Next on a segment, a switch to another segment and to Archived carries no cursor; Archived sends no status; Back opens the segment shown before', async () => {
    const calls = stubFetch([json(200, page([O2], { previous_cursor: 'p1', next_cursor: 'n2' })), json(200, page([D1, D2])), json(200, page([X1])), json(200, page([D1, D2]))]);
    const u = userEvent.setup();
    renderWired(<PurchaseOrdersList initial={ok([O1], { nextCursor: 'n1' })} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(screen.getByText('PO-000002')).toBeInTheDocument());
    await u.click(segmentButton('Draft'));
    await waitFor(() => expect(pressed()).toEqual(['Draft']));
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 1, name: 'Archived purchase orders' })).toBeInTheDocument());
    expect(nav.replace).toHaveBeenLastCalledWith('/purchase-orders?archived=true', { scroll: false });
    // The segment control is the live view's: in Archived only Back to purchase orders.
    expect(screen.queryByRole('button', { name: 'Draft' })).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Back to purchase orders' }));
    await waitFor(() => expect(pressed()).toEqual(['Draft']));
    expect(nav.replace).toHaveBeenLastCalledWith('/purchase-orders?status=draft', { scroll: false });
    expect(listRequests(calls)).toEqual([{ status: 'open', after: 'n1' }, { status: 'draft' }, { archived: 'true' }, { status: 'draft' }]);
  });

  it('a switch reads the search in the box with the new segment, and no cursor', async () => {
    const calls = stubFetch([json(200, page([O1])), json(200, page([D1]))]);
    const u = userEvent.setup();
    renderWired(<PurchaseOrdersList initial={ok([O1, O2])} />);
    await u.type(screen.getByRole('searchbox'), 'PO-0');
    await settle();
    await u.click(segmentButton('Draft'));
    await waitFor(() => expect(pressed()).toEqual(['Draft']));
    expect(listRequests(calls)).toEqual([{ status: 'open', q: 'PO-0' }, { status: 'draft', q: 'PO-0' }]);
  });

  // M-5's second fails-if: two segments with equal counts speak different settled sentences.
  it('M-5 — two segments with equal counts never read alike: "3 open …" then "3 draft purchase orders shown."', async () => {
    stubFetch([json(200, page([D1, D2, D3])), json(200, page([O1, O2, O3]))]);
    const u = userEvent.setup();
    renderWired(<PurchaseOrdersList initial={ok([O1, O2, O3])} />);
    const said = textsOf(region());
    await u.click(segmentButton('Draft'));
    await waitFor(() => expect(region()).toHaveTextContent('3 draft purchase orders shown.'));
    await u.click(segmentButton('Open'));
    await waitFor(() => expect(region()).toHaveTextContent('3 open purchase orders shown.'));
    expect(said.texts.filter((t) => t !== '')).toEqual(['3 draft purchase orders shown.', '3 open purchase orders shown.']);
  });

  it('a failed switch to Received keeps Open on screen and pressed, the address alone; its Retry repeats status=received', async () => {
    const calls = stubFetch([json(500, {}), json(200, page([R1]))]);
    const u = userEvent.setup();
    renderWired(<PurchaseOrdersList initial={ok([O1])} />);
    await u.click(segmentButton('Received'));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument());
    expect(pressed()).toEqual(['Open']);
    expect(nav.replace).not.toHaveBeenCalled();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(pressed()).toEqual(['Received']));
    expect(listRequests(calls)).toEqual([{ status: 'received' }, { status: 'received' }]);
  });

  it('a failed first load on the addressed segment: its Retry reads that segment', async () => {
    const calls = stubFetch([json(200, page([R1]))]);
    const u = userEvent.setup();
    renderWired(<PurchaseOrdersList initial={{ kind: 'server-error' }} initialSegment="received" />);
    expect(pressed()).toEqual(['Received']);
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByText('PO-000021')).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ status: 'received' }]);
    expect(nav.replace).not.toHaveBeenCalled();
  });

  it('opened on the addressed segment (a reload): Draft pressed, page 1, an empty box, nothing read at mount', async () => {
    const calls = stubFetch([]);
    renderWired(<PurchaseOrdersList initial={ok([D1])} initialSegment="draft" />);
    expect(pressed()).toEqual(['Draft']);
    expect(headers()).toEqual(['Order', 'Supplier', 'Warehouse', 'Expected', 'Total']);
    expect(screen.getByRole<HTMLInputElement>('searchbox').value).toBe('');
    await settle();
    expect(calls).toEqual([]);
  });

  it('a page read by its cursor speaks the position by order number', async () => {
    stubFetch([json(200, page([O2, O3], { previous_cursor: 'p1' }))]);
    const u = userEvent.setup();
    renderWired(<PurchaseOrdersList initial={ok([O1], { nextCursor: 'n1' })} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(region()).toHaveTextContent('2 purchase orders shown, PO-000002 to PO-000003.'));
  });

  // Test 4.
  it('test 4 — search sends q as typed (no client folding) with the segment; the rows shown are the rows served', async () => {
    const calls = stubFetch([json(200, page([O3]))]);
    const u = userEvent.setup();
    renderWired(<PurchaseOrdersList initial={ok([O1, O2])} />);
    await u.type(screen.getByRole('searchbox'), ' po-00');
    await settle();
    await waitFor(() => expect(screen.getByText('PO-000003')).toBeInTheDocument());
    expect(screen.queryByText('PO-000001')).toBeNull();
    expect(listRequests(calls)).toEqual([{ status: 'open', q: ' po-00' }]);
    expect(region()).toHaveTextContent('1 open purchase order shown. Order number starts with “ po-00”.');
  });
});

describe('/purchase-orders — the Archived view (L-4; R1-B)', () => {
  // Test 17 (final RED L-4: UX's three columns).
  it('test 17 — exactly Order · Supplier · Deleted on; rows struck; captioned Most recently deleted first', () => {
    renderWired(<PurchaseOrdersList initial={ok([X1])} initialArchived />);
    expect(screen.getByRole('heading', { level: 1, name: 'Archived purchase orders' })).toBeInTheDocument();
    expect(headers()).toEqual(['Order', 'Supplier', 'Deleted on']);
    expect(document.querySelector('caption')?.textContent).toBe('Most recently deleted first');
    expect(document.querySelector('tbody tr')!.className).toBe(styles.struck);
    expect(screen.getByRole('searchbox', { name: 'Search archived orders by order number' })).toBeInTheDocument();
    expect(screen.queryByText('Ordered longest ago first')).toBeNull();
  });

  it('the Archived view speaks no segment and reads none', async () => {
    const calls = stubFetch([json(200, page([X1]))]);
    const u = userEvent.setup();
    renderWired(<PurchaseOrdersList initial={ok([O1])} />);
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(region()).toHaveTextContent('1 archived purchase order shown.'));
    expect(listRequests(calls)).toEqual([{ archived: 'true' }]);
  });
});

describe('/purchase-orders — ST-9a empty states (test 5) and the PO-1 copy forms (test 15)', () => {
  it.each([
    ['open', 'No open purchase orders. Open orders are approved orders that haven\'t been received in full or cancelled.'],
    ['draft', 'No drafts.'],
    ['received', 'No orders have been received in full yet.'],
    ['cancelled', 'No cancelled orders.'],
  ] as const)('test 5 — %s, no search, orders elsewhere (tenant_has_rows true): "%s"', (segment, sentence) => {
    renderWired(<PurchaseOrdersList initial={ok([], { tenantHasRows: true })} initialSegment={segment} />);
    expect(screen.getByText(sentence, { selector: 'p' })).toBeInTheDocument();
  });

  it.each(PURCHASE_ORDER_SEGMENTS)('test 5 — %s with no order at all (tenant_has_rows false): "No purchase orders yet."', (segment) => {
    renderWired(<PurchaseOrdersList initial={ok([], { tenantHasRows: false })} initialSegment={segment} />);
    expect(screen.getByText('No purchase orders yet.', { selector: 'p' })).toBeInTheDocument();
  });

  // Moved at PO-2 (R1a-1 row 2): Restore ships, so the sentence says the archived drafts can be restored from here.
  it('test 5 — Archived, empty: "No archived purchase orders. Archived drafts can be restored from here." whatever tenant_has_rows', () => {
    for (const tenantHasRows of [true, false]) {
      const { unmount } = renderWired(<PurchaseOrdersList initial={ok([], { tenantHasRows })} initialArchived />);
      expect(screen.getByText('No archived purchase orders. Archived drafts can be restored from here.', { selector: 'p' })).toBeInTheDocument();
      unmount();
    }
  });

  it('test 5 — a search with no match, live and archived, names the search and offers Clear search', async () => {
    stubFetch([json(200, page([], { tenant_has_rows: true }))]);
    const u = userEvent.setup();
    renderWired(<PurchaseOrdersList initial={ok([O1])} />);
    await u.type(screen.getByRole('searchbox'), 'PO-9');
    await settle();
    await waitFor(() => expect(screen.getByText('No open order\'s number starts with “PO-9”.', { selector: 'p' })).toBeInTheDocument());
    expect(screen.getByRole('button', { name: 'Clear search' })).toBeInTheDocument();
  });

  // po-1-red.md M-2: the search is sent with the segment, so the empty sentence names it (and is what is spoken).
  it('M-2 — a search with no match names its segment: Draft and Open read differently, on screen and in the region', async () => {
    stubFetch([json(200, page([], { tenant_has_rows: true })), json(200, page([], { tenant_has_rows: true }))]);
    const u = userEvent.setup();
    renderWired(<PurchaseOrdersList initial={ok([D1])} initialSegment="draft" />);
    await u.type(screen.getByRole('searchbox'), 'PO-9');
    await settle();
    const draft = "No draft order's number starts with “PO-9”.";
    await waitFor(() => expect(screen.getByText(draft, { selector: 'p' })).toBeInTheDocument());
    expect(region()).toHaveTextContent(draft);
    await u.click(segmentButton('Open'));
    const open = "No open order's number starts with “PO-9”.";
    await waitFor(() => expect(screen.getByText(open, { selector: 'p' })).toBeInTheDocument());
    expect(region()).toHaveTextContent(open);
    expect(draft).not.toBe(open);
  });

  // po-1-red.md L-3: the segment buttons are one named group, and Archived is outside it.
  it('L-3 — the segment control is a group named "Status" holding the four segments, Archived outside it', () => {
    renderWired(<PurchaseOrdersList initial={ok([O1])} />);
    const group = screen.getByRole('group', { name: 'Status' });
    expect(within(group).getAllByRole('button').map((b) => b.textContent)).toEqual(['Open', 'Draft', 'Received', 'Cancelled']);
    expect(within(group).queryByRole('button', { name: 'Archived' })).toBeNull();
  });

  it('test 5 — an archived search with no match', async () => {
    stubFetch([json(200, page([], { tenant_has_rows: true }))]);
    const u = userEvent.setup();
    renderWired(<PurchaseOrdersList initial={ok([X1])} initialArchived />);
    await u.type(screen.getByRole('searchbox'), 'PO-9');
    await settle();
    await waitFor(() => expect(screen.getByText('No archived order\'s number starts with “PO-9”.', { selector: 'p' })).toBeInTheDocument());
  });

  // Test 15 (R1-2 / UX R1a-1 rows 1–2): the shortened forms render. Moved at PO-2: noneArchived names restoring (shipped
  // here); still no sentence names creating, approving or deleting, and noneArchived says nothing of deleting (PO-6).
  it('test 15 — the PO-2 forms: noneYet and emptyDraft name no action; noneArchived names only restoring', () => {
    const shown: string[] = [];
    for (const [initial, segment, archived] of [
      [ok([], { tenantHasRows: false }), 'open', false],
      [ok([], { tenantHasRows: true }), 'draft', false],
      [ok([], { tenantHasRows: true }), 'open', true],
    ] as const) {
      const { container, unmount } = renderWired(<PurchaseOrdersList initial={initial} initialSegment={segment} initialArchived={archived} />);
      shown.push(container.textContent ?? '');
      unmount();
    }
    const all = shown.join(' ');
    expect(all).toContain('No purchase orders yet.');
    expect(all).toContain('No drafts.');
    expect(all).toContain('No archived purchase orders. Archived drafts can be restored from here.');
    for (const later of ['when someone creates it', 'starts as a draft', "until it's approved", 'deleted']) expect(all).not.toContain(later);
  });
});
