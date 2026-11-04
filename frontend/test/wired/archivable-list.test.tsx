import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createRef, type ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

// The users wrapper keeps its view in the address; the core list must not (Revision 7 A-17 item 1).
const nav = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: nav.replace }), usePathname: () => '/admin/users' }));
import { ArchivableList, ArchivedToggle, type ArchivableListHandle, type ArchivableListProps, type ListLoad } from '@/app/(app)/ArchivableList';
import { UsersList } from '@/app/(app)/admin/users/UsersList';
import { Button } from '@/components/ui/Button';
import styles from '@/app/(app)/Lists.module.css';
import { VIEWER_ID, json, page, renderWired, stubFetch, user } from './harness';

// Revision 7 A-17 (b4-architecture-r7-proposal.md Rev 2, as amended by Revision 3 R3-1/R3-2) and the FE-R3 obligations
// owed to this commit (fe-r3-ux.md Revision 1 H-1/H-2). The call sites here are TEST fixtures: a page with a `header`, and a
// Locations-style section (no h1, an h2 named by `headingId`, no search, never in the address). No warehouses code exists
// yet; these fixtures exercise the options the warehouse screens will use.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.replace.mockReset();
});

interface Thing {
  id: string;
  archived_at: string | null;
  code: string;
}
const live = (code: string): Thing => ({ id: `l-${code}`, archived_at: null, code });
const gone = (code: string, at: string): Thing => ({ id: `a-${code}`, archived_at: at, code });

type Answer = ListLoad<Thing>;
/** What a fixture call site may override (the search option is fixed per fixture). */
type FixtureProps = Partial<Omit<ArchivableListProps<Thing>, 'search' | 'matches'>> & Pick<ArchivableListProps<Thing>, 'initial' | 'load'>;

/** A load whose answers the test hands out one by one, in call order; each call is recorded with its view. */
function scriptedLoad() {
  const calls: { archived: boolean; resolve: (a: Answer) => void }[] = [];
  const load = (_read: unknown, archived: boolean) => new Promise<Answer>((resolve) => calls.push({ archived, resolve }));
  return { calls, load: load as unknown as ArchivableListProps<Thing>['load'] };
}

const ok = (items: Thing[], tenantHasRows?: boolean): Answer => ({ kind: 'ok', items, ...(tenantHasRows === undefined ? {} : { tenantHasRows }) });

const columns = () => [{ header: 'Code', cell: (t: Thing) => t.code }];
const COPY = {
  archived: 'Archived',
  loadFailed: "The list of things couldn't be loaded.",
  shown: (n: number, q: string, archived: boolean) => [`${n} shown.`, q !== '' ? `Starts with “${q}”.` : null, archived ? 'Archived.' : null].filter(Boolean).join(' '),
};
const emptyOf: ArchivableListProps<Thing>['empty'] = ({ archived, q, tenantHasRows, switchTo }) =>
  q !== ''
    ? { heading: `Nothing starts with “${q}”.` }
    : archived
      ? { heading: 'No archived things.', actions: <Button onClick={() => switchTo(false)}>Back to things</Button> }
      : tenantHasRows === true
        ? { heading: 'Every thing is archived.', actions: <Button onClick={() => switchTo(true)}>View archived things</Button> }
        : { heading: 'No things yet.' };

/** Page use with a header: per-view h1, a New action, and the view switch (Back to … only once a view has loaded). */
function PageFixture(props: FixtureProps) {
  return (
    <ArchivableList<Thing>
      matches={(t, lowerQ) => t.code.toLowerCase().startsWith(lowerQ)}
      columns={columns}
      copy={{ ...COPY, heading: (archived) => (archived ? 'Archived things' : 'Things') }}
      search={{ label: (archived) => (archived ? 'Search archived things' : 'Search things'), placeholder: 'Start of a code' }}
      empty={emptyOf}
      archivedView={{ order: 'as-served', rows: 'struck' }}
      denied={<p>You can’t see things.</p>}
      header={({ archived, busy, loaded, switchTo, viewControlRef }) => (
        <>
          {!archived ? <Button>New thing</Button> : null}
          {loaded && archived ? (
            <Button ref={viewControlRef} onClick={() => switchTo(false)}>
              Back to things
            </Button>
          ) : null}
          {loaded && !archived ? <ArchivedToggle ref={viewControlRef} archived={archived} busy={busy} onClick={() => switchTo(true)} label="Archived" /> : null}
        </>
      )}
      {...props}
    />
  );
}

