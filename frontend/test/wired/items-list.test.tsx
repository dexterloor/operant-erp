import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: nav.replace }), usePathname: () => '/items' }));
import { SEARCH_SETTLE_MS } from '@/app/(app)/ArchivableList';
import { ItemsList } from '@/app/(app)/items/ItemsList';
import type { Item } from '@/lib/api/items';
import { formatDate } from '@/lib/format/dates';
import type { ItemsLoad } from '@/lib/items/load';
import { TZ, json, page, renderWired, stubFetch, textsOf, type Recorded } from './harness';
import styles from '@/app/(app)/Lists.module.css';

// Items I-1 (items-plan.md §4 I-1 + Revision 1 — FINAL; items-ux-copy.md B-1, B-2 as amended by R1-3, R1-5, R1-6, R1-8,
// R1-14, R1-16, F-2; b4-architecture.md Revision 8 R8-1 + amendments). The list is the real `ItemsList` over the REAL
// `lib/items/load.ts` and the browser transport: every request the list sends is read off the wire and checked (C-4 /
// RED L-2): no `limit`, never `after` and `before` together, `q` only when not empty. The paging core's own rules are
// pinned in `paged-list.test.tsx`; here the family's wiring, columns, copy and empty states are.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.replace.mockReset();
});

const it_ = (id: string, sku: string, over: Partial<Item> = {}): Item => ({
  id,
  sku,
  name: `Name of ${sku}`,
  unit_of_measure_code: 'EA',
  reorder_point: '10.000000',
  reorder_quantity: '5.500000',
  safety_stock: '0.000000',
  supplier: null,
  archived_at: null,
  ...over,
});
const A1 = it_('b1b2c3d4-0000-4000-8000-000000000001', 'A-1', { supplier: { id: 'c1b2c3d4-0000-4000-8000-000000000009', name: 'Acme Supplies' } });
const A2 = it_('b1b2c3d4-0000-4000-8000-000000000002', 'A-2', { unit_of_measure_code: 'KG' });
const B1 = it_('b1b2c3d4-0000-4000-8000-000000000003', 'B-1');
const Z9 = it_('b1b2c3d4-0000-4000-8000-000000000004', 'Z-9', { archived_at: '2026-03-04T11:00:00Z' });
// Same viewer day as Z-9 in TZ (UTC+14): both are 5 Mar 2026 there.
const Y8 = it_('b1b2c3d4-0000-4000-8000-000000000005', 'Y-8', { archived_at: '2026-03-04T10:30:00Z' });
const X7 = it_('b1b2c3d4-0000-4000-8000-000000000006', 'X-7', { archived_at: '2026-01-02T10:00:00Z' });
const ok = (items: Item[], over: Partial<Extract<ItemsLoad, { kind: 'ok' }>> = {}): ItemsLoad => ({ kind: 'ok', items, nextCursor: null, previousCursor: null, tenantHasRows: items.length > 0, ...over });
const region = () => screen.getByRole('heading', { level: 1 }).closest('section')!.querySelector<HTMLElement>(':scope > [role="status"]')!;
const settle = () => act(() => new Promise((r) => setTimeout(r, SEARCH_SETTLE_MS + 100)));
const headers = () => screen.getAllByRole('columnheader').map((h) => h.textContent);

/**
 * C-4 / RED L-2 (Revision 8 amendment 6, limit stated): every ListItems request the real loader sent, as `{path, params}`,
 * after asserting the three rules on EACH one. A loader building a correct query by hand is not detected; a wrong one is.
 */
function listRequests(calls: readonly Recorded[]) {
  const reqs = calls.filter((c) => c.method === 'GET').map((c) => new URL(c.url, 'http://localhost'));
  expect(reqs.length).toBeGreaterThan(0);
  for (const u of reqs) {
    expect(u.pathname, 'every list read goes to the items collection').toBe('/bff/api/items');
    expect(u.searchParams.has('limit'), `limit is never sent (${u.search})`).toBe(false);
    expect(u.searchParams.has('after') && u.searchParams.has('before'), `never both cursors (${u.search})`).toBe(false);
    expect(u.searchParams.has('q') && u.searchParams.get('q') === '', `an empty search sends no q (${u.search})`).toBe(false);
    expect(u.searchParams.has('supplier_id'), `the supplier filter is out of this build (${u.search})`).toBe(false);
    expect([...u.searchParams.keys()].every((k) => ['archived', 'q', 'after', 'before'].includes(k)), `only declared, wired parameters (${u.search})`).toBe(true);
  }
  return reqs.map((u) => Object.fromEntries(u.searchParams));
}

