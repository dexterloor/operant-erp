import type { ReactNode } from 'react';
import styles from './Chip.module.css';

export type ChipTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'accent' | 'violet';

/**
 * A short tag or state pill (Built-in, Needs an owner's confirmation, Will be given, Given, Expired …). Colour never
 * carries the meaning alone (§2 item 10): the words are the chip; the tone only reinforces them. Text comes from
 * `copy/`, never written here.
 */
export function Chip({ tone = 'neutral', children, icon, id }: { tone?: ChipTone; children: ReactNode; icon?: ReactNode; id?: string }) {
  return (
    <span id={id} className={`${styles.chip} ${styles[tone]}`}>
      {icon ? (
        <span className={styles.icon} aria-hidden="true">
          {icon}
        </span>
      ) : null}
      <span>{children}</span>
    </span>
  );
}