/** Section use (a Locations-style section): no h1, an h2 given as `headingId`, no search, never in the address. */
function SectionFixture(props: FixtureProps) {
  return (
    <ArchivableList<Thing>
      columns={columns}
      copy={{ ...COPY, heading: null }}
      headingId="locations-h"
      search={false}
      empty={emptyOf}
      archivedView={{ order: 'as-served', rows: 'struck' }}
      denied={<p>You can’t see locations.</p>}
      header={({ archived, busy, loaded, switchTo, viewControlRef }) => (
        <>
          <h2 id="locations-h" tabIndex={-1}>
            {archived ? 'Archived locations' : 'Locations'}
          </h2>
          {loaded && archived ? (
            <Button ref={viewControlRef} onClick={() => switchTo(false)}>
              Back to locations
            </Button>
          ) : null}
          {loaded && !archived ? <ArchivedToggle ref={viewControlRef} archived={archived} busy={busy} onClick={() => switchTo(true)} label="Archived" /> : null}
        </>
      )}
      {...props}
    />
  );
}

const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const codes = () => [...document.querySelectorAll('tbody tr')].map((r) => r.textContent);

describe('A-17 item 1 — the core never writes the address; the users wrapper does', () => {
  it('without onViewLoaded, live → Archived → live never calls router.replace; the users wrapper over the same switches does', async () => {
    const { calls, load } = scriptedLoad();
    const u = userEvent.setup();
    const { unmount } = renderWired(<PageFixture initial={ok([live('A1')])} load={load} />);
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await act(async () => calls[0]!.resolve(ok([gone('Z9', '2026-01-01T00:00:00Z')])));
    await u.click(await screen.findByRole('button', { name: 'Back to things' }));
    await act(async () => calls[1]!.resolve(ok([live('A1')])));
    await screen.findByRole('button', { name: 'Archived', pressed: false });
    expect(calls.map((c) => c.archived)).toEqual([true, false]);
    expect(nav.replace).not.toHaveBeenCalled();
    unmount();

    // Positive control: the users wrapper passes useArchivedAddress, so the same two switches write the address twice.
    stubFetch([json(200, page([user({ id: 'a1', display_name: 'Old One', archived_at: '2026-01-02T10:00:00Z' })])), json(200, page([user()]))]);
    renderWired(<UsersList initial={{ kind: 'ok', users: [user()] }} viewerId={VIEWER_ID} />);
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await screen.findByText('Old One');
    expect(nav.replace).toHaveBeenCalledTimes(1);
    expect(nav.replace).toHaveBeenLastCalledWith('/admin/users?archived=true', { scroll: false });
    await u.click(screen.getByRole('button', { name: 'Archived', pressed: true }));
    await screen.findByText('Ada Lovelace');
    expect(nav.replace).toHaveBeenCalledTimes(2);
    expect(nav.replace).toHaveBeenLastCalledWith('/admin/users', { scroll: false });
  });

  it('onViewLoaded is called only after a load succeeded, and only when the view differs from the last one loaded', async () => {
    const { calls, load } = scriptedLoad();
    const onViewLoaded = vi.fn();
    const u = userEvent.setup();
    renderWired(<PageFixture initial={ok([live('A1')])} load={load} onViewLoaded={onViewLoaded} />);
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await act(async () => calls[0]!.resolve({ kind: 'server-error' }));
    expect(onViewLoaded).not.toHaveBeenCalled();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await act(async () => calls[1]!.resolve(ok([gone('Z9', '2026-01-01T00:00:00Z')])));
    expect(onViewLoaded.mock.calls).toEqual([[true]]);
  });

  it('initialArchived starts the core on the Archived view', () => {
    renderWired(<PageFixture initial={ok([gone('Z9', '2026-01-01T00:00:00Z')])} initialArchived load={scriptedLoad().load} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Archived things' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Back to things' })).toBeInTheDocument();
  });
});

