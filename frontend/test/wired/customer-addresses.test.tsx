import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
// Final RED M-4 (d), the C-4a final RED L-6 twin: a hoisted router spy, so `router.refresh()` is observable for addresses.
const nav = vi.hoisted(() => ({ refresh: vi.fn(), replace: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh, replace: nav.replace }), usePathname: () => '/customers/x' }));
import { CustomerPage } from '@/app/(app)/customers/[id]/CustomerPage';
import type { Customer, CustomerAddress } from '@/lib/api/customers';
import type { AddressesLoad, ContactsLoad } from '@/lib/customers/load';
import { formatDate } from '@/lib/format/dates';
import { TZ, held, json, page, problem, refused, renderWired, textsOf, type Recorded, type Reply } from './harness';

// Customers C-5a (customers-plan-c4c5.md Revision 1 R1-4 as fixed by customers-plan-c4b-c5-final-red.md M-4, M-5, L-7, L-8,
// L-9, L-10 and cut by its §4; copy customers-c4c5-ux-copy.md Revision 1a > Revision 1 > Revision 0 §3): Add address and
// Edit address on the customer page's Addresses section. The page is rendered whole, so every test drives the real wired
// actions (`lib/customers/wired.ts`) over the BFF, where the behaviour lives. Every client sentence is asserted by EXACT
// full text; every dialog assertion is scoped `within(dialog())` (R-4); every request body is asserted with
// `toStrictEqual` over its full key set (R-6); every `customer.is_archived` fixture's message is the writer's constant byte
// for byte (R-5: `CustomerAddressWriter::ARCHIVED_ON_CREATE / _ON_UPDATE`, `CustomerAddressWriter.php:96`, `:98`). An
// absence is asserted as `queryAllByRole(...).toEqual([])` (C-4b L-6). A focus check after a dialog closes reads focus at
// the moment the status sentence is written (C-4b commit RED M-1), never after a sleep.

beforeEach(() => {
  nav.refresh.mockClear();
});
afterEach(() => {
  vi.unstubAllGlobals();
});

const CID = 'd1b2c3d4-0000-4000-8000-000000000001';
const LIVE: Customer = { id: CID, code: 'NORTH', name: 'Northwind Retail', country_code: 'GB', currency_code: 'GBP', payment_terms_days: 30, archived_at: null };
const AT = '2026-03-04T11:00:00Z';
const ARCHIVED: Customer = { ...LIVE, archived_at: AT };
const address = (n: number, kind: string, line: string, over: Partial<CustomerAddress> = {}): CustomerAddress => ({ id: `a1000000-0000-4000-8000-00000000000${n}`, kind, line, city: `City ${n}`, postal_code: `PC${n}`, country_code: 'GB', ...over });
const okAddresses = (addresses: CustomerAddress[]): AddressesLoad => ({ kind: 'ok', addresses });
const NO_CONTACTS: ContactsLoad = { kind: 'ok', contacts: [] };

// The server's sentences, byte for byte.
/** S-8, `CustomerAddressWriter::ARCHIVED_ON_CREATE` (`CustomerAddressWriter.php:96`). Never rendered in C-5a (copy D-1). */
const S8 = "This customer is archived, so no address can be added to it until it's restored.";
/** S-11 / S-12 / S-13 (`CustomerAddressWriter.php:403`, `:408`, `:427`). */
const S11 = 'This field may not be blank.';
const S12 = 'Use the two-letter country code, for example GB.';
/** S-16 (`TransactionContention.php:32`). */
const P1 = 'Some of the same records were being changed at the same moment. Nothing was saved; try again.';

// The client's sentences (copy Revision 1a §3), exact.
const ADD_ARCHIVED = 'This customer is archived, so no address can be added to it.';
const ADD_THEN_RESTORED = 'Nothing was added, because this customer was archived at that moment. It has since been restored, so you can press Add address again.';
const ADD_AT_ADD = "Nothing was added, because this customer was archived at that moment. We couldn't check whether it still is.";
const NOT_FOUND_INLINE = "We couldn't find that. It may have been deleted, or you may not have access.";
const REFUSED_WITHOUT_MESSAGE = "This change wasn't made. Reload the page to see where things stand, then try again.";
const FORBIDDEN = 'This needs the Edit customers, their addresses and contacts permission, which you no longer have. Your workspace administrator can grant it.';
const KIND_PROMPT = 'Choose a kind';
const KIND_REQUIRED = 'Choose a kind for this address.';
const LINE_REQUIRED = 'Enter an address line.';
const CITY_REQUIRED = 'Enter a city.';
const POSTAL_REQUIRED = 'Enter a postal code.';
const COUNTRY_REQUIRED = 'Enter a country code.';
const COUNTRY_HINT = 'Two letters, for example GB.';
const EDIT_HINT = 'Changing this address changes no order that already uses it, drafts included.';
const KIND_SWITCH_HINT = "Billing addresses can't be chosen as a delivery address on new orders. Orders already using it are not affected.";
const EMPTY_LIVE = 'No addresses yet.';
const EMPTY_ARCHIVED = 'This customer has no addresses.';
const ARCHIVED_PARENT = "Addresses can't be added, changed or removed while the customer is archived.";
const LIST_STALE = "We couldn't load the list again, so it may be out of date.";
const ADDED = (noun: string, line: string) => `The ${noun} “${line}” was added.`;
const ADD_INTERRUPTED = (noun: string, line: string) => `We can't tell whether the ${noun} “${line}” was added. Check this customer's addresses before you try again.`;
/** The ST-9 banner, exact (the date is formatted in the session's zone). */
const ARCHIVED_BANNER = () => `This customer was archived on ${formatDate(AT, TZ)}.`;
const archivedBanner = () => screen.getByText(/This customer was archived on/);

// Routed fetch: each request takes the next reply queued for its route, whatever the order the page sends them in.
type Route = 'add' | 'save' | 'remove' | 'list' | 'customer' | 'customerSave' | 'contacts';
const routeOf = (method: string, url: string): Route => {
  if (/\/addresses$/.test(url)) return method === 'POST' ? 'add' : 'list';
  if (/\/addresses\/[0-9a-f-]{36}$/.test(url) && method === 'PATCH') return 'save';
  // Customers C-5b: Remove address.
  if (/\/addresses\/[0-9a-f-]{36}$/.test(url) && method === 'DELETE') return 'remove';
  if (/\/contacts$/.test(url) && method === 'GET') return 'contacts';
  if (new RegExp(`/customers/${CID}$`).test(url)) return method === 'PATCH' ? 'customerSave' : 'customer';
  throw new Error(`unrouted ${method} ${url}`);
};
function routes(queues: Partial<Record<Route, Reply[]>>) {
  const calls: (Recorded & { route: Route })[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
      const url = String(input);
      const method = init.method ?? 'GET';
      const route = routeOf(method, url);
      const req = { url, method, headers: new Headers(init.headers), body: typeof init.body === 'string' ? init.body : null, route };
      calls.push(req);
      const next = queues[route]?.shift() ?? json(500, { unexpected: url });
      const r = typeof next === 'function' ? await next(req) : next;
      return r === 'hang' ? new Promise<Response>(() => {}) : r;
    }),
  );
  return calls;
}
const of = (calls: (Recorded & { route: Route })[], r: Route) => calls.filter((c) => c.route === r);

const A1 = address(1, 'shipping', '1 High St');
const A2 = address(2, 'billing', '2 Low Rd');
const show = ({ customer = LIVE, canEdit = false, canAdd = true, canEditAddress = false, canRemoveAddress = false, contacts = NO_CONTACTS, addresses = okAddresses([A1, A2]) }: { customer?: Customer; canEdit?: boolean; canAdd?: boolean; canEditAddress?: boolean; canRemoveAddress?: boolean; contacts?: ContactsLoad | null; addresses?: AddressesLoad | null } = {}) =>
  renderWired(<CustomerPage customer={customer} canEdit={canEdit} canAddAddress={canAdd} canEditAddress={canEditAddress} canRemoveAddress={canRemoveAddress} contacts={contacts} addresses={addresses} />);
