import { act, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { CustomerPage } from '@/app/(app)/customers/[id]/CustomerPage';
import type { Customer } from '@/lib/api/customers';
import { formatDate } from '@/lib/format/dates';
import { TZ, held, json, problem, refused, renderWired, stubFetch } from './harness';

// Customers C-2a (customers-plan.md Revision 1c R1c-3 tests 1–7, over Revision 1 — FINAL R1-5; customers-c2a-ux-copy.md
// Revision 1a > Revision 1 — FINAL): the customer page is the editor of Code, Country, Currency and Payment terms for an
// UpdateCustomer holder on a live customer; Name is plain text; there is no Restore. Every client sentence is asserted by
// EXACT full text (never a substring or a regex), because K-17 is a prefix of K-16 and K-15 of R3, and each case asserts
// the others absent. The page is rendered whole, so each test drives the real wired Save (`lib/customers/wired.ts`) over
// the BFF, where the behaviour lives.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const CID = '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4e';
const NORTH: Customer = { id: CID, code: 'NORTH', name: 'Northwind Retail', country_code: 'GB', currency_code: 'GBP', payment_terms_days: 30, archived_at: null };
const AT = '2026-03-04T10:00:00Z';
const ARCHIVED: Customer = { ...NORTH, archived_at: AT };

// The server's sentences, byte for byte (`CustomerWriter.php`, `TransactionContention.php`).
/** R3, `CustomerWriter::ARCHIVED` (`CustomerWriter.php:68`, raised at `:234`). Never rendered in C-2a (copy D-6). */
const R3 = "This customer is archived, so it can't be changed until it's restored.";
/** R2, `CustomerWriter::CODE_TAKEN_ON_UPDATE` (a 409 with no pointer, `:269`). */
const R2 = 'Another customer already uses this code. Keep the current code or choose a different one.';
/** R4, `CustomerWriter::TERMS_OUT_OF_RANGE` at the customer cap (365, G-35). */
const R4 = 'Payment terms must be a whole number of days from 0 to 365.';
const R5_COUNTRY = "This country isn't supported yet.";
const R5_CURRENCY = "This currency isn't supported yet.";
const R6_COUNTRY = 'Use the two-letter country code, for example GB.';
const P1 = 'Some of the same records were being changed at the same moment. Nothing was saved; try again.';

// The client's sentences (copy Revision 1 — FINAL R1-1, Revision 1a R1a-1), exact.
const K15 = "This customer is archived, so it can't be changed.";
const K16 = 'Nothing was saved, because this customer was archived at that moment. It has since been restored, so you can press Save again.';
const K17 = 'Nothing was saved, because this customer was archived at that moment.';
const REREAD_FAILED = "We couldn't load this customer again, so what's shown may be out of date.";
const REFUSED_WITHOUT_MESSAGE = "This change wasn't made. Reload the page to see where things stand, then try again.";
const CODE_HINT = "A short code people will recognise, for example ACME. Two customers can't share a code unless one of them is archived, and capitals and spaces count.";
const COUNTRY_HINT = 'Two letters, for example GB. Each address below keeps its own country.';
const CURRENCY_HINT = 'Three letters, for example GBP. The currency this customer is usually invoiced in. For your records only: nothing else in the app uses it.';
const TERMS_HINT = 'Days after the invoice date that payment is due. 0 means due on the invoice date. Invoices already issued keep their due date.';
const CODE_REQUIRED = 'Enter a code.';
const COUNTRY_REQUIRED = 'Enter a country code.';
const CURRENCY_REQUIRED = 'Enter a currency code.';
const TERMS_REQUIRED = 'Enter a number of days, or 0.';
const FORBIDDEN = 'This needs the Edit customers, their addresses and contacts permission, which you no longer have. Your workspace administrator can grant it.';
const ARCHIVED_BANNER = () => `This customer was archived on ${formatDate(AT, TZ)}.`;

/** The failure message test 7 prints when Restore renders (customers-plan.md Revision 1c R1c-3 test 7, verbatim). */
const C2B_RESTORE_MESSAGE =
  'C-2b ships Restore: in the same commit, delete K-15 and its branch, so an archived re-read renders R3 as sent. Keep K-16 (a live re-read), K-17 (a failed re-read) and `CUSTOMER_SAVE_REFUSAL.archived`. R3 would be false or unproven in those two cases (customers-c2a-ux-copy-final-red.md M-2).';

const show = (c: Customer = NORTH, canEdit = true) => renderWired(<CustomerPage customer={c} canEdit={canEdit} />);
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const field = (name: string) => screen.getByRole('textbox', { name });
const save = () => screen.getByRole('button', { name: 'Save' });
const violation = (pointer: string, message: string, code = 'out_of_range') => problem(422, 'validation-failed', { errors: [{ pointer, code, message }] });
/** Every "Not saved" banner on the page, as the exact texts of its body paragraphs (the heading is the banner's first `p`). */
const notSaved = () => screen.queryAllByText('Not saved', { selector: 'p' }).map((h) => [...h.parentElement!.children].filter((n) => n !== h).map((n) => n.textContent));
/** No client or server sentence of the archived family is on the page except the ones named. */
const onlyThese = (...shown: string[]) => {
  const text = document.body.textContent ?? '';
  expect(text).not.toContain(R3);
  expect(text).not.toContain("until it's restored");
  for (const s of [K15, K16, K17]) {
    if (shown.includes(s)) continue;
    // K-17 is a prefix of K-16, so K-17's absence is asserted on every paragraph, exactly.
    expect([...document.querySelectorAll('p')].map((p) => p.textContent), s).not.toContain(s);
  }
  if (!shown.includes(K16)) expect(text).not.toContain('It has since been restored');
};

describe('Customers C-2a — test 1: changed-only PATCH; "{code} was saved." with the served code; focus to the h1', () => {
  it('only the changed fields are sent (no name, nothing untouched); the region says the code as served; the h1 takes focus', async () => {
    // The 200 body serves a code unlike the one typed, so the sentence provably reads the served body (copy D-5).
    const after = { ...NORTH, code: 'NORTH2X', country_code: 'FR' };
    const calls = stubFetch([json(200, after), json(200, after)]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Code'));
    await u.type(field('Code'), 'NORTH2');
    await u.clear(field('Country'));
    await u.type(field('Country'), 'fr');
    await u.click(save());
    await waitFor(() => expect(region().textContent).toBe('NORTH2X was saved.'));
    expect(calls.map((c) => [c.method, new URL(c.url, 'http://x').pathname])).toEqual([
      ['PATCH', `/bff/api/customers/${CID}`],
      ['GET', `/bff/api/customers/${CID}`],
    ]);
    expect(JSON.parse(calls[0]!.body!)).toEqual({ code: 'NORTH2', country_code: 'FR' });
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('NORTH2X — Northwind Retail');
    expect(save()).toHaveAttribute('aria-disabled', 'true');
    expect(save()).toHaveAccessibleDescription('Nothing to save.');
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  // Commit RED M-1 (plant P-1): the 200-body fallback is this family's own copy, so it is pinned here.
  it('saved, but the re-read failed: the 200 body is committed (h1, fields), the region error shows, focus on its heading', async () => {
    const after = { ...NORTH, code: 'NORTH2X' };
    stubFetch([json(200, after), json(500, {})]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Code'));
    await u.type(field('Code'), 'NORTH2');
    await u.click(save());
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('heading', { level: 2, name: 'Something went wrong on our side.' })).toHaveFocus());
    expect(region().textContent).toBe('NORTH2X was saved.');
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('NORTH2X — Northwind Retail');
    expect(field('Code')).toHaveValue('NORTH2X');
    expect(save()).toHaveAttribute('aria-disabled', 'true');
  });

  // Commit RED L-1 (plant P-5): the `isCustomer` guard on the write's 200 body.
  it('a 200 whose body is not a customer is a server error: WENT_WRONG + interrupted(code), no re-read, nothing announced', async () => {
    const calls = stubFetch([json(200, { id: CID })]);
    const u = userEvent.setup();
    show();
    await u.type(field('Code'), 'X');
    await u.click(save());
    expect(await screen.findByText("We can't tell whether your changes to NORTH were saved. Reload the page to check.")).toBeInTheDocument();
    expect(screen.getByText('Something went wrong on our side.', { selector: 'p' })).toBeInTheDocument();
    expect(calls.map((c) => c.method)).toEqual(['PATCH']);
    expect(region().textContent).toBe('');
  });
});

describe('Customers C-2a — test 2: the PATCH never carries name; Name is text', () => {
  it('Name is plain text under its label in the field order, never an input, with no hint; a Save sends no name', async () => {
    const calls = stubFetch([json(200, { ...NORTH, currency_code: 'EUR' }), json(200, { ...NORTH, currency_code: 'EUR' })]);
    const u = userEvent.setup();
    show();
    expect([...document.querySelectorAll('form input, form select, form textarea')].map((i) => i.getAttribute('name'))).toEqual(['code', 'country_code', 'currency_code', 'payment_terms_days']);
    expect(screen.queryByRole('textbox', { name: 'Name' })).toBeNull();
    const nameLabel = screen.getByText('Name', { selector: 'form dt' });
    expect(nameLabel.nextElementSibling?.textContent).toBe('Northwind Retail');
    // In the field order: after Code, before Country.
    expect(field('Code').compareDocumentPosition(nameLabel) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(nameLabel.compareDocumentPosition(field('Country')) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(field('Code')).toHaveAccessibleDescription(CODE_HINT);
    expect(field('Country')).toHaveAccessibleDescription(COUNTRY_HINT);
    expect(field('Currency')).toHaveAccessibleDescription(CURRENCY_HINT);
    expect(field('Payment terms (days)')).toHaveAccessibleDescription(TERMS_HINT);
    await u.clear(field('Currency'));
    await u.type(field('Currency'), 'eur');
    expect(field('Currency')).toHaveValue('EUR');
    await u.click(save());
    await waitFor(() => expect(region().textContent).toBe('NORTH was saved.'));
    expect(calls[0]!.body).toBe('{"currency_code":"EUR"}');
    expect(JSON.parse(calls[0]!.body!)).not.toHaveProperty('name');
  });
});

describe('Customers C-2a — test 3: Payment terms', () => {
  it('"45" is sent as the JSON number 45', async () => {
    const calls = stubFetch([json(200, { ...NORTH, payment_terms_days: 45 }), json(200, { ...NORTH, payment_terms_days: 45 })]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Payment terms (days)'));
    await u.type(field('Payment terms (days)'), '45');
    await u.click(save());
    await waitFor(() => expect(region().textContent).toBe('NORTH was saved.'));
    expect(calls[0]!.body).toBe('{"payment_terms_days":45}');
  });

  it.each(['30.5', '-1', ' 30', 'abc', '1234567890123456'])('%j is sent exactly as typed; the server\'s R4 shows beside Payment terms, focused, with no banner', async (typed) => {
    const calls = stubFetch([violation('/payment_terms_days', R4)]);
    const u = userEvent.setup();
    show();
    const box = field('Payment terms (days)');
    await u.clear(box);
    await u.type(box, typed);
    await u.click(save());
    await waitFor(() => expect(calls.length).toBe(1));
    expect(calls[0]!.body).toBe(JSON.stringify({ payment_terms_days: typed }));
    await waitFor(() => expect(box).toHaveAttribute('aria-invalid', 'true'));
    expect(box).toHaveAccessibleDescription(`${TERMS_HINT} ${R4}`);
    expect(notSaved()).toEqual([]);
    await waitFor(() => expect(box).toHaveFocus());
  });

  it('an emptied terms field: "Enter a number of days, or 0." beside it, no request, on every press', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    const box = field('Payment terms (days)');
    await u.clear(box);
    await u.click(save());
    await waitFor(() => expect(box).toHaveAttribute('aria-invalid', 'true'));
    expect(box).toHaveAccessibleDescription(`${TERMS_HINT} ${TERMS_REQUIRED}`);
    await waitFor(() => expect(box).toHaveFocus());
    await u.click(save());
    await waitFor(() => expect(box).toHaveFocus());
    expect(calls).toEqual([]);
  });
});

describe('Customers C-2a — test 4: an emptied Code, Country or Currency', () => {
  it.each([
    ['Code', CODE_HINT, CODE_REQUIRED],
    ['Country', COUNTRY_HINT, COUNTRY_REQUIRED],
    ['Currency', CURRENCY_HINT, CURRENCY_REQUIRED],
  ])('emptied %s: its sentence beside it, focused, and no request', async (label, hint, sentence) => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.clear(field(label));
    await u.click(save());
    await waitFor(() => expect(field(label)).toHaveAttribute('aria-invalid', 'true'));
    expect(field(label)).toHaveAccessibleDescription(`${hint} ${sentence}`);
    await waitFor(() => expect(field(label)).toHaveFocus());
    expect(calls).toEqual([]);
  });

  it('several emptied: each sentence at once, focus to the first in field order (copy D-7), and no request', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Payment terms (days)'));
    await u.clear(field('Currency'));
    await u.clear(field('Code'));
    await u.click(save());
    await waitFor(() => expect(field('Code')).toHaveAttribute('aria-invalid', 'true'));
    expect(field('Code')).toHaveAccessibleDescription(`${CODE_HINT} ${CODE_REQUIRED}`);
    expect(field('Currency')).toHaveAccessibleDescription(`${CURRENCY_HINT} ${CURRENCY_REQUIRED}`);
    expect(field('Payment terms (days)')).toHaveAccessibleDescription(`${TERMS_HINT} ${TERMS_REQUIRED}`);
    expect(field('Country')).not.toHaveAttribute('aria-invalid');
    await waitFor(() => expect(field('Code')).toHaveFocus());
    expect(calls).toEqual([]);
  });
});

describe('Customers C-2a — test 5: a refused Save re-reads, and the re-read decides', () => {
  const typeAndSave = async () => {
    const u = userEvent.setup();
    show();
    await u.clear(field('Code'));
    await u.type(field('Code'), 'NORTH9');
    await u.click(save());
    return u;
  };
  const expectArchivedK15 = async () => {
    await waitFor(() => expect(notSaved()).toEqual([[K15]]));
    const heading = screen.getByText('Not saved', { selector: 'p' });
    await waitFor(() => expect(heading).toHaveFocus());
    const banner = screen.getByText(/This customer was archived on/);
    expect(banner.textContent).toBe(ARCHIVED_BANNER());
    // Not saved + K-15, THEN the ST-9 banner.
    expect(heading.compareDocumentPosition(banner) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    // The form is gone: read-only, no Save, no Restore.
    expect(screen.queryAllByRole('textbox')).toEqual([]);
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    expect(screen.queryByRole('button', { name: /restore/i })).toBeNull();
    onlyThese(K15);
  };

  it('customer.is_archived, re-read archived: Not saved + K-15, then the ST-9 banner; the form is gone; R3 nowhere', async () => {
    const calls = stubFetch([refused('customer.is_archived', R3), json(200, ARCHIVED)]);
    await typeAndSave();
    await expectArchivedK15();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
  });

  it('customer.is_archived, re-read live: Not saved + K-16, the edits kept, Save still offered', async () => {
    const calls = stubFetch([refused('customer.is_archived', R3), json(200, { ...NORTH })]);
    await typeAndSave();
    await waitFor(() => expect(notSaved()).toEqual([[K16]]));
    await waitFor(() => expect(screen.getByText('Not saved', { selector: 'p' })).toHaveFocus());
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(field('Code')).toHaveValue('NORTH9');
    expect(save()).not.toHaveAttribute('aria-disabled');
    expect(screen.queryByText(/This customer was archived on/)).toBeNull();
    onlyThese(K16);
  });

  it('customer.is_archived, re-read failed: Not saved + K-17, the region error with Retry, the edits kept', async () => {
    const calls = stubFetch([refused('customer.is_archived', R3), json(500, {})]);
    await typeAndSave();
    await waitFor(() => expect(notSaved()).toEqual([[K17]]));
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(field('Code')).toHaveValue('NORTH9');
    onlyThese(K17);
  });

  it.each([
    ['R2 (code taken)', 'customer.code_is_unique_per_workspace', R2],
    ['P1 (transaction.contention)', 'transaction.contention', P1],
  ])('any other refusal — %s — with an archived re-read: Not saved + K-15, the ST-9 banner, the form gone; the served sentence nowhere', async (_, rule, message) => {
    stubFetch([refused(rule, message), json(200, ARCHIVED)]);
    await typeAndSave();
    await expectArchivedK15();
    expect(document.body.textContent).not.toContain(message);
  });

  it.each([
    ['R2, as sent', 'customer.code_is_unique_per_workspace', R2, R2],
    ['no message → REFUSED_WITHOUT_MESSAGE', 'customer.something_else', '', REFUSED_WITHOUT_MESSAGE],
  ])('any other refusal whose re-read fails — %s — with rereadFailed and Retry, the edits kept, and neither K-15, K-16 nor K-17', async (_, rule, message, shown) => {
    stubFetch([refused(rule, message), json(500, {})]);
    await typeAndSave();
    await waitFor(() => expect(notSaved()).toEqual([[shown]]));
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
    expect(field('Code')).toHaveValue('NORTH9');
    onlyThese();
  });

  // Commit RED M-2 (plant P-4): a 422 with an empty violations list is "any other refusal" (R1c-2 item 3).
  it('a 422 with no field violations is "any other refusal": it re-reads, and shows Not saved + REFUSED_WITHOUT_MESSAGE, focused; the edits stay', async () => {
    const calls = stubFetch([problem(422, 'validation-failed'), json(200, NORTH)]);
    await typeAndSave();
    await waitFor(() => expect(notSaved()).toEqual([[REFUSED_WITHOUT_MESSAGE]]));
    await waitFor(() => expect(screen.getByText('Not saved', { selector: 'p' })).toHaveFocus());
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(field('Code')).toHaveValue('NORTH9');
    onlyThese();
  });

  // Commit RED L-3: the 403 and offline cases' call lists are asserted in test 6.
  it('a 422 (field violations) never re-reads', async () => {
    const calls = stubFetch([violation('/country_code', R5_COUNTRY, 'unknown_reference')]);
    const u = await typeAndSave();
    void u;
    await waitFor(() => expect(field('Country')).toHaveAttribute('aria-invalid', 'true'));
    expect(calls.map((c) => c.method)).toEqual(['PATCH']);
  });
});

describe('Customers C-2a — test 6: where each refusal shows', () => {
  it('R2 (a 409 with no pointer, the re-read live) shows as sent in the Not saved banner, its heading focused; the edits stay', async () => {
    const calls = stubFetch([refused('customer.code_is_unique_per_workspace', R2), json(200, NORTH)]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Code'));
    await u.type(field('Code'), 'TAKEN');
    await u.click(save());
    await waitFor(() => expect(notSaved()).toEqual([[R2]]));
    await waitFor(() => expect(screen.getByText('Not saved', { selector: 'p' })).toHaveFocus());
    expect(field('Code')).not.toHaveAttribute('aria-invalid');
    expect(field('Code')).toHaveAccessibleDescription(CODE_HINT);
    expect(field('Code')).toHaveValue('TAKEN');
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    onlyThese();
  });

  it.each([
    ['Country', '/country_code', 'unknown_reference', R5_COUNTRY, COUNTRY_HINT, 'fr'],
    ['Currency', '/currency_code', 'unknown_reference', R5_CURRENCY, CURRENCY_HINT, 'eur'],
    ['Country', '/country_code', 'invalid_shape', R6_COUNTRY, COUNTRY_HINT, 'g'],
  ])('a 422 on %s (%s) shows beside its field, focused, never in a banner', async (label, pointer, code, message, hint, typed) => {
    stubFetch([violation(pointer, message, code)]);
    const u = userEvent.setup();
    show();
    await u.clear(field(label));
    await u.type(field(label), typed);
    await u.click(save());
    await waitFor(() => expect(field(label)).toHaveAttribute('aria-invalid', 'true'));
    expect(field(label)).toHaveAccessibleDescription(`${hint} ${message}`);
    expect(notSaved()).toEqual([]);
    await waitFor(() => expect(field(label)).toHaveFocus());
  });

  it('a 403 on Save: CUSTOMER_FORBIDDEN around UpdateCustomer\'s permission label, in the Not saved banner; no re-read', async () => {
    const calls = stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await u.type(field('Code'), 'X');
    await u.click(save());
    await waitFor(() => expect(notSaved()).toEqual([[FORBIDDEN]]));
    expect(screen.getByText('Edit customers, their addresses and contacts', { selector: 'em' })).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText('Not saved', { selector: 'p' })).toHaveFocus());
    expect(calls.map((c) => c.method)).toEqual(['PATCH']);
  });

  it('transaction.contention with a live re-read: P1 as sent, not K-16; the edits stay', async () => {
    stubFetch([refused('transaction.contention', P1), json(200, NORTH)]);
    const u = userEvent.setup();
    show();
    await u.type(field('Code'), 'X');
    await u.click(save());
    await waitFor(() => expect(notSaved()).toEqual([[P1]]));
    expect(field('Code')).toHaveValue('NORTHX');
    onlyThese();
  });

  it('offline: "We can\'t tell whether your changes to {code} were saved." under COULD_NOT_REACH, with the code shown before the press; no re-read', async () => {
    const offline = vi.fn(async () => {
      throw new TypeError('offline');
    });
    vi.stubGlobal('fetch', offline);
    const u = userEvent.setup();
    show();
    await u.type(field('Code'), 'X');
    await u.click(save());
    expect(await screen.findByText("We can't tell whether your changes to NORTH were saved. Reload the page to check.")).toBeInTheDocument();
    expect(screen.getByText("We couldn't reach the server.", { selector: 'p' })).toBeInTheDocument();
    expect(offline).toHaveBeenCalledTimes(1);
  });
});

// Commit RED M-3: the rows R1c-3 marked "—" are this family's own copies (`CustomerForm`'s `settle`, `CustomerPage`'s
// `failed`), which no supplier test renders, so each is pinned here.
describe('Customers C-2a — the remaining result rows (commit RED M-3)', () => {
  it('a 404 on Save: Not saved + PAGE_STATES.notFoundInline, its heading focused, no re-read', async () => {
    const calls = stubFetch([problem(404, 'not-found')]);
    const u = userEvent.setup();
    show();
    await u.type(field('Code'), 'X');
    await u.click(save());
    await waitFor(() => expect(notSaved()).toEqual([["We couldn't find that. It may have been deleted, or you may not have access."]]));
    await waitFor(() => expect(screen.getByText('Not saved', { selector: 'p' })).toHaveFocus());
    expect(calls).toHaveLength(1);
  });

  it('a 5xx on Save: heading WENT_WRONG + interrupted(code) with the code shown before the press', async () => {
    stubFetch([json(500, {})]);
    const u = userEvent.setup();
    show();
    await u.type(field('Code'), 'X');
    await u.click(save());
    expect(await screen.findByText("We can't tell whether your changes to NORTH were saved. Reload the page to check.")).toBeInTheDocument();
    expect(screen.getByText('Something went wrong on our side.', { selector: 'p' })).toBeInTheDocument();
    expect(screen.queryByText("We couldn't reach the server.")).toBeNull();
  });

  it('a 422 with a form-level error and a field error: Not saved with the message and MIXED_FIELDS_TOO, the field marked, the banner heading focused', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '', code: 'x', message: 'Form-level.' }, { pointer: '/country_code', code: 'unknown_reference', message: R5_COUNTRY }] })]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Country'));
    await u.type(field('Country'), 'fr');
    await u.click(save());
    await waitFor(() => expect(notSaved()).toEqual([['Form-level.', 'Check the marked fields below too.']]));
    expect(field('Country')).toHaveAttribute('aria-invalid', 'true');
    expect(field('Country')).toHaveAccessibleDescription(`${COUNTRY_HINT} ${R5_COUNTRY}`);
    await waitFor(() => expect(screen.getByText('Not saved', { selector: 'p' })).toHaveFocus());
  });

  it('a refused Save whose re-read answers 404 → ST-5, no editable page', async () => {
    stubFetch([refused('customer.code_is_unique_per_workspace', R2), problem(404, 'not-found')]);
    const u = userEvent.setup();
    show();
    await u.type(field('Code'), 'X');
    await u.click(save());
    expect(await screen.findByRole('link', { name: 'Back to the list' })).toHaveAttribute('href', '/customers');
    expect(screen.queryAllByRole('textbox')).toEqual([]);
  });

  it('a refused Save whose re-read answers 403 → ST-4b for customers, no editable page', async () => {
    stubFetch([refused('customer.code_is_unique_per_workspace', R2), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await u.type(field('Code'), 'X');
    await u.click(save());
    expect(await screen.findByRole('heading', { level: 1, name: "You don't have access to customers." })).toBeInTheDocument();
    expect(screen.queryAllByRole('textbox')).toEqual([]);
  });
});

