import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), refresh: vi.fn() }), usePathname: () => '/purchase-orders/x' }));
import { PurchaseOrderPage, type PurchaseOrderPageProps } from '@/app/(app)/purchase-orders/[id]/PurchaseOrderPage';
import type { PurchaseOrder, PurchaseOrderLine } from '@/lib/api/purchasing';
import type { LinesLoad } from '@/lib/purchasing/load';
import { json, page, problem, renderWired, stubFetch, VIEWER_ID } from './harness';

// Purchase orders PO-1, the read-only order page (purchase-orders-plan.md Revision 0 §2.2, §4 PO-1 tests 7, 9, 10, 11,
// 14; R1-5 16; UX B-3 / B-4 / R1-B, R1a-1 row 1 forms; final RED L-2, L-11). The page is the real `PurchaseOrderPage`
// and `LinesSection`; the lines Retry goes through the REAL `lib/purchasing/load.ts` and the browser transport.

afterEach(() => vi.unstubAllGlobals());

const CREATOR = 'e1b2c3d4-0000-4000-8000-000000000001';
const order = (status: string, over: Partial<PurchaseOrder> = {}): PurchaseOrder => ({
  id: 'd1b2c3d4-0000-4000-8000-000000000001',
  order_number: 'PO-000001',
  status,
  supplier: { id: 'c1b2c3d4-0000-4000-8000-0000000000a1', name: 'Acme Supplies' },
  warehouse: { id: 'c1b2c3d4-0000-4000-8000-0000000000b1', name: 'Main warehouse' },
  currency_code: 'GBP',
  total_amount: '1250.5000',
  ordered_at: status === 'draft' ? null : '2026-03-04T11:00:00Z',
  expected_at: null,
  created_by: { id: CREATOR, display_name: 'Bea Buyer' },
  archived_at: null,
  ...over,
});
const line = (n: number, over: Partial<PurchaseOrderLine> = {}): PurchaseOrderLine => ({
  id: `f1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`,
  line_number: n,
  inventory_item: { id: `a1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`, sku: `SKU-${n}`, name: `Item ${n}` },
  quantity: '10.000000',
  quantity_received: '2.500000',
  quantity_outstanding: '7.500000',
  unit_cost: '12.5000',
  archived_at: null,
  unit_of_measure_code: 'EA',
  ...over,
});
const lines = (ls: PurchaseOrderLine[], nextCursor: string | null = null): LinesLoad => ({ kind: 'ok', lines: ls, nextCursor });
const show = (over: Partial<PurchaseOrderPageProps> = {}) =>
  renderWired(<PurchaseOrderPage order={order('ordered')} lines={lines([line(1), line(2)])} viewerId={VIEWER_ID} canRestore={false} canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} {...over} />);
const headers = () => screen.getAllByRole('columnheader').map((h) => h.textContent);
const rowCells = (i: number) => [...document.querySelectorAll('tbody tr')][i]!.querySelectorAll('td');
const cellsOf = (i: number) => [...rowCells(i)].map((c) => c.textContent);
const fact = (label: string) => {
  const dt = [...document.querySelectorAll('dt')].find((d) => d.textContent === label);
  return dt ? (dt.nextElementSibling as HTMLElement).textContent : null;
};
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;

