import { screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), push: vi.fn(), refresh: vi.fn() }), usePathname: () => '/stock-movements/x' }));
import { StockMovementPage, type StockMovementPageProps } from '@/app/(app)/stock-movements/[id]/StockMovementPage';
import { movementColumns } from '@/app/(app)/stock-movements/StockMovementsList';
import type { StockMovement } from '@/lib/api/stock';
import { formatDateTime } from '@/lib/format/dates';
import { sourceOf } from '@/lib/stock/source';
import { renderWired, TZ } from './harness';

// Stock ST-1 (stock-plan.md §2.2, §6.5; UX B-2 detail column, B-6, S-13; stock-st1-red.md L-10, L-11, L-12;
// b4-architecture.md Revision 11 R11-3, R11-4, R11-5): the read-only movement page, from the server page's props.

const RECEIPT_ID = 'e1b2c3d4-0000-4000-8000-000000000003';
const PARTNER_ID = 'f1b2c3d4-0000-4000-8000-000000000004';
const ITEM = { id: 'c1b2c3d4-0000-4000-8000-000000000001', sku: 'WID-001', name: 'Widget' };
const RECEIVED: StockMovement = {
  id: 'a1b2c3d4-0000-4000-8000-000000000001',
  quantity: '12.000000',
  occurred_at: '2026-03-04T11:00:00Z',
  reason: 'receipt',
  source_type: 'goods_receipt',
  source_id: RECEIPT_ID,
  inventory_item: ITEM,
  storage_location: { id: 'c9b2c3d4-0000-4000-8000-000000000001', code: 'A-01' },
};
const ALL = { goodsReceipt: true, movement: true };
const props = (over: Partial<StockMovementPageProps> = {}): StockMovementPageProps => ({ movement: RECEIVED, source: sourceOf(RECEIVED, ALL), receiptNumber: 'GR-000123', partnerLocation: null, canOpenItem: true, canListMovements: true, ...over });
const show = (over: Partial<StockMovementPageProps> = {}) => renderWired(<StockMovementPage {...props(over)} />);
const ddAfter = (label: string) => screen.getByText(label, { selector: 'dt' }).nextElementSibling as HTMLElement;
const LOCATION_HINT = "Shown by its code only. The warehouse it's in, and whether it's a quarantine location, aren't shown here.";

describe('/stock-movements/[id] — the page (UX B-6; 6.5-1, 6.5-3)', () => {
  it('h1 "{label} — {sku}"; the six facts in order; the location hint; the read-only note; no button, no input, no money', () => {
    show();
    expect(screen.getByRole('heading', { level: 1, name: 'Received — WID-001' })).toBeInTheDocument();
    expect([...document.querySelectorAll('dt')].map((d) => d.textContent)).toEqual(['Date', 'What happened', 'Item', 'Location', "Change (in the item's unit)", 'Source']);
    expect(ddAfter('Date')).toHaveTextContent(formatDateTime(RECEIVED.occurred_at, TZ));
    expect(ddAfter('What happened')).toHaveTextContent(/^Received$/);
    expect(ddAfter('Location').firstChild?.textContent).toBe('A-01');
    expect(within(ddAfter('Location')).getByText(LOCATION_HINT)).toBeInTheDocument();
    expect(screen.getByText("A stock movement can't be changed or deleted.")).toBeInTheDocument();
    expect(screen.queryAllByRole('button')).toEqual([]);
    expect(document.querySelectorAll('input, select, textarea, form')).toHaveLength(0);
    expect(document.body.textContent).not.toMatch(/GBP|EUR|USD|£|€|\$/);
    // No Unit field until BK-41 (D-1).
    expect(screen.queryByText('Unit', { selector: 'dt' })).toBeNull();
  });

  it('the item links to its page only for a GetItem holder', () => {
    const { unmount } = show();
    expect(within(ddAfter('Item')).getByRole('link', { name: 'WID-001 — Widget' })).toHaveAttribute('href', `/items/${ITEM.id}`);
    unmount();
    show({ canOpenItem: false });
    expect(within(ddAfter('Item')).queryByRole('link')).toBeNull();
    expect(ddAfter('Item')).toHaveTextContent(/^WID-001 — Widget$/);
  });

  it('6.5-3 (RED L-11): All movements of {sku} opens exactly /stock-movements?inventory_item_id=<item id>, only for a ListStockMovements holder; Back to stock movements always', () => {
    const { unmount } = show();
    expect(screen.getByRole('link', { name: 'All movements of WID-001' })).toHaveAttribute('href', `/stock-movements?inventory_item_id=${ITEM.id}`);
    expect(screen.getByRole('link', { name: 'Back to stock movements' })).toHaveAttribute('href', '/stock-movements');
    unmount();
    show({ canListMovements: false });
    expect(screen.queryByRole('link', { name: 'All movements of WID-001' })).toBeNull();
    expect(screen.getByRole('link', { name: 'Back to stock movements' })).toBeInTheDocument();
  });
});

