'use client';

import { useEffect, useEffectEvent, useImperativeHandle, useMemo, useRef, useState, type ReactNode, type Ref, type RefObject } from 'react';
import { flushSync } from 'react-dom';
import { Button } from '@/components/ui/Button';
import { LiveRegion } from '@/components/ui/LiveRegion';
import { Refetchable, RefetchFailedBanner, RegionError } from '@/components/states/PageStates';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { LIST_STATES } from '@/copy/shell';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type PageCursor, type PageRequest, type Read, type ReadFailure } from '@/lib/bff/reads';
import { useApiCaller } from './BffProvider';
import styles from './Lists.module.css';

/** How long the search box must be still before the filter is announced (ST-1a: on the settled filter, never per key). */
export const SEARCH_SETTLE_MS = 400;

type Cause = 'unreachable' | 'server-error';
/**
 * Which control started a fetch: a view switch (the toggle, a header control, an empty-state action), the banner's Retry, or the first-load Retry.
 * A paged list adds a page press (`page`), a settled search (`settle`) and **Back to the first page** (`top`).
 */
type Origin = 'switch' | 'banner' | 'first' | 'page' | 'settle' | 'top';
/** The first page with no search: the only request a whole-view list makes. */
const FIRST_PAGE: PageRequest = { q: '', cursor: null };
/**
 * Focus fell out of the page: onto `<body>`, or with an element that has since been removed (M-3). When a focused node is
 * removed, browsers report `<body>`; the null and detached checks are belt-and-braces. A focused row removed by the new
 * view also counts as lost.
 */
const focusWasLost = () => {
  const el = document.activeElement;
  return el === null || el === document.body || !el.isConnected;
};
const causeOf = (kind: string): Cause => (kind === 'unreachable' ? 'unreachable' : 'server-error');
const headingOf = (c: Cause) => (c === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG);
const newestDeletedFirst = (a: { archived_at: string | null }, b: { archived_at: string | null }) => Date.parse(b.archived_at ?? '') - Date.parse(a.archived_at ?? '');
const connected = <E extends HTMLElement>(el: E | null) => (el !== null && el.isConnected ? el : null);

/** A load's answer. `tenantHasRows` (A-17 item 5) says whether the workspace has any row in either view; a list that does not serve it leaves it out. */
export type ListLoad<T> = { readonly kind: 'ok'; readonly items: T[]; readonly tenantHasRows?: boolean } | { readonly kind: ReadFailure };
/**
 * A paged list's answer (b4-architecture.md Revision 8 R8-1): one page of rows and the cursors to its neighbours (`null`:
 * there is no page that way). `tenantHasRows` is read only from an empty answer (the loader takes it from the wire).
 */
export type PagedLoad<T> =
  | { readonly kind: 'ok'; readonly items: T[]; readonly nextCursor: string | null; readonly previousCursor: string | null; readonly tenantHasRows?: boolean }
  | { readonly kind: ReadFailure };

export interface ListCopy {
  /** The page's `h1`, per view if a function; `null` is section use (the `header` renders the section's heading, named by `headingId`). */
  heading: string | ((archived: boolean) => string) | null;
  /** The default Archived toggle's label (used when there is no `header`). */
  archived: string;
  /** The default search box (used when `search` is not given). */
  searchLabel?: string;
  searchPlaceholder?: string;
  loadFailed: string;
  /** ST-1a's settled announcement. Never called with 0: the empty heading is spoken instead (ST-2a). */
  shown: (n: number, q: string, archived: boolean) => string;
}

export interface Column<T> {
  header: string;
  cell: (item: T) => ReactNode;
}

/** The search box's words; the label may depend on the view (UX B-2). */
export interface SearchCopy {
  label: string | ((archived: boolean) => string);
  placeholder: string;
}

/** What `header` and `empty` may read and do. */
export interface ViewControls {
  /** The view on screen; before any view has loaded, the view `initialArchived` names. */
  archived: boolean;
  busy: boolean;
  /** Switch views. Today's `fetchView` rule: a switch while busy is ignored. Never moves focus unless the control the person used is removed by the new view. */
  switchTo(archived: boolean): void;
}

export interface HeaderView extends ViewControls {
  /** False until a view has loaded (a failed first load): the header then offers no **Back to …** (R3-1). */
  loaded: boolean;
  /** Attach to the control in this view's header that switches views (live: Archived; archived: Back to …). Focus goes there after a banner Retry (FE-R3 H-1). */
  viewControlRef: RefObject<HTMLButtonElement | null>;
}

