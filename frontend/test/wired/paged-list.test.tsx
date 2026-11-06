import { act, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createRef } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ArchivableList, ArchivedToggle, SEARCH_SETTLE_MS, type ArchivableListHandle, type PagedListProps, type PagedLoad, type Paging } from '@/app/(app)/ArchivableList';
import { Button } from '@/components/ui/Button';
import { pageQuery, type PageRequest, type Read } from '@/lib/bff/reads';
import { json, page, renderWired, stubFetch, textsOf, type Recorded, type Reply } from './harness';

// Items I-0b (b4-architecture.md Revision 8 R8-1; items-plan.md §4 I-0b, R1-4 and Revision 1 — FINAL F-2): paging in the
// list core. No product page exists yet, so the call site is a TEST fixture family read through the real browser
// transport, its loader built on the shipped `pageQuery()`. It names `ListWarehouses` only because that is a wired list
// read (`ListItems` joins the client operations at I-1); the answers are the test's own pages, so the server's paging
// of warehouses plays no part. Every answer is a deferred fetch
// resolved by hand (F-M4), so what the list does while a read is in flight is asserted, not inferred. Each test names
// the failure it guards; its mutation is recorded in docs/design/items-i0b-progress.md.

afterEach(() => vi.unstubAllGlobals());

interface Thing {
  id: string;
  archived_at: string | null;
  sku: string;
}
const live = (sku: string): Thing => ({ id: `l-${sku}`, archived_at: null, sku });
const gone = (sku: string, at: string): Thing => ({ id: `a-${sku}`, archived_at: at, sku });

/** The fixture family's loader: what `lib/items/load.ts` will be — `pageQuery()` over the read, the cursors off the wire. */
async function loadThings(read: Read, archived: boolean, p: PageRequest, signal: AbortSignal): Promise<PagedLoad<Thing>> {
  const a = await read('ListWarehouses', { query: pageQuery(archived, p), signal });
  if (a.kind !== 'ok') return a;
  const b = a.body as { items: Thing[]; next_cursor: string | null; previous_cursor: string | null; tenant_has_rows: boolean };
  return { kind: 'ok', items: b.items, nextCursor: b.next_cursor, previousCursor: b.previous_cursor, tenantHasRows: b.tenant_has_rows };
}

/** Every request, held open until the test answers it (F-M4). */
function deferredServer() {
  const open: { url: URL; answer: (r: Response) => Promise<void> }[] = [];
  const sent: URL[] = [];
  const replies: Reply[] = Array.from({ length: 40 }, () => (req: Recorded) =>
    new Promise<Response>((resolve) => {
      const url = new URL(req.url, 'http://localhost');
      sent.push(url);
      open.push({
        url,
        answer: async (r) => {
          await act(async () => {
            resolve(r);
            await new Promise((t) => setTimeout(t, 30));
          });
        },
      });
    }),
  );
  stubFetch(replies);
  const oldest = async () => {
    await vi.waitFor(() => expect(open.length).toBeGreaterThan(0));
    return open.shift()!;
  };
  return {
    sent,
    /** Answers the oldest open request with a page of rows (waiting for it to be sent: a reread is sent a tick later). */
    answer: async (items: Thing[], over: Record<string, unknown> = {}) => (await oldest()).answer(json(200, page(items, over))),
    fail: async () => (await oldest()).answer(json(500, {})),
    openCount: () => open.length,
  };
}
const params = (u: URL) => Object.fromEntries(u.searchParams);
const settle = () => act(() => new Promise((r) => setTimeout(r, SEARCH_SETTLE_MS + 100)));

const date = (iso: string | null) => (iso ?? '').slice(0, 10);
const PAGING: Paging<Thing> = {
  pager: (archived) => (archived ? { previous: 'Newer', next: 'Older' } : { previous: 'Previous', next: 'Next' }),
  pageShown: (rows, q, archived) => {
    const first = rows[0]!;
    const last = rows[rows.length - 1]!;
    const cue = archived
      ? date(first.archived_at) === date(last.archived_at)
        ? `${rows.length} archived things shown, archived on ${date(first.archived_at)}, starting with ${first.sku}.`
        : `${rows.length} archived things shown, archived ${date(first.archived_at)} to ${date(last.archived_at)}, starting with ${first.sku}.`
      : `${rows.length} things shown, ${first.sku} to ${last.sku}.`;
    return q !== '' ? `${cue} SKU starts with “${q}”.` : cue;
  },
  noMore: (archived) => ({
    heading: archived ? 'There are no more archived things here.' : 'There are no more things here.',
    body: 'The list changed after the page you were on was loaded.',
    action: 'Back to the first page',
  }),
};

