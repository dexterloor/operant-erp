import { isValidElement, type ReactElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

// Suppliers S-1 (suppliers-plan.md §R1-4 S-1 tests 3, 11 and 14 over Revision 0 §2.1, §2.2): the supplier page offers its
// editor only to an `UpdateSupplier` holder and Restore supplier only to a `RestoreSupplier` holder, each told apart from
// the other and from the read. The page tests inject `canEdit` / `canRestore`; this one reads them off the server page,
// with the viewer's codes as the only input. The list page: its gate, the address's view (`?archived=true` kept on
// reload, never a cursor or a search), and 401 / 403 / 404 / a non-record id.

const v = vi.hoisted(() => ({ byOperation: null as ((operation: string) => boolean) | null, codes: [] as string[], listCalls: [] as unknown[][], supplier: 'ok' as 'ok' | 'not-found' | 'session-ended' | 'forbidden', list: 'ok' as 'ok' | 'session-ended' | 'forbidden' }));
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
// RestoreSupplier and DeleteSupplier share one code (supplier.delete), and UpdateSupplier and CreateSupplier sit beside
// it in every default role, so codes alone cannot tell which operation a prop was gated on. While `byOperation` is set,
// the gate answers by operation name instead.
vi.mock('@/lib/bff/surface', async (actual) => {
  const real = await actual<typeof import('@/lib/bff/surface')>();
  return { ...real, viewerMay: (operation: Parameters<typeof real.viewerMay>[0], codes: Parameters<typeof real.viewerMay>[1]) => (v.byOperation ? v.byOperation(operation) : real.viewerMay(operation, codes)) };
});
const SUPPLIER = { id: '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4e', code: 'ACME', name: 'Acme Supplies', country_code: 'GB', currency_code: 'GBP', payment_terms_days: 30, archived_at: null };
vi.mock('@/lib/suppliers/load', () => ({
  loadSupplier: async () => (v.supplier === 'ok' ? { kind: 'ok', supplier: SUPPLIER } : { kind: v.supplier }),
  loadSuppliers: async (...args: unknown[]) => {
    v.listCalls.push(args);
    return v.list === 'ok' ? { kind: 'ok', items: [], nextCursor: null, previousCursor: null, tenantHasRows: false } : { kind: v.list };
  },
}));

const { default: Page, generateMetadata } = await import('@/app/(app)/suppliers/[id]/page');
const { default: ListPage, metadata } = await import('@/app/(app)/suppliers/page');
const { SuppliersDenied } = await import('@/app/(app)/suppliers/SupplierStates');
const { SuppliersList } = await import('@/app/(app)/suppliers/SuppliersList');
const { SupplierPage } = await import('@/app/(app)/suppliers/[id]/SupplierPage');

async function render(codes: string[]): Promise<ReactElement> {
  v.codes = codes;
  v.supplier = 'ok';
  const el = await Page({ params: Promise.resolve({ id: SUPPLIER.id }) });
  expect(isValidElement(el)).toBe(true);
  return el as ReactElement;
}
const props = (el: ReactElement) => el.props as { canEdit: boolean; canRestore: boolean };

describe('/suppliers/[id] — Save follows UpdateSupplier, Restore supplier follows RestoreSupplier (test 11, ST-4c)', () => {
  it('supplier.view alone (the viewer shape): the page, no editor, no Restore supplier', async () => {
    const el = await render(['supplier.view']);
    expect(el.type).toBe(SupplierPage);
    expect(props(el)).toMatchObject({ canEdit: false, canRestore: false });
  });

  it('supplier.edit without supplier.delete: the editor, no Restore supplier', async () => {
    expect(props(await render(['supplier.view', 'supplier.edit', 'supplier.create']))).toMatchObject({ canEdit: true, canRestore: false });
  });

  it('supplier.delete without supplier.edit: Restore supplier, no editor', async () => {
    expect(props(await render(['supplier.view', 'supplier.delete', 'supplier.create']))).toMatchObject({ canEdit: false, canRestore: true });
  });

  it.each([
    ['UpdateSupplier', { canEdit: true, canRestore: false }],
    ['RestoreSupplier', { canEdit: false, canRestore: true }],
  ] as const)('the page gates each offer on its own operation: only GetSupplier and %s granted', async (operation, expected) => {
    v.byOperation = (op) => op === 'GetSupplier' || op === operation;
    try {
      expect(props(await render([]))).toEqual(expect.objectContaining(expected));
    } finally {
      v.byOperation = null;
    }
  });

  it('without supplier.view the page is ST-4b, even with every write code', async () => {
    expect((await render(['supplier.edit', 'supplier.delete', 'supplier.create'])).type).toBe(SuppliersDenied);
  });

  // Test 14.
  it('test 14 — 401 → the session-ended notice for this address; 403 → ST-4b; 404 → ST-5; a non-record id → ST-5', async () => {
    v.codes = ['supplier.view'];
    v.supplier = 'session-ended';
    await expect(Page({ params: Promise.resolve({ id: SUPPLIER.id }) })).rejects.toThrow('redirect');
    await expect(Page({ params: Promise.resolve({ id: SUPPLIER.id }) })).rejects.toThrow(encodeURIComponent(`/suppliers/${SUPPLIER.id}`));
    v.supplier = 'forbidden';
    expect(((await Page({ params: Promise.resolve({ id: SUPPLIER.id }) })) as ReactElement).type).toBe(SuppliersDenied);
    v.supplier = 'not-found';
    await expect(Page({ params: Promise.resolve({ id: SUPPLIER.id }) })).rejects.toThrow('not found');
    v.supplier = 'ok';
    await expect(Page({ params: Promise.resolve({ id: 'not-a-uuid' }) })).rejects.toThrow('not found');
  });

  it('the page title is "{code} — {name}"; without the read it is the list heading', async () => {
    v.codes = ['supplier.view'];
    v.supplier = 'ok';
    expect((await generateMetadata({ params: Promise.resolve({ id: SUPPLIER.id }) })).title).toBe('ACME — Acme Supplies');
    v.codes = [];
    expect((await generateMetadata({ params: Promise.resolve({ id: SUPPLIER.id }) })).title).toBe('Suppliers');
  });
});

describe('/suppliers — the list page', () => {
  const at = async (codes: string[], searchParams: Record<string, string>) => {
    v.codes = codes;
    v.list = 'ok';
    v.listCalls = [];
    return (await ListPage({ searchParams: Promise.resolve(searchParams) })) as ReactElement;
  };

  it('the title is "Suppliers"', () => {
    expect(metadata.title).toBe('Suppliers');
  });

  it('without supplier.view it is ST-4b and reads nothing', async () => {
    expect((await at(['supplier.edit'], {})).type).toBe(SuppliersDenied);
    expect(v.listCalls).toEqual([]);
  });

  // Test 3's reload half.
  it('test 3 — ?archived=true is kept on reload: the first read is the Archived view, page 1, no search, no cursor', async () => {
    const el = await at(['supplier.view'], { archived: 'true', q: 'A', after: 'x' });
    expect(el.type).toBe(SuppliersList);
    expect((el.props as { initialArchived: boolean }).initialArchived).toBe(true);
    expect(v.listCalls).toEqual([['read', true, { q: '', cursor: null }]]);
  });

  it('anything but ?archived=true is the live view, page 1', async () => {
    const el = await at(['supplier.view'], { archived: 'yes', q: 'A', before: 'y' });
    expect((el.props as { initialArchived: boolean }).initialArchived).toBe(false);
    expect(v.listCalls).toEqual([['read', false, { q: '', cursor: null }]]);
  });

  it('test 14 — 401 → the session-ended notice for /suppliers (the view kept); 403 → ST-4b', async () => {
    v.codes = ['supplier.view'];
    v.list = 'session-ended';
    await expect(ListPage({ searchParams: Promise.resolve({ archived: 'true' }) })).rejects.toThrow('redirect');
    await expect(ListPage({ searchParams: Promise.resolve({ archived: 'true' }) })).rejects.toThrow(encodeURIComponent('/suppliers?archived=true'));
    v.list = 'forbidden';
    expect(((await ListPage({ searchParams: Promise.resolve({}) })) as ReactElement).type).toBe(SuppliersDenied);
  });
});

// Suppliers S-2 (suppliers-plan.md §R1-4 S-2): the list offers the create link on CreateSupplier, and `/suppliers/new`
// checks GetSupplier first (a creator who cannot view suppliers would land on ST-4b right after a create: the warehouse
// 4.3 RED M-1 precedent), then CreateSupplier (ST-4b naming new suppliers).
const { default: NewPage, metadata: newMetadata } = await import('@/app/(app)/suppliers/new/page');
const { SupplierCreateDenied } = await import('@/app/(app)/suppliers/SupplierStates');
const { NewSupplierPage } = await import('@/app/(app)/suppliers/new/NewSupplierPage');

describe('S-2 — the create gates', () => {
  it('the list passes canCreate from CreateSupplier alone', async () => {
    v.list = 'ok';
    for (const [granted, expected] of [[true, true], [false, false]] as const) {
      v.byOperation = (op) => op === 'ListSuppliers' || (granted && op === 'CreateSupplier');
      try {
        const el = (await ListPage({ searchParams: Promise.resolve({}) })) as ReactElement;
        expect(el.type).toBe(SuppliersList);
        expect((el.props as { canCreate: boolean }).canCreate).toBe(expected);
      } finally {
        v.byOperation = null;
      }
    }
  });

  it('/suppliers/new: title New supplier; without GetSupplier → ST-4b for suppliers (even with CreateSupplier); without CreateSupplier → ST-4b for new suppliers; with both → the page', async () => {
    expect(newMetadata.title).toBe('New supplier');
    const at = async (ops: string[]) => {
      v.byOperation = (op) => ops.includes(op);
      try {
        return ((await NewPage()) as ReactElement).type;
      } finally {
        v.byOperation = null;
      }
    };
    expect(await at(['CreateSupplier'])).toBe(SuppliersDenied);
    expect(await at(['GetSupplier'])).toBe(SupplierCreateDenied);
    expect(await at(['GetSupplier', 'CreateSupplier'])).toBe(NewSupplierPage);
  });

  it('the default viewer shape (supplier.view alone) is refused the create page; the buyer shape (view + create) reaches it', async () => {
    v.codes = ['supplier.view'];
    expect(((await NewPage()) as ReactElement).type).toBe(SupplierCreateDenied);
    v.codes = ['supplier.view', 'supplier.create'];
    expect(((await NewPage()) as ReactElement).type).toBe(NewSupplierPage);
  });
});