export interface EmptyView extends ViewControls {
  q: string;
  tenantHasRows: boolean | undefined;
  /** Empties the search box and returns focus to it. */
  clearSearch(): void;
}

/** The empty state for a view and query; `null` when that state cannot occur. Its heading is also what the settled announcement speaks (ST-2a). */
export type EmptyState = (v: EmptyView) => { heading: string; actions?: ReactNode } | null;

/**
 * The Archived view's order (A-17 item 7). `as-served` keeps the server's order and shows no caption (ST-9b), unless it is
 * given one (R8-1 item 7: a paged list's one order, named — a client re-sort of one page would lie).
 */
export type ArchivedView = { order: 'newest-deleted-first'; caption: string; rows: 'plain' | 'struck' } | { order: 'as-served'; caption?: string; rows: 'plain' | 'struck' };

/**
 * The paged list's own words (R8-1; UX per family). `pager` labels the two page buttons per view (live: Previous / Next;
 * Archived: Newer / Older, Older being the next page). `pageShown` is the sentence after a page loads by its cursor, with a
 * position cue (R8-1 item 6); never called with no rows. `noMore` is the state an empty page reached by a cursor shows
 * (R8-1 item 5), never an empty state of ST-2.
 */
export interface Paging<T> {
  pager: (archived: boolean) => { previous: string; next: string };
  pageShown: (rows: readonly T[], q: string, archived: boolean) => string;
  noMore: (archived: boolean) => { heading: string; body: string; action: string };
}

export type RereadResult = { kind: 'ok' } | { kind: ReadFailure };
export interface ArchivableListHandle {
  /**
   * Re-read the view on screen, keeping the search text (A-5). Waits for a load in flight, then reads, and resolves with
   * its own result after the rows have committed (L-8). Never moves focus and never changes the settled announcement
   * (M-2). A failure raises no banner and leaves the rows as they are. A 403 replaces the list with `denied`, as a switch
   * does; focus then falls to `<body>` if it was inside the list — the caller decides from the resolved `forbidden`
   * whether to move it. A reread before any view has loaded does nothing and resolves `server-error`: the region error's
   * Retry is the way back.
   */
  reread(): Promise<RereadResult>;
}

interface BaseProps<T> {
  /** A whole-view list: one answer is the whole view. */
  paging?: undefined;
  initial: ListLoad<T>;
  /** The view the server loaded for `initial` (from the address, through the caller). Default: the live view. */
  initialArchived?: boolean;
  /** Called after a load succeeded, only when its view differs from the last one loaded. The address writer is `useArchivedAddress()`. */
  onViewLoaded?: (archived: boolean) => void;
  load: (read: Read, archived: boolean, signal: AbortSignal) => Promise<ListLoad<T>>;
  columns: (archived: boolean) => Column<T>[];
  copy: ListCopy;
  /** In place of the default Archived toggle, in every state but `denied` (R3-1). */
  header?: (v: HeaderView) => ReactNode;
  /** The id of the heading `header` renders (section use): labels the list and is the focus target with no `h1` (FE-R3 H-2). */
  headingId?: string;
  empty: EmptyState;
  archivedView: ArchivedView;
  /** One line above the rows, per view (ST-9b). */
  notice?: (archived: boolean) => ReactNode;
  /** Under the table, given the rows on screen. */
  footer?: (rows: readonly T[]) => ReactNode;
  controller?: Ref<ArchivableListHandle>;
  /** ST-4b for this list: shown for a 403 on first load (A-20) or a later fetch (the permission was lost). */
  denied: ReactNode;
}

type SearchOption<T> =
  | {
      /** The search box; default from `copy.searchLabel`/`searchPlaceholder`. */
      search?: SearchCopy;
      /** The client filter. It need not mirror the server's `q`, and a whole-view list never sends `q` (a paged list always does: R8-1, A-17 item 11 as amended). */
      matches: (item: T, lowerQ: string) => boolean;
    }
  | { search: false; matches?: undefined };

export type ArchivableListProps<T> = BaseProps<T> & SearchOption<T>;

/**
 * A paged list (R8-1): the server pages and searches, so the list never filters (`matches` is typed out) and sends the
 * search box's text as `q`, untrimmed and unfolded. Its Archived view keeps the server's order under a caption.
 */
