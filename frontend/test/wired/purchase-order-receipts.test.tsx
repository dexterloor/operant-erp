import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ replace: vi.fn(), refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: nav.replace, refresh: nav.refresh }), usePathname: () => '/purchase-orders/x' }));
import { PurchaseOrderPage, type PurchaseOrderPageProps } from '@/app/(app)/purchase-orders/[id]/PurchaseOrderPage';
import type { GoodsReceipt, PurchaseOrder } from '@/lib/api/purchasing';
import type { ReceiptsLoad } from '@/lib/purchasing/load';
import { json, page, problem, renderWired, stubFetch, VIEWER_ID, type Recorded } from './harness';

// Goods receipts GR-1, the order page's Receipts section (purchase-orders-plan.md §2.2 "Data", §2.7, R1-5 GR-1;
// b4-architecture.md Revision 10 R10-4: one fixed filter, `purchase_order_id`, on every request of that list, never in the
// address; R10-3: no Archived view; A-20: the section is absent — not fetched — without ListGoodsReceipts' code; UX B-9
// `forOrder(number)` / `forOrderEmpty`). The page is the real `PurchaseOrderPage`; the section's reads go through the REAL
// `lib/purchasing/load.ts` and the browser transport.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.replace.mockReset();
});

const ORDER_ID = 'd1b2c3d4-0000-4000-8000-000000000001';
const order = (status: string, over: Partial<PurchaseOrder> = {}): PurchaseOrder => ({
  id: ORDER_ID,
  order_number: 'PO-000001',
  status,
  supplier: { id: 'c1b2c3d4-0000-4000-8000-0000000000a1', name: 'Acme Supplies' },
  warehouse: { id: 'c1b2c3d4-0000-4000-8000-0000000000b1', name: 'Main warehouse' },
  currency_code: 'GBP',
  total_amount: '1250.5000',
  ordered_at: status === 'draft' ? null : '2026-03-04T11:00:00Z',
  expected_at: null,
  created_by: null,
  archived_at: null,
  ...over,
});
const receipt = (n: number, over: Partial<GoodsReceipt> = {}): GoodsReceipt => ({
  id: `e1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`,
  receipt_number: `GR-${String(n).padStart(6, '0')}`,
  received_at: '2026-03-04T11:00:00Z',
  purchase_order: { id: ORDER_ID, order_number: 'PO-000001' },
  received_by: { id: 'f1b2c3d4-0000-4000-8000-000000000001', display_name: 'Wanda Warehouse' },
  lines: [{ id: `a1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`, purchase_order_line: { id: 'b1b2c3d4-0000-4000-8000-000000000001', line_number: 1 }, inventory_item: { id: 'c1b2c3d4-0000-4000-8000-000000000001', sku: 'SKU-1', name: 'Widget' }, quantity: '2.000000', storage_location: { id: 'c9b2c3d4-0000-4000-8000-000000000001', code: 'A-01' } }],
  ...over,
});
const ok = (items: GoodsReceipt[], over: Partial<Extract<ReceiptsLoad, { kind: 'ok' }>> = {}): ReceiptsLoad => ({ kind: 'ok', items, nextCursor: null, previousCursor: null, tenantHasRows: items.length > 0, ...over });
const show = (over: Partial<PurchaseOrderPageProps> = {}) =>
  renderWired(<PurchaseOrderPage order={order('partially_received')} lines={null} viewerId={VIEWER_ID} canRestore={false} canReadLines={false} canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} {...over} />);
const section = () => screen.getByRole('heading', { name: 'Receipts for PO-000001' }).closest('section')!;

/** Every request the section sent: each carries the order's id as `purchase_order_id`, and never `archived` (R10-3, R10-4). */
function sectionRequests(calls: readonly Recorded[]) {
  const reqs = calls.filter((c) => c.method === 'GET').map((c) => new URL(c.url, 'http://localhost'));
  expect(reqs.length).toBeGreaterThan(0);
  for (const u of reqs) {
    expect(u.pathname).toBe('/bff/api/goods-receipts');
    expect(u.searchParams.get('purchase_order_id'), `R10-4: every request of the section carries the fixed filter (${u.search})`).toBe(ORDER_ID);
    expect(u.searchParams.has('archived'), `R10-3: never archived (${u.search})`).toBe(false);
    expect([...u.searchParams.keys()].every((k) => ['purchase_order_id', 'after', 'before'].includes(k)), `only the filter and one cursor (${u.search})`).toBe(true);
  }
  return reqs.map((u) => Object.fromEntries(u.searchParams));
}

