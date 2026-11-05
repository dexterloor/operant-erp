import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { WarehousePage } from '@/app/(app)/warehouses/[id]/WarehousePage';
import type { StorageLocation, Warehouse } from '@/lib/api/warehouses';
import type { LocationsLoad } from '@/lib/warehouses/load';
import listStyles from '@/app/(app)/Lists.module.css';
import { json, problem, refused, renderWired, stubFetch } from './harness';

// Revision 7 §2 step 4.4 (R3-7, A-20, A-21), warehouses-ux-copy.md B-5 and STATES.md ST-9b: the Locations section on the
// warehouse page. Columns Code · Kind (the label, or the value as sent); Edit changes the code only (Kind shown as a label,
// no quarantine hint); Restore location on an Archived row; an archived warehouse's section is read-only under ST-9b's
// notes. No Delete (step 4.6). From step 4.5 Edit also offers Kind (a select); Add location is tested in
// `warehouse-add-location.test.tsx` — here `canCreate` defaults to false, so these tests describe a viewer without it.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const WID = '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
const MAIN: Warehouse = { id: WID, code: 'MAIN', name: 'Main warehouse', country_code: 'GB', archived_at: null };
const ARCHIVED_MAIN: Warehouse = { ...MAIN, archived_at: '2026-03-04T10:00:00Z' };
const loc = (id: string, code: string, kind: string, archived_at: string | null = null): StorageLocation => ({ id, code, kind, archived_at });
const A1 = loc('11111111-1111-4111-8111-111111111111', 'A1', 'bin');
const Q1 = loc('22222222-2222-4222-8222-222222222222', 'Q1', 'quarantine');
const P1 = loc('33333333-3333-4333-8333-333333333333', 'P1', 'pallet');
const B1 = loc('44444444-4444-4444-8444-444444444444', 'B1', 'shelf', '2026-04-01T09:00:00Z');
const coll = (items: StorageLocation[]) => ({ items, next_cursor: null, previous_cursor: null, tenant_has_rows: true });

function show(o: { w?: Warehouse; initial?: LocationsLoad; canEdit?: boolean; canRestore?: boolean; canCreate?: boolean } = {}) {
  const locations = { initial: o.initial ?? { kind: 'ok', locations: [A1, Q1, P1] }, canEdit: o.canEdit ?? true, canRestore: o.canRestore ?? true, canCreate: o.canCreate ?? false };
  return renderWired(<WarehousePage initial={o.w ?? MAIN} canEdit={false} canRestore={false} locations={locations} />);
}
const pageRegion = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const section = () => screen.getByRole('heading', { level: 2 }).closest('section')!;
const rows = () => within(section()).getAllByRole('row').slice(1);
const cellsOf = (r: HTMLElement) => within(r).getAllByRole('cell').map((c) => c.textContent);

