'use client';

import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { flushSync } from 'react-dom';
import { useRouter } from 'next/navigation';
import { PersonLifecycleActions } from '@/components/people/PersonLifecycleActions';
import { RolePicker } from '@/components/roles/RolePicker';
import { RoleProposals } from '@/components/roles/RoleProposals';
import { RegionError } from '@/components/states/PageStates';
import { Banner } from '@/components/ui/Banner';
import { Chip } from '@/components/ui/Chip';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { COULD_NOT_REACH, NOT_CONFIRMED, NOT_REJECTED, NOT_SAVED, NOT_WITHDRAWN, WENT_WRONG } from '@/copy/headings';
import { PERSON, PERSON_PAGE } from '@/copy/people';
import { PERSON_DELETED_WHILE_OPEN, ROLES_FORBIDDEN, ROLES_FORBIDDEN_SENTENCE } from '@/copy/roles';
import type { User } from '@/lib/api/users';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { loadPersonView, type PersonView, type ViewGates } from '@/lib/people/load';
import type { DeleteTakeover } from '@/lib/people/results';
import { personActions } from '@/lib/people/wired';
import { loadProposalPage } from '@/lib/roles/load';
import type { RereadResult } from '@/lib/roles/results';
import { pickerActions, proposalActions, type RereadContext } from '@/lib/roles/wired';
import { useApiCaller, useViewerTimeZone } from '../../../BffProvider';
import { useCommittedState } from '../../../useCommittedState';
import { PeopleDenied, PersonNotFound } from '../PeopleStates';
import styles from '../../Lists.module.css';

export interface PersonPageGates extends ViewGates {
  /** DeleteUser's codes: Delete / Restore person (ST-4c). */
  readonly delete: boolean;
  /** ConfirmRoleGrantProposal's codes AND the owner marker (§9.3, M-1). */
  readonly confirm: boolean;
}

export interface PersonPageProps {
  /** The first render's whole read (§10): the person, and the role reads the viewer's codes allowed. */
  initial: PersonView;
  /** GetCurrentUser's id. */
  viewerId: string;
  gates: PersonPageGates;
}

type Cause = 'unreachable' | 'server-error';
interface PageBanner {
  gen: number;
  heading: string | null;
  body: ReactNode;
}

const PROPOSAL_HEADINGS = { confirm: NOT_CONFIRMED, reject: NOT_REJECTED, withdraw: NOT_WITHDRAWN } as const;
/** The page banner's heading after the action the re-read followed (ux-copy-b3b.md C6-1): none after a success. */
function headingAfter(c: RereadContext): string | null {
  if (c.kind === 'save') return c.notDone > 0 ? NOT_SAVED : null;
  if (c.kind === 'proposal') return c.ok ? null : PROPOSAL_HEADINGS[c.act];
  return null;
}

/** Whether the action the re-read follows changed something the lists show (FE-R1 R-15a / R-15b). */
function wroteSomething(c: RereadContext): boolean {
  if (c.kind === 'save') return c.done > 0;
  if (c.kind === 'proposal') return c.ok;
  return false;
}

/**
 * The person page's container (b3b-design.md §9.1–§9.3, §10 per-read table; A-10). It holds the whole read in state,
 * wires the B-4 components through the BFF and decides — never the components — what a re-read's answer does:
 * - GetUser 403 → ST-4b (the only swap to the denied page); any read 404 → ST-5, with no other sentence;
 * - `ListOfferedRoles` / a page of the pending walk 403 (`role.assign` lost) → the R1-5 banner at the top of the page,
 *   then the picker and the Waiting group unmount; the person and the lifecycle actions stay;
 * - `ListRoles` / `ListPermissions` 403 (`role.view` lost) → no banner; the panel's props go (R4-2's no-view state);
 * - GetUser 200 with `archived_at` newly set → the deleted person's page; the sentence is announced from the page-level
 *   status region, which stays mounted (RED round 2 R2-M1), or is the page banner's body after a save that did not finish;
 * - a failed or timed-out read → the component's own failure copy (the old props kept), or the region error after a
 *   delete.
 * After a restore's message is on screen the router cache is refreshed once (restore-role-design.md F-1 L-13, R-13: the
 * Archived list was measured stale after Back); `useCommittedState` stays the commit signal. The same once after a
 * delete's message is on screen (R-14: the live list was measured still showing the deleted person after Back). And once
 * after a role change's message is on screen — a Save roles that changed something, or a Confirm, Reject or Withdraw —
 * when its re-read was shown (R-15: the People list's Roles column was measured stale after Back). The components decide
 * when. When the page itself takes over after a write that succeeded — the component that would refresh has unmounted
 * or stood down — the page refreshes once instead, after the commit that shows what took over (FE-R1): a delete whose
 * re-read swapped to ST-4b/ST-5 (R-14b); a restore whose follow-up re-read swapped before the restore's own refresh ran
 * (R-13b, exactly once in either order); a role write whose re-read swapped (R-15a) or found `role.assign` lost (R-15b).
 * On a swap, `router.refresh()` re-renders the route on the server, which returns the same ST-4b/ST-5 page as a new
 * element: it remounts with the same heading and takes focus again (r14-gap-red.md M-1; accepted). Still no refresh
 * after a re-read that failed and was never shown again (R-15's rule: refresh only after a shown re-read).
 *
 * A delete whose re-read did not answer a person (FE-D1b, R-14b): the failure travels in the delete's result, and the
 * page applies it in `onPageTookOver` (required), after the dialog's close has committed, with `flushSync` — so
 * focus moves once, straight to the region error's heading (or the swap page's `<h1>`), and the component writes the
 * after-message only after that.
 */