describe('/items — the live view (B-1 as amended)', () => {
  it('SKU · Name · Unit · Preferred supplier, no quantity column; the SKU links to /items/[id]; no supplier reads "None"', () => {
    renderWired(<ItemsList initial={ok([A1, A2])} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Items' })).toBeInTheDocument();
    expect(headers()).toEqual(['SKU', 'Name', 'Unit', 'Preferred supplier']);
    const r1 = screen.getByText('Name of A-1').closest('tr')!;
    expect(within(r1).getByRole('link', { name: 'A-1' })).toHaveAttribute('href', `/items/${A1.id}`);
    expect(within(r1).getAllByRole('cell').map((c) => c.textContent)).toEqual(['A-1', 'Name of A-1', 'EA', 'Acme Supplies']);
    expect(within(screen.getByText('Name of A-2').closest('tr')!).getAllByRole('cell').map((c) => c.textContent)).toEqual(['A-2', 'Name of A-2', 'KG', 'None']);
    // No quantity anywhere in the list (R1-5): the served "10.000000" / "5.500000" and their renderings are absent.
    expect(document.body.textContent).not.toMatch(/10\.000000|5\.5|\b10\b/);
    // RED M-3 c: live rows are not struck.
    for (const r of document.querySelectorAll('tbody tr')) expect(r.className).not.toBe(styles.struck);
    expect(screen.queryByRole('caption')).toBeNull();
    expect(document.querySelector('caption')).toBeNull();
    expect(screen.getByText('2 shown')).toBeInTheDocument();
  });

  it('the search box says what it matches, tied to it: SKU only, from the start, capitals and spaces count (R1-14)', () => {
    renderWired(<ItemsList initial={ok([A1])} />);
    const box = screen.getByRole('searchbox', { name: 'Search by SKU' });
    expect(box).toHaveAttribute('placeholder', 'Start of a SKU');
    expect(box).toHaveAccessibleDescription("Item names can't be searched. SKUs are matched as typed, so capitals and spaces count.");
  });

  it('I-1 renders no link to /items/new and no New item / Create item, with rows or in any empty state (L-11, R1-16)', () => {
    for (const initial of [ok([A1]), ok([], { tenantHasRows: false }), ok([], { tenantHasRows: true })]) {
      const { unmount } = renderWired(<ItemsList initial={initial} />);
      expect(document.querySelector('a[href="/items/new"]')).toBeNull();
      expect(screen.queryByRole('link', { name: /new item|create item/i })).toBeNull();
      expect(screen.queryByRole('button', { name: /new item|create item/i })).toBeNull();
      unmount();
    }
  });

  it('empty, nothing in the workspace: "No items yet." and nothing offered (R1-16)', () => {
    renderWired(<ItemsList initial={ok([], { tenantHasRows: false })} />);
    expect(screen.getByText('No items yet.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /view archived/i })).toBeNull();
  });

  it('empty, all archived: the ST-9a sentence and View archived items, which switches views', async () => {
    const calls = stubFetch([json(200, page([Z9]))]);
    const u = userEvent.setup();
    renderWired(<ItemsList initial={ok([], { tenantHasRows: true })} />);
    expect(screen.getByText("No items to show. Every item here has been deleted, and they're in the archived items.")).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'View archived items' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 1, name: 'Archived items' })).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ archived: 'true' }]);
  });

  it('a search with no match: its sentence, the name note and Clear search, which reads the unsearched first page', async () => {
    const calls = stubFetch([json(200, page([], { tenant_has_rows: true })), json(200, page([A1]))]);
    const u = userEvent.setup();
    renderWired(<ItemsList initial={ok([A1])} />);
    await u.type(screen.getByRole('searchbox'), 'widget');
    await settle();
    await waitFor(() => expect(screen.getByText("No item's SKU starts with “widget”.", { selector: 'p' })).toBeInTheDocument());
    expect(screen.getByText("Item names can't be searched.")).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Clear search' }));
    await waitFor(() => expect(screen.getByText('Name of A-1')).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ q: 'widget' }, {}]);
  });
});

