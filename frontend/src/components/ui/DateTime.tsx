'use client';

import { formatDate, formatDateTime, formatInstant } from '@/lib/format/dates';
import { Tooltip } from './Tooltip';
import styles from './DateTime.module.css';

export interface DateTimeProps {
  /** The server's RFC 3339 value, carried untouched in `<time dateTime>` (DT-4). */
  value: string;
  /** `date` = DT-1 (`22 Oct 2026`); `date-time` = DT-3 (`22 Oct 2026, 14:05`). */
  form: 'date' | 'date-time';
  /**
   * The viewer's IANA zone, from the session (`tz`, captured at sign-in). Never read in render: a browser default would
   * differ from the server's HTML.
   */
  timeZone: string;
}

/**
 * A date as text, with the full instant and its offset (`22 Oct 2026, 14:05 (UTC+08:00)`) in the 1.4.13 `Tooltip` on
 * hover AND keyboard focus (DT-1, DT-3) — so the `<time>` is focusable (RED L-3). The tooltip never holds the only copy
 * of anything: the visible text is the date.
 */
export function DateTime({ value, form, timeZone: zone }: DateTimeProps) {
  const visible = form === 'date' ? formatDate(value, zone) : formatDateTime(value, zone);
  return (
    <Tooltip content={formatInstant(value, zone)} side="top">
      <time dateTime={value} tabIndex={0} className={styles.time}>
        {visible}
      </time>
    </Tooltip>
  );
}
