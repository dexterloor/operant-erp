'use client';

import { forwardRef, useId, type ButtonHTMLAttributes, type MouseEvent, type ReactNode } from 'react';
import { Spinner } from './Spinner';
import styles from './Button.module.css';

export type ButtonVariant = 'primary' | 'secondary' | 'tertiary' | 'destructive' | 'destructive-secondary' | 'link';
export type ButtonSize = 'sm' | 'md' | 'lg';

export interface ButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'disabled'> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  /** Leading icon (Lucide, 16 px). Replaced by the spinner while loading. */
  icon?: ReactNode;
  /**
   * §5.1 Loading: the label stays (width does not shift), a spinner replaces the leading icon, aria-busy is set and
   * further clicks are ignored — but the button is NOT visually disabled.
   */
  loading?: boolean;
  /**
   * §5.1 aria-disabled with a visible reason (ST-4e, F-7a; ruling item 1's three-way rule decides WHEN — not this
   * component). The button stays focusable, clicks are ignored, and the reason is visible text tied by
   * aria-describedby. A permission the user lacks is never expressed this way: such a button is not rendered (ST-4c).
   */
  unavailableReason?: string;
}

/** There is deliberately no `disabled` prop: the native attribute is never used (brief §3 Behaviour). */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'secondary', size = 'md', icon, loading = false, unavailableReason, children, className, onClick, type = 'button', ...rest },
  ref,
) {
  const reasonId = useId();
  const unavailable = typeof unavailableReason === 'string' && unavailableReason.length > 0;
  const blocked = loading || unavailable;
  const describedBy = [rest['aria-describedby'], unavailable ? reasonId : null].filter(Boolean).join(' ') || undefined;

  const handleClick = (e: MouseEvent<HTMLButtonElement>) => {
    if (blocked) {
      e.preventDefault();
      return;
    }
    onClick?.(e);
  };

  const button = (
    <button
      {...rest}
      ref={ref}
      type={type}
      className={[styles.button, styles[variant], styles[size], unavailable ? styles.unavailable : '', className ?? ''].join(' ').trim()}
      aria-busy={loading || undefined}
      aria-disabled={unavailable || undefined}
      aria-describedby={describedBy}
      onClick={handleClick}
    >
      {loading ? <Spinner size={14} /> : icon ? <span className={styles.icon}>{icon}</span> : null}
      <span>{children}</span>
    </button>
  );

  if (!unavailable) return button;
  return (
    <span className={styles.withReason}>
      {button}
      <span id={reasonId} className={styles.reason}>
        {unavailableReason}
      </span>
    </span>
  );
});
