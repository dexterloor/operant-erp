import { StrictMode, useState } from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ push: vi.fn(), refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ push: nav.push, refresh: nav.refresh }) }));
import { leaveArrivalMessage, takeArrivalMessage } from '@/app/(app)/arrivalMessage';
import { BffProvider } from '@/app/(app)/BffProvider';
import { NewSupplierPage } from '@/app/(app)/suppliers/new/NewSupplierPage';
import { SupplierPage } from '@/app/(app)/suppliers/[id]/SupplierPage';
import { SupplierCreateDenied } from '@/app/(app)/suppliers/SupplierStates';
import { RouteFocus } from '@/components/shell/RouteFocus';
import { TooltipProvider } from '@/components/ui/Tooltip';
import type { Supplier } from '@/lib/api/suppliers';
import { SID, TZ, json, problem, refused, renderWired, stubFetch, type Reply } from './harness';

// Suppliers S-2 (suppliers-plan.md Revision 1 — FINAL §R1-4 S-2 over Revision 0 S-2; suppliers-ux-copy.md Revision 1 —
// FINAL S-2; suppliers-final-red.md L-1): `/suppliers/new` creates a supplier with a fresh Idempotency-Key per submit
// (the create does not replay on it), Payment terms starts empty and is required on the client (H-2 (a)), the Currency
// hint carries no catalogue sentence until the catalogue exists (L-1), and the new supplier's page speaks
// "{code} was created." once, after RouteFocus has moved focus to its h1 (S-1 D-3).

afterEach(() => {
  vi.unstubAllGlobals();
  nav.push.mockReset();
  takeArrivalMessage('');
});

const NEW_ID = '7b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e';
const ACME: Supplier = { id: NEW_ID, code: 'ACME', name: 'Acme Supplies', country_code: 'GB', currency_code: 'GBP', payment_terms_days: 30, archived_at: null };
const field = (name: string) => screen.getByRole('textbox', { name });
const submit = () => screen.getByRole('button', { name: 'Create supplier' });
const TERMS = 'Payment terms (days)';
const posts = (calls: { method: string }[]) => calls.filter((c) => c.method === 'POST');
const CODE_HINT = "A short code people will recognise, for example ACME. Two suppliers can't share a code unless one of them is archived, and capitals and spaces count.";
const TERMS_HINT = 'How many days you have to pay this supplier. For your records only: nothing else in the app uses it.';
const TERMS_REQUIRED = 'Enter a number of days, or 0.';

async function fill(u: ReturnType<typeof userEvent.setup>, { code = 'ACME', name = 'Acme Supplies', country = 'gb', currency = 'gbp', terms = '30' } = {}) {
  if (code !== '') await u.type(field('Code'), code);
  if (name !== '') await u.type(field('Name'), name);
  if (country !== '') await u.type(field('Country'), country);
  if (currency !== '') await u.type(field('Currency'), currency);
  if (terms !== '') await u.type(field(TERMS), terms);
}

