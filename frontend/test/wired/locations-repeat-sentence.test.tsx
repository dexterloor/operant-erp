import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { WarehousePage } from '@/app/(app)/warehouses/[id]/WarehousePage';
import type { StorageLocation, Warehouse } from '@/lib/api/warehouses';
import { empty, json, renderWired, stubFetch, textsOf } from './harness';

// FE-R9 (frontend-residuals.md), the Locations rows: `ArchivableList` owns its own status line, so switching between the
// live and archived views writes nothing to the page's region. A row's state changed elsewhere and learned through a view
// switch makes the next Restore / Delete press say what the last one said. The press empties the page's region through
// the page's counted writer (`announce('')`), never around it.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const WID = '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
const MAIN: Warehouse = { id: WID, code: 'MAIN', name: 'Main warehouse', country_code: 'GB', archived_at: null };
const loc = (id: string, code: string, kind: string, archived_at: string | null = null): StorageLocation => ({ id, code, kind, archived_at });
const A1 = loc('11111111-1111-4111-8111-111111111111', 'A1', 'bin');
const Q1 = loc('22222222-2222-4222-8222-222222222222', 'Q1', 'quarantine');
const B1 = loc('44444444-4444-4444-8444-444444444444', 'B1', 'shelf', '2026-04-01T09:00:00Z');
const coll = (items: StorageLocation[]) => ({ items, next_cursor: null, previous_cursor: null, tenant_has_rows: true });
const pageRegion = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const section = () => screen.getByRole('heading', { level: 2 }).closest('section')!;
const dialog = () => screen.getByRole('dialog');
const RESTORED = 'B1 was restored.';
const DELETED = "A1 was deleted. It's now in this warehouse's archived locations.";
const show = () => {
  const locations = { initial: { kind: 'ok' as const, locations: [A1, Q1] }, canEdit: false, canRestore: true, canCreate: false, canDelete: true };
  return renderWired(<WarehousePage initial={MAIN} canEdit={false} canRestore={false} locations={locations} />);
};
const view = async (u: ReturnType<typeof userEvent.setup>, name: 'Archived' | 'Back to locations', heading: string) => {
  await u.click(within(section()).getByRole('button', { name }));
  await screen.findByRole('heading', { level: 2, name: heading });
};

describe('/warehouses/[id] Locations — a repeated row sentence is written again (FE-R9)', () => {
  it('Restore B1, B1 archived elsewhere and seen again through a view switch, Restore B1 again: spoken both times', async () => {
    const live = { ...B1, archived_at: null };
    const calls = stubFetch([json(200, coll([B1])), json(200, live), json(200, coll([])), json(200, coll([A1, Q1])), json(200, coll([B1])), json(200, live), json(200, coll([]))]);
    const u = userEvent.setup();
    show();
    const rec = textsOf(pageRegion());
    await view(u, 'Archived', 'Archived locations');
    await u.click(within(section()).getByRole('button', { name: 'Restore location B1' }));
    await waitFor(() => expect(pageRegion()).toHaveTextContent(RESTORED));
    await within(section()).findByText('No archived locations in this warehouse.');
    await view(u, 'Back to locations', 'Locations');
    await view(u, 'Archived', 'Archived locations');
    await u.click(await within(section()).findByRole('button', { name: 'Restore location B1' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['GET', 'POST', 'GET', 'GET', 'GET', 'POST', 'GET']));
    await waitFor(() => expect(rec.texts).toEqual(['', RESTORED, '', RESTORED]));
    rec.stop();
  });

  it('Delete A1, A1 restored elsewhere and seen again through a view switch, Delete A1 again: spoken both times', async () => {
    const calls = stubFetch([empty(204), json(200, coll([Q1])), json(200, coll([])), json(200, coll([A1, Q1])), empty(204), json(200, coll([Q1]))]);
    const u = userEvent.setup();
    show();
    const rec = textsOf(pageRegion());
    await u.click(screen.getByRole('button', { name: 'Delete A1' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(pageRegion()).toHaveTextContent(DELETED));
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Delete A1' })).toBeNull());
    await view(u, 'Archived', 'Archived locations');
    await view(u, 'Back to locations', 'Locations');
    await u.click(await screen.findByRole('button', { name: 'Delete A1' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'GET', 'GET', 'DELETE', 'GET']));
    await waitFor(() => expect(rec.texts).toEqual(['', DELETED, '', DELETED]));
    rec.stop();
  });
});
