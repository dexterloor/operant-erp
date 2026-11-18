import { isValidElement, type ReactElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

// GR-2 (purchase-orders-plan.md §2.6, Revision 1 R1-3; A-20): the receive page's server half and the order page's Receive
// action. Receive is gated GetPurchaseOrder && CreateGoodsReceipt && ListStorageLocations (each on its own operation),
// offered on the order page only for an ordered or partly received live order. The receive page refuses with ST-4b naming
// the missing operation; an order nothing can be received on reads nothing more and shows the no-form state; otherwise its
// live lines, then its warehouse's live locations (never `archived`), are read; 401 → the session-ended notice.

const ORDER_ID = 'd1b2c3d4-0000-4000-8000-000000000001';
const v = vi.hoisted(() => ({
  byOperation: null as ((operation: string) => boolean) | null,
  codes: [] as string[],
  status: 'ordered',
  archived: false,
  reads: [] as string[],
  locations: 'ok' as 'ok' | 'session-ended' | 'forbidden' | 'server-error',
  lines: 'ok' as 'ok' | 'more' | 'forbidden',
}));
vi.mock('@/app/(app)/currentViewer', async (actual) => ({
  ...(await actual<typeof import('@/app/(app)/currentViewer')>()),
  currentViewer: async () => ({ session: { sid: 's' }, viewer: { id: 'viewer', permissionCodes: v.codes } }),
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
vi.mock('@/lib/bff/surface', async (actual) => {
  const real = await actual<typeof import('@/lib/bff/surface')>();
  return { ...real, viewerMay: (operation: Parameters<typeof real.viewerMay>[0], codes: Parameters<typeof real.viewerMay>[1]) => (v.byOperation ? v.byOperation(operation) : real.viewerMay(operation, codes)) };
});
vi.mock('@/lib/purchasing/load', async (actual) => {
  const real = await actual<typeof import('@/lib/purchasing/load')>();
  return {
    ...real,
    loadPurchaseOrder: async () => ({
      kind: 'ok',
      order: {
        id: ORDER_ID,
        order_number: 'PO-000001',
        status: v.status,
        supplier: { id: 's', name: 'Acme Supplies' },
        warehouse: { id: 'w', name: 'Main' },
        currency_code: 'GBP',
        total_amount: '0.0000',
        ordered_at: null,
        expected_at: null,
        created_by: null,
        archived_at: v.archived ? '2026-03-04T11:00:00Z' : null,
      },
    }),
    loadLines: async () => {
      v.reads.push('lines');
      return v.lines === 'forbidden' ? { kind: 'forbidden' } : { kind: 'ok', lines: [], nextCursor: v.lines === 'more' ? 'c1' : null };
    },
    loadDeletedLines: async () => ({ kind: 'ok', lines: [], nextCursor: null }),
    loadReceipts: async () => ({ kind: 'ok', receipts: [], nextCursor: null, previousCursor: null, tenantHasRows: false }),
    loadLocationChoices: async (_read: unknown, warehouseId: string) => {
      v.reads.push(`locations ${warehouseId}`);
      return v.locations === 'ok' ? { kind: 'ok', locations: [{ id: 'l', code: 'A-01', kind: 'bin' }] } : { kind: v.locations };
    },
  };
});

const { default: ReceiveRoute } = await import('@/app/(app)/purchase-orders/[id]/receive/page');
const { default: OrderRoute } = await import('@/app/(app)/purchase-orders/[id]/page');
const { ReceivePage } = await import('@/app/(app)/purchase-orders/[id]/receive/ReceivePage');
const { ReceiveDenied, PurchaseOrdersDenied } = await import('@/app/(app)/purchase-orders/PurchaseOrderStates');

async function receive(codes: string[]): Promise<ReactElement> {
  v.codes = codes;
  v.reads = [];
  const el = await ReceiveRoute({ params: Promise.resolve({ id: ORDER_ID }) });
  expect(isValidElement(el)).toBe(true);
  return el as ReactElement;
}
async function orderPage(codes: string[]): Promise<ReactElement> {
  v.codes = codes;
  const el = await OrderRoute({ params: Promise.resolve({ id: ORDER_ID }) });
  return el as ReactElement;
}
const OPERATOR = ['purchase_order.view', 'goods_receipt.create', 'storage_location.view'];

describe('/purchase-orders/[id]/receive — the gate (R1-3)', () => {
  it('GetPurchaseOrder + CreateGoodsReceipt + ListStorageLocations on an ordered order: the form, after the lines and then the warehouse\'s live locations', async () => {
    const el = await receive(OPERATOR);
    expect(el.type).toBe(ReceivePage);
    expect(v.reads).toEqual(['lines', 'locations w']);
    expect((el.props as { locations: unknown }).locations).toEqual([{ id: 'l', code: 'A-01', kind: 'bin' }]);
  });

  it.each([['ListPurchaseOrderLines'], ['CreateGoodsReceipt'], ['ListStorageLocations']] as const)('without %s (each on its own operation): ST-4b naming it, nothing read', async (missing) => {
    v.byOperation = (op) => op !== missing;
    try {
      const el = await receive([]);
      expect(el.type).toBe(ReceiveDenied);
      expect((el.props as { operation: string }).operation).toBe(missing);
      expect(v.reads).toEqual([]);
    } finally {
      v.byOperation = null;
    }
  });

  it('without GetPurchaseOrder: the orders ST-4b', async () => {
    expect((await receive(['goods_receipt.create', 'storage_location.view'])).type).toBe(PurchaseOrdersDenied);
  });

  it('an order nothing can be received on (draft, received, cancelled, a deleted draft): the no-form state, nothing more read', async () => {
    try {
      for (const status of ['draft', 'received', 'cancelled']) {
        v.status = status;
        const el = await receive(OPERATOR);
        expect(el.type).toBe(ReceivePage);
        expect(v.reads).toEqual([]);
      }
      v.status = 'draft';
      v.archived = true;
      await receive(OPERATOR);
      expect(v.reads).toEqual([]);
    } finally {
      v.status = 'ordered';
      v.archived = false;
    }
  });

  it('the locations read: 401 → the session-ended notice; 403 → ST-4b for ListStorageLocations; another failure throws to the error page', async () => {
    try {
      v.locations = 'session-ended';
      await expect(receive(OPERATOR)).rejects.toThrow(encodeURIComponent(`/purchase-orders/${ORDER_ID}/receive`));
      v.locations = 'forbidden';
      const el = await receive(OPERATOR);
      expect(el.type).toBe(ReceiveDenied);
      expect((el.props as { operation: string }).operation).toBe('ListStorageLocations');
      v.locations = 'server-error';
      await expect(receive(OPERATOR)).rejects.toThrow();
    } finally {
      v.locations = 'ok';
    }
  });
});

describe('/purchase-orders/[id]/receive — the lines read (gr-2-red.md L-2, L-5)', () => {
  it('a 403 on the lines read → ST-4b for ListPurchaseOrderLines, nothing more read; a lines answer with a next cursor (not the whole order) throws', async () => {
    try {
      v.lines = 'forbidden';
      const el = await receive(OPERATOR);
      expect(el.type).toBe(ReceiveDenied);
      expect((el.props as { operation: string }).operation).toBe('ListPurchaseOrderLines');
      expect(v.reads).toEqual(['lines']);
      v.lines = 'more';
      await expect(receive(OPERATOR)).rejects.toThrow();
    } finally {
      v.lines = 'ok';
    }
  });
});

describe('/purchase-orders/[id] — the Receive action\'s gate (R1-3, §2.8)', () => {
  const canReceiveOf = (el: ReactElement) => (el.props as { canReceive: boolean }).canReceive;

  it('CreateGoodsReceipt + ListStorageLocations held → canReceive; either missing (the composed shape without storage_location.view) → not', async () => {
    expect(canReceiveOf(await orderPage(OPERATOR))).toBe(true);
    expect(canReceiveOf(await orderPage(['purchase_order.view', 'goods_receipt.create']))).toBe(false);
    expect(canReceiveOf(await orderPage(['purchase_order.view', 'storage_location.view']))).toBe(false);
  });
});
