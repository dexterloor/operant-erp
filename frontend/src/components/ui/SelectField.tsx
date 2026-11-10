'use client';

import { forwardRef } from 'react';
import { CircleAlert } from 'lucide-react';
import styles from './SelectField.module.css';

export interface SelectFieldProps {
  id: string;
  name: string;
  label: string;
  /** The served values, in served order (`wireEnum`, read by the caller). */
  options: readonly string[];
  /** Each value's label, from the family's copy; a value with no entry renders as sent (the caller's fallback). */
  labelOf: (value: string) => string;
  value: string;
  onChange: (value: string) => void;
  /** Sentences under the field; none = no hint. */
  hints: readonly string[];
  /** The sentence for this field (the server's, verbatim, or a client refusal's); empty or absent = no error. */
  error?: string | undefined;
  /**
   * The unselected state: a first `<option value="">` with this prompt and NO `disabled` attribute (no preselection for
   * a required picker). Absent, the select has no empty option.
   */
  prompt?: string;
}

/**
 * The in-app select over a served enum (b4-architecture.md Revision 8 R8-5), props-only, with `TextField`'s contract: the
 * label names the select; `aria-invalid` only while there is an error; `aria-describedby` = the hint id then the error id.
 * Options are the given values in the given order, each shown by `labelOf`. A value the list lacks (a record's own value,
 * if the enum ever narrowed) is kept as the first option, so the select never shows a value the record does not have.
 * Never the native `disabled` attribute (ST-4e). Nothing is decided from a value here.
 */
export const SelectField = forwardRef<HTMLSelectElement, SelectFieldProps>(function SelectField({ id, name, label, options, labelOf, value, onChange, hints, error, prompt }, ref) {
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;
  const describedBy = [hints.length > 0 ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;
  const shown = (prompt !== undefined && value === '') || options.includes(value) ? options : [value, ...options];
  return (
    <div className={styles.field}>
      <label htmlFor={id}>{label}</label>
      <select ref={ref} id={id} name={name} value={value} aria-invalid={error ? true : undefined} aria-describedby={describedBy} onChange={(e) => onChange(e.target.value)}>
        {prompt !== undefined ? <option value="">{prompt}</option> : null}
        {shown.map((v) => (
          <option key={v} value={v}>
            {labelOf(v)}
          </option>
        ))}
      </select>
      {hints.length > 0 ? (
        <span className={styles.hint} id={hintId}>
          {hints.join(' ')}
        </span>
      ) : null}
      {error ? (
        <span className={styles.error} id={errorId}>
          <CircleAlert aria-hidden="true" width={16} height={16} strokeWidth={1.5} />
          <span>{error}</span>
        </span>
      ) : null}
    </div>
  );
});