describe('A-17 items 2–4 — header, per-view heading, optional search', () => {
  it('an outside switchTo(true) from the header changes the rows and the per-view heading and search label', async () => {
    const { calls, load } = scriptedLoad();
    const u = userEvent.setup();
    renderWired(<PageFixture initial={ok([live('A1')])} load={load} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Things' })).toBeInTheDocument();
    expect(screen.getByRole('searchbox', { name: 'Search things' })).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await act(async () => calls[0]!.resolve(ok([gone('Z9', '2026-01-01T00:00:00Z')])));
    expect(codes()).toEqual(['Z9']);
    expect(screen.getByRole('heading', { level: 1, name: 'Archived things' })).toBeInTheDocument();
    expect(screen.getByRole('searchbox', { name: 'Search archived things' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'New thing' })).toBeNull();
  });

  it('a switch while busy is ignored (today’s fetchView rule)', async () => {
    const { calls, load } = scriptedLoad();
    const u = userEvent.setup();
    renderWired(<PageFixture initial={ok([live('A1')])} load={load} />);
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    expect(calls).toHaveLength(1);
  });

  it('search: false renders no searchbox; section use renders no h1 and labels the container with headingId', () => {
    renderWired(<SectionFixture initial={ok([live('A1')])} load={scriptedLoad().load} />);
    expect(screen.queryByRole('searchbox')).toBeNull();
    expect(screen.queryByRole('heading', { level: 1 })).toBeNull();
    const section = document.querySelector('section[aria-labelledby]')!;
    expect(section.getAttribute('aria-labelledby')).toBe('locations-h');
    expect(document.getElementById('locations-h')).toHaveTextContent('Locations');
  });

  it('with no header, the default Archived toggle stays in the toolbar, only once a view has loaded (users/roles markup)', async () => {
    const { calls, load } = scriptedLoad();
    const u = userEvent.setup();
    renderWired(<ArchivableList<Thing> initial={{ kind: 'unreachable' }} load={load} matches={() => true} columns={columns} copy={{ ...COPY, heading: 'Things', searchLabel: 'Search', searchPlaceholder: 'x' }} empty={emptyOf} archivedView={{ order: 'as-served', rows: 'plain' }} denied={null} />);
    expect(screen.queryByRole('button', { name: 'Archived' })).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await act(async () => calls[0]!.resolve(ok([live('A1')])));
    expect(screen.getByRole('button', { name: 'Archived', pressed: false })).toBeInTheDocument();
  });

  it('the core refuses to render with neither copy.heading nor headingId (FE-R3 H-2)', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    expect(() => render(<ArchivableList<Thing> initial={ok([])} load={scriptedLoad().load} columns={columns} copy={{ ...COPY, heading: null }} search={false} empty={emptyOf} archivedView={{ order: 'as-served', rows: 'plain' }} denied={null} />)).toThrow(/heading/);
    spy.mockRestore();
  });
});

