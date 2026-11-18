import { isValidElement, type ReactElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

// Goods receipts GR-1 (purchase-orders-plan.md §2.2 "Data", §2.7, Revision 0 §4 GR-1; b4-architecture.md Revision 10
// R10-3, R10-4; Revision 7 A-20): the three server reads, with the viewer's codes as the only input. The register: its gate
// (ListGoodsReceipts), its first read (page 1, no search, NO fixed filter), the links it offers by GetGoodsReceipt and
// GetPurchaseOrder, 401 / 403. The receipt page: its gate (GetGoodsReceipt), its links by GetPurchaseOrder and GetItem,
// 401 / 403 / 404 / a non-record id. The order page: its Receipts section is read — page 1, with the order's id as
// `purchase_order_id` — only for a ListGoodsReceipts holder (A-20: absent and not fetched otherwise), never for a draft,
// live or deleted (gr-1-followups-ux.md §4: hidden on drafts); 401 on it → the session-ended notice, any other failure the
// section's own state.

const ORDER_ID = 'd1b2c3d4-0000-4000-8000-000000000001';
const RECEIPT_ID = 'e1b2c3d4-0000-4000-8000-000000000003';
const v = vi.hoisted(() => ({
  codes: [] as string[],
  receiptsCalls: [] as unknown[][],
  receipts: 'ok' as 'ok' | 'session-ended' | 'forbidden' | 'server-error',
  receipt: 'ok' as 'ok' | 'not-found' | 'session-ended' | 'forbidden',
  archived: false,
  status: 'partially_received',
}));
vi.mock('@/app/(app)/currentViewer', async (actual) => ({
  ...(await actual<typeof import('@/app/(app)/currentViewer')>()),
  currentViewer: async () => ({ session: { sid: 's' }, viewer: { id: 'viewer-id-from-the-server', permissionCodes: v.codes } }),
}));
vi.mock('next/navigation', () => ({
  redirect: (to: string) => {
    throw new Error(`redirect ${to}`);
  },
  notFound: () => {
    throw new Error('not found');
  },
}));
vi.mock('@/lib/bff/server/reads', () => ({ serverRead: () => 'read' }));
vi.mock('@/lib/purchasing/load', async (actual) => {
  const real = await actual<typeof import('@/lib/purchasing/load')>();
  const RECEIPT = { id: RECEIPT_ID, receipt_number: 'GR-000003', received_at: '2026-03-04T11:00:00Z', purchase_order: { id: ORDER_ID, order_number: 'PO-000001' }, received_by: null, lines: [] };
  return {
    ...real,
    loadReceipts: async (...args: unknown[]) => {
      v.receiptsCalls.push(args);
      return v.receipts === 'ok' ? { kind: 'ok', items: [], nextCursor: null, previousCursor: null, tenantHasRows: false } : { kind: v.receipts };
    },
    loadReceipt: async () => (v.receipt === 'ok' ? { kind: 'ok', receipt: RECEIPT } : { kind: v.receipt }),
    loadPurchaseOrder: async () => ({
      kind: 'ok',
      order: { id: ORDER_ID, order_number: 'PO-000001', status: v.status, supplier: { id: 's', name: 'Acme' }, warehouse: { id: 'w', name: 'Main' }, currency_code: 'GBP', total_amount: '0.0000', ordered_at: null, expected_at: null, created_by: null, archived_at: v.archived ? '2026-03-04T11:00:00Z' : null },
    }),
    loadLines: async () => ({ kind: 'ok', lines: [], nextCursor: null }),
  };
});

const { default: ListPage, metadata } = await import('@/app/(app)/goods-receipts/page');
const { default: ReceiptPage, generateMetadata } = await import('@/app/(app)/goods-receipts/[id]/page');
const { default: OrderPage } = await import('@/app/(app)/purchase-orders/[id]/page');
const { GoodsReceiptsDenied } = await import('@/app/(app)/goods-receipts/GoodsReceiptStates');
const { GoodsReceiptsList } = await import('@/app/(app)/goods-receipts/GoodsReceiptsList');
const { GoodsReceiptPage } = await import('@/app/(app)/goods-receipts/[id]/GoodsReceiptPage');

const list = async (codes: string[]) => {
  v.codes = codes;
  v.receiptsCalls = [];
  const el = await ListPage();
  expect(isValidElement(el)).toBe(true);
  return el as ReactElement<Record<string, unknown>>;
};
const receiptPage = async (codes: string[], id = RECEIPT_ID) => {
  v.codes = codes;
  return (await ReceiptPage({ params: Promise.resolve({ id }) })) as ReactElement<Record<string, unknown>>;
};
const orderPage = async (codes: string[]) => {
  v.codes = codes;
  v.receiptsCalls = [];
  return (await OrderPage({ params: Promise.resolve({ id: ORDER_ID }) })) as ReactElement<Record<string, unknown>>;
};

describe('/goods-receipts — the register page', () => {
  it('without goods_receipt.view it is ST-4b (ListGoodsReceipts’ gate), whatever else is held; nothing is read', async () => {
    expect((await list(['purchase_order.view', 'supplier.view', 'inventory_item.view'])).type).toBe(GoodsReceiptsDenied);
    expect(v.receiptsCalls).toEqual([]);
  });

  it('the first read is page 1, no search, and NO fixed filter (R10-4: only the order page’s section carries one)', async () => {
    const el = await list(['goods_receipt.view']);
    expect(el.type).toBe(GoodsReceiptsList);
    expect(v.receiptsCalls).toEqual([['read', { q: '', cursor: null }]]);
  });

  // Plan §4 GR-1 (viewer shape): goods_receipt.view without purchase_order.view links no order.
  it('the order link follows GetPurchaseOrder; the receipt link GetGoodsReceipt', async () => {
    expect((await list(['goods_receipt.view'])).props).toMatchObject({ canOpenReceipt: true, canOpenOrder: false });
    expect((await list(['goods_receipt.view', 'purchase_order.view'])).props).toMatchObject({ canOpenReceipt: true, canOpenOrder: true });
  });

  it('401 → the session-ended notice for /goods-receipts; 403 → ST-4b; the title is "Goods receipts"', async () => {
    v.receipts = 'session-ended';
    await expect(list(['goods_receipt.view'])).rejects.toThrow(encodeURIComponent('/goods-receipts'));
    v.receipts = 'forbidden';
    expect((await list(['goods_receipt.view'])).type).toBe(GoodsReceiptsDenied);
    v.receipts = 'ok';
    expect(metadata.title).toBe('Goods receipts');
  });
});

describe('/goods-receipts/[id] — the receipt page', () => {
  it('goods_receipt.view alone: the page, no link; GetPurchaseOrder and GetItem each add their own link', async () => {
    const el = await receiptPage(['goods_receipt.view']);
    expect(el.type).toBe(GoodsReceiptPage);
    expect(el.props).toMatchObject({ canOpenOrder: false, canOpenItem: false });
    expect((await receiptPage(['goods_receipt.view', 'purchase_order.view'])).props).toMatchObject({ canOpenOrder: true, canOpenItem: false });
    expect((await receiptPage(['goods_receipt.view', 'inventory_item.view'])).props).toMatchObject({ canOpenOrder: false, canOpenItem: true });
  });

  it('without goods_receipt.view it is ST-4b', async () => {
    expect((await receiptPage(['purchase_order.view', 'inventory_item.view'])).type).toBe(GoodsReceiptsDenied);
  });

  it('401 → the session-ended notice for this address; 403 → ST-4b; 404 → ST-5; a non-record id → ST-5', async () => {
    v.receipt = 'session-ended';
    await expect(receiptPage(['goods_receipt.view'])).rejects.toThrow(encodeURIComponent(`/goods-receipts/${RECEIPT_ID}`));
    v.receipt = 'forbidden';
    expect((await receiptPage(['goods_receipt.view'])).type).toBe(GoodsReceiptsDenied);
    v.receipt = 'not-found';
    await expect(receiptPage(['goods_receipt.view'])).rejects.toThrow('not found');
    v.receipt = 'ok';
    await expect(receiptPage(['goods_receipt.view'], 'not-a-uuid')).rejects.toThrow('not found');
  });

  it('the title is the receipt number; without the read it is the register heading', async () => {
    v.codes = ['goods_receipt.view'];
    expect((await generateMetadata({ params: Promise.resolve({ id: RECEIPT_ID }) })).title).toBe('GR-000003');
    v.codes = [];
    expect((await generateMetadata({ params: Promise.resolve({ id: RECEIPT_ID }) })).title).toBe('Goods receipts');
  });
});

describe('/purchase-orders/[id] — the Receipts section’s first read (A-20, R10-4)', () => {
  it('without goods_receipt.view: not read, and the page gets no receipts (the section is absent)', async () => {
    const el = await orderPage(['purchase_order.view']);
    expect(v.receiptsCalls).toEqual([]);
    expect(el.props.receipts).toBeNull();
  });

  it('with it: page 1 of this order’s receipts — purchase_order_id = the order’s id — and the receipt link by GetGoodsReceipt', async () => {
    const el = await orderPage(['purchase_order.view', 'goods_receipt.view']);
    expect(v.receiptsCalls).toEqual([['read', { q: '', cursor: null }, undefined, { name: 'purchase_order_id', value: ORDER_ID }]]);
    expect(el.props).toMatchObject({ receipts: { kind: 'ok' }, canOpenReceipt: true });
  });

  it('a deleted draft’s receipts are not read', async () => {
    v.archived = true;
    try {
      expect((await orderPage(['purchase_order.view', 'goods_receipt.view'])).props.receipts).toBeNull();
      expect(v.receiptsCalls).toEqual([]);
    } finally {
      v.archived = false;
    }
  });

  // gr-1-followups-ux.md §4 / arch 3c item 1: nothing can be received on a draft, so its page makes no ListGoodsReceipts read.
  it('a live draft’s receipts are not read', async () => {
    v.status = 'draft';
    try {
      expect((await orderPage(['purchase_order.view', 'goods_receipt.view'])).props.receipts).toBeNull();
      expect(v.receiptsCalls).toEqual([]);
    } finally {
      v.status = 'partially_received';
    }
  });

  it('every placed status is read: ordered, partly received, received, cancelled', async () => {
    try {
      for (const status of ['ordered', 'partially_received', 'received', 'cancelled']) {
        v.status = status;
        expect((await orderPage(['purchase_order.view', 'goods_receipt.view'])).props.receipts).toMatchObject({ kind: 'ok' });
        expect(v.receiptsCalls).toHaveLength(1);
      }
    } finally {
      v.status = 'partially_received';
    }
  });

  it('a failed read is the section’s own state; a 401 on it → the session-ended notice', async () => {
    v.receipts = 'server-error';
    expect((await orderPage(['purchase_order.view', 'goods_receipt.view'])).props.receipts).toEqual({ kind: 'server-error' });
    v.receipts = 'session-ended';
    await expect(orderPage(['purchase_order.view', 'goods_receipt.view'])).rejects.toThrow(encodeURIComponent(`/purchase-orders/${ORDER_ID}`));
    v.receipts = 'ok';
  });
});
