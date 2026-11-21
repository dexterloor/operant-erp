import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { WarehousePage } from '@/app/(app)/warehouses/[id]/WarehousePage';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { WAITING } from '@/copy/roles';
import { LIST_STATES } from '@/copy/shell';
import { ADD_LOCATION, EDIT_LOCATION, RESTORE_LOCATION } from '@/copy/warehouses';
import type { StorageLocation, Warehouse } from '@/lib/api/warehouses';
import { wireEnum } from '@/lib/bff/surface';
import type { LocationsLoad } from '@/lib/warehouses/load';
import listStyles from '@/app/(app)/Lists.module.css';
import { json, problem, refused, renderWired, stubFetch, textsOf } from './harness';

// Revision 7 §2 step 4.4 (R3-7, A-20, A-21), warehouses-ux-copy.md B-5 and STATES.md ST-9b: the Locations section on the
// warehouse page. Columns Code · Kind (the label, or the value as sent); Edit changes the code only (Kind shown as a label,
// no quarantine hint); Restore location on an Archived row; an archived warehouse's section is read-only under ST-9b's
// notes. Delete (step 4.6) is tested in `warehouse-delete.test.tsx`. From step 4.5 Edit also offers Kind (a select); Add location is tested in
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

describe('Edit location — the banner (LocationForm)', () => {
  it('two out-of-form violations with the same sentence: both shown, no duplicate-key warning', async () => {
    const errors = vi.spyOn(console, 'error').mockImplementation(() => {});
    try {
      stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'x', message: 'Same.' }, { pointer: '', code: 'y', message: 'Same.' }] })]);
      const u = userEvent.setup();
      show();
      await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
      const dialog = await screen.findByRole('dialog');
      await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'X');
      await u.click(within(dialog).getByRole('button', { name: 'Save' }));
      expect(await within(dialog).findAllByText('Same.')).toHaveLength(2);
      expect(errors.mock.calls.filter((c) => String(c[0]).includes('same key'))).toEqual([]);
    } finally {
      errors.mockRestore();
    }
  });
});

describe('Edit location — the banner on a later refusal (LocationForm)', () => {
  it('a later refusal in the open dialog replaces both repeated sentences; none is left behind', async () => {
    const v = (...ms: string[]) => problem(422, 'validation-failed', { errors: ms.map((message) => ({ pointer: '', code: 'x', message })) });
    stubFetch([v('Same.', 'Same.'), v('Other.')]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'X');
    const errors = vi.spyOn(console, 'error').mockImplementation(() => {});
    try {
      await u.click(within(dialog).getByRole('button', { name: 'Save' }));
      expect(await within(dialog).findAllByText('Same.')).toHaveLength(2);
      await u.click(within(dialog).getByRole('button', { name: 'Save' }));
      expect(await within(dialog).findByText('Other.')).toBeInTheDocument();
      expect(within(dialog).queryByText('Same.')).toBeNull();
    } finally {
      errors.mockRestore();
    }
  });
});

// I-2 follow-up (items-i3-ux.md Revision 1 FINAL F-5 #1): a 422 with an out-of-form sentence AND a field sentence ends
// its banner with this line; no other banner carries it.
const MIXED_LINE = 'Check the marked fields below too.';
const bannerBody = (heading: HTMLElement) => [...heading.parentElement!.children].filter((e) => e !== heading).map((e) => e.textContent);

describe('Edit location (LocationForm) — the mixed-422 line (I-2 follow-up)', () => {
  it('a 422 with an out-of-form sentence and a field sentence: the banner ends with "Check the marked fields below too."', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'x', message: 'Out of form.' }, { pointer: '/code', code: 'blank', message: 'This field may not be blank.' }] })]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'X');
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    const heading = await within(dialog).findByText('Not saved');
    expect(bannerBody(heading)).toEqual(['Out of form.', MIXED_LINE]);
  });

  it('a banner-only 422 and a 409 carry no such line', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'x', message: 'Out of form.' }, { pointer: '', code: 'y', message: 'Also out of form.' }] }), refused('some.rule', 'Refused here.')]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'X');
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    const heading = await within(dialog).findByText('Not saved');
    expect(bannerBody(heading)).toEqual(['Out of form.', 'Also out of form.']);
    expect(within(dialog).queryByText(MIXED_LINE)).toBeNull();
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    expect(await within(dialog).findByText('Refused here.')).toBeInTheDocument();
    expect(within(dialog).queryByText(MIXED_LINE)).toBeNull();
  });
});

