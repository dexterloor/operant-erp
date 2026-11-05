import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { WarehousePage } from '@/app/(app)/warehouses/[id]/WarehousePage';
import type { Warehouse } from '@/lib/api/warehouses';
import { PAGE_STATES } from '@/copy/shell';
import { formatDate } from '@/lib/format/dates';
import { TZ, json, problem, refused, renderWired, stubFetch } from './harness';

// warehouses-ux-copy.md B-3 (Revision 1), Revision 7 A-19, step 4.1: the warehouse page is the editor. Code, Name and
// Country only; Save sends the changed fields alone; a 422's field sentences show beside their fields; a 409 shows the
// server's sentence under "Not saved"; an archived warehouse is read-only under its ST-9b banner, with no Restore yet.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const WID = '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
const MAIN: Warehouse = { id: WID, code: 'MAIN', name: 'Main warehouse', country_code: 'GB', archived_at: null };
const show = (w: Warehouse = MAIN, canEdit = true) => renderWired(<WarehousePage initial={w} canEdit={canEdit} />);
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const field = (name: string) => screen.getByRole('textbox', { name });
const save = () => screen.getByRole('button', { name: 'Save' });
const violation = (pointer: string, message: string, code = 'blank') => problem(422, 'validation-failed', { errors: [{ pointer, code, message }] });

