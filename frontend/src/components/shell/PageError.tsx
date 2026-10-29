'use client';

import { useEffect, useRef } from 'react';
import { RefreshCcw } from 'lucide-react';
import { WENT_WRONG } from '@/copy/headings';
import { PAGE_STATES, pageTitle } from '@/copy/shell';
import { Button } from '@/components/ui/Button';
import styles from '@/components/states/PageStates.module.css';

const reload = () => window.location.reload();

/**
 * A page that could not be loaded (b3b-followup C-2, F-4), rendered by `src/app/error.tsx` (frame-less: the `(app)` layout
 * threw) and `src/app/(app)/error.tsx` (inside the frame: a page threw). Heading R1-1's WENT_WRONG — from the server's side
 * every such failure is ours; body `PAGE_STATES.pageLoadFailed` (CONFIRMED, ux-confirm-followup.md §1a). It never shows the error's message or digest
 * (ST-3: a 5xx never shows server detail), so it takes no error prop at all. Retry is a full document reload, so the
 * server reads again (a `reset()` alone re-renders without re-fetching; Next's `retry` prop was considered and not chosen:
 * a reload also recovers a broken client bundle). Focus goes to the h1 on mount, as after any page swap (§1b), and the tab
 * is named after the page (§1c): a client component cannot export metadata, so it sets `document.title` itself.
 * It renders no `<main>` (final-red M-1): the frame-less caller wraps it in one, the frame already has one.
 */
export function PageError({ onRetry = reload }: { onRetry?: () => void }) {
  const h1 = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    document.title = pageTitle(PAGE_STATES.pageLoadFailedTitle);
    h1.current?.focus();
  }, []);
  return (
    <section className={styles.full}>
      <span className={styles.motif} aria-hidden="true">
        <RefreshCcw width={28} height={28} strokeWidth={1.5} />
      </span>
      <h1 ref={h1} tabIndex={-1}>
        {WENT_WRONG}
      </h1>
      <p className={styles.body}>{PAGE_STATES.pageLoadFailed}</p>
      <Button variant="secondary" onClick={() => onRetry()}>
        {PAGE_STATES.retry}
      </Button>
    </section>
  );
}