export type PagedListProps<T> = Omit<BaseProps<T>, 'paging' | 'initial' | 'load' | 'archivedView'> & {
  paging: Paging<T>;
  initial: PagedLoad<T>;
  load: (read: Read, archived: boolean, page: PageRequest, signal: AbortSignal) => Promise<PagedLoad<T>>;
  archivedView: { order: 'as-served'; caption: string; rows: 'plain' | 'struck' };
  search?: SearchCopy;
  matches?: undefined;
};

/** The default Archived toggle (exported so a `header` reuses its `aria-pressed` and `loading` behaviour). */
export function ArchivedToggle({ archived, busy, onClick, label, ref }: { archived: boolean; busy: boolean; onClick: () => void; label: string; ref?: Ref<HTMLButtonElement> }) {
  return (
    <Button ref={ref} aria-pressed={archived} loading={busy} onClick={onClick}>
      {label}
    </Button>
  );
}

/**
 * Wraps today's four empty-state strings (users, roles) as an `empty` function, with **Clear search** on the search rows.
 * `noneLive: null` is a live view that cannot be empty (C5-1).
 */
export function searchEmptyStates(c: { noneLive: string | null; noneArchived: string; noMatch: (q: string) => string; noArchivedMatch: (q: string) => string; clearSearch: string }): EmptyState {
  return ({ archived, q, clearSearch }) => {
    const heading = q !== '' ? (archived ? c.noArchivedMatch(q) : c.noMatch(q)) : archived ? c.noneArchived : c.noneLive;
    if (heading === null) return null;
    return {
      heading,
      actions:
        q !== '' ? (
          <Button size="sm" onClick={clearSearch}>
            {c.clearSearch}
          </Button>
        ) : undefined,
    };
  };
}

/** Renders a caller's slot as a component, so the callbacks and refs it is handed are never read during the list's own render. */
function Slot<V>({ render, v }: { render: (v: V) => ReactNode; v: V }) {
  return <>{render(v)}</>;
}

function EmptyBlock({ empty, v }: { empty: EmptyState; v: EmptyView }) {
  const e = empty(v);
  if (e === null) return null;
  return (
    <div className={styles.empty}>
      <p className={styles.emptyHeading}>{e.heading}</p>
      {e.actions}
    </div>
  );
}

const NOOP = () => {};

/** The empty page a cursor reached (R8-1 item 5): its own heading (the focus target), why, and the way back to the first page. */
function EndOfList({ copy, headingRef, onFirstPage }: { copy: { heading: string; body: string; action: string }; headingRef: Ref<HTMLParagraphElement>; onFirstPage: () => void }) {
  return (
    <div className={styles.empty}>
      <p ref={headingRef} tabIndex={-1} className={styles.emptyHeading}>
        {copy.heading}
      </p>
      <p className={styles.emptyBody}>{copy.body}</p>
      <Button size="sm" onClick={onFirstPage}>
        {copy.action}
      </Button>
    </div>
  );
}

interface Loaded<T> {
  archived: boolean;
  items: T[];
  tenantHasRows: boolean | undefined;
  /** The cursors this answer offers (always null for a whole-view list). */
  next: string | null;
  previous: string | null;
  /** The request that read these rows: its `q` is the search the rows answer, its cursor the page. */
  page: PageRequest;
  /** An empty answer to a request that carried a cursor (R8-1 item 5). */
  endOfList: boolean;
}