describe('/warehouses/[id] — the Locations section (B-5, step 4.4)', () => {
  it('h2 Locations; Code · Kind with the kind label, an unknown kind as sent; Edit per row; no Add location without CreateStorageLocation', () => {
    show();
    expect(screen.getByRole('heading', { level: 2, name: 'Locations' })).toBeInTheDocument();
    expect(rows().map((r) => cellsOf(r).slice(0, 2))).toEqual([
      ['A1', 'Bin'],
      ['Q1', 'Quarantine'],
      ['P1', 'pallet'],
    ]);
    expect(within(section()).getByRole('button', { name: 'Edit A1' })).toBeInTheDocument();
    expect(screen.queryByText(/Add location/)).toBeNull();
    // R3-7 "Silent": the under-table quarantine note is not decided, so it is not rendered.
    expect(screen.queryByText(/on hold/)).toBeNull();
    // RED L-1: a live warehouse never shows ST-9b's archived-warehouse note.
    expect(within(section()).queryByText(/while the warehouse is archived/)).toBeNull();
  });

  it('without UpdateStorageLocation there is no Edit', () => {
    show({ canEdit: false });
    expect(within(section()).queryByRole('button', { name: /^Edit/ })).toBeNull();
  });

  it('without CreateStorageLocation the live empty state is the heading alone', () => {
    show({ initial: { kind: 'ok', locations: [] } });
    expect(within(section()).getByText('No locations in this warehouse yet.')).toBeInTheDocument();
    expect(within(section()).queryByRole('link')).toBeNull();
    expect(within(section()).getAllByRole('button').map((b) => b.textContent)).toEqual(['Archived']);
  });

  it('a 403 on the first read renders the section-sized denied sentence, not "something went wrong" (A-20 L-7)', () => {
    show({ initial: { kind: 'forbidden' } });
    expect(screen.getByText("You don't have access to this warehouse's locations.")).toBeInTheDocument();
    expect(screen.queryByText('Something went wrong on our side.')).toBeNull();
  });

  it('Archived: reads archived=true for this warehouse, heading Archived locations, Restore location per row', async () => {
    const calls = stubFetch([json(200, coll([B1]))]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await screen.findByRole('heading', { level: 2, name: 'Archived locations' });
    expect(calls[0]!.url).toContain(`/bff/api/warehouses/${WID}/locations?archived=true`);
    expect(within(section()).getByRole('button', { name: 'Restore location B1' })).toBeInTheDocument();
    expect(within(section()).getByRole('button', { name: 'Back to locations' })).toBeInTheDocument();
    // RED L-1, L-2: no archived-warehouse note on a live warehouse; archived rows are struck through (ST-9b).
    expect(within(section()).queryByText(/once the warehouse is restored/)).toBeNull();
    expect(rows()[0]!.className).toBe(listStyles.struck);
  });

  it('Archived without RestoreStorageLocation: no Restore location', async () => {
    stubFetch([json(200, coll([B1]))]);
    const u = userEvent.setup();
    show({ canRestore: false });
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await screen.findByRole('heading', { level: 2, name: 'Archived locations' });
    expect(within(section()).queryByRole('button', { name: /Restore location/ })).toBeNull();
  });
});

describe('Edit location — Code and Kind (§2 steps 4.4, 4.5)', () => {
  it('Code and a Kind select holding the current kind; Save starts unavailable', async () => {
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Edit Q1' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit location Q1' });
    expect(within(dialog).getAllByRole('textbox').map((i) => i.getAttribute('name'))).toEqual(['code']);
    expect(within(dialog).getByRole('combobox', { name: 'Kind' })).toHaveValue('quarantine');
    expect(within(dialog).getByRole('textbox', { name: 'Code' })).toHaveFocus();
    expect(within(dialog).getByRole('button', { name: 'Save' })).toHaveAttribute('aria-disabled', 'true');
  });

  it('a code-only change sends exactly { code } (never kind) to the location, then closes, says "{code} was saved." and re-reads the view', async () => {
    const saved = { ...A1, code: 'A2' };
    const calls = stubFetch([json(200, saved), json(200, coll([saved, Q1, P1]))]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    const code = within(dialog).getByRole('textbox', { name: 'Code' });
    await u.clear(code);
    await u.type(code, 'A2');
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(pageRegion()).toHaveTextContent('A2 was saved.'));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls[0]!.method).toBe('PATCH');
    expect(calls[0]!.url).toContain(`/bff/api/warehouses/${WID}/locations/${A1.id}`);
    expect(JSON.parse(calls[0]!.body!)).toEqual({ code: 'A2' });
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']));
    expect(calls[1]!.url).toContain(`/bff/api/warehouses/${WID}/locations`);
    await waitFor(() => expect(cellsOf(rows()[0]!)[0]).toBe('A2'));
    // RED L-3 (D-7): focus returns to the same row's Edit button (key = id), under its new code.
    await waitFor(() => expect(within(section()).getByRole('button', { name: 'Edit A2' })).toHaveFocus());
  });

  it('Cancel returns focus to the row\'s Edit button and sends nothing (RED L-3)', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(within(section()).getByRole('button', { name: 'Edit A1' })).toHaveFocus());
    expect(calls).toEqual([]);
  });

  it('a 409 that says the row is stale (L5) re-reads the section when the dialog closes', async () => {
    const calls = stubFetch([refused('storage_location.is_archived', "This location is archived, so it can't be changed until it's restored."), json(200, coll([Q1, P1]))]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'X');
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    await within(dialog).findByText('Not saved');
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']));
    await waitFor(() => expect(within(section()).queryByRole('button', { name: 'Edit A1' })).toBeNull());
  });

  it('a 403 on Save names Edit locations and closing re-reads (RED L-7)', async () => {
    const calls = stubFetch([problem(403, 'permission-denied'), json(200, coll([A1, Q1, P1]))]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'X');
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    await within(dialog).findByText('Not saved');
    expect(within(dialog).getByText('Edit locations')).toBeInTheDocument();
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']));
  });

  it('a 404 on Save: closing re-reads (D-10; RED L-7)', async () => {
    const calls = stubFetch([problem(404, 'not-found'), json(200, coll([Q1, P1]))]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'X');
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    await within(dialog).findByText('Not saved');
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']));
  });

  it('a 409 shows "Not saved" and the server sentence in the dialog; the dialog and Save stay', async () => {
    stubFetch([refused('storage_location.code_is_unique_per_warehouse', 'Another location in this warehouse already uses this code. Keep the current code or choose a different one.')]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'X');
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    await within(dialog).findByText('Not saved');
    expect(within(dialog).getByText(/Another location in this warehouse already uses this code/)).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: 'Save' })).toBeInTheDocument();
  });

  it('a 422 puts the sentence beside Code', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/code', code: 'blank', message: 'This field may not be blank.' }] })]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    await u.clear(within(dialog).getByRole('textbox', { name: 'Code' }));
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(within(dialog).getByRole('textbox', { name: 'Code' })).toHaveAttribute('aria-invalid', 'true'));
    expect(within(dialog).queryByText('Not saved')).toBeNull();
  });

  it('an interrupted save says so; closing then re-reads the section (L-8)', async () => {
    const calls = stubFetch([problem(503, 'x'), json(200, coll([A1, Q1, P1]))]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'X');
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    await within(dialog).findByText("We can't tell whether your changes to A1 were saved. Close this and check the location before you try again.");
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']));
  });

  it('an unreachable save (offline) says so too, and closing re-reads (L-8; mutation M15)', async () => {
    const offline = () => {
      throw new TypeError('Failed to fetch');
    };
    const calls = stubFetch([offline, json(200, coll([A1, Q1, P1]))]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'X');
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    await within(dialog).findByText("We couldn't reach the server.");
    await u.keyboard('{Escape}');
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']));
  });
});

