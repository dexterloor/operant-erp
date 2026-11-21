import { isValidElement, type ReactElement } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Stock ST-1 (stock-plan.md §2.1, §2.2, §2.3, §6.6 as amended by stock-st1-red.md M-1, L-2, L-11; b4-architecture.md
// Revision 11 R11-2, R11-3, R11-4): the two server pages, with the viewer's codes and the address as the only inputs. The
// list page applies M-1's six steps in order; the movement page reads after `isRecordId` only, and makes at most one
// fail-soft secondary read, only for a source the viewer may open.

const ITEM_ID = 'c1b2c3d4-0000-4000-8000-000000000001';
const MOVEMENT_ID = 'a1b2c3d4-0000-4000-8000-000000000001';
const RECEIPT_ID = 'e1b2c3d4-0000-4000-8000-000000000003';
const PARTNER_ID = 'f1b2c3d4-0000-4000-8000-000000000004';
const v = vi.hoisted(() => ({
  codes: [] as string[],
  calls: [] as unknown[][],
  list: 'ok' as 'ok' | 'session-ended' | 'forbidden' | 'server-error',
  listItems: [] as unknown[],
  tenantHasRows: true,
  item: 'ok' as 'ok' | 'session-ended' | 'forbidden' | 'not-found' | 'server-error',
  movement: 'ok' as 'ok' | 'session-ended' | 'forbidden' | 'not-found' | 'server-error',
  movementBody: null as unknown,
  partner: 'ok' as 'ok' | 'session-ended' | 'forbidden' | 'not-found' | 'server-error',
  receipt: 'ok' as 'ok' | 'session-ended' | 'forbidden' | 'not-found' | 'server-error',
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
vi.mock('@/lib/stock/load', async (actual) => {
  const real = await actual<typeof import('@/lib/stock/load')>();
  return {
    ...real,
    loadMovements: async (...args: unknown[]) => {
      v.calls.push(['loadMovements', ...args]);
      return v.list === 'ok' ? { kind: 'ok', items: v.listItems, nextCursor: null, previousCursor: null, tenantHasRows: v.tenantHasRows } : { kind: v.list };
    },
    loadItemName: async (...args: unknown[]) => {
      v.calls.push(['loadItemName', ...args]);
      return v.item === 'ok' ? { kind: 'ok', item: { id: ITEM_ID, sku: 'WID-001', name: 'Widget' } } : { kind: v.item };
    },
    loadMovement: async (...args: unknown[]) => {
      v.calls.push(['loadMovement', ...args]);
      const id = args[1];
      const kind = id === MOVEMENT_ID ? v.movement : v.partner;
      if (kind !== 'ok') return { kind };
      return id === MOVEMENT_ID ? { kind: 'ok', movement: v.movementBody } : { kind: 'ok', movement: { ...(v.movementBody as object), id: PARTNER_ID, storage_location: { id: 'l2', code: 'B-02' } } };
    },
    loadReceiptNumber: async (...args: unknown[]) => {
      v.calls.push(['loadReceiptNumber', ...args]);
      return v.receipt === 'ok' ? { kind: 'ok', receiptNumber: 'GR-000123' } : { kind: v.receipt };
    },
  };
});

const { default: ListPage, metadata } = await import('@/app/(app)/stock-movements/page');
const { default: MovementPage, generateMetadata } = await import('@/app/(app)/stock-movements/[id]/page');
const { StockMovementsDenied, StockMovementsUnknownItem } = await import('@/app/(app)/stock-movements/StockMovementStates');
const { StockMovementsList } = await import('@/app/(app)/stock-movements/StockMovementsList');
const { StockMovementPage } = await import('@/app/(app)/stock-movements/[id]/StockMovementPage');

const ROW = { id: MOVEMENT_ID, quantity: '12.000000', occurred_at: '2026-03-04T11:00:00Z', reason: 'receipt', source_type: 'goods_receipt', source_id: RECEIPT_ID, inventory_item: { id: ITEM_ID, sku: 'WID-001', name: 'Widget' }, storage_location: { id: 'l1', code: 'A-01' } };
const VIEW = ['stock_movement.view'];

beforeEach(() => {
  v.calls = [];
  v.list = 'ok';
  v.listItems = [];
  v.tenantHasRows = true;
  v.item = 'ok';
  v.movement = 'ok';
  v.movementBody = ROW;
  v.partner = 'ok';
  v.receipt = 'ok';
});

const list = async (codes: string[], query: Record<string, string | string[] | undefined> = {}) => {
  v.codes = codes;
  const el = await ListPage({ searchParams: Promise.resolve(query) });
  expect(isValidElement(el)).toBe(true);
  return el as ReactElement<Record<string, unknown>>;
};
const movementPage = async (codes: string[], id = MOVEMENT_ID) => {
  v.codes = codes;
  return (await MovementPage({ params: Promise.resolve({ id }) })) as ReactElement<Record<string, unknown>>;
};
const reads = (name: string) => v.calls.filter((c) => c[0] === name);

describe('/stock-movements — the list page (M-1’s order)', () => {
  it('without stock_movement.view it is ST-4b, whatever else is held; nothing is read', async () => {
    expect((await list(['inventory_item.view', 'goods_receipt.view', 'stock_level.view'])).type).toBe(StockMovementsDenied);
    expect(v.calls).toEqual([]);
  });

  it('unfiltered: page 1, no cursor, no search, NO filter; the element is keyed "all"', async () => {
    v.listItems = [ROW];
    const el = await list(VIEW);
    expect(el.type).toBe(StockMovementsList);
    expect(el.key).toBe('all');
    expect(v.calls).toEqual([['loadMovements', 'read', { q: '', cursor: null }, undefined, undefined]]);
    expect(el.props).toMatchObject({ itemId: null, itemName: null });
  });

  it('step 1: a malformed, repeated or empty inventory_item_id is the unknown-item state and nothing is read', async () => {
    for (const bad of ['not-a-uuid', '', [ITEM_ID, ITEM_ID], ITEM_ID.toUpperCase()]) {
      v.calls = [];
      expect((await list(VIEW, { inventory_item_id: bad })).type).toBe(StockMovementsUnknownItem);
      expect(v.calls).toEqual([]);
    }
  });

  it('steps 2–3: a valid id is the first read’s fixed filter; rows name the item from the first row, with no item read; keyed by the id', async () => {
    v.listItems = [ROW];
    const el = await list([...VIEW, 'inventory_item.view'], { inventory_item_id: ITEM_ID });
    expect(v.calls).toEqual([['loadMovements', 'read', { q: '', cursor: null }, undefined, { name: 'inventory_item_id', value: ITEM_ID }]]);
    expect(el.key).toBe(ITEM_ID);
    expect(el.props).toMatchObject({ itemId: ITEM_ID, itemName: { sku: 'WID-001', name: 'Widget' } });
    expect(reads('loadItemName')).toEqual([]);
  });

  it('step 2: 401 → the session-ended notice WITH the query; 403 → ST-4b; any other failure → the list (its region error), unnamed', async () => {
    v.list = 'session-ended';
    await expect(list(VIEW, { inventory_item_id: ITEM_ID })).rejects.toThrow(encodeURIComponent(`/stock-movements?inventory_item_id=${ITEM_ID}`));
    v.list = 'forbidden';
    expect((await list(VIEW)).type).toBe(StockMovementsDenied);
    v.list = 'server-error';
    const el = await list([...VIEW, 'inventory_item.view'], { inventory_item_id: ITEM_ID });
    expect(el.type).toBe(StockMovementsList);
    expect(el.props).toMatchObject({ initial: { kind: 'server-error' }, itemName: null });
    expect(reads('loadItemName')).toEqual([]);
  });

  it('step 4 (M24): empty and the workspace has no movement → no item read, even filtered and holding GetItem', async () => {
    v.tenantHasRows = false;
    const el = await list([...VIEW, 'inventory_item.view'], { inventory_item_id: ITEM_ID });
    expect(reads('loadItemName')).toEqual([]);
    expect(el.props).toMatchObject({ itemId: ITEM_ID, itemName: null, initial: { kind: 'ok', tenantHasRows: false } });
  });

  it('step 5: empty, the workspace has movements, filtered, GetItem held → one narrow item read: ok names it; 404 → unknown item; 401 → session ended; other → unnamed', async () => {
    const el = await list([...VIEW, 'inventory_item.view'], { inventory_item_id: ITEM_ID });
    expect(reads('loadItemName')).toEqual([['loadItemName', 'read', ITEM_ID]]);
    expect(el.props).toMatchObject({ itemName: { sku: 'WID-001', name: 'Widget' } });
    v.item = 'not-found';
    expect((await list([...VIEW, 'inventory_item.view'], { inventory_item_id: ITEM_ID })).type).toBe(StockMovementsUnknownItem);
    v.item = 'session-ended';
    // Commit RED L-2 (M34): the item read's 401 keeps the query, so the filter survives sign-in.
    await expect(list([...VIEW, 'inventory_item.view'], { inventory_item_id: ITEM_ID })).rejects.toThrow(encodeURIComponent('/stock-movements?inventory_item_id=' + ITEM_ID));
    for (const k of ['forbidden', 'server-error'] as const) {
      v.item = k;
      expect((await list([...VIEW, 'inventory_item.view'], { inventory_item_id: ITEM_ID })).props).toMatchObject({ itemName: null });
    }
  });

  it('step 6 and M19: no GetItem → no item read; a non-empty filtered page never reads the item; an unfiltered empty one neither', async () => {
    await list(VIEW, { inventory_item_id: ITEM_ID });
    expect(reads('loadItemName')).toEqual([]);
    v.listItems = [ROW];
    await list([...VIEW, 'inventory_item.view'], { inventory_item_id: ITEM_ID });
    expect(reads('loadItemName')).toEqual([]);
    v.listItems = [];
    await list([...VIEW, 'inventory_item.view']);
    expect(reads('loadItemName')).toEqual([]);
  });

  it('the gates are server-computed booleans (RED L-2): source links by GetGoodsReceipt and GetStockMovement, items by GetItem, noneYetLink by ListPurchaseOrders', async () => {
    expect((await list(VIEW)).props).toMatchObject({ gates: { goodsReceipt: false, movement: true }, canOpenItem: false, canListOrders: false });
    expect((await list([...VIEW, 'goods_receipt.view', 'inventory_item.view', 'purchase_order.view'])).props).toMatchObject({ gates: { goodsReceipt: true, movement: true }, canOpenItem: true, canListOrders: true });
  });

  it('the title is "Stock movements"', () => {
    expect(metadata.title).toBe('Stock movements');
  });
});

describe('/stock-movements/[id] — the movement page', () => {
  it('without stock_movement.view it is ST-4b and nothing is read', async () => {
    expect((await movementPage(['goods_receipt.view', 'inventory_item.view'])).type).toBe(StockMovementsDenied);
    expect(v.calls).toEqual([]);
  });

  it('a non-record id (the count’s and the transfer’s path words included) is ST-5 with no read (M11)', async () => {
    for (const id of ['adjustments', 'transfers', 'not-a-uuid', MOVEMENT_ID.toUpperCase()]) {
      v.calls = [];
      await expect(movementPage(VIEW, id)).rejects.toThrow('not found');
      expect(v.calls).toEqual([]);
    }
  });

  it('401 → the session-ended notice for this address; 403 → ST-4b; 404 → ST-5; any other failure throws', async () => {
    v.movement = 'session-ended';
    await expect(movementPage(VIEW)).rejects.toThrow(encodeURIComponent(`/stock-movements/${MOVEMENT_ID}`));
    v.movement = 'forbidden';
    expect((await movementPage(VIEW)).type).toBe(StockMovementsDenied);
    v.movement = 'not-found';
    await expect(movementPage(VIEW)).rejects.toThrow('not found');
    v.movement = 'server-error';
    await expect(movementPage(VIEW)).rejects.toThrow('The stock movement could not be read.');
  });

  it('a receipt movement: the receipt number is read only with GetGoodsReceipt (M17); a secondary failure is fail-soft, a secondary 401 ends the session', async () => {
    let el = await movementPage(VIEW);
    expect(el.type).toBe(StockMovementPage);
    expect(reads('loadReceiptNumber')).toEqual([]);
    expect(el.props).toMatchObject({ receiptNumber: null, source: { kind: 'text', word: 'goodsReceipt' } });
    el = await movementPage([...VIEW, 'goods_receipt.view']);
    expect(reads('loadReceiptNumber')).toEqual([['loadReceiptNumber', 'read', RECEIPT_ID]]);
    expect(el.props).toMatchObject({ receiptNumber: 'GR-000123', source: { kind: 'link', href: `/goods-receipts/${RECEIPT_ID}` } });
    for (const k of ['forbidden', 'not-found', 'server-error'] as const) {
      v.receipt = k;
      expect((await movementPage([...VIEW, 'goods_receipt.view'])).props).toMatchObject({ receiptNumber: null });
    }
    v.receipt = 'session-ended';
    await expect(movementPage([...VIEW, 'goods_receipt.view'])).rejects.toThrow(encodeURIComponent(`/stock-movements/${MOVEMENT_ID}`));
  });

  it('a transfer leg: its partner is read for its location; fail-soft; a secondary 401 ends the session', async () => {
    v.movementBody = { ...ROW, reason: 'transfer_out', quantity: '-3.000000', source_type: 'stock_movement', source_id: PARTNER_ID };
    const el = await movementPage(VIEW);
    expect(reads('loadMovement').map((c) => c[2])).toEqual([MOVEMENT_ID, PARTNER_ID]);
    expect(el.props).toMatchObject({ partnerLocation: 'B-02', receiptNumber: null, source: { kind: 'link', word: 'matchingMoveIn' } });
    v.partner = 'server-error';
    expect((await movementPage(VIEW)).props).toMatchObject({ partnerLocation: null });
    v.partner = 'session-ended';
    // Commit RED L-2 (M35): the partner read's 401 returns to this page's own address.
    await expect(movementPage(VIEW)).rejects.toThrow(encodeURIComponent('/stock-movements/' + MOVEMENT_ID));
  });

  it('never a secondary read for a shipment, a customer return, a count, an unknown kind, or a source id that is not a record id', async () => {
    for (const [source_type, reason, source_id] of [['fulfilment', 'fulfilment', RECEIPT_ID], ['sales_return', 'return', RECEIPT_ID], [null, 'adjustment', null], ['zzz', 'receipt', RECEIPT_ID], ['goods_receipt', 'receipt', 'not-a-uuid']] as const) {
      v.calls = [];
      v.movementBody = { ...ROW, source_type, reason, source_id };
      await movementPage([...VIEW, 'goods_receipt.view']);
      expect(v.calls.map((c) => c[0])).toEqual(['loadMovement']);
    }
  });

  it('the item link by GetItem; the item’s movements link by ListStockMovements (RED L-11)', async () => {
    expect((await movementPage(VIEW)).props).toMatchObject({ canOpenItem: false, canListMovements: true });
    expect((await movementPage([...VIEW, 'inventory_item.view'])).props).toMatchObject({ canOpenItem: true });
  });

  it('the title is "{label} — {sku}" when readable, else the list heading', async () => {
    v.codes = VIEW;
    expect((await generateMetadata({ params: Promise.resolve({ id: MOVEMENT_ID }) })).title).toBe('Received — WID-001');
    v.codes = [];
    expect((await generateMetadata({ params: Promise.resolve({ id: MOVEMENT_ID }) })).title).toBe('Stock movements');
    v.codes = VIEW;
    expect((await generateMetadata({ params: Promise.resolve({ id: 'adjustments' }) })).title).toBe('Stock movements');
    // Commit RED L-2: a failed main read with the gate held falls back to the list heading.
    v.movement = 'server-error';
    expect((await generateMetadata({ params: Promise.resolve({ id: MOVEMENT_ID }) })).title).toBe('Stock movements');
  });
});
