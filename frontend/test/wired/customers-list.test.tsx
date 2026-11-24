import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: nav.replace }), usePathname: () => '/customers' }));
import { SEARCH_SETTLE_MS } from '@/app/(app)/ArchivableList';
import { CustomersList } from '@/app/(app)/customers/CustomersList';
import type { Customer } from '@/lib/api/customers';
import { formatDate } from '@/lib/format/dates';
import type { Read } from '@/lib/bff/reads';
import { loadCustomer, type CustomersLoad } from '@/lib/customers/load';
import { TZ, json, page, renderWired, textsOf, stubFetch, type Recorded } from './harness';
import styles from '@/app/(app)/Lists.module.css';

// Customers C-1 (customers-plan.md Revision 0 §8 C-1 tests 1–5 under Revision 1 — FINAL R1-6; STATES.md ST-9a;
// b4-architecture.md Revision 8 R8-1 + amendments; the customers-list.test.tsx shape). The list is the real `CustomersList`
// over the REAL `lib/customers/load.ts` and the browser transport: every request it sends is read off the wire and checked
// — no `limit`, no `sort` (no order control), never `after` and `before` together, `q` only when not empty. The paging
// core's own rules are pinned in `paged-list.test.tsx`; here the family's wiring, columns, copy and empty states.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.replace.mockReset();
});

const cust = (id: string, code: string, name: string, over: Partial<Customer> = {}): Customer => ({
  id,
  code,
  name,
  country_code: 'GB',
  currency_code: 'GBP',
  payment_terms_days: 30,
  archived_at: null,
  ...over,
});
const ACME = cust('c1b2c3d4-0000-4000-8000-000000000001', 'ACME', 'Acme Retail');
const BOLT = cust('c1b2c3d4-0000-4000-8000-000000000002', 'BOLT', 'Bolt & Co', { country_code: 'DE', currency_code: 'EUR', payment_terms_days: 1 });
const CASH = cust('c1b2c3d4-0000-4000-8000-000000000003', 'CASH', 'Cash Only Ltd', { payment_terms_days: 0 });
const ZED = cust('c1b2c3d4-0000-4000-8000-000000000004', 'ZED', 'Zed Stores', { archived_at: '2026-03-04T11:00:00Z' });
// Same viewer day as ZED in TZ (UTC+14): both are 5 Mar 2026 there.
const YAK = cust('c1b2c3d4-0000-4000-8000-000000000005', 'YAK', 'Yak Goods', { archived_at: '2026-03-04T10:30:00Z' });
const XEN = cust('c1b2c3d4-0000-4000-8000-000000000006', 'XEN', 'Xen Trading', { archived_at: '2026-01-02T10:00:00Z' });
const ok = (items: Customer[], over: Partial<Extract<CustomersLoad, { kind: 'ok' }>> = {}): CustomersLoad => ({ kind: 'ok', items, nextCursor: null, previousCursor: null, tenantHasRows: items.length > 0, ...over });
const region = () => screen.getByRole('heading', { level: 1 }).closest('section')!.querySelector<HTMLElement>(':scope > [role="status"]')!;
const settle = () => act(() => new Promise((r) => setTimeout(r, SEARCH_SETTLE_MS + 100)));
const headers = () => screen.getAllByRole('columnheader').map((h) => h.textContent);
const cells = (name: string) => within(screen.getByText(name).closest('tr')!).getAllByRole('cell').map((c) => c.textContent);

/**
 * Every ListCustomers request the real loader sent, as its query, after asserting the rules on EACH one (Revision 8
 * amendment 6, limit stated: a loader building a correct query by hand is not detected; a wrong one is). Test 1.
 */
function listRequests(calls: readonly Recorded[]) {
  const reqs = calls.filter((c) => c.method === 'GET').map((c) => new URL(c.url, 'http://localhost'));
  expect(reqs.length).toBeGreaterThan(0);
  for (const u of reqs) {
    expect(u.pathname, 'every list read goes to the customers collection').toBe('/bff/api/customers');
    expect(u.searchParams.has('limit'), `limit is never sent (${u.search})`).toBe(false);
    expect(u.searchParams.has('sort'), `sort is never sent: the default order is the served one (${u.search})`).toBe(false);
    expect(u.searchParams.has('after') && u.searchParams.has('before'), `never both cursors (${u.search})`).toBe(false);
    expect(u.searchParams.has('q') && u.searchParams.get('q') === '', `an empty search sends no q (${u.search})`).toBe(false);
    expect([...u.searchParams.keys()].every((k) => ['archived', 'q', 'after', 'before'].includes(k)), `only declared, wired parameters (${u.search})`).toBe(true);
  }
  return reqs.map((u) => Object.fromEntries(u.searchParams));
}

