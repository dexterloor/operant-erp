'use client';

import type { ReactNode } from 'react';
import { Archive } from 'lucide-react';
import { ARCHIVED_PERSON } from '@/copy/people';
import { DateTime } from '@/components/ui/DateTime';
import styles from './People.module.css';

/**
 * §2.3 / round-6 §2d: "This person was deleted on {date}." — the archived person's page's only explanation (no role
 * picker is rendered there). Shown to every viewer of the page; the Restore action is passed in only for holders of
 * `app_user.delete` (ST-4c). The date is the served `archived_at`, DT-1, with the full instant on hover and focus.
 */
export function ArchivedPersonBanner({ archivedAt, timeZone, action }: { archivedAt: string; timeZone?: string; action?: ReactNode }) {
  return (
    <div className={styles.archived}>
      <Archive aria-hidden="true" width={16} height={16} strokeWidth={1.5} className={styles.archivedIcon} />
      <p className={styles.archivedText}>
        {ARCHIVED_PERSON.bannerBefore}
        <DateTime value={archivedAt} form="date" timeZone={timeZone} />
        {ARCHIVED_PERSON.bannerAfter}
      </p>
      {action}
    </div>
  );
}
