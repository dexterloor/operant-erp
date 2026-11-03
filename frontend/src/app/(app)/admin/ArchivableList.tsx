'use client';

import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { flushSync } from 'react-dom';
import { usePathname, useRouter } from 'next/navigation';
import { Button } from '@/components/ui/Button';
import { LiveRegion } from '@/components/ui/LiveRegion';
import { Refetchable, RefetchFailedBanner, RegionError } from '@/components/states/PageStates';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { LIST_STATES } from '@/copy/shell';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type Read, type ReadFailure } from '@/lib/bff/reads';
import { useApiCaller } from '../BffProvider';
import styles from './Lists.module.css';

/** How long the search box must be still before the filter is announced (ST-1a: on the settled filter, never per key). */
export const SEARCH_SETTLE_MS = 400;

type Cause = 'unreachable' | 'server-error';
const causeOf = (kind: string): Cause => (kind === 'unreachable' ? 'unreachable' : 'server-error');
const headingOf = (c: Cause) => (c === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG);
const newestDeletedFirst = (a: { archived_at: string | null }, b: { archived_at: string | null }) => Date.parse(b.archived_at ?? '') - Date.parse(a.archived_at ?? '');

export type ListLoad<T> = { readonly kind: 'ok'; readonly items: T[] } | { readonly kind: ReadFailure };

export interface ListCopy {
  heading: string;
  archived: string;
  archivedOrder: string;
  searchLabel: string;
  searchPlaceholder: string;
  clearSearch: string;
  loadFailed: string;
  /** The live view with no rows and no search; null when that state cannot occur (ux-copy-b3b.md C5-1: no copy). */
  noneLive: string | null;
  noneArchived: string;
  noMatch: (q: string) => string;
  noArchivedMatch: (q: string) => string;
  shown: (n: number, q: string, archived: boolean) => string;
}

export interface Column<T> {
  header: string;
  cell: (item: T) => ReactNode;
}

export interface ArchivableListProps<T> {
  initial: ListLoad<T>;
  /** The view the server loaded for `initial`, from the address (`?archived=true`, b3b-followup C-4). */
  initialArchived?: boolean;
  load: (read: Read, archived: boolean, signal: AbortSignal) => Promise<ListLoad<T>>;
  /** The client filter: the same case-insensitive prefix the server's `q` uses. */
  matches: (item: T, lowerQ: string) => boolean;
  columns: (archived: boolean) => Column<T>[];
  copy: ListCopy;
  /** ST-4b for this list, shown when a later fetch answers 403 (the permission was lost). */
  denied: ReactNode;
}

/**
 * A bounded list with a live and an Archived view (IA §5.6; ux-copy-b3b.md C5-1, C7-1). Search filters on the client;
 * the only refetch is the Archived switch or a Retry. The view is in the address (b3b-followup C-4): the server page
 * reads `?archived=true` for the first render, and a switch that loaded replaces the address through the App Router
 * (`router.replace`, never `history.replaceState`, which the router ignores and Back then contradicts — RED H-1), which
 * also re-renders the server page once (one extra read, accepted). A failed switch leaves the address alone. A Retry
 * after a failed first load retries the view the address names. The Archived control is derived from the rows on screen, so a
 * failed switch leaves it on the view actually shown (RED round 2 R2-L2) under `LIST_STATES.refetchFailed`. The settled
 * announcement starts after the first interaction (first paint is not announced, ST-2a) and never says "0 shown": an
 * empty result speaks its empty heading. The status region is mounted empty from first render at the section root, so
 * a successful Retry after a failed first load is a change it announces (principle 6; RED M-3). That Retry's button
 * unmounts with the error, so focus moves to the h1 — with the rows on screen — and only then is the sentence written
 * (RED X-2; the RolePage/PersonPage re-read precedent; FE-D1 order).
 */
