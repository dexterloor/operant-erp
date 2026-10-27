'use client';

import { forwardRef, useEffect, useState, type InputHTMLAttributes, type ReactNode } from 'react';
import { CircleAlert } from 'lucide-react';
import styles from './AuthCard.module.css';

/**
 * The sign-in card's frame, reused by the credential pages ("the sign-in card with different fields and copy", L-5).
 * `page` = full-viewport backdrop (signed-out pages); `in-app` = the card alone, for a page inside the app frame;
 * `embedded` = the component preview.
 */
export function AuthCard({ layout = 'page', children }: { layout?: 'page' | 'in-app' | 'embedded'; children: ReactNode }) {
  const outer = layout === 'in-app' ? styles.inApp : layout === 'embedded' ? `${styles.page} ${styles.embedded}` : styles.page;
  const card = (
    <div className={styles.card}>
      {layout === 'in-app' ? null : <span className={styles.mark} aria-hidden="true" />}
      {children}
    </div>
  );
  // A signed-out page has no app frame, so the card's page IS the main landmark (axe landmark-one-main / region, found
  // in the real-browser run). In-app and embedded cards sit inside a page that already has its <main>.
  return layout === 'page' ? <main className={outer}>{card}</main> : <div className={outer}>{card}</div>;
}

/** A labelled input with its server error tied by aria-describedby (ST-3, WCAG 3.3.1). */
export interface AuthFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
  id: string;
  label: string;
  hint?: string;
  error?: string | undefined;
  /** Rendered at the end of the label row (e.g. the show-password toggle). */
  labelAction?: ReactNode;
}

export const AuthField = forwardRef<HTMLInputElement, AuthFieldProps>(function AuthField({ id, label, hint, error, labelAction, ...input }, ref) {
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;
  const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;
  return (
    <div className={styles.field}>
      {labelAction ? (
        <div className={styles.labelRow}>
          <label htmlFor={id}>{label}</label>
          {labelAction}
        </div>
      ) : (
        <label htmlFor={id}>{label}</label>
      )}
      <input ref={ref} id={id} aria-invalid={error ? true : undefined} aria-describedby={describedBy} {...input} />
      {hint ? (
        <span className={styles.hint} id={hintId}>
          {hint}
        </span>
      ) : null}
      {error ? (
        <span className={styles.fieldError} id={errorId}>
          <CircleAlert aria-hidden="true" width={16} height={16} strokeWidth={1.5} />
          <span>{error}</span>
        </span>
      ) : null}
    </div>
  );
});

/** The house wait before offering sign-in after a credential change (§1.4 "The wait", DT-6, BFF note R1-§8). */
export const SIGN_IN_WAIT_MS = 1100;

/**
 * True once `delayMs` have passed since `since` (a `performance.now()` reading taken when the response arrived).
 * Measured on the monotonic clock, never `Date.now()` or `changed_at`; re-checks on wake so an early timer cannot
 * offer sign-in too soon.
 */
export function useElapsedSince(since: number | null, delayMs: number = SIGN_IN_WAIT_MS): boolean {
  const [elapsed, setElapsed] = useState(false);
  useEffect(() => {
    if (since === null) return;
    let timer: ReturnType<typeof setTimeout> | undefined;
    const check = () => {
      const remaining = since + delayMs - performance.now();
      if (remaining <= 0) setElapsed(true);
      else timer = setTimeout(check, Math.ceil(remaining));
    };
    timer = setTimeout(check, Math.max(0, Math.ceil(since + delayMs - performance.now())));
    return () => clearTimeout(timer);
  }, [since, delayMs]);
  return since !== null && elapsed;
}

export { styles as authStyles };
