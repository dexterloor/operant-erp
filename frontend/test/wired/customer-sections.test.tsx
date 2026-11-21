import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), refresh: vi.fn() }), usePathname: () => '/customers/x' }));
import { CustomerPage } from '@/app/(app)/customers/[id]/CustomerPage';
import type { Customer, CustomerAddress, CustomerContact } from '@/lib/api/customers';
import type { AddressesLoad, ContactsLoad } from '@/lib/customers/load';
import { json, page, problem, renderWired, stubFetch, textsOf } from './harness';

// Customers C-1 (customers-plan.md Revision 1 — FINAL R1-5 C-1 row, R1-6 C-1 tests 7 and 8, Revision 0 §5, §5.1, §5.2;
// `customers-c1-ux-copy.md` Revision 1 — FINAL §2, §3, R1-4, R1-5, R1-9): the read-only Contacts and Addresses sections on
// the customer page, on the S-3 catalogue section's shape (`supplier-catalogue.test.tsx`). Each is a `<section>` named by
// its h2, a table over a bounded whole set with no action of any kind, its own region error and Retry, and the count spoken
// through the page's one region only on the first successful load after a failed one. Contacts come first, then Addresses.

afterEach(() => {
  vi.unstubAllGlobals();
});

const CID = 'd1b2c3d4-0000-4000-8000-000000000001';
const LIVE: Customer = { id: CID, code: 'NORTH', name: 'Northwind Retail', country_code: 'GB', currency_code: 'GBP', payment_terms_days: 30, archived_at: null };
const contact = (n: number, over: Partial<CustomerContact> = {}): CustomerContact => ({ id: `c1000000-0000-4000-8000-00000000000${n}`, name: `Contact ${n}`, email: `c${n}@example.com`, phone: `+44 20 7946 000${n}`, is_primary: false, ...over });
const address = (n: number, kind: string, line: string): CustomerAddress => ({ id: `a1000000-0000-4000-8000-00000000000${n}`, kind, line, city: `City ${n}`, postal_code: `PC${n}`, country_code: 'GB' });
const okContacts = (contacts: CustomerContact[]): ContactsLoad => ({ kind: 'ok', contacts });
const okAddresses = (addresses: CustomerAddress[]): AddressesLoad => ({ kind: 'ok', addresses });

const show = (contacts: ContactsLoad | null = okContacts([]), addresses: AddressesLoad | null = okAddresses([]), customer: Customer = LIVE) =>
  renderWired(<CustomerPage customer={customer} contacts={contacts} addresses={addresses} />);
const pageRegion = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const contactsSection = () => screen.getByRole('region', { name: 'Contacts' });
const addressesSection = () => screen.getByRole('region', { name: 'Addresses' });
const rowsOf = (s: HTMLElement) => within(s).queryAllByRole('row').slice(1).map((r) => within(r).getAllByRole('cell').map((c) => c.textContent));

const CONTACTS_FAILED = "The contacts couldn't be loaded.";
const ADDRESSES_FAILED = "The addresses couldn't be loaded.";

