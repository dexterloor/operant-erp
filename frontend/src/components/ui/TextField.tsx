'use client';

import { forwardRef, type InputHTMLAttributes } from 'react';
import { CircleAlert } from 'lucide-react';
import styles from './TextField.module.css';

/**
 * The in-app form field (Revision 7 A-19 item 1), props-only, with the contract `AuthField` already has (ST-3, WCAG
 * 3.3.1): the label names the input through `htmlFor`; `aria-invalid` is set only while there is an error;
 * `aria-describedby` is the hint id then the error id, and absent when there is neither; the error line carries its
 * icon. It takes no value transform: a field that upper-cases as typed does it in the form's own `onChange`. No length
 * limit is typed here (the contract states none); a presentation limit is the caller's `maxLength`. `AuthField` is not
 * re-based on this component (the credential pages do not change).
 */
export interface TextFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id' | 'aria-invalid' | 'aria-describedby'> {
  id: string;
  label: string;
  hint?: string;
  /** The server's sentence for this field, verbatim; empty or absent = no error. */
  error?: string | undefined;
  /**
   * Text shown after the input, outside it (items B-3 / R1-2: a quantity's unit), and read with the field: it joins
   * `aria-describedby` first. `shown` is the visible text, hidden from assistive technology; `spoken` is the accessible
   * description, verbatim as the caller gives it (the field adds no punctuation), and is required wherever a suffix is
   * shown (S-3, FE-R5). An empty `shown` still renders the wrapper. Absent = the field renders exactly as before.
   */
  suffix?: { shown: string; spoken: string };
}

export const TextField = forwardRef<HTMLInputElement, TextFieldProps>(function TextField({ id, label, hint, error, suffix, type = 'text', ...input }, ref) {
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;
  const suffixId = `${id}-suffix`;
  const describedBy = [suffix ? suffixId : null, hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;
  const field = <input ref={ref} id={id} type={type} aria-invalid={error ? true : undefined} aria-describedby={describedBy} {...input} />;
  return (
    <div className={styles.field}>
      <label htmlFor={id}>{label}</label>
      {suffix ? (
        <span className={styles.withSuffix}>
          {field}
          <span className={styles.suffix}>
            <span aria-hidden="true">{suffix.shown}</span>
            <span className="visually-hidden" id={suffixId}>
              {suffix.spoken}
            </span>
          </span>
        </span>
      ) : (
        field
      )}
      {hint ? (
        <span className={styles.hint} id={hintId}>
          {hint}
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
