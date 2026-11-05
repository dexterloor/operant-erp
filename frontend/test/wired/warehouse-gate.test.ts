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
const MAIN = { id: '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d', code: 'MAIN', name: 'Main', country_code: 'GB', archived_at: null };
vi.mock('@/lib/warehouses/load', () => ({ loadWarehouse: async () => ({ kind: 'ok', warehouse: MAIN }) }));

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
