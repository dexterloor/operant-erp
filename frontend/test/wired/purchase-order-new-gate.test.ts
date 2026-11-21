import type { ReactElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

// Purchase orders PO-3 (purchase-orders-plan.md §2.4 gate, Revision 0 §4 PO-3 tests "creator without GetPurchaseOrder
// lands on a truthful page" and "gate"): `/purchase-orders/new` checks GetPurchaseOrder first (the suppliers S-2 / warehouse
// 4.3 RED M-1 precedent: a creator who cannot open the order would land on ST-4b right after the create), then
// CreatePurchaseOrder, then the two reads its choices need — ListSuppliers and ListWarehouses — each answered by ST-4b
// naming the missing permission, never an empty choice. Its one first read is the LIVE warehouses over the server
// transport. The list offers the create links only to a viewer who would reach the create page.

const v = vi.hoisted(() => ({
  byOperation: null as ((operation: string) => boolean) | null,
  codes: [] as string[],
  warehouseCalls: [] as unknown[][],
  warehouses: 'ok' as 'ok' | 'session-ended' | 'forbidden' | 'server-error',
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
    loadPurchaseOrders: async () => ({ kind: 'ok', items: [], nextCursor: null, previousCursor: null, tenantHasRows: false }),
    loadWarehouseChoices: async (...args: unknown[]) => {
      v.warehouseCalls.push(args);
      return v.warehouses === 'ok' ? { kind: 'ok', warehouses: [] } : { kind: v.warehouses };
    },
  };
});

const { default: NewPage, metadata } = await import('@/app/(app)/purchase-orders/new/page');
const { NewPurchaseOrderPage } = await import('@/app/(app)/purchase-orders/new/NewPurchaseOrderPage');
const { NewPurchaseOrderDenied, PurchaseOrdersDenied } = await import('@/app/(app)/purchase-orders/PurchaseOrderStates');
const { default: ListPage } = await import('@/app/(app)/purchase-orders/page');
const { PurchaseOrdersList } = await import('@/app/(app)/purchase-orders/PurchaseOrdersList');

const ALL = ['GetPurchaseOrder', 'CreatePurchaseOrder', 'ListSuppliers', 'ListWarehouses'];
const at = async (ops: string[]) => {
  v.byOperation = (op) => ops.includes(op) || op === 'ListPurchaseOrders';
  v.warehouseCalls = [];
  try {
    return (await NewPage()) as ReactElement;
  } finally {
    v.byOperation = null;
  }
};

describe('/purchase-orders/new — the gates', () => {
  it('title New purchase order; with all four operations → the page, given the first warehouse read, which is the live view read once over the server transport', async () => {
    expect(metadata.title).toBe('New purchase order');
    v.warehouses = 'ok';
    const el = await at(ALL);
    expect(el.type).toBe(NewPurchaseOrderPage);
    expect(el.props).toEqual({ warehouses: { kind: 'ok', warehouses: [] } });
    expect(v.warehouseCalls).toEqual([['read']]);
  });

  it('without GetPurchaseOrder → ST-4b for purchase orders, even with every other operation, and nothing is read', async () => {
    const el = await at(ALL.filter((o) => o !== 'GetPurchaseOrder'));
    expect(el.type).toBe(PurchaseOrdersDenied);
    expect(v.warehouseCalls).toEqual([]);
  });

  it.each(['CreatePurchaseOrder', 'ListSuppliers', 'ListWarehouses'])('without %s → ST-4b for new purchase orders naming that operation’s permission, never an empty choice; nothing is read', async (missing) => {
    const el = await at(ALL.filter((o) => o !== missing));
    expect(el.type).toBe(NewPurchaseOrderDenied);
    expect(el.props).toEqual({ operation: missing });
    expect(v.warehouseCalls).toEqual([]);
  });

  it('the first warehouse read: 401 → the session-ended notice for /purchase-orders/new; 403 → ST-4b naming ListWarehouses; any other failure → the page, which shows its own Retry', async () => {
    v.warehouses = 'session-ended';
    await expect(at(ALL)).rejects.toThrow(encodeURIComponent('/purchase-orders/new'));
    v.warehouses = 'forbidden';
    const denied = await at(ALL);
    expect(denied.type).toBe(NewPurchaseOrderDenied);
    expect(denied.props).toEqual({ operation: 'ListWarehouses' });
    v.warehouses = 'server-error';
    const el = await at(ALL);
    expect(el.type).toBe(NewPurchaseOrderPage);
    expect(el.props).toEqual({ warehouses: { kind: 'server-error' } });
    v.warehouses = 'ok';
  });

  it('the default role shapes: a buyer (view + create + supplier.view + warehouse.view) reaches the page; the warehouse operator (view only) does not', async () => {
    v.warehouses = 'ok';
    v.codes = ['purchase_order.view', 'purchase_order.create', 'supplier.view', 'warehouse.view'];
    expect(((await NewPage()) as ReactElement).type).toBe(NewPurchaseOrderPage);
    v.codes = ['purchase_order.view', 'warehouse.view', 'goods_receipt.create'];
    expect(((await NewPage()) as ReactElement).type).toBe(NewPurchaseOrderDenied);
  });
});

describe('/purchase-orders — canCreate is the create page’s own gate (a link never leads to ST-4b)', () => {
  it.each([
    [ALL, true],
    ...ALL.map((missing) => [ALL.filter((o) => o !== missing), false] as [string[], boolean]),
  ])('operations %j → canCreate %s', async (ops, expected) => {
    v.byOperation = (op) => op === 'ListPurchaseOrders' || ops.includes(op);
    try {
      const el = (await ListPage({ searchParams: Promise.resolve({}) })) as ReactElement;
      expect(el.type).toBe(PurchaseOrdersList);
      expect((el.props as { canCreate: boolean }).canCreate).toBe(expected);
    } finally {
      v.byOperation = null;
    }
  });
});
