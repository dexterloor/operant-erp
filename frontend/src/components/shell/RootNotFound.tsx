'use client';

import { useEffect, useRef, type ReactNode } from 'react';
import { SearchX } from 'lucide-react';
import { PAGE_STATES, pageTitle } from '@/copy/shell';
import { documentHasArrived } from './DocumentArrival';
import styles from '@/components/states/PageStates.module.css';

/**
 * The root not-found page's content (ux-confirm-followup.md §1d, with its RED M-1 and L-1): ST-5's RULED words and the
 * caller's "Go to your start page" link (passed in as an element, so no `next/*` import lives under
 * src/components; a server caller cannot pass a function to a client component).
 * - Title: set on EVERY mount. `not-found.tsx`'s metadata names the tab for an unmatched address, but a document load of
 *   a page that calls `notFound()` gets only "ERP" (measured), so the tab must be named here too.
 * - Focus: only on a client navigation (the StartPage rule, ST-2a): on a document load the page is ordinary content.
 *   A link to an address with no page reloads the document (measured), so this branch is reached only when a page calls
 *   `notFound()` without its own not-found page. It is rare today but real, and ST-5's contract (`STATES.md` ~:420)
 *   applies to it.
 */
export function RootNotFound({ startLink }: { startLink: ReactNode }) {
  const h1 = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    document.title = pageTitle(PAGE_STATES.notFoundTitle);
    if (!documentHasArrived()) return;
    h1.current?.focus();
  }, []);
  return (
    <section className={styles.full}>
      <span className={styles.motif} aria-hidden="true">
        <SearchX width={28} height={28} strokeWidth={1.5} />
      </span>
      <h1 ref={h1} tabIndex={-1}>
        {PAGE_STATES.notFoundHeading}
      </h1>
      <p className={styles.body}>{PAGE_STATES.notFoundBody}</p>
      {startLink}
    </section>
  );
}
