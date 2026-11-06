import { render, screen, waitFor, within } from '@testing-library/react';
import { createRef } from 'react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { WarehousePage } from '@/app/(app)/warehouses/[id]/WarehousePage';
import { AddLocationForm } from '@/components/warehouses/AddLocationForm';
import { PAGE_STATES } from '@/copy/shell';
import { KIND_LABELS } from '@/copy/warehouses';
import type { StorageLocation, Warehouse } from '@/lib/api/warehouses';
import { wireEnum } from '@/lib/bff/surface';
import type { LocationsLoad } from '@/lib/warehouses/load';
import { json, problem, refused, renderWired, stubFetch } from './harness';

// Revision 7 §2 step 4.5 (R3-7, R3-9, A-21), warehouses-ux-copy.md B-5 and B-6: Add location (header + live empty
// state, `CreateStorageLocation`), the Kind select over the served kinds, starting unselected and required (UX
// `kind-preselection-ux.md`: do not preselect; A-21 + S-7), and Edit
// location's Kind control with the quarantine hint. Kinds in this file come from the served enum, never typed (A-21).

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const KINDS = wireEnum('StorageLocation', 'kind');
/** The first served kind (any one would do: nothing here depends on which). */
const FIRST = KINDS[0]!;
/** The served kind whose label entry carries an option help (and an Edit hint): found through the entry, not by name. */
const HELD = KINDS.find((k) => KIND_LABELS[k]?.editHint !== undefined)!;
const PLAIN = KINDS.find((k) => k !== HELD && k !== FIRST && KIND_LABELS[k]?.editHint === undefined)!;

const WID = '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
const MAIN: Warehouse = { id: WID, code: 'MAIN', name: 'Main warehouse', country_code: 'GB', archived_at: null };
const ARCHIVED_MAIN: Warehouse = { ...MAIN, archived_at: '2026-03-04T10:00:00Z' };
const loc = (id: string, code: string, kind: string, archived_at: string | null = null): StorageLocation => ({ id, code, kind, archived_at });
const A1 = loc('11111111-1111-4111-8111-111111111111', 'A1', FIRST);
const Q1 = loc('22222222-2222-4222-8222-222222222222', 'Q1', HELD);
const N1 = loc('55555555-5555-4555-8555-555555555555', 'N1', PLAIN);
const coll = (items: StorageLocation[]) => ({ items, next_cursor: null, previous_cursor: null, tenant_has_rows: true });

function show(o: { w?: Warehouse; initial?: LocationsLoad; canCreate?: boolean; canEdit?: boolean } = {}) {
  const locations = { initial: o.initial ?? { kind: 'ok', locations: [A1, Q1] }, canEdit: o.canEdit ?? false, canRestore: false, canCreate: o.canCreate ?? true };
  return renderWired(<WarehousePage initial={o.w ?? MAIN} canEdit={false} canRestore={false} locations={locations} />);
}
const pageRegion = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const section = () => screen.getByRole('heading', { level: 2 }).closest('section')!;
const rows = () => within(section()).getAllByRole('row').slice(1);
const addButton = () => within(section()).getByRole('button', { name: 'Add location' });
async function openAdd(u: ReturnType<typeof userEvent.setup>) {
  await u.click(addButton());
  return screen.findByRole('dialog', { name: 'Add a location to MAIN' });
}