// locations-stale-banner-design.md "Revision 1 — FINAL" as corrected by locations-stale-banner-final-red.md and
// locations-stale-banner-po-arch.md (H-1 option (a′)): Edit, Add and Restore whose view re-read fails show the section's
// banner with that write's stale sentence, which the page's region also speaks; a refused or interrupted dialog's Close
// shows the generic body and speaks nothing (FE-R8 R-2). The delete's cases and the banner's Retry are in
// `warehouse-delete.test.tsx`.
describe('/warehouses/[id] — Locations, the stale banner after a failed re-read (Edit, Add, Restore)', () => {
  const retries = () => within(section()).queryAllByRole('button', { name: 'Retry' });
  const bannerTexts = (retry: HTMLElement) => {
    let el: HTMLElement | null = retry.parentElement;
    while (el !== null && el.querySelectorAll('p').length < 2) el = el.parentElement;
    return [...el!.querySelectorAll('p')].map((p) => p.textContent);
  };
  const settle = () =>
    act(async () => {
      await new Promise((t) => setTimeout(t, 50));
    });
  const editTo = async (u: ReturnType<typeof userEvent.setup>, from: string, to: string) => {
    await u.click(within(section()).getByRole('button', { name: `Edit ${from}` }));
    const dialog = await screen.findByRole('dialog');
    const code = within(dialog).getByRole('textbox', { name: 'Code' });
    await u.clear(code);
    await u.type(code, to);
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    return dialog;
  };
  const archivedView = async (extra: Parameters<typeof stubFetch>[0]) => {
    const calls = stubFetch([json(200, coll([B1])), ...extra]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await screen.findByRole('heading', { level: 2, name: 'Archived locations' });
    return { calls, u };
  };

  // T-3 (Edit), over every stale failure (commit RED L-3: Add and Restore share `staleAfter` and the same `isStale`).
  // Mutations M-1, M-14 (Edit keeps "saved") and P5 (a 404 not stale on Edit) → red.
  const offline = () => {
    throw new TypeError('Failed to fetch');
  };
  for (const [what, reply, heading] of [
    ['503', () => json(503, {}), WENT_WRONG],
    ['offline', offline, COULD_NOT_REACH],
    ['404', () => problem(404, 'not-found'), WENT_WRONG],
  ] as const) {
    it(`T-3 an Edit saved, then the re-read fails (${what}): the banner and the region both say savedListStale`, async () => {
      const saved = { ...A1, code: 'A2' };
      stubFetch([json(200, saved), reply]);
      const u = userEvent.setup();
      show();
      await editTo(u, 'A1', 'A2');
      await waitFor(() => expect(retries()).toHaveLength(1));
      expect(bannerTexts(retries()[0]!)).toEqual([heading, EDIT_LOCATION.savedListStale('A2')]);
      await settle();
      expect(pageRegion().textContent).toBe(EDIT_LOCATION.savedListStale('A2'));
    });
  }

  it('T-3 an Add created, then the re-read fails: the banner and the region both say ADD_LOCATION.afterListStale', async () => {
    const kind = wireEnum('StorageLocation', 'kind')[0]!;
    const made = loc('66666666-6666-4666-8666-666666666666', 'B2', kind);
    stubFetch([json(201, made), json(503, {})]);
    const u = userEvent.setup();
    show({ canCreate: true });
    await u.click(within(section()).getByRole('button', { name: 'Add location' }));
    const dialog = await screen.findByRole('dialog', { name: 'Add a location to MAIN' });
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'B2');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), kind);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await waitFor(() => expect(retries()).toHaveLength(1));
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, ADD_LOCATION.afterListStale('B2')]);
    await settle();
    expect(pageRegion().textContent).toBe(ADD_LOCATION.afterListStale('B2'));
  });

  it('T-3 a Restore restored, then the re-read fails: the banner and the region both say RESTORE_LOCATION.afterListStale', async () => {
    const { u } = await archivedView([json(200, { ...B1, archived_at: null }), json(503, {})]);
    await u.click(within(section()).getByRole('button', { name: 'Restore location B1' }));
    await waitFor(() => expect(retries()).toHaveLength(1));
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, RESTORE_LOCATION.afterListStale('B1')]);
    await settle();
    expect(pageRegion().textContent).toBe(RESTORE_LOCATION.afterListStale('B1'));
  });

  it('T-3 a Restore refused but no longer archived (restored elsewhere), then the re-read fails: our stale sentence replaces the server\'s', async () => {
    const msg = 'This location is not archived, so there is nothing to restore.';
    const { u } = await archivedView([refused('storage_location.is_not_archived', msg), json(200, coll([])), json(503, {})]);
    await u.click(within(section()).getByRole('button', { name: 'Restore location B1' }));
    await waitFor(() => expect(retries()).toHaveLength(1));
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, RESTORE_LOCATION.afterListStale('B1')]);
    await settle();
    expect(pageRegion().textContent).toBe(RESTORE_LOCATION.afterListStale('B1'));
  });

  // T-3b's Edit half (final RED L-7): a refused or interrupted Edit's Close whose re-read fails is shown, not spoken.
  it("T-3b an Edit refused (409), then Cancel, then the re-read fails: the generic banner; the region is not written", async () => {
    stubFetch([refused('storage_location.code_is_unique_per_warehouse', 'Another location in this warehouse already uses this code.'), json(503, {})]);
    const u = userEvent.setup();
    show();
    const dialog = await editTo(u, 'A1', 'Q1');
    await within(dialog).findByText('Not saved');
    const before = pageRegion().textContent;
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(retries()).toHaveLength(1));
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, LIST_STATES.refetchFailed]);
    await settle();
    expect(pageRegion().textContent).toBe(before);
  });

  it('T-3b an Edit interrupted (offline), then Escape, then the re-read fails: the generic banner; the region is not written', async () => {
    stubFetch([offline, json(503, {})]);
    const u = userEvent.setup();
    show();
    const dialog = await editTo(u, 'A1', 'A9');
    await within(dialog).findByText("We couldn't reach the server.");
    const before = pageRegion().textContent;
    await u.keyboard('{Escape}');
    await waitFor(() => expect(retries()).toHaveLength(1));
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, LIST_STATES.refetchFailed]);
    await settle();
    expect(pageRegion().textContent).toBe(before);
  });

  // T-3b's Add half (commit RED L-8; named in FE-R8 R-2's trigger). Mutation M-22 (a refused Add's Close speaks) → red.
  it('T-3b-Add an Add refused (409), then Cancel, then the re-read fails: the generic banner; the region is not written', async () => {
    const kind = wireEnum('StorageLocation', 'kind')[0]!;
    stubFetch([refused('storage_location.code_is_unique_per_warehouse', 'Another location in this warehouse already uses this code. Choose a different code.'), json(503, {})]);
    const u = userEvent.setup();
    show({ canCreate: true });
    await u.click(within(section()).getByRole('button', { name: 'Add location' }));
    const dialog = await screen.findByRole('dialog', { name: 'Add a location to MAIN' });
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'A1');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), kind);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await within(dialog).findByText('Not added');
    const before = pageRegion().textContent;
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(retries()).toHaveLength(1));
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, LIST_STATES.refetchFailed]);
    await settle();
    expect(pageRegion().textContent).toBe(before);
  });

  // T-8b′ (commit RED L-1): the same in the Archived view. Mutation M-19 (`listBannerUp` ignored in the Archived view) → red.
  it("T-8b′ in the Archived view, a failed Back to locations, then a Restore whose re-read fails: exactly one banner (the list's); the region says RESTORE_LOCATION.afterListStale", async () => {
    stubFetch([json(200, coll([B1])), json(503, {}), json(200, { ...B1, archived_at: null }), json(503, {})]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await screen.findByRole('heading', { level: 2, name: 'Archived locations' });
    await u.click(within(section()).getByRole('button', { name: 'Back to locations' }));
    await waitFor(() => expect(retries()).toHaveLength(1));
    await u.click(within(section()).getByRole('button', { name: 'Restore location B1' }));
    await waitFor(() => expect(pageRegion().textContent).toBe(RESTORE_LOCATION.afterListStale('B1')));
    await settle();
    expect(retries()).toHaveLength(1);
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, LIST_STATES.refetchFailed]);
  });

  // T-8b. Mutation M-11 (ignore `listBannerUp`) → red.
  it("T-8b a failed switch, then an Edit whose re-read fails: exactly one banner (the list's); the region says savedListStale", async () => {
    stubFetch([json(503, {}), json(200, { ...A1, code: 'A2' }), json(503, {})]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(retries()).toHaveLength(1));
    await editTo(u, 'A1', 'A2');
    await waitFor(() => expect(pageRegion().textContent).toBe(EDIT_LOCATION.savedListStale('A2')));
    await settle();
    expect(retries()).toHaveLength(1);
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, LIST_STATES.refetchFailed]);
  });

  // T-10.
  it("T-10 a failed first load, and its Retry failing again: only the list's region error, never the section's banner", async () => {
    const calls = stubFetch([json(503, {})]);
    const u = userEvent.setup();
    show({ initial: { kind: 'server-error' } });
    // With a failed first load the page holds more than one level-2 heading, so the section is found by the list's heading id.
    const list = () => document.getElementById('locations-heading')!.closest('section')!;
    const listRetries = () => within(list()).queryAllByRole('button', { name: 'Retry' });
    expect(listRetries()).toHaveLength(1);
    await u.click(listRetries()[0]!);
    await waitFor(() => expect(calls).toHaveLength(1));
    await settle();
    expect(listRetries()).toHaveLength(1);
    expect(within(list()).getByText("The locations couldn't be loaded.")).toBeInTheDocument();
    expect(within(list()).queryByText(LIST_STATES.refetchFailed)).toBeNull();
  });

  it("T-10 an Edit whose re-read answers 403: the section's denied state, no banner", async () => {
    stubFetch([json(200, { ...A1, code: 'A2' }), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await editTo(u, 'A1', 'A2');
    await screen.findByText("You don't have access to this warehouse's locations.");
    expect(screen.queryAllByRole('button', { name: 'Retry' })).toHaveLength(0);
  });

  // T-11: each NEW sentence is the write's own sentence followed by the shared list-stale sentence.
  it('T-11 savedListStale and both afterListStale sentences are the write\'s sentence followed by WAITING.listStale', () => {
    for (const c of ['A1', 'X-9']) {
      expect(EDIT_LOCATION.savedListStale(c)).toBe(`${EDIT_LOCATION.saved(c)} ${WAITING.listStale}`);
      expect(ADD_LOCATION.afterListStale(c)).toBe(`${ADD_LOCATION.after(c)} ${WAITING.listStale}`);
      expect(RESTORE_LOCATION.afterListStale(c)).toBe(`${RESTORE_LOCATION.after(c)} ${WAITING.listStale}`);
    }
  });
});

