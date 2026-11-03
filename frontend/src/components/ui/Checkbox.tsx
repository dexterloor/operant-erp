'use client';

import { forwardRef, useCallback, useLayoutEffect, useRef, type ChangeEvent, type InputHTMLAttributes, type ReactNode } from 'react';
import styles from './Checkbox.module.css';

type NativeOmitted = 'type' | 'checked' | 'defaultChecked' | 'onChange' | 'disabled' | 'aria-disabled' | 'aria-checked' | 'aria-describedby';

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, NativeOmitted> {
  /** Controlled. `'mixed'` sets the DOM `indeterminate` property (never `aria-checked` on a native box). */
  checked: boolean | 'mixed';
  /** Called with the next value. Never called while `unavailable`. A click on a mixed box asks for `true`. */
  onCheckedChange: (next: boolean) => void;
  /**
   * ST-4e: `aria-disabled`, never the `disabled` attribute (b4-architecture.md principle 7, RED M-5). The box stays
   * focusable; click and Space change nothing because the change handler ignores them and React restores the
   * controlled value. The REASON is visible text elsewhere (a row can have several reason lines) — pass its ids.
   */
  unavailable?: boolean;
  /** Space-separated ids of the visible reason / help text. */
  describedBy?: string;
  label: ReactNode;
  /** Keeps the label as the accessible name but hides it visually (e.g. a table row's "Select {name}"). */
  hideLabel?: boolean;
}

/**
 * Native checkbox in a `<label>` whose min-size token is the 24×24 hit area (WCAG 2.2 SC 2.5.8; RED M-5: the label is
 * the target, not padding around a 16 px box). There is deliberately no `disabled` prop.
 */
export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(function Checkbox(
  { checked, onCheckedChange, unavailable = false, describedBy, label, hideLabel = false, className, ...rest },
  forwardedRef,
) {
  const inner = useRef<HTMLInputElement | null>(null);
  const setRef = useCallback(
    (node: HTMLInputElement | null) => {
      inner.current = node;
      if (typeof forwardedRef === 'function') forwardedRef(node);
      else if (forwardedRef) forwardedRef.current = node;
    },
    [forwardedRef],
  );

  useLayoutEffect(() => {
    if (inner.current) inner.current.indeterminate = checked === 'mixed';
  }, [checked]);

  const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
    // The browser cleared `indeterminate` on the click. Restore it from the prop: if the caller accepts the change, the
    // layout effect sets the new value; if not (or while unavailable), the box must still read as mixed.
    e.currentTarget.indeterminate = checked === 'mixed';
    if (unavailable) return;
    onCheckedChange(e.currentTarget.checked);
  };

  return (
    <label className={[styles.hit, unavailable ? styles.unavailable : '', className ?? ''].join(' ').trim()}>
      <input
        {...rest}
        ref={setRef}
        type="checkbox"
        className={styles.box}
        checked={checked === true}
        aria-disabled={unavailable || undefined}
        aria-describedby={describedBy || undefined}
        onChange={handleChange}
      />
      <span className={hideLabel ? 'visually-hidden' : styles.label}>{label}</span>
    </label>
  );
});