describe('R3-1 — the header renders in every state but denied; no Back to … before a view has loaded', () => {
  it('page use, failed initial: the header’s New action renders; no Back to …', () => {
    const first = renderWired(<PageFixture initial={{ kind: 'server-error' }} initialArchived load={scriptedLoad().load} />);
    expect(screen.getByRole('heading', { name: 'Something went wrong on our side.' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Back to/ })).toBeNull();
    first.unmount();
    renderWired(<PageFixture initial={{ kind: 'unreachable' }} load={scriptedLoad().load} />);
    expect(screen.getByRole('button', { name: 'New thing' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Back to/ })).toBeNull();
  });

  it('section use, failed initial: the h2 is present and aria-labelledby resolves to it; no Back to …', () => {
    renderWired(<SectionFixture initial={{ kind: 'unreachable' }} initialArchived load={scriptedLoad().load} />);
    const h2 = screen.getByRole('heading', { level: 2, name: 'Archived locations' });
    const labelled = document.querySelector('section[aria-labelledby]')!;
    expect(document.getElementById(labelled.getAttribute('aria-labelledby')!)).toBe(h2);
    expect(screen.queryByRole('button', { name: /^Back to/ })).toBeNull();
  });

  it('denied: neither the header nor the list renders', () => {
    renderWired(<SectionFixture initial={{ kind: 'forbidden' }} load={scriptedLoad().load} />);
    expect(screen.getByText('You can’t see locations.')).toBeInTheDocument();
    expect(screen.queryByRole('heading', { level: 2 })).toBeNull();
  });
});