type Fixture = Partial<Omit<PagedListProps<Thing>, 'initial'>> & { initial: PagedLoad<Thing> };
function PagedFixture(props: Fixture) {
  return (
    <ArchivableList<Thing>
      paging={PAGING}
      load={loadThings}
      columns={() => [{ header: 'SKU', cell: (t: Thing) => t.sku }]}
      copy={{
        heading: (archived) => (archived ? 'Archived things' : 'Things'),
        archived: 'Archived',
        loadFailed: "The list of things couldn't be loaded.",
        shown: (n, q, archived) => [`${n} ${archived ? 'archived things' : 'things'} shown.`, q !== '' ? `SKU starts with “${q}”.` : null].filter(Boolean).join(' '),
      }}
      search={{ label: 'Search by SKU', placeholder: 'Start of a SKU' }}
      empty={({ archived, q, clearSearch }) =>
        q !== ''
          ? { heading: `No thing's SKU starts with “${q}”.`, actions: <Button onClick={clearSearch}>Clear search</Button> }
          : { heading: archived ? 'No archived things.' : 'No things yet.' }
      }
      archivedView={{ order: 'as-served', caption: 'Most recently archived first', rows: 'struck' }}
      denied={<p>You can’t see things.</p>}
      header={({ archived, busy, loaded, switchTo, viewControlRef }) =>
        loaded && archived ? (
          <Button ref={viewControlRef} onClick={() => switchTo(false)}>
            Back to things
          </Button>
        ) : loaded ? (
          <ArchivedToggle ref={viewControlRef} archived={archived} busy={busy} onClick={() => switchTo(true)} label="Archived" />
        ) : null
      }
      {...props}
    />
  );
}

const first = (items: Thing[], next: string | null = null, previous: string | null = null): PagedLoad<Thing> => ({ kind: 'ok', items, nextCursor: next, previousCursor: previous, tenantHasRows: true });
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const skus = () => [...document.querySelectorAll('tbody tr')].map((r) => r.textContent);
const box = () => screen.getByRole<HTMLInputElement>('searchbox', { name: 'Search by SKU' });

describe('R8-1 item 2 — only a page press (or a Retry repeating one) carries a cursor', () => {
  it('after a page press, each of the four transitions sends no after/before: live→archived, archived→live, a settled search, Clear search', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1'), live('A2')], 'c-live-1')} />);

    await u.click(screen.getByRole('button', { name: 'Next' }));
    await srv.answer([live('B1')], { previous_cursor: 'c-live-0' });
    await u.click(screen.getByRole('button', { name: 'Archived' })); // live → archived
    await srv.answer([gone('Z1', '2026-10-02T00:00:00Z')], { next_cursor: 'c-arch-1' });
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await srv.answer([gone('Z2', '2026-10-01T00:00:00Z')], { previous_cursor: 'c-arch-0' });
    await u.click(screen.getByRole('button', { name: 'Back to things' })); // archived → live
    await srv.answer([live('A1')], { next_cursor: 'c-live-1' });
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await srv.answer([live('B1')], { previous_cursor: 'c-live-0' });
    await u.type(box(), 'B');
    await settle(); // a settled search
    await srv.answer([live('B1')], { next_cursor: 'c-b-1' });
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await srv.answer([live('B2')], { previous_cursor: 'c-b-0' });
    await u.clear(box());
    await settle(); // the empty box settles: Clear search's request
    await srv.answer([live('A1')]);

    expect(srv.sent.map(params)).toEqual([
      { after: 'c-live-1' },
      { archived: 'true' },
      { archived: 'true', after: 'c-arch-1' },
      {},
      { after: 'c-live-1' },
      { q: 'B' },
      { q: 'B', after: 'c-b-1' },
      {},
    ]);
  });

  it('the empty state’s Clear search reads the unsearched first page at once, with no cursor', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')], 'c-1')} />);
    await u.type(box(), 'Q');
    await settle();
    await srv.answer([]);
    await u.click(screen.getByRole('button', { name: 'Clear search' }));
    expect(srv.sent.map(params)).toEqual([{ q: 'Q' }, {}]);
    await srv.answer([live('A1')], { next_cursor: 'c-1' });
    expect(box().value).toBe('');
    expect(skus()).toEqual(['A1']);
  });

  it('a banner Retry after a failed Next repeats it exactly (same view, q and after)', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')], 'c-1')} />);
    await u.type(box(), 'A');
    await settle();
    await srv.answer([live('A1')], { next_cursor: 'c-a-1' });
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await srv.fail();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    expect(srv.sent.map(params)).toEqual([{ q: 'A' }, { q: 'A', after: 'c-a-1' }, { q: 'A', after: 'c-a-1' }]);
    // The repeated read is a page read by its cursor, so it speaks the position cue (RED L-1, P15).
    await srv.answer([live('A2'), live('A3')], { previous_cursor: 'c-a-0' });
    expect(region()).toHaveTextContent('2 things shown, A2 to A3. SKU starts with “A”.');
  });

  it('limit is never sent, and after and before never together (every request of a long run)', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')], 'n1')} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await srv.answer([live('B1')], { next_cursor: 'n2', previous_cursor: 'p1' });
    await u.click(screen.getByRole('button', { name: 'Previous' }));
    await srv.answer([live('A1')], { next_cursor: 'n1' });
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await srv.answer([gone('Z', '2026-10-01T00:00:00Z')]);
    expect(srv.sent).toHaveLength(3);
    for (const url of srv.sent) {
      expect(url.searchParams.has('limit'), url.search).toBe(false);
      expect(url.searchParams.has('after') && url.searchParams.has('before'), url.search).toBe(false);
    }
    expect(params(srv.sent[1]!)).toEqual({ before: 'p1' });
  });
});