export function PersonPage({ initial, viewerId, gates }: PersonPageProps) {
  const caller = useApiCaller();
  const router = useRouter();
  const timeZone = useViewerTimeZone();
  const [view, setView] = useCommittedState(initial);
  const viewRef = useRef(initial);
  const [lost, setLost] = useState({ assign: false, view: false });
  const lostRef = useRef(lost);
  // The latest committed values, for the actions' callbacks (they run after render, never during it).
  useLayoutEffect(() => {
    viewRef.current = view;
    lostRef.current = lost;
  }, [view, lost]);
  const [swap, setSwap] = useState<'forbidden' | 'not-found' | null>(null);
  const [rereadError, setRereadError] = useState<{ gen: number; cause: Cause } | null>(null);
  const [banner, setBanner] = useState<PageBanner | null>(null);
  const [retrying, setRetrying] = useState(false);
  const h1 = useRef<HTMLHeadingElement>(null);
  const bannerHeading = useRef<HTMLParagraphElement>(null);
  const pageStatus = useLiveRegion();
  const announcePage = pageStatus.announce;
  const [focusH1, setFocusH1] = useState(0);
  // FE-R1: a refresh the page owes because it took over after a write that succeeded (see the docblock).
  const [takeoverRefreshGen, setTakeoverRefreshGen] = useState(0);
  // R-13b: a restore's own refresh has not run yet while its follow-up re-read is in flight.
  const restoreRefreshOwed = useRef(false);
  // R-13b: a swap after no successful write; its effect refreshes only if the restore's refresh is still owed then.
  const [restoreSwapGen, setRestoreSwapGen] = useState(0);
  const id = initial.person.id;

  const gatesNow = useCallback((): ViewGates => ({ assign: gates.assign && !lostRef.current.assign, view: gates.view && !lostRef.current.view }), [gates.assign, gates.view]);

  /** The whole re-read under `signal`, applied per §10; resolves after the commit that shows it. */
  const reread = useCallback(
    async (signal: AbortSignal, context: RereadContext): Promise<RereadResult> => {
      const r = await loadPersonView(readOf(caller), id, gatesNow(), signal);
      if (r.kind === 'session-ended') return { kind: 'failed', failure: 'unreachable' };
      if (r.kind === 'forbidden' || r.kind === 'not-found') {
        setSwap(r.kind);
        // R-15a (a role write changed the lists): the swap unmounts the component that would refresh, so the page does,
        // once, after the swap commits. R-13b (a restore's own refresh may not have run): decided in the effect, not
        // here — whichever of `onRestored` and that effect runs first clears the owed flag, and the other skips
        // (fe-r1-red.md L-3), so the order of React's passive effects cannot make it twice.
        if (wroteSomething(context)) setTakeoverRefreshGen((g) => g + 1);
        else setRestoreSwapGen((g) => g + 1);
        return { kind: 'failed', failure: r.kind };
      }
      if (r.kind !== 'ok') return { kind: 'failed', failure: r.kind };
      const before = viewRef.current;
      const nowDeleted = before.person.archived_at === null && r.view.person.archived_at !== null;
      const heading = headingAfter(context);
      if (r.lost.assign || r.lost.view) setLost((cur) => ({ assign: cur.assign || r.lost.assign, view: cur.view || r.lost.view }));
      // R-15b: the write changed the lists, and the picker / Waiting group now unmount without reporting it; no swap, so
      // the refresh re-renders this same page (in the commit that shows the R1-5 banner or its sentence).
      if (r.lost.assign && wroteSomething(context)) setTakeoverRefreshGen((g) => g + 1);
      if (r.lost.assign) {
        setBanner((cur) => ({
          gen: (cur?.gen ?? 0) + 1,
          heading,
          body: (
            <p>
              {ROLES_FORBIDDEN.before}
              <em>{ROLES_FORBIDDEN.label}</em>
              {ROLES_FORBIDDEN.after}
            </p>
          ),
        }));
        if (heading === null) {
          announcePage(ROLES_FORBIDDEN_SENTENCE);
          setFocusH1((n) => n + 1);
        }
      } else if (nowDeleted) {
        if (heading !== null) setBanner((cur) => ({ gen: (cur?.gen ?? 0) + 1, heading, body: <p>{PERSON_DELETED_WHILE_OPEN}</p> }));
        else {
          announcePage(PERSON_DELETED_WHILE_OPEN);
          setFocusH1((n) => n + 1);
        }
      }
      await setView(r.view);
      return r.lost.assign ? { kind: 'failed', failure: 'forbidden' } : { kind: 'ok' };
    },
    [caller, id, gatesNow, setView, announcePage],
  );

  // After a re-read the page took over: focus the banner's heading, else the page h1 (§10 "Focus").
  const bannerGen = banner?.gen ?? null;
  useLayoutEffect(() => {
    if (bannerGen !== null && banner?.heading !== null) bannerHeading.current?.focus();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [bannerGen]);
  useLayoutEffect(() => {
    if (focusH1 > 0) h1.current?.focus();
  }, [focusH1]);

  const setPersonOnly = useCallback(
    async (person: User) => {
      const wasArchived = viewRef.current.person.archived_at !== null;
      await setView({ ...viewRef.current, person });
      // A restore brings the person back: their offered roles were read for a deleted person, so read them again.
      if (wasArchived && person.archived_at === null && (gatesNow().assign || gatesNow().view)) {
        restoreRefreshOwed.current = true; // R-13b: cleared by the restore's own refresh, or taken by a swap first.
        const d = startDeadline(ACTION_DEADLINES_MS.reread);
        void reread(d.signal, { kind: 'none' }).finally(() => d.cancel());
      }
    },
    [setView, gatesNow, reread],
  );

  const failed = useCallback((f: ReadFailure) => {
    if (f === 'session-ended') return;
    if (f === 'forbidden' || f === 'not-found') {
      setSwap(f);
      return;
    }
    // After the dialog has closed, so the region error's heading takes focus (ux-copy-b3b.md C5 pins). This timer alone
    // did not guarantee it — Radix returns focus on a timer of its own — the Dialog now leaves focus a page has already
    // moved (b3b-followup "Flake").
    setTimeout(() => setRereadError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: f })), 0);
  }, []);

  // FE-R1 (R-14b, FE-D1b): a delete's re-read failed; the failure arrives in the delete's result, and the lifecycle
  // component calls this once the dialog's close has committed. `flushSync`: the region error (or ST-4b / ST-5) mounts
  // and its heading takes focus inside this call (a sync commit flushes its passive effects at its end), so the
  // component's after-message, written when this returns, follows the one focus move.
  const takeOverAfterDelete = useCallback((f: DeleteTakeover) => {
    flushSync(() => {
      if (f === 'forbidden' || f === 'not-found') {
        setSwap(f);
        setTakeoverRefreshGen((g) => g + 1); // R-14b: the component unmounts before its own refresh can run.
      } else setRereadError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: f }));
    });
  }, []);

  const showPage = useCallback(
    async (direction: 'after' | 'before', cursor: string, signal: AbortSignal) => {
      const p = await loadProposalPage(readOf(caller), id, { direction, value: cursor }, signal);
      const current = viewRef.current;
      if (p.kind === 'ok' && current.access) await setView({ ...current, access: { ...current.access, page: p.value } });
      else if (p.kind === 'forbidden' || p.kind === 'not-found') {
        // The page takes over exactly as on a re-read (§10): read the whole page again under its own deadline.
        const d = startDeadline(ACTION_DEADLINES_MS.reread);
        await reread(d.signal, { kind: 'none' }).finally(() => d.cancel());
      }
    },
    [caller, id, reread, setView],
  );

  // Each wrapper calls the latest callback when the action runs, never while rendering: the factories only store them,
  // so the refs those callbacks read are read after render (the lint rule cannot see that the factories do not call).
  // eslint-disable-next-line react-hooks/refs -- stored, not called, during render
  const lifecycle = useMemo(() => personActions({ caller, id, setPerson: (p) => setPersonOnly(p) }), [caller, id, setPersonOnly]);
  // eslint-disable-next-line react-hooks/refs -- stored, not called, during render
  const picker = useMemo(() => pickerActions({ caller, personId: id, reread: (s, c) => reread(s, c) }), [caller, id, reread]);
  const proposals = useMemo(
    // eslint-disable-next-line react-hooks/refs -- stored, not called, during render
    () => proposalActions({ caller, personId: id, reread: (s, c) => reread(s, c), onPage: (d, c, s) => showPage(d, c, s) }),
    [caller, id, reread, showPage],
  );

  // Cache invalidation only (never the commit signal): after Back, neither the Archived list (restore) nor the live list
  // (delete) may show this person where they no longer are, nor the lists their old roles (R-15). One call per event.
  const refreshRouterCache = useCallback(() => router.refresh(), [router]);
  // The restore's own refresh (R-13); a swap that lands first takes it over instead (R-13b).
  const onRestored = useCallback(() => {
    restoreRefreshOwed.current = false;
    refreshRouterCache();
  }, [refreshRouterCache]);
  // FE-R1: after the commit that shows what the page took over with (above the swap returns: hook order).
  useEffect(() => {
    if (takeoverRefreshGen > 0) {
      restoreRefreshOwed.current = false; // this refresh also covers a restore's, if one is still owed
      refreshRouterCache();
    }
    // Once per takeover only.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [takeoverRefreshGen]);
  // R-13b: a swap after no write refreshes only if the restore's own refresh has still not run when this effect does.
  useEffect(() => {
    if (restoreSwapGen > 0 && restoreRefreshOwed.current) {
      restoreRefreshOwed.current = false;
      refreshRouterCache();
    }
    // Once per swap only.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [restoreSwapGen]);

  const retry = async () => {
    if (retrying) return;
    setRetrying(true);
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await reread(d.signal, { kind: 'none' });
    d.cancel();
    setRetrying(false);
    if (r.kind === 'ok') {
      setRereadError(null);
      h1.current?.focus();
    } else if (r.failure === 'unreachable' || r.failure === 'server-error') failed(r.failure);
  };

  if (swap === 'forbidden') return <PeopleDenied />;
  if (swap === 'not-found') return <PersonNotFound />;

  const { person, access, catalogue } = view;
  const live = person.archived_at === null;
  const own = person.id === viewerId;
  const showAccess = gates.assign && !lost.assign && access !== null && live;
  const pendingRoleIds = new Set((access?.pending ?? []).map((p) => p.role.id));
  return (
    <section className={styles.page}>
      <header className={styles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {own ? PERSON.ownName(person.display_name) : person.display_name}
        </h1>
        {!live ? <Chip tone="neutral">{PERSON.deletedChip}</Chip> : null}
      </header>
      {/* The page-level status region: mounted from first render and never inside a part that unmounts (R2-M1). */}
      <LiveRegion message={pageStatus.message} visuallyHidden />
      {banner ? (
        <Banner tone="danger" live="none" heading={banner.heading} ref={bannerHeading}>
          {banner.body}
        </Banner>
      ) : null}
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
        canDelete={gates.delete}
        canAssign={gates.assign && !lost.assign}
        deletePerson={lifecycle.deletePerson}
        restorePerson={lifecycle.restorePerson}
        onGiveRole={() => document.querySelector<HTMLInputElement>('[data-surface="role-picker"] input')?.focus()}
        fallbackFocusRef={h1}
        timeZone={timeZone}
        onRestored={onRestored}
        onDeleted={refreshRouterCache}
        onPageTookOver={takeOverAfterDelete}
      />
      {showAccess ? (
        <>
          <RolePicker
            person={person}
            viewerId={viewerId}
            offered={access.offered}
            pendingRoleIds={pendingRoleIds}
            roles={gates.view && !lost.view ? catalogue?.roles : undefined}
            permissions={gates.view && !lost.view ? catalogue?.permissions : undefined}
            actions={picker}
            onRolesChanged={refreshRouterCache}
          />
          {!own ? <RoleProposals proposals={access.page} canConfirm={gates.confirm} actions={proposals} fallbackFocusRef={h1} timeZone={timeZone} marginExposure={person.margin_exposure} onProposalChanged={refreshRouterCache} /> : null}
        </>
      ) : null}
    </section>
  );
}
