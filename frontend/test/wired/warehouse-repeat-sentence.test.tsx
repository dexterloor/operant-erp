import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { WarehousePage } from '@/app/(app)/warehouses/[id]/WarehousePage';
import type { StorageLocation, Warehouse } from '@/lib/api/warehouses';
import { empty, held, json, refused, renderWired, stubFetch, textsOf } from './harness';

// FE-R9 (frontend-residuals.md), the warehouse page's half: a counted page, so the press empties the region through the
// page's counted writer (`announce('')`), never around it. The record's state changes elsewhere and the page learns it
// through a re-read that writes nothing (the Delete dialog's Close, a Retry); the next press says what the last one said.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const WID = '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
const MAIN: Warehouse = { id: WID, code: 'MAIN', name: 'Main warehouse', country_code: 'GB', archived_at: null };
const ARCHIVED: Warehouse = { ...MAIN, archived_at: '2026-11-05T10:00:00Z' };
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const dialog = () => screen.getByRole('dialog');
const RESTORED = 'MAIN was restored.';
const AFTER = "MAIN was deleted. It's now in the archived warehouses.";
const ALREADY = 'This warehouse is already archived.';
const ARCHIVED_BANNER = /This warehouse was archived on/;
const show = (w: Warehouse) => renderWired(<WarehousePage initial={w} canEdit={false} canRestore canDelete />);
const pressDelete = async (u: ReturnType<typeof userEvent.setup>) => {
  await u.click(await screen.findByRole('button', { name: 'Delete warehouse' }));
  await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
};

