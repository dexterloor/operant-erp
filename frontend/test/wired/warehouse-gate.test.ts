import { isValidElement, type ReactElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

// RED r7-step41 M-2 (ST-4c): the warehouse page offers its editor only to an `UpdateWarehouse` holder. The page tests
// inject `canEdit`; this one reads it off the server page, with the viewer's codes as the only input.

const v = vi.hoisted(() => ({ codes: [] as string[], locationReads: 0 }));
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
vi.mock('@/lib/warehouses/load', () => ({
  loadWarehouse: async () => ({ kind: 'ok', warehouse: MAIN }),
  loadWarehouses: async () => ({ kind: 'ok', warehouses: [], tenantHasRows: false }),
  loadLocations: async () => {
    v.locationReads += 1;
    return { kind: 'ok', locations: [] };
  },
}));

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

// §2 step 4.6: Delete warehouse on its own operation (DeleteWarehouse), told apart from every neighbouring code — each
// refused shape holds a neighbour (edit, create, storage_location.delete) but not the gate's own.
describe('/warehouses/[id] — the delete gate is DeleteWarehouse’s (ST-4c, step 4.6)', () => {
  const canDelete = (el: ReactElement) => (el.props as { canDelete: boolean }).canDelete;
  it('neighbouring codes alone get no Delete warehouse', async () => {
    expect(canDelete(await render(['warehouse.view', 'warehouse.edit', 'warehouse.create', 'storage_location.view', 'storage_location.delete']))).toBe(false);
  });
  it('a holder of warehouse.delete gets Delete warehouse', async () => {
    expect(canDelete(await render(['warehouse.view', 'warehouse.delete']))).toBe(true);
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

// Revision 7 A-20 (§2 step 4.4): the Locations section is read and rendered only for a ListStorageLocations holder;
// each row action is gated on its own operation. One case per default-role shape, built from codes, never role names.
describe('/warehouses/[id] — the Locations section and its actions are gated per operation (A-20, step 4.4)', () => {
  type Locs = { canEdit: boolean; canRestore: boolean; canCreate: boolean } | null;
  const locs = (el: ReactElement) => (el.props as { locations: Locs }).locations;
  const at = async (codes: string[]) => {
    v.locationReads = 0;
    const el = await render(codes);
    return { locations: locs(el), reads: v.locationReads };
  };

  it('buyer shape (warehouse.view, no storage_location.view): no section and no ListStorageLocations read', async () => {
    expect(await at(['warehouse.view', 'purchase_order.view'])).toEqual({ locations: null, reads: 0 });
  });

  it('viewer shape (storage_location.view only): the section, read once, with no Edit and no Restore location', async () => {
    expect(await at(['warehouse.view', 'storage_location.view'])).toEqual({ locations: { initial: { kind: 'ok', locations: [] }, canEdit: false, canRestore: false, canCreate: false, canDelete: false }, reads: 1 });
  });

  it('warehouse_operator shape (storage_location.view + .edit): Edit, no Restore location', async () => {
    const r = await at(['warehouse.view', 'warehouse.edit', 'storage_location.view', 'storage_location.edit']);
    expect(r.reads).toBe(1);
    expect(r.locations).toMatchObject({ canEdit: true, canRestore: false });
  });

  it('storage_location.delete holder: Restore location', async () => {
    expect((await at(['warehouse.view', 'storage_location.view', 'storage_location.delete'])).locations).toMatchObject({ canEdit: false, canRestore: true });
  });

  // RED M-2: each action on its own operation (F-13), told apart from the warehouse's own codes.
  it('Edit follows storage_location.edit, not warehouse.edit (each action on its own operation)', async () => {
    expect((await at(['warehouse.view', 'warehouse.edit', 'storage_location.view'])).locations).toMatchObject({ canEdit: false });
    expect((await at(['warehouse.view', 'storage_location.view', 'storage_location.edit'])).locations).toMatchObject({ canEdit: true });
  });

  it('Restore location follows storage_location.delete, not warehouse.delete', async () => {
    expect((await at(['warehouse.view', 'warehouse.delete', 'storage_location.view'])).locations).toMatchObject({ canRestore: false });
  });

  // §2 step 4.5: Add location on its own operation (CreateStorageLocation = storage_location.create), told apart from
  // every other location code and from warehouse.create — each shape holds one neighbouring code but not the gate's.
  it('Add location follows storage_location.create alone', async () => {
    expect((await at(['warehouse.view', 'storage_location.view', 'storage_location.edit', 'storage_location.delete'])).locations).toMatchObject({ canCreate: false });
    expect((await at(['warehouse.view', 'warehouse.create', 'storage_location.view'])).locations).toMatchObject({ canCreate: false });
    expect((await at(['warehouse.view', 'storage_location.view', 'storage_location.create'])).locations).toMatchObject({ canCreate: true, canEdit: false, canRestore: false });
  });

  // §2 step 4.6: Delete location on its own operation (DeleteStorageLocation), told apart from warehouse.delete and from
  // the other location codes; the delete holder gets it without Edit or Add.
  it('Delete location follows DeleteStorageLocation alone', async () => {
    expect((await at(['warehouse.view', 'warehouse.delete', 'storage_location.view', 'storage_location.edit', 'storage_location.create'])).locations).toMatchObject({ canDelete: false });
    expect((await at(['warehouse.view', 'storage_location.view', 'storage_location.delete'])).locations).toMatchObject({ canDelete: true, canEdit: false, canCreate: false });
  });

  it('warehouse_operator shape: no Add location (it edits but does not create, B-5)', async () => {
    expect((await at(['warehouse.view', 'warehouse.edit', 'storage_location.view', 'storage_location.edit'])).locations).toMatchObject({ canCreate: false });
  });
});
