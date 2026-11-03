import type { Metadata } from 'next';
import Link from 'next/link';
import { PAGE_STATES } from '@/copy/shell';
import { RootNotFound } from '@/components/shell/RootNotFound';
import frame from '@/components/shell/PageError.module.css';
import styles from '@/components/states/PageStates.module.css';

/**
 * An address that matches no page (b3b-followup C-2; ux-confirm-followup.md §1d): ST-5's RULED words — identical for
 * "absent" and "not yours", so showing it outside the session check discloses nothing — and "Go to your start page" →
 * `/` (the `deniedBack` reuse is CONFIRMED, also for a signed-out visitor, for whom `/` leads to sign-in). Frame-less,
 * so it is the main landmark. The title is in the metadata (what the 404 HTML carries) and set again by
 * `RootNotFound` on mount (a document load of a `notFound()` page does not get this metadata).
 */
export const metadata: Metadata = { title: PAGE_STATES.notFoundTitle };

export default function NotFound() {
  return (
    <main className={frame.standalone}>
      <RootNotFound
        startLink={
          <Link href="/" className={styles.back}>
            {PAGE_STATES.deniedBack}
          </Link>
        }
      />
    </main>
  );
}
