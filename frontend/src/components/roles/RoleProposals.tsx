'use client';

import { useId, useLayoutEffect, useRef, useState, type ReactNode, type RefObject } from 'react';
import type { Collection } from '@/lib/api/envelope';
import type { RoleGrantProposal } from '@/lib/api/proposals';
import type { ConfirmResult, RejectResult, RoleProposalActions, WithdrawResult } from '@/lib/roles/results';
import { PICKER, ROLES_FORBIDDEN, WAITING } from '@/copy/roles';
import { COULD_NOT_REACH, NOT_CONFIRMED, NOT_REJECTED, NOT_WITHDRAWN, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PAGE_STATES } from '@/copy/shell';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DateTime } from '@/components/ui/DateTime';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import styles from './RolePicker.module.css';

type Act = 'confirm' | 'reject' | 'withdraw';
type ActResult = ConfirmResult | RejectResult | WithdrawResult;

export interface RoleProposalsProps {
  /**
   * The visible page of `ListRoleGrantProposals?user={id}&status=pending`, as served — expired rows included (4b-3). The
   * page renders this group only for a `role.assign` holder and never on the viewer's own page (round-6 §2a; Q-8).
   */
  proposals: Collection<RoleGrantProposal>;
  /**
   * Confirm/Reject are shown only to an owner who also manages roles (ST-4f's one named exception: the owner marker AND
   * `role.assign`) — decided by the caller from served data.
   */
  canConfirm: boolean;
  actions: RoleProposalActions;
  /** The page's `<h1>`: focus goes there when the row whose button was pressed leaves the view (4b-4 pattern). */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /**
   * The viewer's IANA zone, from the session (`tz`, captured at sign-in). Never read in render: a browser default would
   * differ from the server's HTML.
   */
  timeZone: string;
  /**
   * The served `person.margin_exposure`, passed by the page (b4-step5-gaps.md Rev 2 item 2). When a Confirm's re-read turns
   * it from false to true, the RULED held-form sentence is appended to the confirmation message (4b-2's "made it appear").
   */
  marginExposure: boolean;
}

interface Notice {
  id: number;
  heading: string;
  body: ReactNode;
}

const HEADINGS: Record<Act, string> = { confirm: NOT_CONFIRMED, reject: NOT_REJECTED, withdraw: NOT_WITHDRAWN };

/** The banner for a non-success answer, or null for a 401 (R1-4). A refusal's `message` is the server's, verbatim. */
function noticeFor(act: Act, result: Exclude<ActResult, { kind: 'confirmed' | 'rejected' | 'withdrawn' }>, id: number): Notice | null {
  switch (result.kind) {
    case 'refused':
      return { id, heading: HEADINGS[act], body: result.message !== '' ? result.message : REFUSED_WITHOUT_MESSAGE };
    case 'not-found':
      return { id, heading: HEADINGS[act], body: `${PAGE_STATES.notFoundHeading} ${PAGE_STATES.notFoundBody}` };
    case 'forbidden':
      return {
        id,
        heading: HEADINGS[act],
        body: (
          <>
            {ROLES_FORBIDDEN.before}
            <em>{ROLES_FORBIDDEN.label}</em>
            {ROLES_FORBIDDEN.after}
          </>
        ),
      };
    case 'unreachable':
      return { id, heading: COULD_NOT_REACH, body: WAITING.interrupted[act] };
    case 'server-error':
      return { id, heading: WENT_WRONG, body: WAITING.interrupted[act] };
    case 'session-ended':
      return null;
  }
}

/**
 * "Waiting for an owner" (round-6 §2a as amended by R4-1, 4b-3, 4c; b4-owed-copy R1-2/R1-5/R1-6), props only — a SIBLING
 * of `RolePicker` (RED M-3). Rows come only from the served pending proposals: never counted in, merged into or
 * highlighted in the effective permissions. Expired is drawn ONLY from the served `is_expired` (4c S-2): no clock is read.
 * **Withdraw** is rendered, enabled, for every viewer of the group (4b-3: the page does not compare `proposed_by` with the
 * viewer); the server refuses a non-proposer after the click. The outermost element is always rendered, so its banner
 * shows even when the group has no rows; the group itself is not rendered without rows (R4-1).
 */
