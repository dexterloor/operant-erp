'use client';

import { useLayoutEffect, useRef, type RefObject } from 'react';
import { CircleCheck, X } from 'lucide-react';
import { BULK } from '@/copy/bulk';
import { Banner } from '@/components/ui/Banner';
import { IconButton } from '@/components/ui/IconButton';
import { LiveRegion } from '@/components/ui/LiveRegion';
import type { BulkSelection } from './useBulkSelection';
import styles from './Bulk.module.css';

/**
 * The after-message (§3.1, counts only — the server never says which rows; E-14). Rendered by the LIST (the bulk
 * surface's parent), outside `BulkBar` and outside any branch on the selection, so its region is in the DOM from page
 * load, empty, and only its text changes (R4-4 L-2, RED M-6): when a finished run clears the selection the bar goes,
 * and this node must still be there to speak. The visible box appears and disappears around the node. It stays until
 * **Dismiss message** (R1-9), which returns focus to the list's `<h1>`.
 */
export function BulkResultMessage({ bulk, fallbackFocusRef }: { bulk: BulkSelection; fallbackFocusRef: RefObject<HTMLElement | null> }) {
  const has = bulk.live.message !== '';
  const dismiss = () => {
    bulk.live.clear();
    fallbackFocusRef.current?.focus();
  };
  return (
    <div className={has ? styles.after : styles.afterIdle}>
      {has ? <CircleCheck aria-hidden="true" width={16} height={16} strokeWidth={1.5} className={styles.afterIcon} /> : null}
      <LiveRegion message={bulk.live.message} className={styles.afterMessage} />
      {has ? <IconButton label={BULK.dismissMessage} size="sm" icon={<X aria-hidden="true" width={14} height={14} />} onClick={dismiss} /> : null}
    </div>
  );
}

/**
 * The list's banner zone for bulk (bulk Restore has no dialog, so its refusals and interruptions show here; R1-3, R1-5;
 * and R1-8's Already sent after either action). A banner that takes focus has no live role (R1-1); Already sent keeps
 * ST-3's alert role because focus goes to the `<h1>` when the bar goes (R1-9). No dismiss button: it clears on the next
 * change to the selection or the next run.
 */
export function BulkNotices({ bulk }: { bulk: BulkSelection }) {
  const heading = useRef<HTMLParagraphElement>(null);
  const notice = bulk.notice;
  const focusId = notice && notice.focus ? notice.id : null;
  // Layout effect: focus moves in the commit that shows the banner, never a task later ("Flake" in b4-step3-progress.md).
  useLayoutEffect(() => {
    if (focusId !== null) heading.current?.focus();
  }, [focusId]);
  if (!notice) return null;
  return (
    <Banner tone={notice.tone} live={notice.focus ? 'none' : 'alert'} heading={notice.heading} ref={heading}>
      <p>{notice.body}</p>
    </Banner>
  );
}
