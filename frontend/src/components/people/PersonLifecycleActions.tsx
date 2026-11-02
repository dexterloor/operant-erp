'use client';

import { useEffect, useState, type RefObject } from 'react';
import { flushSync } from 'react-dom';
import { ArchiveRestore, CircleCheck, Trash2, X } from 'lucide-react';
import type { User } from '@/lib/api/users';
import type { DeletePersonAction, PersonActionFailure, RestorePersonAction } from '@/lib/people/results';
import { COULD_NOT_REACH, NOT_DELETED, NOT_RESTORED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { DELETE_PERSON, INTERRUPTED, PERSON, PERSON_FORBIDDEN, RESTORE_PERSON } from '@/copy/people';
import { PAGE_STATES } from '@/copy/shell';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { ArchivedPersonBanner } from './ArchivedPersonBanner';
import { DeletePersonDialog, type PersonDialogNotice } from './DeletePersonDialog';
import { RestorePersonDialog } from './RestorePersonDialog';
import styles from './People.module.css';

export interface PersonLifecycleActionsProps {
  /** The served person (`GetUser`); the branch on `archived_at` and the Restore body's `is_active` read it. */
  person: User;
  /** `GetCurrentUser`'s id: on your own page Delete person is aria-disabled with its reason (ST-4f row 11). */
  viewerId: string;
  /** Holds `app_user.delete` (ST-4c): without it, neither action is rendered. */
  canDelete: boolean;
  /** Holds `role.assign` (ST-4c): only then does a restore's after-message offer Give a role. */
  canAssign: boolean;
  deletePerson: DeletePersonAction;
  restorePerson: RestorePersonAction;
  onGiveRole?: () => void;
  /** The page's `<h1>`, owned by the route (RED M-7): focus target when the opener is gone, and after Dismiss message. */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /**
   * The viewer's IANA zone, from the session (`tz`, captured at sign-in). Never read in render: a browser default would
   * differ from the server's HTML.
   */
  timeZone: string;
  /** Called once after a restore's message has been committed (the page refreshes its router cache then). */
  onRestored?: () => void;
  /** Called once after a delete's message has been committed (the page refreshes its router cache then). */
  onDeleted?: () => void;
}

type Action = 'delete' | 'restore';

/**
 * The banner for a non-success answer, or null for a 401 (R1-4: no in-page copy; the caller's fetch wrapper navigates
 * to sign-in). A refusal's `message` is the server's sentence and is rendered as sent (ST-3, R1-7 step 4); no refusal
 * code is branched on.
 */
function noticeFor(action: Action, failure: PersonActionFailure, name: string, id: number): PersonDialogNotice | null {
  const refusedHeading = action === 'delete' ? NOT_DELETED : NOT_RESTORED;
  const interruptedBody = action === 'delete' ? INTERRUPTED.deleteBody(name) : INTERRUPTED.restoreBody(name);
  switch (failure.kind) {
    case 'refused':
      return { id, heading: refusedHeading, body: failure.message !== '' ? failure.message : REFUSED_WITHOUT_MESSAGE };
    case 'not-found':
      return { id, heading: refusedHeading, body: PAGE_STATES.notFoundInline };
    case 'forbidden':
      return {
        id,
        heading: refusedHeading,
        body: (
          <>
            {PERSON_FORBIDDEN.before}
            <em>{PERSON_FORBIDDEN.label}</em>
            {PERSON_FORBIDDEN.after}
          </>
        ),
      };
    case 'session-ended':
      return null;
    case 'unreachable':
      return { id, heading: COULD_NOT_REACH, body: interruptedBody };
    case 'server-error':
      return { id, heading: WENT_WRONG, body: interruptedBody };
  }
}

/**
 * Delete and restore a person (ux-copy-frontend.md §2.2–§2.3; round-6 §2d), props only. The surface root renders the
 * after-message's live region ABOVE the branch on `archived_at` (R4-4 L-2, RED M-6): the region node is mounted, empty,
 * from first render and survives the delete → archived swap; only its text changes. Refusals are after the click and
 * render inside the open dialog (4b-4); nothing here reads a role, a permission code or a refusal code.
 *
 * On success (FE-D1) the close is committed with `flushSync`, then focus moves to the page's `<h1>`, then the
 * after-message is announced, then the refresh generation is bumped: a screen reader hears the message after focus has
 * landed, never before. This rests on React flushing a sync commit's passive effects at its end (Radix's trap teardown
 * and `hideOthers` cleanup run there), and on the dialog having NO exit animation: an exit animation would keep the
 * content mounted and the page `aria-hidden` while focus moves (see `Dialog.module.css`). `test/fe-d1-order.test.tsx`
 * guards the order.
 */
export function PersonLifecycleActions({
  person,
  viewerId,
  canDelete,
  canAssign,
  deletePerson,
  restorePerson,
  onGiveRole,
  fallbackFocusRef,
  timeZone,
  onRestored,
  onDeleted,
}: PersonLifecycleActionsProps) {
  const live = useLiveRegion();
  const [open, setOpen] = useState<Action | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<PersonDialogNotice | null>(null);
  const [offerGiveRole, setOfferGiveRole] = useState(false);
  const [restoredGen, setRestoredGen] = useState(0);
  const [deletedGen, setDeletedGen] = useState(0);
  const name = person.display_name;

  // After the commit that put the restore's message on screen (restore-role-design.md F-1 L-13, branch R; R-13).
  useEffect(() => {
    if (restoredGen > 0) onRestored?.();
    // Once per successful restore only.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [restoredGen]);

  // The same after a delete's message is on screen (R-14: the live list was measured stale after Back).
  useEffect(() => {
    if (deletedGen > 0) onDeleted?.();
    // Once per successful delete only.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [deletedGen]);

  const openDialog = (action: Action) => {
    setNotice(null);
    setOpen(action);
  };
  const onOpenChange = (next: boolean) => {
    if (!next) {
      setOpen(null);
      setNotice(null);
    }
  };

  const run = async (action: Action) => {
    if (busy) return;
    setBusy(true);
    let result: Awaited<ReturnType<DeletePersonAction | RestorePersonAction>>;
    try {
      result = action === 'delete' ? await deletePerson() : await restorePerson();
    } catch {
      result = { kind: 'unreachable' };
    }
    setBusy(false);
    if (result.kind === 'deleted') {
      // Focus first, then speak (FE-D1): the close is committed synchronously, so the dialog, its focus trap and its
      // aria-hidden on the page are gone before focus moves; Dialog's pageTookFocus guard then keeps focus here.
      flushSync(() => {
        setOpen(null);
        setNotice(null);
        setOfferGiveRole(false);
      });
      fallbackFocusRef.current?.focus();
      live.announce(DELETE_PERSON.after(name));
      setDeletedGen((g) => g + 1);
    } else if (result.kind === 'restored') {
      const active = result.person.is_active;
      // Focus first, then speak (FE-D1), as above.
      flushSync(() => {
        setOpen(null);
        setNotice(null);
        setOfferGiveRole(active);
      });
      fallbackFocusRef.current?.focus();
      live.announce(active ? `${RESTORE_PERSON.after(name)} ${RESTORE_PERSON.afterActive}` : RESTORE_PERSON.after(name));
      setRestoredGen((g) => g + 1);
    } else {
      // A 401 leaves the dialog as it is: the caller navigates away (R1-4).
      if (result.kind !== 'session-ended') setNotice((prev) => noticeFor(action, result, name, (prev?.id ?? 0) + 1));
    }
  };

  const dismiss = () => {
    live.clear();
    setOfferGiveRole(false);
    fallbackFocusRef.current?.focus();
  };

  const hasMessage = live.message !== '';
  const own = person.id === viewerId;

  return (
    <div className={styles.surface}>
      <div className={hasMessage ? styles.after : styles.afterIdle}>
        {hasMessage ? <CircleCheck aria-hidden="true" width={16} height={16} strokeWidth={1.5} className={styles.afterIcon} /> : null}
        <div className={hasMessage ? styles.afterText : undefined}>
          <LiveRegion message={live.message} className={styles.afterMessage} />
          {hasMessage && offerGiveRole && canAssign && onGiveRole ? (
            <Button variant="primary" size="sm" onClick={onGiveRole}>
              {RESTORE_PERSON.giveRole}
            </Button>
          ) : null}
        </div>
        {hasMessage ? <IconButton label={PERSON.dismissMessage} size="sm" icon={<X aria-hidden="true" width={14} height={14} />} onClick={dismiss} /> : null}
      </div>

      {person.archived_at === null ? (
        canDelete ? (
          <div className={styles.actions}>
            <Button
              variant="destructive-secondary"
              icon={<Trash2 aria-hidden="true" width={16} height={16} strokeWidth={1.5} />}
              unavailableReason={own ? DELETE_PERSON.ownPageReason : undefined}
              onClick={() => openDialog('delete')}
            >
              {DELETE_PERSON.action}
            </Button>
          </div>
        ) : null
      ) : (
        <ArchivedPersonBanner
          archivedAt={person.archived_at}
          timeZone={timeZone}
          action={
            canDelete ? (
              <Button icon={<ArchiveRestore aria-hidden="true" width={16} height={16} strokeWidth={1.5} />} onClick={() => openDialog('restore')}>
                {RESTORE_PERSON.action}
              </Button>
            ) : null
          }
        />
      )}

      <DeletePersonDialog
        open={open === 'delete'}
        onOpenChange={onOpenChange}
        name={name}
        busy={busy}
        notice={open === 'delete' ? notice : null}
        onConfirm={() => void run('delete')}
        fallbackFocusRef={fallbackFocusRef}
      />
      <RestorePersonDialog
        open={open === 'restore'}
        onOpenChange={onOpenChange}
        name={name}
        isActive={person.is_active}
        busy={busy}
        notice={open === 'restore' ? notice : null}
        onConfirm={() => void run('restore')}
        fallbackFocusRef={fallbackFocusRef}
      />
    </div>
  );
}