describe('Add location — where it is offered (B-5, step 4.5)', () => {
  it('a CreateStorageLocation holder on a live warehouse gets Add location in the section header', () => {
    show();
    expect(addButton()).toBeInTheDocument();
  });

  it('without CreateStorageLocation: no Add location anywhere, populated or empty', () => {
    show({ canCreate: false });
    expect(within(section()).queryByRole('button', { name: 'Add location' })).toBeNull();
  });

  it('the live empty state is the heading + Add location (ends step 4.4\'s heading-alone interim)', () => {
    show({ initial: { kind: 'ok', locations: [] } });
    expect(within(section()).getByText('No locations in this warehouse yet.')).toBeInTheDocument();
    expect(within(section()).getAllByRole('button', { name: 'Add location' })).toHaveLength(2);
  });

  it('an archived warehouse offers no Add location, even to a holder, in its live or empty view', () => {
    show({ w: ARCHIVED_MAIN });
    expect(within(section()).queryByRole('button', { name: 'Add location' })).toBeNull();
  });

  it('an archived warehouse\'s empty live view: the heading alone', () => {
    show({ w: ARCHIVED_MAIN, initial: { kind: 'ok', locations: [] } });
    expect(within(section()).getByText('No locations in this warehouse yet.')).toBeInTheDocument();
    expect(within(section()).queryByRole('button', { name: 'Add location' })).toBeNull();
  });

  it('the Archived view: Back to locations replaces Add location, and its empty state has no Add', async () => {
    stubFetch([json(200, coll([]))]);
    const u = userEvent.setup();
    show();
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await screen.findByRole('heading', { level: 2, name: 'Archived locations' });
    expect(within(section()).queryByRole('button', { name: 'Add location' })).toBeNull();
    expect(within(section()).getByRole('button', { name: 'Back to locations' })).toBeInTheDocument();
  });
});

