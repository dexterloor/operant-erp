'use client';

import { useCallback, useState } from 'react';
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
 * pattern for text that must follow a focus move (b4-final-red L-5; `RolePicker`, `RoleProposals`).
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
