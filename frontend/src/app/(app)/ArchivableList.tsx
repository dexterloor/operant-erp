'use client';

import { useEffect, useImperativeHandle, useMemo, useRef, useState, type ReactNode, type Ref, type RefObject } from 'react';
import { flushSync } from 'react-dom';
import { Button } from '@/components/ui/Button';
import { LiveRegion } from '@/components/ui/LiveRegion';
import { Refetchable, RefetchFailedBanner, RegionError } from '@/components/states/PageStates';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { LIST_STATES } from '@/copy/shell';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type Read, type ReadFailure } from '@/lib/bff/reads';
import { useApiCaller } from './BffProvider';
import styles from './Lists.module.css';

/** How long the search box must be still before the filter is announced (ST-1a: on the settled filter, never per key). */
export const SEARCH_SETTLE_MS = 400;

type Cause = 'unreachable' | 'server-error';
/** Which control started a fetch: a view switch (the toggle, a header control, an empty-state action), the banner's Retry, or the first-load Retry. */
type Origin = 'switch' | 'banner' | 'first';
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

/** The Archived view's order (A-17 item 7). `as-served` keeps the server's order and shows no caption (ST-9b). */
export type ArchivedView = { order: 'newest-deleted-first'; caption: string; rows: 'plain' | 'struck' } | { order: 'as-served'; rows: 'plain' | 'struck' };

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
      /** The client filter. It need not mirror the server's `q`, and the list never sends `q`. */
      matches: (item: T, lowerQ: string) => boolean;
    }
  | { search: false; matches?: undefined };

export type ArchivableListProps<T> = BaseProps<T> & SearchOption<T>;

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

interface Loaded<T> {
  archived: boolean;
  items: T[];
  tenantHasRows: boolean | undefined;
}

/**
 * A bounded list with a live and an Archived view (IA §5.6; ux-copy-b3b.md C5-1, C7-1), the core of every archivable list
 * (Revision 7 A-17). It never touches the address: the caller passes `initialArchived` and, to keep the view in the
 * address, `onViewLoaded={useArchivedAddress()}` (A-17 item 1). Search filters on the client; the only refetches are a
 * view switch, a Retry and `controller.reread()`. The view control is derived from the rows on screen, so a failed
 * switch leaves it on the view actually shown (RED round 2 R2-L2) under `LIST_STATES.refetchFailed`. The settled
 * announcement starts after the first interaction (first paint is not announced, ST-2a) and never says "0 shown": an
 * empty result speaks its empty heading — `empty` is called with the settled query for the announcement and with the
 * live query for the empty state on screen. The status region is mounted empty from first render at the section root,
 * so a successful Retry after a failed first load is a change it announces (principle 6; RED M-3).
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
 * Retry is in flight (`busy`), so a repeat failure with the same cause is still a text change (defect B, M-4). A reread
 * does neither: its count is never announced (M-2), and a failed reread raises no banner.
 */