describe('Add location — the dialog', () => {
  it('Code (focused) and Kind: an empty first option "Choose a kind" (no disabled), selected, then the served kinds in served order by label', async () => {
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    expect(within(dialog).getByRole('textbox', { name: 'Code' })).toHaveFocus();
    const kind = within(dialog).getByRole('combobox', { name: 'Kind' });
    expect(kind).toHaveValue('');
    const options = within(kind).getAllByRole('option');
    expect(options.map((o) => [o.getAttribute('value'), o.textContent])).toEqual([['', 'Choose a kind'], ...KINDS.map((k) => [k, KIND_LABELS[k]!.label])]);
    expect(options[0]).not.toHaveAttribute('disabled');
    expect(dialog.querySelector('form')).toHaveAttribute('novalidate');
    expect(within(dialog).getByText('Each location in this warehouse needs its own code.')).toBeInTheDocument();
  });

  it('choosing a kind whose entry has option help shows it under Kind; a plain kind shows none', async () => {
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    const kind = within(dialog).getByRole('combobox', { name: 'Kind' });
    expect(kind).not.toHaveAttribute('aria-describedby');
    await u.selectOptions(kind, HELD);
    expect(within(dialog).getByText(KIND_LABELS[HELD]!.optionHelp!)).toBeInTheDocument();
    expect(kind).toHaveAttribute('aria-describedby', 'new-location-kind-hint');
    await u.selectOptions(kind, PLAIN);
    expect(within(dialog).queryByText(KIND_LABELS[HELD]!.optionHelp!)).toBeNull();
  });

  it('201: POSTs exactly { code, kind } with an Idempotency-Key, closes, says "{code} was added.", re-reads, focus back on Add location', async () => {
    const made = loc('66666666-6666-4666-8666-666666666666', 'B2', PLAIN);
    const calls = stubFetch([json(201, made), json(200, coll([A1, Q1, made]))]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'B2');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await waitFor(() => expect(pageRegion()).toHaveTextContent('B2 was added.'));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls[0]!.method).toBe('POST');
    expect(calls[0]!.url).toContain(`/bff/api/warehouses/${WID}/locations`);
    expect(calls[0]!.url).not.toContain('?');
    expect(JSON.parse(calls[0]!.body!)).toEqual({ code: 'B2', kind: PLAIN });
    expect(calls[0]!.headers.get('idempotency-key')).toMatch(/^[0-9a-f-]{36}$/);
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['POST', 'GET']));
    await waitFor(() => expect(rows()).toHaveLength(3));
    await waitFor(() => expect(addButton()).toHaveFocus());
  });

  it('nothing chosen: Add location sends NO request and says "Choose a kind for this location." beside Kind, focused (UX kind-preselection-ux.md)', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'B3');
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    const kind = within(dialog).getByRole('combobox', { name: 'Kind' });
    await waitFor(() => expect(kind).toHaveAttribute('aria-invalid', 'true'));
    expect(within(dialog).getByText('Choose a kind for this location.')).toBeInTheDocument();
    expect(kind.getAttribute('aria-describedby')).toContain('new-location-kind-error');
    expect(document.getElementById('new-location-kind-error')).toHaveTextContent('Choose a kind for this location.');
    expect(kind).toHaveFocus();
    expect(within(dialog).queryByText('Not added')).toBeNull();
    expect(calls).toEqual([]);
  });

  it('a chosen kind is sent: after the no-choice refusal, choosing one and pressing again POSTs { code, kind }', async () => {
    const made = loc('66666666-6666-4666-8666-666666666666', 'B3', FIRST);
    const calls = stubFetch([json(201, made), json(200, coll([A1, Q1, made]))]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'B3');
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await within(dialog).findByText('Choose a kind for this location.');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), FIRST);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await waitFor(() => expect(pageRegion()).toHaveTextContent('B3 was added.'));
    expect(calls.filter((c) => c.method === 'POST')).toHaveLength(1);
    expect(JSON.parse(calls[0]!.body!)).toEqual({ code: 'B3', kind: FIRST });
  });

  it('added from the empty state: the opener is gone, so focus goes to the section heading', async () => {
    const made = loc('66666666-6666-4666-8666-666666666666', 'B4', FIRST);
    stubFetch([json(201, made), json(200, coll([made]))]);
    const u = userEvent.setup();
    show({ initial: { kind: 'ok', locations: [] } });
    await u.click(within(section()).getAllByRole('button', { name: 'Add location' })[1]!);
    const dialog = await screen.findByRole('dialog');
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'B4');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await waitFor(() => expect(rows()).toHaveLength(1));
    await waitFor(() => expect(screen.getByRole('heading', { level: 2, name: 'Locations' })).toHaveFocus());
  });

  it('a fresh key for every submit: after a lost answer the second submit carries a new key, and L1 shows as sent under "Not added"', async () => {
    const keys: (string | null)[] = [];
    let n = 0;
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_input: RequestInfo | URL, init: RequestInit = {}) => {
        keys.push(new Headers(init.headers).get('idempotency-key'));
        n += 1;
        if (n === 1) throw new TypeError('offline');
        return refused('storage_location.code_is_unique_per_warehouse', 'Another location in this warehouse already uses this code. Choose a different code.');
      }),
    );
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'B5');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    expect(await within(dialog).findByText("We can't tell whether B5 was added. Check this warehouse's locations before you try again.")).toBeInTheDocument();
    expect(within(dialog).getByText("We couldn't reach the server.")).toHaveFocus();
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    expect(await within(dialog).findByText('Another location in this warehouse already uses this code. Choose a different code.')).toBeInTheDocument();
    expect(within(dialog).getByText('Not added')).toHaveFocus();
    expect(keys).toHaveLength(2);
    expect(keys[0]).not.toBeNull();
    expect(keys[1]).not.toBeNull();
    expect(keys[0]).not.toBe(keys[1]);
    // The dialog and its Add location stay (B-7).
    expect(within(dialog).getByRole('button', { name: 'Add location' })).toBeInTheDocument();
  });

  it('a refusal (L3, warehouse archived meanwhile): "Not added", the sentence as sent; closing re-reads', async () => {
    const calls = stubFetch([refused('warehouse.is_archived', "This warehouse is archived, so no location can be added to it until it's restored."), json(200, coll([A1, Q1]))]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'X');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    expect(await within(dialog).findByText("This warehouse is archived, so no location can be added to it until it's restored.")).toBeInTheDocument();
    expect(within(dialog).getByText('Not added')).toHaveFocus();
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['POST', 'GET']));
    expect(pageRegion()).not.toHaveTextContent('was added');
  });

  it('a 422 puts the sentences beside Code and Kind, not in a banner; Kind focused when only it is wrong', async () => {
    stubFetch([
      problem(422, 'validation-failed', { errors: [{ pointer: '/kind', code: 'not_a_location_kind', message: 'Use one of: bin, shelf, zone, staging, quarantine.' }] }),
      problem(422, 'validation-failed', { errors: [{ pointer: '/code', code: 'blank', message: 'This field may not be blank.' }] }),
    ]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), ' ');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    const kind = within(dialog).getByRole('combobox', { name: 'Kind' });
    await waitFor(() => expect(kind).toHaveAttribute('aria-invalid', 'true'));
    expect(within(dialog).getByText('Use one of: bin, shelf, zone, staging, quarantine.')).toBeInTheDocument();
    // RED L-5 (R3): the select names its error through aria-describedby, as TextField does.
    expect(kind.getAttribute('aria-describedby')).toContain('new-location-kind-error');
    expect(kind).toHaveFocus();
    expect(within(dialog).queryByText('Not added')).toBeNull();
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    const code = within(dialog).getByRole('textbox', { name: 'Code' });
    await waitFor(() => expect(code).toHaveAttribute('aria-invalid', 'true'));
    expect(code).toHaveFocus();
  });

  it('an unreachable add (offline): closing re-reads the section (mutation M16)', async () => {
    const methods: string[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_input: RequestInfo | URL, init: RequestInit = {}) => {
        methods.push(init.method ?? 'GET');
        if (methods.length === 1) throw new TypeError('offline');
        return json(200, coll([A1, Q1]));
      }),
    );
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'B7');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    expect(await within(dialog).findByText("We couldn't reach the server.")).toBeInTheDocument();
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(methods).toEqual(['POST', 'GET']));
  });

  it('a 5xx says "Something went wrong" with the interrupted sentence; closing re-reads', async () => {
    const calls = stubFetch([json(500, {}), json(200, coll([A1, Q1]))]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'B6');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    expect(await within(dialog).findByText('Something went wrong on our side.')).toBeInTheDocument();
    expect(within(dialog).getByText("We can't tell whether B6 was added. Check this warehouse's locations before you try again.")).toBeInTheDocument();
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['POST', 'GET']));
  });

  it('a 403 names Create locations; a 404 shows the not-found sentence; each closing re-reads', async () => {
    const calls = stubFetch([problem(403, 'permission-denied'), json(200, coll([A1, Q1])), problem(404, 'not-found'), json(200, coll([A1, Q1]))]);
    const u = userEvent.setup();
    show();
    let dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'X');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await within(dialog).findByText('Not added');
    expect(within(dialog).getByText('Create locations')).toBeInTheDocument();
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['POST', 'GET']));
    dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'X');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await within(dialog).findByText('Not added');
    // RED L-4: the 404's body is the not-found sentence.
    expect(within(dialog).getByText(PAGE_STATES.notFoundInline)).toBeInTheDocument();
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'POST', 'GET']));
  });

  it('RED M-1: a later 422 clears the earlier refusal banner (L1, then blank)', async () => {
    const L1 = 'Another location in this warehouse already uses this code. Choose a different code.';
    stubFetch([refused('storage_location.code_is_unique_per_warehouse', L1), problem(422, 'validation-failed', { errors: [{ pointer: '/code', code: 'blank', message: 'This field may not be blank.' }] })]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    const code = within(dialog).getByRole('textbox', { name: 'Code' });
    await u.type(code, 'A1');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await within(dialog).findByText(L1);
    await u.clear(code);
    await u.type(code, ' ');
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await waitFor(() => expect(code).toHaveAttribute('aria-invalid', 'true'));
    expect(within(dialog).queryByText('Not added')).toBeNull();
    expect(within(dialog).queryByText(L1)).toBeNull();
  });

  it('RED M-2: "{code} was added." is spoken before the re-read answers (FE-D1: close → focus → announce → refresh)', async () => {
    const made = loc('66666666-6666-4666-8666-666666666666', 'B2', PLAIN);
    let release: (r: Response) => void = () => {};
    const held = new Promise<Response>((resolve) => (release = resolve));
    const calls = stubFetch([json(201, made), () => held]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'B2');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['POST', 'GET']));
    expect(pageRegion()).toHaveTextContent('B2 was added.');
    expect(rows()).toHaveLength(2);
    release(json(200, coll([A1, Q1, made])));
    await waitFor(() => expect(rows()).toHaveLength(3));
  });

  it('RED M-3: closing with Escape after a refusal re-reads too', async () => {
    const calls = stubFetch([refused('warehouse.is_archived', "This warehouse is archived, so no location can be added to it until it's restored."), json(200, coll([A1, Q1]))]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'X');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await within(dialog).findByText('Not added');
    await u.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['POST', 'GET']));
  });

  it('RED L-5 (R5): a 201 whose body is not a location says "Something went wrong" and announces nothing', async () => {
    stubFetch([json(201, {})]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'B8');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    expect(await within(dialog).findByText('Something went wrong on our side.')).toBeInTheDocument();
    expect(pageRegion()).not.toHaveTextContent('was added');
  });

  it('RED2 M-1: when focus lands on Kind after the no-choice refusal, the error is already linked', async () => {
    stubFetch([]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    const kind = within(dialog).getByRole('combobox', { name: 'Kind' });
    const atFocus: Array<string | null> = [];
    kind.addEventListener('focus', () => atFocus.push(kind.getAttribute('aria-describedby')));
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await waitFor(() => expect(kind).toHaveFocus());
    expect(atFocus.at(-1)).toContain('new-location-kind-error');
    expect(kind).toHaveAttribute('aria-invalid', 'true');
  });

  it('RED2 M-1: a 422 on Kind alone — when focus lands on Kind the error is already linked', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/kind', code: 'not_a_location_kind', message: 'Use one of: bin, shelf, zone, staging, quarantine.' }] })]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'B9');
    const kind = within(dialog).getByRole('combobox', { name: 'Kind' });
    await u.selectOptions(kind, PLAIN);
    const atFocus: Array<string | null> = [];
    kind.addEventListener('focus', () => atFocus.push(kind.getAttribute('aria-describedby')));
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await waitFor(() => expect(kind).toHaveFocus());
    expect(atFocus.at(-1)).toContain('new-location-kind-error');
  });

  it('RED2 M-2: a second press with nothing chosen still sends nothing, and the sentence stays', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'B3');
    const add = within(dialog).getByRole('button', { name: 'Add location' });
    await u.click(add);
    await within(dialog).findByText('Choose a kind for this location.');
    await u.click(add);
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), FIRST);
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), '');
    await u.click(add);
    expect(calls).toEqual([]);
    expect(within(dialog).getByText('Choose a kind for this location.')).toBeInTheDocument();
  });

  it('RED2 L-3: after a refusal banner, re-choosing "Choose a kind" and pressing clears the banner and sends nothing more', async () => {
    const L1 = 'Another location in this warehouse already uses this code. Choose a different code.';
    const calls = stubFetch([refused('storage_location.code_is_unique_per_warehouse', L1)]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'A1');
    const kind = within(dialog).getByRole('combobox', { name: 'Kind' });
    await u.selectOptions(kind, PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await within(dialog).findByText(L1);
    await u.selectOptions(kind, '');
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await within(dialog).findByText('Choose a kind for this location.');
    expect(within(dialog).queryByText('Not added')).toBeNull();
    expect(within(dialog).queryByText(L1)).toBeNull();
    expect(calls).toHaveLength(1);
  });

  it('RED2 L-4: reopening the dialog starts empty again (no kind carried over)', async () => {
    stubFetch([]);
    const u = userEvent.setup();
    show();
    let dialog = await openAdd(u);
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    dialog = await openAdd(u);
    expect(within(dialog).getByRole('combobox', { name: 'Kind' })).toHaveValue('');
  });

  it('RED2 L-5(d): with option help and an error together, Kind is described by the hint then the error', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/kind', code: 'not_a_location_kind', message: 'Use one of: bin, shelf, zone, staging, quarantine.' }] })]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.type(within(dialog).getByRole('textbox', { name: 'Code' }), 'B9');
    const kind = within(dialog).getByRole('combobox', { name: 'Kind' });
    await u.selectOptions(kind, HELD);
    await u.click(within(dialog).getByRole('button', { name: 'Add location' }));
    await waitFor(() => expect(kind).toHaveAttribute('aria-invalid', 'true'));
    expect(kind.getAttribute('aria-describedby')).toBe('new-location-kind-hint new-location-kind-error');
  });

  it('Cancel sends nothing and returns focus to Add location', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    const dialog = await openAdd(u);
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(addButton()).toHaveFocus());
    expect(calls).toEqual([]);
  });
});