describe('/customers — the live view (UX S-1 SUPPLIERS_LIST)', () => {
  it('test 5 — Name · Code · Country · Currency · Payment terms; the name links to /customers/[id]; terms read {n} days / 1 day / 0 days', () => {
    renderWired(<CustomersList initial={ok([ACME, BOLT, CASH])} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Customers' })).toBeInTheDocument();
    expect(headers()).toEqual(['Name', 'Code', 'Country', 'Currency', 'Payment terms']);
    const row = screen.getByText('ACME').closest('tr')!;
    expect(within(row).getByRole('link', { name: 'Acme Retail' })).toHaveAttribute('href', `/customers/${ACME.id}`);
    expect(cells('ACME')).toEqual(['Acme Retail', 'ACME', 'GB', 'GBP', '30 days']);
    expect(cells('BOLT')).toEqual(['Bolt & Co', 'BOLT', 'DE', 'EUR', '1 day']);
    expect(cells('CASH')).toEqual(['Cash Only Ltd', 'CASH', 'GB', 'GBP', '0 days']);
    for (const r of document.querySelectorAll('tbody tr')) expect(r.className).not.toBe(styles.struck);
    expect(document.querySelector('caption')).toBeNull();
    expect(screen.getByText('3 shown')).toBeInTheDocument();
  });

  it('the search box says what it matches, tied to it (TB-5a): names ignore capitals, codes do not', () => {
    renderWired(<CustomersList initial={ok([ACME])} />);
    const box = screen.getByRole('searchbox', { name: 'Search by name or code' });
    expect(box).toHaveAttribute('placeholder', 'Start of a name or code');
    expect(box).toHaveAccessibleDescription("Capitals don't matter for names. For codes they do, and so do spaces.");
  });

  it('no sort control, with rows or in any empty state', () => {
    for (const initial of [ok([ACME]), ok([], { tenantHasRows: false }), ok([], { tenantHasRows: true })]) {
      const { unmount } = renderWired(<CustomersList initial={initial} />);
      expect(screen.queryByRole('combobox')).toBeNull();
      expect(screen.queryByText(/sort by/i)).toBeNull();
      unmount();
    }
  });

  // C-1 has no create page (C-3 ships it with G-48's shared-name dialog): no create link or button anywhere.
  it('C-1 — no link to /customers/new and no New customer / Create customer, with rows, in any empty state, or Archived', () => {
    for (const [initial, archived] of [[ok([ACME]), false], [ok([], { tenantHasRows: false }), false], [ok([], { tenantHasRows: true }), false], [ok([]), true]] as const) {
      const { unmount } = renderWired(<CustomersList initial={initial} initialArchived={archived} />);
      expect(document.querySelector('a[href="/customers/new"]')).toBeNull();
      expect(screen.queryByRole('link', { name: /new customer|create customer/i })).toBeNull();
      expect(screen.queryByRole('button', { name: /new customer|create customer/i })).toBeNull();
      unmount();
    }
  });

  // Test 4 (ST-9a's five rows): live none yet / live all archived / live no match / archived none / archived no match.
  it('test 3 — none yet: "No customers yet." and nothing offered', () => {
    renderWired(<CustomersList initial={ok([], { tenantHasRows: false })} />);
    expect(screen.getByText('No customers yet.', { selector: 'p' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /view archived/i })).toBeNull();
  });

  it('test 3 — all archived (tenant_has_rows true): the ST-9a sentence and View archived customers, which switches views', async () => {
    const calls = stubFetch([json(200, page([ZED]))]);
    const u = userEvent.setup();
    renderWired(<CustomersList initial={ok([], { tenantHasRows: true })} />);
    expect(screen.getByText("No customers to show. Every customer here has been deleted, and they're in the archived customers.", { selector: 'p' })).toBeInTheDocument();
    expect(screen.queryByText('No customers yet.')).toBeNull();
    await u.click(screen.getByRole('button', { name: 'View archived customers' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 1, name: 'Archived customers' })).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ archived: 'true' }]);
  });

  it('test 3 — a live search with no match: its sentence and Clear search, which reads the unsearched first page', async () => {
    const calls = stubFetch([json(200, page([], { tenant_has_rows: true })), json(200, page([ACME]))]);
    const u = userEvent.setup();
    renderWired(<CustomersList initial={ok([ACME])} />);
    await u.type(screen.getByRole('searchbox'), 'wid');
    await settle();
    await waitFor(() => expect(screen.getByText("No customer's name or code starts with “wid”.", { selector: 'p' })).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Clear search' }));
    await waitFor(() => expect(screen.getByText('Acme Retail')).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ q: 'wid' }, {}]);
  });

  // Test 2: the server searches; the list shows exactly the rows it serves, never re-filtered on the client.
  it('test 1 — the search box sends q exactly as typed (untrimmed, unfolded), and the rows shown are the rows served', async () => {
    // The server's answer holds a row the typed text does not prefix (a code match the client cannot see): it is shown.
    const calls = stubFetch([json(200, page([BOLT, CASH]))]);
    const u = userEvent.setup();
    renderWired(<CustomersList initial={ok([ACME, BOLT, CASH])} />);
    await u.type(screen.getByRole('searchbox'), ' aC');
    await settle();
    await waitFor(() => expect(screen.queryByText('Acme Retail')).toBeNull());
    expect(screen.getByText('Bolt & Co')).toBeInTheDocument();
    expect(screen.getByText('Cash Only Ltd')).toBeInTheDocument();
    expect(listRequests(calls)).toEqual([{ q: ' aC' }]);
  });
});