/**
 * A bounded list with a live and an Archived view (IA §5.6; ux-copy-b3b.md C5-1, C7-1), the core of every archivable list
 * (Revision 7 A-17). It never touches the address: the caller passes `initialArchived` and, to keep the view in the
 * address, `onViewLoaded={useArchivedAddress()}` (A-17 item 1). Search filters on the client; the only refetches are a
 * view switch, a Retry and `controller.reread()`. The view control is derived from the rows on screen, so a failed
 * switch leaves it on the view actually shown (RED round 2 R2-L2) under `LIST_STATES.refetchFailed`. The status region
 * is mounted empty from first render at the section root, so a successful Retry after a failed first load is a change it
 * announces (principle 6; RED M-3).
 *
 * Paged (`paging`, b4-architecture.md Revision 8 R8-1). The server pages and searches; the list never filters. A request
 * carries a cursor only when it is a page press, or the banner's Retry repeating a failed one exactly; a view switch (with
 * the search in the box), a settled search, Clear search, **Back to the first page** and the first load carry none. A
 * settle reads the first page for its query and the region is blank until that read succeeds — it never speaks from the
 * rows on screen; a settle during any fetch waits, the last one winning, and the fetch it waited for then speaks nothing.
 * A page press during any fetch (a reread included) is ignored, and it and `reread()` use the settled search, never
 * unsettled text; while the rows answer another search (a settle or a switch failed) the pager is withdrawn.
 * A page read by its cursor speaks `paging.pageShown` (a position cue); an empty one is the core's own state (`noMore`,
 * focus to its heading if focus was lost — after a reread too, which still announces nothing), never an ST-2 empty state. After a page press focus stays on the pressed button if it is still
 * rendered, else goes to the list's heading. Cursors and `q` are never written to the address: a reload opens page 1.
 *
 * The region (FE-R4, fe-r4-ux.md Revision 1). Its text is one string written explicitly, never derived on render, and
 * only at four moments: a switch or Retry starts (blank), succeeds (the settled sentence), or fails (the banner's
 * heading), and a search settles. A keystroke never writes it, so while a search is unsettled the region keeps what it
 * holds. A settle writes only when its query differs from the last settled one, or when it clears a failure heading the
 * user searched past (FE-R3); so the mount settle and a type-and-erase say nothing, and announcements start at the first
 * changed settle or the first successful fetch, whichever comes first (first paint is not announced, ST-2a). A success
 * settles a pending search at once: its sentence is for the query in the box. The sentence never says "0 shown": an
 * empty result speaks its empty heading — `empty` is called with the settled query for the region and with the live
 * query for the empty state on screen (the two may differ while a search is unsettled). Every settled sentence and
 * non-null empty heading for a non-empty query must name the query (rule 6), or two queries with one count read alike.
 *
 * Focus (FE-R3, fe-r3-ux.md Revision 1 incl. H-1/H-2). The list's heading is the `h1` when `copy.heading` is given, else
 * the element named by `headingId` (which must carry `tabIndex={-1}`); with neither, or with a `headingId` that names no
 * such element, the list refuses to render in development (the check is compiled out of production). A first-load Retry unmounts with its error,
 * so on success focus moves to the list's heading. A banner Retry unmounts with the banner, so on success focus moves to
 * the control that switches views in the new view (the default toggle, or what the `header` attaches to
 * `viewControlRef`), else the list's heading. A view switch never moves focus (ST-1a), except when the control the person
 * used is itself removed by the new view (an empty-state action, a **Back to …**): then focus goes where a banner Retry's
 * does. Every move happens only if focus was lost (inside the removed node, or on `<body>`) — never away from where the
 * user went while waiting (M-3) — and every success holds the sentence until after any focus move. A failed switch or
 * Retry writes the banner's heading into this list's one status region (the banner has no live role of its own,
 * principle 6) and never leaves the settled sentence there (defect A, M-2); the region is blanked while a switch or
 * Retry is in flight (the explicit blank at fetch start, and `inFlight` holding a settle back), so a repeat failure with the same cause is still a text change (defect B, M-4). A reread
 * does neither: its count is never announced (M-2), and a failed reread raises no banner.
 */