describe('Add location — no kind is preselected, whatever the served list (A-21; RED L-1 superseded by the UX ruling)', () => {
  it('kinds ["shelf", "bin"]: the select starts on the empty option, which comes first', () => {
    const ref = createRef<HTMLElement>();
    render(<AddLocationForm warehouseCode="MAIN" kinds={['shelf', 'bin']} create={async () => ({ kind: 'server-error' })} createCode={null} onClose={() => {}} fallbackFocusRef={ref} />);
    const kind = screen.getByRole('combobox', { name: 'Kind' });
    expect(kind).toHaveValue('');
    expect(within(kind).getAllByRole('option').map((o) => o.getAttribute('value'))).toEqual(['', 'shelf', 'bin']);
  });
});

describe('Edit location — the Kind control (B-5, B-6, step 4.5)', () => {
  it('RED L-5 (R6, D-5 pinned): on a quarantine-like row with its kind kept, the hint is the Edit hint then the option help', async () => {
    const u = userEvent.setup();
    show({ canEdit: true });
    await u.click(within(section()).getByRole('button', { name: 'Edit Q1' }));
    await screen.findByRole('dialog', { name: 'Edit location Q1' });
    expect(document.getElementById('location-kind-hint')?.textContent).toBe(`${KIND_LABELS[HELD]!.editHint} ${KIND_LABELS[HELD]!.optionHelp}`);
  });

  it('RED2 M-1 (Edit, shipped in step 4.4): when focus lands on Code after a 422, the error is already linked', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/code', code: 'blank', message: 'This field may not be blank.' }] })]);
    const u = userEvent.setup();
    show({ canEdit: true });
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    const code = within(dialog).getByRole('textbox', { name: 'Code' });
    await u.clear(code);
    await u.type(code, ' ');
    const atFocus: Array<string | null> = [];
    code.addEventListener('focus', () => atFocus.push(code.getAttribute('aria-describedby')));
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(code).toHaveFocus());
    expect(atFocus.at(-1)).toContain('location-code-error');
  });

  it('RED2 M-1 (Edit): when focus lands on Kind after a 422 on Kind, the error is already linked', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/kind', code: 'not_a_location_kind', message: 'Use one of: bin, shelf, zone, staging, quarantine.' }] })]);
    const u = userEvent.setup();
    show({ canEdit: true });
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    const kind = within(dialog).getByRole('combobox', { name: 'Kind' });
    await u.selectOptions(kind, PLAIN);
    const atFocus: Array<string | null> = [];
    kind.addEventListener('focus', () => atFocus.push(kind.getAttribute('aria-describedby')));
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(kind).toHaveFocus());
    expect(atFocus.at(-1)).toContain('location-kind-error');
  });

  it('RED M-1 (Edit): a later 422 clears the earlier "Not saved" banner', async () => {
    const L2 = 'Another location in this warehouse already uses this code. Keep the current code or choose a different one.';
    stubFetch([refused('storage_location.code_is_unique_per_warehouse', L2), problem(422, 'validation-failed', { errors: [{ pointer: '/code', code: 'blank', message: 'This field may not be blank.' }] })]);
    const u = userEvent.setup();
    show({ canEdit: true });
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    const code = within(dialog).getByRole('textbox', { name: 'Code' });
    await u.type(code, 'x');
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    await within(dialog).findByText(L2);
    await u.clear(code);
    await u.type(code, ' ');
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(code).toHaveAttribute('aria-invalid', 'true'));
    expect(within(dialog).queryByText('Not saved')).toBeNull();
    expect(within(dialog).queryByText(L2)).toBeNull();
  });

  it('the served current kind\'s Edit hint shows (quarantine-like entry); a plain kind shows none', async () => {
    const u = userEvent.setup();
    show({ canEdit: true, initial: { kind: 'ok', locations: [A1, Q1] } });
    await u.click(within(section()).getByRole('button', { name: 'Edit Q1' }));
    let dialog = await screen.findByRole('dialog', { name: 'Edit location Q1' });
    expect(within(dialog).getByText(new RegExp(KIND_LABELS[HELD]!.editHint!.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))).toBeInTheDocument();
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    dialog = await screen.findByRole('dialog', { name: 'Edit location A1' });
    expect(within(dialog).queryByText(new RegExp(KIND_LABELS[HELD]!.editHint!.slice(0, 20)))).toBeNull();
    expect(within(dialog).getByRole('combobox', { name: 'Kind' })).not.toHaveAttribute('aria-describedby');
  });

  it('the Edit hint follows the SERVED current kind, not the chosen one; the chosen kind adds its option help (mutation M12)', async () => {
    const u = userEvent.setup();
    show({ canEdit: true, initial: { kind: 'ok', locations: [A1, Q1] } });
    await u.click(within(section()).getByRole('button', { name: 'Edit Q1' }));
    let dialog = await screen.findByRole('dialog', { name: 'Edit location Q1' });
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    const hint = () => document.getElementById('location-kind-hint');
    expect(hint()?.textContent).toBe(KIND_LABELS[HELD]!.editHint);
    await u.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    dialog = await screen.findByRole('dialog', { name: 'Edit location A1' });
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), HELD);
    expect(hint()?.textContent).toBe(KIND_LABELS[HELD]!.optionHelp);
  });

  it('a kind-only change sends exactly { kind }', async () => {
    const saved = { ...A1, kind: PLAIN };
    const calls = stubFetch([json(200, saved), json(200, coll([saved, Q1]))]);
    const u = userEvent.setup();
    show({ canEdit: true });
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), PLAIN);
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(pageRegion()).toHaveTextContent('A1 was saved.'));
    expect(JSON.parse(calls[0]!.body!)).toEqual({ kind: PLAIN });
  });

  it('both changed: sends { code, kind }; choosing the current kind back leaves Save unavailable', async () => {
    const saved = { ...A1, code: 'A9', kind: PLAIN };
    const calls = stubFetch([json(200, saved), json(200, coll([saved, Q1]))]);
    const u = userEvent.setup();
    show({ canEdit: true });
    await u.click(within(section()).getByRole('button', { name: 'Edit A1' }));
    const dialog = await screen.findByRole('dialog');
    const kind = within(dialog).getByRole('combobox', { name: 'Kind' });
    await u.selectOptions(kind, PLAIN);
    await u.selectOptions(kind, FIRST);
    expect(within(dialog).getByRole('button', { name: 'Save' })).toHaveAttribute('aria-disabled', 'true');
    await u.selectOptions(kind, PLAIN);
    const code = within(dialog).getByRole('textbox', { name: 'Code' });
    await u.clear(code);
    await u.type(code, 'A9');
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(pageRegion()).toHaveTextContent('A9 was saved.'));
    expect(JSON.parse(calls[0]!.body!)).toEqual({ code: 'A9', kind: PLAIN });
  });

  it('L13 (taking a stocked location out of quarantine without being an owner): "Not saved", the sentence as sent; Save stays', async () => {
    stubFetch([refused('storage_location.release_needs_owner', 'This location holds stock, so only an owner can take it out of quarantine.')]);
    const u = userEvent.setup();
    show({ canEdit: true });
    await u.click(within(section()).getByRole('button', { name: 'Edit Q1' }));
    const dialog = await screen.findByRole('dialog');
    await u.selectOptions(within(dialog).getByRole('combobox', { name: 'Kind' }), FIRST);
    await u.click(within(dialog).getByRole('button', { name: 'Save' }));
    expect(await within(dialog).findByText('This location holds stock, so only an owner can take it out of quarantine.')).toBeInTheDocument();
    expect(within(dialog).getByText('Not saved')).toHaveFocus();
    expect(within(dialog).getByRole('button', { name: 'Save' })).toBeInTheDocument();
  });

  it('a row whose kind the served list lacks keeps it as the first option, as sent', async () => {
    const odd = loc('77777777-7777-4777-8777-777777777777', 'P1', 'pallet');
    const u = userEvent.setup();
    show({ canEdit: true, initial: { kind: 'ok', locations: [odd, N1] } });
    await u.click(within(section()).getByRole('button', { name: 'Edit P1' }));
    const dialog = await screen.findByRole('dialog');
    const kind = within(dialog).getByRole('combobox', { name: 'Kind' });
    expect(kind).toHaveValue('pallet');
    expect(within(kind).getAllByRole('option')[0]).toHaveTextContent('pallet');
  });
});
