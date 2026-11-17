import { screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), refresh: vi.fn() }), usePathname: () => '/goods-receipts/x' }));
import { GoodsReceiptPage } from '@/app/(app)/goods-receipts/[id]/GoodsReceiptPage';
import type { GoodsReceipt } from '@/lib/api/purchasing';
import { formatDateTime } from '@/lib/format/dates';
import { renderWired, TZ } from './harness';

// Goods receipts GR-1, the receipt page (purchase-orders-plan.md §2.7 "Detail", Revision 0 §4 GR-1; UX B-10
// `GOODS_RECEIPT_PAGE`; IA L-5a: read-only). Header Receipt number · Purchase order (a link only with GetPurchaseOrder) ·
// Received (DT-1 date-with-time) · Received by (or *Not recorded*); lines Order line · Item (a link only with GetItem) ·
// Quantity (QR-2, the interim *item's unit* heading until BK-33, final RED L-11) · Location (its code, never a link: the
// receipt serves no warehouse id). No cost anywhere, no action.

const ORDER = { id: 'd1b2c3d4-0000-4000-8000-000000000001', order_number: 'PO-000001' };
const R: GoodsReceipt = {
  id: 'e1b2c3d4-0000-4000-8000-000000000003',
  receipt_number: 'GR-000003',
  received_at: '2026-03-04T11:00:00Z',
  purchase_order: ORDER,
  received_by: { id: 'f1b2c3d4-0000-4000-8000-000000000001', display_name: 'Wanda Warehouse' },
  lines: [
    { id: 'a1b2c3d4-0000-4000-8000-000000000001', purchase_order_line: { id: 'b1b2c3d4-0000-4000-8000-000000000001', line_number: 1 }, inventory_item: { id: 'c1b2c3d4-0000-4000-8000-000000000001', sku: 'SKU-1', name: 'Widget' }, quantity: '1234567890123.123456', storage_location: { id: 'c9b2c3d4-0000-4000-8000-000000000001', code: 'A-01' } },
    { id: 'a1b2c3d4-0000-4000-8000-000000000002', purchase_order_line: { id: 'b1b2c3d4-0000-4000-8000-000000000003', line_number: 3 }, inventory_item: { id: 'c1b2c3d4-0000-4000-8000-000000000002', sku: 'SKU-2', name: 'Gadget' }, quantity: '10.000000', storage_location: { id: 'c9b2c3d4-0000-4000-8000-000000000002', code: 'B-07' } },
  ],
};
const show = (over: { receipt?: GoodsReceipt; canOpenOrder?: boolean; canOpenItem?: boolean } = {}) => renderWired(<GoodsReceiptPage receipt={over.receipt ?? R} canOpenOrder={over.canOpenOrder ?? false} canOpenItem={over.canOpenItem ?? false} />);
const fact = (label: string) => {
  const dt = [...document.querySelectorAll('dt')].find((d) => d.textContent === label);
  return dt ? (dt.nextElementSibling as HTMLElement).textContent : null;
};
const cellsOf = (i: number) => [...[...document.querySelectorAll('tbody tr')][i]!.querySelectorAll('td')].map((c) => c.textContent);

describe('/goods-receipts/[id] — the receipt page (UX B-10)', () => {
  it('h1 is the receipt number; Purchase order · Received (date and time) · Received by; the read-only note', () => {
    show();
    expect(screen.getByRole('heading', { level: 1, name: 'GR-000003' })).toBeInTheDocument();
    expect(fact('Purchase order')).toBe('PO-000001');
    expect(fact('Received')).toBe(formatDateTime('2026-03-04T11:00:00Z', TZ));
    expect(fact('Received by')).toBe('Wanda Warehouse');
    expect(screen.getByText("A receipt can't be changed or deleted.")).toBeInTheDocument();
  });

  it('Received by null reads "Not recorded"', () => {
    show({ receipt: { ...R, received_by: null } });
    expect(fact('Received by')).toBe('Not recorded');
  });

  it('lines: Order line · Item · Quantity (item’s unit) · Location; QR-2 quantities; the location code, never a link', () => {
    show({ canOpenItem: true, canOpenOrder: true });
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Order line', 'Item', "Quantity (item's unit)", 'Location']);
    expect(cellsOf(0)).toEqual(['1', 'SKU-1 — Widget', '1234567890123.123456', 'A-01']);
    expect(cellsOf(1)).toEqual(['3', 'SKU-2 — Gadget', '10', 'B-07']);
    const rows = document.querySelectorAll('tbody tr');
    expect(within(rows[0] as HTMLElement).queryByRole('link', { name: 'A-01' })).toBeNull();
  });

  // Plan §4 GR-1: the order link only with GetPurchaseOrder (viewer holds goods_receipt.view without purchase_order.view).
  it('the order and the items are links only with GetPurchaseOrder / GetItem', () => {
    const { unmount } = show();
    expect(screen.queryAllByRole('link')).toEqual([]);
    unmount();
    show({ canOpenOrder: true, canOpenItem: true });
    expect(screen.getByRole('link', { name: 'PO-000001' })).toHaveAttribute('href', `/purchase-orders/${ORDER.id}`);
    expect(screen.getByRole('link', { name: 'SKU-1 — Widget' })).toHaveAttribute('href', '/items/c1b2c3d4-0000-4000-8000-000000000001');
    expect(screen.queryByRole('link', { name: 'B-07' })).toBeNull();
  });

  it('read-only: no button, no input, no cost', () => {
    show({ canOpenOrder: true, canOpenItem: true });
    expect(screen.queryAllByRole('button')).toEqual([]);
    expect(document.querySelector('input, select, textarea, form')).toBeNull();
    expect(document.body.textContent).not.toMatch(/GBP|EUR|USD|JPY|cost/i);
  });
});
