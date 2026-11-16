'use client';

import { useCallback, useEffect, useState } from 'react';
import styles from './LiveRegion.module.css';

/**
 * R4-4 L-2 + RED M-6: a polite status region that is in the DOM from first render, EMPTY, and only its text changes —
 * the same node for the life of the surface. Render it from the OUTERMOST component of a surface, above any branch on
 * `archived_at`, selection size or loading, so it never unmounts at the moment it must speak; children write to it
 * through `announce`, never by rendering a region of their own.
 *
 * For the four B-4 surfaces only. `StatusMessages` (§5.8 toasts, auto-dismiss) and the B-3a forms keep their own
 * regions. An after-message's visible box may appear and disappear around this node; the node itself does not.
 *
 * Announce from event handlers or after an awaited action, never from a mount effect (RED L-8: StrictMode double-runs
 * effects, so an effect-driven announcement would fire twice). A layout effect gated on a generation counter is the
 * pattern for text that must follow a focus move (b4-final-red L-5; `RolePicker`, `RoleProposals`). When the success
 * also closes a dialog, commit the close with `flushSync`, then focus, then announce (FE-D1; `PersonLifecycleActions`,
 * `BulkBar`): an effect would run while the dialog's aria-hidden still covers the page.
 *
 * FE-R6 (fe-r6-saving-status-design.md Revision 1 B-1): a surface may own a second region, wait-only, written solely by
 * `useWaitStatus` and rendered right after the surface's own region, so the first region found is still the surface's.
 * Nothing else writes it, and it never carries an outcome.
 */
export function LiveRegion({ message, visuallyHidden = false, className, id }: { message: string; visuallyHidden?: boolean; className?: string; id?: string }) {
  return (
    <div
      id={id}
      role="status"
      aria-live="polite"
      aria-atomic="true"
      className={[visuallyHidden ? 'visually-hidden' : styles.region, className ?? ''].join(' ').trim()}
    >
      {message}
    </div>
  );
}

export interface LiveRegionHandle {
  /** The current text; pass it to `<LiveRegion message={…}>`. Empty until something is announced. */
  message: string;
  /** Replaces the region's text. */
  announce: (text: string) => void;
  /** Empties the region (the node stays). */
  clear: () => void;
}

/** State for one `<LiveRegion>`: the surface root owns it and hands `announce` to its children. */
export function useLiveRegion(): LiveRegionHandle {
  const [message, setMessage] = useState('');
  const announce = useCallback((text: string) => setMessage(text), []);
  const clear = useCallback(() => setMessage(''), []);
  return { message, announce, clear };
}

/** FE-R6 (Revision 1 B-1; RED M-5): how long a request waits before `useWaitStatus` says so. */
export const WAIT_STATUS_DELAY_MS = 1500;

/**
 * FE-R6: `''` until `busy` has been true for `delayMs`, then `text`; `''` again the moment `busy` turns false. The one
 * sanctioned effect-armed write: the timer is armed only by press-driven state (`busy`), never at mount, and
 * StrictMode's effect re-run cancels the first timer, so one runs. The value is computed in render (`busy ? waited :
 * ''`), so the render in which `busy` turns false never carries the text, even before the cleanup has reset it.
 */
export function useWaitStatus(busy: boolean, text: string, delayMs = WAIT_STATUS_DELAY_MS): string {
  const [waited, setWaited] = useState('');
  useEffect(() => {
    if (!busy) return;
    const timer = setTimeout(() => setWaited(text), delayMs);
    return () => {
      clearTimeout(timer);
      // Cleanup of the press-armed timer; the returned value is render-derived (Revision 1 B-1). Kept: without it a second
      // busy period starts already saying the text (RED M-3). No set-state-in-effect directive: the rule does not flag a
      // write inside a cleanup today (eslint reports such a directive as unused). If it starts to, add
      // `eslint-disable-next-line react-hooks/set-state-in-effect` with this reason; never restructure to dodge it.
      setWaited('');
    };
  }, [busy, text, delayMs]);
  return busy ? waited : '';
}