describe('/purchase-orders/[id] — the header (UX B-3)', () => {
  it('title "{number} — {supplier}", the status badge, and the facts; Total in D1’s form (1250.50 GBP)', () => {
    show();
    expect(screen.getByRole('heading', { level: 1, name: 'PO-000001 — Acme Supplies' })).toBeInTheDocument();
    expect(screen.getByText('Ordered', { selector: 'span' })).toBeInTheDocument();
    expect(fact('Supplier')).toBe('Acme Supplies');
    expect(fact('Deliver to')).toBe('Main warehouse');
    expect(fact('Currency')).toBe('GBP');
    expect(fact('Expected delivery')).toBe('Not set');
    expect(fact('Ordered on')).not.toBeNull();
    expect(fact('Raised by')).toBe('Bea Buyer');
    expect(fact('Total')).toBe('1250.50 GBP');
  });

  it('a draft has no Ordered on; a value finer than the minor unit is shown as stored', () => {
    show({ order: order('draft', { total_amount: '0.3333' }), lines: lines([]) });
    expect(fact('Ordered on')).toBeNull();
    expect(fact('Total')).toBe('0.3333 GBP');
  });

  // Test 9.
  it('test 9 — supplier and warehouse are links only with GetSupplier / GetWarehouse; the item only with GetItem', () => {
    const { unmount } = show();
    expect(screen.queryByRole('link')).toBeNull();
    unmount();
    show({ canOpenSupplier: true, canOpenWarehouse: true, canOpenItem: true });
    expect(screen.getByRole('link', { name: 'Acme Supplies' })).toHaveAttribute('href', '/suppliers/c1b2c3d4-0000-4000-8000-0000000000a1');
    expect(screen.getByRole('link', { name: 'Main warehouse' })).toHaveAttribute('href', '/warehouses/c1b2c3d4-0000-4000-8000-0000000000b1');
    expect(screen.getByRole('link', { name: 'SKU-1 — Item 1' })).toHaveAttribute('href', '/items/a1b2c3d4-0000-4000-8000-000000000001');
  });

  // Test 10.
  it('test 10 — Raised by: the display name, or "Not recorded" when created_by is null', () => {
    show({ order: order('ordered', { created_by: null }) });
    expect(fact('Raised by')).toBe('Not recorded');
    expect(document.body.textContent).not.toContain('null');
  });

  // Test 16 (final RED L-2: the viewer's id from currentViewer(), on the server).
  it('test 16 — "(you)" only when created_by.id is the viewer’s id, and nothing else on the page changes with it', () => {
    const other = show();
    const asOther = other.container.textContent!;
    expect(fact('Raised by')).toBe('Bea Buyer');
    other.unmount();
    const mine = show({ viewerId: CREATOR });
    expect(fact('Raised by')).toBe('Bea Buyer (you)');
    expect(mine.container.textContent!.replace(' (you)', '')).toBe(asOther);
    expect(mine.container.querySelectorAll('button').length).toBe(0);
  });

  // Moved at PO-4a (R1a-1 row 4): the draft note names changing, which ships there (Save). Moved again at PO-6 (row 6): it
  // names deleting too (Delete purchase order ships there). Moved at PO-7 (row 7): `orderedNote` in its final form names
  // cancelling (Cancel order ships there). Moved at PO-8 (row 9): the draft note names approving (Approve ships there),
  // without its owner sentence, which SEC has not confirmed (final RED L-6); no note names receiving (GR-2).
  it('the status notes, in their PO-8 forms (R1a-1 rows 1, 7 and 9): none names an action the app lacks', () => {
    const notes: Record<string, string[]> = {
      draft: ["This is a draft. It can be changed or deleted until it's approved."],
      ordered: ["This order has been approved, so it can't be changed. It can still be cancelled.", "The app doesn't send purchase orders to suppliers."],
      partially_received: ["This order has been approved, so it can't be changed. It can still be cancelled.", "The app doesn't send purchase orders to suppliers."],
      received: ['This order is closed: it was received in full.'],
      cancelled: ['This order is closed: it was cancelled.'],
    };
    for (const [status, expected] of Object.entries(notes)) {
      const { container, unmount } = show({ order: order(status), lines: lines([line(1)]) });
      for (const t of expected) expect(screen.getByText(t, { selector: 'p' })).toBeInTheDocument();
      for (const later of ['approved by someone other', 'received against']) expect(container.textContent).not.toContain(later);
      unmount();
    }
  });

  // PO-2 adds Restore purchase order on a deleted draft only (purchase-order-restore.test.tsx): a live order still has none.
  it('a live order has no action: no button on any status, even for a RestorePurchaseOrder holder', () => {
    for (const status of ['draft', 'ordered', 'partially_received', 'received', 'cancelled']) {
      const { container, unmount } = show({ order: order(status), lines: lines([line(1)]), canRestore: true });
      expect(container.querySelectorAll('button')).toHaveLength(0);
      unmount();
    }
  });

  // Moved at PO-2: Restore purchase order is offered for its holder (purchase-order-restore.test.tsx); without it, none.
  it('a deleted draft: the ST-9 banner "This draft was deleted on {date}.", no Restore without RestorePurchaseOrder, no note and no lines section', () => {
    show({ order: order('draft', { archived_at: '2026-03-04T11:00:00Z' }), lines: null });
    expect(document.body.textContent).toMatch(/This draft was deleted on .+\./);
    expect(screen.queryByRole('button')).toBeNull();
    expect(screen.queryByText(/This is a draft\./)).toBeNull();
    expect(screen.queryByRole('heading', { level: 2, name: 'Lines' })).toBeNull();
    expect(screen.queryByText('This draft has no lines yet.')).toBeNull();
  });

  // PO-2 (po-2-progress.md D-1): the page's state holds "no lines on a deleted draft", whatever it is given.
  it('a deleted draft given lines still shows no lines section', () => {
    show({ order: order('draft', { archived_at: '2026-03-04T11:00:00Z' }), lines: lines([line(1)]) });
    expect(screen.queryByRole('heading', { level: 2, name: 'Lines' })).toBeNull();
    expect(screen.queryByRole('table')).toBeNull();
  });
});

