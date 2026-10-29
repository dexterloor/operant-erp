'use client';

import { PageError } from '@/components/shell/PageError';
import styles from '@/components/shell/PageError.module.css';

/**
 * The root error boundary (b3b-followup C-2). It catches what `(app)/error.tsx` cannot: a throw in the `(app)` layout
 * itself (`currentViewer()` when GetCurrentUser fails), so it renders without the frame and is the main landmark. The
 * error object is deliberately not read (ST-3).
 */
export default function RootError() {
  return (
    <main className={styles.standalone}>
      <PageError />
    </main>
  );
}