describe('/stock-movements/[id] — the source (UX B-2 detail column, S-13; 6.5-2)', () => {
  it('a receipt with its number read: "Goods receipt {number}", linked to the receipt', () => {
    show();
    expect(within(ddAfter('Source')).getByRole('link', { name: 'Goods receipt GR-000123' })).toHaveAttribute('href', `/goods-receipts/${RECEIPT_ID}`);
  });

  it('a receipt whose number was not read (failed, fail-soft): the plain word, still linked; without the gate: plain text, no number', () => {
    const { unmount } = show({ receiptNumber: null });
    expect(within(ddAfter('Source')).getByRole('link', { name: 'Goods receipt' })).toHaveAttribute('href', `/goods-receipts/${RECEIPT_ID}`);
    unmount();
    show({ source: sourceOf(RECEIVED, { goodsReceipt: false, movement: true }), receiptNumber: null });
    expect(within(ddAfter('Source')).queryByRole('link')).toBeNull();
    expect(ddAfter('Source')).toHaveTextContent(/^Goods receipt$/);
    expect(document.body.textContent).not.toContain(RECEIPT_ID);
  });

  it('a transfer leg with its partner read: Moved to / Moved from {bin}, linked to the partner, with the location hint under it (RED L-10)', () => {
    const out: StockMovement = { ...RECEIVED, reason: 'transfer_out', quantity: '-3.000000', source_type: 'stock_movement', source_id: PARTNER_ID };
    const { unmount } = show({ movement: out, source: sourceOf(out, ALL), receiptNumber: null, partnerLocation: 'B-02' });
    expect(screen.getByRole('heading', { level: 1, name: 'Moved out — WID-001' })).toBeInTheDocument();
    expect(within(ddAfter('Source')).getByRole('link', { name: 'Moved to B-02' })).toHaveAttribute('href', `/stock-movements/${PARTNER_ID}`);
    expect(within(ddAfter('Source')).getByText(LOCATION_HINT)).toBeInTheDocument();
    unmount();
    const into: StockMovement = { ...out, reason: 'transfer_in', quantity: '3.000000' };
    const second = show({ movement: into, source: sourceOf(into, ALL), receiptNumber: null, partnerLocation: 'B-02' });
    expect(within(ddAfter('Source')).getByRole('link', { name: 'Moved from B-02' })).toBeInTheDocument();
    second.unmount();
    // The partner not read: the partner word, still linked, and no hint under Source.
    show({ movement: out, source: sourceOf(out, ALL), receiptNumber: null, partnerLocation: null });
    expect(within(ddAfter('Source')).getByRole('link', { name: 'Matching move in' })).toBeInTheDocument();
    expect(within(ddAfter('Source')).queryByText(LOCATION_HINT)).toBeNull();
  });

  it('a shipment, a customer return, a count and an unknown kind are plain words', () => {
    for (const [source_type, reason, word] of [['fulfilment', 'fulfilment', 'Fulfilment'], ['sales_return', 'return', 'Customer return'], [null, 'adjustment', 'None'], ['zzz', 'receipt', 'Other']] as const) {
      const m: StockMovement = { ...RECEIVED, source_type, reason, source_id: source_type === null ? null : RECEIPT_ID };
      const { unmount } = show({ movement: m, source: sourceOf(m, ALL), receiptNumber: null });
      expect(within(ddAfter('Source')).queryByRole('link')).toBeNull();
      expect(ddAfter('Source')).toHaveTextContent(new RegExp(`^${word}$`));
      unmount();
    }
  });
});

describe('/stock-movements/[id] — the change (QR-1; 6.5-4)', () => {
  it('the Change field is the same signed string as the list cell for the same movement', () => {
    for (const quantity of ['12.000000', '-2.500000', '-0.000001']) {
      const m = { ...RECEIVED, quantity };
      const cell = movementColumns({ timeZone: TZ, gates: ALL, canOpenItem: true })[4]!.cell(m);
      const { unmount } = show({ movement: m });
      expect(ddAfter("Change (in the item's unit)").textContent).toBe(cell);
      expect(ddAfter("Change (in the item's unit)").textContent).toMatch(/^[+−]/);
      unmount();
    }
  });
});
