import { isValidElement, type ReactElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

// RED r7-step41 M-2 (ST-4c): the warehouse page offers its editor only to an `UpdateWarehouse` holder. The page tests
// inject `canEdit`; this one reads it off the server page, with the viewer's codes as the only input.

const v = vi.hoisted(() => ({ codes: [] as string[] }));
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
const MAIN = { id: '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d', code: 'MAIN', name: 'Main', country_code: 'GB', archived_at: '2026-03-04T10:00:00Z' };
vi.mock('@/lib/warehouses/load', () => ({ loadWarehouse: async () => ({ kind: 'ok', warehouse: MAIN }), loadWarehouses: async () => ({ kind: 'ok', warehouses: [], tenantHasRows: false }) }));

const { default: Page } = await import('@/app/(app)/warehouses/[id]/page');
const { WarehousesDenied } = await import('@/app/(app)/warehouses/WarehouseStates');

async function render(codes: string[]): Promise<ReactElement> {
  v.codes = codes;
  const el = await Page({ params: Promise.resolve({ id: MAIN.id }) });
  expect(isValidElement(el)).toBe(true);
  return el as ReactElement;
}

describe('/warehouses/[id] — the edit gate is UpdateWarehouse’s (ST-4c)', () => {
  it('(a) a viewer with warehouse.view only gets no editor', async () => {
    expect(((await render(['warehouse.view'])).props as { canEdit: boolean }).canEdit).toBe(false);
  });

  it('(b) a holder of warehouse.edit gets the editor', async () => {
    expect(((await render(['warehouse.view', 'warehouse.edit'])).props as { canEdit: boolean }).canEdit).toBe(true);
  });

  it('(c) without warehouse.view the page is ST-4b', async () => {
    expect((await render([])).type).toBe(WarehousesDenied);
  });
});

describe('/warehouses/[id] — the restore gate is RestoreWarehouse’s (ST-4c, step 4.2)', () => {
  const canRestore = (el: ReactElement) => (el.props as { canRestore: boolean }).canRestore;

  it('(a) warehouse.view and warehouse.edit alone get no Restore warehouse', async () => {
    expect(canRestore(await render(['warehouse.view', 'warehouse.edit']))).toBe(false);
  });

  it('(b) a holder of warehouse.delete gets Restore warehouse', async () => {
    expect(canRestore(await render(['warehouse.view', 'warehouse.delete']))).toBe(true);
  });
});

describe('/warehouses and /warehouses/new — the create gate is CreateWarehouse’s (ST-4c, step 4.3)', () => {
  it('the list offers create only to a warehouse.create holder', async () => {
    const { default: ListPage } = await import('@/app/(app)/warehouses/page');
    const at = async (codes: string[]) => {
      v.codes = codes;
      const el = (await ListPage({ searchParams: Promise.resolve({}) })) as ReactElement;
      return (el.props as { canCreate: boolean }).canCreate;
    };
    expect(await at(['warehouse.view', 'warehouse.edit', 'warehouse.delete'])).toBe(false);
    expect(await at(['warehouse.view', 'warehouse.create'])).toBe(true);
  });

  it('/warehouses/new without warehouse.create is ST-4b; with it, the page', async () => {
    const { default: NewPage } = await import('@/app/(app)/warehouses/new/page');
    const { WarehouseCreateDenied } = await import('@/app/(app)/warehouses/WarehouseStates');
    const { NewWarehousePage } = await import('@/app/(app)/warehouses/new/NewWarehousePage');
    v.codes = ['warehouse.view', 'warehouse.edit'];
    expect(((await NewPage()) as ReactElement).type).toBe(WarehouseCreateDenied);
    v.codes = ['warehouse.view', 'warehouse.create'];
    expect(((await NewPage()) as ReactElement).type).toBe(NewWarehousePage);
  });

  it('/warehouses/new with warehouse.create alone is the view ST-4b, no form (RED M-1)', async () => {
    const { default: NewPage } = await import('@/app/(app)/warehouses/new/page');
    v.codes = ['warehouse.create'];
    expect(((await NewPage()) as ReactElement).type).toBe(WarehousesDenied);
  });

  it('the create ST-4b reads "You don\u2019t have access to new warehouses." and names Create warehouses (RED M-2)', async () => {
    const { render, screen } = await import('@testing-library/react');
    const { WarehouseCreateDenied } = await import('@/app/(app)/warehouses/WarehouseStates');
    render(WarehouseCreateDenied());
    expect(screen.getByRole('heading', { level: 1 }).textContent).toMatch(/access to new warehouses\./);
    expect(document.body.textContent).toContain('Create warehouses');
  });
});
