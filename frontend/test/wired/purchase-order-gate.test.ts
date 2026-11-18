import { isValidElement, type ReactElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

// Purchase orders PO-1 (purchase-orders-plan.md §4 PO-1 tests 2, 9, 14; R1-5 16; final RED L-2): the two server pages,
// with the viewer's codes as the only input. The list page: its gate, the view and segment the address names (kept on
// reload, never a cursor or a search), and 401 / 403. The order page: its gate, the links it offers by GetSupplier,
// GetWarehouse and GetItem (each on its own operation), the viewer's id from currentViewer() (never the client session),
// the lines read (skipped for a deleted draft), and 401 / 403 / 404 / a non-record id.

const ORDER_ID = 'd1b2c3d4-0000-4000-8000-000000000001';
const v = vi.hoisted(() => ({
  byOperation: null as ((operation: string) => boolean) | null,
  codes: [] as string[],
  listCalls: [] as unknown[][],
  list: 'ok' as 'ok' | 'session-ended' | 'forbidden',
  order: 'ok' as 'ok' | 'not-found' | 'session-ended' | 'forbidden',
  archived: false,
  lineReads: 0,
  lines: 'ok' as 'ok' | 'session-ended' | 'server-error',
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
vi.mock('@/lib/bff/surface', async (actual) => {
  const real = await actual<typeof import('@/lib/bff/surface')>();
  return { ...real, viewerMay: (operation: Parameters<typeof real.viewerMay>[0], codes: Parameters<typeof real.viewerMay>[1]) => (v.byOperation ? v.byOperation(operation) : real.viewerMay(operation, codes)) };
});
vi.mock('@/lib/purchasing/load', async (actual) => {
  const real = await actual<typeof import('@/lib/purchasing/load')>();
  const ORDER = {
    id: ORDER_ID,
    order_number: 'PO-000001',
    status: 'draft',
    supplier: { id: 's', name: 'Acme Supplies' },
    warehouse: { id: 'w', name: 'Main' },
    currency_code: 'GBP',
    total_amount: '0.0000',
    ordered_at: null,
    expected_at: null,
    created_by: null,
    archived_at: null,
  };
  return {
    ...real,
    loadPurchaseOrders: async (...args: unknown[]) => {
      v.listCalls.push(args);
      return v.list === 'ok' ? { kind: 'ok', items: [], nextCursor: null, previousCursor: null, tenantHasRows: false } : { kind: v.list };
    },
    loadPurchaseOrder: async () => (v.order === 'ok' ? { kind: 'ok', order: { ...ORDER, archived_at: v.archived ? '2026-03-04T11:00:00Z' : null } } : { kind: v.order }),
    loadLines: async () => {
      v.lineReads += 1;
      return v.lines === 'ok' ? { kind: 'ok', lines: [], nextCursor: null } : { kind: v.lines };
    },
  };
});

const { default: Page, generateMetadata } = await import('@/app/(app)/purchase-orders/[id]/page');
const { default: ListPage, metadata } = await import('@/app/(app)/purchase-orders/page');
const { PurchaseOrdersDenied } = await import('@/app/(app)/purchase-orders/PurchaseOrderStates');
const { PurchaseOrdersList } = await import('@/app/(app)/purchase-orders/PurchaseOrdersList');
const { PurchaseOrderPage } = await import('@/app/(app)/purchase-orders/[id]/PurchaseOrderPage');

async function render(codes: string[]): Promise<ReactElement> {
  v.codes = codes;
  v.order = 'ok';
  const el = await Page({ params: Promise.resolve({ id: ORDER_ID }) });
  expect(isValidElement(el)).toBe(true);
  return el as ReactElement;
}
type Props = { viewerId: string; canRestore: boolean; canReadLines: boolean; canOpenSupplier: boolean; canOpenWarehouse: boolean; canOpenItem: boolean; canDelete: boolean; canCancel: boolean; canApprove: boolean; lines: unknown };
const props = (el: ReactElement) => el.props as Props;

describe('/purchase-orders — the list page', () => {
  const list = async (codes: string[], query: Record<string, string> = {}) => {
    v.codes = codes;
    v.listCalls = [];
    return (await ListPage({ searchParams: Promise.resolve(query) })) as ReactElement;
  };

  it('without purchase_order.view the list is ST-4b (ListPurchaseOrders’ gate), whatever else is held', async () => {
    expect((await list(['goods_receipt.view', 'supplier.view', 'inventory_item.view'])).type).toBe(PurchaseOrdersDenied);
    expect(v.listCalls).toEqual([]);
  });

  // Test 2 (the reload half): the first read is the addressed segment's first page; the list opens on it.
  it.each([
    [{}, false, 'open'],
    [{ status: 'draft' }, false, 'draft'],
    [{ status: 'received' }, false, 'received'],
    [{ status: 'cancelled' }, false, 'cancelled'],
    [{ status: 'bogus' }, false, 'open'],
    [{ archived: 'true' }, true, undefined],
    [{ archived: 'true', status: 'draft' }, true, undefined],
  ])('address %o → first read archived=%s segment=%s, page 1, no search', async (query, archived, segment) => {
    const el = await list(['purchase_order.view'], query);
    expect(el.type).toBe(PurchaseOrdersList);
    expect(v.listCalls).toEqual([['read', archived, { q: '', cursor: null }, undefined, segment]]);
    expect(el.props).toMatchObject({ initialArchived: archived, initialSegment: segment ?? 'open' });
  });

  it('401 → the session-ended notice for this address (the query kept); 403 → ST-4b; the title is "Purchase orders"', async () => {
    v.list = 'session-ended';
    await expect(list(['purchase_order.view'], { status: 'draft' })).rejects.toThrow(encodeURIComponent('/purchase-orders?status=draft'));
    v.list = 'forbidden';
    expect((await list(['purchase_order.view'])).type).toBe(PurchaseOrdersDenied);
    v.list = 'ok';
    expect(metadata.title).toBe('Purchase orders');
  });
});

describe('/purchase-orders/[id] — the order page', () => {
  it('purchase_order.view alone: the page, no link anywhere, the lines read once', async () => {
    v.lineReads = 0;
    const el = await render(['purchase_order.view']);
    expect(el.type).toBe(PurchaseOrderPage);
    expect(props(el)).toMatchObject({ canOpenSupplier: false, canOpenWarehouse: false, canOpenItem: false, lines: { kind: 'ok', lines: [], nextCursor: null } });
    expect(v.lineReads).toBe(1);
  });

  // Test 9: each link on its own operation.
  it.each([
    ['GetSupplier', { canOpenSupplier: true, canOpenWarehouse: false, canOpenItem: false }],
    ['GetWarehouse', { canOpenSupplier: false, canOpenWarehouse: true, canOpenItem: false }],
    ['GetItem', { canOpenSupplier: false, canOpenWarehouse: false, canOpenItem: true }],
  ] as const)('test 9 — only GetPurchaseOrder, ListPurchaseOrderLines and %s granted', async (operation, expected) => {
    v.byOperation = (op) => op === 'GetPurchaseOrder' || op === 'ListPurchaseOrderLines' || op === operation;
    try {
      expect(props(await render([]))).toMatchObject(expected);
    } finally {
      v.byOperation = null;
    }
  });

  it('the links follow the codes: supplier.view, warehouse.view, inventory_item.view', async () => {
    expect(props(await render(['purchase_order.view', 'supplier.view', 'warehouse.view', 'inventory_item.view']))).toMatchObject({ canOpenSupplier: true, canOpenWarehouse: true, canOpenItem: true });
  });

  // Test 16 / final RED L-2: the id is the server's viewer.
  it('test 16 — the viewer id passed to the page is currentViewer()’s, read on the server', async () => {
    expect(props(await render(['purchase_order.view'])).viewerId).toBe('viewer-id-from-the-server');
  });

  // PO-2: Restore purchase order follows RestorePurchaseOrder alone (purchase_order.delete), never the view's codes; the
  // browser reads a restored order's lines only for a ListPurchaseOrderLines holder.
  it('PO-2 — canRestore follows RestorePurchaseOrder, canReadLines ListPurchaseOrderLines, each on its own operation', async () => {
    expect(props(await render(['purchase_order.view']))).toMatchObject({ canRestore: false, canReadLines: true });
    expect(props(await render(['purchase_order.view', 'purchase_order.delete']))).toMatchObject({ canRestore: true, canReadLines: true });
    for (const [operation, expected] of [
      ['RestorePurchaseOrder', { canRestore: true, canReadLines: false }],
      ['ListPurchaseOrderLines', { canRestore: false, canReadLines: true }],
    ] as const) {
      v.byOperation = (op) => op === 'GetPurchaseOrder' || op === operation;
      try {
        expect(props(await render([]))).toMatchObject(expected);
      } finally {
        v.byOperation = null;
      }
    }
  });

  // PO-6: Delete purchase order follows DeletePurchaseOrder alone (purchase_order.delete), on its own operation; the page
  // offers it only on a live draft (purchase-order-delete.test.tsx).
  it('PO-6 — canDelete follows DeletePurchaseOrder, on its own operation', async () => {
    expect(props(await render(['purchase_order.view'])).canDelete).toBe(false);
    expect(props(await render(['purchase_order.view', 'purchase_order.delete'])).canDelete).toBe(true);
    for (const [operation, expected] of [
      ['DeletePurchaseOrder', true],
      ['RestorePurchaseOrder', false],
    ] as const) {
      v.byOperation = (op) => op === 'GetPurchaseOrder' || op === operation;
      try {
        expect(props(await render([])).canDelete).toBe(expected);
      } finally {
        v.byOperation = null;
      }
    }
  });

  // PO-7: Cancel order follows CancelPurchaseOrder alone (purchase_order.cancel), on its own operation; the page offers it
  // only on an ordered or partly received order (purchase-order-cancel.test.tsx).
  it('PO-7 — canCancel follows CancelPurchaseOrder, on its own operation', async () => {
    expect(props(await render(['purchase_order.view'])).canCancel).toBe(false);
    expect(props(await render(['purchase_order.view', 'purchase_order.cancel'])).canCancel).toBe(true);
    for (const [operation, expected] of [
      ['CancelPurchaseOrder', true],
      ['ApprovePurchaseOrder', false],
      ['DeletePurchaseOrder', false],
    ] as const) {
      v.byOperation = (op) => op === 'GetPurchaseOrder' || op === operation;
      try {
        expect(props(await render([])).canCancel).toBe(expected);
      } finally {
        v.byOperation = null;
      }
    }
  });

  // PO-8: Approve follows ApprovePurchaseOrder alone (purchase_order.approve), on its own operation; the page offers it only on
  // a live draft (purchase-order-approve.test.tsx).
  it('PO-8 — canApprove follows ApprovePurchaseOrder, on its own operation', async () => {
    expect(props(await render(['purchase_order.view'])).canApprove).toBe(false);
    expect(props(await render(['purchase_order.view', 'purchase_order.approve'])).canApprove).toBe(true);
    for (const [operation, expected] of [
      ['ApprovePurchaseOrder', true],
      ['CancelPurchaseOrder', false],
      ['UpdatePurchaseOrder', false],
    ] as const) {
      v.byOperation = (op) => op === 'GetPurchaseOrder' || op === operation;
      try {
        expect(props(await render([])).canApprove).toBe(expected);
      } finally {
        v.byOperation = null;
      }
    }
  });

  it('a deleted draft: its lines are not read (they went with it), and Restore is offered to a purchase_order.delete holder', async () => {
    v.archived = true;
    v.lineReads = 0;
    try {
      expect(props(await render(['purchase_order.view'])).lines).toBeNull();
      expect(v.lineReads).toBe(0);
      expect(props(await render(['purchase_order.view', 'purchase_order.delete']))).toMatchObject({ lines: null, canRestore: true });
      expect(v.lineReads).toBe(0);
    } finally {
      v.archived = false;
    }
  });

  it('a failed lines read is the section’s own state; a 401 on it → the session-ended notice', async () => {
    v.lines = 'server-error';
    expect(props(await render(['purchase_order.view'])).lines).toEqual({ kind: 'server-error' });
    v.lines = 'session-ended';
    await expect(render(['purchase_order.view'])).rejects.toThrow(encodeURIComponent(`/purchase-orders/${ORDER_ID}`));
    v.lines = 'ok';
  });

  it('without purchase_order.view the page is ST-4b, even with every other code', async () => {
    expect((await render(['goods_receipt.view', 'supplier.view', 'warehouse.view', 'inventory_item.view'])).type).toBe(PurchaseOrdersDenied);
  });

  // Test 14.
  it('test 14 — 401 → the session-ended notice for this address; 403 → ST-4b; 404 → ST-5; a non-record id → ST-5', async () => {
    v.codes = ['purchase_order.view'];
    v.order = 'session-ended';
    await expect(Page({ params: Promise.resolve({ id: ORDER_ID }) })).rejects.toThrow(encodeURIComponent(`/purchase-orders/${ORDER_ID}`));
    v.order = 'forbidden';
    expect(((await Page({ params: Promise.resolve({ id: ORDER_ID }) })) as ReactElement).type).toBe(PurchaseOrdersDenied);
    v.order = 'not-found';
    await expect(Page({ params: Promise.resolve({ id: ORDER_ID }) })).rejects.toThrow('not found');
    v.order = 'ok';
    await expect(Page({ params: Promise.resolve({ id: 'not-a-uuid' }) })).rejects.toThrow('not found');
  });

  it('the page title is "{number} — {supplier}"; without the read it is the list heading', async () => {
    v.codes = ['purchase_order.view'];
    v.order = 'ok';
    expect((await generateMetadata({ params: Promise.resolve({ id: ORDER_ID }) })).title).toBe('PO-000001 — Acme Supplies');
    v.codes = [];
    expect((await generateMetadata({ params: Promise.resolve({ id: ORDER_ID }) })).title).toBe('Purchase orders');
  });
});