describe('/items — the Archived view (B-2 as amended)', () => {
  it('heading, SKU · Name · Archived on (DT-1), struck rows, the caption, Back to items', () => {
    renderWired(<ItemsList initial={ok([Z9, X7])} initialArchived />);
    expect(screen.getByRole('heading', { level: 1, name: 'Archived items' })).toBeInTheDocument();
    expect(headers()).toEqual(['SKU', 'Name', 'Archived on']);
    expect(document.querySelector('caption')?.textContent).toBe('Most recently archived first');
    expect(within(screen.getByText('Name of Z-9').closest('tr')!).getAllByRole('cell').map((c) => c.textContent)).toEqual(['Z-9', 'Name of Z-9', formatDate(Z9.archived_at!, TZ)]);
    expect(screen.getByRole('button', { name: 'Back to items' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Archived' })).toBeNull();
    // RED M-3 c: every archived row carries the core's struck class.
    expect(styles.struck).toEqual(expect.any(String));
    const rows = [...document.querySelectorAll('tbody tr')];
    expect(rows).toHaveLength(2);
    for (const r of rows) expect(r.className).toBe(styles.struck);
    expect(screen.getByRole('searchbox', { name: 'Search archived items by SKU' })).toBeInTheDocument();
  });

  it('server order kept (newest archived first as served, never re-sorted on the client)', () => {
    renderWired(<ItemsList initial={ok([X7, Z9])} initialArchived />);
    expect(screen.getAllByRole('link').map((a) => a.textContent)).toEqual(['X-7', 'Z-9']);
  });

  it('empty states: none archived, and an archived search with no match (with Clear search)', async () => {
    stubFetch([json(200, page([], { tenant_has_rows: true }))]);
    const u = userEvent.setup();
    renderWired(<ItemsList initial={ok([], { tenantHasRows: true })} initialArchived />);
    expect(screen.getByText('No archived items. Items that are deleted appear here and can be restored from here.')).toBeInTheDocument();
    await u.type(screen.getByRole('searchbox'), 'Q');
    await settle();
    await waitFor(() => expect(screen.getByText("No archived item's SKU starts with “Q”.", { selector: 'p' })).toBeInTheDocument());
    expect(screen.getByRole('button', { name: 'Clear search' })).toBeInTheDocument();
  });
});

// RED M-2: the settled announcements (UX R1-8) for a first load, a switch and a settle. The first load's sentence is spoken
// when a Retry of a failed first load succeeds (first paint is not announced, ST-2a), so the first-load cases fail the
// server read first and Retry.
describe('/items — the settled announcements (R1-8)', () => {
  it('first load, live, 2 rows: "2 items shown."', async () => {
    stubFetch([json(200, page([A1, A2]))]);
    const u = userEvent.setup();
    renderWired(<ItemsList initial={{ kind: 'server-error' }} />);
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(region()).toHaveTextContent(/^2 items shown\.$/));
  });

  it('first load, Archived, 1 row: "1 archived item shown."', async () => {
    stubFetch([json(200, page([Z9]))]);
    const u = userEvent.setup();
    renderWired(<ItemsList initial={{ kind: 'server-error' }} initialArchived />);
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(region()).toHaveTextContent(/^1 archived item shown\.$/));
  });

  it('switch to Archived, 2 rows: "2 archived items shown."', async () => {
    stubFetch([json(200, page([Z9, X7]))]);
    const u = userEvent.setup();
    renderWired(<ItemsList initial={ok([A1])} />);
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(region()).toHaveTextContent(/^2 archived items shown\.$/));
  });

  it('a settle on "A" answering 1 row: "1 item shown. SKU starts with “A”."', async () => {
    stubFetch([json(200, page([A1]))]);
    const u = userEvent.setup();
    renderWired(<ItemsList initial={ok([A1, B1])} />);
    const { texts, stop } = textsOf(region());
    await u.type(screen.getByRole('searchbox'), 'A');
    await settle();
    await waitFor(() => expect(region()).toHaveTextContent(/^1 item shown\. SKU starts with “A”\.$/));
    stop();
    expect(texts.at(-1)).toBe('1 item shown. SKU starts with “A”.');
  });
});