// Commit RED L-7 (the supplier-page.test.tsx:278-297 port): the stamp rule, in this family's own `commit`.
describe('Customers C-2a — late answers (the stamp rule)', () => {
  it('a stale Retry answered after a later save does not overwrite it', async () => {
    const retry = held();
    stubFetch([json(200, { ...NORTH, code: 'N6' }), json(500, {}), retry.reply, json(200, { ...NORTH, code: 'N7' }), json(200, { ...NORTH, code: 'N7' })]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Code'));
    await u.type(field('Code'), 'N6');
    await u.click(save());
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await u.clear(field('Code'));
    await u.type(field('Code'), 'N7');
    await u.click(save());
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('N7 — Northwind Retail'));
    await act(async () => {
      retry.release(json(200, { ...NORTH, code: 'N6' }));
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('N7 — Northwind Retail');
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
  });
});

describe('Customers C-2a — test 7: no Restore; the read-only page for an archived customer and for a seat without customer.edit', () => {
  it.each([
    ['an archived customer, viewed by an UpdateCustomer holder', ARCHIVED, true],
    ['an archived customer, viewed by a seat without customer.edit', ARCHIVED, false],
    ['a live customer, viewed by a seat without customer.edit', NORTH, false],
  ] as const)('%s: C-1\'s read-only page — no field, no hint, no Save, no required sentence, no Restore', (_, c, canEdit) => {
    show(c, canEdit);
    expect(screen.queryByRole('button', { name: /restore/i }), C2B_RESTORE_MESSAGE).toBeNull();
    expect(document.body.textContent, C2B_RESTORE_MESSAGE).not.toMatch(/restore/i);
    expect(screen.queryAllByRole('textbox')).toEqual([]);
    expect(document.querySelector('form')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    expect(screen.queryAllByRole('button')).toEqual([]);
    const text = document.body.textContent ?? '';
    for (const s of [CODE_HINT, COUNTRY_HINT, CURRENCY_HINT, TERMS_HINT, CODE_REQUIRED, COUNTRY_REQUIRED, CURRENCY_REQUIRED, TERMS_REQUIRED]) expect(text).not.toContain(s);
    expect([...document.querySelectorAll('dl > dt')].map((dt) => [dt.textContent, dt.nextElementSibling?.textContent])).toEqual([
      ['Code', 'NORTH'],
      ['Name', 'Northwind Retail'],
      ['Country', 'GB'],
      ['Currency', 'GBP'],
      ['Payment terms (days)', '30 days'],
    ]);
    if (c.archived_at !== null) expect(screen.getByText(/This customer was archived on/).textContent).toBe(ARCHIVED_BANNER());
  });

  it('a live customer for an UpdateCustomer holder offers Save and no Restore', () => {
    show(NORTH, true);
    expect(save()).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /restore/i }), C2B_RESTORE_MESSAGE).toBeNull();
  });

  it('after an archived re-read the page offers no Restore either (the K-15 page)', async () => {
    stubFetch([refused('customer.is_archived', R3), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show();
    await u.type(field('Code'), 'X');
    await u.click(save());
    await waitFor(() => expect(notSaved()).toEqual([[K15]]));
    await act(async () => {
      await new Promise((t) => setTimeout(t, 20));
    });
    expect(screen.queryByRole('button', { name: /restore/i }), C2B_RESTORE_MESSAGE).toBeNull();
    expect(document.body.textContent).not.toContain(CODE_HINT);
  });
});