describe('R8-1 item 4 — server search: a settle reads, it never speaks from the rows on screen', () => {
  it('a settle during an in-flight Next waits, and the list ends on the rows for the text in the box', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')], 'c-1')} />);
    const texts = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await u.type(box(), 'K');
    await settle(); // settles while Next is still open
    expect(srv.openCount()).toBe(1);
    await srv.answer([live('B1')], { previous_cursor: 'c-0' });
    expect(srv.sent.map(params)).toEqual([{ after: 'c-1' }, { q: 'K' }]);
    await srv.answer([live('K1'), live('K2')]);
    expect(skus()).toEqual(['K1', 'K2']);
    expect(box().value).toBe('K');
    // The Next the search waited for spoke nothing: its rows were about to be replaced (RED M-3, P3).
    expect(texts.texts).toEqual(['', '2 things shown. SKU starts with “K”.']);
    texts.stop();
  });

  it('a search that settles behind a read that then fails is still read, and its answer ends the banner (never dropped)', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')], 'c-1')} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await u.type(box(), 'K');
    await settle();
    await srv.fail();
    expect(srv.sent.map(params)).toEqual([{ after: 'c-1' }, { q: 'K' }]);
    await srv.answer([live('K1')]);
    expect(skus()).toEqual(['K1']);
    expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull();
  });

  it('the mount settle and a type-and-erase read nothing (RED L-1, P1)', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')], 'c-1')} />);
    await settle();
    await u.type(box(), 'x');
    await u.type(box(), '{Backspace}');
    await settle();
    expect(srv.sent).toHaveLength(0);
  });

  it('a switch reads the text in the box and settles it, so the later settle reads nothing more (RED L-1, P14)', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')], 'c-1')} />);
    await u.type(box(), 'Z');
    await u.click(screen.getByRole('button', { name: 'Archived' })); // before the box settles
    await srv.answer([gone('Z1', '2026-10-01T00:00:00Z')]);
    await settle();
    expect(srv.sent.map(params)).toEqual([{ archived: 'true', q: 'Z' }]);
  });

  it('a queued search equal to the one in flight is not read again: A in flight, then AB, then back to A → [A] (RED L-1, P21)', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')])} />);
    await u.type(box(), 'A');
    await settle();
    await u.type(box(), 'B');
    await settle();
    await u.type(box(), '{Backspace}');
    await settle();
    await srv.answer([live('A1')]);
    await act(() => new Promise((r) => setTimeout(r, 50)));
    expect(srv.sent.map(params)).toEqual([{ q: 'A' }]);
    expect(srv.openCount()).toBe(0);
  });

  it('the region is blank while the settle’s read is open, and speaks only when that read succeeds', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1'), live('A2')], 'c-1')} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await srv.answer([live('B1'), live('B2')], { previous_cursor: 'c-0' });
    expect(region()).toHaveTextContent('2 things shown, B1 to B2.');
    await u.type(box(), 'A');
    await settle();
    expect(srv.openCount()).toBe(1);
    expect(region().textContent).toBe('');
    await srv.answer([live('A1'), live('A2')]);
    expect(region()).toHaveTextContent('2 things shown. SKU starts with “A”.');
  });

  it('three settles — A in flight, then B, then C — send exactly [A, C], and the list ends on C’s rows', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')])} />);
    await u.type(box(), 'A');
    await settle();
    await u.type(box(), 'B');
    await settle();
    await u.type(box(), 'C');
    await settle();
    expect(srv.openCount()).toBe(1);
    await srv.answer([live('A1')]);
    await srv.answer([live('ABC1')]);
    expect(srv.sent.map(params)).toEqual([{ q: 'A' }, { q: 'ABC' }]);
    expect(srv.openCount()).toBe(0);
    expect(skus()).toEqual(['ABC1']);
  });

  it('with unsettled text in the box, Next sends the settled q', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')], 'c-1')} />);
    await u.type(box(), 'AB');
    await settle();
    await srv.answer([live('AB1')], { next_cursor: 'c-ab-1' });
    await u.type(box(), 'C'); // not settled
    await u.click(screen.getByRole('button', { name: 'Next' }));
    expect(params(srv.sent[1]!)).toEqual({ q: 'AB', after: 'c-ab-1' });
  });

  it('the list shows exactly the rows served, even rows whose SKU does not start with the search (no client filter)', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')])} />);
    await u.type(box(), 'ab');
    await settle();
    await srv.answer([live('ZZ-9'), live('AB-1'), live('x')]);
    expect(skus()).toEqual(['ZZ-9', 'AB-1', 'x']);
  });

  it('the search is sent as typed: untrimmed, capitals kept', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')])} />);
    await u.type(box(), ' Ab ');
    await settle();
    expect(srv.sent[0]!.searchParams.get('q')).toBe(' Ab ');
  });
});