const pageRegion = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const addressesSection = () => screen.getByRole('region', { name: 'Addresses' });
const addressesHeading = () => within(addressesSection()).getByRole('heading', { level: 2, name: 'Addresses' });
const rowsOf = (s: HTMLElement) => within(s).queryAllByRole('row').slice(1).map((r) => within(r).getAllByRole('cell').map((c) => c.textContent));
const dialog = () => screen.getByRole('dialog');
const inDialog = () => within(dialog());
const headerAdd = () => within(addressesSection()).getAllByRole('button', { name: 'Add address' })[0]!;
const bannersOf = (heading: string) => inDialog().queryAllByText(heading, { selector: 'p' }).map((h) => [...h.parentElement!.children].filter((n) => n !== h).map((n) => n.textContent));
const notAdded = () => bannersOf('Not added');
const kindSelect = () => inDialog().getByRole('combobox', { name: 'Kind' });
const text = (name: string) => inDialog().getByRole('textbox', { name });
type U = ReturnType<typeof userEvent.setup>;
const openAdd = async (u: U) => {
  await u.click(headerAdd());
  return dialog();
};
/** Fills Add address; an empty string leaves that field empty, `kind: ''` leaves the prompt chosen. */
const fill = async (u: U, { kind = 'shipping', line = '12 Mill Lane', city = 'Leeds', postal = 'LS1 4AB', country = 'gb' }: { kind?: string; line?: string; city?: string; postal?: string; country?: string } = {}) => {
  if (kind !== '') await u.selectOptions(kindSelect(), kind);
  for (const [name, v] of [['Address line', line], ['City', city], ['Postal code', postal], ['Country', country]] as const) {
    await u.clear(text(name));
    if (v !== '') await u.type(text(name), v);
  }
};
const submitAdd = (u: U) => u.click(inDialog().getByRole('button', { name: 'Add address' }));
const created = (over: Partial<CustomerAddress> = {}) => json(201, address(9, 'shipping', '12 Mill Lane', { city: 'Leeds', postal_code: 'LS1 4AB', ...over }));
/** Records `document.activeElement` at the moment the page region's text first becomes `sentence` (C-4b test 5's recipe). */
/**
 * And, for `target` (C-4b commit RED-2 N-1), the region's text at the moment `target` takes focus: a speak-then-focus
 * inversion flushed in one task (mutant X1) passes the observer but not this.
 */
function focusWhenWritten(sentence: string, target?: HTMLElement) {
  let at: Element | null | undefined;
  let textAtFocus: string | null = null;
  const region = pageRegion();
  const obs = new MutationObserver(() => {
    if (at === undefined && region.textContent === sentence) at = document.activeElement;
  });
  obs.observe(region, { childList: true, characterData: true, subtree: true });
  target?.addEventListener('focus', () => {
    if (textAtFocus === null) textAtFocus = region.textContent;
  });
  return {
    get: () => at,
    textAtFocus: () => textAtFocus,
    stop: () => obs.disconnect(),
  };
}