export function ArchivableList<T extends { id: string; archived_at: string | null }>({ initial, initialArchived = false, load, matches, columns, copy, denied }: ArchivableListProps<T>) {
  const caller = useApiCaller();
  const router = useRouter();
  const pathname = usePathname();
  /** The view the address names; it changes only after a load of the other view succeeded. */
  const addressArchived = useRef(initialArchived);
  const [shown, setShown] = useState<{ archived: boolean; items: T[] } | null>(initial.kind === 'ok' ? { archived: initialArchived, items: initial.items } : null);
  const [firstError, setFirstError] = useState<{ gen: number; cause: Cause } | null>(initial.kind === 'ok' ? null : { gen: 0, cause: causeOf(initial.kind) });
  const [refetchError, setRefetchError] = useState<{ cause: Cause; archived: boolean } | null>(null);
  const [isDenied, setDenied] = useState(false);
  const [busy, setBusy] = useState(false);
  const [q, setQ] = useState('');
  const [settledQ, setSettledQ] = useState('');
  const [interacted, setInteracted] = useState(false);
  /** While true, the settled sentence waits: a Retry of a failed first load writes it only after the h1 took focus. */
  const [quiet, setQuiet] = useState(false);
  const searchRef = useRef<HTMLInputElement>(null);
  const h1 = useRef<HTMLHeadingElement>(null);

  useEffect(() => {
    const t = setTimeout(() => setSettledQ(q), SEARCH_SETTLE_MS);
    return () => clearTimeout(t);
  }, [q]);

  const fetchView = async (archived: boolean) => {
    if (busy) return;
    setBusy(true);
    const deadline = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await load(readOf(caller), archived, deadline.signal);
    deadline.cancel();
    setBusy(false);
    if (r.kind === 'ok') {
      const recovering = shown === null;
      // X-2: a Retry of a failed first load unmounts its own button. Commit the rows first (sentence held), then focus
      // the h1, then let the sentence through — focus first, then announce (FE-D1's flushSync order).
      flushSync(() => {
        if (recovering) setQuiet(true);
        setShown({ archived, items: r.items });
        setFirstError(null);
        setRefetchError(null);
        setInteracted(true);
      });
      if (recovering) {
        h1.current?.focus();
        setQuiet(false);
      }
      if (archived !== addressArchived.current) {
        addressArchived.current = archived;
        router.replace(pathname + (archived ? '?archived=true' : ''), { scroll: false });
      }
      return;
    }
    if (r.kind === 'session-ended') return;
    if (r.kind === 'forbidden') {
      setDenied(true);
      return;
    }
    if (shown === null) setFirstError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: causeOf(r.kind) }));
    else setRefetchError({ cause: causeOf(r.kind), archived });
  };

  const archived = shown?.archived ?? false;
  const filter = (query: string) => (shown ? shown.items.filter((i) => matches(i, query.toLowerCase())) : []);
  const rows = useMemo(() => {
    const f = shown ? shown.items.filter((i) => matches(i, q.toLowerCase())) : [];
    return archived ? [...f].sort(newestDeletedFirst) : f;
  }, [shown, q, archived, matches]);

  if (isDenied) return <>{denied}</>;

  const emptyHeadingFor = (query: string) => (query !== '' ? (archived ? copy.noArchivedMatch(query) : copy.noMatch(query)) : archived ? copy.noneArchived : copy.noneLive);
  const settledCount = filter(settledQ).length;
  const announcement = !interacted || !shown || quiet || busy ? '' : settledCount > 0 ? copy.shown(settledCount, settledQ, archived) : (emptyHeadingFor(settledQ) ?? '');
  const emptyHeading = shown && rows.length === 0 ? emptyHeadingFor(q) : null;
  const cols = columns(archived);

  const clearSearch = () => {
    setQ('');
    searchRef.current?.focus();
  };

  return (
    <section className={styles.page}>
      <header className={styles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {copy.heading}
        </h1>
      </header>
      {/* Mounted from first render, above every branch below, so it never arrives already speaking (principle 6). */}
      <LiveRegion message={announcement} visuallyHidden />
      {firstError ? (
        <RegionError key={firstError.gen} heading={headingOf(firstError.cause)} body={copy.loadFailed} retrying={busy} autoFocus={firstError.gen > 0} onRetry={() => void fetchView(initialArchived)} />
      ) : null}
      {shown ? (
        <>
          <div className={styles.toolbar}>
            <Button aria-pressed={archived} loading={busy} onClick={() => void fetchView(!archived)}>
              {copy.archived}
            </Button>
            <label className={styles.search}>
              <span>{copy.searchLabel}</span>
              <input
                ref={searchRef}
                type="search"
                className={styles.searchInput}
                placeholder={copy.searchPlaceholder}
                value={q}
                onChange={(e) => {
                  setQ(e.target.value);
                  setInteracted(true);
                }}
              />
            </label>
          </div>
          {refetchError ? <RefetchFailedBanner heading={headingOf(refetchError.cause)} body={LIST_STATES.refetchFailed} retrying={busy} onRetry={() => void fetchView(refetchError.archived)} /> : null}
          <Refetchable busy={busy} liveRegion={false}>
            {emptyHeading !== null ? (
              <div className={styles.empty}>
                <p className={styles.emptyHeading}>{emptyHeading}</p>
                {q !== '' ? (
                  <Button size="sm" onClick={clearSearch}>
                    {copy.clearSearch}
                  </Button>
                ) : null}
              </div>
            ) : rows.length > 0 ? (
              <table className={styles.table}>
                {archived ? <caption>{copy.archivedOrder}</caption> : null}
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
                    <tr key={item.id}>
                      {cols.map((c) => (
                        <td key={c.header}>{c.cell(item)}</td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : null}
          </Refetchable>
        </>
      ) : null}
    </section>
  );
}
