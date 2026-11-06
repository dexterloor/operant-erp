import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { WarehousePage } from '@/app/(app)/warehouses/[id]/WarehousePage';
import type { Warehouse } from '@/lib/api/warehouses';
import { REFUSED_WITHOUT_MESSAGE } from '@/copy/headings';
import { PAGE_STATES } from '@/copy/shell';
import { formatDate } from '@/lib/format/dates';
import { TZ, json, problem, refused, renderWired, stubFetch } from './harness';

// warehouses-ux-copy.md B-3 (Revision 1), Revision 7 A-19, step 4.1: the warehouse page is the editor. Code, Name and
// Country only; Save sends the changed fields alone; a 422's field sentences show beside their fields; a 409 shows the
// server's sentence under "Not saved"; an archived warehouse is read-only under its ST-9b banner. Step 4.2: that banner
// carries **Restore warehouse** for a RestoreWarehouse holder (B-7: no dialog; refusals under "Not restored", as sent).

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const WID = '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
const MAIN: Warehouse = { id: WID, code: 'MAIN', name: 'Main warehouse', country_code: 'GB', archived_at: null };
const show = (w: Warehouse = MAIN, canEdit = true, canRestore = false) => renderWired(<WarehousePage initial={w} canEdit={canEdit} canRestore={canRestore} />);
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

  it('an archived warehouse: the ST-9b banner with its date, read-only even with UpdateWarehouse, and no Restore without RestoreWarehouse', () => {
    const at = '2026-03-04T10:00:00Z';
    show({ ...MAIN, archived_at: at }, true, false);
    expect(screen.getByText(/This warehouse was archived on/)).toHaveTextContent(`This warehouse was archived on ${formatDate(at, TZ)}.`);
    expect(screen.queryByRole('textbox')).toBeNull();
    expect(screen.queryByRole('button')).toBeNull();
    expect(document.body).not.toHaveTextContent(/restore/i);
  });

  it('a live warehouse never shows Restore warehouse, whatever the viewer holds', () => {
    show(MAIN, true, true);
    expect(screen.queryByRole('button', { name: 'Restore warehouse' })).toBeNull();
  });
});

describe('/warehouses/[id] — Restore warehouse (B-7, step 4.2)', () => {
  const AT = '2026-03-04T10:00:00Z';
  const ARCHIVED: Warehouse = { ...MAIN, archived_at: AT };
  const restore = () => screen.getByRole('button', { name: 'Restore warehouse' });

  it('is in the archived banner, no dialog: POST …/restoration with no body, re-reads, says "{code} was restored.", focuses the h1 and turns the page live', async () => {
    // The re-read differs from the 200, so the test sees which one the page shows.
    const calls = stubFetch([json(200, MAIN), json(200, { ...MAIN, name: 'Main warehouse (re-read)' })]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent('MAIN was restored.'));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET']);
    expect(calls[0]!.url).toContain(`/bff/api/warehouses/${WID}/restoration`);
    expect(calls[0]!.body ?? null).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('MAIN — Main warehouse (re-read)');
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
    expect(screen.queryByText(/This warehouse was archived on/)).toBeNull();
    expect(screen.queryByRole('button', { name: 'Restore warehouse' })).toBeNull();
    expect(field('Code')).toHaveValue('MAIN');
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('W5: the code collision shows verbatim under "Not restored", focus on its heading, the button stays', async () => {
    const sentence = "Another warehouse now uses this warehouse's code, so it can't be restored. It can be restored once that warehouse's code is changed.";
    stubFetch([refused('warehouse.code_is_unique_per_workspace', sentence)]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText(sentence)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText('Not restored')).toHaveFocus());
    expect(restore()).toBeInTheDocument();
    expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument();
    expect(nav.refresh).not.toHaveBeenCalled();
  });

  it('a 403: the lost-permission sentence names "Delete and restore warehouses" (B-8)', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText(/which you no longer have/)).toHaveTextContent('This needs the Delete and restore warehouses permission, which you no longer have. Your workspace administrator can grant it.');
    expect(screen.getByText('Not restored')).toBeInTheDocument();
  });

  it('offline, then a repeat press answers W4 and the re-read finds it live: the page turns live and speaks W4 as sent (RED H-1)', async () => {
    const w4 = 'This warehouse is not archived, so there is nothing to restore.';
    let n = 0;
    vi.stubGlobal('fetch', vi.fn(async () => {
      n += 1;
      if (n === 1) throw new TypeError('offline');
      if (n === 2) return refused('warehouse.is_not_archived', w4);
      return json(200, MAIN);
    }));
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText("We can't tell whether MAIN was restored. Press Restore warehouse to try again.")).toBeInTheDocument();
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent(w4));
    expect(screen.queryByText(/This warehouse was archived on/)).toBeNull();
    expect(screen.queryByRole('button', { name: 'Restore warehouse' })).toBeNull();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('W5 then a re-read that still reads archived: the refusal shows and the button stays (RED H-1)', async () => {
    const sentence = "Another warehouse now uses this warehouse's code, so it can't be restored. It can be restored once that warehouse's code is changed.";
    const calls = stubFetch([refused('warehouse.code_is_unique_per_workspace', sentence), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText(sentence)).toBeInTheDocument();
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET']);
    expect(restore()).toBeInTheDocument();
    expect(nav.refresh).not.toHaveBeenCalled();
  });

  it('restored, but the re-read fails: the 200 body is committed, the editor shows, the region error shows, "MAIN was restored." is spoken (RED M-1)', async () => {
    stubFetch([json(200, MAIN), json(500, {})]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText("We couldn't load this warehouse again, so what's shown may be out of date.")).toBeInTheDocument();
    expect(screen.queryByText(/This warehouse was archived on/)).toBeNull();
    expect(screen.queryByRole('button', { name: 'Restore warehouse' })).toBeNull();
    expect(field('Code')).toHaveValue('MAIN');
    expect(region()).toHaveTextContent('MAIN was restored.');
  });

  it('a 409 with an empty message: "Not restored" with the no-message sentence (RED L-1)', async () => {
    stubFetch([refused('warehouse.code_is_unique_per_workspace', ''), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText(REFUSED_WITHOUT_MESSAGE)).toBeInTheDocument();
    expect(screen.getByText('Not restored')).toBeInTheDocument();
  });

  it('a 404: "Not restored" with ST-5\'s inline words (RED L-1)', async () => {
    stubFetch([problem(404, 'not-found')]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText(PAGE_STATES.notFoundInline)).toBeInTheDocument();
    expect(screen.getByText('Not restored')).toBeInTheDocument();
  });

  it('a lost connection: "We can\'t tell whether {code} was restored. Press Restore warehouse to try again."', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => Promise.reject(new TypeError('offline'))));
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText("We can't tell whether MAIN was restored. Press Restore warehouse to try again.")).toBeInTheDocument();
    expect(restore()).toBeInTheDocument();
  });
});
