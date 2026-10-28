'use client';

import Link from 'next/link';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { DateTime } from '@/components/ui/DateTime';
import { Refetchable, RefetchFailedBanner, RegionError } from '@/components/states/PageStates';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { PERSON, USERS_LIST } from '@/copy/people';
import { LIST_STATES } from '@/copy/shell';
import type { User } from '@/lib/api/users';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf } from '@/lib/bff/reads';
import { formatList } from '@/lib/format/lists';
import { loadUsers, type UsersLoad } from '@/lib/people/load';
import { useApiCaller, useViewerTimeZone } from '../../BffProvider';
import { PeopleDenied } from './PeopleStates';
import styles from '../Lists.module.css';

type View = 'live' | 'archived';
type Cause = 'unreachable' | 'server-error';

/** How long the search box must be still before the filter is announced (ST-1a: on the settled filter, never per key). */
export const SEARCH_SETTLE_MS = 400;

const causeOf = (kind: string): Cause => (kind === 'unreachable' ? 'unreachable' : 'server-error');
const headingOf = (c: Cause) => (c === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG);
/** The server's `q` is a case-insensitive prefix of the email (UserReader.php); the client lower-cases the same way. */
const matches = (u: User, q: string) => u.email.toLowerCase().startsWith(q.toLowerCase());
const newestDeletedFirst = (a: User, b: User) => Date.parse(b.archived_at ?? '') - Date.parse(a.archived_at ?? '');

export interface UsersListProps {
  /** The first render's ListUsers answer (live view). A 401 and a 403 never reach here: the server page handles them. */
  initial: UsersLoad;
  viewerId: string;
}

/**
 * `/admin/users` (ux-copy-b3b.md C5-1). The views are bounded (IA §5.6), so search filters on the client and the only
 * refetch is the Archived switch or a Retry. The Archived control is derived from the rows on screen, so a failed
 * switch leaves it showing the view actually shown (RED round 2 R2-L2) under `LIST_STATES.refetchFailed`.
 */