describe('/suppliers/new — the form (UX S-2 NEW_SUPPLIER)', () => {
  it('h1 New supplier; Code, Name, Country, Currency, Payment terms (days) in that order, with their hints; no Name hint; Cancel goes to the list', () => {
    renderWired(<NewSupplierPage />);
    expect(screen.getByRole('heading', { level: 1, name: 'New supplier' })).toBeInTheDocument();
    expect(screen.getAllByRole('textbox').map((i) => i.getAttribute('name'))).toEqual(['code', 'name', 'country_code', 'currency_code', 'payment_terms_days']);
    expect(field('Code')).toHaveAccessibleDescription(CODE_HINT);
    expect(field('Name')).not.toHaveAccessibleDescription();
    expect(field('Country')).toHaveAccessibleDescription('Two letters, for example GB.');
    expect(field(TERMS)).toHaveAccessibleDescription(TERMS_HINT);
    expect(screen.getByRole('link', { name: 'Cancel' })).toHaveAttribute('href', '/suppliers');
  });

  it('final RED L-1: the Currency hint is "Three letters, for example GBP." and nothing else — no catalogue sentence before the catalogue exists (S-3)', () => {
    renderWired(<NewSupplierPage />);
    expect(field('Currency')).toHaveAccessibleDescription('Three letters, for example GBP.');
    expect(document.body.textContent).not.toMatch(/catalogue/i);
  });

  it('H-2 (a): Payment terms starts empty (no prefilled 30); Country and Currency are upper-cased as typed', async () => {
    const u = userEvent.setup();
    renderWired(<NewSupplierPage />);
    expect(field(TERMS)).toHaveValue('');
    await u.type(field('Country'), 'gb');
    await u.type(field('Currency'), 'gbp');
    expect(field('Country')).toHaveValue('GB');
    expect(field('Currency')).toHaveValue('GBP');
    expect(field('Country')).toHaveAttribute('maxlength', '2');
    expect(field('Currency')).toHaveAttribute('maxlength', '3');
  });

  it('201: POSTs exactly the typed fields, terms "30" as the JSON number 30, with an Idempotency-Key; leaves "{code} was created." for the new page and navigates there', async () => {
    const calls = stubFetch([json(201, ACME)]);
    const u = userEvent.setup();
    renderWired(<NewSupplierPage />);
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/suppliers/${NEW_ID}`));
    expect(nav.push).toHaveBeenCalledTimes(1);
    expect(calls).toHaveLength(1);
    expect(calls[0]!.method).toBe('POST');
    expect(calls[0]!.url).toMatch(/\/bff\/api\/suppliers$/);
    expect(JSON.parse(calls[0]!.body!)).toEqual({ code: 'ACME', name: 'Acme Supplies', country_code: 'GB', currency_code: 'GBP', payment_terms_days: 30 });
    expect(calls[0]!.headers.get('idempotency-key')).toMatch(/^[0-9a-f-]{36}$/);
    expect(takeArrivalMessage(`/suppliers/${NEW_ID}`)).toBe('ACME was created.');
  });

  it('an in-flight submit is sent once: a second press and an Enter while busy send nothing', async () => {
    const calls = stubFetch(['hang']);
    const u = userEvent.setup();
    renderWired(<NewSupplierPage />);
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(submit()).toHaveAttribute('aria-busy', 'true'));
    await u.click(submit());
    await u.type(field('Name'), '{Enter}');
    expect(posts(calls)).toHaveLength(1);
  });

  it('the form itself refuses a second submit while busy, whatever triggers it (a submit event, not the button)', async () => {
    const calls = stubFetch(['hang']);
    const u = userEvent.setup();
    renderWired(<NewSupplierPage />);
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(submit()).toHaveAttribute('aria-busy', 'true'));
    fireEvent.submit(submit().closest('form')!);
    await new Promise((r) => setTimeout(r, 10));
    expect(posts(calls)).toHaveLength(1);
  });

  it('a malformed 201 body (no id) → "Something went wrong on our side." + the interrupted sentence; nothing navigates, no arrival message is left', async () => {
    const { id: _id, ...noId } = ACME;
    void _id;
    stubFetch([json(201, noId)]);
    const u = userEvent.setup();
    renderWired(<NewSupplierPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText("We can't tell whether ACME was created. Search the list for ACME before you try again.")).toBeInTheDocument();
    expect(screen.getByText('Something went wrong on our side.')).toHaveFocus();
    expect(nav.push).not.toHaveBeenCalled();
    // One take only (a take clears the slot whatever it returns): the address a body without an id would have produced.
    expect(takeArrivalMessage('/suppliers/undefined')).toBeNull();
  });

  it('H-2 (a): Create with Payment terms empty → "Enter a number of days, or 0." beside the field, focused, and NO request', async () => {
    const calls = stubFetch([json(201, ACME)]);
    const u = userEvent.setup();
    renderWired(<NewSupplierPage />);
    await fill(u, { terms: '' });
    await u.click(submit());
    await waitFor(() => expect(field(TERMS)).toHaveAttribute('aria-invalid', 'true'));
    expect(field(TERMS)).toHaveAccessibleDescription(expect.stringContaining(TERMS_REQUIRED));
    expect(field(TERMS)).toHaveFocus();
    expect(screen.queryByText('Not created')).toBeNull();
    expect(calls).toHaveLength(0);
    expect(nav.push).not.toHaveBeenCalled();
  });

  it('a typed non-integer ("30.5") is sent as typed, so the server’s own sentence shows beside the field (suppliers-red M-1)', async () => {
    const calls = stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/payment_terms_days', code: 'out_of_range', message: 'Payment terms must be a whole number of days from 0 to 3650.' }] })]);
    const u = userEvent.setup();
    renderWired(<NewSupplierPage />);
    await fill(u, { terms: '30.5' });
    await u.click(submit());
    await waitFor(() => expect(field(TERMS)).toHaveAttribute('aria-invalid', 'true'));
    expect(JSON.parse(calls[0]!.body!).payment_terms_days).toBe('30.5');
    expect(field(TERMS)).toHaveAccessibleDescription(expect.stringContaining('Payment terms must be a whole number of days from 0 to 3650.'));
    expect(field(TERMS)).toHaveFocus();
  });

  it('a fresh key for every submit: a lost answer says the interrupted sentence (search the list), the second submit carries a new key and its refusal shows as sent', async () => {
    const keys: (string | null)[] = [];
    let n = 0;
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_input: RequestInfo | URL, init: RequestInit = {}) => {
        keys.push(new Headers(init.headers).get('idempotency-key'));
        n += 1;
        if (n === 1) throw new TypeError('offline');
        return refused('supplier.code_is_unique_per_workspace', 'Another supplier already uses this code.');
      }),
    );
    const u = userEvent.setup();
    renderWired(<NewSupplierPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText("We can't tell whether ACME was created. Search the list for ACME before you try again.")).toBeInTheDocument();
    expect(screen.getByText("We couldn't reach the server.")).toHaveFocus();
    await u.click(submit());
    expect(await screen.findByText('Another supplier already uses this code.')).toBeInTheDocument();
    expect(screen.getByText('Not created')).toHaveFocus();
    expect(keys).toHaveLength(2);
    expect(keys[0]).not.toBeNull();
    expect(keys[0]).not.toBe(keys[1]);
    expect(nav.push).not.toHaveBeenCalled();
  });

  it('a lost answer with a code that is empty after trimming names no code: "…the new supplier was created. Check the list of suppliers…"', async () => {
    stubFetch([problem(500, 'internal')]);
    const u = userEvent.setup();
    renderWired(<NewSupplierPage />);
    await fill(u, { code: '   ' });
    await u.click(submit());
    expect(await screen.findByText("We can't tell whether the new supplier was created. Check the list of suppliers before you try again.")).toBeInTheDocument();
  });

  it('a 422 field sentence shows beside its field, not in a banner; focus goes to the first invalid field in screen order', async () => {
    stubFetch([
      problem(422, 'validation-failed', {
        errors: [
          { pointer: '/currency_code', code: 'unknown_reference', message: "This currency isn't supported yet." },
          { pointer: '/country_code', code: 'unknown_reference', message: "This country isn't supported yet." },
        ],
      }),
    ]);
    const u = userEvent.setup();
    renderWired(<NewSupplierPage />);
    await fill(u, { country: 'zz', currency: 'zzz' });
    await u.click(submit());
    await waitFor(() => expect(field('Currency')).toHaveAttribute('aria-invalid', 'true'));
    expect(field('Country')).toHaveFocus();
    expect(field('Currency')).toHaveAccessibleDescription(expect.stringContaining("This currency isn't supported yet."));
    expect(screen.queryByText('Not created')).toBeNull();
  });

  it('a 403 names the Create suppliers permission (SUPPLIER_FORBIDDEN), under "Not created", focused', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    renderWired(<NewSupplierPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(/which you no longer have/)).toHaveTextContent('This needs the Create suppliers permission, which you no longer have. Your workspace administrator can grant it.');
    expect(screen.getByText('Not created')).toHaveFocus();
  });
});

describe('/suppliers/new — an unexpected 429 (items F-4 / T-5)', () => {
  it('takes the shared path: "Something went wrong on our side." + the interrupted sentence, never a "try again"', async () => {
    stubFetch([problem(429, 'too-many-attempts')]);
    const u = userEvent.setup();
    renderWired(<NewSupplierPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText("We can't tell whether ACME was created. Search the list for ACME before you try again.")).toBeInTheDocument();
    expect(screen.getByText('Something went wrong on our side.')).toHaveFocus();
    expect(nav.push).not.toHaveBeenCalled();
  });
});

describe('/suppliers/new without CreateSupplier (ST-4b)', () => {
  it('names new suppliers and the Create suppliers permission', () => {
    renderWired(<SupplierCreateDenied />);
    expect(document.body.textContent).toContain('new suppliers');
    expect(document.body.textContent).toContain('Create suppliers');
  });
});

// S-1 D-3: the arrival hook lands on the supplier page in this commit, through the page's region. The ordering after
// RouteFocus assumes the new page and the new pathname commit together (the warehouse precedent).
describe.each([
  ['plain', false],
  ['StrictMode', true],
] as const)('/suppliers/[id] speaks the arrival message once, after the h1 has focus (%s)', (_name, strict) => {
  let go: (path: string) => void = () => {};
  function Frame() {
    const [path, setPath] = useState('/suppliers/new');
    go = setPath;
    return (
      <>
        <main id="main-content">{path === '/suppliers/new' ? <h1 tabIndex={-1}>New supplier</h1> : <SupplierPage initial={ACME} canEdit canRestore={false} />}</main>
        <RouteFocus routeKey={path} />
      </>
    );
  }
  const mount = () => {
    const tree = (
      <TooltipProvider>
        <BffProvider session={{ sid: SID, tz: TZ }} reload={() => {}}>
          <Frame />
        </BffProvider>
      </TooltipProvider>
    );
    return render(strict ? <StrictMode>{tree}</StrictMode> : tree);
  };
  const regionEl = () => document.querySelector('section > [role="status"]')!;

  it('the region holds "{code} was created." once, and the h1 is the active element when it does', async () => {
    mount();
    leaveArrivalMessage(`/suppliers/${NEW_ID}`, 'ACME was created.');
    const seen: { text: string; focusedH1: boolean }[] = [];
    const observer = new MutationObserver(() => {
      const r = document.querySelector('section > [role="status"]');
      if (r && r.textContent !== '') seen.push({ text: r.textContent ?? '', focusedH1: document.activeElement?.tagName === 'H1' });
    });
    observer.observe(document.body, { subtree: true, childList: true, characterData: true });
    act(() => go(`/suppliers/${NEW_ID}`));
    await waitFor(() => expect(seen.length).toBeGreaterThan(0));
    await new Promise((r) => setTimeout(r, 20));
    observer.disconnect();
    expect(seen).toEqual([{ text: 'ACME was created.', focusedH1: true }]);
    expect(document.activeElement).toBe(screen.getByRole('heading', { level: 1, name: 'ACME — Acme Supplies' }));
  });

  it('a reload (a later mount of the same page) says nothing: the message was taken once', async () => {
    leaveArrivalMessage(`/suppliers/${NEW_ID}`, 'ACME was created.');
    const first = mount();
    act(() => go(`/suppliers/${NEW_ID}`));
    await waitFor(() => expect(regionEl().textContent).toBe('ACME was created.'));
    first.unmount();
    mount();
    act(() => go(`/suppliers/${NEW_ID}`));
    await new Promise((r) => setTimeout(r, 20));
    expect(regionEl().textContent).toBe('');
  });

  it('a later writer wins: Save after the arrival message → the region says "{code} was saved.", and the arrival sentence is not written again', async () => {
    stubFetch([json(200, { ...ACME, name: 'Acme Two' }), json(200, { ...ACME, name: 'Acme Two' })]);
    leaveArrivalMessage(`/suppliers/${NEW_ID}`, 'ACME was created.');
    mount();
    act(() => go(`/suppliers/${NEW_ID}`));
    await waitFor(() => expect(regionEl().textContent).toBe('ACME was created.'));
    const u = userEvent.setup();
    await u.clear(field('Name'));
    await u.type(field('Name'), 'Acme Two');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(regionEl().textContent).toBe('ACME was saved.'));
    await new Promise((r) => setTimeout(r, 20));
    expect(regionEl().textContent).toBe('ACME was saved.');
  });

  it('a page whose address was not the one left says nothing, and the message is gone', async () => {
    mount();
    leaveArrivalMessage('/suppliers/other', 'X was created.');
    act(() => go(`/suppliers/${NEW_ID}`));
    await new Promise((r) => setTimeout(r, 20));
    expect(regionEl().textContent).toBe('');
    expect(takeArrivalMessage('/suppliers/other')).toBeNull();
  });
});

// suppliers-s2-red.md L-1 (family-wide): `created` calls the page's `onCreated`, which navigates with `router.push`, and the
// form is still on screen until the new page mounts. Here `nav.push` is a mock, so the page never leaves: the window is held
// open. In it the form stays busy, so a press, an Enter or a submit event sends nothing: no second key is spent and no false
// "Not created" appears (the code-taken 409 queued behind the 201 would say it). Every answer other than `created` gives the
// form back, so a form that never clears busy fails here too.
describe('/suppliers/new — after a 201, until the new page takes over (L-1)', () => {
  // A function: a Response body can be read once, so each test needs its own.
  afterEach(() => {
    vi.useRealTimers();
  });
  const TAKEN = () => refused('supplier.code_is_unique_per_workspace', 'Another supplier already uses this code.');
  it('Create supplier stays busy: a second press and an Enter send nothing, and nothing says "Not created"', async () => {
    // A real client navigation takes hundreds of ms to seconds: let a minute pass, so a form that hands Create back after any
    // delay is caught (create-repress-red.md L-1).
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const calls = stubFetch([json(201, ACME), TAKEN()]);
    const u = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderWired(<NewSupplierPage />);
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/suppliers/${NEW_ID}`));
    act(() => {
      vi.advanceTimersByTime(60_000);
    });
    expect(submit()).toHaveAttribute('aria-busy', 'true');
    await u.click(submit());
    await u.type(field('Name'), '{Enter}');
    await new Promise((r) => setTimeout(r, 10));
    expect(posts(calls)).toHaveLength(1);
    expect(screen.queryByText('Not created')).toBeNull();
    expect(nav.push).toHaveBeenCalledTimes(1);
  });

  it('the form itself refuses a submit event after a 201, not only the button', async () => {
    const calls = stubFetch([json(201, ACME), TAKEN()]);
    const u = userEvent.setup();
    renderWired(<NewSupplierPage />);
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/suppliers/${NEW_ID}`));
    fireEvent.submit(submit().closest('form')!);
    await new Promise((r) => setTimeout(r, 10));
    expect(posts(calls)).toHaveLength(1);
    expect(screen.queryByText('Not created')).toBeNull();
  });

  // Every answer the form can show other than `created`, each with the visible sign to wait for. A fresh Response per row (a
  // body is read once). `session-ended` is not here: it renders nothing and the page goes to sign-in, so no Create is left.
  const OTHER_ANSWERS: [string, () => Reply, string][] = [
    ['409 refused', TAKEN, 'Not created'],
    ['422 field-only', () => problem(422, 'validation-failed', { errors: [{ pointer: '/payment_terms_days', code: 'out_of_range', message: 'Payment terms must be a whole number of days from 0 to 3650.' }] }), 'Payment terms must be a whole number of days from 0 to 3650.'],
    ['422 banner-only', () => problem(422, 'validation-failed', { errors: [{ pointer: '', code: 'y', message: 'Out of form.' }] }), 'Not created'],
    [
      'lost connection',
      () => () => {
        throw new TypeError('offline');
      },
      "We couldn't reach the server.",
    ],
    ['500', () => problem(500, 'internal'), 'Something went wrong on our side.'],
    ['403', () => problem(403, 'permission-denied'), 'Not created'],
    ['404', () => problem(404, 'not-found'), 'Not created'],
  ];
  it.each(OTHER_ANSWERS)('any other answer gives the form back (%s): Create supplier is not busy and a second press sends', async (_name, reply, sign) => {
    const calls = stubFetch([reply(), json(201, ACME)]);
    const u = userEvent.setup();
    renderWired(<NewSupplierPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(sign)).toBeInTheDocument();
    expect(submit()).not.toHaveAttribute('aria-busy');
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/suppliers/${NEW_ID}`));
    expect(posts(calls)).toHaveLength(2);
  });
});
