import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: nav.replace }), usePathname: () => '/suppliers' }));
import { SEARCH_SETTLE_MS } from '@/app/(app)/ArchivableList';
import { SuppliersList } from '@/app/(app)/suppliers/SuppliersList';
import type { Supplier } from '@/lib/api/suppliers';
import { formatDate } from '@/lib/format/dates';
import type { SuppliersLoad } from '@/lib/suppliers/load';
import { TZ, json, page, renderWired, textsOf, stubFetch, type Recorded } from './harness';
import styles from '@/app/(app)/Lists.module.css';

// Suppliers S-1 (suppliers-plan.md §R1-4 S-1 tests 1–4 over Revision 0 §2.1; suppliers-ux-copy.md Revision 1 — FINAL S-1;
// b4-architecture.md Revision 8 R8-1 + amendments). The list is the real `SuppliersList` over the REAL
// `lib/suppliers/load.ts` and the browser transport: every request it sends is read off the wire and checked — no
// `limit`, no `sort` (the order control is out, §0), never `after` and `before` together, `q` only when not empty. The
// paging core's own rules are pinned in `paged-list.test.tsx`; here the family's wiring, columns, copy and empty states.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.replace.mockReset();
});

const sup = (id: string, code: string, name: string, over: Partial<Supplier> = {}): Supplier => ({
  id,
  code,
  name,
  country_code: 'GB',
  currency_code: 'GBP',
  payment_terms_days: 30,
  archived_at: null,
  ...over,
});
const ACME = sup('c1b2c3d4-0000-4000-8000-000000000001', 'ACME', 'Acme Supplies');
const BOLT = sup('c1b2c3d4-0000-4000-8000-000000000002', 'BOLT', 'Bolt & Co', { country_code: 'DE', currency_code: 'EUR', payment_terms_days: 1 });
const CASH = sup('c1b2c3d4-0000-4000-8000-000000000003', 'CASH', 'Cash Only Ltd', { payment_terms_days: 0 });
const ZED = sup('c1b2c3d4-0000-4000-8000-000000000004', 'ZED', 'Zed Parts', { archived_at: '2026-03-04T11:00:00Z' });
// Same viewer day as ZED in TZ (UTC+14): both are 5 Mar 2026 there.
const YAK = sup('c1b2c3d4-0000-4000-8000-000000000005', 'YAK', 'Yak Goods', { archived_at: '2026-03-04T10:30:00Z' });
const XEN = sup('c1b2c3d4-0000-4000-8000-000000000006', 'XEN', 'Xen Trading', { archived_at: '2026-01-02T10:00:00Z' });
const ok = (items: Supplier[], over: Partial<Extract<SuppliersLoad, { kind: 'ok' }>> = {}): SuppliersLoad => ({ kind: 'ok', items, nextCursor: null, previousCursor: null, tenantHasRows: items.length > 0, ...over });
const region = () => screen.getByRole('heading', { level: 1 }).closest('section')!.querySelector<HTMLElement>(':scope > [role="status"]')!;
const settle = () => act(() => new Promise((r) => setTimeout(r, SEARCH_SETTLE_MS + 100)));
const headers = () => screen.getAllByRole('columnheader').map((h) => h.textContent);
const cells = (name: string) => within(screen.getByText(name).closest('tr')!).getAllByRole('cell').map((c) => c.textContent);

/**
 * Every ListSuppliers request the real loader sent, as its query, after asserting the rules on EACH one (Revision 8
 * amendment 6, limit stated: a loader building a correct query by hand is not detected; a wrong one is). Test 1.
 */
function listRequests(calls: readonly Recorded[]) {
  const reqs = calls.filter((c) => c.method === 'GET').map((c) => new URL(c.url, 'http://localhost'));
  expect(reqs.length).toBeGreaterThan(0);
  for (const u of reqs) {
    expect(u.pathname, 'every list read goes to the suppliers collection').toBe('/bff/api/suppliers');
    expect(u.searchParams.has('limit'), `limit is never sent (${u.search})`).toBe(false);
    expect(u.searchParams.has('sort'), `sort is never sent: the default order is the served one (${u.search})`).toBe(false);
    expect(u.searchParams.has('after') && u.searchParams.has('before'), `never both cursors (${u.search})`).toBe(false);
    expect(u.searchParams.has('q') && u.searchParams.get('q') === '', `an empty search sends no q (${u.search})`).toBe(false);
    expect([...u.searchParams.keys()].every((k) => ['archived', 'q', 'after', 'before'].includes(k)), `only declared, wired parameters (${u.search})`).toBe(true);
  }
  return reqs.map((u) => Object.fromEntries(u.searchParams));
}