describe('/warehouses/[id] — the editor (B-3)', () => {
  it('h1 is "{code} — {name}"; the three fields only, with their hints', () => {
    show();
    expect(screen.getByRole('heading', { level: 1, name: 'MAIN — Main warehouse' })).toBeInTheDocument();
    expect(screen.getAllByRole('textbox').map((i) => i.getAttribute('name'))).toEqual(['code', 'name', 'country_code']);
    expect(field('Name')).toHaveAccessibleDescription('Renaming changes the name shown on orders and pick lists that already name this warehouse.');
    expect(field('Country')).toHaveAttribute('maxlength', '2');
    expect(field('Country')).toHaveAttribute('autocomplete', 'off');
    expect(field('Country')).toHaveAttribute('spellcheck', 'false');
  });

  it('Save is aria-disabled with "Nothing to save." until a field differs, and again when it is typed back', async () => {
    const u = userEvent.setup();
    show();
    expect(save()).toHaveAttribute('aria-disabled', 'true');
    expect(save()).toHaveAccessibleDescription('Nothing to save.');
    await u.type(field('Name'), 'x');
    expect(save()).not.toHaveAttribute('aria-disabled');
    await u.type(field('Name'), '{Backspace}');
    expect(save()).toHaveAttribute('aria-disabled', 'true');
  });

  it('the Country field upper-cases as typed', async () => {
    const u = userEvent.setup();
    show();
    await u.clear(field('Country'));
    await u.type(field('Country'), 'fr');
    expect(field('Country')).toHaveValue('FR');
  });

  it('Save sends only the changed fields (PATCH), re-reads, says "{code} was saved." and focuses the h1', async () => {
    const after = { ...MAIN, code: 'HUB' };
    // RED L-1: the re-read differs from the 200, so the test sees which one the page shows.
    const calls = stubFetch([json(200, after), json(200, { ...after, name: 'Main warehouse (re-read)' })]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Code'));
    await u.type(field('Code'), 'HUB');
    await u.click(save());
    await waitFor(() => expect(region()).toHaveTextContent('HUB was saved.'));
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(calls[0]!.url).toContain(`/bff/api/warehouses/${WID}`);
    expect(JSON.parse(calls[0]!.body!)).toEqual({ code: 'HUB' });
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('HUB — Main warehouse (re-read)');
    expect(region()).toHaveTextContent('HUB was saved.');
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
    expect(save()).toHaveAttribute('aria-disabled', 'true');
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('a 422 puts the field sentence beside its field, not in a banner, and focuses that field', async () => {
    stubFetch([violation('/country_code', 'Use the two-letter country code, for example GB.', 'not_a_country_code')]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Country'));
    await u.type(field('Country'), 'G');
    await u.click(save());
    await waitFor(() => expect(field('Country')).toHaveAttribute('aria-invalid', 'true'));
    expect(field('Country')).toHaveAccessibleDescription(expect.stringContaining('Use the two-letter country code, for example GB.'));
    expect(screen.queryByText('Not saved')).toBeNull();
    expect(field('Country')).toHaveFocus();
    expect(field('Code')).not.toHaveAttribute('aria-invalid');
  });

  it('a field sentence does not outlive a later failure: a 422 on Country, then a 500 (RED L-3)', async () => {
    stubFetch([violation('/country_code', 'Use the two-letter country code, for example GB.', 'not_a_country_code'), json(500, {})]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Country'));
    await u.type(field('Country'), 'G');
    await u.click(save());
    await waitFor(() => expect(field('Country')).toHaveAttribute('aria-invalid', 'true'));
    await u.click(save());
    expect(await screen.findByText("We can't tell whether your changes to MAIN were saved. Reload the page to check.")).toBeInTheDocument();
    expect(field('Country')).not.toHaveAttribute('aria-invalid');
  });

  it('a 422 pointer that names no field shows its sentence in the "Not saved" banner', async () => {
    stubFetch([violation('', 'Something about the whole form.', 'x')]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText('Something about the whole form.')).toBeInTheDocument();
    expect(screen.getByText('Not saved')).toHaveFocus();
  });

  it('a 409 shows the server sentence verbatim under "Not saved", focus on the heading', async () => {
    const sentence = 'Another warehouse already uses this code. Keep the current code or choose a different one.';
    stubFetch([refused('warehouse.code_is_unique_per_workspace', sentence)]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Code'));
    await u.type(field('Code'), 'TAKEN');
    await u.click(save());
    expect(await screen.findByText(sentence)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText('Not saved')).toHaveFocus());
    expect(field('Code')).not.toHaveAttribute('aria-invalid');
  });

  it('a 403 on Save: the lost-permission sentence names "Edit warehouses" (B-8)', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText(/which you no longer have/)).toHaveTextContent('This needs the Edit warehouses permission, which you no longer have. Your workspace administrator can grant it.');
  });

  it('a 404 on Save: "Not saved" with ST-5\'s inline words', async () => {
    stubFetch([problem(404, 'not-found')]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText(PAGE_STATES.notFoundInline)).toBeInTheDocument();
  });

  it('a lost connection: "We can\'t tell whether your changes to {code} were saved…"', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => Promise.reject(new TypeError('offline'))));
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText("We can't tell whether your changes to MAIN were saved. Reload the page to check.")).toBeInTheDocument();
    expect(screen.getByText("We couldn't reach the server.")).toBeInTheDocument();
  });

  it('saved but the re-read failed: the region error, focus on its heading', async () => {
    stubFetch([json(200, { ...MAIN, name: 'Mainx' }), json(503, {})]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    const heading = await screen.findByRole('heading', { name: 'Something went wrong on our side.' });
    expect(screen.getByText("We couldn't load this warehouse again, so what's shown may be out of date.")).toBeInTheDocument();
    await waitFor(() => expect(heading).toHaveFocus());
  });

  it('the re-read answers 403 → ST-4b for warehouses; 404 → ST-5 back to the list', async () => {
    stubFetch([json(200, MAIN), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    const { unmount } = show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByRole('heading', { level: 1, name: "You don't have access to warehouses." })).toBeInTheDocument();
    expect(screen.getByText(/needs the/)).toHaveTextContent('This needs the View warehouses permission. Your workspace administrator can grant it.');
    unmount();
    stubFetch([json(200, MAIN), problem(404, 'not-found')]);
    const u2 = userEvent.setup();
    show();
    await u2.type(field('Name'), 'x');
    await u2.click(save());
    expect(await screen.findByRole('link', { name: 'Back to the list' })).toHaveAttribute('href', '/warehouses');
  });
});

describe('/warehouses/[id] — read-only', () => {
  it('without UpdateWarehouse: plain text, no field, no Save', () => {
    show(MAIN, false);
    expect(screen.queryByRole('textbox')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    const dl = document.querySelector('dl')!;
    expect(within(dl).getByText('MAIN')).toBeInTheDocument();
    expect(within(dl).getByText('GB')).toBeInTheDocument();
  });

  it('an archived warehouse: the ST-9b banner with its date, read-only even with UpdateWarehouse, and no Restore yet (R3-8)', () => {
    const at = '2026-03-04T10:00:00Z';
    show({ ...MAIN, archived_at: at }, true);
    expect(screen.getByText(/This warehouse was archived on/)).toHaveTextContent(`This warehouse was archived on ${formatDate(at, TZ)}.`);
    expect(screen.queryByRole('textbox')).toBeNull();
    expect(screen.queryByRole('button')).toBeNull();
    expect(document.body).not.toHaveTextContent(/restore/i);
  });
});