export function UsersList({ initial, viewerId }: UsersListProps) {
  const caller = useApiCaller();
  const timeZone = useViewerTimeZone();
  const [shown, setShown] = useState<{ view: View; users: User[] } | null>(initial.kind === 'ok' ? { view: 'live', users: initial.users } : null);
  const [firstError, setFirstError] = useState<{ gen: number; cause: Cause } | null>(initial.kind === 'ok' ? null : { gen: 0, cause: causeOf(initial.kind) });
  const [refetchError, setRefetchError] = useState<{ cause: Cause; target: View } | null>(null);
  const [denied, setDenied] = useState(false);
  const [busy, setBusy] = useState(false);
  const [q, setQ] = useState('');
  const [settledQ, setSettledQ] = useState('');
  const [interacted, setInteracted] = useState(false);
  const searchRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const t = setTimeout(() => setSettledQ(q), SEARCH_SETTLE_MS);
    return () => clearTimeout(t);
  }, [q]);

  const fetchView = async (view: View) => {
    if (busy) return;
    setBusy(true);
    const deadline = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadUsers(readOf(caller), view === 'archived', deadline.signal);
    deadline.cancel();
    setBusy(false);
    if (r.kind === 'ok') {
      setShown({ view, users: r.users });
      setFirstError(null);
      setRefetchError(null);
      setInteracted(true);
      return;
    }
    if (r.kind === 'session-ended') return;
    if (r.kind === 'forbidden') {
      setDenied(true);
      return;
    }
    if (shown === null) setFirstError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: causeOf(r.kind) }));
    else setRefetchError({ cause: causeOf(r.kind), target: view });
  };

  const view: View = shown?.view ?? 'live';
  const archived = view === 'archived';
  const rows = useMemo(() => {
    if (!shown) return [];
    const filtered = shown.users.filter((u) => matches(u, q));
    return archived ? [...filtered].sort(newestDeletedFirst) : filtered;
  }, [shown, q, archived]);
  const settledCount = useMemo(() => (shown ? shown.users.filter((u) => matches(u, settledQ)).length : 0), [shown, settledQ]);

  if (denied) return <PeopleDenied />;

  const emptyHeadingFor = (query: string) => (query !== '' ? (archived ? USERS_LIST.noArchivedMatch(query) : USERS_LIST.noMatch(query)) : archived ? USERS_LIST.noneArchived : null);
  const announcement = !interacted || !shown ? '' : settledCount > 0 ? USERS_LIST.shown(settledCount, settledQ, archived) : (emptyHeadingFor(settledQ) ?? '');
  const emptyHeading = shown && rows.length === 0 ? emptyHeadingFor(q) : null;

  const clearSearch = () => {
    setQ('');
    searchRef.current?.focus();
  };

  return (
    <section className={styles.page}>
      <header className={styles.header}>
        <h1 tabIndex={-1}>{USERS_LIST.heading}</h1>
      </header>
      {firstError ? (
        <RegionError
          key={firstError.gen}
          heading={headingOf(firstError.cause)}
          body={USERS_LIST.loadFailed}
          retrying={busy}
          autoFocus={firstError.gen > 0}
          onRetry={() => void fetchView('live')}
        />
      ) : null}
      {shown ? (
        <>
          <div className={styles.toolbar}>
            <Button aria-pressed={archived} loading={busy} onClick={() => void fetchView(archived ? 'live' : 'archived')}>
              {USERS_LIST.archived}
            </Button>
            <label className={styles.search}>
              <span>{USERS_LIST.searchLabel}</span>
              <input
                ref={searchRef}
                type="search"
                className={styles.searchInput}
                placeholder={USERS_LIST.searchPlaceholder}
                value={q}
                onChange={(e) => {
                  setQ(e.target.value);
                  setInteracted(true);
                }}
              />
            </label>
          </div>
          {refetchError ? (
            <RefetchFailedBanner heading={headingOf(refetchError.cause)} body={LIST_STATES.refetchFailed} retrying={busy} onRetry={() => void fetchView(refetchError.target)} />
          ) : null}
          <Refetchable busy={busy} announcement={announcement}>
            {emptyHeading !== null ? (
              <div className={styles.empty}>
                <p className={styles.emptyHeading}>{emptyHeading}</p>
                {q !== '' ? (
                  <Button size="sm" onClick={clearSearch}>
                    {USERS_LIST.clearSearch}
                  </Button>
                ) : null}
              </div>
            ) : rows.length > 0 ? (
              <table className={styles.table}>
                {archived ? <caption>{USERS_LIST.archivedOrder}</caption> : null}
                <thead>
                  <tr>
                    <th scope="col">{USERS_LIST.name}</th>
                    <th scope="col">{USERS_LIST.email}</th>
                    {archived ? <th scope="col">{PERSON.deletedOnColumn}</th> : (
                      <>
                        <th scope="col">{USERS_LIST.roles}</th>
                        <th scope="col">{USERS_LIST.status}</th>
                      </>
                    )}
                  </tr>
                </thead>
                <tbody>
                  {rows.map((u) => (
                    <tr key={u.id}>
                      <td>
                        <Link href={`/admin/users/${u.id}`}>{u.id === viewerId ? PERSON.ownName(u.display_name) : u.display_name}</Link>
                      </td>
                      <td>{u.email}</td>
                      {archived ? (
                        <td>{u.archived_at !== null ? <DateTime value={u.archived_at} form="date" timeZone={timeZone} /> : null}</td>
                      ) : (
                        <>
                          <td>{u.roles.length > 0 ? formatList(u.roles.map((r) => r.name)) : USERS_LIST.noRoles}</td>
                          <td>{u.is_active ? USERS_LIST.active : USERS_LIST.switchedOff}</td>
                        </>
                      )}
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