export function RoleProposals({ proposals, canConfirm, actions, fallbackFocusRef, timeZone, marginExposure }: RoleProposalsProps) {
  const headingId = useId();
  const [busy, setBusy] = useState<{ id: string; act: Act } | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [after, setAfter] = useState<{
    gen: number;
    /** Set on a 2xx: what to announce, and whether the re-read that followed succeeded. */
    done: { act: Act; role: string; rereadOk: boolean; marginBefore: boolean } | null;
  } | null>(null);
  const noticeHeading = useRef<HTMLParagraphElement>(null);
  const groupHeading = useRef<HTMLHeadingElement>(null);
  const status = useLiveRegion();

  const gen = after?.gen ?? null;
  // Layout effect: focus moves in the commit that shows the result, never a task later.
  useLayoutEffect(() => {
    if (gen === null || !after) return;
    const { done } = after;
    if (done) {
      // Gaps Rev 1 P-4: focus first — the Waiting <h2> if the group is still rendered, else the page h1, NEVER a row's
      // action button (RED H-2); after a failed re-read focus stays on the opener (RED M-5). Then the message (L-3).
      if (done.rereadOk) (groupHeading.current?.isConnected ? groupHeading.current : fallbackFocusRef.current)?.focus();
      const parts = [WAITING.done[done.act](done.role)];
      if (!done.rereadOk) parts.push(WAITING.listStale);
      else if (done.act === 'confirm' && !done.marginBefore && marginExposure) parts.push(PICKER.marginHeld);
      status.announce(parts.join(' '));
      return;
    }
    // A failure always shows a banner (a 401 returns before `after` is set), so focus always has a heading to go to.
    noticeHeading.current?.focus();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [gen]);

  const run = async (act: Act, proposal: RoleGrantProposal) => {
    if (busy) return;
    const marginBefore = marginExposure;
    setBusy({ id: proposal.id, act });
    setNotice(null); // R1-2: it clears on the next action in the group.
    let result: ActResult;
    try {
      result = await (act === 'confirm' ? actions.confirm(proposal.id) : act === 'reject' ? actions.reject(proposal.id) : actions.withdraw(proposal.id));
    } catch {
      result = { kind: 'unreachable' };
    }
    if (result.kind === 'session-ended') {
      setBusy(null);
      return;
    }
    const succeeded = result.kind === 'confirmed' || result.kind === 'rejected' || result.kind === 'withdrawn';
    const next = succeeded ? null : noticeFor(act, result as Exclude<ActResult, { kind: 'confirmed' | 'rejected' | 'withdrawn' }>, (notice?.id ?? 0) + 1);
    // The page re-reads after every answer (a confirm changes the person's roles; a refusal may mean the row is stale).
    let rereadOk = false;
    try {
      rereadOk = (await actions.reread()).kind === 'ok';
    } catch {
      rereadOk = false;
    }
    setBusy(null);
    setNotice(next);
    setAfter((cur) => ({ gen: (cur?.gen ?? 0) + 1, done: succeeded ? { act, role: proposal.role.name, rereadOk, marginBefore } : null }));
  };

  const items = proposals.items;
  return (
    <div className={styles.proposals}>
      {notice ? (
        <Banner tone="danger" live="none" heading={notice.heading} ref={noticeHeading}>
          <p>{notice.body}</p>
        </Banner>
      ) : null}
      {items.length > 0 ? (
        <section aria-labelledby={headingId} className={styles.panel}>
          <h2 id={headingId} ref={groupHeading} tabIndex={-1} className={styles.panelHeading}>
            {WAITING.heading}
          </h2>
          <p className={styles.help}>{WAITING.line}</p>
          <ul className={styles.proposalList}>
            {items.map((p) => {
              // Scoped by the group's useId (b4-final-red L-4): two groups on one page never share an id.
              const nameId = `${headingId}-proposal-${p.id}-role`;
              const button = (act: Act, label: string, variant: 'primary' | 'secondary') => (
                <Button
                  variant={variant}
                  size="sm"
                  aria-describedby={nameId}
                  loading={busy?.id === p.id && busy.act === act}
                  onClick={() => void run(act, p)}
                >
                  {label}
                </Button>
              );
              return (
                <li key={p.id} className={styles.proposal}>
                  <p id={nameId} className={styles.proposalName}>
                    {p.role.name}
                  </p>
                  {p.is_expired ? <Chip tone="warning">{WAITING.expiredChip}</Chip> : null}
                  <p className={styles.meta}>
                    {WAITING.proposedBy(p.proposed_by.display_name)}
                    <DateTime value={p.proposed_at} form="date" timeZone={timeZone} />
                  </p>
                  <p className={styles.meta}>
                    {p.is_expired ? WAITING.expired : WAITING.expires}
                    <DateTime value={p.expires_at} form="date-time" timeZone={timeZone} />
                  </p>
                  <div className={styles.proposalActions}>
                    {canConfirm && !p.is_expired ? button('confirm', WAITING.confirm, 'primary') : null}
                    {canConfirm ? button('reject', WAITING.reject, 'secondary') : null}
                    {button('withdraw', WAITING.withdraw, 'secondary')}
                  </div>
                </li>
              );
            })}
          </ul>
          <div className={styles.pager}>
            <span className={styles.meta}>{WAITING.shown(items.length)}</span>
            {proposals.previous_cursor !== null ? (
              <Button size="sm" onClick={() => actions.onPage('before', proposals.previous_cursor!)}>
                {WAITING.newer}
              </Button>
            ) : null}
            {proposals.next_cursor !== null ? (
              <Button size="sm" onClick={() => actions.onPage('after', proposals.next_cursor!)}>
                {WAITING.older}
              </Button>
            ) : null}
          </div>
        </section>
      ) : null}
      {/* Gaps Rev 1 P-4 / RED L-3: the group's own status region, at the always-rendered root, outside the rows branch. */}
      <LiveRegion message={status.message} visuallyHidden />
    </div>
  );
}
