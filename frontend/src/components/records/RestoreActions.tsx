'use client';

import { useLayoutEffect, useRef, useState, type ReactNode, type RefObject } from 'react';
import { ArchiveRestore } from 'lucide-react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { DateTime } from '@/components/ui/DateTime';
import { COULD_NOT_REACH, NOT_RESTORED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import type { RestoreFailure, RestoreResult } from '@/lib/results';

/** The family's sentences for the archived banner and its Restore action (e.g. `WAREHOUSE_PAGE` + `RESTORE_WAREHOUSE`). */
export interface RestoreActionsCopy<S> {
  /** The banner heading around the archive date: **{archivedBefore}{date}{archivedAfter}**. */
  archivedBefore: string;
  archivedAfter: string;
  /** The button's label. */
  action: string;
  /** The after-message spoken on `restored` (and on `already-live` when the server sent no sentence). */
  after: (restored: S) => string;
  /** The interrupted sentence (unreachable / server error). */
  interrupted: string;
  /** B-8's 403 sentence around the permission's label. */
  forbidden: { before: string; after: string };
}

export interface RestoreActionsProps<S> {
  /** The served archive instant (the page renders this only while the record is archived). */
  archivedAt: string;
  copy: RestoreActionsCopy<S>;
  /** Holds the restore operation's codes (from the contract, ST-4c): without it, no button is rendered, never a disabled one. */
  canRestore: boolean;
  /** The restore itself. Caller contract: `restored` resolves after the re-read record is committed. */
  restore: () => Promise<RestoreResult<S>>;
  /** The restore operation's permission code (B-8 reads its label), or null when the contract names none. */
  restoreCode: string | null;
  /** The viewer's IANA zone, from the session. Never read in render from the browser. */
  timeZone: string;
  /** Speaks the after-message in the page's live region. */
  announce: (message: string) => void;
  /** The page's `<h1>`: focus goes there after a restore, because the button that had focus is gone. */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /** Called once after a restore's message has been committed (the page refreshes its router cache then). */
  onRestored?: () => void;
}

interface Notice {
  id: number;
  heading: string;
  body: ReactNode;
}


/**
 * An archived record page's ST-9b banner and its **Restore** action (warehouses-ux-copy.md B-3 archived page, B-7 restore
 * rows; Revision 7 §2 step 4.2), props only. No dialog. A refusal shows under the banner with heading **Not restored**,
 * body as sent, its heading focused; the button stays, because the record is still archived and P1 asks for another
 * press. Nothing here reads a refusal code.
 *
 * Shared by families (Revision 8 R8-3, items plan I-0a; moved from `components/warehouses/WarehouseRestoreActions.tsx`):
 * the family's copy and result payload `S` come in as props, and nothing here imports a family's `copy/` or `lib/`
 * (architecture.test.ts).
 */
export function RestoreActions<S>({ archivedAt, copy, canRestore, restore, restoreCode, timeZone, announce, fallbackFocusRef, onRestored }: RestoreActionsProps<S>) {
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const noticeHeading = useRef<HTMLParagraphElement>(null);

  const noticeId = notice?.id ?? null;
  useLayoutEffect(() => {
    if (noticeId !== null) noticeHeading.current?.focus();
  }, [noticeId]);

  const noticeFor = (failure: RestoreFailure, id: number): Notice | null => {
    switch (failure.kind) {
      case 'refused':
        return { id, heading: NOT_RESTORED, body: failure.message !== '' ? failure.message : REFUSED_WITHOUT_MESSAGE };
      case 'not-found':
        return { id, heading: NOT_RESTORED, body: PAGE_STATES.notFoundInline };
      case 'forbidden': {
        const label = (restoreCode !== null ? PERMISSION_LABELS[restoreCode]?.label : undefined) ?? '';
        return {
          id,
          heading: NOT_RESTORED,
          body: (
            <>
              {copy.forbidden.before}
              <em>{label}</em>
              {copy.forbidden.after}
            </>
          ),
        };
      }
      case 'session-ended':
        return null;
      case 'unreachable':
        return { id, heading: COULD_NOT_REACH, body: copy.interrupted };
      case 'server-error':
        return { id, heading: WENT_WRONG, body: copy.interrupted };
    }
  };

  const run = async () => {
    if (busy) return;
    // An earlier refusal never survives a new press.
    setNotice(null);
    setBusy(true);
    let result: RestoreResult<S>;
    try {
      result = await restore();
    } catch {
      result = { kind: 'unreachable' };
    } finally {
      setBusy(false);
    }
    switch (result.kind) {
      case 'restored':
      case 'already-live':
        // The record is live now (the action committed it): this banner and its button are gone. After a refusal that found
        // it live already, the server's sentence is spoken as sent; it is true at that moment.
        fallbackFocusRef.current?.focus();
        announce(result.kind === 'already-live' && result.message !== '' ? result.message : copy.after(result));
        onRestored?.();
        return;
      case 'refused':
      case 'not-found':
      case 'forbidden':
      case 'session-ended':
      case 'unreachable':
      case 'server-error': {
        const next = noticeFor(result, (notice?.id ?? 0) + 1);
        if (next) setNotice(next);
        return;
      }
      default: {
        // A new kind of answer must be handled above, not routed to a refusal notice by default (RED L-3).
        const unhandled: never = result;
        return unhandled;
      }
    }
  };

  return (
    <>
      <Banner
        tone="neutral"
        heading={
          <>
            {copy.archivedBefore}
            <DateTime value={archivedAt} form="date" timeZone={timeZone} />
            {copy.archivedAfter}
          </>
        }
        action={
          canRestore ? (
            <Button icon={<ArchiveRestore aria-hidden="true" width={16} height={16} strokeWidth={1.5} />} loading={busy} onClick={() => void run()}>
              {copy.action}
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
  );
}