describe('/warehouses/[id] — a repeated restore or delete sentence is written again (FE-R9)', () => {
  // This route is now covered by both clears: the Delete press empties the region, and so does the second Restore press
  // (fe-r9-red.md M-1). Red when both are dropped.
  it('Restore, an archive made elsewhere learned through the Delete dialog\'s Close, Restore again: spoken both times', async () => {
    const calls = stubFetch([json(200, MAIN), json(200, MAIN), refused('warehouse.is_archived', ALREADY), json(200, ARCHIVED), json(200, MAIN), json(200, MAIN)]);
    const u = userEvent.setup();
    show(ARCHIVED);
    const rec = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Restore warehouse' }));
    await waitFor(() => expect(region()).toHaveTextContent(RESTORED));
    await pressDelete(u);
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Restore warehouse' }));
    await waitFor(() => expect(screen.queryByText(ARCHIVED_BANNER)).toBeNull());
    await waitFor(() => expect(rec.texts).toEqual(['', RESTORED, '', RESTORED]));
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'DELETE', 'GET', 'POST', 'GET']);
    rec.stop();
  });

  // fe-r9-red.md M-1 (Probe P2): no Delete press at all. The Restore press's own re-read fails, so the page shows a region
  // error; the warehouse is archived elsewhere and Retry learns it, writing nothing; Restore again says what the first
  // said. What keeps it from repeating is the Restore press emptying the region through the page's counted writer.
  it('Restore (its re-read fails), an archive made elsewhere learned through Retry, Restore again: spoken both times', async () => {
    const calls = stubFetch([json(200, MAIN), json(503, {}), json(200, ARCHIVED), json(200, MAIN), json(200, MAIN)]);
    const u = userEvent.setup();
    show(ARCHIVED);
    const rec = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Restore warehouse' }));
    await waitFor(() => expect(region()).toHaveTextContent(RESTORED));
    await u.click(await screen.findByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Restore warehouse' }));
    await waitFor(() => expect(screen.queryByText(ARCHIVED_BANNER)).toBeNull());
    await waitFor(() => expect(rec.texts).toEqual(['', RESTORED, '', RESTORED]));
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET', 'POST', 'GET']);
    rec.stop();
  });

  it('Delete (its re-read fails), a restore made elsewhere learned through Retry, Delete again: spoken both times', async () => {
    const calls = stubFetch([empty(204), json(503, {}), json(200, MAIN), empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show(MAIN);
    const rec = textsOf(region());
    await pressDelete(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await u.click(await screen.findByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.queryByText(ARCHIVED_BANNER)).toBeNull());
    await pressDelete(u);
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    await waitFor(() => expect(rec.texts).toEqual(['', AFTER, '', AFTER]));
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'GET', 'DELETE', 'GET']);
    rec.stop();
  });

  // The counted writer, not around it (FE-R6 final RED A-2's shape): a Delete warehouse press made while a location
  // delete's re-read is held supersedes that re-read's correction at the press. A raw `writeRegion('')` would empty the
  // region too, but leave the correction free to be written when the re-read lands.
  it('Delete warehouse pressed while a location delete\'s re-read is held: that re-read\'s correction is never written', async () => {
    const loc = (id: string, code: string, kind: string): StorageLocation => ({ id, code, kind, archived_at: null });
    const A1 = loc('11111111-1111-4111-8111-111111111111', 'A1', 'bin');
    const Q1 = loc('22222222-2222-4222-8222-222222222222', 'Q1', 'quarantine');
    const coll = (items: StorageLocation[]) => ({ items, next_cursor: null, previous_cursor: null, tenant_has_rows: true });
    const LOC_AFTER = "A1 was deleted. It's now in this warehouse's archived locations.";
    const CORRECTION = 'A1 was deleted, and has since been restored.';
    const settle = () => act(async () => void (await new Promise((t) => setTimeout(t, 50))));
    const reread = held();
    const del = held();
    const calls = stubFetch([empty(204), reread.reply, del.reply, json(200, ARCHIVED)]);
    const u = userEvent.setup();
    const locations = { initial: { kind: 'ok' as const, locations: [A1, Q1] }, canEdit: false, canRestore: true, canCreate: false, canDelete: true };
    renderWired(<WarehousePage initial={MAIN} canEdit={false} canRestore canDelete locations={locations} />);
    await u.click(screen.getByRole('button', { name: 'Delete A1' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(region().textContent).toBe(LOC_AFTER));
    const rec = textsOf(region());
    await pressDelete(u);
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'DELETE']));
    reread.release(json(200, coll([A1, Q1])));
    await settle();
    await settle();
    expect(rec.texts).toEqual([LOC_AFTER, '']);
    del.release(empty(204));
    await waitFor(() => expect(region().textContent).toBe(AFTER));
    expect(rec.texts).toEqual([LOC_AFTER, '', AFTER]);
    expect(rec.texts).not.toContain(CORRECTION);
    rec.stop();
  });

  // The Cancel twin (fe-r9-red.md L-3): Cancel sends nothing, so it must not empty the region through the counted writer;
  // a clear there would bump the count and drop the held location re-read's correction.
  it('Delete warehouse opened then cancelled while a location delete\'s re-read is held: that re-read\'s correction is still written', async () => {
    const loc = (id: string, code: string, kind: string): StorageLocation => ({ id, code, kind, archived_at: null });
    const A1 = loc('11111111-1111-4111-8111-111111111111', 'A1', 'bin');
    const Q1 = loc('22222222-2222-4222-8222-222222222222', 'Q1', 'quarantine');
    const coll = (items: StorageLocation[]) => ({ items, next_cursor: null, previous_cursor: null, tenant_has_rows: true });
    const LOC_AFTER = "A1 was deleted. It's now in this warehouse's archived locations.";
    const CORRECTION = 'A1 was deleted, and has since been restored.';
    const reread = held();
    const calls = stubFetch([empty(204), reread.reply]);
    const u = userEvent.setup();
    const locations = { initial: { kind: 'ok' as const, locations: [A1, Q1] }, canEdit: false, canRestore: true, canCreate: false, canDelete: true };
    renderWired(<WarehousePage initial={MAIN} canEdit={false} canRestore canDelete locations={locations} />);
    await u.click(screen.getByRole('button', { name: 'Delete A1' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(region().textContent).toBe(LOC_AFTER));
    const rec = textsOf(region());
    await u.click(await screen.findByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    reread.release(json(200, coll([A1, Q1])));
    await waitFor(() => expect(region().textContent).toBe(CORRECTION));
    expect(rec.texts).toEqual([LOC_AFTER, CORRECTION]);
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']);
    rec.stop();
  });
});