export function ArchivableList<T extends { id: string; archived_at: string | null }>(props: ArchivableListProps<T> | PagedListProps<T>) {
  const { initial, initialArchived = false, onViewLoaded, columns, copy, header, headingId, empty, archivedView, notice, footer, controller, denied } = props;
  const paging = props.paging ?? null;
  if (process.env.NODE_ENV !== 'production' && copy.heading === null && headingId === undefined) {
    throw new Error('ArchivableList needs a heading: give copy.heading (the page h1) or headingId (the section heading), the focus target after a Retry (FE-R3 H-2).');
  }
  const matches = props.paging !== undefined || props.search === false ? null : props.matches;
  const searchCopy: SearchCopy | null = props.search === false ? null : (props.search ?? { label: copy.searchLabel ?? '', placeholder: copy.searchPlaceholder ?? '' });

  const caller = useApiCaller();
  /** An answer as the rows on screen: the cursors it offers, and whether it is the empty page a cursor reached. */
  const loadedOf = (archived: boolean, r: Extract<ListLoad<T> | PagedLoad<T>, { kind: 'ok' }>, page: PageRequest): Loaded<T> => ({
    archived,
    items: r.items,
    tenantHasRows: r.tenantHasRows,
    next: 'nextCursor' in r ? r.nextCursor : null,
    previous: 'previousCursor' in r ? r.previousCursor : null,
    page,
    endOfList: paging !== null && page.cursor !== null && r.items.length === 0,
  });
  const firstShown: Loaded<T> | null = initial.kind === 'ok' ? loadedOf(initialArchived, initial, FIRST_PAGE) : null;
  /** The view last loaded; `onViewLoaded` fires when a load lands on the other one. */
  const lastLoaded = useRef(initialArchived);
  const [shown, setShownState] = useState<Loaded<T> | null>(firstShown);
  const shownRef = useRef<Loaded<T> | null>(firstShown);
  const [firstError, setFirstError] = useState<{ gen: number; cause: Cause } | null>(initial.kind === 'ok' || initial.kind === 'forbidden' ? null : { gen: 0, cause: causeOf(initial.kind) });
  const [refetchError, setRefetchError] = useState<{ cause: Cause; archived: boolean; page: PageRequest } | null>(null);
  const [isDenied, setDenied] = useState(initial.kind === 'forbidden');
  const [busy, setBusy] = useState(false);
  const [q, setQState] = useState('');
  /** The search box's text for handlers that outlive a render (a success settles the query in the box, rule 2). */
  const qRef = useRef('');
  /** The list's one status region (FE-R4): written only by a fetch's start, success or failure, and a search's settle. */
  const [said, setSaid] = useState('');
  /** The query the region last spoke for (or would have, under a failure heading or a fetch in flight). */
  const settledQ = useRef('');
  /** The heading of the last failed refetch, held in the region until a fetch succeeds or a search the user typed settles. */
  const failureNote = useRef<string | null>(null);
  /** The user changed the search since the last failure: the note clears when that search settles, never on the keystroke. */
  const searchedSinceFailure = useRef(false);
  /** A switch or Retry is in flight: the region stays blank until it ends (FE-R3 defect B). */
  const inFlight = useRef(false);
  /** `busy` for handlers that outlive a render (a paged list's settle and the fetch that ends before it). */
  const busyRef = useRef(false);
  /** A paged list's search that settled while a fetch was in flight: the last one wins, and it runs when that fetch ends (R8-1 item 4). */
  const pendingSettle = useRef<string | null>(null);
  /** The heading of the empty page a cursor reached (R8-1 item 5). */
  const endHeading = useRef<HTMLParagraphElement>(null);
  /** A `reread()` is reading: a page press then is ignored, as during any fetch (R8-1 item 4; it would carry a cursor the reread is replacing). */
  const rereading = useRef(false);
  const viewControlRef = useRef<HTMLButtonElement | null>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const h1 = useRef<HTMLHeadingElement>(null);
  /** Loads run one after another: a reread waits for a load in flight (L-8), and a switch for a reread. */
  const queue = useRef<Promise<unknown>>(Promise.resolve());

  const setQ = (next: string) => {
    qRef.current = next;
    setQState(next);
  };
  /** The settled sentence for a view and query (ST-1a); a count of 0 speaks the empty heading instead (ST-2a). Inert actions: only the heading is read. */
  const sentenceFor = (view: Loaded<T>, query: string) => {
    const n = matches ? view.items.filter((i) => matches(i, query.toLowerCase())).length : view.items.length;
    return n > 0 ? copy.shown(n, query, view.archived) : (empty({ archived: view.archived, q: query, tenantHasRows: view.tenantHasRows, busy: false, switchTo: NOOP, clearSearch: NOOP })?.heading ?? '');
  };
  const setShown = (next: Loaded<T>) => {
    shownRef.current = next;
    setShownState(next);
  };
  const listHeading = (): HTMLElement | null => (copy.heading !== null ? h1.current : headingId !== undefined ? document.getElementById(headingId) : null);
  const viewLoaded = (archived: boolean) => {
    if (archived === lastLoaded.current) return;
    lastLoaded.current = archived;
    onViewLoaded?.(archived);
  };
  const run = <R,>(task: () => Promise<R>): Promise<R> => {
    const p = queue.current.then(task, task);
    queue.current = p.catch(() => undefined);
    return p;
  };
  const readView = async (archived: boolean, page: PageRequest): Promise<ListLoad<T> | PagedLoad<T>> => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = props.paging !== undefined ? await props.load(readOf(caller), archived, page, deadline.signal) : await props.load(readOf(caller), archived, deadline.signal);
    deadline.cancel();
    return r;
  };

  // FE-R3 H-2 / RED L-4: a `focus()` on a heading without tabindex is a silent no-op, leaving focus on <body>.
  useEffect(() => {
    if (process.env.NODE_ENV === 'production' || isDenied || copy.heading !== null || headingId === undefined) return;
    if (document.getElementById(headingId)?.getAttribute('tabindex') !== '-1') {
      throw new Error("ArchivableList's headingId must name a heading with tabIndex={-1}, the focus target after a Retry (FE-R3 H-2).");
    }
  }, [isDenied, copy.heading, headingId]);

  /**
   * A paged list's settled search (R8-1 item 4): it reads the first page for the query — never speaking from the rows on
   * screen — and while a fetch is in flight it waits, the last settled query winning. A query equal to the settled one
   * reads nothing (the mount settle, a type-and-erase).
   */
  const settlePaged = (query: string) => {
    const changed = query !== settledQ.current;
    settledQ.current = query;
    if (failureNote.current !== null && searchedSinceFailure.current) {
      searchedSinceFailure.current = false;
      failureNote.current = null;
    }
    const view = shownRef.current;
    if (!changed || view === null) return;
    if (busyRef.current) {
      pendingSettle.current = query;
      return;
    }
    void fetchView(view.archived, 'settle', { q: query, cursor: null });
  };

  const fetchView = async (archived: boolean, from: Origin, page: PageRequest = FIRST_PAGE) => {
    if (from === 'settle' ? busyRef.current : busy) return;
    /** The control the person used, if it had focus (a mouse click in Safari leaves `<body>`): removed by the new view → focus is moved. */
    const started = document.activeElement;
    setBusy(true);
    busyRef.current = true;
    inFlight.current = true;
    // A paged switch reads the search in the box, so that search is settled now (its settle then finds nothing changed).
    if (paging !== null && from === 'switch') settledQ.current = page.q;
    // Blank while in flight, so a repeat failure (same heading) or a success is a text change (FE-R3 defect B, M-4).
    setSaid('');
    const r = await run(() => readView(archived, page));
    setBusy(false);
    busyRef.current = false;
    inFlight.current = false;
    // A search that settled meanwhile (paged): it runs next, and this answer speaks nothing (R8-1 item 4).
    const queued = pendingSettle.current;
    pendingSettle.current = null;
    const rerun = queued !== null && queued !== page.q ? queued : null;
    if (r.kind === 'ok') {
      // X-2 / FE-R3: commit the rows first (the region still blank), then, only if focus was lost, focus the list's heading
      // (first load) or the new view's switch control (banner; a switch whose own control is gone), then write the
      // sentence — focus first, then announce (FE-D1's flushSync order).
      const next = loadedOf(archived, r, page);
      flushSync(() => {
        setShown(next);
        setFirstError(null);
        setRefetchError(null);
      });
      // Defensive, unobservable (RED L-2): the reset of `searchedSinceFailure` plus any later changed query already
      // clears a stale note before it is read; kept so the note's meaning, "cleared by a successful fetch", is literal.
      failureNote.current = null;
      searchedSinceFailure.current = false;
      const controlGone = started instanceof HTMLElement && started !== document.body && !started.isConnected;
      if (focusWasLost()) {
        // R8-1: the empty page a cursor reached takes focus to its heading; a page press whose button is gone, to the list's heading.
        if (next.endOfList) endHeading.current?.focus();
        else if (from === 'first' || from === 'page') listHeading()?.focus();
        else if (from === 'banner' || controlGone) (connected(viewControlRef.current) ?? listHeading())?.focus();
      }
      if (paging === null) {
        // FE-R4 rule 2: a success settles a pending search at once — the sentence is for the query in the box, and the
        // pending settle then finds nothing changed.
        settledQ.current = qRef.current;
        setSaid(sentenceFor(next, qRef.current));
      } else if (rerun === null) {
        // R8-1 items 5–6: a page read by its cursor speaks its position; the empty one, its heading; any other, ST-1a's sentence.
        setSaid(next.endOfList ? paging.noMore(archived).heading : page.cursor !== null ? paging.pageShown(next.items, page.q, archived) : sentenceFor(next, page.q));
      }
      viewLoaded(archived);
      if (rerun !== null) void fetchView(archived, 'settle', { q: rerun, cursor: null });
      return;
    }
    if (r.kind === 'session-ended') return;
    if (r.kind === 'forbidden') {
      setDenied(true);
      return;
    }
    if (shownRef.current === null) setFirstError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: causeOf(r.kind) }));
    else {
      // The failed request is kept whole: the banner's Retry repeats it exactly (R8-1 item 2).
      setRefetchError({ cause: causeOf(r.kind), archived, page });
      searchedSinceFailure.current = false;
      failureNote.current = headingOf(causeOf(r.kind));
      setSaid(failureNote.current);
      if (rerun !== null) void fetchView(shownRef.current.archived, 'settle', { q: rerun, cursor: null });
    }
  };

  // FE-R4 rules 3–5: a settle writes only for a changed query, or to clear a failure heading the user searched past. The
  // mount settle (`q = ''`) and a type-and-erase change nothing. A failure heading the user did not search past keeps the
  // region, and a fetch in flight keeps it blank; either way the query counts as settled.
  const onSettle = useEffectEvent((query: string) => {
    if (paging !== null) {
      settlePaged(query);
      return;
    }
    const changed = query !== settledQ.current;
    const clearsFailure = failureNote.current !== null && searchedSinceFailure.current;
    settledQ.current = query;
    if (clearsFailure) {
      searchedSinceFailure.current = false;
      failureNote.current = null;
    }
    if (!changed && !clearsFailure) return;
    const view = shownRef.current;
    if (failureNote.current !== null || inFlight.current || view === null) return;
    setSaid(sentenceFor(view, query));
  });
  useEffect(() => {
    const t = setTimeout(() => onSettle(q), SEARCH_SETTLE_MS);
    return () => clearTimeout(t);
  }, [q]);

  useImperativeHandle(controller, () => ({
    reread: () =>
      run(async (): Promise<RereadResult> => {
        // RED L-1 option (a): before any view has loaded there is nothing on screen to refresh.
        if (shownRef.current === null) return { kind: 'server-error' };
        const view = shownRef.current.archived;
        // The page on screen, read again: same view, same search, same cursor (R8-1; L-8).
        const page = shownRef.current.page;
        rereading.current = true;
        const r = await readView(view, page).finally(() => {
          rereading.current = false;
        });
        if (r.kind === 'ok') {
          const next = loadedOf(view, r, page);
          flushSync(() => setShown(next));
          // R8-1 item 5: the empty page lands here too. A reread may remove the page button that held focus (the page is
          // empty, or no longer offers that way): only then does focus move — to the state's heading, else the list's, as a
          // page press does — taken from nowhere the person went, and nothing is announced (a reread never speaks, M-2).
          // A whole-view list keeps its reread rule (focus never moves).
          if (focusWasLost()) (next.endOfList ? endHeading.current : paging !== null ? listHeading() : null)?.focus();
          viewLoaded(view);
          return { kind: 'ok' };
        }
        if (r.kind === 'forbidden') setDenied(true);
        return { kind: r.kind };
      }),
  }));

  const archived = shown?.archived ?? initialArchived;
  const rows = useMemo(() => {
    const f = shown ? (matches ? shown.items.filter((i) => matches(i, q.toLowerCase())) : shown.items) : [];
    return archived && archivedView.order === 'newest-deleted-first' ? [...f].sort(newestDeletedFirst) : f;
  }, [shown, q, archived, matches, archivedView.order]);

  if (isDenied) return <>{denied}</>;

  // A paged switch reads the search in the box and no cursor; a whole-view one reads the whole view.
  const switchTo = (to: boolean) => void fetchView(to, 'switch', paging !== null ? { q: qRef.current, cursor: null } : FIRST_PAGE);
  const clearSearch = () => {
    searchedSinceFailure.current = true;
    setQ('');
    searchRef.current?.focus();
    // A paged list reads the unsearched first page at once (no cursor); a whole-view one only stops filtering.
    if (paging !== null) settlePaged('');
  };
  /**
   * The rows on screen answer a search other than the settled one: a settle or a switch (which settles the box) failed, so
   * the rows are the older read's (RED H-1). Their cursors belong to that older search, and sending the settled one with
   * them would be served silently as a position in the wrong list: the pager is withdrawn until a read answers the settled
   * search (the banner's Retry, a new search, a switch).
   */
  // Its predicate (`refetchError.page.q`) and `goTo`'s (`settledQ`) are two expressions of one fact, equal while a search
  // can settle only by a read or a switch, and while every read but `reread()` and the banner's Retry sends the settled
  // search (Back to the first page included: R8-1 item 5); a change that lets a search settle without a fetch, or a read
  // send another search, must revisit both together.
  const rowsAnswerAnotherSearch = paging !== null && shown !== null && refetchError !== null && refetchError.page.q !== shown.page.q;
  /** A page press (R8-1 item 2): its cursor, and the search the rows on screen answer — the settled one, never the unsettled text. Ignored during any read. */
  const goTo = (cursor: PageCursor) => {
    if (busy || rereading.current || shown === null || settledQ.current !== shown.page.q) return;
    void fetchView(shown.archived, 'page', { q: shown.page.q, cursor });
  };
  const cols = columns(archived);
  const headingText = typeof copy.heading === 'function' ? copy.heading(archived) : copy.heading;
  const searchLabel = searchCopy ? (typeof searchCopy.label === 'function' ? searchCopy.label(archived) : searchCopy.label) : '';
  const struck = archived && archivedView.rows === 'struck' ? styles.struck : undefined;

  return (
    <section className={styles.page} aria-labelledby={headingId}>
      {headingText !== null ? (
        <header className={styles.header}>
          <h1 ref={h1} tabIndex={-1}>
            {headingText}
          </h1>
        </header>
      ) : null}
      {/* Mounted from first render, above every branch below, so it never arrives already speaking (principle 6). */}
      <LiveRegion message={said} visuallyHidden />
      {header || shown ? (
        <div className={styles.toolbar}>
          {header ? <Slot render={header} v={{ archived, busy, loaded: shown !== null, switchTo, viewControlRef }} /> : <ArchivedToggle ref={viewControlRef} archived={archived} busy={busy} onClick={() => switchTo(!archived)} label={copy.archived} />}
          {shown && searchCopy ? (
            <label className={styles.search}>
              <span>{searchLabel}</span>
              <input
                ref={searchRef}
                type="search"
                className={styles.searchInput}
                placeholder={searchCopy.placeholder}
                value={q}
                onChange={(e) => {
                  // FE-R4 rule 1: a keystroke never writes the region; its settle does.
                  setQ(e.target.value);
                  searchedSinceFailure.current = true;
                }}
              />
            </label>
          ) : null}
        </div>
      ) : null}
      {firstError ? (
        <RegionError key={firstError.gen} heading={headingOf(firstError.cause)} body={copy.loadFailed} retrying={busy} autoFocus={firstError.gen > 0} onRetry={() => void fetchView(initialArchived, 'first')} />
      ) : null}
      {shown ? (
        <>
          {refetchError ? <RefetchFailedBanner heading={headingOf(refetchError.cause)} body={LIST_STATES.refetchFailed} retrying={busy} onRetry={() => void fetchView(refetchError.archived, 'banner', refetchError.page)} /> : null}
          {notice ? notice(archived) : null}
          <Refetchable busy={busy}>
            {paging !== null && shown.endOfList ? (
              <EndOfList copy={paging.noMore(shown.archived)} headingRef={endHeading} onFirstPage={() => void fetchView(shown.archived, 'top', { q: settledQ.current, cursor: null })} />
            ) : rows.length === 0 ? (
              // The empty state on screen follows the live query; the announcement, the settled one (FE-R4 keeps this split).
              // A paged list's rows answer the search they were read with, so its empty state follows that one.
              <EmptyBlock empty={empty} v={{ archived: shown.archived, q: paging !== null ? shown.page.q : q, tenantHasRows: shown.tenantHasRows, busy, switchTo, clearSearch }} />
            ) : (
              <table className={styles.table}>
                {archived && archivedView.caption !== undefined ? <caption>{archivedView.caption}</caption> : null}
                <thead>
                  <tr>
                    {cols.map((c) => (
                      <th key={c.header} scope="col">
                        {c.header}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {rows.map((item) => (
                    <tr key={item.id} className={struck}>
                      {cols.map((c) => (
                        <td key={c.header}>{c.cell(item)}</td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Refetchable>
          {footer ? footer(rows) : null}
          {paging !== null && !shown.endOfList && !rowsAnswerAnotherSearch && (shown.previous !== null || shown.next !== null) ? (
            <div className={styles.pager}>
              {/* Both slots always render (null or a button), so a button's node survives a page change and keeps focus. */}
              {shown.previous !== null ? (
                <Button size="sm" onClick={() => goTo({ before: shown.previous! })}>
                  {paging.pager(archived).previous}
                </Button>
              ) : null}
              {shown.next !== null ? (
                <Button size="sm" onClick={() => goTo({ after: shown.next! })}>
                  {paging.pager(archived).next}
                </Button>
              ) : null}
            </div>
          ) : null}
        </>
      ) : null}
    </section>
  );
}