describe('R8-1 items 3 and 6 — pager per view, position cue, focus', () => {
  it('each button shows only with its cursor; live reads Previous / Next, Archived Newer / Older with Older on next_cursor', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')], 'n1')} />);
    expect(screen.getByRole('button', { name: 'Next' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Previous' })).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await srv.answer([live('B1')], { previous_cursor: 'p1' });
    expect(screen.getByRole('button', { name: 'Previous' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Next' })).toBeNull();

    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await srv.answer([gone('Z1', '2026-10-03T00:00:00Z')], { next_cursor: 'older-1', previous_cursor: 'newer-1' });
    expect(screen.queryByRole('button', { name: 'Next' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Previous' })).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await srv.answer([gone('Z2', '2026-10-02T00:00:00Z')], { next_cursor: 'older-2', previous_cursor: 'newer-2' });
    await u.click(screen.getByRole('button', { name: 'Newer' }));
    expect(srv.sent.slice(2).map(params)).toEqual([
      { archived: 'true', after: 'older-1' },
      { archived: 'true', before: 'newer-2' },
    ]);
  });

  it('two full pages of equal size give different region text, in each view (Archived: both pages on one date)', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1'), live('A2')], 'n1')} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await srv.answer([live('B1'), live('B2')], { next_cursor: 'n2', previous_cursor: 'p1' });
    const livePage2 = region().textContent;
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await srv.answer([live('C1'), live('C2')], { previous_cursor: 'p2' });
    expect(region().textContent).not.toBe(livePage2);
    expect(region()).toHaveTextContent('2 things shown, C1 to C2.');

    const day = '2026-10-01T09:00:00Z';
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await srv.answer([gone('Y1', day), gone('Y2', day)], { next_cursor: 'o1' });
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await srv.answer([gone('X1', day), gone('X2', day)], { next_cursor: 'o2', previous_cursor: 'w1' });
    const archivedPage2 = region().textContent;
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await srv.answer([gone('W1', day), gone('W2', day)], { previous_cursor: 'w2' });
    expect(region().textContent).not.toBe(archivedPage2);
    expect(region()).toHaveTextContent('2 archived things shown, archived on 2026-10-01, starting with W1.');
  });

  it('after a page press focus stays on the pressed button while it is still rendered, else goes to the list heading', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')], 'n1')} />);
    const next = screen.getByRole('button', { name: 'Next' });
    await u.click(next);
    await srv.answer([live('B1')], { next_cursor: 'n2', previous_cursor: 'p1' });
    expect(document.activeElement).toBe(next);
    expect(next.isConnected).toBe(true);
    await u.click(next);
    await srv.answer([live('C1')], { previous_cursor: 'p2' });
    expect(next.isConnected).toBe(false);
    expect(document.activeElement).toBe(screen.getByRole('heading', { level: 1, name: 'Things' }));
  });

  it('a page press during any read is ignored, as a switch is', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')], 'n1')} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    // Loads run one after another, so an accepted press would only be sent once the first read ends: end it, then look.
    await srv.answer([live('B1')], { next_cursor: 'n2', previous_cursor: 'p1' });
    await act(() => new Promise((r) => setTimeout(r, 50)));
    expect(srv.sent).toHaveLength(1);
    expect(srv.openCount()).toBe(0);
    expect(skus()).toEqual(['B1']);
  });
});

describe('RED H-1 — no pager while the rows answer a search other than the settled one', () => {
  it('a failed search withdraws the pager; Retry reads that search, and on success the rows answer it and the pager returns', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')], 'c-1')} />);
    await u.type(box(), 'B');
    await settle();
    await srv.fail();
    expect(skus()).toEqual(['A1']);
    expect(screen.queryByRole('button', { name: 'Next' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Previous' })).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    expect(srv.sent.map(params)).toEqual([{ q: 'B' }, { q: 'B' }]);
    await srv.answer([live('B1')], { next_cursor: 'c-b-1' });
    expect(skus()).toEqual(['B1']);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    expect(params(srv.sent[2]!)).toEqual({ q: 'B', after: 'c-b-1' });
  });

  it('a failed switch with a search in the box withdraws the pager of the rows still shown', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')], 'c-1')} />);
    await u.type(box(), 'Z');
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await srv.fail();
    expect(skus()).toEqual(['A1']);
    expect(screen.queryByRole('button', { name: 'Next' })).toBeNull();
  });
});

describe('R8-1 item 5 — an empty page reached by a cursor is the core’s own state', () => {
  for (const [label, pager, cursor] of [
    ['after', 'Next', { after: 'n1' }],
    ['before', 'Previous', { before: 'p1' }],
  ] as const) {
    it(`an empty \`${label}\` page: no ST-2 heading, its own heading takes focus and is spoken; Back to the first page sends the settled q and no cursor`, async () => {
      const srv = deferredServer();
      const u = userEvent.setup();
      renderWired(<PagedFixture initial={first([live('A1')], 'n0')} />);
      await u.type(box(), 'A');
      await settle();
      await srv.answer([live('A1')], { next_cursor: 'n1', previous_cursor: 'p1' });
      await u.click(screen.getByRole('button', { name: pager }));
      await srv.answer([], { tenant_has_rows: true });
      expect(params(srv.sent[1]!)).toEqual({ q: 'A', ...cursor });
      expect(screen.queryByText('No thing’s SKU starts with “A”.')).toBeNull();
      expect(screen.queryByText("No thing's SKU starts with “A”.")).toBeNull();
      const heading = screen.getByText('There are no more things here.', { selector: 'p' });
      expect(document.activeElement).toBe(heading);
      expect(screen.getByText('The list changed after the page you were on was loaded.')).toBeInTheDocument();
      expect(region()).toHaveTextContent('There are no more things here.');
      expect(screen.queryByRole('button', { name: 'Next' })).toBeNull();
      await u.click(screen.getByRole('button', { name: 'Back to the first page' }));
      expect(params(srv.sent[2]!)).toEqual({ q: 'A' });
      await srv.answer([live('A1')], { next_cursor: 'n1' });
      expect(skus()).toEqual(['A1']);
    });
  }

  it('Back to the first page sends the settled search after that search failed on an empty page, and the pager then works', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    renderWired(<PagedFixture initial={first([live('A1')], 'n1')} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await srv.answer([], { tenant_has_rows: true });
    expect(screen.getByText('There are no more things here.', { selector: 'p' })).toBeInTheDocument();
    await u.type(box(), 'B');
    await settle();
    await srv.fail();
    await u.click(screen.getByRole('button', { name: 'Back to the first page' }));
    expect(params(srv.sent[srv.sent.length - 1]!)).toEqual({ q: 'B' });
    await srv.answer([live('B1')], { next_cursor: 'nb1' });
    expect(skus()).toEqual(['B1']);
    expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Next' }));
    expect(params(srv.sent[srv.sent.length - 1]!)).toEqual({ q: 'B', after: 'nb1' });
  });

  it('a reread that comes back empty under a cursor lands in the same state, and speaks nothing', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    const controller = createRef<ArchivableListHandle>();
    renderWired(<PagedFixture initial={first([live('A1')], 'n1')} controller={controller} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await srv.answer([live('B1')], { previous_cursor: 'p1' });
    act(() => screen.getByRole('button', { name: 'Previous' }).focus());
    const said = region().textContent;
    let done: Promise<unknown> = Promise.resolve();
    act(() => void (done = controller.current!.reread()));
    await srv.answer([]);
    await act(async () => void (await done));
    expect(params(srv.sent[1]!)).toEqual({ after: 'n1' });
    // The reread removed the pager that held focus: focus goes to the state's heading, and nothing is announced (RED M-1).
    expect(document.activeElement).toBe(screen.getByText('There are no more things here.', { selector: 'p' }));
    expect(region().textContent).toBe(said);
  });

  it('a reread that removes the focused page button on a page that still has rows moves focus to the list heading, and speaks nothing (RED L-6)', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    const controller = createRef<ArchivableListHandle>();
    renderWired(<PagedFixture initial={first([live('A1')], 'n1')} controller={controller} />);
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await srv.answer([live('B1')], { next_cursor: 'n2', previous_cursor: 'p1' });
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Next' }));
    const said = region().textContent;
    let done: Promise<unknown> = Promise.resolve();
    act(() => void (done = controller.current!.reread()));
    await srv.answer([live('B1')], { previous_cursor: 'p1' });
    await act(async () => void (await done));
    expect(screen.queryByRole('button', { name: 'Next' })).toBeNull();
    expect(skus()).toEqual(['B1']);
    expect(document.activeElement).toBe(screen.getByRole('heading', { level: 1, name: 'Things' }));
    expect(region().textContent).toBe(said);
  });

  it('a page press while a reread is open is ignored (RED M-2)', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    const controller = createRef<ArchivableListHandle>();
    renderWired(<PagedFixture initial={first([live('A1')], 'n1')} controller={controller} />);
    let done: Promise<unknown> = Promise.resolve();
    act(() => void (done = controller.current!.reread()));
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await srv.answer([live('A1')], { next_cursor: 'n1' });
    await act(async () => void (await done));
    await act(() => new Promise((r) => setTimeout(r, 50)));
    expect(srv.sent.map(params)).toEqual([{}]);
    expect(srv.openCount()).toBe(0);
  });
});

describe('R8-1 — reread, the Archived caption, the address', () => {
  it('reread keeps the cursor, q and view, resolves after the rows commit, and leaves the region unchanged', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    const controller = createRef<ArchivableListHandle>();
    renderWired(<PagedFixture initial={first([live('A1')], 'n1')} controller={controller} />);
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await srv.answer([gone('Z1', '2026-10-02T00:00:00Z')], { next_cursor: 'o1' });
    await u.type(box(), 'Z');
    await settle();
    await srv.answer([gone('Z1', '2026-10-02T00:00:00Z')], { next_cursor: 'oz1' });
    await u.click(screen.getByRole('button', { name: 'Older' }));
    await srv.answer([gone('Z2', '2026-10-01T00:00:00Z')], { previous_cursor: 'wz1' });
    const texts = textsOf(region());
    let rowsAtResolve: (string | null)[] = [];
    let done: Promise<unknown> = Promise.resolve();
    act(() => void (done = controller.current!.reread().then((r) => ((rowsAtResolve = skus()), r))));
    await srv.answer([gone('Z3', '2026-10-01T00:00:00Z')], { previous_cursor: 'wz1' });
    expect(await act(async () => await done)).toEqual({ kind: 'ok' });
    expect(params(srv.sent[3]!)).toEqual({ archived: 'true', q: 'Z', after: 'oz1' });
    expect(rowsAtResolve).toEqual(['Z3']);
    expect(texts.texts).toHaveLength(1);
    texts.stop();
  });

  it('the paged Archived view keeps the server order and renders its caption; a whole-view newest-deleted-first list re-sorts (positive control)', async () => {
    renderWired(<PagedFixture initial={first([gone('OLD', '2026-01-01T00:00:00Z'), gone('NEW', '2026-10-01T00:00:00Z')])} initialArchived />);
    const table = screen.getByRole('table');
    expect(within(table).getByText('Most recently archived first').tagName).toBe('CAPTION');
    expect(skus()).toEqual(['OLD', 'NEW']);
  });

  it('positive control: a whole-view list with newest-deleted-first sorts the same rows', () => {
    renderWired(
      <ArchivableList<Thing>
        initial={{ kind: 'ok', items: [gone('OLD', '2026-01-01T00:00:00Z'), gone('NEW', '2026-10-01T00:00:00Z')] }}
        initialArchived
        load={async () => ({ kind: 'ok', items: [] })}
        columns={() => [{ header: 'SKU', cell: (t: Thing) => t.sku }]}
        copy={{ heading: 'Things', archived: 'Archived', loadFailed: 'x', shown: (n) => `${n} shown.` }}
        search={false}
        empty={() => null}
        archivedView={{ order: 'newest-deleted-first', caption: 'Newest first', rows: 'plain' }}
        denied={<p>no</p>}
      />,
    );
    expect(skus()).toEqual(['NEW', 'OLD']);
  });

  it('cursors and the search never leave the list: onViewLoaded is told only the view, and a fresh mount opens page 1 with an empty box', async () => {
    const srv = deferredServer();
    const u = userEvent.setup();
    const onViewLoaded = vi.fn();
    const initial = first([live('A1')], 'n1');
    const { unmount } = renderWired(<PagedFixture initial={initial} onViewLoaded={onViewLoaded} />);
    await u.type(box(), 'A');
    await settle();
    await srv.answer([live('A1')], { next_cursor: 'na1' });
    await u.click(screen.getByRole('button', { name: 'Next' }));
    await srv.answer([live('A2')], { previous_cursor: 'pa1' });
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await srv.answer([gone('Z', '2026-10-01T00:00:00Z')]);
    expect(onViewLoaded.mock.calls).toEqual([[true]]);
    unmount();
    renderWired(<PagedFixture initial={initial} onViewLoaded={onViewLoaded} />);
    expect(box().value).toBe('');
    expect(skus()).toEqual(['A1']);
    expect(screen.queryByRole('button', { name: 'Previous' })).toBeNull();
  });
});

describe('R8-1 item 1 — the typed shape', () => {
  it('a paged list cannot be given a client filter, nor a client re-sort of its Archived view (compile-time)', () => {
    const base = { initial: first([]), load: loadThings, paging: PAGING, columns: () => [], copy: { heading: 'x', archived: 'x', loadFailed: 'x', shown: () => 'x' }, empty: () => null, denied: null } as const;
    const valid: PagedListProps<Thing> = { ...base, archivedView: { order: 'as-served', caption: 'c', rows: 'plain' } };
    // @ts-expect-error — `matches` is typed out of a paged list (R8-1 item 1).
    const withMatches: PagedListProps<Thing> = { ...base, archivedView: { order: 'as-served', caption: 'c', rows: 'plain' }, matches: () => true };
    // @ts-expect-error — a paged list's Archived view keeps the server order (R8-1 item 7).
    const reSorted: PagedListProps<Thing> = { ...base, archivedView: { order: 'newest-deleted-first', caption: 'c', rows: 'plain' } };
    expect([valid, withMatches, reSorted]).toHaveLength(3);
  });

  it('pageQuery: the view, q as typed when not empty, at most one cursor, never limit', () => {
    expect(pageQuery(false, { q: '', cursor: null }).toString()).toBe('');
    expect(pageQuery(true, { q: ' a B', cursor: { after: 'x' } }).toString()).toBe('archived=true&q=+a+B&after=x');
    expect(pageQuery(false, { q: 'A', cursor: { before: 'y' } }).toString()).toBe('q=A&before=y');
  });
});