// fe-r6-saving-status-design.md Revision 1 — FINAL commit A (RED H-2; final RED A-3): LocationsSection empties the page
// region through the counted writer at each Edit Save press that sends a request, so a second identical
// "{code} was saved." is given again.
describe('Edit location — a second identical saved sentence is written again (FE-R6 commit A)', () => {
  const SAVED = 'A1 was saved.';
  const editKind = async (u: ReturnType<typeof userEvent.setup>, kind: string) => {
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit location A1' });
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), kind);
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
  };

  // Mutation A-M4 (no call in LocationForm) → red.
  it('two Edits of the same location in a row each say "A1 was saved.": the region empties at the second press', async () => {
    // Two served kinds other than A1's own, so each Edit changes something (the served list, never typed).
    const [k1, k2] = wireEnum('StorageLocation', 'kind').filter((k) => k !== A1.kind) as [string, string];
    const one = { ...A1, kind: k1 };
    const two = { ...A1, kind: k2 };
    stubFetch([json(200, one), json(200, coll([one, Q1, P1])), json(200, two), json(200, coll([two, Q1, P1]))]);
    const u = userEvent.setup();
    show();
    const rec = textsOf(pageRegion());
    await editKind(u, k1);
    await waitFor(() => expect(pageRegion().textContent).toBe(SAVED));
    await waitFor(() => expect(within(section()).getByRole('button', { name: 'Edit A1' })).toHaveFocus());
    await editKind(u, k2);
    await waitFor(() => expect(rec.texts).toEqual(['', SAVED, '', SAVED]));
    rec.stop();
  });
});

