'use client';

import { useLayoutEffect, useRef, useState, type ReactNode, type RefObject } from 'react';
import { ArchiveRestore } from 'lucide-react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { DateTime } from '@/components/ui/DateTime';
import { COULD_NOT_REACH, NOT_RESTORED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { RESTORE_WAREHOUSE, WAREHOUSE_FORBIDDEN, WAREHOUSE_PAGE } from '@/copy/warehouses';
import type { Warehouse } from '@/lib/api/warehouses';
import type { RestoreWarehouseAction, RestoreWarehouseResult } from '@/lib/warehouses/results';

export interface WarehouseRestoreActionsProps {
  /** The served, archived warehouse (the page renders this only while `archived_at` is set). */
  warehouse: Warehouse & { archived_at: string };
  /** Holds `RestoreWarehouse`'s codes (from the contract, ST-4c): without it, no button is rendered, never a disabled one. */
  canRestore: boolean;
  restoreWarehouse: RestoreWarehouseAction;
  /** `RestoreWarehouse`'s permission code (B-8 reads its label), or null when the contract names none. */
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

type Failure = Exclude<RestoreWarehouseResult, { kind: 'restored' } | { kind: 'already-live' }>;

/**
 * The archived warehouse page's ST-9b banner and its **Restore warehouse** action (warehouses-ux-copy.md B-3 archived
 * page, B-7 restore rows; Revision 7 §2 step 4.2), props only. No dialog. A refusal (W4, W5, P1) shows under the banner
 * with heading **Not restored**, body as sent, its heading focused; the button stays, because the warehouse is still
 * archived and P1 asks for another press. Nothing here reads a refusal code.
 */
export function WarehouseRestoreActions({ warehouse, canRestore, restoreWarehouse, restoreCode, timeZone, announce, fallbackFocusRef, onRestored }: WarehouseRestoreActionsProps) {
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const noticeHeading = useRef<HTMLParagraphElement>(null);

  const noticeId = notice?.id ?? null;
  useLayoutEffect(() => {
    if (noticeId !== null) noticeHeading.current?.focus();
  }, [noticeId]);

  const noticeFor = (failure: Failure, id: number): Notice | null => {
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
              {WAREHOUSE_FORBIDDEN.before}
              <em>{label}</em>
              {WAREHOUSE_FORBIDDEN.after}
            </>
          ),
        };
      }
      case 'session-ended':
        return null;
      case 'unreachable':
        return { id, heading: COULD_NOT_REACH, body: RESTORE_WAREHOUSE.interrupted(warehouse.code) };
      case 'server-error':
        return { id, heading: WENT_WRONG, body: RESTORE_WAREHOUSE.interrupted(warehouse.code) };
    }
  };

  const run = async () => {
    if (busy) return;
    // An earlier refusal never survives a new press.
    setNotice(null);
    setBusy(true);
    let result: RestoreWarehouseResult;
    try {
      result = await restoreWarehouse();
    } catch {
      result = { kind: 'unreachable' };
    } finally {
      setBusy(false);
    }
    if (result.kind === 'restored' || result.kind === 'already-live') {
      // The warehouse is live now (the action committed it): this banner and its button are gone. After a refusal that
      // found it live already, the server's sentence (W4) is spoken as sent; it is true at that moment.
      fallbackFocusRef.current?.focus();
      announce(result.kind === 'already-live' && result.message !== '' ? result.message : RESTORE_WAREHOUSE.after(result.warehouse.code));
      onRestored?.();
      return;
    }
    const next = noticeFor(result, (notice?.id ?? 0) + 1);
    if (next) setNotice(next);
  };

  return (
    <>
      <Banner
        tone="neutral"
        heading={
          <>
            {WAREHOUSE_PAGE.archivedOn}
            <DateTime value={warehouse.archived_at} form="date" timeZone={timeZone} />
            {WAREHOUSE_PAGE.archivedAfter}
          </>
        }
        action={
          canRestore ? (
            <Button icon={<ArchiveRestore aria-hidden="true" width={16} height={16} strokeWidth={1.5} />} loading={busy} onClick={() => void run()}>
              {RESTORE_WAREHOUSE.action}
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
