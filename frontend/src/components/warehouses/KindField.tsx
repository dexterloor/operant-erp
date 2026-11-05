'use client';

import { forwardRef } from 'react';
import { CircleAlert } from 'lucide-react';
import { kindLabel } from '@/copy/warehouses';
import styles from './Warehouses.module.css';

export interface KindFieldProps {
  id: string;
  label: string;
  /** The served kinds, in served order (`wireEnum`, read by the caller; Revision 7 A-21). */
  kinds: readonly string[];
  value: string;
  onChange: (kind: string) => void;
  /** Sentences under the field (the chosen kind's option help; in Edit, the served current kind's hint). */
  hints: readonly string[];
  /** The server's sentence for `kind`, verbatim; empty or absent = no error. */
  error?: string | undefined;
  /**
   * The unselected state (A-21 "Preselection", S-7; Add location only): a first `<option value="">` with this prompt and
   * NO `disabled` attribute. Absent (Edit), the select has no empty option.
   */
  prompt?: string;
}

/**
 * The Kind select (warehouses-ux-copy.md B-5; Revision 7 A-21, §2 step 4.5), props-only, with `TextField`'s contract:
 * the label names the select; `aria-invalid` only while there is an error; `aria-describedby` = the hint id then the
 * error id. Options are the served values in served order, each shown by its label (or the value as sent). A value the
 * served list lacks (the row's own kind, if the enum ever narrowed) is kept as the first option, so the select never
 * shows a kind the row does not have. Never the native `disabled` attribute (ST-4e). Nothing is decided from a kind value.
 */
export const KindField = forwardRef<HTMLSelectElement, KindFieldProps>(function KindField({ id, label, kinds, value, onChange, hints, error, prompt }, ref) {
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;
  const describedBy = [hints.length > 0 ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;
  const options = (prompt !== undefined && value === '') || kinds.includes(value) ? kinds : [value, ...kinds];
  return (
    <div className={styles.kindField}>
      <label htmlFor={id}>{label}</label>
      <select ref={ref} id={id} name="kind" value={value} aria-invalid={error ? true : undefined} aria-describedby={describedBy} onChange={(e) => onChange(e.target.value)}>
        {prompt !== undefined ? <option value="">{prompt}</option> : null}
        {options.map((k) => (
          <option key={k} value={k}>
            {kindLabel(k)}
          </option>
        ))}
      </select>
      {hints.length > 0 ? (
        <span className={styles.kindHint} id={hintId}>
          {hints.join(' ')}
        </span>
      ) : null}
      {error ? (
        <span className={styles.kindError} id={errorId}>
          <CircleAlert aria-hidden="true" width={16} height={16} strokeWidth={1.5} />
          <span>{error}</span>
        </span>
      ) : null}
    </div>
  );
});
