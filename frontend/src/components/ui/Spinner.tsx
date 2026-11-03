import { LoaderCircle } from 'lucide-react';
import { PAGE_STATES } from '@/copy/shell';
import styles from './Spinner.module.css';

/**
 * Spinners live only inside a control or a region, never centred on the page (ST-1).
 * Under reduced motion: `control` keeps a static icon (the control's own label still says what is happening);
 * `region` swaps to static "Loading…" text (§6).
 */
export function Spinner({ size = 14, variant = 'control' }: { size?: 12 | 14 | 16; variant?: 'control' | 'region' }) {
  if (variant === 'region') {
    return (
      <span className={styles.region}>
        <LoaderCircle aria-hidden="true" width={size} height={size} strokeWidth={1.5} className={styles.icon} />
        <span className={styles.staticText}>{PAGE_STATES.loading}</span>
      </span>
    );
  }
  return <LoaderCircle aria-hidden="true" width={size} height={size} strokeWidth={1.5} className={styles.icon} />;
}