describe('/customers/[id] — Add address (C-5a chunk 1)', () => {
  // A1 (L-10, D-1): the served order Billing, Shipping, after the prompt; nothing preselected.
  it('A1 — Kind is a select over the served enum: Choose a kind, Billing, Shipping, nothing preselected; Add with no kind → "Choose a kind for this address." beside Kind, focus there, no request, on two presses', async () => {
    const calls = routes({});
    const u = userEvent.setup();
    show();
    await openAdd(u);
    expect(within(kindSelect()).getAllByRole('option').map((o) => o.textContent)).toEqual([KIND_PROMPT, 'Billing', 'Shipping']);
    expect(kindSelect()).toHaveValue('');
    await fill(u, { kind: '' });
    await submitAdd(u);
    await waitFor(() => expect(kindSelect()).toHaveAccessibleDescription(KIND_REQUIRED));
    await waitFor(() => expect(document.activeElement).toBe(kindSelect()));
    await submitAdd(u);
    expect(kindSelect()).toHaveAccessibleDescription(KIND_REQUIRED);
    expect(of(calls, 'add')).toEqual([]);
  });

  // A2 (Add half): every empty field at once, focus to the first in field order, nothing sent.
  it('A2 — Add with Address line, City, Postal code and Country empty: each sentence beside its field at once, focus on Address line, no request', async () => {
    const calls = routes({});
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await fill(u, { line: '', city: '', postal: '', country: '' });
    await submitAdd(u);
    await waitFor(() => expect(text('Address line')).toHaveAccessibleDescription(LINE_REQUIRED));
    expect(text('City')).toHaveAccessibleDescription(CITY_REQUIRED);
    expect(text('Postal code')).toHaveAccessibleDescription(POSTAL_REQUIRED);
    expect(text('Country')).toHaveAccessibleDescription(`${COUNTRY_HINT} ${COUNTRY_REQUIRED}`);
    expect(kindSelect()).not.toHaveAccessibleDescription(KIND_REQUIRED);
    await waitFor(() => expect(document.activeElement).toBe(text('Address line')));
    expect(of(calls, 'add')).toEqual([]);
  });

  it('A2 / A10 — Add sends the whole body by name: the chosen kind, each text as typed, Country typed "gb" sent "GB"; a fresh Idempotency-Key per submit', async () => {
    const calls = routes({ add: [json(500, {}), created()], list: [json(200, page([address(9, 'shipping', '12 Mill Lane')]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await fill(u, { kind: 'billing', line: '12 Mill Lane', city: 'Leeds', postal: 'LS1 4AB', country: 'gb' });
    expect(text('Country')).toHaveValue('GB');
    await submitAdd(u);
    await waitFor(() => expect(bannersOf('Something went wrong on our side.')).toEqual([[ADD_INTERRUPTED('billing address', '12 Mill Lane')]]));
    await submitAdd(u);
    await waitFor(() => expect(screen.queryAllByRole('dialog')).toEqual([]));
    const adds = of(calls, 'add');
    expect(adds).toHaveLength(2);
    expect(adds[0]!.url).toMatch(new RegExp(`/bff/api/customers/${CID}/addresses$`));
    expect(JSON.parse(adds[0]!.body!)).toStrictEqual({ kind: 'billing', line: '12 Mill Lane', city: 'Leeds', postal_code: 'LS1 4AB', country_code: 'GB' });
    const keys = adds.map((c) => c.headers.get('Idempotency-Key'));
    for (const k of keys) expect(k).toMatch(/^[0-9a-f-]{36}$/);
    expect(keys[0]).not.toBe(keys[1]);
  });

  // A3 (Add half, F-6, M-3, L-2, L-3).
  it('A3 — after a 201 the section re-reads and shows Shipping first, then Billing, newest first within each; only the write\'s sentence, with the 201\'s kind and line; focus on the Addresses heading when it is written', async () => {
    const served = [address(2, 'billing', '2 Low Rd'), address(1, 'shipping', '1 High St'), address(9, 'shipping', '12 Mill Lane (served)')];
    routes({ add: [created({ line: '12 Mill Lane (served)' })], list: [json(200, page(served))] });
    const u = userEvent.setup();
    show();
    const t = textsOf(pageRegion());
    const h = addressesHeading();
    await openAdd(u);
    await fill(u, { kind: 'shipping', line: '12 Mill Lane' });
    const spoken = ADDED('shipping address', '12 Mill Lane (served)');
    const focus = focusWhenWritten(spoken, h);
    await submitAdd(u);
    await waitFor(() => expect(rowsOf(addressesSection())).toHaveLength(3));
    expect(rowsOf(addressesSection()).map(([k, l]) => [k, l])).toEqual([
      ['Shipping', '12 Mill Lane (served)'],
      ['Shipping', '1 High St'],
      ['Billing', '2 Low Rd'],
    ]);
    expect(pageRegion().textContent).toBe(spoken);
    expect(t.texts.filter((x) => x !== '')).toEqual([spoken]);
    t.stop();
    focus.stop();
    expect(focus.get(), 'focus is on the heading when the sentence is written').toBe(addressesHeading());
    expect(focus.textAtFocus(), 'the sentence is not yet written when the heading takes focus (FE-D1 order)').not.toBe(spoken);
    expect(focus.textAtFocus()).not.toBeNull();
    expect(document.activeElement).toBe(addressesHeading());
  });

  it('A3 — M-3: a 201 whose section re-read fails: the table is exactly the pre-write table, no new row; after · LIST_STALE', async () => {
    routes({ add: [created()], list: [json(500, {})] });
    const u = userEvent.setup();
    show();
    const before = rowsOf(addressesSection());
    await openAdd(u);
    await fill(u);
    await submitAdd(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(`${ADDED('shipping address', '12 Mill Lane')} ${LIST_STALE}`));
    expect(rowsOf(addressesSection())).toEqual(before);
  });

  // A4 (Add half, L-7 (a)).
  it('A4 — Add: countryHint is Country\'s exact description within the dialog; no editHint, no kindSwitchHint, not the page\'s longer country hint', async () => {
    routes({});
    const u = userEvent.setup();
    show();
    await openAdd(u);
    expect(text('Country')).toHaveAccessibleDescription(COUNTRY_HINT);
    expect(inDialog().getAllByText(COUNTRY_HINT).map((p) => p.textContent)).toEqual([COUNTRY_HINT]);
    expect(inDialog().queryAllByText(EDIT_HINT)).toEqual([]);
    expect(inDialog().queryAllByText(KIND_SWITCH_HINT)).toEqual([]);
    expect(dialog().textContent).not.toContain('Each address below keeps its own country.');
  });

  // A11 (Add half).
  it('A11 — Add → 404: Not added + notFoundInline, Close only; no customer re-read in the action; Close has the page re-read the customer (→ ST-5)', async () => {
    const calls = routes({ add: [problem(404, 'not-found')], customer: [problem(404, 'not-found')], list: [problem(404, 'not-found')] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await fill(u);
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[NOT_FOUND_INLINE]]));
    expect(inDialog().queryAllByRole('button', { name: 'Add address' })).toEqual([]);
    expect(inDialog().queryAllByRole('button', { name: 'Cancel' })).toEqual([]);
    expect(of(calls, 'customer')).toEqual([]);
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.getByText("We couldn't find that.", { selector: 'h1' })).toBeInTheDocument());
    expect(screen.queryAllByRole('region', { name: 'Addresses' })).toEqual([]);
    expect(of(calls, 'customer')).toHaveLength(1);
  });

  // A8 (Add half): field 422s beside their fields; spaces sent as typed (L-3).
  it('A8 — Add: S-12 beside Country and S-11 beside City, focus on City (the first in field order), no banner, no re-read', async () => {
    const calls = routes({ add: [problem(422, 'validation-failed', { errors: [{ pointer: '/country_code', code: 'not_a_country_code', message: S12 }, { pointer: '/city', code: 'blank', message: S11 }] })] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await fill(u, { country: 'xx' });
    await submitAdd(u);
    await waitFor(() => expect(text('City')).toHaveAccessibleDescription(S11));
    expect(text('Country')).toHaveAccessibleDescription(`${COUNTRY_HINT} ${S12}`);
    await waitFor(() => expect(document.activeElement).toBe(text('City')));
    expect(notAdded()).toEqual([]);
    expect(of(calls, 'customer')).toEqual([]);
    expect(of(calls, 'list')).toEqual([]);
  });

  it('A8 — L-3: a City of spaces is sent as typed, and S-11 shows beside it', async () => {
    const calls = routes({ add: [problem(422, 'validation-failed', { errors: [{ pointer: '/city', code: 'blank', message: S11 }] })] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await fill(u, { city: '   ' });
    await submitAdd(u);
    await waitFor(() => expect(text('City')).toHaveAccessibleDescription(S11));
    expect(JSON.parse(of(calls, 'add')[0]!.body!)).toStrictEqual({ kind: 'shipping', line: '12 Mill Lane', city: '   ', postal_code: 'LS1 4AB', country_code: 'GB' });
  });

  // A16 (Add half).
  it('A16 — an interrupted Add keeps Add with interrupted(the typed kind and line); Cancel re-reads the section', async () => {
    const calls = routes({ add: [() => Promise.reject(new TypeError('network'))], list: [json(200, page([A1]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await fill(u, { kind: 'billing', line: '7 Dock Rd' });
    await submitAdd(u);
    await waitFor(() => expect(bannersOf("We couldn't reach the server.")).toEqual([[ADD_INTERRUPTED('billing address', '7 Dock Rd')]]));
    expect(inDialog().getByRole('button', { name: 'Add address' })).toBeInTheDocument();
    await u.click(inDialog().getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
  });

  // A17 (Add half) and M-4 (b): other refusals, a 403, the body guard.
  it('A17 — S-16 with a live re-read is shown as sent and Add stays; with a failed re-read too', async () => {
    const calls = routes({ add: [refused('transaction.contention', P1), refused('transaction.contention', P1)], customer: [json(200, LIVE), json(500, {})] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await fill(u);
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[P1]]));
    expect(inDialog().getByRole('button', { name: 'Add address' })).toBeInTheDocument();
    await submitAdd(u);
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(2));
    await waitFor(() => expect(notAdded()).toEqual([[P1]]));
    expect(inDialog().getByRole('button', { name: 'Add address' })).toBeInTheDocument();
  });

  it('A17 — a 422 with no violations re-reads the customer and shows REFUSED_WITHOUT_MESSAGE; a 403 shows the permission sentence, Add stays, no re-read', async () => {
    const calls = routes({ add: [problem(422, 'validation-failed'), problem(403, 'permission-denied')], customer: [json(200, LIVE)] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await fill(u);
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[REFUSED_WITHOUT_MESSAGE]]));
    expect(of(calls, 'customer')).toHaveLength(1);
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[FORBIDDEN]]));
    expect(inDialog().getByText('Edit customers, their addresses and contacts', { selector: 'em' })).toBeInTheDocument();
    expect(inDialog().getByRole('button', { name: 'Add address' })).toBeInTheDocument();
    expect(of(calls, 'customer')).toHaveLength(1);
  });

  it('A17 — M-4 (b): a 201 whose body is not a whole address → the dialog\'s WENT_WRONG banner, Add stays, nothing spoken in the page region', async () => {
    routes({ add: [json(201, { id: 'x', kind: 'shipping', city: 'Leeds', postal_code: 'LS1 4AB', country_code: 'GB' })] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await fill(u);
    await submitAdd(u);
    await waitFor(() => expect(bannersOf('Something went wrong on our side.')).toEqual([[ADD_INTERRUPTED('shipping address', '12 Mill Lane')]]));
    expect(inDialog().getByRole('button', { name: 'Add address' })).toBeInTheDocument();
    expect(pageRegion().textContent).toBe('');
  });
});

describe('/customers/[id] — Add address: the R12 branch (C-5a chunk 1, A15 Add column)', () => {
  it('A15 — customer.is_archived + archived re-read: the interim sentence, Close only, S-8 nowhere; Close → the page re-reads: ST-9 banner, no Add address, router refreshed once', async () => {
    const calls = routes({ add: [refused('customer.is_archived', S8)], customer: [json(200, ARCHIVED), json(200, ARCHIVED)], list: [json(200, page([A1]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await fill(u);
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[ADD_ARCHIVED]]));
    expect(document.body.textContent).not.toContain(S8);
    expect(document.body.textContent).not.toContain("until it's restored");
    expect(inDialog().queryAllByRole('button', { name: 'Add address' })).toEqual([]);
    expect(of(calls, 'customer')).toHaveLength(1);
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(addressesSection()).queryAllByRole('button', { name: 'Add address' })).toEqual([]);
    expect(within(addressesSection()).getByText(ARCHIVED_PARENT, { selector: 'p' })).toBeInTheDocument();
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('A15 — customer.is_archived + live re-read: archivedThenRestored, the typed values and Add stay; Cancel → the page re-reads the customer', async () => {
    const calls = routes({ add: [refused('customer.is_archived', S8)], customer: [json(200, LIVE), json(200, LIVE)], list: [json(200, page([A1]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await fill(u);
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[ADD_THEN_RESTORED]]));
    expect(document.body.textContent).not.toContain(S8);
    expect(document.body.textContent).not.toContain("We couldn't check");
    expect(text('Address line')).toHaveValue('12 Mill Lane');
    expect(kindSelect()).toHaveValue('shipping');
    expect(inDialog().getByRole('button', { name: 'Add address' })).toBeInTheDocument();
    await u.click(inDialog().getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(2));
    expect(nav.refresh).not.toHaveBeenCalled();
  });

  it('A15 — customer.is_archived + failed re-read: archivedAtAdd, values and Add stay; Escape → the page re-reads (archived now): ST-9 banner, no Add address', async () => {
    const calls = routes({ add: [refused('customer.is_archived', S8)], customer: [json(500, {}), json(200, ARCHIVED)], list: [json(200, page([A1]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await fill(u);
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[ADD_AT_ADD]]));
    expect(document.body.textContent).not.toContain(S8);
    expect(document.body.textContent).not.toContain('It has since been restored');
    expect(text('Address line')).toHaveValue('12 Mill Lane');
    expect(inDialog().getByRole('button', { name: 'Add address' })).toBeInTheDocument();
    await u.keyboard('{Escape}');
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(addressesSection()).queryAllByRole('button', { name: 'Add address' })).toEqual([]);
  });

  it('A15 — S-16 + archived re-read: the interim sentence (never "try again"), Close only; Close → ST-9 banner, no Add address', async () => {
    const calls = routes({ add: [refused('transaction.contention', P1)], customer: [json(200, ARCHIVED), json(200, ARCHIVED)], list: [json(200, page([A1]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await fill(u);
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[ADD_ARCHIVED]]));
    expect(document.body.textContent).not.toContain(P1);
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(addressesSection()).queryAllByRole('button', { name: 'Add address' })).toEqual([]);
  });

  it('A15 — M-4 (a): customer.is_archived + live re-read, Add again → 201: the dialog closes and the page\'s GetCustomer is requested once', async () => {
    const calls = routes({ add: [refused('customer.is_archived', S8), created()], customer: [json(200, LIVE), json(200, LIVE)], list: [json(200, page([A1]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await fill(u);
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[ADD_THEN_RESTORED]]));
    expect(of(calls, 'customer')).toHaveLength(1);
    await submitAdd(u);
    await waitFor(() => expect(screen.queryAllByRole('dialog')).toEqual([]));
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(2));
  });
});

describe('/customers/[id] — who sees Add address, and the empty states (C-5a chunk 1)', () => {
  it('A14 — no Add prop: no Add address, no dialog; with it on a live customer: Add in the header (and after the empty sentence); no Actions column with only canAdd', () => {
    const { unmount } = show({ canAdd: false });
    expect(within(addressesSection()).queryAllByRole('button')).toEqual([]);
    expect(screen.queryAllByRole('dialog')).toEqual([]);
    unmount();
    const r2 = show({ addresses: okAddresses([]) });
    expect(within(addressesSection()).getAllByRole('button', { name: 'Add address' })).toHaveLength(2);
    r2.unmount();
    show();
    expect(within(addressesSection()).getAllByRole('button', { name: 'Add address' })).toHaveLength(1);
    expect(within(addressesSection()).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Kind', 'Address', 'City', 'Postal code', 'Country']);
  });

  it('A14 — L-7: Add is offered in the header in the region-error state, never when the section is denied', () => {
    const { unmount } = show({ addresses: { kind: 'server-error' } });
    expect(within(addressesSection()).getByRole('button', { name: 'Add address' })).toBeInTheDocument();
    unmount();
    show({ addresses: { kind: 'forbidden' } });
    expect(within(addressesSection()).queryAllByRole('button', { name: 'Add address' })).toEqual([]);
  });

  it('A6 / A14 — archived with canAdd: no action; archivedParent above the table, and above emptyArchived on an empty section; on a live empty section, "No addresses yet." alone', () => {
    const { unmount } = show({ customer: ARCHIVED });
    expect(within(addressesSection()).queryAllByRole('button')).toEqual([]);
    expect([...addressesSection().querySelectorAll('p')].map((p) => p.textContent)).toEqual([ARCHIVED_PARENT]);
    unmount();
    const r = show({ customer: ARCHIVED, addresses: okAddresses([]) });
    expect([...addressesSection().querySelectorAll('p')].map((p) => p.textContent)).toEqual([ARCHIVED_PARENT, EMPTY_ARCHIVED]);
    r.unmount();
    show({ addresses: okAddresses([]) });
    expect([...addressesSection().querySelectorAll('p')].map((p) => p.textContent)).toEqual([EMPTY_LIVE]);
  });

  it('A6 — F-11: a Retry that brings none on an archived page speaks emptyArchived; on a live page, "No addresses yet."', async () => {
    routes({ list: [json(200, page([])), json(200, page([]))] });
    const u = userEvent.setup();
    const r = show({ customer: ARCHIVED, addresses: { kind: 'server-error' } });
    await u.click(within(addressesSection()).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(pageRegion().textContent).toBe(EMPTY_ARCHIVED));
    r.unmount();
    show({ addresses: { kind: 'server-error' } });
    await u.click(within(addressesSection()).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(pageRegion().textContent).toBe(EMPTY_LIVE));
  });

  it('A14 — M-4 (c), the L-5 press order: change Code, press Save (held), open Add address, type a line; Save answers customer.is_archived with an archived re-read: the dialog is still open with the line, focus inside it', async () => {
    const save = held();
    routes({ customerSave: [save.reply], customer: [json(200, ARCHIVED)] });
    const u = userEvent.setup();
    show({ canEdit: true });
    const code = screen.getByRole('textbox', { name: 'Code' });
    await u.clear(code);
    await u.type(code, 'NORTH2');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openAdd(u);
    await u.type(text('Address line'), '12 Mill Lane');
    await act(async () => save.release(refused('customer.is_archived', "This customer is archived, so it can't be changed until it's restored.")));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(text('Address line')).toHaveValue('12 Mill Lane');
    expect(dialog().contains(document.activeElement)).toBe(true);
  });
});

describe('/customers/[id] — Add address: late corrections and late answers (C-5a chunk 1)', () => {
  it('A13 — a failed section re-read after Add replaces the sentence with its stale form', async () => {
    routes({ add: [created()], list: [json(503, {})] });
    const u = userEvent.setup();
    show();
    const t = textsOf(pageRegion());
    await openAdd(u);
    await fill(u);
    await submitAdd(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(`${ADDED('shipping address', '12 Mill Lane')} ${LIST_STALE}`));
    expect(t.texts.filter((x) => x !== '').at(-1)).toBe(`${ADDED('shipping address', '12 Mill Lane')} ${LIST_STALE}`);
    t.stop();
  });

  it.each([
    ['a 200 with a non-null next_cursor', () => json(200, page([A1], { next_cursor: 'abc' }))],
    ['a 404', () => problem(404, 'not-found')],
  ] as const)('A13 — M-4 (f): the address re-read answers %s → the stale form', async (_, reply) => {
    routes({ add: [created()], list: [reply()] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await fill(u);
    await submitAdd(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(`${ADDED('shipping address', '12 Mill Lane')} ${LIST_STALE}`));
  });

  it('A13 — K9-1 mirror: the Contacts Retry writes the region between the Add and its failed re-read: the stale form is never written', async () => {
    const reread = held();
    routes({ add: [created()], list: [reread.reply], contacts: [json(200, page([]))] });
    const u = userEvent.setup();
    show({ contacts: { kind: 'server-error' } });
    const t = textsOf(pageRegion());
    await openAdd(u);
    await fill(u);
    await submitAdd(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(ADDED('shipping address', '12 Mill Lane')));
    const contacts = screen.getByRole('region', { name: 'Contacts' });
    await u.click(within(contacts).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(pageRegion().textContent).toBe('No contacts for this customer yet.'));
    await act(async () => reread.release(json(500, {})));
    await new Promise((r) => setTimeout(r, 20));
    expect(t.texts).not.toContain(`${ADDED('shipping address', '12 Mill Lane')} ${LIST_STALE}`);
    expect(pageRegion().textContent).toBe('No contacts for this customer yet.');
    t.stop();
  });

  it('A18 — a Retry in flight under an Add: the Add\'s re-read lands first; the Retry\'s older answer is dropped', async () => {
    const retry = held();
    routes({ list: [retry.reply, json(200, page([A1, address(9, 'shipping', '12 Mill Lane')]))], add: [created()] });
    const u = userEvent.setup();
    show({ addresses: { kind: 'server-error' } });
    await u.click(within(addressesSection()).getByRole('button', { name: 'Retry' }));
    await openAdd(u);
    await fill(u);
    await submitAdd(u);
    await waitFor(() => expect(rowsOf(addressesSection()).map(([, l]) => l)).toEqual(['12 Mill Lane', '1 High St']));
    await act(async () => retry.release(json(200, page([A1]))));
    await new Promise((r) => setTimeout(r, 20));
    expect(rowsOf(addressesSection()).map(([, l]) => l)).toEqual(['12 Mill Lane', '1 High St']);
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// Chunk 2: Edit address.
// ---------------------------------------------------------------------------------------------------------------------

/** S-9, `CustomerAddressWriter::ARCHIVED_ON_UPDATE` (`CustomerAddressWriter.php:98`). Never rendered in C-5a (copy D-1). */
const S9 = "This customer is archived, so its addresses can't be changed until it's restored.";
const EDIT_ARCHIVED = "This customer is archived, so its addresses can't be changed.";
const EDIT_THEN_RESTORED = 'Nothing was saved, because this customer was archived at that moment. It has since been restored, so you can press Save again.';
const EDIT_AT_SAVE = "Nothing was saved, because this customer was archived at that moment. We couldn't check whether it still is.";
const SAVE_NOTHING = 'Nothing to save.';
const SAVED = (noun: string, line: string) => `The ${noun} “${line}” was saved.`;
const EDIT_INTERRUPTED = (noun: string, line: string) => `We can't tell whether your changes to the ${noun} “${line}” were saved. Close this and check the addresses before you try again.`;
const DOCK = address(3, 'dock', '1 Dock Rd');
const notSaved = () => bannersOf('Not saved');
const rowEdit = (name: string) => within(addressesSection()).getByRole('button', { name });
const openEdit = async (u: U, name = 'Edit shipping address 1 High St') => {
  await u.click(rowEdit(name));
  return dialog();
};
const pressSave = (u: U) => u.click(inDialog().getByRole('button', { name: 'Save' }));
const retype = async (u: U, name: string, v: string) => {
  await u.clear(text(name));
  if (v !== '') await u.type(text(name), v);
};
const savedAs = (a: CustomerAddress) => json(200, a);
const showEdit = (over: Parameters<typeof show>[0] = {}) => show({ canAdd: false, canEditAddress: true, ...over });

describe('/customers/[id] — Edit address (C-5a chunk 2)', () => {
  it('A2 — Edit sends the changed members only (City alone), by PATCH on the address, no Idempotency-Key (A10)', async () => {
    const calls = routes({ save: [savedAs({ ...A1, city: 'York' })], list: [json(200, page([{ ...A1, city: 'York' }, A2]))] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u);
    await retype(u, 'City', 'York');
    await pressSave(u);
    await waitFor(() => expect(of(calls, 'save')).toHaveLength(1));
    const s = of(calls, 'save')[0]!;
    expect(s.method).toBe('PATCH');
    expect(s.url).toMatch(new RegExp(`/bff/api/customers/${CID}/addresses/${A1.id}$`));
    expect(JSON.parse(s.body!)).toStrictEqual({ city: 'York' });
    expect(s.headers.get('Idempotency-Key')).toBeNull();
  });

  it('A2 — an emptied field on Edit: its sentence beside it, focus there, no request', async () => {
    const calls = routes({});
    const u = userEvent.setup();
    showEdit();
    await openEdit(u);
    await retype(u, 'Postal code', '');
    await pressSave(u);
    await waitFor(() => expect(text('Postal code')).toHaveAccessibleDescription(POSTAL_REQUIRED));
    await waitFor(() => expect(document.activeElement).toBe(text('Postal code')));
    expect(of(calls, 'save')).toEqual([]);
  });

  it('A2 — unchanged: Save is aria-disabled with "Nothing to save." as its exact description, and a press sends nothing', async () => {
    const calls = routes({});
    const u = userEvent.setup();
    showEdit();
    await openEdit(u);
    const save = inDialog().getByRole('button', { name: 'Save' });
    expect(save).toHaveAttribute('aria-disabled', 'true');
    expect(save).toHaveAccessibleDescription(SAVE_NOTHING);
    await pressSave(u);
    expect(of(calls, 'save')).toEqual([]);
  });

  it('A9 / L-8 — Edit on a dock row: Kind shows "Choose a kind", nothing preselected; unchanged, Save is aria-disabled with "Nothing to save."', async () => {
    const calls = routes({});
    const u = userEvent.setup();
    showEdit({ addresses: okAddresses([DOCK]) });
    await openEdit(u, 'Edit address 1 Dock Rd');
    expect(within(kindSelect()).getAllByRole('option').map((o) => o.textContent)).toEqual([KIND_PROMPT, 'Billing', 'Shipping']);
    expect(kindSelect()).toHaveValue('');
    const save = inDialog().getByRole('button', { name: 'Save' });
    expect(save).toHaveAttribute('aria-disabled', 'true');
    expect(save).toHaveAccessibleDescription(SAVE_NOTHING);
    await pressSave(u);
    expect(of(calls, 'save')).toEqual([]);
  });

  it('A9 / L-8 — Edit on a dock row, City changed alone: the body is { city } — no kind, and no "Choose a kind" refusal', async () => {
    const calls = routes({ save: [savedAs({ ...DOCK, city: 'Hull' })], list: [json(200, page([{ ...DOCK, city: 'Hull' }]))] });
    const u = userEvent.setup();
    showEdit({ addresses: okAddresses([DOCK]) });
    await openEdit(u, 'Edit address 1 Dock Rd');
    await retype(u, 'City', 'Hull');
    await pressSave(u);
    await waitFor(() => expect(of(calls, 'save')).toHaveLength(1));
    expect(JSON.parse(of(calls, 'save')[0]!.body!)).toStrictEqual({ city: 'Hull' });
  });

  it('A3 — after a 200 the section re-reads; saved() uses the 200\'s kind and line; focus is on the row\'s Edit when it is written', async () => {
    const answered = { ...A1, kind: 'billing', line: '1 High Street' };
    routes({ save: [savedAs(answered)], list: [json(200, page([A2, answered]))] });
    const u = userEvent.setup();
    showEdit();
    const opener = rowEdit('Edit shipping address 1 High St');
    await openEdit(u);
    await retype(u, 'Address line', '1 High Street');
    const spoken = SAVED('billing address', '1 High Street');
    const focus = focusWhenWritten(spoken, opener);
    await pressSave(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(spoken));
    await waitFor(() => expect(rowsOf(addressesSection()).map(([k, l]) => [k, l])).toEqual([['Billing', '1 High Street'], ['Billing', '2 Low Rd']]));
    focus.stop();
    expect(focus.get(), "focus is on the row's Edit when the sentence is written").toBe(rowEdit('Edit billing address 1 High Street'));
    expect(focus.textAtFocus(), "the sentence is not yet written when the row's Edit takes focus (FE-D1 order)").not.toBe(spoken);
    // C-5a commit RED L-1 (added by C-5b): the focus listener fired at all.
    expect(focus.textAtFocus()).not.toBeNull();
  });

  it('A3 — M-3 (Edit): a 200 whose section re-read fails: the table is exactly the pre-write table; saved · LIST_STALE', async () => {
    routes({ save: [savedAs({ ...A1, line: '1 High Street' })], list: [json(500, {})] });
    const u = userEvent.setup();
    showEdit();
    const before = rowsOf(addressesSection());
    await openEdit(u);
    await retype(u, 'Address line', '1 High Street');
    await pressSave(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(`${SAVED('shipping address', '1 High Street')} ${LIST_STALE}`));
    expect(rowsOf(addressesSection())).toEqual(before);
  });

  it('A4 — Edit: kindSwitchHint is Kind\'s exact description; countryHint Country\'s; editHint beside Save', async () => {
    routes({});
    const u = userEvent.setup();
    showEdit();
    await openEdit(u);
    expect(kindSelect()).toHaveAccessibleDescription(KIND_SWITCH_HINT);
    expect(text('Country')).toHaveAccessibleDescription(COUNTRY_HINT);
    expect(inDialog().getAllByText(EDIT_HINT, { selector: 'p' }).map((p) => p.textContent)).toEqual([EDIT_HINT]);
    expect(dialog().textContent).not.toContain('Each address below keeps its own country.');
  });

  it('A5 — each row\'s Edit is named "Edit {noun} {line}", unquoted, and reads Edit; an unknown kind reads "address"', () => {
    showEdit({ addresses: okAddresses([A1, A2, DOCK]) });
    expect(within(addressesSection()).getAllByRole('button', { name: /^Edit/ }).map((b) => [b.textContent, b.getAttribute('aria-label')])).toEqual([
      ['Edit', 'Edit shipping address 1 High St'],
      ['Edit', 'Edit billing address 2 Low Rd'],
      ['Edit', 'Edit address 1 Dock Rd'],
    ]);
    expect(within(addressesSection()).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Kind', 'Address', 'City', 'Postal code', 'Country', 'Actions']);
  });

  it('A8 — Save: S-12 beside Country, focus there, no banner, no re-read', async () => {
    const calls = routes({ save: [problem(422, 'validation-failed', { errors: [{ pointer: '/country_code', code: 'not_a_country_code', message: S12 }] })] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u);
    await retype(u, 'Country', 'xx');
    await pressSave(u);
    await waitFor(() => expect(text('Country')).toHaveAccessibleDescription(`${COUNTRY_HINT} ${S12}`));
    await waitFor(() => expect(document.activeElement).toBe(text('Country')));
    expect(notSaved()).toEqual([]);
    expect(of(calls, 'customer')).toEqual([]);
    expect(of(calls, 'list')).toEqual([]);
  });

  it('A11 — Edit → 404: Not saved + notFoundInline, Close only; Close re-reads the section (not the customer); focus on the row\'s Edit, then on the heading when the re-read drops the row', async () => {
    const reread = held();
    const calls = routes({ save: [problem(404, 'not-found')], list: [reread.reply] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u);
    await retype(u, 'City', 'York');
    await pressSave(u);
    await waitFor(() => expect(notSaved()).toEqual([[NOT_FOUND_INLINE]]));
    expect(inDialog().queryAllByRole('button', { name: 'Save' })).toEqual([]);
    expect(inDialog().queryAllByRole('button', { name: 'Cancel' })).toEqual([]);
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.queryAllByRole('dialog')).toEqual([]));
    await waitFor(() => expect(document.activeElement).toBe(rowEdit('Edit shipping address 1 High St')));
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
    expect(of(calls, 'customer')).toEqual([]);
    await act(async () => reread.release(json(200, page([A2]))));
    await waitFor(() => expect(rowsOf(addressesSection())).toHaveLength(1));
    expect(document.activeElement).toBe(addressesHeading());
  });

  it('A16 — an interrupted Save keeps Save, with interrupted(the row as served before the press); Cancel re-reads the section', async () => {
    const calls = routes({ save: [json(503, {})], list: [json(200, page([A1, A2]))] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u);
    await retype(u, 'Address line', '9 New St');
    await pressSave(u);
    await waitFor(() => expect(bannersOf('Something went wrong on our side.')).toEqual([[EDIT_INTERRUPTED('shipping address', '1 High St')]]));
    expect(inDialog().getByRole('button', { name: 'Save' })).toBeInTheDocument();
    await u.click(inDialog().getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
  });

  it('A17 — Save: a 422 with no violations re-reads and shows REFUSED_WITHOUT_MESSAGE; a 403 the permission sentence, Save stays, no re-read', async () => {
    const calls = routes({ save: [problem(422, 'validation-failed'), problem(403, 'permission-denied')], customer: [json(200, LIVE)] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u);
    await retype(u, 'City', 'York');
    await pressSave(u);
    await waitFor(() => expect(notSaved()).toEqual([[REFUSED_WITHOUT_MESSAGE]]));
    expect(of(calls, 'customer')).toHaveLength(1);
    await pressSave(u);
    await waitFor(() => expect(notSaved()).toEqual([[FORBIDDEN]]));
    expect(inDialog().getByRole('button', { name: 'Save' })).toBeInTheDocument();
    expect(of(calls, 'customer')).toHaveLength(1);
  });

  it('A17 — M-4 (b): a 200 whose body is not a whole address → WENT_WRONG + interrupted, Save stays, nothing spoken', async () => {
    routes({ save: [json(200, { id: A1.id, kind: 'shipping', city: 'York', postal_code: 'PC1', country_code: 'GB' })] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u);
    await retype(u, 'City', 'York');
    await pressSave(u);
    await waitFor(() => expect(bannersOf('Something went wrong on our side.')).toEqual([[EDIT_INTERRUPTED('shipping address', '1 High St')]]));
    expect(inDialog().getByRole('button', { name: 'Save' })).toBeInTheDocument();
    expect(pageRegion().textContent).toBe('');
  });

  it('A17 — Save: S-16 with a live re-read is shown as sent and Save stays; with a failed re-read too', async () => {
    const calls = routes({ save: [refused('transaction.contention', P1), refused('transaction.contention', P1)], customer: [json(200, LIVE), json(500, {})] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u);
    await retype(u, 'City', 'York');
    await pressSave(u);
    await waitFor(() => expect(notSaved()).toEqual([[P1]]));
    await pressSave(u);
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(2));
    await waitFor(() => expect(notSaved()).toEqual([[P1]]));
    expect(inDialog().getByRole('button', { name: 'Save' })).toBeInTheDocument();
  });
});

describe('/customers/[id] — Edit address: the R12 branch (C-5a chunk 2, A15 Save column)', () => {
  const edited = async (u: U) => {
    await openEdit(u);
    await retype(u, 'City', 'York');
    await pressSave(u);
  };

  it('A15 — Save: customer.is_archived + archived re-read: the interim sentence, Close only, S-9 nowhere; Close → ST-9, no Edit, router refreshed once', async () => {
    const calls = routes({ save: [refused('customer.is_archived', S9)], customer: [json(200, ARCHIVED), json(200, ARCHIVED)], list: [json(200, page([A1, A2]))] });
    const u = userEvent.setup();
    showEdit();
    await edited(u);
    await waitFor(() => expect(notSaved()).toEqual([[EDIT_ARCHIVED]]));
    expect(document.body.textContent).not.toContain(S9);
    expect(document.body.textContent).not.toContain("until it's restored");
    expect(inDialog().queryAllByRole('button', { name: 'Save' })).toEqual([]);
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(addressesSection()).queryAllByRole('button', { name: /^Edit/ })).toEqual([]);
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('A15 — Save: customer.is_archived + live re-read: K-16\'s words, the edits and Save stay; Cancel → the page re-reads the customer', async () => {
    const calls = routes({ save: [refused('customer.is_archived', S9)], customer: [json(200, LIVE), json(200, LIVE)], list: [json(200, page([A1, A2]))] });
    const u = userEvent.setup();
    showEdit();
    await edited(u);
    await waitFor(() => expect(notSaved()).toEqual([[EDIT_THEN_RESTORED]]));
    expect(document.body.textContent).not.toContain(S9);
    expect(document.body.textContent).not.toContain("We couldn't check");
    expect(text('City')).toHaveValue('York');
    expect(inDialog().getByRole('button', { name: 'Save' })).toBeInTheDocument();
    await u.click(inDialog().getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(2));
    expect(nav.refresh).not.toHaveBeenCalled();
  });

  it('A15 — Save: customer.is_archived + failed re-read: archivedAtSave, edits and Save stay; Escape → the page re-reads (archived now): ST-9, no Edit', async () => {
    const calls = routes({ save: [refused('customer.is_archived', S9)], customer: [json(500, {}), json(200, ARCHIVED)], list: [json(200, page([A1, A2]))] });
    const u = userEvent.setup();
    showEdit();
    await edited(u);
    await waitFor(() => expect(notSaved()).toEqual([[EDIT_AT_SAVE]]));
    expect(document.body.textContent).not.toContain(S9);
    expect(document.body.textContent).not.toContain('It has since been restored');
    expect(text('City')).toHaveValue('York');
    expect(inDialog().getByRole('button', { name: 'Save' })).toBeInTheDocument();
    await u.keyboard('{Escape}');
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(addressesSection()).queryAllByRole('button', { name: /^Edit/ })).toEqual([]);
  });

  it('A15 — Save: S-16 + archived re-read: the interim sentence, Close only; Close → ST-9, no Edit', async () => {
    const calls = routes({ save: [refused('transaction.contention', P1)], customer: [json(200, ARCHIVED), json(200, ARCHIVED)], list: [json(200, page([A1, A2]))] });
    const u = userEvent.setup();
    showEdit();
    await edited(u);
    await waitFor(() => expect(notSaved()).toEqual([[EDIT_ARCHIVED]]));
    expect(document.body.textContent).not.toContain(P1);
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(addressesSection()).queryAllByRole('button', { name: /^Edit/ })).toEqual([]);
  });

  it('A15 — M-4 (a): Save: customer.is_archived + live re-read, Save again → 200: the dialog closes and the page\'s GetCustomer is requested once', async () => {
    const calls = routes({ save: [refused('customer.is_archived', S9), savedAs({ ...A1, city: 'York' })], customer: [json(200, LIVE), json(200, LIVE)], list: [json(200, page([A1, A2]))] });
    const u = userEvent.setup();
    showEdit();
    await edited(u);
    await waitFor(() => expect(notSaved()).toEqual([[EDIT_THEN_RESTORED]]));
    expect(of(calls, 'customer')).toHaveLength(1);
    await pressSave(u);
    await waitFor(() => expect(screen.queryAllByRole('dialog')).toEqual([]));
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(2));
  });
});

describe('/customers/[id] — who sees Edit address, and Edit\'s late corrections (C-5a chunk 2)', () => {
  it('A14 — canEditAddress: an Actions column with Edit per row; archived with both props: no Edit, no Actions column', () => {
    const { unmount } = showEdit();
    expect(within(addressesSection()).getAllByRole('button', { name: /^Edit/ })).toHaveLength(2);
    unmount();
    show({ customer: ARCHIVED, canAdd: true, canEditAddress: true });
    expect(within(addressesSection()).queryAllByRole('button')).toEqual([]);
    expect(within(addressesSection()).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Kind', 'Address', 'City', 'Postal code', 'Country']);
  });

  it('A14 — M-4 (c), the L-5 press order: change Code, press Save (held), open an address\'s Edit, change its City; Save answers customer.is_archived with an archived re-read: the dialog is still open with the edit, focus inside it', async () => {
    const save = held();
    routes({ customerSave: [save.reply], customer: [json(200, ARCHIVED)] });
    const u = userEvent.setup();
    show({ canEdit: true, canAdd: false, canEditAddress: true });
    const code = screen.getByRole('textbox', { name: 'Code' });
    await u.clear(code);
    await u.type(code, 'NORTH2');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openEdit(u);
    await retype(u, 'City', 'York');
    await act(async () => save.release(refused('customer.is_archived', "This customer is archived, so it can't be changed until it's restored.")));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(text('City')).toHaveValue('York');
    expect(dialog().contains(document.activeElement)).toBe(true);
  });

  it('A13 — a failed section re-read after Edit replaces the sentence with its stale form', async () => {
    routes({ save: [savedAs({ ...A1, city: 'York' })], list: [json(503, {})] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u);
    await retype(u, 'City', 'York');
    await pressSave(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(`${SAVED('shipping address', '1 High St')} ${LIST_STALE}`));
  });

  it('A13 — K9-1 mirror for Edit: the Contacts Retry writes the region between the Save and its failed re-read: the stale form is never written', async () => {
    const reread = held();
    routes({ save: [savedAs({ ...A1, city: 'York' })], list: [reread.reply], contacts: [json(200, page([]))] });
    const u = userEvent.setup();
    showEdit({ contacts: { kind: 'server-error' } });
    const t = textsOf(pageRegion());
    await openEdit(u);
    await retype(u, 'City', 'York');
    await pressSave(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(SAVED('shipping address', '1 High St')));
    const contacts = screen.getByRole('region', { name: 'Contacts' });
    await u.click(within(contacts).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(pageRegion().textContent).toBe('No contacts for this customer yet.'));
    await act(async () => reread.release(json(500, {})));
    await new Promise((r) => setTimeout(r, 20));
    expect(t.texts).not.toContain(`${SAVED('shipping address', '1 High St')} ${LIST_STALE}`);
    expect(pageRegion().textContent).toBe('No contacts for this customer yet.');
    t.stop();
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// Customers C-5b (customers-plan-c4c5.md Revision 1 R1-4, Remove part, as fixed by customers-plan-c4b-c5-final-red.md
// M-2, M-3, M-4 (c), L-1, L-3, L-4, L-5, L-7 (c), L-11 and cut by its §4; copy customers-c4c5-ux-copy.md Revision 1a >
// Revision 1 > Revision 0 §3.4): Remove address, through the shared Delete dialog. The same rules as above: exact full
// texts, `within(dialog())`, the writer's constant byte for byte, focus read at the moment the sentence is written.
// ---------------------------------------------------------------------------------------------------------------------

/** S-10, `CustomerAddressWriter::ARCHIVED_ON_DELETE` (`CustomerAddressWriter.php:100`). Never rendered in C-5b (copy D-1). */
const S10 = "This customer is archived, so its addresses can't be removed until it's restored.";
const REMOVE_TITLE = (noun: string, line: string) => `Remove the ${noun} “${line}”?`;
const REMOVE_BODY = "The address is deleted for good, not archived, so it can't be restored. Removing it changes no order that already uses it, drafts included.";
const REMOVED = (noun: string, line: string) => `The ${noun} “${line}” was removed.`;
const REMOVE_INTERRUPTED = (noun: string, line: string) => `We can't tell whether the ${noun} “${line}” was removed. Close this and check the addresses.`;
const REMOVE_ARCHIVED = "This customer is archived, so its addresses can't be removed.";
const REMOVE_THEN_RESTORED = 'Nothing was removed, because this customer was archived at that moment. It has since been restored, so you can close this and remove the address.';
const REMOVE_AT_REMOVE = "Nothing was removed, because this customer was archived at that moment. We couldn't check whether it still is.";
const notRemoved = () => bannersOf('Not removed');
const rowRemove = (name: string) => within(addressesSection()).getByRole('button', { name });
const REMOVE_A2 = 'Remove billing address 2 Low Rd';
const openRemove = async (u: U, name = REMOVE_A2) => {
  await u.click(rowRemove(name));
  return dialog();
};
const pressRemove = (u: U) => u.click(inDialog().getByRole('button', { name: 'Remove' }));
const showRemove = (over: Parameters<typeof show>[0] = {}) => show({ canAdd: false, canRemoveAddress: true, ...over });
const gone = () => new Response(null, { status: 204 });
/** Close only: Close is there, and neither Remove nor Cancel is. */
const closeOnly = () => {
  expect(inDialog().getByRole('button', { name: 'Close' })).toBeInTheDocument();
  expect(inDialog().queryAllByRole('button', { name: 'Remove' })).toEqual([]);
  expect(inDialog().queryAllByRole('button', { name: 'Cancel' })).toEqual([]);
};

describe('/customers/[id] — Remove address (C-5b)', () => {
  it('C5b A10 / A7 — the Remove request: DELETE on the address, no body, no Idempotency-Key; the title names the address, the body is both sentences', async () => {
    const calls = routes({ remove: [gone()], list: [json(200, page([A1]))] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u);
    expect(inDialog().getByRole('heading', { name: REMOVE_TITLE('billing address', '2 Low Rd') })).toBeInTheDocument();
    expect(dialog()).toHaveAccessibleDescription(REMOVE_BODY);
    await pressRemove(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    const removes = of(calls, 'remove');
    expect(removes).toHaveLength(1);
    expect(removes[0]!.url).toMatch(new RegExp(`/bff/api/customers/${CID}/addresses/${A2.id}$`));
    expect(removes[0]!.body).toBeNull();
    expect(removes[0]!.headers.get('idempotency-key')).toBeNull();
  });

  it('C5b A12 — M-3: after a 204 the row is dropped at once, after() is spoken with focus on the heading, before the held re-read; a failed re-read then gives afterListStale and the row stays gone', async () => {
    const list = held();
    const calls = routes({ remove: [gone()], list: [list.reply] });
    const u = userEvent.setup();
    showRemove();
    const spoken = REMOVED('billing address', '2 Low Rd');
    const focus = focusWhenWritten(spoken, addressesHeading());
    await openRemove(u);
    await pressRemove(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
    expect(rowsOf(addressesSection()).map((r) => r[1])).toEqual(['1 High St']);
    expect(pageRegion().textContent).toBe(spoken);
    focus.stop();
    expect(focus.get(), 'focus is on the heading when the sentence is written (FE-D1)').toBe(addressesHeading());
    expect(focus.textAtFocus(), 'the sentence is not yet written when the heading takes focus (FE-D1 order)').not.toBe(spoken);
    expect(focus.textAtFocus()).not.toBeNull();
    await act(async () => list.release(json(500, {})));
    await waitFor(() => expect(pageRegion().textContent).toBe(`${spoken} ${LIST_STALE}`));
    expect(rowsOf(addressesSection()).map((r) => r[1])).toEqual(['1 High St']);
  });

  it('C5b A12 — L-3: two addresses of one kind and line removed in turn: the region is emptied between the two identical sentences, so the second is spoken', async () => {
    const T1 = address(1, 'shipping', '9 Twin Rd');
    const T2 = address(2, 'shipping', '9 Twin Rd');
    routes({ remove: [gone(), gone()], list: [json(200, page([T2])), json(200, page([]))] });
    const u = userEvent.setup();
    showRemove({ addresses: okAddresses([T1, T2]) });
    const t = textsOf(pageRegion());
    await u.click(within(addressesSection()).getAllByRole('button', { name: 'Remove shipping address 9 Twin Rd' })[0]!);
    await pressRemove(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(rowsOf(addressesSection())).toHaveLength(1));
    await u.click(rowRemove('Remove shipping address 9 Twin Rd'));
    await pressRemove(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(t.texts).toEqual(['', REMOVED('shipping address', '9 Twin Rd'), '', REMOVED('shipping address', '9 Twin Rd')]));
    t.stop();
  });

  it('C5b A11 — L-4: Remove → 404: Not removed + notFoundInline, Close only, no customer re-read; after Close focus is on the row\'s Remove, and when the held section re-read drops the row, on the heading', async () => {
    const list = held();
    const calls = routes({ remove: [problem(404, 'not-found')], list: [list.reply] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u);
    await pressRemove(u);
    await waitFor(() => expect(notRemoved()).toEqual([[NOT_FOUND_INLINE]]));
    closeOnly();
    expect(of(calls, 'customer')).toEqual([]);
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
    await new Promise((r) => setTimeout(r, 30));
    expect(document.activeElement).toBe(rowRemove(REMOVE_A2));
    await act(async () => list.release(json(200, page([A1]))));
    await waitFor(() => expect(rowsOf(addressesSection())).toHaveLength(1));
    expect(document.activeElement).toBe(addressesHeading());
    expect(of(calls, 'customer')).toEqual([]);
  });

  it('C5b A16 — an interrupted Remove: WENT_WRONG + interrupted(kind, line), Close only; Close re-reads the section', async () => {
    const calls = routes({ remove: [json(500, {})], list: [json(200, page([A1, A2]))] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u);
    await pressRemove(u);
    await waitFor(() => expect(bannersOf('Something went wrong on our side.')).toEqual([[REMOVE_INTERRUPTED('billing address', '2 Low Rd')]]));
    closeOnly();
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
    expect(of(calls, 'customer')).toEqual([]);
  });

  it('C5b A5 — L-7 (c): each row\'s Remove is named "Remove {noun} {line}", unquoted, and reads Remove; an unknown kind reads "address"', () => {
    routes({});
    showRemove({ addresses: okAddresses([A1, A2, address(3, 'dock', '3 Dock Rd')]) });
    expect(rowRemove('Remove shipping address 1 High St').textContent).toBe('Remove');
    expect(rowRemove(REMOVE_A2).textContent).toBe('Remove');
    expect(rowRemove('Remove address 3 Dock Rd').textContent).toBe('Remove');
  });

  it('C5b A17 — S-16 with a live re-read is shown as sent and Remove stays; with a failed re-read too', async () => {
    for (const again of [json(200, LIVE), json(500, {})]) {
      const calls = routes({ remove: [refused('transaction.contention', P1)], customer: [again] });
      const u = userEvent.setup();
      const v = showRemove();
      await openRemove(u);
      await pressRemove(u);
      await waitFor(() => expect(notRemoved()).toEqual([[P1]]));
      expect(inDialog().getByRole('button', { name: 'Remove' })).toBeInTheDocument();
      expect(inDialog().getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
      expect(of(calls, 'customer')).toHaveLength(1);
      v.unmount();
    }
  });

  it('C5b A17 — M-2: a 403 on Remove: Not removed + the permission sentence (its label in <em>), Remove stays, no customer re-read', async () => {
    const calls = routes({ remove: [problem(403, 'permission-denied')] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u);
    await pressRemove(u);
    await waitFor(() => expect(notRemoved()).toEqual([[FORBIDDEN]]));
    expect(inDialog().getByText('Edit customers, their addresses and contacts', { selector: 'em' })).toBeInTheDocument();
    expect(inDialog().getByRole('button', { name: 'Remove' })).toBeInTheDocument();
    expect(inDialog().getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    await new Promise((r) => setTimeout(r, 20));
    expect(of(calls, 'customer')).toEqual([]);
  });
});

describe('/customers/[id] — Remove address: the R12 branch (C-5b, A15 Remove column)', () => {
  it('C5b A15 — customer.is_archived + archived re-read: the interim sentence, Close only, S-10 nowhere; Close → the page re-reads: ST-9 banner, no Remove, router refreshed once', async () => {
    const calls = routes({ remove: [refused('customer.is_archived', S10)], customer: [json(200, ARCHIVED), json(200, ARCHIVED)], list: [json(200, page([A1, A2]))] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u);
    await pressRemove(u);
    await waitFor(() => expect(notRemoved()).toEqual([[REMOVE_ARCHIVED]]));
    expect(document.body.textContent).not.toContain(S10);
    expect(document.body.textContent).not.toContain("until it's restored");
    closeOnly();
    expect(of(calls, 'customer')).toHaveLength(1);
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(addressesSection()).queryAllByRole('button', { name: /^Remove/ })).toEqual([]);
    expect(within(addressesSection()).getByText(ARCHIVED_PARENT, { selector: 'p' })).toBeInTheDocument();
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('C5b A15 — customer.is_archived + live re-read: archivedThenRestored, Close only (L-1), S-10 nowhere; Close → the page re-reads the customer', async () => {
    const calls = routes({ remove: [refused('customer.is_archived', S10)], customer: [json(200, LIVE), json(200, LIVE)], list: [json(200, page([A1, A2]))] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u);
    await pressRemove(u);
    await waitFor(() => expect(notRemoved()).toEqual([[REMOVE_THEN_RESTORED]]));
    expect(document.body.textContent).not.toContain(S10);
    closeOnly();
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(2));
    expect(nav.refresh).not.toHaveBeenCalled();
  });

  it('C5b A15 — customer.is_archived + failed re-read: archivedAtRemove, Close only (L-1); Escape → the page re-reads (archived now): ST-9 banner, no Remove', async () => {
    const calls = routes({ remove: [refused('customer.is_archived', S10)], customer: [json(500, {}), json(200, ARCHIVED)], list: [json(200, page([A1, A2]))] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u);
    await pressRemove(u);
    await waitFor(() => expect(notRemoved()).toEqual([[REMOVE_AT_REMOVE]]));
    expect(document.body.textContent).not.toContain(S10);
    closeOnly();
    await u.keyboard('{Escape}');
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(addressesSection()).queryAllByRole('button', { name: /^Remove/ })).toEqual([]);
  });

  it('C5b A15 — S-16 + archived re-read: the interim sentence (never "try again"), Close only; Close → ST-9 banner, no Remove', async () => {
    const calls = routes({ remove: [refused('transaction.contention', P1)], customer: [json(200, ARCHIVED), json(200, ARCHIVED)], list: [json(200, page([A1, A2]))] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u);
    await pressRemove(u);
    await waitFor(() => expect(notRemoved()).toEqual([[REMOVE_ARCHIVED]]));
    expect(document.body.textContent).not.toContain(P1);
    closeOnly();
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(addressesSection()).queryAllByRole('button', { name: /^Remove/ })).toEqual([]);
  });

  it('C5b A15 — L-11: after a customer.is_archived + live re-read Remove is closed (one page re-read), a later Remove dialog\'s Cancel re-reads nothing', async () => {
    const calls = routes({ remove: [refused('customer.is_archived', S10)], customer: [json(200, LIVE), json(200, LIVE), json(200, LIVE)], list: [json(200, page([A1, A2]))] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u);
    await pressRemove(u);
    await waitFor(() => expect(notRemoved()).toEqual([[REMOVE_THEN_RESTORED]]));
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(2));
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
    await openRemove(u, 'Remove shipping address 1 High St');
    await u.click(inDialog().getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await new Promise((r) => setTimeout(r, 30));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(of(calls, 'list')).toHaveLength(1);
  });
});

describe('/customers/[id] — who sees Remove address, and Remove\'s late corrections (C-5b)', () => {
  it('C5b A14 — canRemoveAddress: an Actions column with Remove per row; archived with the prop: no Remove, no Actions column', () => {
    routes({});
    const v = showRemove();
    expect(within(addressesSection()).getByRole('columnheader', { name: 'Actions' })).toBeInTheDocument();
    expect(rowRemove(REMOVE_A2)).toBeInTheDocument();
    v.unmount();
    showRemove({ customer: ARCHIVED });
    expect(within(addressesSection()).queryAllByRole('button', { name: /^Remove/ })).toEqual([]);
    expect(within(addressesSection()).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Kind', 'Address', 'City', 'Postal code', 'Country']);
  });

  it('C5b A14 — L-5: canRemoveAddress alone: the Actions column with Remove and no Edit', () => {
    routes({});
    showRemove();
    expect(within(addressesSection()).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Kind', 'Address', 'City', 'Postal code', 'Country', 'Actions']);
    expect(rowRemove(REMOVE_A2)).toBeInTheDocument();
    expect(within(addressesSection()).queryAllByRole('button', { name: /^Edit/ })).toEqual([]);
  });

  it('C5b A14 — M-4 (c), the L-5 press order: change Code, press Save (held), open an address\'s Remove; Save answers customer.is_archived with an archived re-read: the dialog is still open, focus inside it', async () => {
    const save = held();
    routes({ customerSave: [save.reply], customer: [json(200, ARCHIVED)] });
    const u = userEvent.setup();
    showRemove({ canEdit: true });
    const code = screen.getByRole('textbox', { name: 'Code' });
    await u.clear(code);
    await u.type(code, 'NORTH2');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openRemove(u);
    await act(async () => save.release(refused('customer.is_archived', "This customer is archived, so it can't be changed until it's restored.")));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(inDialog().getByRole('heading', { name: REMOVE_TITLE('billing address', '2 Low Rd') })).toBeInTheDocument();
    expect(dialog().contains(document.activeElement)).toBe(true);
  });

  // Commit RED M-1: the F-6 fresh-stamp guard in closeRemove (the supplier-catalogue-remove.test.tsx:225 precedent).
  it('C5b A18 — F-6: an Add re-read sent before Remove and answered after its 204 cannot bring the removed row back', async () => {
    const addRead = held();
    const removeRead = held();
    const NEW = address(9, 'shipping', '12 Mill Lane', { city: 'Leeds', postal_code: 'LS1 4AB' });
    routes({ add: [created()], list: [addRead.reply, removeRead.reply], remove: [gone()] });
    const u = userEvent.setup();
    show({ canRemoveAddress: true });
    await openAdd(u);
    await fill(u);
    await submitAdd(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await openRemove(u);
    await pressRemove(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(rowsOf(addressesSection()).map((r) => r[1])).not.toContain('2 Low Rd'));
    await act(async () => addRead.release(json(200, page([A1, A2, NEW]))));
    await new Promise((r) => setTimeout(r, 20));
    expect(rowsOf(addressesSection()).map((r) => r[1])).not.toContain('2 Low Rd');
    await act(async () => removeRead.release(json(500, {})));
    await new Promise((r) => setTimeout(r, 20));
    expect(rowsOf(addressesSection()).map((r) => r[1])).not.toContain('2 Low Rd');
  });

  it('C5b A13 — positive half: a failed section re-read after Remove replaces after() with afterListStale once', async () => {
    const reread = held();
    routes({ remove: [gone()], list: [reread.reply] });
    const u = userEvent.setup();
    showRemove();
    const t = textsOf(pageRegion());
    await openRemove(u);
    await pressRemove(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(REMOVED('billing address', '2 Low Rd')));
    await act(async () => reread.release(json(503, {})));
    await waitFor(() => expect(pageRegion().textContent).toBe(`${REMOVED('billing address', '2 Low Rd')} ${LIST_STALE}`));
    expect(t.texts.filter((x) => x !== '')).toEqual([REMOVED('billing address', '2 Low Rd'), `${REMOVED('billing address', '2 Low Rd')} ${LIST_STALE}`]);
    t.stop();
  });

  it('C5b A13 — K9-1 mirror for Remove: the Contacts Retry writes the region between the 204 and its failed re-read: afterListStale is never written', async () => {
    const reread = held();
    routes({ remove: [gone()], list: [reread.reply], contacts: [json(200, page([]))] });
    const u = userEvent.setup();
    showRemove({ contacts: { kind: 'server-error' } });
    const t = textsOf(pageRegion());
    await openRemove(u);
    await pressRemove(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(REMOVED('billing address', '2 Low Rd')));
    const contacts = screen.getByRole('region', { name: 'Contacts' });
    await u.click(within(contacts).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(pageRegion().textContent).toBe('No contacts for this customer yet.'));
    await act(async () => reread.release(json(500, {})));
    await new Promise((r) => setTimeout(r, 20));
    expect(t.texts).not.toContain(`${REMOVED('billing address', '2 Low Rd')} ${LIST_STALE}`);
    expect(pageRegion().textContent).toBe('No contacts for this customer yet.');
    t.stop();
  });
});
