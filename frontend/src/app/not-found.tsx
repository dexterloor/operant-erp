import Link from 'next/link';
import { SearchX } from 'lucide-react';
import { PAGE_STATES } from '@/copy/shell';
import styles from '@/components/states/PageStates.module.css';
import frame from '@/components/shell/PageError.module.css';

/**
 * An address that matches no page (b3b-followup C-2): ST-5's RULED words — identical for "absent" and "not yours", so
 * showing it outside the session check discloses nothing — and "Go to your start page" → `/` (reused words, UX to
 * confirm). Frame-less, so it is the main landmark. Carried to UX (final-red L-4): its `<title>`, and whether the link
 * reads the same to a signed-out visitor (for whom `/` leads to sign-in).
 */
export default function NotFound() {
  return (
    <main className={frame.standalone}>
      <section className={styles.full}>
        <span className={styles.motif} aria-hidden="true">
          <SearchX width={28} height={28} strokeWidth={1.5} />
        </span>
        <h1 tabIndex={-1}>{PAGE_STATES.notFoundHeading}</h1>
        <p className={styles.body}>{PAGE_STATES.notFoundBody}</p>
        <Link href="/" className={styles.back}>
          {PAGE_STATES.deniedBack}
        </Link>
      </section>
    </main>
  );
}
