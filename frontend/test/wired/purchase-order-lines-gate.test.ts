import { isValidElement, type ReactElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

// Purchase orders PO-5 (purchase-orders-plan.md §2.3, Revision 1 R1-3 / R1-5 PO-5; final RED L-5): the order page's server
// half of Add line and Delete line. Add (`add`) is given only for a LIVE DRAFT whose lines were read, to a viewer holding
// CreatePurchaseOrderLine's AND ListSupplierProducts' codes (each on its own operation), with the order's supplier's
// catalogue read for the new row's Item; never for another status, a deleted draft, or a viewer without either. A 401 on
// that read → the session-ended notice; any other failure is the row's own state. Delete (`canDeleteLine`) follows
// DeletePurchaseOrderLine's codes; the page offers it only on a live draft.

const ORDER_ID = 'd1b2c3d4-0000-4000-8000-000000000001';
const SUPPLIER_ID = 'c1b2c3d4-0000-4000-8000-0000000000a1';
const v = vi.hoisted(() => ({
  byOperation: null as ((operation: string) => boolean) | null,
  codes: [] as string[],
  status: 'draft',
  archived: false,
  catalogueReads: [] as string[],
  catalogue: 'ok' as 'ok' | 'session-ended' | 'forbidden' | 'server-error',
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
const ENTRY = { id: 'e', inventory_item: { id: 'i', sku: 'BOLT-7', name: 'Bolt' }, unit_cost: '1.0000', currency_code: 'GBP', unit_of_measure_code: 'EA' };
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
        supplier: { id: SUPPLIER_ID, name: 'Acme Supplies' },
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
    loadWarehouseChoices: async () => ({ kind: 'ok', warehouses: [] }),
    loadCatalogueChoices: async (_read: unknown, supplierId: string) => {
      v.catalogueReads.push(supplierId);
      return v.catalogue === 'ok' ? { kind: 'ok', entries: [ENTRY] } : { kind: v.catalogue };
    },
  };
});

const { default: Page } = await import('@/app/(app)/purchase-orders/[id]/page');
const { PurchaseOrderPage } = await import('@/app/(app)/purchase-orders/[id]/PurchaseOrderPage');

async function render(codes: string[]): Promise<ReactElement> {
  v.codes = codes;
  v.catalogueReads = [];
  const el = await Page({ params: Promise.resolve({ id: ORDER_ID }) });
  expect(isValidElement(el)).toBe(true);
  return el as ReactElement;
}
const addOf = (el: ReactElement) => (el.props as { add: unknown }).add;
const deleteOf = (el: ReactElement) => (el.props as { canDeleteLine: unknown }).canDeleteLine;
const BUYER = ['purchase_order.view', 'purchase_order.edit', 'supplier_product.view'];

describe('/purchase-orders/[id] — Add line’s and Delete line’s gates (PO-5)', () => {
  it('a live draft and purchase_order.edit + supplier_product.view: Add, with the order’s supplier’s catalogue read once; Delete', async () => {
    const el = await render(BUYER);
    expect(el.type).toBe(PurchaseOrderPage);
    expect(addOf(el)).toEqual({ catalogue: { kind: 'ok', entries: [ENTRY] } });
    expect(v.catalogueReads).toEqual([SUPPLIER_ID]);
    expect(deleteOf(el)).toBe(true);
  });

  it('without supplier_product.view: no Add and no catalogue read, but Delete; without purchase_order.edit: neither', async () => {
    let el = await render(['purchase_order.view', 'purchase_order.edit']);
    expect(addOf(el)).toBeNull();
    expect(v.catalogueReads).toEqual([]);
    expect(deleteOf(el)).toBe(true);
    el = await render(['purchase_order.view', 'supplier_product.view']);
    expect(addOf(el)).toBeNull();
    expect(v.catalogueReads).toEqual([]);
    expect(deleteOf(el)).toBe(false);
  });

  it.each(['CreatePurchaseOrderLine', 'ListSupplierProducts'] as const)('each on its own operation: only GetPurchaseOrder, ListPurchaseOrderLines and %s granted → no Add', async (operation) => {
    v.byOperation = (op) => op === 'GetPurchaseOrder' || op === 'ListPurchaseOrderLines' || op === operation;
    try {
      expect(addOf(await render([]))).toBeNull();
      expect(v.catalogueReads).toEqual([]);
    } finally {
      v.byOperation = null;
    }
  });

  it('without ListPurchaseOrderLines (no lines section): no Add, nothing read', async () => {
    v.byOperation = (op) => op !== 'ListPurchaseOrderLines';
    try {
      expect(addOf(await render([]))).toBeNull();
      expect(v.catalogueReads).toEqual([]);
    } finally {
      v.byOperation = null;
    }
  });

  it('every other status, and a deleted draft: no Add, nothing read, whatever the viewer holds', async () => {
    try {
      for (const status of ['ordered', 'partially_received', 'received', 'cancelled']) {
        v.status = status;
        expect(addOf(await render(BUYER))).toBeNull();
        expect(v.catalogueReads).toEqual([]);
      }
      v.status = 'draft';
      v.archived = true;
      expect(addOf(await render(BUYER))).toBeNull();
      expect(v.catalogueReads).toEqual([]);
    } finally {
      v.status = 'draft';
      v.archived = false;
    }
  });

  it('a 401 on the catalogue read → the session-ended notice; a 403 or another failure is the row’s own state', async () => {
    try {
      v.catalogue = 'session-ended';
      await expect(render(BUYER)).rejects.toThrow(encodeURIComponent(`/purchase-orders/${ORDER_ID}`));
      v.catalogue = 'forbidden';
      expect(addOf(await render(BUYER))).toEqual({ catalogue: { kind: 'forbidden' } });
      v.catalogue = 'server-error';
      expect(addOf(await render(BUYER))).toEqual({ catalogue: { kind: 'server-error' } });
    } finally {
      v.catalogue = 'ok';
    }
  });
});