describe('/purchase-orders/[id] — the lines (UX B-4)', () => {
  // Test 7 + the interim unit headings (QR-8, L-11) + the line money.
  it('test 7 — an ordered order: Line · Item · Ordered · Received · Still to come (item’s unit) · Unit cost (GBP); 10.000000 → 10, 2.500000 → 2.5; 12.5000 → 12.50 GBP', () => {
    show();
    expect(screen.getByRole('heading', { level: 2, name: 'Lines' })).toBeInTheDocument();
    expect(headers()).toEqual(['Line', 'Item', "Ordered (item's unit)", "Received (item's unit)", "Still to come (item's unit)", 'Unit cost (GBP)']);
    expect(cellsOf(0)).toEqual(['1', 'SKU-1 — Item 1', '10', '2.5', '7.5', '12.50 GBP']);
    expect(cellsOf(1)[0]).toBe('2');
  });

  it('a draft: Line · Item · Quantity (item’s unit) · Unit cost; the total note under the table; no Actions column', () => {
    show({ order: order('draft'), lines: lines([line(1, { quantity: '1234567890123.123456', unit_cost: '0.1250' })]) });
    expect(headers()).toEqual(['Line', 'Item', "Quantity (item's unit)", 'Unit cost (GBP)']);
    expect(cellsOf(0)).toEqual(['1', 'SKU-1 — Item 1', '1234567890123.123456', '0.1250 GBP']);
    expect(screen.getByText('The total is worked out from the lines.', { selector: 'p' })).toBeInTheDocument();
  });

  // Test 11 (D-3: relabel, never recompute).
  it('test 11 — a cancelled order heads the served remainder "Never received", with the same served number', () => {
    show({ order: order('cancelled'), lines: lines([line(1)]) });
    expect(headers()).toEqual(['Line', 'Item', "Ordered (item's unit)", "Received (item's unit)", "Never received (item's unit)", 'Unit cost (GBP)']);
    expect(headers()).not.toContain("Still to come (item's unit)");
    expect(cellsOf(0)[4]).toBe('7.5');
  });

  it('the unit cost is in the order’s currency: JPY 250.0000 → 250 JPY', () => {
    show({ order: order('ordered', { currency_code: 'JPY', total_amount: '2500.0000' }), lines: lines([line(1, { unit_cost: '250.0000' })]) });
    expect(headers()).toContain('Unit cost (JPY)');
    expect(cellsOf(0)[5]).toBe('250 JPY');
    expect(fact('Total')).toBe('2500 JPY');
  });

  it('a draft with no line: "This draft has no lines yet."; a partial answer says so above the rows', () => {
    const { unmount } = show({ order: order('draft'), lines: lines([]) });
    expect(screen.getByText('This draft has no lines yet.', { selector: 'p' })).toBeInTheDocument();
    expect(screen.queryByRole('table')).toBeNull();
    unmount();
    show({ lines: lines([line(1)], 'c1') });
    expect(screen.getByText('Only the first 1 lines of this order are shown.', { selector: 'p' })).toBeInTheDocument();
  });

  it('a failed first read: the section’s region error; Retry reads the order’s lines, focuses the heading, then speaks the count', async () => {
    const calls = stubFetch([json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    show({ lines: { kind: 'server-error' } });
    expect(screen.getByText("This order's lines couldn't be loaded.")).toBeInTheDocument();
    expect(region()).toHaveTextContent('');
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(region()).toHaveTextContent('2 lines.'));
    expect(document.activeElement).toBe(screen.getByRole('heading', { level: 2, name: 'Lines' }));
    expect(calls.map((c) => c.url)).toEqual(['/bff/api/purchase-orders/d1b2c3d4-0000-4000-8000-000000000001/lines']);
    expect(within(screen.getByRole('table')).getAllByRole('row')).toHaveLength(3);
  });

  it('a Retry that finds an empty draft speaks its empty sentence; a 403 shows the lost-permission sentence', async () => {
    stubFetch([json(200, page([]))]);
    const u = userEvent.setup();
    const { unmount } = show({ order: order('draft'), lines: { kind: 'unreachable' } });
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(region()).toHaveTextContent('This draft has no lines yet.'));
    unmount();
    vi.unstubAllGlobals();
    stubFetch([problem(403, 'permission-denied')]);
    show({ lines: { kind: 'server-error' } });
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByText('This needs the View purchase orders, including what goods cost permission, which you no longer have. Your workspace administrator can grant it.')).toBeInTheDocument());
    // po-1-red.md L-6: the Retry button is gone from under focus; the section heading takes it.
    expect(document.activeElement).toBe(screen.getByRole('heading', { level: 2, name: 'Lines' }));
  });
});