describe('/suppliers — the live view (UX S-1 SUPPLIERS_LIST)', () => {
  it('Name · Code · Country · Currency · Payment terms; the name links to /suppliers/[id]; terms read {n} days / 1 day / 0 days', () => {
    renderWired(<SuppliersList initial={ok([ACME, BOLT, CASH])} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Suppliers' })).toBeInTheDocument();
    expect(headers()).toEqual(['Name', 'Code', 'Country', 'Currency', 'Payment terms']);
    const row = screen.getByText('ACME').closest('tr')!;
    expect(within(row).getByRole('link', { name: 'Acme Supplies' })).toHaveAttribute('href', `/suppliers/${ACME.id}`);
    expect(cells('ACME')).toEqual(['Acme Supplies', 'ACME', 'GB', 'GBP', '30 days']);
    expect(cells('BOLT')).toEqual(['Bolt & Co', 'BOLT', 'DE', 'EUR', '1 day']);
    expect(cells('CASH')).toEqual(['Cash Only Ltd', 'CASH', 'GB', 'GBP', '0 days']);
    for (const r of document.querySelectorAll('tbody tr')) expect(r.className).not.toBe(styles.struck);
    expect(document.querySelector('caption')).toBeNull();
    expect(screen.getByText('3 shown')).toBeInTheDocument();
  });

  it('the search box says what it matches, tied to it (TB-5a): names ignore capitals, codes do not', () => {
    renderWired(<SuppliersList initial={ok([ACME])} />);
    const box = screen.getByRole('searchbox', { name: 'Search by name or code' });
    expect(box).toHaveAttribute('placeholder', 'Start of a name or code');
    expect(box).toHaveAccessibleDescription("Capitals don't matter for names. For codes they do, and so do spaces.");
  });

  it('no sort control, with rows or in any empty state', () => {
    for (const initial of [ok([ACME]), ok([], { tenantHasRows: false }), ok([], { tenantHasRows: true })]) {
      const { unmount } = renderWired(<SuppliersList initial={initial} canCreate />);
      expect(screen.queryByRole('combobox')).toBeNull();
      expect(screen.queryByText(/sort by/i)).toBeNull();
      unmount();
    }
  });

  // S-2 (UX S-2 `newSupplier` · `createSupplier`): the create link for a CreateSupplier holder only.
  it('S-2 — without CreateSupplier: no link to /suppliers/new and no New supplier / Create supplier, with rows or in any empty state', () => {
    for (const initial of [ok([ACME]), ok([], { tenantHasRows: false }), ok([], { tenantHasRows: true })]) {
      const { unmount } = renderWired(<SuppliersList initial={initial} />);
      expect(document.querySelector('a[href="/suppliers/new"]')).toBeNull();
      expect(screen.queryByRole('link', { name: /new supplier|create supplier/i })).toBeNull();
      expect(screen.queryByRole('button', { name: /new supplier|create supplier/i })).toBeNull();
      unmount();
    }
  });

  it('S-2 — with CreateSupplier: New supplier in the live header (before Archived); Create supplier on "none yet" and "all archived"', () => {
    const live = renderWired(<SuppliersList initial={ok([ACME])} canCreate />);
    const newLink = screen.getByRole('link', { name: 'New supplier' });
    expect(newLink).toHaveAttribute('href', '/suppliers/new');
    expect(newLink.compareDocumentPosition(screen.getByRole('button', { name: 'Archived' })) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Create supplier' })).toBeNull();
    live.unmount();
    for (const initial of [ok([], { tenantHasRows: false }), ok([], { tenantHasRows: true })]) {
      const { unmount } = renderWired(<SuppliersList initial={initial} canCreate />);
      expect(screen.getByRole('link', { name: 'Create supplier' })).toHaveAttribute('href', '/suppliers/new');
      unmount();
    }
  });

  it('S-2 — with CreateSupplier: no Create supplier on a search with no match', async () => {
    const calls = stubFetch([json(200, page([], { tenant_has_rows: true })), json(200, page([]))]);
    const u = userEvent.setup();
    renderWired(<SuppliersList initial={ok([ACME])} canCreate />);
    await u.type(screen.getByRole('searchbox'), 'wid');
    await settle();
    await waitFor(() => expect(screen.getByText("No supplier's name or code starts with “wid”.", { selector: 'p' })).toBeInTheDocument());
    expect(screen.queryByRole('link', { name: 'Create supplier' })).toBeNull();
    expect(listRequests(calls)).toEqual([{ q: 'wid' }]);
  });

  it('S-2 — the Archived view offers neither New supplier nor Create supplier', () => {
    renderWired(<SuppliersList initial={ok([])} initialArchived canCreate />);
    expect(screen.getByText('No archived suppliers. Suppliers that are deleted appear here and can be restored from here.', { selector: 'p' })).toBeInTheDocument();
    expect(document.querySelector('a[href="/suppliers/new"]')).toBeNull();
  });

  // Test 4 (ST-9a's five rows): live none yet / live all archived / live no match / archived none / archived no match.
  it('test 4 — none yet: "No suppliers yet." and nothing offered', () => {
    renderWired(<SuppliersList initial={ok([], { tenantHasRows: false })} />);
    expect(screen.getByText('No suppliers yet.', { selector: 'p' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /view archived/i })).toBeNull();
  });

  it('test 4 — all archived (tenant_has_rows true): the ST-9a sentence and View archived suppliers, which switches views', async () => {
    const calls = stubFetch([json(200, page([ZED]))]);
    const u = userEvent.setup();
    renderWired(<SuppliersList initial={ok([], { tenantHasRows: true })} />);
    expect(screen.getByText("No suppliers to show. Every supplier here has been deleted, and they're in the archived suppliers.", { selector: 'p' })).toBeInTheDocument();
    expect(screen.queryByText('No suppliers yet.')).toBeNull();
    await u.click(screen.getByRole('button', { name: 'View archived suppliers' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 1, name: 'Archived suppliers' })).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ archived: 'true' }]);
  });

  it('test 4 — a live search with no match: its sentence and Clear search, which reads the unsearched first page', async () => {
    const calls = stubFetch([json(200, page([], { tenant_has_rows: true })), json(200, page([ACME]))]);
    const u = userEvent.setup();
    renderWired(<SuppliersList initial={ok([ACME])} />);
    await u.type(screen.getByRole('searchbox'), 'wid');
    await settle();
    await waitFor(() => expect(screen.getByText("No supplier's name or code starts with “wid”.", { selector: 'p' })).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Clear search' }));
    await waitFor(() => expect(screen.getByText('Acme Supplies')).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ q: 'wid' }, {}]);
  });

  // Test 2: the server searches; the list shows exactly the rows it serves, never re-filtered on the client.
  it('test 2 — the search box sends q exactly as typed (untrimmed, unfolded), and the rows shown are the rows served', async () => {
    // The server's answer holds a row the typed text does not prefix (a code match the client cannot see): it is shown.
    const calls = stubFetch([json(200, page([BOLT, CASH]))]);
    const u = userEvent.setup();
    renderWired(<SuppliersList initial={ok([ACME, BOLT, CASH])} />);
    await u.type(screen.getByRole('searchbox'), ' aC');
    await settle();
    await waitFor(() => expect(screen.queryByText('Acme Supplies')).toBeNull());
    expect(screen.getByText('Bolt & Co')).toBeInTheDocument();
    expect(screen.getByText('Cash Only Ltd')).toBeInTheDocument();
    expect(listRequests(calls)).toEqual([{ q: ' aC' }]);
  });
});