describe('/items — the wire, the pager and the page sentences', () => {
  it('a walk through every transition: each request has no limit, one cursor at most, q only when not empty', async () => {
    const calls = stubFetch([
      json(200, page([A1, A2], { next_cursor: 'n1' })), // settle q=A
      json(200, page([B1], { previous_cursor: 'p1' })), // Next (after=n1, q=A)
      json(200, page([A1, A2], { next_cursor: 'n1' })), // Previous (before=p1, q=A)
      json(200, page([Z9], {})), // switch to Archived with the box's text
      json(200, page([A1], {})), // Back to items
    ]);
    const u = userEvent.setup();
    renderWired(<ItemsList initial={ok([A1, A2, B1], { nextCursor: 'n0' })} />);
    await u.type(screen.getByRole('searchbox'), 'A');
    await settle();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Next' })).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(screen.getByText('Name of B-1')).toBeInTheDocument());
    await waitFor(() => expect(region()).toHaveTextContent('1 item shown, B-1. SKU starts with “A”.'));
    await u.click(screen.getByRole('button', { name: 'Previous' }));
    await waitFor(() => expect(region()).toHaveTextContent('2 items shown, A-1 to A-2. SKU starts with “A”.'));
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 1, name: 'Archived items' })).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Back to items' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 1, name: 'Items' })).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ q: 'A' }, { q: 'A', after: 'n1' }, { q: 'A', before: 'p1' }, { archived: 'true', q: 'A' }, { q: 'A' }]);
    expect(nav.replace.mock.calls.map((c) => c[0])).toEqual(['/items?archived=true', '/items']);
  });

  it('live pager: Previous / Next, each only with its cursor; no page number, no total', () => {
    renderWired(<ItemsList initial={ok([A1], { nextCursor: 'n' })} />);
    expect(screen.getByRole('button', { name: 'Next' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Previous' })).toBeNull();
    expect(document.body.textContent).not.toMatch(/page \d|of \d/i);
  });

  it('Archived pager: Newer / Older, Older being the next page; its page sentence names dates, not an SKU range (F-2)', async () => {
    const calls = stubFetch([json(200, page([Z9, Y8], { previous_cursor: 'p' })), json(200, page([X7], { previous_cursor: 'p2' }))]);
    const u = userEvent.setup();
    renderWired(<ItemsList initial={ok([X7], { nextCursor: 'o1' })} initialArchived />);
    expect(screen.queryByRole('button', { name: 'Newer' })).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(region()).toHaveTextContent(`2 archived items shown, archived on ${formatDate(Z9.archived_at!, TZ)}, starting with Z-9.`));
    // A second full page of the same size carries a different sentence (the cue, M-6 / F-M1), and one row its own form.
    await u.click(screen.getByRole('button', { name: 'Newer' }));
    await waitFor(() => expect(region()).toHaveTextContent(`1 archived item shown, X-7, archived on ${formatDate(X7.archived_at!, TZ)}.`));
    expect(listRequests(calls)).toEqual([{ archived: 'true', after: 'o1' }, { archived: 'true', before: 'p' }]);
  });

  it('Archived page with differing dates: "archived {newest} to {oldest}, starting with {firstSku}."', async () => {
    stubFetch([json(200, page([Z9, X7]))]);
    const u = userEvent.setup();
    renderWired(<ItemsList initial={ok([Y8], { nextCursor: 'o1' })} initialArchived />);
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await waitFor(() => expect(region()).toHaveTextContent(`2 archived items shown, archived ${formatDate(Z9.archived_at!, TZ)} to ${formatDate(X7.archived_at!, TZ)}, starting with Z-9.`));
  });

  it('the empty page a cursor reached: its own heading and Back to the first page, which sends no cursor', async () => {
    const calls = stubFetch([json(200, page([], { tenant_has_rows: true })), json(200, page([A1]))]);
    const u = userEvent.setup();
    renderWired(<ItemsList initial={ok([A1], { nextCursor: 'n' })} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(screen.getByText('There are no more items here.', { selector: 'p' })).toBeInTheDocument());
    expect(screen.getByText('The list changed after the page you were on was loaded.')).toBeInTheDocument();
    expect(screen.queryByText('No items yet.')).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Back to the first page' }));
    await waitFor(() => expect(screen.getByText('Name of A-1')).toBeInTheDocument());
    expect(listRequests(calls)).toEqual([{ after: 'n' }, {}]);
  });

  it('a malformed answer is a failure, never rows: an item missing a number reads as the refetch-failed banner', async () => {
    const bad = { ...A2 } as Partial<Item>;
    delete bad.safety_stock;
    stubFetch([json(200, page([bad]))]);
    const u = userEvent.setup();
    renderWired(<ItemsList initial={ok([A1], { nextCursor: 'n' })} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(screen.getByText("The list couldn't be updated, so it still shows the earlier results.")).toBeInTheDocument());
    expect(screen.queryByText('Name of A-2')).toBeNull();
  });
});