describe('A-20 — a forbidden initial renders denied, never "something went wrong"', () => {
  it('a section given a forbidden initial shows denied', () => {
    renderWired(<SectionFixture initial={{ kind: 'forbidden' }} load={scriptedLoad().load} />);
    expect(screen.getByText('You can’t see locations.')).toBeInTheDocument();
    expect(screen.queryByText('Something went wrong on our side.')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull();
  });
});

describe('A-17 items 5–8 — tenantHasRows, empty, archivedView, notice/footer', () => {
  it('the two live empty headings follow tenantHasRows', () => {
    const { unmount } = renderWired(<PageFixture initial={ok([], true)} load={scriptedLoad().load} />);
    expect(screen.getByText('Every thing is archived.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'View archived things' })).toBeInTheDocument();
    unmount();
    renderWired(<PageFixture initial={ok([], false)} load={scriptedLoad().load} />);
    expect(screen.getByText('No things yet.')).toBeInTheDocument();
  });

  it('the empty heading on screen follows the live query; the region speaks only the settled one (D-9)', async () => {
    const u = userEvent.setup();
    renderWired(<PageFixture initial={ok([live('A1')])} load={scriptedLoad().load} />);
    await u.type(screen.getByRole('searchbox'), 'z');
    await waitFor(() => expect(region()).toHaveTextContent('Nothing starts with “z”.'));
    await u.type(screen.getByRole('searchbox'), 'q');
    expect(screen.getByText('Nothing starts with “zq”.')).toBeInTheDocument();
    expect(region()).toHaveTextContent('Nothing starts with “z”.');
    await waitFor(() => expect(region()).toHaveTextContent('Nothing starts with “zq”.'));
  });

  it("'as-served' keeps the server's order and renders no caption; 'newest-deleted-first' re-orders and captions", () => {
    const rows = [gone('OLD', '2026-01-01T00:00:00Z'), gone('NEW', '2026-09-01T00:00:00Z')];
    const { unmount } = renderWired(<PageFixture initial={ok(rows)} initialArchived load={scriptedLoad().load} />);
    expect(codes()).toEqual(['OLD', 'NEW']);
    expect(document.querySelector('caption')).toBeNull();
    unmount();
    renderWired(<PageFixture initial={ok(rows)} initialArchived load={scriptedLoad().load} archivedView={{ order: 'newest-deleted-first', caption: 'Most recently deleted first', rows: 'plain' }} />);
    expect(codes()).toEqual(['NEW', 'OLD']);
    expect(document.querySelector('caption')).toHaveTextContent('Most recently deleted first');
  });

  it("rows: 'struck' marks archived-view rows only; 'plain' marks none", () => {
    const { unmount } = renderWired(<PageFixture initial={ok([gone('Z9', '2026-01-01T00:00:00Z')])} initialArchived load={scriptedLoad().load} />);
    expect(styles.struck).toEqual(expect.any(String));
    expect(document.querySelector('tbody tr')!.className).toBe(styles.struck);
    unmount();
    const r = renderWired(<PageFixture initial={ok([live('A1')])} load={scriptedLoad().load} />);
    expect(document.querySelector('tbody tr')!.getAttribute('class')).toBeNull();
    r.unmount();
    renderWired(<PageFixture initial={ok([gone('Z9', '2026-01-01T00:00:00Z')])} initialArchived load={scriptedLoad().load} archivedView={{ order: 'as-served', rows: 'plain' }} />);
    expect(document.querySelector('tbody tr')!.getAttribute('class')).toBeNull();
  });

  it('notice renders above the rows per view; footer is given the rows on screen', async () => {
    const footer = vi.fn((rows: readonly Thing[]): ReactNode => <p>{`${rows.length} under the table`}</p>);
    const u = userEvent.setup();
    renderWired(<PageFixture initial={ok([live('A1'), live('B2')])} load={scriptedLoad().load} notice={(archived) => <p>{archived ? 'archived note' : 'live note'}</p>} footer={footer} />);
    const note = screen.getByText('live note');
    expect(note.compareDocumentPosition(document.querySelector('table')!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.getByText('2 under the table')).toBeInTheDocument();
    await u.type(screen.getByRole('searchbox'), 'a');
    expect(screen.getByText('1 under the table')).toBeInTheDocument();
  });
});

describe('A-17 item 9 — controller.reread()', () => {
  it('keeps the search text and the view, and resolves after the new rows have committed', async () => {
    const { calls, load } = scriptedLoad();
    const controller = createRef<ArchivableListHandle>();
    const u = userEvent.setup();
    renderWired(<PageFixture initial={ok([gone('AA', '2026-01-01T00:00:00Z'), gone('AB', '2026-01-02T00:00:00Z')])} initialArchived load={load} controller={controller} />);
    await u.type(screen.getByRole('searchbox'), 'a');
    let seen: string[] | null = null;
    const done = controller.current!.reread().then((r) => {
      seen = codes();
      return r;
    });
    await waitFor(() => expect(calls).toHaveLength(1));
    expect(calls[0]!.archived).toBe(true);
    await act(async () => calls[0]!.resolve(ok([gone('AB', '2026-01-02T00:00:00Z'), gone('AC', '2026-01-03T00:00:00Z'), gone('ZZ', '2026-01-04T00:00:00Z')])));
    expect(await done).toEqual({ kind: 'ok' });
    expect(seen).toEqual(['AB', 'AC']);
    expect(screen.getByRole('searchbox')).toHaveValue('a');
    expect(screen.getByRole('heading', { level: 1, name: 'Archived things' })).toBeInTheDocument();
  });

  it('called during an in-flight switch, it waits, then reads again, and resolves only after that second read', async () => {
    const { calls, load } = scriptedLoad();
    const controller = createRef<ArchivableListHandle>();
    const u = userEvent.setup();
    renderWired(<PageFixture initial={ok([live('A1')])} load={load} controller={controller} />);
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    let resolved = false;
    const done = controller.current!.reread().then((r) => {
      resolved = true;
      return r;
    });
    await act(async () => calls[0]!.resolve(ok([gone('Z9', '2026-01-01T00:00:00Z')])));
    expect(resolved).toBe(false);
    await waitFor(() => expect(calls).toHaveLength(2));
    expect(calls[1]!.archived).toBe(true);
    await act(async () => calls[1]!.resolve(ok([gone('Z8', '2026-01-02T00:00:00Z')])));
    expect(await done).toEqual({ kind: 'ok' });
    expect(codes()).toEqual(['Z8']);
  });

  it('never changes the settled announcement, never moves focus (M-2)', async () => {
    const { calls, load } = scriptedLoad();
    const controller = createRef<ArchivableListHandle>();
    const u = userEvent.setup();
    renderWired(<PageFixture initial={ok([live('A1'), live('A2')])} load={load} controller={controller} />);
    // Before any interaction: a reread does not start the announcement either.
    let done = controller.current!.reread();
    await waitFor(() => expect(calls).toHaveLength(1));
    await act(async () => calls[0]!.resolve(ok([live('A1')])));
    await done;
    expect(region()).toHaveTextContent('');
    await u.type(screen.getByRole('searchbox'), 'a');
    await waitFor(() => expect(region()).toHaveTextContent('1 shown. Starts with “a”.'));
    const box = screen.getByRole('searchbox');
    expect(box).toHaveFocus();
    const texts: string[] = [];
    const obs = new MutationObserver(() => texts.push(region().textContent ?? ''));
    obs.observe(region(), { childList: true, characterData: true, subtree: true });
    done = controller.current!.reread();
    await waitFor(() => expect(calls).toHaveLength(2));
    await act(async () => calls[1]!.resolve(ok([live('A1'), live('A2'), live('A3')])));
    await done;
    obs.disconnect();
    expect(codes()).toEqual(['A1', 'A2', 'A3']);
    expect(texts).toEqual([]);
    expect(region()).toHaveTextContent('1 shown. Starts with “a”.');
    expect(box).toHaveFocus();
  });

  it('a failed reread raises no banner, leaves the rows and the region alone, and resolves with the failure', async () => {
    const { calls, load } = scriptedLoad();
    const controller = createRef<ArchivableListHandle>();
    renderWired(<PageFixture initial={ok([live('A1')])} load={load} controller={controller} />);
    const done = controller.current!.reread();
    await waitFor(() => expect(calls).toHaveLength(1));
    await act(async () => calls[0]!.resolve({ kind: 'unreachable' }));
    expect(await done).toEqual({ kind: 'unreachable' });
    expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull();
    expect(codes()).toEqual(['A1']);
    expect(region()).toHaveTextContent('');
  });
});

describe('A-17 item 9 — controller.reread(), after RED (r7-step2-red.md M-1, L-1)', () => {
  it('a forbidden reread shows denied and resolves with forbidden (decision 5)', async () => {
    const { calls, load } = scriptedLoad();
    const controller = createRef<ArchivableListHandle>();
    renderWired(<PageFixture initial={ok([live('A1')])} load={load} controller={controller} />);
    const done = controller.current!.reread();
    await waitFor(() => expect(calls).toHaveLength(1));
    await act(async () => calls[0]!.resolve({ kind: 'forbidden' }));
    expect(await done).toEqual({ kind: 'forbidden' });
    expect(screen.getByText('You can’t see things.')).toBeInTheDocument();
    expect(document.querySelector('table')).toBeNull();
  });

  it('a reread before any view has loaded reads nothing, keeps the region error, and resolves server-error (L-1 option (a))', async () => {
    const { calls, load } = scriptedLoad();
    const controller = createRef<ArchivableListHandle>();
    renderWired(<SectionFixture initial={{ kind: 'unreachable' }} load={load} controller={controller} />);
    expect(await controller.current!.reread()).toEqual({ kind: 'server-error' });
    expect(calls).toHaveLength(0);
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
    expect(document.querySelector('table')).toBeNull();
  });
});

describe('FE-R3 H-2 — the headingId element must be a focus target (r7-step2-red.md L-4, dev only)', () => {
  const quiet = () => vi.spyOn(console, 'error').mockImplementation(() => {});
  const section = (h2: ReactNode) => (
    <ArchivableList<Thing>
      initial={ok([live('A1')])}
      load={scriptedLoad().load}
      columns={columns}
      copy={{ ...COPY, heading: null }}
      headingId="sec-h"
      search={false}
      empty={emptyOf}
      archivedView={{ order: 'as-served', rows: 'plain' }}
      denied={null}
      header={() => h2}
    />
  );

  it('a headingId element without tabIndex={-1} is refused', () => {
    const spy = quiet();
    expect(() => renderWired(section(<h2 id="sec-h">Locations</h2>))).toThrow(/tabIndex=\{-1\}/);
    spy.mockRestore();
  });

  it('a headingId that names no element is refused', () => {
    const spy = quiet();
    expect(() => renderWired(section(<h2 id="other">Locations</h2>))).toThrow(/tabIndex=\{-1\}/);
    spy.mockRestore();
  });

  it('positive control: an h2 with tabIndex={-1} renders', () => {
    renderWired(section(<h2 id="sec-h" tabIndex={-1}>Locations</h2>));
    expect(screen.getByRole('heading', { level: 2, name: 'Locations' })).toBeInTheDocument();
  });
});

describe('FE-R3 H-1/H-2 — where focus goes after a Retry on a header list or a section', () => {
  it('section, failed first load, Retry succeeds → activeElement is the section h2, never <body>', async () => {
    const { calls, load } = scriptedLoad();
    const u = userEvent.setup();
    renderWired(<SectionFixture initial={{ kind: 'unreachable' }} load={load} />);
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await act(async () => calls[0]!.resolve(ok([live('A1')])));
    await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('heading', { level: 2, name: 'Locations' })));
    expect(region()).toHaveTextContent('1 shown.');
  });

  it('section, banner Retry succeeds on the archived view → focus goes to Back to locations (the new view’s switch)', async () => {
    const { calls, load } = scriptedLoad();
    const u = userEvent.setup();
    renderWired(<SectionFixture initial={ok([live('A1')])} load={load} />);
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await act(async () => calls[0]!.resolve({ kind: 'server-error' }));
    const retry = screen.getByRole('button', { name: 'Retry' });
    await u.click(retry);
    await act(async () => calls[1]!.resolve(ok([gone('Z9', '2026-01-01T00:00:00Z')])));
    await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Back to locations' })));
    expect(screen.getByRole('heading', { level: 2, name: 'Archived locations' })).toBeInTheDocument();
  });

  it('page with a header whose new view offers no switch → focus goes to the h1', async () => {
    const { calls, load } = scriptedLoad();
    const u = userEvent.setup();
    renderWired(<PageFixture initial={ok([live('A1')])} load={load} header={({ switchTo }) => <Button onClick={() => switchTo(true)}>Go archived</Button>} />);
    await u.click(screen.getByRole('button', { name: 'Go archived' }));
    await act(async () => calls[0]!.resolve({ kind: 'server-error' }));
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await act(async () => calls[1]!.resolve(ok([gone('Z9', '2026-01-01T00:00:00Z')])));
    await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('heading', { level: 1, name: 'Archived things' })));
  });

  it('a switch whose own control is removed by the new view moves focus to the new view’s switch (D-4)', async () => {
    const { calls, load } = scriptedLoad();
    const u = userEvent.setup();
    renderWired(<PageFixture initial={ok([], true)} load={load} />);
    const viewArchived = screen.getByRole('button', { name: 'View archived things' });
    await u.click(viewArchived);
    expect(viewArchived).toHaveFocus();
    await act(async () => calls[0]!.resolve(ok([gone('Z9', '2026-01-01T00:00:00Z')])));
    expect(viewArchived.isConnected).toBe(false);
    await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Back to things' })));
  });

  it('a section whose rows came back after a plain switch keeps focus where the user put it', async () => {
    const { calls, load } = scriptedLoad();
    const u = userEvent.setup();
    renderWired(<SectionFixture initial={ok([live('A1')])} load={load} />);
    const toggle = screen.getByRole('button', { name: 'Archived' });
    await u.click(toggle);
    act(() => screen.getByRole('heading', { level: 2 }).focus());
    const h2 = document.activeElement;
    await act(async () => calls[0]!.resolve(ok([gone('Z9', '2026-01-01T00:00:00Z')])));
    expect(document.activeElement).toBe(h2);
    expect(within(document.querySelector('section')!).getByText('Z9')).toBeInTheDocument();
  });
});