describe('/customers — the Archived view (UX S-1)', () => {
  it('test 2 — heading, Name · Code · Archived on (DT-1), struck rows, the caption, Back to customers, its own search label', () => {
    renderWired(<CustomersList initial={ok([ZED, XEN])} initialArchived />);
    expect(screen.getByRole('heading', { level: 1, name: 'Archived customers' })).toBeInTheDocument();
    expect(headers()).toEqual(['Name', 'Code', 'Archived on']);
    expect(document.querySelector('caption')?.textContent).toBe('Most recently archived first');
    expect(cells('ZED')).toEqual(['Zed Stores', 'ZED', formatDate(ZED.archived_at!, TZ)]);
    expect(within(screen.getByText('ZED').closest('tr')!).getByRole('link', { name: 'Zed Stores' })).toHaveAttribute('href', `/customers/${ZED.id}`);
    expect(screen.getByRole('button', { name: 'Back to customers' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Archived' })).toBeNull();
    expect(styles.struck).toEqual(expect.any(String));
    const rows = [...document.querySelectorAll('tbody tr')];
    expect(rows).toHaveLength(2);
    for (const r of rows) expect(r.className).toBe(styles.struck);
    expect(screen.getByRole('searchbox', { name: 'Search archived customers by name or code' })).toBeInTheDocument();
  });

  it('server order kept (as served, never re-sorted on the client)', () => {
    renderWired(<CustomersList initial={ok([XEN, ZED])} initialArchived />);
    expect(screen.getAllByRole('link').map((a) => a.textContent)).toEqual(['Xen Trading', 'Zed Stores']);
  });

  it('test 3 — none archived, and an archived search with no match (with Clear search)', async () => {
    stubFetch([json(200, page([], { tenant_has_rows: true }))]);
    const u = userEvent.setup();
    renderWired(<CustomersList initial={ok([], { tenantHasRows: true })} initialArchived />);
    // C-1's INTERIM sentence (customers-c1-ux-copy.md Revision 1 — FINAL §1, R1-1): no Restore before C-2b, so nothing on
    // this view may name one; swapping in ST-9a's full sentence early goes red here (the warehouses-list `434f239` guard).
    expect(screen.getByText('No archived customers. Customers that are deleted appear here.', { selector: 'p' })).toBeInTheDocument();
    expect(document.body).not.toHaveTextContent(/restore/i);
    await u.type(screen.getByRole('searchbox'), 'Q');
    await settle();
    await waitFor(() => expect(screen.getByText("No archived customer's name or code starts with “Q”.", { selector: 'p' })).toBeInTheDocument());
    expect(screen.getByRole('button', { name: 'Clear search' })).toBeInTheDocument();
  });
});

// The settled announcements (the `shown` sentence: first load, a switch, a settle). First paint is not announced (ST-2a),
// so the first-load cases fail the server read first and Retry.
describe('/customers — the settled announcements', () => {
  it('first load, live, 2 rows: "2 customers shown."', async () => {
    stubFetch([json(200, page([ACME, BOLT]))]);
    const u = userEvent.setup();
    renderWired(<CustomersList initial={{ kind: 'server-error' }} />);
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(region()).toHaveTextContent(/^2 customers shown\.$/));
  });

  it('first load, Archived, 1 row: "1 archived customer shown."', async () => {
    stubFetch([json(200, page([ZED]))]);
    const u = userEvent.setup();
    renderWired(<CustomersList initial={{ kind: 'server-error' }} initialArchived />);
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(region()).toHaveTextContent(/^1 archived customer shown\.$/));
  });

  it('a failed first load says the list could not be loaded', () => {
    renderWired(<CustomersList initial={{ kind: 'server-error' }} />);
    expect(screen.getByText("The list of customers couldn't be loaded.")).toBeInTheDocument();
  });

  it('a settle on "A" answering 1 row: "1 customer shown. Name or code starts with “A”."', async () => {
    stubFetch([json(200, page([ACME]))]);
    const u = userEvent.setup();
    renderWired(<CustomersList initial={ok([ACME, BOLT])} />);
    const { texts, stop } = textsOf(region());
    await u.type(screen.getByRole('searchbox'), 'A');
    await settle();
    await waitFor(() => expect(region()).toHaveTextContent(/^1 customer shown\. Name or code starts with “A”\.$/));
    stop();
    expect(texts.at(-1)).toBe('1 customer shown. Name or code starts with “A”.');
  });
});

describe('/customers — the wire, the pager and the page sentences', () => {
  // Test 1: a view switch sends no cursor; the paged read never sends `limit` or `sort`.
  it('test 1 — a walk through every transition: no limit, no sort, one cursor at most, none after a switch, q only when not empty', async () => {
    const calls = stubFetch([
      json(200, page([ACME, BOLT], { next_cursor: 'n1' })), // settle q=A
      json(200, page([CASH], { previous_cursor: 'p1' })), // Next (after=n1, q=A)
      json(200, page([ZED], {})), // switch to Archived with the box's text: no cursor
      json(200, page([ACME], {})), // Back to customers: no cursor
    ]);
    const u = userEvent.setup();
    renderWired(<CustomersList initial={ok([ACME, BOLT, CASH], { nextCursor: 'n0' })} />);
    await u.type(screen.getByRole('searchbox'), 'A');
    await settle();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Next' })).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(region()).toHaveTextContent('1 customer shown, Cash Only Ltd. Name or code starts with “A”.'));
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 1, name: 'Archived customers' })).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Back to customers' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 1, name: 'Customers' })).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ q: 'A' }, { q: 'A', after: 'n1' }, { archived: 'true', q: 'A' }, { q: 'A' }]);
    expect(nav.replace.mock.calls.map((c) => c[0])).toEqual(['/customers?archived=true', '/customers']);
  });

  it('live pager: Previous / Next, each only with its cursor; the page sentence names the first and last names', async () => {
    const calls = stubFetch([json(200, page([BOLT, CASH], { previous_cursor: 'p' }))]);
    const u = userEvent.setup();
    renderWired(<CustomersList initial={ok([ACME], { nextCursor: 'n' })} />);
    expect(screen.queryByRole('button', { name: 'Previous' })).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(region()).toHaveTextContent(/^2 customers shown, Bolt & Co to Cash Only Ltd\.$/));
    expect(screen.getByRole('button', { name: 'Previous' })).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/page \d|of \d/i);
    expect(listRequests(calls)).toEqual([{ after: 'n' }]);
  });

  it('Archived pager: Newer / Older, Older being the next page; its page sentences name dates and the first name', async () => {
    const calls = stubFetch([json(200, page([ZED, YAK], { previous_cursor: 'p' })), json(200, page([XEN], { previous_cursor: 'p2' })), json(200, page([ZED, XEN]))]);
    const u = userEvent.setup();
    renderWired(<CustomersList initial={ok([XEN], { nextCursor: 'o1' })} initialArchived />);
    expect(screen.queryByRole('button', { name: 'Newer' })).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(region()).toHaveTextContent(`2 archived customers shown, archived on ${formatDate(ZED.archived_at!, TZ)}, starting with Zed Stores.`));
    await u.click(screen.getByRole('button', { name: 'Newer' }));
    await waitFor(() => expect(region()).toHaveTextContent(`1 archived customer shown, Xen Trading, archived on ${formatDate(XEN.archived_at!, TZ)}.`));
    expect(listRequests(calls).slice(0, 2)).toEqual([{ archived: 'true', after: 'o1' }, { archived: 'true', before: 'p' }]);
  });

  it('Archived page with differing dates: "archived {newest} to {oldest}, starting with {firstName}."', async () => {
    stubFetch([json(200, page([ZED, XEN]))]);
    const u = userEvent.setup();
    renderWired(<CustomersList initial={ok([YAK], { nextCursor: 'o1' })} initialArchived />);
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(region()).toHaveTextContent(`2 archived customers shown, archived ${formatDate(ZED.archived_at!, TZ)} to ${formatDate(XEN.archived_at!, TZ)}, starting with Zed Stores.`));
  });

  it('the empty page a cursor reached: its own heading and Back to the first page, which sends no cursor', async () => {
    const calls = stubFetch([json(200, page([], { tenant_has_rows: true })), json(200, page([ACME]))]);
    const u = userEvent.setup();
    renderWired(<CustomersList initial={ok([ACME], { nextCursor: 'n' })} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(screen.getByText('There are no more customers here.', { selector: 'p' })).toBeInTheDocument());
    expect(screen.getByText('The list changed after the page you were on was loaded.')).toBeInTheDocument();
    expect(screen.queryByText('No customers yet.')).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Back to the first page' }));
    await waitFor(() => expect(screen.getByText('Acme Retail')).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ after: 'n' }, {}]);
  });

  it.each([
    ['code missing', (s: Record<string, unknown>): void => void delete s.code],
    ['name missing', (s: Record<string, unknown>): void => void delete s.name],
    ['id missing', (s: Record<string, unknown>): void => void delete s.id],
    ['country_code missing', (s: Record<string, unknown>): void => void delete s.country_code],
    ['payment_terms_days missing', (s: Record<string, unknown>): void => void delete s.payment_terms_days],
    ['payment_terms_days a string', (s: Record<string, unknown>): void => void (s.payment_terms_days = '30')],
    ['payment_terms_days not whole', (s: Record<string, unknown>): void => void (s.payment_terms_days = 30.5)],
    ['currency_code missing', (s: Record<string, unknown>): void => void delete s.currency_code],
    ['archived_at missing', (s: Record<string, unknown>): void => void delete s.archived_at],
  ] as const)('test 4 — a malformed row (%s) is a failure, never rows: the refetch-failed banner', async (_, spoil) => {
    const bad = { ...BOLT } as Record<string, unknown>;
    spoil(bad);
    stubFetch([json(200, page([bad]))]);
    const u = userEvent.setup();
    renderWired(<CustomersList initial={ok([ACME], { nextCursor: 'n' })} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(screen.getByText("The list couldn't be updated, so it still shows the earlier results.")).toBeInTheDocument());
    expect(screen.queryByText('Bolt & Co')).toBeNull();
  });
});

// Test 4, the page's own read (customers-c1-commit-red.md H-1): `GetCustomer`'s body goes through the same row check, so a
// body missing `code` (or any other served field) is `server-error`, never a customer.
describe('test 4 — GetCustomer: a malformed body is server-error', () => {
  it.each(['code', 'id', 'name', 'country_code', 'currency_code', 'payment_terms_days', 'archived_at'])('a body missing %s', async (field) => {
    const body = { ...BOLT } as Record<string, unknown>;
    delete body[field];
    const read: Read = async () => ({ kind: 'ok', body });
    expect(await loadCustomer(read, BOLT.id)).toEqual({ kind: 'server-error' });
  });

  it('a whole body is the customer', async () => {
    const read: Read = async () => ({ kind: 'ok', body: { ...BOLT } });
    expect(await loadCustomer(read, BOLT.id)).toEqual({ kind: 'ok', customer: BOLT });
  });
});