describe('Restore location (ST-9b)', () => {
  async function archivedView(extra: Parameters<typeof stubFetch>[0]) {
    const calls = stubFetch([json(200, coll([B1])), ...extra]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await screen.findByRole('heading', { level: 2, name: 'Archived locations' });
    return { calls, u };
  }

  it('POSTs the restoration, says "{code} was restored.", focuses the section heading and re-reads', async () => {
    const { calls, u } = await archivedView([json(200, { ...B1, archived_at: null }), json(200, coll([]))]);
    await u.click(within(section()).getByRole('button', { name: 'Restore location B1' }));
    await waitFor(() => expect(pageRegion()).toHaveTextContent('B1 was restored.'));
    expect(calls[1]!.method).toBe('POST');
    expect(calls[1]!.url).toContain(`/bff/api/warehouses/${WID}/locations/${B1.id}/restoration`);
    expect(calls[1]!.body).toBeNull();
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['GET', 'POST', 'GET']));
    await within(section()).findByText('No archived locations in this warehouse.');
    expect(screen.getByRole('heading', { level: 2 })).toHaveFocus();
  });

  it('a refusal (L12) shows "Not restored" with the sentence as sent, its heading focused', async () => {
    const msg = "Another location in this warehouse now uses this location's code, so it can't be restored. It can be restored once that location's code is changed.";
    const { calls, u } = await archivedView([refused('storage_location.code_is_unique_per_warehouse', msg), json(200, coll([B1])), json(200, coll([B1]))]);
    await u.click(within(section()).getByRole('button', { name: 'Restore location B1' }));
    const heading = await within(section()).findByText('Not restored');
    expect(within(section()).getByText(msg)).toBeInTheDocument();
    await waitFor(() => expect(heading).toHaveFocus());
    expect(pageRegion()).not.toHaveTextContent('restored');
    // The refusal's check read and the view's re-read (RED L-5, M-3).
    expect(calls.map((c) => c.method)).toEqual(['GET', 'POST', 'GET', 'GET']);
  });

  it('a refusal after which the row has left the Archived view (L11, restored elsewhere) is treated as restored, the sentence as sent (RED L-5)', async () => {
    const msg = 'This location is not archived, so there is nothing to restore.';
    const { calls, u } = await archivedView([refused('storage_location.is_not_archived', msg), json(200, coll([])), json(200, coll([]))]);
    await u.click(within(section()).getByRole('button', { name: 'Restore location B1' }));
    await waitFor(() => expect(pageRegion()).toHaveTextContent(msg));
    expect(within(section()).queryByText('Not restored')).toBeNull();
    await within(section()).findByText('No archived locations in this warehouse.');
    expect(screen.getByRole('heading', { level: 2 })).toHaveFocus();
    expect(calls.map((c) => c.method)).toEqual(['GET', 'POST', 'GET', 'GET']);
  });

  it('an interrupted restore re-reads the view before it speaks: a committed press leaves the row gone (D-8)', async () => {
    const offline = () => {
      throw new TypeError('Failed to fetch');
    };
    const { calls, u } = await archivedView([offline, json(200, coll([]))]);
    await u.click(within(section()).getByRole('button', { name: 'Restore location B1' }));
    await within(section()).findByText("We can't tell whether B1 was restored. If it's still listed here, press Restore location to try again.");
    expect(calls.map((c) => c.method)).toEqual(['GET', 'POST', 'GET']);
    expect(within(section()).queryByRole('button', { name: 'Restore location B1' })).toBeNull();
    expect(within(section()).getByText("We couldn't reach the server.")).toBeInTheDocument();
  });

  it('a 5xx restore says "Something went wrong" with the same sentence', async () => {
    const { u } = await archivedView([problem(503, 'x'), json(200, coll([B1]))]);
    await u.click(within(section()).getByRole('button', { name: 'Restore location B1' }));
    await within(section()).findByText('Something went wrong on our side.');
    expect(within(section()).getByText("We can't tell whether B1 was restored. If it's still listed here, press Restore location to try again.")).toBeInTheDocument();
  });

  it('a 403 on Restore names Delete and restore locations (RED L-7)', async () => {
    const { u } = await archivedView([problem(403, 'permission-denied'), json(200, coll([B1]))]);
    await u.click(within(section()).getByRole('button', { name: 'Restore location B1' }));
    await within(section()).findByText('Not restored');
    expect(within(section()).getByText('Delete and restore locations')).toBeInTheDocument();
  });

  it('while one restore runs, every other row\'s Restore location is unavailable and sends nothing (RED L-6)', async () => {
    const B2 = loc('55555555-5555-4555-8555-555555555555', 'B2', 'bin', '2026-04-02T09:00:00Z');
    const calls = stubFetch([json(200, coll([B1, B2])), 'hang']);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await screen.findByRole('heading', { level: 2, name: 'Archived locations' });
    await u.click(within(section()).getByRole('button', { name: 'Restore location B1' }));
    const other = within(section()).getByRole('button', { name: 'Restore location B2' });
    await waitFor(() => expect(other).toHaveAttribute('aria-disabled', 'true'));
    expect(other).toHaveAccessibleDescription('Another location is being restored. Wait for it to finish.');
    await u.click(other);
    expect(calls.map((c) => c.method)).toEqual(['GET', 'POST']);
  });
});