describe('/suppliers — the Archived view (UX S-1)', () => {
  it('test 3 — heading, Name · Code · Archived on (DT-1), struck rows, the caption, Back to suppliers, its own search label', () => {
    renderWired(<SuppliersList initial={ok([ZED, XEN])} initialArchived />);
    expect(screen.getByRole('heading', { level: 1, name: 'Archived suppliers' })).toBeInTheDocument();
    expect(headers()).toEqual(['Name', 'Code', 'Archived on']);
    expect(document.querySelector('caption')?.textContent).toBe('Most recently archived first');
    expect(cells('ZED')).toEqual(['Zed Parts', 'ZED', formatDate(ZED.archived_at!, TZ)]);
    expect(within(screen.getByText('ZED').closest('tr')!).getByRole('link', { name: 'Zed Parts' })).toHaveAttribute('href', `/suppliers/${ZED.id}`);
    expect(screen.getByRole('button', { name: 'Back to suppliers' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Archived' })).toBeNull();
    expect(styles.struck).toEqual(expect.any(String));
    const rows = [...document.querySelectorAll('tbody tr')];
    expect(rows).toHaveLength(2);
    for (const r of rows) expect(r.className).toBe(styles.struck);
    expect(screen.getByRole('searchbox', { name: 'Search archived suppliers by name or code' })).toBeInTheDocument();
  });

  it('server order kept (as served, never re-sorted on the client)', () => {
    renderWired(<SuppliersList initial={ok([XEN, ZED])} initialArchived />);
    expect(screen.getAllByRole('link').map((a) => a.textContent)).toEqual(['Xen Trading', 'Zed Parts']);
  });

  it('test 4 — none archived, and an archived search with no match (with Clear search)', async () => {
    stubFetch([json(200, page([], { tenant_has_rows: true }))]);
    const u = userEvent.setup();
    renderWired(<SuppliersList initial={ok([], { tenantHasRows: true })} initialArchived />);
    expect(screen.getByText('No archived suppliers. Suppliers that are deleted appear here and can be restored from here.', { selector: 'p' })).toBeInTheDocument();
    await u.type(screen.getByRole('searchbox'), 'Q');
    await settle();
    await waitFor(() => expect(screen.getByText("No archived supplier's name or code starts with “Q”.", { selector: 'p' })).toBeInTheDocument());
    expect(screen.getByRole('button', { name: 'Clear search' })).toBeInTheDocument();
  });
});

// The settled announcements (the `shown` sentence: first load, a switch, a settle). First paint is not announced (ST-2a),
// so the first-load cases fail the server read first and Retry.
describe('/suppliers — the settled announcements', () => {
  it('first load, live, 2 rows: "2 suppliers shown."', async () => {
    stubFetch([json(200, page([ACME, BOLT]))]);
    const u = userEvent.setup();
    renderWired(<SuppliersList initial={{ kind: 'server-error' }} />);
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(region()).toHaveTextContent(/^2 suppliers shown\.$/));
  });

  it('first load, Archived, 1 row: "1 archived supplier shown."', async () => {
    stubFetch([json(200, page([ZED]))]);
    const u = userEvent.setup();
    renderWired(<SuppliersList initial={{ kind: 'server-error' }} initialArchived />);
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(region()).toHaveTextContent(/^1 archived supplier shown\.$/));
  });

  it('a failed first load says the list could not be loaded', () => {
    renderWired(<SuppliersList initial={{ kind: 'server-error' }} />);
    expect(screen.getByText("The list of suppliers couldn't be loaded.")).toBeInTheDocument();
  });

  it('a settle on "A" answering 1 row: "1 supplier shown. Name or code starts with “A”."', async () => {
    stubFetch([json(200, page([ACME]))]);
    const u = userEvent.setup();
    renderWired(<SuppliersList initial={ok([ACME, BOLT])} />);
    const { texts, stop } = textsOf(region());
    await u.type(screen.getByRole('searchbox'), 'A');
    await settle();
    await waitFor(() => expect(region()).toHaveTextContent(/^1 supplier shown\. Name or code starts with “A”\.$/));
    stop();
    expect(texts.at(-1)).toBe('1 supplier shown. Name or code starts with “A”.');
  });
});

describe('/suppliers — the wire, the pager and the page sentences', () => {
  // Test 1: a view switch sends no cursor; the paged read never sends `limit` or `sort`.
  it('test 1 — a walk through every transition: no limit, no sort, one cursor at most, none after a switch, q only when not empty', async () => {
    const calls = stubFetch([
      json(200, page([ACME, BOLT], { next_cursor: 'n1' })), // settle q=A
      json(200, page([CASH], { previous_cursor: 'p1' })), // Next (after=n1, q=A)
      json(200, page([ZED], {})), // switch to Archived with the box's text: no cursor
      json(200, page([ACME], {})), // Back to suppliers: no cursor
    ]);
    const u = userEvent.setup();
    renderWired(<SuppliersList initial={ok([ACME, BOLT, CASH], { nextCursor: 'n0' })} />);
    await u.type(screen.getByRole('searchbox'), 'A');
    await settle();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Next' })).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(region()).toHaveTextContent('1 supplier shown, Cash Only Ltd. Name or code starts with “A”.'));
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 1, name: 'Archived suppliers' })).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Back to suppliers' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 1, name: 'Suppliers' })).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ q: 'A' }, { q: 'A', after: 'n1' }, { archived: 'true', q: 'A' }, { q: 'A' }]);
    expect(nav.replace.mock.calls.map((c) => c[0])).toEqual(['/suppliers?archived=true', '/suppliers']);
  });

  it('live pager: Previous / Next, each only with its cursor; the page sentence names the first and last names', async () => {
    const calls = stubFetch([json(200, page([BOLT, CASH], { previous_cursor: 'p' }))]);
    const u = userEvent.setup();
    renderWired(<SuppliersList initial={ok([ACME], { nextCursor: 'n' })} />);
    expect(screen.queryByRole('button', { name: 'Previous' })).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(region()).toHaveTextContent(/^2 suppliers shown, Bolt & Co to Cash Only Ltd\.$/));
    expect(screen.getByRole('button', { name: 'Previous' })).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/page \d|of \d/i);
    expect(listRequests(calls)).toEqual([{ after: 'n' }]);
  });

  it('Archived pager: Newer / Older, Older being the next page; its page sentences name dates and the first name', async () => {
    const calls = stubFetch([json(200, page([ZED, YAK], { previous_cursor: 'p' })), json(200, page([XEN], { previous_cursor: 'p2' })), json(200, page([ZED, XEN]))]);
    const u = userEvent.setup();
    renderWired(<SuppliersList initial={ok([XEN], { nextCursor: 'o1' })} initialArchived />);
    expect(screen.queryByRole('button', { name: 'Newer' })).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(region()).toHaveTextContent(`2 archived suppliers shown, archived on ${formatDate(ZED.archived_at!, TZ)}, starting with Zed Parts.`));
    await u.click(screen.getByRole('button', { name: 'Newer' }));
    await waitFor(() => expect(region()).toHaveTextContent(`1 archived supplier shown, Xen Trading, archived on ${formatDate(XEN.archived_at!, TZ)}.`));
    expect(listRequests(calls).slice(0, 2)).toEqual([{ archived: 'true', after: 'o1' }, { archived: 'true', before: 'p' }]);
  });

  it('Archived page with differing dates: "archived {newest} to {oldest}, starting with {firstName}."', async () => {
    stubFetch([json(200, page([ZED, XEN]))]);
    const u = userEvent.setup();
    renderWired(<SuppliersList initial={ok([YAK], { nextCursor: 'o1' })} initialArchived />);
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(region()).toHaveTextContent(`2 archived suppliers shown, archived ${formatDate(ZED.archived_at!, TZ)} to ${formatDate(XEN.archived_at!, TZ)}, starting with Zed Parts.`));
  });

  it('the empty page a cursor reached: its own heading and Back to the first page, which sends no cursor', async () => {
    const calls = stubFetch([json(200, page([], { tenant_has_rows: true })), json(200, page([ACME]))]);
    const u = userEvent.setup();
    renderWired(<SuppliersList initial={ok([ACME], { nextCursor: 'n' })} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(screen.getByText('There are no more suppliers here.', { selector: 'p' })).toBeInTheDocument());
    expect(screen.getByText('The list changed after the page you were on was loaded.')).toBeInTheDocument();
    expect(screen.queryByText('No suppliers yet.')).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Back to the first page' }));
    await waitFor(() => expect(screen.getByText('Acme Supplies')).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ after: 'n' }, {}]);
  });

  it.each([
    ['payment_terms_days missing', (s: Record<string, unknown>): void => void delete s.payment_terms_days],
    ['payment_terms_days a string', (s: Record<string, unknown>): void => void (s.payment_terms_days = '30')],
    ['payment_terms_days not whole', (s: Record<string, unknown>): void => void (s.payment_terms_days = 30.5)],
    ['currency_code missing', (s: Record<string, unknown>): void => void delete s.currency_code],
    ['archived_at missing', (s: Record<string, unknown>): void => void delete s.archived_at],
  ] as const)('a malformed row (%s) is a failure, never rows: the refetch-failed banner', async (_, spoil) => {
    const bad = { ...BOLT } as Record<string, unknown>;
    spoil(bad);
    stubFetch([json(200, page([bad]))]);
    const u = userEvent.setup();
    renderWired(<SuppliersList initial={ok([ACME], { nextCursor: 'n' })} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(screen.getByText("The list couldn't be updated, so it still shows the earlier results.")).toBeInTheDocument());
    expect(screen.queryByText('Bolt & Co')).toBeNull();
  });
});
