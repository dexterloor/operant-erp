import { isValidElement, type ReactElement } from 'react';
import { render as renderDom } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// Items I-1 (items-plan.md §4 I-1 "edit gate per operation", 4.4 RED M-2): the item page offers its editor only to an
// `UpdateItem` holder and Restore item only to a `RestoreItem` holder, each told apart from the other and from the read.
// The page tests inject `canEdit` / `canRestore`; this one reads them off the server page, with the viewer's codes as the
// only input. The list page: its gate, the address's view (`?archived=true` kept on reload, never a cursor or a search:
// R8-1 item 6, UX R1-15), and 401 / 403 / 404.

const v = vi.hoisted(() => ({ byOperation: null as ((operation: string) => boolean) | null, codes: [] as string[], listCalls: [] as unknown[][], item: 'ok' as 'ok' | 'not-found' | 'session-ended' | 'forbidden', list: 'ok' as 'ok' | 'session-ended' | 'forbidden' }));
vi.mock('@/app/(app)/currentViewer', async (actual) => ({
  ...(await actual<typeof import('@/app/(app)/currentViewer')>()),
  currentViewer: async () => ({ session: { sid: 's' }, viewer: { id: 'v', permissionCodes: v.codes } }),
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
// i3-c1 N-1: RestoreItem and DeleteItem share one code (inventory_item.delete), so a viewer's codes cannot tell which
// operation a prop was gated on. While `byOperation` is set, the gate answers by operation name instead.
vi.mock('@/lib/bff/surface', async (actual) => {
  const real = await actual<typeof import('@/lib/bff/surface')>();
  return { ...real, viewerMay: (operation: Parameters<typeof real.viewerMay>[0], codes: Parameters<typeof real.viewerMay>[1]) => (v.byOperation ? v.byOperation(operation) : real.viewerMay(operation, codes)) };
});
const ITEM = { id: '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d', sku: 'A-1', name: 'Bolt', unit_of_measure_code: 'EA', reorder_point: '0.000000', reorder_quantity: '0.000000', safety_stock: '0.000000', supplier: null, archived_at: null, holds_stock: false };
vi.mock('@/lib/items/load', () => ({
  loadItem: async () => (v.item === 'ok' ? { kind: 'ok', item: ITEM } : { kind: v.item }),
  loadItems: async (...args: unknown[]) => {
    v.listCalls.push(args);
    return v.list === 'ok' ? { kind: 'ok', items: [], nextCursor: null, previousCursor: null, tenantHasRows: false } : { kind: v.list };
  },
}));

const { default: Page } = await import('@/app/(app)/items/[id]/page');
const { default: ListPage } = await import('@/app/(app)/items/page');
const { default: NewPage } = await import('@/app/(app)/items/new/page');
const { NewItemPage } = await import('@/app/(app)/items/new/NewItemPage');
const { ItemCreateDenied, ItemsDenied } = await import('@/app/(app)/items/ItemStates');
const { ItemsList } = await import('@/app/(app)/items/ItemsList');

async function render(codes: string[]): Promise<ReactElement> {
  v.codes = codes;
  v.item = 'ok';
  const el = await Page({ params: Promise.resolve({ id: ITEM.id }) });
  expect(isValidElement(el)).toBe(true);
  return el as ReactElement;
}
const props = (el: ReactElement) => el.props as { canEdit: boolean; canRestore: boolean; canDelete: boolean; canListMovements: boolean };

describe('/items/[id] — Save follows UpdateItem, Restore item follows RestoreItem (ST-4c, each on its own operation)', () => {
  it('inventory_item.view alone: no editor, no Restore item', async () => {
    expect(props(await render(['inventory_item.view']))).toMatchObject({ canEdit: false, canRestore: false });
  });

  it('UpdateItem without RestoreItem: the editor, no Restore item', async () => {
    expect(props(await render(['inventory_item.view', 'inventory_item.edit', 'inventory_item.create']))).toMatchObject({ canEdit: true, canRestore: false });
  });

  it('RestoreItem without UpdateItem: Restore item, no editor', async () => {
    expect(props(await render(['inventory_item.view', 'inventory_item.delete', 'inventory_item.create']))).toMatchObject({ canEdit: false, canRestore: true });
  });

  // Items I-3 T-3 (final RED / first RED L-6): Delete item follows DeleteItem's codes, told apart from UpdateItem's.
  it('UpdateItem without DeleteItem (inventory_item.view + inventory_item.edit, no inventory_item.delete): the editor, no Delete item', async () => {
    expect(props(await render(['inventory_item.view', 'inventory_item.edit']))).toMatchObject({ canEdit: true, canDelete: false });
  });

  it('with inventory_item.delete: Delete item', async () => {
    expect(props(await render(['inventory_item.view', 'inventory_item.delete']))).toMatchObject({ canDelete: true });
    expect(props(await render(['inventory_item.view']))).toMatchObject({ canDelete: false });
  });

  // i3-c1 N-1: each offer is gated on its own operation, by name. The gate answers yes for GetItem and ONE write
  // operation only; exactly the prop of that operation must be true.
  it.each([
    ['UpdateItem', { canEdit: true, canRestore: false, canDelete: false }],
    ['RestoreItem', { canEdit: false, canRestore: true, canDelete: false }],
    ['DeleteItem', { canEdit: false, canRestore: false, canDelete: true }],
  ] as const)('the page gates each offer on its own operation: only %s granted', async (operation, expected) => {
    v.byOperation = (op) => op === 'GetItem' || op === operation;
    try {
      expect(props(await render([]))).toEqual(expect.objectContaining(expected));
    } finally {
      v.byOperation = null;
    }
  });

  // Stock ST-1 (stock-plan.md §2.4; M18): the movements link follows ListStockMovements' codes, and nothing else.
  it('the link to this item’s movements follows ListStockMovements (stock_movement.view)', async () => {
    expect(props(await render(['inventory_item.view', 'stock_movement.view']))).toMatchObject({ canListMovements: true });
    expect(props(await render(['inventory_item.view', 'stock_level.view', 'goods_receipt.view']))).toMatchObject({ canListMovements: false });
  });

  it('without inventory_item.view the page is ST-4b, even with every write code', async () => {
    expect((await render(['inventory_item.edit', 'inventory_item.delete'])).type).toBe(ItemsDenied);
  });

  it('401 → the session-ended notice for this address; 403 → ST-4b; 404 → ST-5; a non-record id → ST-5', async () => {
    v.codes = ['inventory_item.view'];
    v.item = 'session-ended';
    await expect(Page({ params: Promise.resolve({ id: ITEM.id }) })).rejects.toThrow(`redirect`);
    await expect(Page({ params: Promise.resolve({ id: ITEM.id }) })).rejects.toThrow(encodeURIComponent(`/items/${ITEM.id}`));
    v.item = 'forbidden';
    expect(((await Page({ params: Promise.resolve({ id: ITEM.id }) })) as ReactElement).type).toBe(ItemsDenied);
    v.item = 'not-found';
    await expect(Page({ params: Promise.resolve({ id: ITEM.id }) })).rejects.toThrow('not found');
    v.item = 'ok';
    await expect(Page({ params: Promise.resolve({ id: 'not-a-uuid' }) })).rejects.toThrow('not found');
  });
});

describe('/items — the list page', () => {
  const at = async (codes: string[], searchParams: Record<string, string>) => {
    v.codes = codes;
    v.list = 'ok';
    v.listCalls = [];
    return (await ListPage({ searchParams: Promise.resolve(searchParams) })) as ReactElement;
  };

  it('without inventory_item.view it is ST-4b and reads nothing', async () => {
    expect((await at(['inventory_item.edit'], {})).type).toBe(ItemsDenied);
    expect(v.listCalls).toEqual([]);
  });

  it('?archived=true is kept on reload: the first read is the Archived view, page 1, no search, no cursor', async () => {
    const el = await at(['inventory_item.view'], { archived: 'true', q: 'A', after: 'x' });
    expect(el.type).toBe(ItemsList);
    expect((el.props as { initialArchived: boolean }).initialArchived).toBe(true);
    expect(v.listCalls).toEqual([['read', true, { q: '', cursor: null }]]);
  });

  it('anything but ?archived=true is the live view, page 1 (a reload or back opens page 1 with an empty box)', async () => {
    const el = await at(['inventory_item.view'], { archived: 'yes', q: 'A', before: 'y' });
    expect((el.props as { initialArchived: boolean }).initialArchived).toBe(false);
    expect(v.listCalls).toEqual([['read', false, { q: '', cursor: null }]]);
  });

  it('401 → the session-ended notice for /items (the view kept); 403 → ST-4b', async () => {
    v.codes = ['inventory_item.view'];
    v.list = 'session-ended';
    await expect(ListPage({ searchParams: Promise.resolve({ archived: 'true' }) })).rejects.toThrow('redirect');
    // RED M-3 b: the return path keeps the view.
    await expect(ListPage({ searchParams: Promise.resolve({ archived: 'true' }) })).rejects.toThrow(encodeURIComponent('/items?archived=true'));
    v.list = 'forbidden';
    expect(((await ListPage({ searchParams: Promise.resolve({}) })) as ReactElement).type).toBe(ItemsDenied);
  });
});

// Items I-2 (items-i2-design.md Revision 1 — FINAL A-8, T-6; Final RED FR-L3): `/items/new` gates on `GetItem` FIRST
// (the warehouses step-4.3 RED M-1 scenario: a creator who cannot view items would land on ST-4b right after a successful
// create), then on `CreateItem`.
describe('/items/new — GetItem first, then CreateItem (A-8)', () => {
  const at = async (codes: string[]) => {
    v.codes = codes;
    return (await NewPage()) as ReactElement;
  };

  it('a creator who cannot view items (inventory_item.create without inventory_item.view): the ITEMS ST-4b (View items), not the new-items one', async () => {
    const el = await at(['inventory_item.create']);
    expect(el.type).toBe(ItemsDenied);
    const { container, unmount } = renderDom(el);
    expect(container.textContent).toContain("You don't have access to items.");
    expect(container.textContent).toContain('View items');
    unmount();
  });

  it('GetItem without CreateItem: the new-items ST-4b, naming Create items', async () => {
    const el = await at(['inventory_item.view', 'inventory_item.edit']);
    expect(el.type).toBe(ItemCreateDenied);
    const { container, unmount } = renderDom(el);
    expect(container.textContent).toContain("You don't have access to new items.");
    expect(container.textContent).toContain('Create items');
    unmount();
  });

  it('both: the form page', async () => {
    expect((await at(['inventory_item.view', 'inventory_item.create'])).type).toBe(NewItemPage);
  });

  it('a viewer holding neither inventory_item.view nor inventory_item.create: the ITEMS ST-4b (GetItem is checked first)', async () => {
    for (const codes of [[], ['warehouse.view']]) expect((await at(codes)).type).toBe(ItemsDenied);
  });
});

describe('/items — canCreate follows CreateItem (A-11)', () => {
  const list = async (codes: string[]) => {
    v.codes = codes;
    v.list = 'ok';
    return (await ListPage({ searchParams: Promise.resolve({}) })) as ReactElement;
  };
  it('with CreateItem the list is told canCreate; without it, not', async () => {
    expect(((await list(['inventory_item.view', 'inventory_item.create'])).props as { canCreate: boolean }).canCreate).toBe(true);
    expect(((await list(['inventory_item.view', 'inventory_item.edit'])).props as { canCreate: boolean }).canCreate).toBe(false);
  });
});
