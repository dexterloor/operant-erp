import { act, fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { SupplierPage } from '@/app/(app)/suppliers/[id]/SupplierPage';
import type { Supplier } from '@/lib/api/suppliers';
import { formatDate } from '@/lib/format/dates';
import { TZ, held, json, problem, refused, renderWired, stubFetch, textsOf } from './harness';

// Suppliers S-1 (suppliers-plan.md §R1-4 S-1 tests 5–11 over Revision 0 §2.2 and §4 S-1; suppliers-ux-copy.md Revision 1
// — FINAL S-1; suppliers-final-red.md R-2, L-9; b4-architecture.md Revision 9 R9-2). The supplier page is the editor (IA
// L-4): Code, Name, Country and Payment terms for an UpdateSupplier holder on a live supplier; Currency is read-only text
// in every state of this commit (no hint, no control, never sent). Payment terms is sent as a JSON number when typed as
// 1–15 digits and exactly as typed otherwise, so the server's own sentence shows (R9-2). An archived supplier is
// read-only under its ST-9 banner with Restore supplier for a RestoreSupplier holder.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const SID_ = '8c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f';
const ACME: Supplier = { id: SID_, code: 'ACME', name: 'Acme Supplies', country_code: 'GB', currency_code: 'GBP', payment_terms_days: 30, archived_at: null };
const AT = '2026-03-04T10:00:00Z';
const ARCHIVED: Supplier = { ...ACME, archived_at: AT };
const show = (s: Supplier = ACME, canEdit = true, canRestore = false) => renderWired(<SupplierPage initial={s} canEdit={canEdit} canRestore={canRestore} />);
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const field = (name: string) => screen.getByRole('textbox', { name });
const save = () => screen.getByRole('button', { name: 'Save' });
const ddAfter = (label: string) => screen.getByText(label, { selector: 'dt' }).nextElementSibling as HTMLElement;
const violation = (pointer: string, message: string, code = 'out_of_range') => problem(422, 'validation-failed', { errors: [{ pointer, code, message }] });
/** S5 (`SupplierWriter::ARCHIVED`). */
const S5 = "This supplier is archived, so it can't be changed until it's restored.";
/** S12 (`SupplierWriter::TERMS_OUT_OF_RANGE`). */
const S12 = 'Payment terms must be a whole number of days from 0 to 3650.';
const P1 = 'Some of the same records were being changed at the same moment. Nothing was saved; try again.';
const REREAD_FAILED = "We couldn't load this supplier again, so what's shown may be out of date.";
const CODE_HINT = 'A short code people will recognise, for example ACME. Two suppliers can\'t share a code unless one of them is archived, and capitals and spaces count.';
const NAME_HINT = 'Renaming also changes the name on every purchase order and item that already names this supplier.';
const TERMS_HINT = 'How many days you have to pay this supplier. For your records only: nothing else in the app uses it.';
const TERMS_REQUIRED = 'Enter a number of days, or 0.';

describe('/suppliers/[id] — the editor (UX S-1 SUPPLIER_PAGE)', () => {
  it('h1 "{code} — {name}"; editable Code, Name, Country, Payment terms (days), in that order, with their hints; Currency is text', () => {
    show();
    expect(screen.getByRole('heading', { level: 1, name: 'ACME — Acme Supplies' })).toBeInTheDocument();
    expect([...document.querySelectorAll('form input, form select, form textarea')].map((i) => i.getAttribute('name'))).toEqual(['code', 'name', 'country_code', 'payment_terms_days']);
    expect(field('Code')).toHaveAccessibleDescription(CODE_HINT);
    expect(field('Name')).toHaveAccessibleDescription(NAME_HINT);
    expect(field('Country')).toHaveAccessibleDescription('Two letters, for example GB.');
    expect(field('Payment terms (days)')).toHaveAccessibleDescription(TERMS_HINT);
    expect(field('Payment terms (days)')).toHaveValue('30');
    expect(field('Payment terms (days)')).toHaveAttribute('type', 'text');
    expect(field('Payment terms (days)')).toHaveAttribute('inputmode', 'numeric');
    expect(field('Country')).toHaveAttribute('maxlength', '2');
    expect(ddAfter('Currency')).toHaveTextContent(/^GBP$/);
    // No sort control, no create link, no catalogue, no Delete in S-1.
    expect(screen.queryAllByRole('link')).toEqual([]);
    expect(screen.queryByRole('button', { name: /delete/i })).toBeNull();
  });

  // Test 7: Currency is never a control in S-1, for any viewer and any state, and it has no hint.
  it('test 7 — Currency is not editable in S-1, for any viewer: never an input, never a hint, its served code as text', () => {
    for (const [s, canEdit, canRestore] of [[ACME, true, true], [ACME, false, false], [ARCHIVED, true, true], [ARCHIVED, false, true]] as const) {
      const { unmount } = show(s, canEdit, canRestore);
      expect(screen.queryByRole('textbox', { name: 'Currency' })).toBeNull();
      expect(document.querySelector('[name="currency_code"]')).toBeNull();
      expect(ddAfter('Currency')).toHaveTextContent(/^GBP$/);
      expect(document.body.textContent).not.toMatch(/Three letters|currency can't be changed|catalogue/i);
      unmount();
    }
  });

  it('Country is upper-cased as typed', async () => {
    stubFetch([violation('/country_code', 'This country isn\'t supported yet.', 'unknown_reference')]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Country'));
    await u.type(field('Country'), 'fr');
    expect(field('Country')).toHaveValue('FR');
  });

  it('Save is aria-disabled with "Nothing to save." until a field differs', async () => {
    const u = userEvent.setup();
    show();
    expect(save()).toHaveAttribute('aria-disabled', 'true');
    expect(save()).toHaveAccessibleDescription('Nothing to save.');
    await u.type(field('Name'), 'x');
    expect(save()).not.toHaveAttribute('aria-disabled');
    await u.type(field('Name'), '{Backspace}');
    expect(save()).toHaveAttribute('aria-disabled', 'true');
  });

  // Test 5: only what changed is sent; an untouched terms field (served as the number 30, shown as "30") sends nothing.
  it('test 5 — Save sends only the changed fields (never currency_code, never an untouched terms), re-reads, says "{code} was saved.", focuses the h1', async () => {
    const after = { ...ACME, code: 'ACME2', name: 'Acme Ltd' };
    const calls = stubFetch([json(200, after), json(200, { ...after, name: 'Acme Ltd (re-read)' })]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Code'));
    await u.type(field('Code'), 'ACME2');
    await u.clear(field('Name'));
    await u.type(field('Name'), 'Acme Ltd');
    await u.click(save());
    await waitFor(() => expect(region()).toHaveTextContent('ACME2 was saved.'));
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(calls[0]!.url).toContain(`/bff/api/suppliers/${SID_}`);
    expect(JSON.parse(calls[0]!.body!)).toEqual({ code: 'ACME2', name: 'Acme Ltd' });
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('ACME2 — Acme Ltd (re-read)');
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
    expect(save()).toHaveAttribute('aria-disabled', 'true');
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  // Test 6 (suppliers-red.md M-1; final RED R-2): "30" → the number 30; anything else as typed, the server's sentence shown.
  it('test 6 — terms "45" is sent as the JSON number 45', async () => {
    const calls = stubFetch([json(200, { ...ACME, payment_terms_days: 45 }), json(200, { ...ACME, payment_terms_days: 45 })]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Payment terms (days)'));
    await u.type(field('Payment terms (days)'), '45');
    await u.click(save());
    await waitFor(() => expect(region()).toHaveTextContent('ACME was saved.'));
    expect(calls[0]!.body).toBe('{"payment_terms_days":45}');
    expect(field('Payment terms (days)')).toHaveValue('45');
  });

  it.each(['30.5', '-1', ' 30', 'abc', '1234567890123456'])('test 6 — terms %j is sent as the typed string; the server\'s sentence shows beside the field, focused', async (typed) => {
    const calls = stubFetch([violation('/payment_terms_days', S12)]);
    const u = userEvent.setup();
    show();
    const box = field('Payment terms (days)');
    await u.clear(box);
    await u.type(box, typed);
    await u.click(save());
    await waitFor(() => expect(calls.length).toBe(1));
    expect(JSON.parse(calls[0]!.body!)).toEqual({ payment_terms_days: typed });
    await waitFor(() => expect(box).toHaveAttribute('aria-invalid', 'true'));
    expect(box).toHaveAccessibleDescription(expect.stringContaining(S12));
    expect(screen.queryByText('Not saved')).toBeNull();
    await waitFor(() => expect(box).toHaveFocus());
  });

  it('test 6 — an emptied terms field: "Enter a number of days, or 0." beside it, no request, on two presses, focus on it after the error shows', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    const box = field('Payment terms (days)');
    const invalidAtFocus: (string | null)[] = [];
    box.addEventListener('focus', () => invalidAtFocus.push(box.getAttribute('aria-invalid')));
    await u.clear(box);
    await u.click(field('Name'));
    invalidAtFocus.length = 0;
    await u.click(save());
    await waitFor(() => expect(box).toHaveAttribute('aria-invalid', 'true'));
    expect(box).toHaveAccessibleDescription(expect.stringContaining(TERMS_REQUIRED));
    await waitFor(() => expect(box).toHaveFocus());
    await u.click(field('Name'));
    await u.click(save());
    await waitFor(() => expect(box).toHaveFocus());
    expect(calls).toEqual([]);
    expect(invalidAtFocus).toEqual(['true', 'true']);
  });

  // Test 8: a 422's field sentence beside its field (not in a banner), focus on the first invalid field once it renders.
  it('test 8 — a 422 puts each sentence beside its field and focuses the FIRST invalid one in screen order, after it renders', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/payment_terms_days', code: 'out_of_range', message: S12 }, { pointer: '/name', code: 'blank', message: 'This field may not be blank.' }] })]);
    const u = userEvent.setup();
    show();
    const name = field('Name');
    const invalidAtFocus: (string | null)[] = [];
    name.addEventListener('focus', () => invalidAtFocus.push(name.getAttribute('aria-invalid')));
    await u.type(name, 'x');
    await u.clear(field('Payment terms (days)'));
    await u.type(field('Payment terms (days)'), '9999');
    // Focus is away from Name now, so its arrival after Save is observable.
    invalidAtFocus.length = 0;
    await u.click(save());
    await waitFor(() => expect(field('Payment terms (days)')).toHaveAttribute('aria-invalid', 'true'));
    expect(name).toHaveAttribute('aria-invalid', 'true');
    expect(name).toHaveAccessibleDescription(expect.stringContaining('This field may not be blank.'));
    expect(screen.queryByText('Not saved')).toBeNull();
    await waitFor(() => expect(name).toHaveFocus());
    expect(invalidAtFocus).toEqual(['true']);
  });

  it('a 409 (code taken) shows the server sentence under "Not saved", focused; the edits stay', async () => {
    const S2 = 'Another supplier already uses this code. Keep the current code or choose a different one.';
    const calls = stubFetch([refused('supplier.code_is_unique_per_workspace', S2), json(200, ACME)]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Code'));
    await u.type(field('Code'), 'TAKEN');
    await u.click(save());
    expect(await screen.findByText(S2)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText('Not saved')).toHaveFocus());
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(field('Code')).toHaveValue('TAKEN');
  });

  it('P1 contention on Save: the sentence under "Not saved", the edits kept and Save still offered (a live re-read is not committed)', async () => {
    stubFetch([refused('transaction.contention', P1), json(200, { ...ACME, name: 'Changed elsewhere' })]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), ' Ltd');
    await u.click(save());
    expect(await screen.findByText(P1)).toBeInTheDocument();
    expect(field('Name')).toHaveValue('Acme Supplies Ltd');
    expect(save()).not.toHaveAttribute('aria-disabled');
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('ACME — Acme Supplies');
  });

  // Test 9 (R3 on a live page, 4.4 RED M-1): the refusal is followed by a re-read; archived → read-only, the sentence shown.
  it('test 9 — a refusal that proves the page stale (S5): the page re-reads, turns archived and read-only, shows the sentence under "Not saved" over the re-read values', async () => {
    const reread = { ...ARCHIVED, name: 'Acme (re-read)' };
    const calls = stubFetch([refused('supplier.is_archived', S5), json(200, reread)]);
    const u = userEvent.setup();
    show(ACME, true, true);
    await u.type(field('Name'), ' Ltd');
    await u.click(save());
    await waitFor(() => expect(screen.getByText(S5)).toBeInTheDocument());
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    await waitFor(() => expect(screen.getByText('Not saved')).toHaveFocus());
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('ACME — Acme (re-read)');
    expect(screen.queryAllByRole('textbox')).toEqual([]);
    expect(ddAfter('Name')).toHaveTextContent('Acme (re-read)');
    expect(screen.getByText(/This supplier was archived on/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Restore supplier' })).toBeInTheDocument();
  });

  it('P1 whose re-read finds the supplier archived: the page turns archived, and P1 is never shown as the archived refusal', async () => {
    stubFetch([refused('transaction.contention', P1), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show(ACME, true, true);
    await u.type(field('Name'), 'x');
    await u.click(save());
    await waitFor(() => expect(screen.getByText(/This supplier was archived on/)).toBeInTheDocument());
    expect(screen.queryByText(P1)).toBeNull();
    expect(screen.queryByText('Not saved')).toBeNull();
  });

  it('a 403 on Save names "Edit suppliers"; offline says "We can\'t tell whether your changes to {code} were saved."', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    const { unmount } = show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText(/which you no longer have/)).toHaveTextContent('This needs the Edit suppliers permission, which you no longer have. Your workspace administrator can grant it.');
    unmount();
    vi.stubGlobal('fetch', vi.fn(async () => {
      throw new TypeError('offline');
    }));
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText("We can't tell whether your changes to ACME were saved. Reload the page to check.")).toBeInTheDocument();
  });

  it('saved but the re-read failed: the 200 body is committed, the region error shows, focus on its heading', async () => {
    stubFetch([json(200, { ...ACME, name: 'Acme Ltd' }), json(500, {})]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Name'));
    await u.type(field('Name'), 'Acme Ltd');
    await u.click(save());
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('heading', { level: 2, name: 'Something went wrong on our side.' })).toHaveFocus());
    expect(region()).toHaveTextContent('ACME was saved.');
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('ACME — Acme Ltd');
  });

  it('a stale Retry answered after a later save does not overwrite it (the stamp rule)', async () => {
    const retry = held();
    stubFetch([json(200, { ...ACME, name: 'Acme M6' }), json(500, {}), retry.reply, json(200, { ...ACME, name: 'Acme M7' }), json(200, { ...ACME, name: 'Acme M7' })]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), ' M6');
    await u.click(save());
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await u.clear(field('Name'));
    await u.type(field('Name'), 'Acme M7');
    await u.click(save());
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('ACME — Acme M7'));
    await act(async () => {
      retry.release(json(200, { ...ACME, name: 'Acme M6' }));
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('ACME — Acme M7');
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
  });

  it('the re-read answers 404 → ST-5 back to /suppliers; 403 → ST-4b for suppliers', async () => {
    stubFetch([json(200, ACME), problem(404, 'not-found')]);
    const u = userEvent.setup();
    const { unmount } = show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByRole('link', { name: 'Back to the list' })).toHaveAttribute('href', '/suppliers');
    unmount();
    stubFetch([refused('supplier.is_archived', S5), problem(403, 'permission-denied')]);
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByRole('heading', { level: 1, name: "You don't have access to suppliers." })).toBeInTheDocument();
  });

  it('without UpdateSupplier: plain text, no field, no Save, no hints; terms as "{n} days", "1 day", "0 days"', () => {
    for (const [n, text] of [[30, '30 days'], [1, '1 day'], [0, '0 days']] as const) {
      const { unmount } = show({ ...ACME, payment_terms_days: n }, false);
      expect(screen.queryAllByRole('textbox')).toEqual([]);
      expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
      expect(ddAfter('Code')).toHaveTextContent(/^ACME$/);
      expect(ddAfter('Name')).toHaveTextContent(/^Acme Supplies$/);
      expect(ddAfter('Country')).toHaveTextContent(/^GB$/);
      expect(ddAfter('Payment terms (days)')).toHaveTextContent(new RegExp(`^${text}$`));
      expect(document.body.textContent).not.toMatch(/Renaming also|Two letters|For your records|short code/);
      unmount();
    }
  });
});

// S-1 follow-up (the stamp rule's commit guard; suppliers-s1-red.md L-1): the page's stamp rule and FE-R7, through the page's own
// commit. A Save is refused (S5) and its re-read is held; a Retry, sent after it, commits a newer live reading; the
// held re-read then answers the supplier ARCHIVED. That older reading must be dropped (the stamp rule), and the Save's
// `refused-archived` answer, landing on a live page, must not be kept for a later archived reading (FE-R7).
const lateArchivedRefusal = async () => {
  const reread = held();
  const calls = stubFetch([json(200, { ...ACME, name: 'Acme M6' }), json(500, {}), refused('supplier.is_archived', S5), reread.reply, json(200, { ...ACME, name: 'Acme (Retry)' })]);
  const u = userEvent.setup();
  show(ACME, true, true);
  await u.type(field('Name'), ' M6');
  await u.click(save());
  expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
  await u.type(field('Name'), 'x');
  await u.click(save());
  await waitFor(() => expect(calls).toHaveLength(4));
  await u.click(screen.getByRole('button', { name: 'Retry' }));
  await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('ACME — Acme (Retry)'));
  await act(async () => {
    reread.release(json(200, { ...ARCHIVED, name: 'Acme (stale archived)' }));
    await new Promise((t) => setTimeout(t, 50));
  });
  expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET', 'PATCH', 'GET', 'GET']);
  return u;
};

describe('/suppliers/[id] — late answers (the stamp rule, FE-R7)', () => {
  it('an older re-read answering last does not overwrite a newer one: the page stays on the Retry reading, live (the stamp rule)', async () => {
    await lateArchivedRefusal();
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('ACME — Acme (Retry)');
    expect(screen.queryByText(/This supplier was archived on/)).toBeNull();
    expect(screen.queryByText('Not saved')).toBeNull();
    expect(field('Name')).toBeInTheDocument();
  });

  it('a refused Save landing on a live page is not kept: when the page reads archived later, no old "Not saved" appears (FE-R7, RED L-1)', async () => {
    const u = await lateArchivedRefusal();
    // Archived again by a path that sets no sentence of its own (P1 + an archived re-read).
    stubFetch([refused('transaction.contention', P1), json(200, ARCHIVED)]);
    await u.type(field('Name'), 'y');
    await u.click(save());
    await waitFor(() => expect(screen.getByText(/This supplier was archived on/)).toBeInTheDocument());
    expect(screen.queryByText(S5)).toBeNull();
    expect(screen.queryByText('Not saved')).toBeNull();
  });
});

// S-1 follow-up (suppliers-s1-red.md L-2, L-3): a refusal whose re-read fails shows both the refusal and the region
// error. Which heading ends up focused is pinned as built today (L-3: two claimants, the region error's mount focus runs
// after the banner's; UX TO CONFIRM which should win).
describe('/suppliers/[id] — a refusal whose re-read fails (RED L-2, L-3)', () => {
  it('Save refused (S2), re-read 500: S2 under "Not saved", the region error, the edits kept; focus ends on the region error heading', async () => {
    const S2 = 'Another supplier already uses this code. Keep the current code or choose a different one.';
    stubFetch([refused('supplier.code_is_unique_per_workspace', S2), json(500, {})]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Code'));
    await u.type(field('Code'), 'TAKEN');
    await u.click(save());
    expect(await screen.findByText(S2)).toBeInTheDocument();
    expect(screen.getByText('Not saved')).toBeInTheDocument();
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    expect(field('Code')).toHaveValue('TAKEN');
    await act(async () => {
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(screen.getByRole('heading', { level: 2, name: 'Something went wrong on our side.' })).toHaveFocus();
  });

  it('Restore refused (S10), re-read 500: S10 under "Not restored", the region error, the button kept; focus ends on the region error heading', async () => {
    const S10 = "Another supplier now uses this supplier's name, so it can't be restored. It can be restored once that supplier is renamed.";
    stubFetch([refused('supplier.name_is_unique_per_workspace', S10), json(500, {})]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(screen.getByRole('button', { name: 'Restore supplier' }));
    expect(await screen.findByText(S10)).toBeInTheDocument();
    expect(screen.getByText('Not restored')).toBeInTheDocument();
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Restore supplier' })).toBeInTheDocument();
    await act(async () => {
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(screen.getByRole('heading', { level: 2, name: 'Something went wrong on our side.' })).toHaveFocus();
  });
});

// S-1 follow-up (suppliers-s1-red.md L-4): a served supplier whose terms are not a whole number is malformed, and a
// successful Retry focuses the h1.
describe('/suppliers/[id] — malformed re-read and Retry focus (RED L-4)', () => {
  it('a re-read serving payment_terms_days 30.5 is malformed: the 200 body is shown under the region error', async () => {
    stubFetch([json(200, { ...ACME, name: 'Acme Ltd' }), json(200, { ...ACME, name: 'Acme (bad)', payment_terms_days: 30.5 })]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Name'));
    await u.type(field('Name'), 'Acme Ltd');
    await u.click(save());
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('ACME — Acme Ltd');
  });

  it('a successful Retry clears the region error and focuses the h1', async () => {
    stubFetch([json(200, { ...ACME, name: 'Acme Ltd' }), json(500, {}), json(200, { ...ACME, name: 'Acme (Retry)' })]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Name'));
    await u.type(field('Name'), 'Acme Ltd');
    await u.click(save());
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('ACME — Acme (Retry)'));
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
  });
});

describe('/suppliers/[id] — archived, and Restore supplier (UX S-1 RESTORE_SUPPLIER, ST-9)', () => {
  const restore = () => screen.getByRole('button', { name: 'Restore supplier' });

  it('the ST-9 banner with its date (DT-1), read-only even with UpdateSupplier, no Restore without RestoreSupplier, no hints', () => {
    show(ARCHIVED, true, false);
    expect(screen.getByText(/This supplier was archived on/)).toHaveTextContent(`This supplier was archived on ${formatDate(AT, TZ)}.`);
    expect(screen.queryAllByRole('textbox')).toEqual([]);
    expect(screen.queryByRole('button', { name: 'Restore supplier' })).toBeNull();
    expect(screen.queryByText(/Renaming also changes/)).toBeNull();
  });

  it('a live supplier never shows Restore supplier, whatever the viewer holds', () => {
    show(ACME, true, true);
    expect(screen.queryByRole('button', { name: 'Restore supplier' })).toBeNull();
  });

  // Test 10.
  it('test 10 — restored: POST …/restoration with no body, re-reads, says "{code} was restored.", focuses the h1, turns live without a remount', async () => {
    const calls = stubFetch([json(200, ACME), json(200, { ...ACME, name: 'Acme (re-read)' })]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    const h1 = screen.getByRole('heading', { level: 1 });
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent('ACME was restored.'));
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET']);
    expect(calls[0]!.url).toContain(`/bff/api/suppliers/${SID_}/restoration`);
    expect(calls[0]!.body ?? null).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toBe(h1);
    expect(h1).toHaveTextContent('ACME — Acme (re-read)');
    await waitFor(() => expect(h1).toHaveFocus());
    expect(screen.queryByText(/This supplier was archived on/)).toBeNull();
    expect(field('Name')).toHaveValue('Acme (re-read)');
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('test 10 — refused, and the re-read finds it live: the page turns live and says "{code} was already restored."', async () => {
    stubFetch([refused('supplier.is_not_archived', 'This supplier is not archived, so there is nothing to restore.'), json(200, ACME)]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent('ACME was already restored.'));
    expect(screen.queryByText(/This supplier was archived on/)).toBeNull();
    expect(screen.queryByRole('button', { name: 'Restore supplier' })).toBeNull();
  });

  it('test 10 — refused (name taken), and the re-read still reads archived: the sentence under "Not restored", focused, the button stays', async () => {
    const S10 = "Another supplier now uses this supplier's name, so it can't be restored. It can be restored once that supplier is renamed.";
    stubFetch([refused('supplier.name_is_unique_per_workspace', S10), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText(S10)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText('Not restored')).toHaveFocus());
    expect(restore()).toBeInTheDocument();
    expect(nav.refresh).not.toHaveBeenCalled();
  });

  it('test 10 — P1 contention, then a second press succeeds: Restore supplier is kept after "try again"', async () => {
    stubFetch([refused('transaction.contention', P1), json(200, ARCHIVED), json(200, ACME), json(200, ACME)]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText(P1)).toBeInTheDocument();
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent('ACME was restored.'));
    expect(screen.queryByRole('button', { name: 'Restore supplier' })).toBeNull();
  });

  it('a 403 names "Delete and restore suppliers"; offline says "Press Restore supplier to try again."', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    const { unmount } = show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText(/which you no longer have/)).toHaveTextContent('This needs the Delete and restore suppliers permission, which you no longer have. Your workspace administrator can grant it.');
    unmount();
    vi.stubGlobal('fetch', vi.fn(async () => {
      throw new TypeError('offline');
    }));
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText("We can't tell whether ACME was restored. Press Restore supplier to try again.")).toBeInTheDocument();
  });

  it('restored (200) but the re-read fails: the 200 body is committed, never "restored" over the archived banner', async () => {
    stubFetch([json(200, ACME), json(500, {})]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent('ACME was restored.'));
    expect(screen.queryByText(/This supplier was archived on/)).toBeNull();
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    expect(field('Name')).toHaveValue('Acme Supplies');
  });

  it('after a stale Save turned the page archived, a restore removes the "Not saved" banner with the archived state', async () => {
    stubFetch([refused('supplier.is_archived', S5), json(200, ARCHIVED), json(200, ACME), json(200, ACME)]);
    const u = userEvent.setup();
    show(ACME, true, true);
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText(S5)).toBeInTheDocument();
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent('ACME was restored.'));
    expect(screen.queryByText(S5)).toBeNull();
    expect(within(document.body).queryByText('Not saved')).toBeNull();
  });
});

// fe-r6-saving-status-design.md Revision 1 — FINAL commit A (RED H-2; final RED A-1, A-3): the region is cleared at each
// Save press that sends a request, so a second identical "{code} was saved." is a DOM change and is given again.
describe('/suppliers/[id] — a second identical saved sentence is written again (FE-R6 commit A)', () => {
  const SAVED = 'ACME was saved.';
  const saveName = async (u: ReturnType<typeof userEvent.setup>, typed: string) => {
    await u.type(field('Name'), typed);
    await u.click(save());
  };

  // Mutations A-M2 (no call in SupplierForm) → red.
  it('two Saves of the same supplier in a row each say "ACME was saved.": the region empties at the second press', async () => {
    const one = { ...ACME, name: 'Acme Suppliesx' };
    const two = { ...ACME, name: 'Acme Suppliesxy' };
    stubFetch([json(200, one), json(200, one), json(200, two), json(200, two)]);
    const u = userEvent.setup();
    show();
    const rec = textsOf(region());
    await saveName(u, 'x');
    await waitFor(() => expect(region().textContent).toBe(SAVED));
    await waitFor(() => expect(field('Name')).toHaveValue('Acme Suppliesx'));
    await saveName(u, 'y');
    await waitFor(() => expect(rec.texts).toEqual(['', SAVED, '', SAVED]));
    rec.stop();
  });

  // Final RED A-1: red under A-M10 (call before the emptied-terms return) only because the region holds a sentence at
  // the press.
  it('emptied Payment terms after a Save does not clear the region: nothing is sent, so nothing is cleared', async () => {
    const one = { ...ACME, name: 'Acme Suppliesx' };
    const calls = stubFetch([json(200, one), json(200, one)]);
    const u = userEvent.setup();
    show();
    const rec = textsOf(region());
    await saveName(u, 'x');
    await waitFor(() => expect(region().textContent).toBe(SAVED));
    await u.clear(field('Payment terms (days)'));
    await u.click(save());
    await waitFor(() => expect(field('Payment terms (days)')).toHaveAttribute('aria-invalid', 'true'));
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(rec.texts).toEqual(['', SAVED]);
    rec.stop();
  });

  // The busy/nothing guard: the aria-disabled Save's click is prevented by Button, so the submit is driven directly.
  it('a submit with nothing changed after a Save does not clear the region', async () => {
    const one = { ...ACME, name: 'Acme Suppliesx' };
    const calls = stubFetch([json(200, one), json(200, one)]);
    const u = userEvent.setup();
    show();
    const rec = textsOf(region());
    await saveName(u, 'x');
    await waitFor(() => expect(region().textContent).toBe(SAVED));
    await waitFor(() => expect(save()).toHaveAttribute('aria-disabled', 'true'));
    fireEvent.submit(save().closest('form')!);
    await act(async () => {
      await new Promise((t) => setTimeout(t, 20));
    });
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(rec.texts).toEqual(['', SAVED]);
    rec.stop();
  });
});