describe('/purchase-orders/[id] — the Receipts section (GR-1)', () => {
  it('under "Receipts for {number}": Receipt · Received · Received by · Lines; the receipt links to its page for a GetGoodsReceipt holder', () => {
    show({ receipts: ok([receipt(2), receipt(1, { received_by: null })]), canOpenReceipt: true });
    const s = section();
    expect(within(s).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Receipt', 'Received', 'Received by', 'Lines']);
    expect(within(s).getByRole('link', { name: 'GR-000002' })).toHaveAttribute('href', '/goods-receipts/e1b2c3d4-0000-4000-8000-000000000002');
    expect(within(s).getByText('Not recorded')).toBeInTheDocument();
    // No search, no Archived, no view control (R10-3; the section is one order's receipts).
    expect(within(s).queryByRole('searchbox')).toBeNull();
    expect(within(s).queryByRole('button', { name: /archived/i })).toBeNull();
    expect(s.querySelector('[aria-pressed]')).toBeNull();
  });

  it('without GetGoodsReceipt the receipt number is text, not a link', () => {
    show({ receipts: ok([receipt(1)]), canOpenReceipt: false });
    expect(within(section()).queryAllByRole('link')).toEqual([]);
    expect(within(section()).getByText('GR-000001')).toBeInTheDocument();
  });

  it('an order with no receipt reads "Nothing has been received on this order yet."', () => {
    show({ receipts: ok([], { tenantHasRows: true }), canOpenReceipt: true });
    expect(within(section()).getByText('Nothing has been received on this order yet.')).toBeInTheDocument();
  });

  // R10-4's fails-if: a request of that list lacks the filter. Every request the section sends, a page press and a Retry
  // included, carries `purchase_order_id`; none carries `archived`; the address is never written.
  it('R10-4 — Older, Newer and a banner Retry each carry purchase_order_id; the address is never written', async () => {
    const calls = stubFetch([json(200, page([receipt(1)], { previous_cursor: 'p1' })), json(500, {}), json(200, page([receipt(3), receipt(2)], { next_cursor: 'n1' }))]);
    const u = userEvent.setup();
    show({ receipts: ok([receipt(3), receipt(2)], { nextCursor: 'n1' }), canOpenReceipt: true });
    await u.click(within(section()).getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(within(section()).getByText('GR-000001')).toBeInTheDocument());
    await u.click(within(section()).getByRole('button', { name: 'Newer' }));
    await waitFor(() => expect(within(section()).getByRole('button', { name: 'Retry' })).toBeInTheDocument());
    await u.click(within(section()).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(within(section()).getByText('GR-000003')).toBeInTheDocument());
    expect(sectionRequests(calls)).toEqual([
      { purchase_order_id: ORDER_ID, after: 'n1' },
      { purchase_order_id: ORDER_ID, before: 'p1' },
      { purchase_order_id: ORDER_ID, before: 'p1' },
    ]);
    expect(nav.replace).not.toHaveBeenCalled();
  });

  it('a failed first read is the section’s own region error; its Retry reads with the filter', async () => {
    const calls = stubFetch([json(200, page([receipt(1)]))]);
    const u = userEvent.setup();
    show({ receipts: { kind: 'server-error' }, canOpenReceipt: true });
    expect(screen.getByText("The list of goods receipts couldn't be loaded.")).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByText('GR-000001')).toBeInTheDocument());
    expect(sectionRequests(calls)).toEqual([{ purchase_order_id: ORDER_ID }]);
  });

  it('a 403 in the section is the lost-permission sentence naming View goods receipts', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show({ receipts: ok([receipt(2)], { nextCursor: 'n1' }), canOpenReceipt: true });
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(screen.getByText('View goods receipts')).toBeInTheDocument());
    expect(screen.getByText(/permission, which you no longer have/)).toBeInTheDocument();
  });

  // A-20: absent, not fetched, without the code (the server gives no `receipts`).
  it('A-20 — without receipts (no ListGoodsReceipts code) the section is absent and nothing is fetched', () => {
    const calls = stubFetch([]);
    show({ receipts: null });
    expect(screen.queryByRole('heading', { name: /Receipts for/ })).toBeNull();
    expect(calls).toEqual([]);
  });

  it('a deleted draft shows no Receipts section', () => {
    show({ order: order('draft', { archived_at: '2026-03-04T11:00:00Z' }), receipts: ok([]), canOpenReceipt: true });
    expect(screen.queryByRole('heading', { name: /Receipts for/ })).toBeNull();
  });

  it('no cost is shown in the section', () => {
    show({ receipts: ok([receipt(1)]), canOpenReceipt: true });
    expect(section().textContent).not.toMatch(/GBP|cost/i);
  });
});
