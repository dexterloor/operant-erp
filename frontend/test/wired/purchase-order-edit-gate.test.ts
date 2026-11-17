import { isValidElement, type ReactElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

// Purchase orders PO-4a (purchase-orders-plan.md §2.2 "Draft (editor)", Revision 1 R1-3 / R1-5 PO-4a): the order page's
// server half of the header editor. The editor (`edit`) is given only for a LIVE DRAFT and a viewer holding
// UpdatePurchaseOrder's AND ListWarehouses' codes (each on its own operation), with the live warehouses read for Deliver
// to; never for another status, a deleted draft, or a viewer without either. A 401 on that read → the session-ended notice;
// any other failure is the field's own state (a 403 included: the page itself stays viewable).

const ORDER_ID = 'd1b2c3d4-0000-4000-8000-000000000001';
const v = vi.hoisted(() => ({
  byOperation: null as ((operation: string) => boolean) | null,
  codes: [] as string[],
  status: 'draft',
  archived: false,
  warehouseReads: 0,
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
    loadLines: async () => ({ kind: 'ok', lines: [], nextCursor: null }),
    loadWarehouseChoices: async () => {
      v.warehouseReads += 1;
      return v.warehouses === 'ok' ? { kind: 'ok', warehouses: [{ id: 'w', code: 'MAIN', name: 'Main' }] } : { kind: v.warehouses };
    },
  };
});

const { default: Page } = await import('@/app/(app)/purchase-orders/[id]/page');
const { PurchaseOrderPage } = await import('@/app/(app)/purchase-orders/[id]/PurchaseOrderPage');

async function render(codes: string[]): Promise<ReactElement> {
  v.codes = codes;
  v.warehouseReads = 0;
  const el = await Page({ params: Promise.resolve({ id: ORDER_ID }) });
  expect(isValidElement(el)).toBe(true);
  return el as ReactElement;
}
const editOf = (el: ReactElement) => (el.props as { edit: unknown }).edit;
const BUYER = ['purchase_order.view', 'purchase_order.edit', 'warehouse.view'];

describe('/purchase-orders/[id] — the header editor’s gate (PO-4a)', () => {
  it('a live draft and purchase_order.edit + warehouse.view: the editor, with the live warehouses read once', async () => {
    const el = await render(BUYER);
    expect(el.type).toBe(PurchaseOrderPage);
    expect(editOf(el)).toEqual({ warehouses: { kind: 'ok', warehouses: [{ id: 'w', code: 'MAIN', name: 'Main' }] } });
    expect(v.warehouseReads).toBe(1);
  });

  it('without purchase_order.edit, or without warehouse.view: no editor, and the warehouses are not read', async () => {
    for (const codes of [['purchase_order.view', 'warehouse.view'], ['purchase_order.view', 'purchase_order.edit']]) {
      const el = await render(codes);
      expect(editOf(el)).toBeNull();
      expect(v.warehouseReads).toBe(0);
    }
  });

  it.each([
    ['UpdatePurchaseOrder', null],
    ['ListWarehouses', null],
  ] as const)('each on its own operation: only GetPurchaseOrder, ListPurchaseOrderLines and %s granted → no editor', async (operation, expected) => {
    v.byOperation = (op) => op === 'GetPurchaseOrder' || op === 'ListPurchaseOrderLines' || op === operation;
    try {
      expect(editOf(await render([]))).toBe(expected);
      expect(v.warehouseReads).toBe(0);
    } finally {
      v.byOperation = null;
    }
  });

  it('every other status, and a deleted draft: no editor, nothing read, whatever the viewer holds', async () => {
    try {
      for (const status of ['ordered', 'partially_received', 'received', 'cancelled']) {
        v.status = status;
        expect(editOf(await render(BUYER))).toBeNull();
        expect(v.warehouseReads).toBe(0);
      }
      v.status = 'draft';
      v.archived = true;
      expect(editOf(await render(BUYER))).toBeNull();
      expect(v.warehouseReads).toBe(0);
    } finally {
      v.status = 'draft';
      v.archived = false;
    }
  });

  it('a 401 on the warehouses read → the session-ended notice; a 403 or another failure is the field’s own state', async () => {
    try {
      v.warehouses = 'session-ended';
      await expect(render(BUYER)).rejects.toThrow(encodeURIComponent(`/purchase-orders/${ORDER_ID}`));
      v.warehouses = 'forbidden';
      expect(editOf(await render(BUYER))).toEqual({ warehouses: { kind: 'forbidden' } });
      v.warehouses = 'server-error';
      expect(editOf(await render(BUYER))).toEqual({ warehouses: { kind: 'server-error' } });
    } finally {
      v.warehouses = 'ok';
    }
  });
});
