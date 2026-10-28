'use client';

import { formatDate, formatDateTime, formatInstant, viewerTimeZone } from '@/lib/format/dates';
import { Tooltip } from './Tooltip';
import styles from './DateTime.module.css';

export interface DateTimeProps {
  /** The server's RFC 3339 value, carried untouched in `<time dateTime>` (DT-4). */
  value: string;
  /** `date` = DT-1 (`22 Oct 2026`); `date-time` = DT-3 (`22 Oct 2026, 14:05`). */
  form: 'date' | 'date-time';
  /**
   * The zone to show it in. DT-2's interim rule is the viewer's browser zone, which is the default; tests and the
   * preview pass one so the output is deterministic.
   * Required once a server route renders this: the default reads the browser zone during render and will not match
   * server HTML (B-3b carry, b4-final-red M-4).
   */
  timeZone?: string;
}

/**
 * A date as text, with the full instant and its offset (`22 Oct 2026, 14:05 (UTC+08:00)`) in the 1.4.13 `Tooltip` on
 * hover AND keyboard focus (DT-1, DT-3) — so the `<time>` is focusable (RED L-3). The tooltip never holds the only copy
 * of anything: the visible text is the date.
 */
export function DateTime({ value, form, timeZone }: DateTimeProps) {
  const zone = timeZone ?? viewerTimeZone();
  const visible = form === 'date' ? formatDate(value, zone) : formatDateTime(value, zone);
  return (
    <Tooltip content={formatInstant(value, zone)} side="top">
      <time dateTime={value} tabIndex={0} className={styles.time}>
        {visible}
      </time>
    </Tooltip>
  );
}
