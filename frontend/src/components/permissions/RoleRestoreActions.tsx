'use client';

import { useEffect, useLayoutEffect, useRef, useState, type ReactNode, type RefObject } from 'react';
import { ArchiveRestore, CircleCheck, X } from 'lucide-react';
import type { Role } from '@/lib/api/roles';
import type { RestoreRoleAction, RestoreRoleResult } from '@/lib/permissions/results';
import { COULD_NOT_REACH, NOT_RESTORED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERSON } from '@/copy/people';
import { RESTORE_ROLE, ROLE_PAGE } from '@/copy/roles';
import { PAGE_STATES } from '@/copy/shell';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { DateTime } from '@/components/ui/DateTime';
import { IconButton } from '@/components/ui/IconButton';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import styles from './PermissionChecklist.module.css';

export interface RoleRestoreActionsProps {
  /** The served role (`GetRole`); the branch on `archived_at` and the built-in check (`is_system`) read it. */
  role: Role;
  /** Holds `RestoreRole`'s codes (from the contract, ST-4c): without it, no button is rendered, never a disabled one. */
  canRestore: boolean;
  restoreRole: RestoreRoleAction;
  /** The page's `<h1>`, owned by the route: focus goes there after a restore (the button is gone) and after Dismiss. */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /** The viewer's IANA zone, from the session. Never read in render from the browser. */
  timeZone: string;
  /** Called once after a restore's message has been committed (the page refreshes its router cache then). */
  onRestored?: () => void;
}

interface Notice {
  id: number;
  heading: string;
  body: ReactNode;
}

type Failure = Exclude<RestoreRoleResult, { kind: 'restored' } | { kind: 'page-took-over' }>;

/**
 * The banner for a non-success answer, or null for a 401 (R1-4: the caller navigates to sign-in). A refusal's `message` is
 * the server's sentence, rendered as sent (ST-3, R1-7 step 4); no refusal code is branched on here.
 */
function noticeFor(failure: Failure, name: string, id: number): Notice | null {
  switch (failure.kind) {
    case 'refused':
      return { id, heading: NOT_RESTORED, body: failure.message !== '' ? failure.message : REFUSED_WITHOUT_MESSAGE };
    case 'not-found':
      return { id, heading: NOT_RESTORED, body: PAGE_STATES.notFoundInline };
    case 'forbidden':
      return {
        id,
        heading: NOT_RESTORED,
        body: (
          <>
            {RESTORE_ROLE.forbidden.before}
            <em>{RESTORE_ROLE.forbidden.label}</em>
            {RESTORE_ROLE.forbidden.after}
          </>
        ),
      };
    case 'session-ended':
      return null;
    case 'unreachable':
      return { id, heading: COULD_NOT_REACH, body: RESTORE_ROLE.interruptedBody(name) };
    case 'server-error':
      return { id, heading: WENT_WRONG, body: RESTORE_ROLE.interruptedBody(name) };
  }
}

/**
 * The deleted role's banner and its **Restore role** action (restore-role-design.md Revision 2 F-2), props only. The
 * after-message's live region is rendered ABOVE the branch on `archived_at` (R4-4 L-2): it is mounted, empty, from first
 * render and survives the deleted → live swap; only its text changes. A refusal shows under the deleted banner with its
 * heading focused (ST-9a); the button stays, because the role is still deleted. Nothing here reads a permission code, a
 * role name or a refusal code: `canRestore` is computed by the page from the contract, built-in is the served
 * `is_system`, and "already restored" is decided by the wired action.
 */
export function RoleRestoreActions({ role, canRestore, restoreRole, fallbackFocusRef, timeZone, onRestored }: RoleRestoreActionsProps) {
  const live = useLiveRegion();
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [restoredGen, setRestoredGen] = useState(0);
  const noticeHeading = useRef<HTMLParagraphElement>(null);

  // Layout effect: focus moves in the commit that shows the banner, never a task later (b4-step3-progress.md "Flake").
  const noticeId = notice?.id ?? null;
  useLayoutEffect(() => {
    if (noticeId !== null) noticeHeading.current?.focus();
  }, [noticeId]);

  // After the commit that put the restore's message on screen (restore-role-design.md F-1 L-13, branch R).
  useEffect(() => {
    if (restoredGen > 0) onRestored?.();
    // Once per successful restore only.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [restoredGen]);

  const run = async () => {
    if (busy) return;
    // L-11: an earlier refusal never survives a new press.
    setNotice(null);
    setBusy(true);
    let result: RestoreRoleResult;
    try {
      result = await restoreRole();
    } catch {
      result = { kind: 'unreachable' };
    } finally {
      setBusy(false);
    }
    if (result.kind === 'restored') {
      // The role is live now (the caller committed it): the button that had focus is gone. Focus first, then speak.
      fallbackFocusRef.current?.focus();
      live.announce(result.already ? RESTORE_ROLE.alreadyRestored(result.role.name) : RESTORE_ROLE.after(result.role.name));
      setRestoredGen((g) => g + 1);
      return;
    }
    // The page shows its region error or swaps (re-read failed); a 401 navigates away.
    if (result.kind === 'page-took-over') return;
    const next = noticeFor(result, role.name, (notice?.id ?? 0) + 1);
    if (next) setNotice(next);
  };

  const dismiss = () => {
    live.clear();
    fallbackFocusRef.current?.focus();
  };

  const hasMessage = live.message !== '';

  return (
    <div className={styles.restore}>
      <div className={hasMessage ? styles.restoreAfter : styles.restoreAfterIdle}>
        {hasMessage ? <CircleCheck aria-hidden="true" width={16} height={16} strokeWidth={1.5} className={styles.restoreAfterIcon} /> : null}
        <LiveRegion message={live.message} className={hasMessage ? styles.restoreAfterMessage : undefined} />
        {hasMessage ? <IconButton label={PERSON.dismissMessage} size="sm" icon={<X aria-hidden="true" width={14} height={14} />} onClick={dismiss} /> : null}
      </div>

      {role.archived_at !== null ? (
        <>
          <Banner
            tone="neutral"
            live="none"
            heading={
              <>
                {ROLE_PAGE.deletedBannerBefore}
                <DateTime value={role.archived_at} form="date" timeZone={timeZone} />
                {ROLE_PAGE.deletedBannerAfter}
              </>
            }
            action={
              canRestore && !role.is_system ? (
                <Button icon={<ArchiveRestore aria-hidden="true" width={16} height={16} strokeWidth={1.5} />} loading={busy} onClick={() => void run()}>
                  {RESTORE_ROLE.action}
                </Button>
              ) : undefined
            }
          />
          {notice ? (
            <Banner tone="danger" live="none" heading={notice.heading} ref={noticeHeading}>
              <p>{notice.body}</p>
            </Banner>
          ) : null}
        </>
      ) : null}
    </div>
  );
}
