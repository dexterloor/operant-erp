import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
// Final RED L-6: a hoisted router spy (the `customer-editor.test.tsx` shape), so `router.refresh()` is observable.
const nav = vi.hoisted(() => ({ refresh: vi.fn(), replace: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh, replace: nav.replace }), usePathname: () => '/customers/x' }));
import { CustomerPage } from '@/app/(app)/customers/[id]/CustomerPage';
import type { Customer, CustomerContact } from '@/lib/api/customers';
import type { AddressesLoad, ContactsLoad } from '@/lib/customers/load';
import { formatDate } from '@/lib/format/dates';
import { TZ, held, json, page, problem, refused, renderWired, textsOf, type Recorded, type Reply } from './harness';

// Customers C-4a (customers-plan-c4c5.md Revision 1 R1-2 as fixed by customers-plan-c4a-final-red.md; copy
// customers-c4c5-ux-copy.md Revision 1a > Revision 1 > Revision 0): Add contact and Edit contact on the customer page's
// Contacts section, and the page machinery they share (`replaceIfCurrent`, `onParentStale` + `router.refresh()`, the
// section's stamp rule, the in-action customer re-read, the R12 branch). The page is rendered whole, so every test drives
// the real wired actions (`lib/customers/wired.ts`) over the BFF, where the behaviour lives. Every client sentence is
// asserted by EXACT full text; every dialog assertion is scoped `within(dialog())` (R-4); every request body is asserted
// with `toStrictEqual` over its full key set (R-6); every `customer.is_archived` fixture's message is the writer's
// constant byte for byte (R-5).

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
const contact = (n: number, over: Partial<CustomerContact> = {}): CustomerContact => ({ id: `c1000000-0000-4000-8000-00000000000${n}`, name: `Contact ${n}`, email: `c${n}@example.com`, phone: `+44 20 7946 000${n}`, is_primary: false, ...over });
const okContacts = (contacts: CustomerContact[]): ContactsLoad => ({ kind: 'ok', contacts });
const NO_ADDRESSES: AddressesLoad = { kind: 'ok', addresses: [] };

// The server's sentences, byte for byte.
/** S-1, `CustomerContactWriter::ARCHIVED_ON_CREATE` (`CustomerContactWriter.php:96`). Never rendered in C-4a (copy D-1). */
const S1 = "This customer is archived, so no contact can be added to it until it's restored.";
/** S-4 / S-5 / S-6 (`CustomerContactWriter.php:105`, `:109`, `:439-440`). */
const S4 = 'Enter an email address like name@example.com.';
const S5 = 'A phone number can be at most 50 characters.';
const S6 = 'This field may not be blank.';
/** S-16 (`TransactionContention.php:32`). */
const P1 = 'Some of the same records were being changed at the same moment. Nothing was saved; try again.';

// The client's sentences (copy Revision 1a), exact.
const ADD_ARCHIVED = 'This customer is archived, so no contact can be added to it.';
const ADD_THEN_RESTORED = 'Nothing was added, because this customer was archived at that moment. It has since been restored, so you can press Add contact again.';
const ADD_AT_ADD = "Nothing was added, because this customer was archived at that moment. We couldn't check whether it still is.";
const NOT_FOUND_INLINE = "We couldn't find that. It may have been deleted, or you may not have access.";
const REFUSED_WITHOUT_MESSAGE = "This change wasn't made. Reload the page to see where things stand, then try again.";
const FORBIDDEN = 'This needs the Edit customers, their addresses and contacts permission, which you no longer have. Your workspace administrator can grant it.';
const PRIMARY_HINT = "Only one contact can be primary, and it's listed first. Marking this one takes the mark off any other.";
const NAME_REQUIRED = 'Enter a name.';
const EMPTY_LIVE = 'No contacts for this customer yet.';
const EMPTY_ARCHIVED = 'This customer has no contacts.';
const ARCHIVED_PARENT = "Contacts can't be added, changed or removed while the customer is archived.";
const ADDED = (name: string) => `“${name}” was added to the contacts.`;
const PRIMARY_NOW = "It's now the primary contact.";
const LIST_STALE = "We couldn't load the list again, so it may be out of date.";
const ADD_INTERRUPTED = (name: string) => `We can't tell whether “${name}” was added. Check this customer's contacts before you try again.`;
/** The ST-9 banner, exact (the `customer-editor.test.tsx` shape: the date is formatted in the session's zone). */
const ARCHIVED_BANNER = () => `This customer was archived on ${formatDate(AT, TZ)}.`;
const archivedBanner = () => screen.getByText(/This customer was archived on/);