describe('/customers/[id] — the read-only Contacts section (C-1)', () => {
  it('a section named by its h2 "Contacts"; columns Name · Email · Phone · Primary; served order; no button, no form', () => {
    // Served order (primary first, then oldest first — §15.1), kept as served.
    show(okContacts([contact(2, { is_primary: true }), contact(1), contact(3)]));
    const s = contactsSection();
    expect(s.tagName).toBe('SECTION');
    expect(within(s).getByRole('heading', { level: 2, name: 'Contacts' })).toBeInTheDocument();
    expect(within(s).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Name', 'Email', 'Phone', 'Primary']);
    expect(rowsOf(s)).toEqual([
      ['Contact 2', 'c2@example.com', '+44 20 7946 0002', 'Yes'],
      ['Contact 1', 'c1@example.com', '+44 20 7946 0001', 'No'],
      ['Contact 3', 'c3@example.com', '+44 20 7946 0003', 'No'],
    ]);
    expect(within(s).queryAllByRole('button')).toEqual([]);
    expect(document.querySelector('form, input, select, textarea')).toBeNull();
  });

  it('a null email or phone reads "None"; with no primary contact every Primary cell reads "No"', () => {
    show(okContacts([contact(1, { email: null }), contact(2, { phone: null })]));
    expect(rowsOf(contactsSection())).toEqual([
      ['Contact 1', 'None', '+44 20 7946 0001', 'No'],
      ['Contact 2', 'c2@example.com', 'None', 'No'],
    ]);
  });

  it('empty: "This customer has no contacts." and nothing offered; a 403: the denied sentence; first paint speaks nothing', () => {
    const { unmount } = show(okContacts([]));
    expect(within(contactsSection()).getByText('This customer has no contacts.', { selector: 'p' })).toBeInTheDocument();
    expect(within(contactsSection()).queryAllByRole('button')).toEqual([]);
    expect(within(contactsSection()).queryByRole('table')).toBeNull();
    expect(pageRegion().textContent).toBe('');
    unmount();
    show({ kind: 'forbidden' });
    expect(within(contactsSection()).getByText("You don't have access to this customer's contacts.", { selector: 'p' })).toBeInTheDocument();
  });

  it('absent when the page is handed no contacts read (the viewer lacks its codes, A-20)', () => {
    show(null, okAddresses([]));
    expect(screen.queryByRole('region', { name: 'Contacts' })).toBeNull();
    expect(screen.queryByRole('heading', { name: 'Contacts' })).toBeNull();
  });

  it('an archived customer: the rows are listed as usual, with no archived-parent note and no action (copy R1-4, R1-5 L-3)', () => {
    show(okContacts([contact(1, { is_primary: true })]), okAddresses([address(1, 'shipping', '1 High St')]), { ...LIVE, archived_at: '2026-03-04T11:00:00Z' });
    expect(rowsOf(contactsSection())).toHaveLength(1);
    expect(rowsOf(addressesSection())).toHaveLength(1);
    expect(contactsSection().textContent).not.toMatch(/archived|restore|can't be changed/i);
    expect(addressesSection().textContent).not.toMatch(/archived|restore|can't be changed/i);
    expect(screen.queryAllByRole('button')).toEqual([]);
  });

  // Test 7, the contacts half (customers-c1-commit-red.md H-2): driven through the REAL `loadContacts` over the browser
  // transport, as the addresses half is. The served order (primary first, then oldest first) is not the id order, so a
  // loader that re-sorts or reverses the rows goes red here.
  it('test 7 — a failed first read → its region error; Retry → 200 with 3 → rows in SERVED order, "3 contacts for this customer.", focus on the heading', async () => {
    const calls = stubFetch([json(200, page([contact(3, { is_primary: true }), contact(1), contact(2)]))]);
    const u = userEvent.setup();
    show({ kind: 'server-error' });
    expect(within(contactsSection()).getByText(CONTACTS_FAILED)).toBeInTheDocument();
    const t = textsOf(pageRegion());
    await u.click(within(contactsSection()).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(rowsOf(contactsSection())).toHaveLength(3));
    expect(rowsOf(contactsSection()).map(([n]) => n)).toEqual(['Contact 3', 'Contact 1', 'Contact 2']);
    expect(pageRegion().textContent).toBe('3 contacts for this customer.');
    expect(t.texts.filter((x) => x !== '')).toEqual(['3 contacts for this customer.']);
    t.stop();
    expect(calls).toHaveLength(1);
    expect(calls[0]!.method).toBe('GET');
    expect(calls[0]!.url).toMatch(new RegExp(`/bff/api/customers/${CID}/contacts$`));
    expect(document.activeElement).toBe(within(contactsSection()).getByRole('heading', { level: 2, name: 'Contacts' }));
  });

  it.each([
    ['1 contact', [contact(1)], '1 contact for this customer.'],
    ['none, though the workspace has contacts (tenant_has_rows ignored)', [], 'This customer has no contacts.'],
  ] as const)('Retry → %s: the page region says "%s"', async (_, items, said) => {
    stubFetch([json(200, page([...items], { tenant_has_rows: true }))]);
    const u = userEvent.setup();
    show({ kind: 'unreachable' });
    await u.click(within(contactsSection()).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(pageRegion().textContent).toBe(said));
  });

  it('Retry → 404: the same region error and Retry, no not-found sentence (copy R1-5 L-3)', async () => {
    stubFetch([problem(404, 'not-found')]);
    const u = userEvent.setup();
    show({ kind: 'server-error' });
    // The region error is keyed by the section's load number, so the answer's commit replaces the node: wait for that.
    const before = within(contactsSection()).getByText(CONTACTS_FAILED);
    await u.click(within(contactsSection()).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(within(contactsSection()).getByText(CONTACTS_FAILED)).not.toBe(before));
    expect(within(contactsSection()).queryByRole('table')).toBeNull();
  });
});

describe('/customers/[id] — the read-only Addresses section (C-1)', () => {
  it('a section named by its h2 "Addresses", after Contacts; columns Kind · Address · City · Postal code · Country; no button', () => {
    show(okContacts([]), okAddresses([address(1, 'shipping', '1 High St')]));
    const s = addressesSection();
    expect(s.tagName).toBe('SECTION');
    expect(within(s).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Kind', 'Address', 'City', 'Postal code', 'Country']);
    expect(rowsOf(s)).toEqual([['Shipping', '1 High St', 'City 1', 'PC1', 'GB']]);
    expect(within(s).queryAllByRole('button')).toEqual([]);
    // Page order (copy R1-5 L-4): Contacts, then Addresses.
    expect(screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent)).toEqual(['Contacts', 'Addresses']);
  });

  /**
   * Test 7 (customers-plan.md Revision 1 — FINAL R1-6, amended by RED M-3; §5.2; `IA.md:358`): Shipping first, then
   * Billing, NEWEST FIRST within each; an unknown kind last, shown as sent. The served order is `kind, created_at, id`
   * (`CustomerAddressReader.php:62`), pinned by the backend's
   * `CustomerAddressListTest::aCustomersAddressesAreListedWholeInKindThenAgeOrder` — not by the OpenAPI document — and the
   * client reverses it within each kind, because the read carries no `created_at`. This case is driven through the REAL
   * `loadAddresses` over the browser transport (a Retry), the same loader the server page uses on first render.
   */
  //
  // Unknown kinds (customers-c1-commit-red.md L-2; the builder's deviation 4, UX TO CONFIRM — the plan says only "sorted
  // last"): pinned exactly as built — every unknown kind after Billing, the unknown kinds in the order served, and each
  // unknown kind newest first like the known ones.
  it('test 7 — Shipping first then Billing, newest first within each; unknown kinds last, raw, in served order, each newest first', async () => {
    // Served: kind, created_at, id (alphabetical kinds; oldest first within each).
    stubFetch([
      json(
        200,
        page([
          address(1, 'billing', 'Old billing'),
          address(2, 'billing', 'New billing'),
          address(7, 'collection', 'Collection point'),
          address(3, 'dock', 'Old dock'),
          address(8, 'dock', 'New dock'),
          address(4, 'shipping', 'Old shipping'),
          address(5, 'shipping', 'Mid shipping'),
          address(6, 'shipping', 'New shipping'),
        ]),
      ),
    ]);
    const u = userEvent.setup();
    show(okContacts([]), { kind: 'server-error' });
    await u.click(within(addressesSection()).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(rowsOf(addressesSection())).toHaveLength(8));
    expect(rowsOf(addressesSection()).map(([kind, line]) => [kind, line])).toEqual([
      ['Shipping', 'New shipping'],
      ['Shipping', 'Mid shipping'],
      ['Shipping', 'Old shipping'],
      ['Billing', 'New billing'],
      ['Billing', 'Old billing'],
      ['collection', 'Collection point'],
      ['dock', 'New dock'],
      ['dock', 'Old dock'],
    ]);
    expect(pageRegion().textContent).toBe('8 addresses for this customer.');
  });

  it('empty: "This customer has no addresses."; a 403: the denied sentence; Retry → 1 → "1 address for this customer."', async () => {
    const { unmount } = show(okContacts([]), okAddresses([]));
    expect(within(addressesSection()).getByText('This customer has no addresses.', { selector: 'p' })).toBeInTheDocument();
    unmount();
    const r = show(okContacts([]), { kind: 'forbidden' });
    expect(within(addressesSection()).getByText("You don't have access to this customer's addresses.", { selector: 'p' })).toBeInTheDocument();
    r.unmount();
    const calls = stubFetch([json(200, page([address(1, 'billing', '2 Low Rd')]))]);
    const u = userEvent.setup();
    show(okContacts([]), { kind: 'unreachable' });
    expect(within(addressesSection()).getByText(ADDRESSES_FAILED)).toBeInTheDocument();
    await u.click(within(addressesSection()).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(pageRegion().textContent).toBe('1 address for this customer.'));
    expect(calls[0]!.url).toMatch(new RegExp(`/bff/api/customers/${CID}/addresses$`));
    expect(rowsOf(addressesSection())).toEqual([['Billing', '2 Low Rd', 'City 1', 'PC1', 'GB']]);
  });
});

/**
 * Test 8 (customers-plan.md Revision 1 — FINAL R1-6; §5 "Validation of a bounded answer"): either section's answer with a
 * non-null cursor (next or previous) is `server-error` — the region error with Retry — never a partial table: the section
 * claims the whole set (contrast the supplier catalogue's G-42 partial notice).
 */
describe('test 8 — a contacts or addresses answer with a non-null cursor is the section\'s server-error, never a partial table', () => {
  it.each([
    ['Contacts', 'next_cursor', CONTACTS_FAILED],
    ['Contacts', 'previous_cursor', CONTACTS_FAILED],
    ['Addresses', 'next_cursor', ADDRESSES_FAILED],
    ['Addresses', 'previous_cursor', ADDRESSES_FAILED],
  ] as const)('%s with %s set', async (which, cursor, failed) => {
    const row = which === 'Contacts' ? contact(1) : address(1, 'shipping', '1 High St');
    stubFetch([json(200, page([row], { [cursor]: 'eyJrIjoxfQ' }))]);
    const u = userEvent.setup();
    if (which === 'Contacts') show({ kind: 'server-error' });
    else show(okContacts([]), { kind: 'server-error' });
    const s = screen.getByRole('region', { name: which });
    const t = textsOf(pageRegion());
    // The region error is keyed by the section's load number, so the answer's commit replaces the node: wait for that.
    const before = within(s).getByText(failed);
    await u.click(within(s).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(within(s).getByText(failed)).not.toBe(before));
    expect(within(s).getByRole('button', { name: 'Retry' })).toBeInTheDocument();
    expect(within(s).queryByRole('table')).toBeNull();
    expect(t.texts.filter((x) => x !== '')).toEqual([]);
    t.stop();
  });
});

/**
 * The row checks (customers-c1-commit-red.md M-1; customers-plan.md §5): a malformed contact or address row makes the
 * whole answer the section's server-error — the region error with Retry, no table, nothing spoken — never a partial or a
 * guessed row.
 */
describe('test 8 (rows) — a malformed contact or address row is the section\'s server-error, never a table', () => {
  const spoiled = (row: object, spoil: (r: Record<string, unknown>) => void) => {
    const r = { ...row } as Record<string, unknown>;
    spoil(r);
    return r;
  };
  it.each([
    ['Contacts', 'is_primary a string', spoiled(contact(1), (r) => void (r.is_primary = 'yes')), CONTACTS_FAILED],
    ['Contacts', 'email missing', spoiled(contact(1), (r) => void delete r.email), CONTACTS_FAILED],
    ['Addresses', 'kind missing', spoiled(address(1, 'shipping', '1 High St'), (r) => void delete r.kind), ADDRESSES_FAILED],
    ['Addresses', 'line a number', spoiled(address(1, 'shipping', '1 High St'), (r) => void (r.line = 7)), ADDRESSES_FAILED],
  ] as const)('%s with %s', async (which, _, row, failed) => {
    stubFetch([json(200, page([row]))]);
    const u = userEvent.setup();
    if (which === 'Contacts') show({ kind: 'server-error' });
    else show(okContacts([]), { kind: 'server-error' });
    const s = screen.getByRole('region', { name: which });
    const t = textsOf(pageRegion());
    const before = within(s).getByText(failed);
    await u.click(within(s).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(within(s).getByText(failed)).not.toBe(before));
    expect(within(s).getByRole('button', { name: 'Retry' })).toBeInTheDocument();
    expect(within(s).queryByRole('table')).toBeNull();
    expect(t.texts.filter((x) => x !== '')).toEqual([]);
    t.stop();
  });
});