describe('the archived warehouse page — Locations read-only under ST-9b’s notes', () => {
  it('live view: the note above the rows, unstruck rows, no Edit even for an UpdateStorageLocation holder', () => {
    show({ w: ARCHIVED_MAIN, canEdit: true, canRestore: true });
    expect(within(section()).getByText("These locations can't be used while the warehouse is archived. They'll be back in use as soon as it's restored.")).toBeInTheDocument();
    expect(within(section()).queryByRole('button', { name: /^Edit/ })).toBeNull();
    // RED L-2: the live view's rows are not struck (the core strikes by class, never with <s>/<del>).
    expect(rows().every((r) => r.className === '')).toBe(true);
  });

  it('Archived view: "These can be restored once the warehouse is restored." and no Restore location', async () => {
    stubFetch([json(200, coll([B1]))]);
    const u = userEvent.setup();
    show({ w: ARCHIVED_MAIN, canEdit: true, canRestore: true });
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await screen.findByRole('heading', { level: 2, name: 'Archived locations' });
    expect(within(section()).getByText('These can be restored once the warehouse is restored.')).toBeInTheDocument();
    expect(within(section()).queryByRole('button', { name: /Restore location/ })).toBeNull();
  });
});

describe('the warehouse page without the section', () => {
  it('locations null (no ListStorageLocations): no Locations heading and no request', () => {
    const calls = stubFetch([]);
    renderWired(<WarehousePage initial={MAIN} canEdit={false} canRestore={false} locations={null} />);
    expect(screen.queryByRole('heading', { level: 2 })).toBeNull();
    expect(calls).toEqual([]);
  });
});