// Routed fetch: each request takes the next reply queued for its route, whatever the order the page sends them in.
type Route = 'add' | 'save' | 'remove' | 'list' | 'customer' | 'customerSave' | 'addresses';
type Next = Reply;
const routeOf = (method: string, url: string): Route => {
  if (/\/contacts$/.test(url)) return method === 'POST' ? 'add' : 'list';
  if (/\/contacts\/[0-9a-f-]{36}$/.test(url) && method === 'PATCH') return 'save';
  // Customers C-4b: Remove contact.
  if (/\/contacts\/[0-9a-f-]{36}$/.test(url) && method === 'DELETE') return 'remove';
  if (/\/addresses$/.test(url)) return 'addresses';
  if (new RegExp(`/customers/${CID}$`).test(url)) return method === 'PATCH' ? 'customerSave' : 'customer';
  throw new Error(`unrouted ${method} ${url}`);
};
function routes(queues: Partial<Record<Route, Next[]>>) {
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

const show = ({ customer = LIVE, canEdit = false, canAdd = true, canEditContact = false, canRemoveContact = false, contacts = okContacts([contact(1, { is_primary: true }), contact(2)]), addresses = NO_ADDRESSES }: { customer?: Customer; canEdit?: boolean; canAdd?: boolean; canEditContact?: boolean; canRemoveContact?: boolean; contacts?: ContactsLoad | null; addresses?: AddressesLoad | null } = {}) =>
  renderWired(<CustomerPage customer={customer} canEdit={canEdit} canAddContact={canAdd} canEditContact={canEditContact} canRemoveContact={canRemoveContact} contacts={contacts} addresses={addresses} />);
const pageRegion = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const contactsSection = () => screen.getByRole('region', { name: 'Contacts' });
const contactsHeading = () => within(contactsSection()).getByRole('heading', { level: 2, name: 'Contacts' });
const rowsOf = (s: HTMLElement) => within(s).queryAllByRole('row').slice(1).map((r) => within(r).getAllByRole('cell').map((c) => c.textContent));
const dialog = () => screen.getByRole('dialog');
const headerAdd = () => within(contactsSection()).getAllByRole('button', { name: 'Add contact' })[0]!;
const inDialog = () => within(dialog());
/** The dialog's **Not added** banner, as the exact texts of its body paragraphs (the heading is the banner's first `p`). */
const notAdded = () => inDialog().queryAllByText('Not added', { selector: 'p' }).map((h) => [...h.parentElement!.children].filter((n) => n !== h).map((n) => n.textContent));
const bannersOf = (heading: string) => inDialog().queryAllByText(heading, { selector: 'p' }).map((h) => [...h.parentElement!.children].filter((n) => n !== h).map((n) => n.textContent));
const typeName = async (u: ReturnType<typeof userEvent.setup>, name: string) => {
  const f = inDialog().getByRole('textbox', { name: 'Name' });
  await u.clear(f);
  if (name !== '') await u.type(f, name);
};
const submitAdd = (u: ReturnType<typeof userEvent.setup>) => u.click(inDialog().getByRole('button', { name: 'Add contact' }));
const openAdd = async (u: ReturnType<typeof userEvent.setup>) => {
  await u.click(headerAdd());
  return dialog();
};
const created = (over: Partial<CustomerContact> = {}) => json(201, contact(9, { name: 'Ann Lee', email: null, phone: null, ...over }));

describe('/customers/[id] — Add contact (C-4a chunk 1)', () => {
  // Test 1 (Add half): bodies, an empty Name.
  it('test 1 — Add omits an empty Email and Phone, sends is_primary false when unchecked, and the Name as typed', async () => {
    const calls = routes({ add: [created()], list: [json(200, page([contact(1, { is_primary: true }), contact(2), contact(9, { name: 'Ann Lee' })]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    const adds = of(calls, 'add');
    expect(adds).toHaveLength(1);
    expect(adds[0]!.url).toMatch(new RegExp(`/bff/api/customers/${CID}/contacts$`));
    expect(JSON.parse(adds[0]!.body!)).toStrictEqual({ name: 'Ann Lee', is_primary: false });
  });

  it('test 1 — Add sends a typed Email and Phone as typed, and is_primary true (a JSON boolean) when ticked', async () => {
    const calls = routes({ add: [created({ is_primary: true })], list: [json(200, page([contact(9, { name: 'Ann Lee', is_primary: true })]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await u.type(inDialog().getByRole('textbox', { name: 'Email (optional)' }), 'ann@example.com');
    await u.type(inDialog().getByRole('textbox', { name: 'Phone (optional)' }), '+44 1');
    await u.click(inDialog().getByRole('checkbox', { name: 'Primary contact' }));
    await submitAdd(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(JSON.parse(of(calls, 'add')[0]!.body!)).toStrictEqual({ name: 'Ann Lee', email: 'ann@example.com', phone: '+44 1', is_primary: true });
  });

  it('test 1 — an empty Name: "Enter a name." beside it, focus on Name, no request; a Name of spaces is sent as typed and S-6 shows beside it', async () => {
    const calls = routes({ add: [problem(422, 'validation-failed', { errors: [{ pointer: '/name', code: 'blank', message: S6 }] })] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await submitAdd(u);
    const name = inDialog().getByRole('textbox', { name: 'Name' });
    expect(name).toHaveAccessibleDescription(NAME_REQUIRED);
    await waitFor(() => expect(document.activeElement).toBe(name));
    expect(calls).toEqual([]);
    await u.type(name, '   ');
    await submitAdd(u);
    await waitFor(() => expect(name).toHaveAccessibleDescription(S6));
    expect(JSON.parse(of(calls, 'add')[0]!.body!)).toStrictEqual({ name: '   ', is_primary: false });
    // A field 422 is beside its field: no customer re-read, no contacts re-read.
    expect(of(calls, 'customer')).toEqual([]);
    expect(of(calls, 'list')).toEqual([]);
  });

  // Test 2 (Add half): the primary mark, the re-read, focus.
  it('test 2 — after a 201 the section re-reads (a sibling that lost the mark shows No); only the write\'s sentence is spoken, with the 201\'s name; focus on the Contacts heading', async () => {
    routes({ add: [created({ name: 'Ann Lee (served)', is_primary: true })], list: [json(200, page([contact(9, { name: 'Ann Lee (served)', is_primary: true }), contact(1), contact(2)]))] });
    const u = userEvent.setup();
    show();
    const t = textsOf(pageRegion());
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await u.click(inDialog().getByRole('checkbox', { name: 'Primary contact' }));
    await submitAdd(u);
    await waitFor(() => expect(rowsOf(contactsSection())).toHaveLength(3));
    expect(rowsOf(contactsSection()).map(([n, , , p]) => [n, p])).toEqual([
      ['Ann Lee (served)', 'Yes'],
      ['Contact 1', 'No'],
      ['Contact 2', 'No'],
    ]);
    expect(pageRegion().textContent).toBe(`${ADDED('Ann Lee (served)')} ${PRIMARY_NOW}`);
    expect(t.texts.filter((x) => x !== '')).toEqual([`${ADDED('Ann Lee (served)')} ${PRIMARY_NOW}`]);
    t.stop();
    expect(document.activeElement).toBe(contactsHeading());
  });

  it('test 2 — M-3: Add ticked, answered primary, the section re-read fails: the table is exactly the pre-write table', async () => {
    routes({ add: [created({ is_primary: true })], list: [json(500, {})] });
    const u = userEvent.setup();
    show();
    const before = rowsOf(contactsSection());
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await u.click(inDialog().getByRole('checkbox', { name: 'Primary contact' }));
    await submitAdd(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(`${ADDED('Ann Lee')} ${PRIMARY_NOW} ${LIST_STALE}`));
    expect(rowsOf(contactsSection())).toEqual(before);
    expect(rowsOf(contactsSection()).filter(([, , , p]) => p === 'Yes')).toHaveLength(1);
  });

  it('test 2 / test 14 — no primary clause when the box was not ticked, nor when the 201 is not primary', async () => {
    routes({ add: [created({ is_primary: false }), created({ is_primary: true })], list: [json(200, page([contact(1)])), json(200, page([contact(1)]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await u.click(inDialog().getByRole('checkbox', { name: 'Primary contact' }));
    await submitAdd(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(ADDED('Ann Lee')));
    await waitFor(() => expect(rowsOf(contactsSection())).toHaveLength(1));
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(ADDED('Ann Lee')));
  });

  // Test 3 (Add half): keys.
  it('test 3 — a fresh Idempotency-Key for every Add submit', async () => {
    const calls = routes({ add: [json(500, {}), created()], list: [json(200, page([contact(1)]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(bannersOf("Something went wrong on our side.")).toEqual([[ADD_INTERRUPTED('Ann Lee')]]));
    await submitAdd(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    const keys = of(calls, 'add').map((c) => c.headers.get('Idempotency-Key'));
    expect(keys).toHaveLength(2);
    for (const k of keys) expect(k).toMatch(/^[0-9a-f-]{36}$/);
    expect(keys[0]).not.toBe(keys[1]);
  });

  // Test 4a (Add half): a 404.
  it('test 4a — Add → 404: Not added + notFoundInline, Close only; Close has the page re-read the customer (→ ST-5)', async () => {
    const calls = routes({ add: [problem(404, 'not-found')], customer: [problem(404, 'not-found')], list: [problem(404, 'not-found')] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[NOT_FOUND_INLINE]]));
    expect(inDialog().queryByRole('button', { name: 'Add contact' })).toBeNull();
    expect(inDialog().queryByRole('button', { name: 'Cancel' })).toBeNull();
    // No customer re-read inside the action on a 404.
    expect(of(calls, 'customer')).toEqual([]);
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    // ST-5: the page swaps to the not-found state.
    await waitFor(() => expect(screen.getByText("We couldn't find that.", { selector: 'h1' })).toBeInTheDocument());
    expect(screen.queryByRole('region', { name: 'Contacts' })).toBeNull();
    expect(of(calls, 'customer')).toHaveLength(1);
  });

  // Test 13a (Add column): the R12 branch.
  it('test 13a — customer.is_archived + archived re-read: the interim sentence, Close only, S-1 nowhere; Close → the page re-reads: ST-9 banner, no Add contact, router refreshed once', async () => {
    const calls = routes({ add: [refused('customer.is_archived', S1)], customer: [json(200, ARCHIVED), json(200, ARCHIVED)], list: [json(200, page([contact(1)]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[ADD_ARCHIVED]]));
    expect(document.body.textContent).not.toContain(S1);
    expect(document.body.textContent).not.toContain("until it's restored");
    expect(inDialog().queryByRole('button', { name: 'Add contact' })).toBeNull();
    expect(of(calls, 'customer')).toHaveLength(1);
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(document.activeElement).toBe(screen.getByRole('heading', { level: 1 }));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(contactsSection()).queryByRole('button', { name: 'Add contact' })).toBeNull();
    expect(within(contactsSection()).getByText(ARCHIVED_PARENT, { selector: 'p' })).toBeInTheDocument();
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('test 13a — customer.is_archived + live re-read: archivedThenRestored, the typed values and Add stay; Cancel → the page re-reads the customer', async () => {
    const calls = routes({ add: [refused('customer.is_archived', S1)], customer: [json(200, LIVE), json(200, LIVE)], list: [json(200, page([contact(1)]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[ADD_THEN_RESTORED]]));
    expect(document.body.textContent).not.toContain(S1);
    expect(document.body.textContent).not.toContain("We couldn't check");
    expect(inDialog().getByRole('textbox', { name: 'Name' })).toHaveValue('Ann Lee');
    expect(inDialog().getByRole('button', { name: 'Add contact' })).toBeInTheDocument();
    await u.click(inDialog().getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(2));
    expect(nav.refresh).not.toHaveBeenCalled();
  });

  it('test 13a — customer.is_archived + failed re-read: archivedAtAdd, values and Add stay; Escape → the page re-reads (archived now): ST-9 banner, no Add contact', async () => {
    const calls = routes({ add: [refused('customer.is_archived', S1)], customer: [json(500, {}), json(200, ARCHIVED)], list: [json(200, page([contact(1)]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[ADD_AT_ADD]]));
    expect(document.body.textContent).not.toContain(S1);
    expect(document.body.textContent).not.toContain('It has since been restored');
    expect(inDialog().getByRole('textbox', { name: 'Name' })).toHaveValue('Ann Lee');
    expect(inDialog().getByRole('button', { name: 'Add contact' })).toBeInTheDocument();
    await u.keyboard('{Escape}');
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(contactsSection()).queryByRole('button', { name: 'Add contact' })).toBeNull();
  });

  it('test 13a — S-16 + archived re-read: the interim sentence (never "try again"), Close only; Close → ST-9 banner, no Add contact', async () => {
    const calls = routes({ add: [refused('transaction.contention', P1)], customer: [json(200, ARCHIVED), json(200, ARCHIVED)], list: [json(200, page([contact(1)]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[ADD_ARCHIVED]]));
    expect(document.body.textContent).not.toContain(P1);
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(contactsSection()).queryByRole('button', { name: 'Add contact' })).toBeNull();
  });

  it('test 13a — M-3 (final RED): customer.is_archived + live re-read, Add again → 201: the dialog closes and the page\'s GetCustomer is requested once', async () => {
    const calls = routes({ add: [refused('customer.is_archived', S1), created()], customer: [json(200, LIVE), json(200, LIVE)], list: [json(200, page([contact(1)]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[ADD_THEN_RESTORED]]));
    expect(of(calls, 'customer')).toHaveLength(1);
    await submitAdd(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(2));
  });

  // Test 18a (Add half): other refusals and a 403.
  it('test 18a — S-16 with a live re-read is shown as sent and Add stays; with a failed re-read too', async () => {
    const calls = routes({ add: [refused('transaction.contention', P1), refused('transaction.contention', P1)], customer: [json(200, LIVE), json(500, {})] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[P1]]));
    expect(inDialog().getByRole('button', { name: 'Add contact' })).toBeInTheDocument();
    await submitAdd(u);
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(2));
    await waitFor(() => expect(notAdded()).toEqual([[P1]]));
    expect(inDialog().getByRole('button', { name: 'Add contact' })).toBeInTheDocument();
  });

  it('test 18a — a 422 with no violations re-reads the customer and shows REFUSED_WITHOUT_MESSAGE; a 403 shows the permission sentence, Add stays, no re-read', async () => {
    const calls = routes({ add: [problem(422, 'validation-failed'), problem(403, 'permission-denied')], customer: [json(200, LIVE)] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[REFUSED_WITHOUT_MESSAGE]]));
    expect(of(calls, 'customer')).toHaveLength(1);
    await submitAdd(u);
    await waitFor(() => expect(notAdded()).toEqual([[FORBIDDEN]]));
    expect(inDialog().getByRole('button', { name: 'Add contact' })).toBeInTheDocument();
    expect(of(calls, 'customer')).toHaveLength(1);
  });

  it('test 18a — M-4 (final RED): a 201 whose body is not a whole contact → the dialog\'s WENT_WRONG banner, Add stays, nothing spoken in the page region', async () => {
    routes({ add: [json(201, { id: 'x', email: null, phone: null, is_primary: false })] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(bannersOf('Something went wrong on our side.')).toEqual([[ADD_INTERRUPTED('Ann Lee')]]));
    expect(inDialog().getByRole('button', { name: 'Add contact' })).toBeInTheDocument();
    expect(pageRegion().textContent).toBe('');
  });

  // Test 16a (Add half): an interrupted Add.
  it('test 16a — an interrupted Add keeps Add with interrupted(typed name); Close re-reads the section', async () => {
    const calls = routes({ add: [() => Promise.reject(new TypeError('network'))], list: [json(200, page([contact(1)]))] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(bannersOf("We couldn't reach the server.")).toEqual([[ADD_INTERRUPTED('Ann Lee')]]));
    expect(inDialog().getByRole('button', { name: 'Add contact' })).toBeInTheDocument();
    await u.click(inDialog().getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
  });

  // Test 17 (Add half): field 422s.
  it('test 17 — S-4 beside Email and S-5 beside Phone, focus on the first, no banner, no re-read', async () => {
    const calls = routes({
      add: [problem(422, 'validation-failed', { errors: [{ pointer: '/email', code: 'not_an_email', message: S4 }, { pointer: '/phone', code: 'too_long', message: S5 }] })],
    });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await u.type(inDialog().getByRole('textbox', { name: 'Email (optional)' }), 'ann');
    await u.type(inDialog().getByRole('textbox', { name: 'Phone (optional)' }), '1');
    await submitAdd(u);
    const email = inDialog().getByRole('textbox', { name: 'Email (optional)' });
    await waitFor(() => expect(email).toHaveAccessibleDescription(S4));
    expect(inDialog().getByRole('textbox', { name: 'Phone (optional)' })).toHaveAccessibleDescription(S5);
    await waitFor(() => expect(document.activeElement).toBe(email));
    expect(notAdded()).toEqual([]);
    expect(of(calls, 'customer')).toEqual([]);
    expect(of(calls, 'list')).toEqual([]);
  });

  // Test 12 (Add half, L-3): primaryHint on Add always.
  it('test 12 — Add shows primaryHint under the box, as its description', async () => {
    routes({});
    const u = userEvent.setup();
    show();
    await openAdd(u);
    expect(inDialog().getByRole('checkbox', { name: 'Primary contact' })).toHaveAccessibleDescription(PRIMARY_HINT);
    expect(inDialog().getByText(PRIMARY_HINT, { selector: 'p' })).toBeInTheDocument();
  });
});

describe('/customers/[id] — who sees Add contact, and the empty states (C-4a chunk 1)', () => {
  it('test 7a — no Add prop: no Add contact, no dialog; with it on a live customer: Add in the header (and after an empty sentence)', () => {
    const { unmount } = show({ canAdd: false });
    expect(within(contactsSection()).queryByRole('button')).toBeNull();
    expect(screen.queryByRole('dialog')).toBeNull();
    unmount();
    const r2 = show({ contacts: okContacts([]) });
    expect(within(contactsSection()).getAllByRole('button', { name: 'Add contact' })).toHaveLength(2);
    r2.unmount();
    show();
    expect(within(contactsSection()).getAllByRole('button', { name: 'Add contact' })).toHaveLength(1);
    expect(within(contactsSection()).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Name', 'Email', 'Phone', 'Primary']);
  });

  it('test 7a — L-7: Add is offered in the header in the region-error state, never when the section is denied', () => {
    const { unmount } = show({ contacts: { kind: 'server-error' } });
    expect(within(contactsSection()).getByRole('button', { name: 'Add contact' })).toBeInTheDocument();
    unmount();
    show({ contacts: { kind: 'forbidden' } });
    expect(within(contactsSection()).queryByRole('button', { name: 'Add contact' })).toBeNull();
  });

  it('test 7a / 9 — archived with canAdd: no action; archivedParent above the table, and above emptyArchived on an empty section; never on a live page', () => {
    const { unmount } = show({ customer: ARCHIVED });
    expect(within(contactsSection()).queryAllByRole('button')).toEqual([]);
    const ps = [...contactsSection().querySelectorAll('p')].map((p) => p.textContent);
    expect(ps).toEqual([ARCHIVED_PARENT]);
    unmount();
    const r = show({ customer: ARCHIVED, contacts: okContacts([]) });
    expect([...contactsSection().querySelectorAll('p')].map((p) => p.textContent)).toEqual([ARCHIVED_PARENT, EMPTY_ARCHIVED]);
    r.unmount();
    show({ contacts: okContacts([]) });
    expect([...contactsSection().querySelectorAll('p')].map((p) => p.textContent)).toEqual([EMPTY_LIVE]);
  });

  it('test 9 — F-11: a Retry that brings none on an archived page speaks emptyArchived; on a live page, empty', async () => {
    routes({ list: [json(200, page([])), json(200, page([]))] });
    const u = userEvent.setup();
    const r = show({ customer: ARCHIVED, contacts: { kind: 'server-error' } });
    await u.click(within(contactsSection()).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(pageRegion().textContent).toBe(EMPTY_ARCHIVED));
    r.unmount();
    show({ contacts: { kind: 'server-error' } });
    await u.click(within(contactsSection()).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(pageRegion().textContent).toBe(EMPTY_LIVE));
  });

  it('test 7a — M-4 (final RED, L-5 order): an open Add dialog survives the page turning archived; its typed name stays and focus stays inside it', async () => {
    const save = held();
    routes({ customerSave: [save.reply], customer: [json(200, ARCHIVED)] });
    const u = userEvent.setup();
    show({ canEdit: true });
    const code = screen.getByRole('textbox', { name: 'Code' });
    await u.clear(code);
    await u.type(code, 'NORTH2');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await act(async () => save.release(refused('customer.is_archived', "This customer is archived, so it can't be changed until it's restored.")));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(inDialog().getByRole('textbox', { name: 'Name' })).toHaveValue('Ann Lee');
    expect(dialog().contains(document.activeElement)).toBe(true);
  });
});

describe('/customers/[id] — late corrections and late answers (C-4a chunk 1)', () => {
  it('test 6a — a failed section re-read after Add replaces the sentence with its stale form', async () => {
    routes({ add: [created()], list: [json(503, {})] });
    const u = userEvent.setup();
    show();
    const t = textsOf(pageRegion());
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(`${ADDED('Ann Lee')} ${LIST_STALE}`));
    expect(t.texts.filter((x) => x !== '').at(-1)).toBe(`${ADDED('Ann Lee')} ${LIST_STALE}`);
    t.stop();
  });

  it.each([
    ['a 200 with a non-null next_cursor', () => json(200, page([contact(1)], { next_cursor: 'abc' }))],
    ['a 404', () => problem(404, 'not-found')],
  ] as const)('test 6a — L-5: the section re-read answers %s → the stale form', async (_, reply) => {
    routes({ add: [created()], list: [reply()] });
    const u = userEvent.setup();
    show();
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(`${ADDED('Ann Lee')} ${LIST_STALE}`));
  });

  it('test 6a — K9-1: the Addresses Retry writes the region between the Add and its failed re-read: the stale form is never written', async () => {
    const reread = held();
    routes({ add: [created()], list: [reread.reply], addresses: [json(200, page([]))] });
    const u = userEvent.setup();
    show({ addresses: { kind: 'server-error' } });
    const t = textsOf(pageRegion());
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(ADDED('Ann Lee')));
    const addresses = screen.getByRole('region', { name: 'Addresses' });
    await u.click(within(addresses).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(pageRegion().textContent).toBe('No addresses yet.'));
    await act(async () => reread.release(json(500, {})));
    await new Promise((r) => setTimeout(r, 20));
    expect(t.texts).not.toContain(`${ADDED('Ann Lee')} ${LIST_STALE}`);
    expect(pageRegion().textContent).toBe('No addresses yet.');
    t.stop();
  });

  it('test 19 — a Retry in flight under an Add: the Add\'s re-read lands first; the Retry\'s older answer is dropped', async () => {
    const retry = held();
    routes({ list: [retry.reply, json(200, page([contact(9, { name: 'Ann Lee' }), contact(1)]))], add: [created()] });
    const u = userEvent.setup();
    show({ contacts: { kind: 'server-error' } });
    await u.click(within(contactsSection()).getByRole('button', { name: 'Retry' }));
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(rowsOf(contactsSection()).map(([n]) => n)).toEqual(['Ann Lee', 'Contact 1']));
    await act(async () => retry.release(json(200, page([contact(1)]))));
    await new Promise((r) => setTimeout(r, 20));
    expect(rowsOf(contactsSection()).map(([n]) => n)).toEqual(['Ann Lee', 'Contact 1']);
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// Chunk 2: Edit contact, the primary mark and its clauses.
// ---------------------------------------------------------------------------------------------------------------------

/** S-2, `CustomerContactWriter::ARCHIVED_ON_UPDATE` (`CustomerContactWriter.php:98`). Never rendered in C-4a (copy D-1). */
const S2 = "This customer is archived, so its contacts can't be changed until it's restored.";
const EDIT_ARCHIVED = "This customer is archived, so its contacts can't be changed.";
const EDIT_THEN_RESTORED = 'Nothing was saved, because this customer was archived at that moment. It has since been restored, so you can press Save again.';
const EDIT_AT_SAVE = "Nothing was saved, because this customer was archived at that moment. We couldn't check whether it still is.";
const SAVED = (name: string) => `“${name}” was saved.`;
const PRIMARY_NO_LONGER = "It's no longer the primary contact.";
const PRIMARY_CLEAR_HINT = 'This is the primary contact. If you clear the mark, this customer has no primary contact until another one is marked.';
const EDIT_INTERRUPTED = (name: string) => `We can't tell whether your changes to “${name}” were saved. Close this and check the contacts before you try again.`;
const C1 = contact(1, { is_primary: true });
const C2 = contact(2);
const notSaved = () => bannersOf('Not saved');
const rowEdit = (name: string) => within(contactsSection()).getByRole('button', { name: `Edit ${name}` });
const openEdit = async (u: ReturnType<typeof userEvent.setup>, name: string) => {
  await u.click(rowEdit(name));
  return dialog();
};
const pressSave = (u: ReturnType<typeof userEvent.setup>) => u.click(inDialog().getByRole('button', { name: 'Save' }));
const field = (name: string) => inDialog().getByRole('textbox', { name });
const savedAs = (c: CustomerContact) => json(200, c);
const showEdit = (over: Parameters<typeof show>[0] = {}) => show({ canAdd: false, canEditContact: true, contacts: okContacts([C1, C2]), ...over });

describe('/customers/[id] — Edit contact (C-4a chunk 2)', () => {
  // Test 1 (Edit half).
  it('test 1 — Edit sends the changed members only, a cleared Phone as null, no is_primary when the box did not change', async () => {
    const calls = routes({ save: [savedAs({ ...C2, phone: null })], list: [json(200, page([C1, { ...C2, phone: null }]))] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u, 'Contact 2');
    expect(inDialog().getByRole('heading', { name: 'Edit contact “Contact 2”' })).toBeInTheDocument();
    await u.clear(field('Phone (optional)'));
    await pressSave(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    const saves = of(calls, 'save');
    expect(saves).toHaveLength(1);
    expect(saves[0]!.url).toMatch(new RegExp(`/bff/api/customers/${CID}/contacts/${C2.id}$`));
    expect(JSON.parse(saves[0]!.body!)).toStrictEqual({ phone: null });
  });

  it('test 1 — an emptied Name: "Enter a name." beside it, focus on Name, no request', async () => {
    const calls = routes({});
    const u = userEvent.setup();
    showEdit();
    await openEdit(u, 'Contact 2');
    await u.clear(field('Name'));
    await pressSave(u);
    expect(field('Name')).toHaveAccessibleDescription(NAME_REQUIRED);
    await waitFor(() => expect(document.activeElement).toBe(field('Name')));
    expect(calls).toEqual([]);
  });

  it('test 1 — unchanged: Save is aria-disabled with "Nothing to save." as its exact description, and a press sends nothing', async () => {
    const calls = routes({});
    const u = userEvent.setup();
    showEdit();
    await openEdit(u, 'Contact 2');
    const save = inDialog().getByRole('button', { name: 'Save' });
    expect(save).toHaveAttribute('aria-disabled', 'true');
    expect(save).toHaveAccessibleDescription('Nothing to save.');
    await u.click(save);
    expect(calls).toEqual([]);
  });

  // Test 2 (Edit half).
  it('test 2 — marking a contact primary: is_primary true sent; after the 200 the section re-reads, the old primary shows No; the 200\'s name; focus on that row\'s Edit', async () => {
    const served = { ...C2, name: 'Contact 2 (served)', is_primary: true };
    const calls = routes({ save: [savedAs(served)], list: [json(200, page([served, { ...C1, is_primary: false }]))] });
    const u = userEvent.setup();
    showEdit();
    const t = textsOf(pageRegion());
    await openEdit(u, 'Contact 2');
    await u.click(inDialog().getByRole('checkbox', { name: 'Primary contact' }));
    await pressSave(u);
    await waitFor(() => expect(rowsOf(contactsSection()).map(([n, , , p]) => [n, p])).toEqual([['Contact 2 (served)', 'Yes'], ['Contact 1', 'No']]));
    expect(JSON.parse(of(calls, 'save')[0]!.body!)).toStrictEqual({ is_primary: true });
    expect(pageRegion().textContent).toBe(`${SAVED('Contact 2 (served)')} ${PRIMARY_NOW}`);
    expect(t.texts.filter((x) => x !== '')).toEqual([`${SAVED('Contact 2 (served)')} ${PRIMARY_NOW}`]);
    t.stop();
    expect(document.activeElement).toBe(rowEdit('Contact 2 (served)'));
  });

  it('test 2 — M-3: Edit changed to ticked, answered primary, the section re-read fails: the table is exactly the pre-write table', async () => {
    routes({ save: [savedAs({ ...C2, is_primary: true })], list: [json(500, {})] });
    const u = userEvent.setup();
    showEdit();
    const before = rowsOf(contactsSection());
    await openEdit(u, 'Contact 2');
    await u.click(inDialog().getByRole('checkbox', { name: 'Primary contact' }));
    await pressSave(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(`${SAVED('Contact 2')} ${PRIMARY_NOW} ${LIST_STALE}`));
    expect(rowsOf(contactsSection())).toEqual(before);
    expect(rowsOf(contactsSection()).filter(([, , , p]) => p === 'Yes')).toHaveLength(1);
  });

  // Test 3 (Edit half).
  it('test 3 — Edit sends no Idempotency-Key', async () => {
    const calls = routes({ save: [savedAs({ ...C2, phone: null })], list: [json(200, page([C1, C2]))] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u, 'Contact 2');
    await u.clear(field('Phone (optional)'));
    await pressSave(u);
    await waitFor(() => expect(of(calls, 'save')).toHaveLength(1));
    expect(of(calls, 'save')[0]!.headers.get('Idempotency-Key')).toBeNull();
  });

  // Test 4a (Edit half).
  it('test 4a — Edit → 404: Not saved + notFoundInline, Close only; closing re-reads the section (not the customer)', async () => {
    const calls = routes({ save: [problem(404, 'not-found')], list: [json(200, page([C1]))] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u, 'Contact 2');
    await u.clear(field('Phone (optional)'));
    await pressSave(u);
    await waitFor(() => expect(notSaved()).toEqual([[NOT_FOUND_INLINE]]));
    expect(inDialog().queryByRole('button', { name: 'Save' })).toBeNull();
    expect(inDialog().queryByRole('button', { name: 'Cancel' })).toBeNull();
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
    await waitFor(() => expect(rowsOf(contactsSection())).toHaveLength(1));
    expect(of(calls, 'customer')).toEqual([]);
  });

  it('test 4a — Edit 404, the section re-read lands after the dialog has gone: when it drops the row, focus goes to the heading (F-12)', async () => {
    const list = held();
    const calls = routes({ save: [problem(404, 'not-found')], list: [list.reply] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u, 'Contact 2');
    await u.clear(field('Phone (optional)'));
    await pressSave(u);
    await waitFor(() => expect(notSaved()).toEqual([[NOT_FOUND_INLINE]]));
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
    await new Promise((r) => setTimeout(r, 30));
    expect(document.activeElement).toBe(rowEdit('Contact 2'));
    await act(async () => list.release(json(200, page([C1]))));
    await waitFor(() => expect(rowsOf(contactsSection())).toHaveLength(1));
    expect(document.activeElement).toBe(contactsHeading());
  });

  // Test 8a.
  it('test 8a — each row\'s Edit is named "Edit {name}", unquoted, and reads Edit', () => {
    routes({});
    showEdit();
    expect(within(contactsSection()).getAllByRole('button').map((b) => [b.textContent, b.getAttribute('aria-label')])).toEqual([
      ['Edit', 'Edit Contact 1'],
      ['Edit', 'Edit Contact 2'],
    ]);
  });

  // Test 12.
  it('test 12 — Edit on the primary contact shows primaryClearHint; on another, primaryHint', async () => {
    routes({});
    const u = userEvent.setup();
    showEdit();
    await openEdit(u, 'Contact 1');
    expect(inDialog().getByRole('checkbox', { name: 'Primary contact' })).toHaveAccessibleDescription(PRIMARY_CLEAR_HINT);
    await u.click(inDialog().getByRole('button', { name: 'Cancel' }));
    await openEdit(u, 'Contact 2');
    expect(inDialog().getByRole('checkbox', { name: 'Primary contact' })).toHaveAccessibleDescription(PRIMARY_HINT);
  });

  // Test 14.
  it('test 14 — un-marking sends is_primary false and says "It\'s no longer the primary contact."', async () => {
    const calls = routes({ save: [savedAs({ ...C1, is_primary: false })], list: [json(200, page([{ ...C1, is_primary: false }, C2]))] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u, 'Contact 1');
    await u.click(inDialog().getByRole('checkbox', { name: 'Primary contact' }));
    await pressSave(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(`${SAVED('Contact 1')} ${PRIMARY_NO_LONGER}`));
    expect(JSON.parse(of(calls, 'save')[0]!.body!)).toStrictEqual({ is_primary: false });
  });

  it.each([
    ['a save that did not send is_primary, answered primary', false, true],
    ['a save that sent is_primary true, answered NOT primary (the answered-row check)', true, false],
  ] as const)('test 14 — no primary clause on %s', async (_, tick, answered) => {
    routes({ save: [savedAs({ ...C2, phone: null, is_primary: answered })], list: [json(200, page([C1, C2]))] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u, 'Contact 2');
    await u.clear(field('Phone (optional)'));
    if (tick) await u.click(inDialog().getByRole('checkbox', { name: 'Primary contact' }));
    await pressSave(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(SAVED('Contact 2')));
  });

  // Test 16a (Edit half).
  it('test 16a — an interrupted Save keeps Save, with interrupted(the name shown before the press); Close re-reads the section', async () => {
    const calls = routes({ save: [() => Promise.reject(new TypeError('network'))], list: [json(200, page([C1, C2]))] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u, 'Contact 2');
    await u.clear(field('Name'));
    await u.type(field('Name'), 'Renamed');
    await pressSave(u);
    await waitFor(() => expect(bannersOf("We couldn't reach the server.")).toEqual([[EDIT_INTERRUPTED('Contact 2')]]));
    expect(inDialog().getByRole('button', { name: 'Save' })).toBeInTheDocument();
    await u.click(inDialog().getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
  });

  // Test 17 (Save half).
  it('test 17 — a field 422 on Save: S-4 beside Email, focus there, no banner, no re-read', async () => {
    const calls = routes({ save: [problem(422, 'validation-failed', { errors: [{ pointer: '/email', code: 'not_an_email', message: S4 }] })] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u, 'Contact 2');
    await u.clear(field('Email (optional)'));
    await u.type(field('Email (optional)'), 'x');
    await pressSave(u);
    await waitFor(() => expect(field('Email (optional)')).toHaveAccessibleDescription(S4));
    await waitFor(() => expect(document.activeElement).toBe(field('Email (optional)')));
    expect(notSaved()).toEqual([]);
    expect(of(calls, 'customer')).toEqual([]);
    expect(of(calls, 'list')).toEqual([]);
  });

  // Test 18a (Save half).
  it('test 18a — Save: a 422 with no violations re-reads and shows REFUSED_WITHOUT_MESSAGE; a 403 the permission sentence, Save stays, no re-read', async () => {
    const calls = routes({ save: [problem(422, 'validation-failed'), problem(403, 'permission-denied')], customer: [json(200, LIVE)] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u, 'Contact 2');
    await u.clear(field('Phone (optional)'));
    await pressSave(u);
    await waitFor(() => expect(notSaved()).toEqual([[REFUSED_WITHOUT_MESSAGE]]));
    expect(of(calls, 'customer')).toHaveLength(1);
    await pressSave(u);
    await waitFor(() => expect(notSaved()).toEqual([[FORBIDDEN]]));
    expect(inDialog().getByRole('button', { name: 'Save' })).toBeInTheDocument();
    expect(of(calls, 'customer')).toHaveLength(1);
  });

  it('test 18a — M-4: a 200 whose body is not a whole contact → WENT_WRONG + interrupted, Save stays, nothing spoken', async () => {
    routes({ save: [json(200, { id: C2.id, email: null, phone: null, is_primary: false })] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u, 'Contact 2');
    await u.clear(field('Phone (optional)'));
    await pressSave(u);
    await waitFor(() => expect(bannersOf('Something went wrong on our side.')).toEqual([[EDIT_INTERRUPTED('Contact 2')]]));
    expect(inDialog().getByRole('button', { name: 'Save' })).toBeInTheDocument();
    expect(pageRegion().textContent).toBe('');
  });
});

describe('/customers/[id] — Edit contact: the R12 branch (C-4a chunk 2, test 13a Save column)', () => {
  const refuseSave = async (u: ReturnType<typeof userEvent.setup>) => {
    await openEdit(u, 'Contact 2');
    await u.clear(field('Phone (optional)'));
    await pressSave(u);
  };

  it('test 13a — Save: customer.is_archived + archived re-read: the interim sentence, Close only, S-2 nowhere; Close → ST-9, no Edit, router refreshed once', async () => {
    const calls = routes({ save: [refused('customer.is_archived', S2)], customer: [json(200, ARCHIVED), json(200, ARCHIVED)], list: [json(200, page([C1, C2]))] });
    const u = userEvent.setup();
    showEdit();
    await refuseSave(u);
    await waitFor(() => expect(notSaved()).toEqual([[EDIT_ARCHIVED]]));
    expect(document.body.textContent).not.toContain(S2);
    expect(inDialog().queryByRole('button', { name: 'Save' })).toBeNull();
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(document.activeElement).toBe(screen.getByRole('heading', { level: 1 }));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(contactsSection()).queryByRole('button', { name: /^Edit/ })).toBeNull();
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('test 13a — Save: customer.is_archived + live re-read: K-16\'s words, the edits and Save stay; Cancel → the page re-reads the customer', async () => {
    const calls = routes({ save: [refused('customer.is_archived', S2)], customer: [json(200, LIVE), json(200, LIVE)], list: [json(200, page([C1, C2]))] });
    const u = userEvent.setup();
    showEdit();
    await refuseSave(u);
    await waitFor(() => expect(notSaved()).toEqual([[EDIT_THEN_RESTORED]]));
    expect(document.body.textContent).not.toContain(S2);
    expect(document.body.textContent).not.toContain("We couldn't check");
    expect(field('Phone (optional)')).toHaveValue('');
    expect(inDialog().getByRole('button', { name: 'Save' })).toBeInTheDocument();
    await u.click(inDialog().getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(2));
    expect(nav.refresh).not.toHaveBeenCalled();
  });

  it('test 13a — Save: customer.is_archived + failed re-read: archivedAtSave, edits and Save stay; Escape → the page re-reads (archived now): ST-9, no Edit', async () => {
    const calls = routes({ save: [refused('customer.is_archived', S2)], customer: [json(500, {}), json(200, ARCHIVED)], list: [json(200, page([C1, C2]))] });
    const u = userEvent.setup();
    showEdit();
    await refuseSave(u);
    await waitFor(() => expect(notSaved()).toEqual([[EDIT_AT_SAVE]]));
    expect(document.body.textContent).not.toContain('It has since been restored');
    expect(field('Phone (optional)')).toHaveValue('');
    expect(inDialog().getByRole('button', { name: 'Save' })).toBeInTheDocument();
    await u.keyboard('{Escape}');
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(contactsSection()).queryByRole('button', { name: /^Edit/ })).toBeNull();
  });

  it('test 13a — Save: S-16 + archived re-read: the interim sentence, Close only; Close → ST-9, no Edit', async () => {
    const calls = routes({ save: [refused('transaction.contention', P1)], customer: [json(200, ARCHIVED), json(200, ARCHIVED)], list: [json(200, page([C1, C2]))] });
    const u = userEvent.setup();
    showEdit();
    await refuseSave(u);
    await waitFor(() => expect(notSaved()).toEqual([[EDIT_ARCHIVED]]));
    expect(document.body.textContent).not.toContain(P1);
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(contactsSection()).queryByRole('button', { name: /^Edit/ })).toBeNull();
  });

  it('test 13a — Save: S-16 with a live re-read is shown as sent and Save stays', async () => {
    routes({ save: [refused('transaction.contention', P1)], customer: [json(200, LIVE)] });
    const u = userEvent.setup();
    showEdit();
    await refuseSave(u);
    await waitFor(() => expect(notSaved()).toEqual([[P1]]));
    expect(inDialog().getByRole('button', { name: 'Save' })).toBeInTheDocument();
  });

  it('test 18a — Save: S-16 with a failed re-read is shown as sent and Save stays', async () => {
    const calls = routes({ save: [refused('transaction.contention', P1)], customer: [json(500, {})] });
    const u = userEvent.setup();
    showEdit();
    await refuseSave(u);
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(1));
    await waitFor(() => expect(notSaved()).toEqual([[P1]]));
    expect(inDialog().getByRole('button', { name: 'Save' })).toBeInTheDocument();
  });

  it('test 13a — M-3 (final RED): Save: customer.is_archived + live re-read, Save again → 200: the dialog closes and the page\'s GetCustomer is requested once', async () => {
    const calls = routes({ save: [refused('customer.is_archived', S2), savedAs({ ...C2, phone: null })], customer: [json(200, LIVE), json(200, LIVE)], list: [json(200, page([C1, C2]))] });
    const u = userEvent.setup();
    showEdit();
    await refuseSave(u);
    await waitFor(() => expect(notSaved()).toEqual([[EDIT_THEN_RESTORED]]));
    expect(of(calls, 'customer')).toHaveLength(1);
    await pressSave(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(2));
  });
});

describe('/customers/[id] — who sees Edit, and Edit\'s late corrections (C-4a chunk 2)', () => {
  it('test 7a — canEditContact: an Actions column with Edit per row; archived with both props: no Edit, no Actions column', () => {
    routes({});
    const r = show({ canEditContact: true });
    expect(within(contactsSection()).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Name', 'Email', 'Phone', 'Primary', 'Actions']);
    expect(within(contactsSection()).getAllByRole('button', { name: /^Edit / })).toHaveLength(2);
    r.unmount();
    show({ customer: ARCHIVED, canEditContact: true });
    expect(within(contactsSection()).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Name', 'Email', 'Phone', 'Primary']);
    expect(within(contactsSection()).queryAllByRole('button')).toEqual([]);
  });

  it('test 7a — M-4 (L-5 order): an open Edit dialog survives the page turning archived; its edits stay and focus stays inside it', async () => {
    const save = held();
    routes({ customerSave: [save.reply], customer: [json(200, ARCHIVED)] });
    const u = userEvent.setup();
    show({ canEdit: true, canAdd: false, canEditContact: true });
    const code = screen.getByRole('textbox', { name: 'Code' });
    await u.clear(code);
    await u.type(code, 'NORTH2');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openEdit(u, 'Contact 2');
    await u.clear(field('Phone (optional)'));
    await act(async () => save.release(refused('customer.is_archived', "This customer is archived, so it can't be changed until it's restored.")));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(field('Phone (optional)')).toHaveValue('');
    expect(dialog().contains(document.activeElement)).toBe(true);
  });

  it('test 6a — a failed section re-read after Edit replaces the sentence with its stale form', async () => {
    routes({ save: [savedAs({ ...C2, phone: null })], list: [json(503, {})] });
    const u = userEvent.setup();
    showEdit();
    await openEdit(u, 'Contact 2');
    await u.clear(field('Phone (optional)'));
    await pressSave(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(`${SAVED('Contact 2')} ${LIST_STALE}`));
  });

  it('test 6a — K9-1 for Edit: the Addresses Retry writes the region between the Save and its failed re-read: the stale form is never written', async () => {
    const reread = held();
    routes({ save: [savedAs({ ...C2, phone: null })], list: [reread.reply], addresses: [json(200, page([]))] });
    const u = userEvent.setup();
    showEdit({ addresses: { kind: 'server-error' } });
    const t = textsOf(pageRegion());
    await openEdit(u, 'Contact 2');
    await u.clear(field('Phone (optional)'));
    await pressSave(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(SAVED('Contact 2')));
    await u.click(within(screen.getByRole('region', { name: 'Addresses' })).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(pageRegion().textContent).toBe('No addresses yet.'));
    await act(async () => reread.release(json(500, {})));
    await new Promise((r) => setTimeout(r, 20));
    expect(t.texts).not.toContain(`${SAVED('Contact 2')} ${LIST_STALE}`);
    t.stop();
  });

  it('test 6a — M-2 (final RED): the same sentence written twice: only the LATEST write\'s failed re-read may write the stale form', async () => {
    const a = held();
    const b = held();
    routes({ save: [savedAs({ ...C2, phone: null }), savedAs({ ...C2, phone: '+1' })], list: [a.reply, b.reply] });
    const u = userEvent.setup();
    showEdit();
    const t = textsOf(pageRegion());
    await openEdit(u, 'Contact 2');
    await u.clear(field('Phone (optional)'));
    await pressSave(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(SAVED('Contact 2')));
    await openEdit(u, 'Contact 2');
    await u.type(field('Phone (optional)'), '9');
    await pressSave(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(pageRegion().textContent).toBe(SAVED('Contact 2')));
    await act(async () => a.release(json(500, {})));
    await new Promise((r) => setTimeout(r, 20));
    expect(t.texts).not.toContain(`${SAVED('Contact 2')} ${LIST_STALE}`);
    await act(async () => b.release(json(500, {})));
    await waitFor(() => expect(pageRegion().textContent).toBe(`${SAVED('Contact 2')} ${LIST_STALE}`));
    expect(t.texts.filter((x) => x === `${SAVED('Contact 2')} ${LIST_STALE}`)).toHaveLength(1);
    t.stop();
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// Customers C-4b (customers-plan-c4c5.md Revision 1 R1-3 as fixed by customers-plan-c4b-c5-final-red.md M-2, M-3, L-1,
// L-3…L-6, L-11; copy customers-c4c5-ux-copy.md Revision 1a > Revision 1 > Revision 0 §2.5): Remove contact, through the
// shared Delete dialog. The same rules as above: exact full texts, `within(dialog())`, the writer's constant byte for byte.
// ---------------------------------------------------------------------------------------------------------------------

/** S-3, `CustomerContactWriter::ARCHIVED_ON_DELETE` (`CustomerContactWriter.php:100`). Never rendered in C-4b (copy D-1). */
const S3 = "This customer is archived, so its contacts can't be removed until it's restored.";
const REMOVE_TITLE = (name: string) => `Remove “${name}” from this customer's contacts?`;
const REMOVE_BODY = "The contact is deleted for good, not archived, so it can't be restored.";
const REMOVE_BODY_PRIMARY = "The contact is deleted for good, not archived, so it can't be restored. It's the primary contact, so this customer will have no primary contact until another one is marked.";
const REMOVED = (name: string) => `“${name}” was removed from the contacts.`;
const REMOVE_INTERRUPTED = (name: string) => `We can't tell whether “${name}” was removed. Close this and check the contacts.`;
const REMOVE_ARCHIVED = "This customer is archived, so its contacts can't be removed.";
const REMOVE_THEN_RESTORED = 'Nothing was removed, because this customer was archived at that moment. It has since been restored, so you can close this and remove the contact.';
const REMOVE_AT_REMOVE = "Nothing was removed, because this customer was archived at that moment. We couldn't check whether it still is.";
const R1 = contact(1, { is_primary: true });
const R2 = contact(2);
const notRemoved = () => bannersOf('Not removed');
const rowRemove = (name: string) => within(contactsSection()).getByRole('button', { name: `Remove ${name}` });
const openRemove = async (u: ReturnType<typeof userEvent.setup>, name: string) => {
  await u.click(rowRemove(name));
  return dialog();
};
const pressRemove = (u: ReturnType<typeof userEvent.setup>) => u.click(inDialog().getByRole('button', { name: 'Remove' }));
const showRemove = (over: Parameters<typeof show>[0] = {}) => show({ canAdd: false, canRemoveContact: true, contacts: okContacts([R1, R2]), ...over });
/** Close only: Close is there, and neither Remove nor Cancel is. */
const closeOnly = () => {
  expect(inDialog().getByRole('button', { name: 'Close' })).toBeInTheDocument();
  expect(inDialog().queryByRole('button', { name: 'Remove' })).toBeNull();
  expect(inDialog().queryByRole('button', { name: 'Cancel' })).toBeNull();
};

describe('/customers/[id] — Remove contact (C-4b)', () => {
  it('test 3b / 15 — the Remove request: DELETE on the contact, no body, no Idempotency-Key; the dialog names the contact and its body', async () => {
    const calls = routes({ remove: [new Response(null, { status: 204 })], list: [json(200, page([R1]))] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u, 'Contact 2');
    expect(inDialog().getByRole('heading', { name: REMOVE_TITLE('Contact 2') })).toBeInTheDocument();
    expect(dialog()).toHaveAccessibleDescription(REMOVE_BODY);
    await pressRemove(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    const removes = of(calls, 'remove');
    expect(removes).toHaveLength(1);
    expect(removes[0]!.url).toMatch(new RegExp(`/bff/api/customers/${CID}/contacts/${R2.id}$`));
    expect(removes[0]!.body).toBeNull();
    expect(removes[0]!.headers.get('idempotency-key')).toBeNull();
  });

  it('test 15 — T-C10: the primary contact\'s Remove body says the customer will have no primary contact', async () => {
    routes({});
    const u = userEvent.setup();
    showRemove();
    await openRemove(u, 'Contact 1');
    expect(inDialog().getByRole('heading', { name: REMOVE_TITLE('Contact 1') })).toBeInTheDocument();
    expect(dialog()).toHaveAccessibleDescription(REMOVE_BODY_PRIMARY);
  });

  it('test 5 — M-3: after a 204 the row is dropped at once, after(name) is spoken and focus is on the heading, before the held re-read; a failed re-read then gives afterListStale and the row stays gone', async () => {
    const list = held();
    const calls = routes({ remove: [new Response(null, { status: 204 })], list: [list.reply] });
    const u = userEvent.setup();
    showRemove();
    // Commit RED M-1: where focus is at the moment the sentence is written (FE-D1), not after the Dialog's close timer.
    let atSpeak: Element | null = null;
    const obs = new MutationObserver(() => {
      if (atSpeak === null && pageRegion().textContent === REMOVED('Contact 2')) atSpeak = document.activeElement;
    });
    obs.observe(pageRegion(), { childList: true, characterData: true, subtree: true });
    // Commit RED-2 N-1 (added by C-5a, the next commit touching this file): the region's text at the moment the heading takes
    // focus, so a speak-then-focus inversion flushed in one task (mutant X1) goes red too.
    let textAtFocus: string | null = null;
    contactsHeading().addEventListener('focus', () => {
      if (textAtFocus === null) textAtFocus = pageRegion().textContent;
    });
    await openRemove(u, 'Contact 2');
    await pressRemove(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
    await new Promise((r) => setTimeout(r, 30));
    expect(rowsOf(contactsSection()).map((r) => r[0])).toEqual(['Contact 1']);
    expect(pageRegion().textContent).toBe(REMOVED('Contact 2'));
    expect(document.activeElement).toBe(contactsHeading());
    obs.disconnect();
    expect(atSpeak, 'focus is on the heading when the sentence is written (FE-D1)').toBe(contactsHeading());
    expect(textAtFocus, 'the sentence is not yet written when the heading takes focus (FE-D1 order)').not.toBe(REMOVED('Contact 2'));
    expect(textAtFocus).not.toBeNull();
    await act(async () => list.release(json(500, {})));
    await waitFor(() => expect(pageRegion().textContent).toBe(`${REMOVED('Contact 2')} ${LIST_STALE}`));
    expect(rowsOf(contactsSection()).map((r) => r[0])).toEqual(['Contact 1']);
  });

  it('test 5 — L-2 (commit RED): removing the primary contact promotes no other row: the remaining row reads No before the held re-read answers, and after it fails', async () => {
    const list = held();
    const calls = routes({ remove: [new Response(null, { status: 204 })], list: [list.reply] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u, 'Contact 1');
    await pressRemove(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
    expect(rowsOf(contactsSection()).map((r) => [r[0], r[3]])).toEqual([['Contact 2', 'No']]);
    await act(async () => list.release(json(500, {})));
    await waitFor(() => expect(pageRegion().textContent).toBe(`${REMOVED('Contact 1')} ${LIST_STALE}`));
    expect(rowsOf(contactsSection()).map((r) => [r[0], r[3]])).toEqual([['Contact 2', 'No']]);
  });

  it('test 5 — L-3: two contacts of one name removed in turn: the region is emptied between the two identical sentences, so the second is spoken', async () => {
    const A1 = contact(1, { name: 'Ann' });
    const A2 = contact(2, { name: 'Ann' });
    routes({ remove: [new Response(null, { status: 204 }), new Response(null, { status: 204 })], list: [json(200, page([A2])), json(200, page([]))] });
    const u = userEvent.setup();
    showRemove({ contacts: okContacts([A1, A2]) });
    const t = textsOf(pageRegion());
    await u.click(within(contactsSection()).getAllByRole('button', { name: 'Remove Ann' })[0]!);
    await pressRemove(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(rowsOf(contactsSection())).toHaveLength(1));
    await u.click(rowRemove('Ann'));
    await pressRemove(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(t.texts).toEqual(['', REMOVED('Ann'), '', REMOVED('Ann')]));
    t.stop();
  });

  it('test 4b — L-4: Remove → 404: Not removed + notFoundInline, Close only, no customer re-read; after Close focus is on the row\'s Remove, and when the held section re-read drops the row, on the heading', async () => {
    const list = held();
    const calls = routes({ remove: [problem(404, 'not-found')], list: [list.reply] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u, 'Contact 2');
    await pressRemove(u);
    await waitFor(() => expect(notRemoved()).toEqual([[NOT_FOUND_INLINE]]));
    closeOnly();
    expect(of(calls, 'customer')).toEqual([]);
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
    await new Promise((r) => setTimeout(r, 30));
    expect(document.activeElement).toBe(rowRemove('Contact 2'));
    await act(async () => list.release(json(200, page([R1]))));
    await waitFor(() => expect(rowsOf(contactsSection())).toHaveLength(1));
    expect(document.activeElement).toBe(contactsHeading());
    expect(of(calls, 'customer')).toEqual([]);
  });

  it('test 16b — an interrupted Remove: WENT_WRONG + interrupted(name), Close only; Close re-reads the section', async () => {
    const calls = routes({ remove: [json(500, {})], list: [json(200, page([R1, R2]))] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u, 'Contact 2');
    await pressRemove(u);
    await waitFor(() => expect(bannersOf('Something went wrong on our side.')).toEqual([[REMOVE_INTERRUPTED('Contact 2')]]));
    closeOnly();
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
    expect(of(calls, 'customer')).toEqual([]);
  });

  it('test 8b — each row\'s Remove is named "Remove {name}", unquoted, and reads Remove', () => {
    routes({});
    showRemove();
    expect(rowRemove('Contact 1').textContent).toBe('Remove');
    expect(rowRemove('Contact 2').textContent).toBe('Remove');
  });

  it('test 18b — S-16 with a live re-read is shown as sent and Remove stays; with a failed re-read too', async () => {
    for (const again of [json(200, LIVE), json(500, {})]) {
      const calls = routes({ remove: [refused('transaction.contention', P1)], customer: [again] });
      const u = userEvent.setup();
      const v = showRemove();
      await openRemove(u, 'Contact 2');
      await pressRemove(u);
      await waitFor(() => expect(notRemoved()).toEqual([[P1]]));
      expect(inDialog().getByRole('button', { name: 'Remove' })).toBeInTheDocument();
      expect(inDialog().getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
      expect(of(calls, 'customer')).toHaveLength(1);
      v.unmount();
    }
  });

  it('test 18b — M-2: a 403 on Remove: Not removed + the permission sentence (its label in <em>), Remove stays, no customer re-read', async () => {
    const calls = routes({ remove: [problem(403, 'permission-denied')] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u, 'Contact 2');
    await pressRemove(u);
    await waitFor(() => expect(notRemoved()).toEqual([[FORBIDDEN]]));
    expect(inDialog().getByText('Edit customers, their addresses and contacts', { selector: 'em' })).toBeInTheDocument();
    expect(inDialog().getByRole('button', { name: 'Remove' })).toBeInTheDocument();
    expect(inDialog().getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    await new Promise((r) => setTimeout(r, 20));
    expect(of(calls, 'customer')).toEqual([]);
  });
});

describe('/customers/[id] — Remove contact: the R12 branch (C-4b, test 13b)', () => {
  it('test 13b — customer.is_archived + archived re-read: the interim sentence, Close only, S-3 nowhere; Close → the page re-reads: ST-9 banner, no Remove, router refreshed once', async () => {
    const calls = routes({ remove: [refused('customer.is_archived', S3)], customer: [json(200, ARCHIVED), json(200, ARCHIVED)], list: [json(200, page([R1, R2]))] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u, 'Contact 2');
    await pressRemove(u);
    await waitFor(() => expect(notRemoved()).toEqual([[REMOVE_ARCHIVED]]));
    expect(document.body.textContent).not.toContain(S3);
    expect(document.body.textContent).not.toContain("until it's restored");
    closeOnly();
    expect(of(calls, 'customer')).toHaveLength(1);
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(contactsSection()).queryAllByRole('button', { name: /^Remove/ })).toEqual([]);
    expect(within(contactsSection()).getByText(ARCHIVED_PARENT, { selector: 'p' })).toBeInTheDocument();
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('test 13b — customer.is_archived + live re-read: archivedThenRestored, Close only (L-1), S-3 nowhere; Close → the page re-reads the customer', async () => {
    const calls = routes({ remove: [refused('customer.is_archived', S3)], customer: [json(200, LIVE), json(200, LIVE)], list: [json(200, page([R1, R2]))] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u, 'Contact 2');
    await pressRemove(u);
    await waitFor(() => expect(notRemoved()).toEqual([[REMOVE_THEN_RESTORED]]));
    expect(document.body.textContent).not.toContain(S3);
    closeOnly();
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(2));
    expect(nav.refresh).not.toHaveBeenCalled();
  });

  it('test 13b — customer.is_archived + failed re-read: archivedAtRemove, Close only (L-1); Escape → the page re-reads (archived now): ST-9 banner, no Remove', async () => {
    const calls = routes({ remove: [refused('customer.is_archived', S3)], customer: [json(500, {}), json(200, ARCHIVED)], list: [json(200, page([R1, R2]))] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u, 'Contact 2');
    await pressRemove(u);
    await waitFor(() => expect(notRemoved()).toEqual([[REMOVE_AT_REMOVE]]));
    expect(document.body.textContent).not.toContain(S3);
    closeOnly();
    await u.keyboard('{Escape}');
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(contactsSection()).queryAllByRole('button', { name: /^Remove/ })).toEqual([]);
  });

  it('test 13b — M-1: S-16 + archived re-read: the interim sentence (never "try again"), Close only; Close → ST-9 banner, no Remove', async () => {
    const calls = routes({ remove: [refused('transaction.contention', P1)], customer: [json(200, ARCHIVED), json(200, ARCHIVED)], list: [json(200, page([R1, R2]))] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u, 'Contact 2');
    await pressRemove(u);
    await waitFor(() => expect(notRemoved()).toEqual([[REMOVE_ARCHIVED]]));
    expect(document.body.textContent).not.toContain(P1);
    closeOnly();
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(within(contactsSection()).queryAllByRole('button', { name: /^Remove/ })).toEqual([]);
  });

  it('test 13b — L-11: after a customer.is_archived + live re-read Remove is closed (one page re-read), a later Remove dialog\'s Cancel re-reads nothing', async () => {
    const calls = routes({ remove: [refused('customer.is_archived', S3)], customer: [json(200, LIVE), json(200, LIVE), json(200, LIVE)], list: [json(200, page([R1, R2]))] });
    const u = userEvent.setup();
    showRemove();
    await openRemove(u, 'Contact 2');
    await pressRemove(u);
    await waitFor(() => expect(notRemoved()).toEqual([[REMOVE_THEN_RESTORED]]));
    await u.click(inDialog().getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(of(calls, 'customer')).toHaveLength(2));
    await waitFor(() => expect(of(calls, 'list')).toHaveLength(1));
    await openRemove(u, 'Contact 1');
    await u.click(inDialog().getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await new Promise((r) => setTimeout(r, 30));
    expect(of(calls, 'customer')).toHaveLength(2);
    expect(of(calls, 'list')).toHaveLength(1);
  });
});

describe('/customers/[id] — who sees Remove, and Remove\'s late corrections (C-4b)', () => {
  it('test 7b — canRemoveContact: an Actions column with Remove per row; archived with the prop: no Remove, no Actions column', () => {
    routes({});
    const v = showRemove();
    expect(within(contactsSection()).getByRole('columnheader', { name: 'Actions' })).toBeInTheDocument();
    expect(rowRemove('Contact 1')).toBeInTheDocument();
    v.unmount();
    showRemove({ customer: ARCHIVED });
    expect(within(contactsSection()).queryAllByRole('button', { name: /^Remove/ })).toEqual([]);
    expect(within(contactsSection()).queryByRole('columnheader', { name: 'Actions' })).toBeNull();
  });

  it('test 7b — L-5: canRemoveContact alone: the Actions column with Remove and no Edit', () => {
    routes({});
    showRemove();
    expect(within(contactsSection()).getByRole('columnheader', { name: 'Actions' })).toBeInTheDocument();
    expect(rowRemove('Contact 2')).toBeInTheDocument();
    expect(within(contactsSection()).queryAllByRole('button', { name: /^Edit/ })).toEqual([]);
  });

  it('test 7b — M-4 (L-5 order): an open Remove dialog survives the page turning archived; focus stays inside it', async () => {
    const save = held();
    routes({ customerSave: [save.reply], customer: [json(200, ARCHIVED)] });
    const u = userEvent.setup();
    showRemove({ canEdit: true });
    const code = screen.getByRole('textbox', { name: 'Code' });
    await u.clear(code);
    await u.type(code, 'NORTH2');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openRemove(u, 'Contact 2');
    await act(async () => save.release(refused('customer.is_archived', "This customer is archived, so it can't be changed until it's restored.")));
    await waitFor(() => expect(archivedBanner().textContent).toBe(ARCHIVED_BANNER()));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(inDialog().getByRole('heading', { name: REMOVE_TITLE('Contact 2') })).toBeInTheDocument();
    expect(dialog().contains(document.activeElement)).toBe(true);
  });

  // C-5b commit RED M-1, the Contacts twin (a shipped C-4b guard left unpinned): the F-6 fresh stamp in closeRemove.
  it('test 19b — F-6: an Add re-read sent before Remove and answered after its 204 cannot bring the removed row back', async () => {
    const addRead = held();
    const removeRead = held();
    routes({ add: [created()], list: [addRead.reply, removeRead.reply], remove: [new Response(null, { status: 204 })] });
    const u = userEvent.setup();
    show({ canRemoveContact: true, contacts: okContacts([R1, R2]) });
    await openAdd(u);
    await typeName(u, 'Ann Lee');
    await submitAdd(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await openRemove(u, 'Contact 2');
    await pressRemove(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(rowsOf(contactsSection()).map((r) => r[0])).not.toContain('Contact 2'));
    await act(async () => addRead.release(json(200, page([R1, R2, contact(9, { name: 'Ann Lee', email: null, phone: null })]))));
    await new Promise((r) => setTimeout(r, 20));
    expect(rowsOf(contactsSection()).map((r) => r[0])).not.toContain('Contact 2');
    await act(async () => removeRead.release(json(500, {})));
    await new Promise((r) => setTimeout(r, 20));
    expect(rowsOf(contactsSection()).map((r) => r[0])).not.toContain('Contact 2');
  });

  it('test 6b — L-6 positive half: a failed section re-read after Remove replaces after(name) with afterListStale once', async () => {
    const reread = held();
    routes({ remove: [new Response(null, { status: 204 })], list: [reread.reply] });
    const u = userEvent.setup();
    showRemove();
    const t = textsOf(pageRegion());
    await openRemove(u, 'Contact 2');
    await pressRemove(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(REMOVED('Contact 2')));
    await act(async () => reread.release(json(503, {})));
    await waitFor(() => expect(pageRegion().textContent).toBe(`${REMOVED('Contact 2')} ${LIST_STALE}`));
    expect(t.texts.filter((x) => x !== '')).toEqual([REMOVED('Contact 2'), `${REMOVED('Contact 2')} ${LIST_STALE}`]);
    t.stop();
  });

  it('test 6b — L-6 K9-1 for Remove: the Addresses Retry writes the region between the 204 and its failed re-read: afterListStale is never written', async () => {
    const reread = held();
    routes({ remove: [new Response(null, { status: 204 })], list: [reread.reply], addresses: [json(200, page([]))] });
    const u = userEvent.setup();
    showRemove({ addresses: { kind: 'server-error' } });
    const t = textsOf(pageRegion());
    await openRemove(u, 'Contact 2');
    await pressRemove(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(REMOVED('Contact 2')));
    const addresses = screen.getByRole('region', { name: 'Addresses' });
    await u.click(within(addresses).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(pageRegion().textContent).toBe('No addresses yet.'));
    await act(async () => reread.release(json(500, {})));
    await new Promise((r) => setTimeout(r, 20));
    expect(t.texts).not.toContain(`${REMOVED('Contact 2')} ${LIST_STALE}`);
    expect(pageRegion().textContent).toBe('No addresses yet.');
    t.stop();
  });
});