export function ArchivableList<T extends { id: string; archived_at: string | null }>(props: ArchivableListProps<T>) {
  const { initial, initialArchived = false, onViewLoaded, load, columns, copy, header, headingId, empty, archivedView, notice, footer, controller, denied } = props;
  if (process.env.NODE_ENV !== 'production' && copy.heading === null && headingId === undefined) {
    throw new Error('ArchivableList needs a heading: give copy.heading (the page h1) or headingId (the section heading), the focus target after a Retry (FE-R3 H-2).');
  }
  const matches = props.search === false ? null : props.matches;
  const searchCopy: SearchCopy | null = props.search === false ? null : (props.search ?? { label: copy.searchLabel ?? '', placeholder: copy.searchPlaceholder ?? '' });

  const caller = useApiCaller();
  const firstShown: Loaded<T> | null = initial.kind === 'ok' ? { archived: initialArchived, items: initial.items, tenantHasRows: initial.tenantHasRows } : null;
  /** The view last loaded; `onViewLoaded` fires when a load lands on the other one. */
  const lastLoaded = useRef(initialArchived);
  const [shown, setShownState] = useState<Loaded<T> | null>(firstShown);
  const shownRef = useRef<Loaded<T> | null>(firstShown);
  /** What the settled announcement counts: the rows of the last switch, Retry or settled search — never a reread's (M-2). */
  const [announced, setAnnounced] = useState<Loaded<T> | null>(firstShown);
  const [firstError, setFirstError] = useState<{ gen: number; cause: Cause } | null>(initial.kind === 'ok' || initial.kind === 'forbidden' ? null : { gen: 0, cause: causeOf(initial.kind) });
  const [refetchError, setRefetchError] = useState<{ cause: Cause; archived: boolean } | null>(null);
  const [isDenied, setDenied] = useState(initial.kind === 'forbidden');
  const [busy, setBusy] = useState(false);
  const [q, setQ] = useState('');
  const [settledQ, setSettledQ] = useState('');
  const [interacted, setInteracted] = useState(false);
  /** While true, the settled sentence waits: a successful fetch writes it only after any focus move. */
  const [quiet, setQuiet] = useState(false);
  /** The heading of the last failed refetch, spoken in place of the settled sentence until a fetch succeeds or a search the user typed settles. */
  const [failureNote, setFailureNote] = useState<string | null>(null);
  /** The user changed the search since the last failure: the note clears when that search settles, never on the keystroke. */
  const searchedSinceFailure = useRef(false);
  const viewControlRef = useRef<HTMLButtonElement | null>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const h1 = useRef<HTMLHeadingElement>(null);
  /** Loads run one after another: a reread waits for a load in flight (L-8), and a switch for a reread. */
  const queue = useRef<Promise<unknown>>(Promise.resolve());

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
  const readView = async (archived: boolean) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await load(readOf(caller), archived, deadline.signal);
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

  useEffect(() => {
    const t = setTimeout(() => {
      setSettledQ(q);
      setAnnounced(shownRef.current);
      if (searchedSinceFailure.current) {
        searchedSinceFailure.current = false;
        setFailureNote(null);
      }
    }, SEARCH_SETTLE_MS);
    return () => clearTimeout(t);
  }, [q]);

  const fetchView = async (archived: boolean, from: Origin) => {
    if (busy) return;
    /** The control the person used, if it had focus (a mouse click in Safari leaves `<body>`): removed by the new view → focus is moved. */
    const started = document.activeElement;
    setBusy(true);
    const r = await run(() => readView(archived));
    setBusy(false);
    if (r.kind === 'ok') {
      // X-2 / FE-R3: commit the rows first (sentence held), then, only if focus was lost, focus the list's heading (first
      // load) or the new view's switch control (banner; a switch whose own control is gone), then let the sentence
      // through — focus first, then announce (FE-D1's flushSync order).
      const next = { archived, items: r.items, tenantHasRows: r.tenantHasRows };
      flushSync(() => {
        setQuiet(true);
        setShown(next);
        setAnnounced(next);
        setFirstError(null);
        setRefetchError(null);
        setFailureNote(null);
        setInteracted(true);
      });
      const controlGone = started instanceof HTMLElement && started !== document.body && !started.isConnected;
      if (focusWasLost()) {
        if (from === 'first') listHeading()?.focus();
        else if (from === 'banner' || controlGone) (connected(viewControlRef.current) ?? listHeading())?.focus();
      }
      setQuiet(false);
      viewLoaded(archived);
      return;
    }
    if (r.kind === 'session-ended') return;
    if (r.kind === 'forbidden') {
      setDenied(true);
      return;
    }
    if (shownRef.current === null) setFirstError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: causeOf(r.kind) }));
    else {
      setRefetchError({ cause: causeOf(r.kind), archived });
      searchedSinceFailure.current = false;
      setFailureNote(headingOf(causeOf(r.kind)));
    }
  };

  useImperativeHandle(controller, () => ({
    reread: () =>
      run(async (): Promise<RereadResult> => {
        // RED L-1 option (a): before any view has loaded there is nothing on screen to refresh.
        if (shownRef.current === null) return { kind: 'server-error' };
        const view = shownRef.current.archived;
        const r = await readView(view);
        if (r.kind === 'ok') {
          flushSync(() => setShown({ archived: view, items: r.items, tenantHasRows: r.tenantHasRows }));
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

  const switchTo = (to: boolean) => void fetchView(to, 'switch');
  const clearSearch = () => {
    searchedSinceFailure.current = true;
    setQ('');
    searchRef.current?.focus();
  };
  // The announcement reads only the heading, so it is asked with inert actions (never rendered).
  const emptyHeadingFor = (view: Loaded<T>, query: string) => empty({ archived: view.archived, q: query, tenantHasRows: view.tenantHasRows, busy, switchTo: NOOP, clearSearch: NOOP })?.heading ?? '';
  const settledCount = announced ? (matches ? announced.items.filter((i) => matches(i, settledQ.toLowerCase())).length : announced.items.length) : 0;
  // `busy` blanks the region while a fetch is in flight, so the next failure (same heading) or success is a text change.
  const announcement = busy ? '' : failureNote !== null ? failureNote : !interacted || !announced || quiet ? '' : settledCount > 0 ? copy.shown(settledCount, settledQ, announced.archived) : emptyHeadingFor(announced, settledQ);
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
      <LiveRegion message={announcement} visuallyHidden />
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
                  setQ(e.target.value);
                  setInteracted(true);
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
          {refetchError ? <RefetchFailedBanner heading={headingOf(refetchError.cause)} body={LIST_STATES.refetchFailed} retrying={busy} onRetry={() => void fetchView(refetchError.archived, 'banner')} /> : null}
          {notice ? notice(archived) : null}
          <Refetchable busy={busy}>
            {rows.length === 0 ? (
              // The empty state on screen follows the live query; the announcement, the settled one (FE-R4 keeps this split).
              <EmptyBlock empty={empty} v={{ archived: shown.archived, q, tenantHasRows: shown.tenantHasRows, busy, switchTo, clearSearch }} />
            ) : (
              <table className={styles.table}>
                {archived && archivedView.order === 'newest-deleted-first' ? <caption>{archivedView.caption}</caption> : null}
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
        </>
      ) : null}
    </section>
  );
}