// S-4 RED-2 M-2 (shipped): FE-D1's order on Edit location — focus is back on the row's Edit BEFORE "{code} was saved." is
// written. The dialog's own focus return runs on a timer after it unmounts, later than the sentence, so the section puts
// focus back itself (the supplier catalogue's DV-16 shape). The spy tells a call inside the dialog from the page's.
describe('/warehouses/[id] — Edit location: focus returns before the saved sentence (FE-D1)', () => {
  it('after a 200 the row\'s Edit takes focus while the page region is still empty; "A2 was saved." follows', async () => {
    const saved = { ...A1, code: 'A2' };
    stubFetch([json(200, saved), json(200, coll([saved, Q1, P1]))]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const code = within(screen.getByRole('dialog')).getByRole('textbox', { name: 'Code' });
    await u.clear(code);
    await u.type(code, 'A2');
    const seen: { el: string; region: string }[] = [];
    const real = HTMLElement.prototype.focus;
    const spy = vi.spyOn(HTMLElement.prototype, 'focus').mockImplementation(function (this: HTMLElement, opts?: FocusOptions) {
      seen.push({ el: (this.closest('[role="dialog"]') ? 'dialog:' : '') + (this.getAttribute('aria-label') ?? this.textContent ?? this.tagName), region: pageRegion().textContent ?? '' });
      real.call(this, opts);
    });
    try {
      await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Save' }));
      await waitFor(() => expect(pageRegion().textContent).toBe('A2 was saved.'));
      await waitFor(() => expect(within(section()).getByRole('button', { name: 'Edit A2' })).toHaveFocus());
    } finally {
      spy.mockRestore();
    }
    const onEdit = seen.filter((c) => c.el === 'Edit A1' || c.el === 'Edit A2');
    expect(onEdit.length).toBeGreaterThan(0);
    expect(onEdit[0]!.region).toBe('');
    expect(pageRegion().textContent).toBe('A2 was saved.');
  });
});
