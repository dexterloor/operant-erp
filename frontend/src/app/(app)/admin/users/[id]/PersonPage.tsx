'use client';

import { useCallback, useMemo, useRef, useState } from 'react';
import { PersonLifecycleActions } from '@/components/people/PersonLifecycleActions';
import { RegionError } from '@/components/states/PageStates';
import { Chip } from '@/components/ui/Chip';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { PERSON, PERSON_PAGE } from '@/copy/people';
import type { User } from '@/lib/api/users';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { loadPerson } from '@/lib/people/load';
import { personActions } from '@/lib/people/wired';
import { useApiCaller, useViewerTimeZone } from '../../../BffProvider';
import { useCommittedState } from '../../../useCommittedState';
import { PeopleDenied, PersonNotFound } from '../PeopleStates';
import styles from '../../Lists.module.css';

export interface PersonPageProps {
  /** GetUser's answer on first render (a deleted person is served with `archived_at`). */
  initial: User;
  /** GetCurrentUser's id. */
  viewerId: string;
  /** Holds DeleteUser's codes (from the contract): only then are Delete / Restore person rendered (ST-4c). */
  canDelete: boolean;
}

type Cause = 'unreachable' | 'server-error';

/**
 * The person page's container (b3b-design.md §9.1, §10): it holds the person in state, wires the B-4 lifecycle actions
 * through the BFF, and decides what a failed re-read does — never the B-4 components. GetUser 403 → ST-4b (the only
 * swap to the denied page); 404 → ST-5 with no other sentence; a failed or timed-out read → the region error with the
 * one body (ux-copy-b3b.md C5-2), focus on its heading. The B-4 components render nothing for `session-ended`.
 */
export function PersonPage({ initial, viewerId, canDelete }: PersonPageProps) {
  const caller = useApiCaller();
  const timeZone = useViewerTimeZone();
  const [person, setPerson] = useCommittedState(initial);
  const [swap, setSwap] = useState<'forbidden' | 'not-found' | null>(null);
  const [rereadError, setRereadError] = useState<{ gen: number; cause: Cause } | null>(null);
  const [retrying, setRetrying] = useState(false);
  const h1 = useRef<HTMLHeadingElement>(null);

  const failed = useCallback((f: ReadFailure) => {
    if (f === 'session-ended') return;
    if (f === 'forbidden' || f === 'not-found') {
      setSwap(f);
      return;
    }
    // After the dialog's close has returned focus, so the region error's heading takes it (ux-copy-b3b.md C5 pins).
    setTimeout(() => setRereadError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: f })), 0);
  }, []);

  const actions = useMemo(() => personActions({ caller, id: initial.id, setPerson, onRereadFailed: failed }), [caller, initial.id, setPerson, failed]);

  const retry = async () => {
    if (retrying) return;
    setRetrying(true);
    const deadline = startDeadline(ACTION_DEADLINES_MS.reread);
    const again = await loadPerson(readOf(caller), initial.id, deadline.signal);
    deadline.cancel();
    setRetrying(false);
    if (again.kind === 'ok') {
      await setPerson(again.person);
      setRereadError(null);
      h1.current?.focus();
    } else failed(again.kind);
  };

  if (swap === 'forbidden') return <PeopleDenied />;
  if (swap === 'not-found') return <PersonNotFound />;

  const name = person.display_name;
  return (
    <section className={styles.page}>
      <header className={styles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {person.id === viewerId ? PERSON.ownName(name) : name}
        </h1>
        {person.archived_at !== null ? <Chip tone="neutral">{PERSON.deletedChip}</Chip> : null}
      </header>
      {rereadError ? (
        <RegionError
          key={rereadError.gen}
          heading={rereadError.cause === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG}
          body={PERSON_PAGE.rereadFailed}
          retrying={retrying}
          onRetry={() => void retry()}
        />
      ) : null}
      <PersonLifecycleActions
        person={person}
        viewerId={viewerId}
        canDelete={canDelete}
        canAssign={false}
        deletePerson={actions.deletePerson}
        restorePerson={actions.restorePerson}
        fallbackFocusRef={h1}
        timeZone={timeZone}
      />
    </section>
  );
}
