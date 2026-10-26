import { forwardRef, type ReactNode } from 'react';
import { CircleAlert, Info, TriangleAlert, WifiOff } from 'lucide-react';
import styles from './Banner.module.css';

export type BannerTone = 'info' | 'warning' | 'danger' | 'neutral';

const ICONS = { info: Info, warning: TriangleAlert, danger: CircleAlert, neutral: Info, offline: WifiOff } as const;

export interface BannerProps {
  tone: BannerTone;
  heading: ReactNode;
  children?: ReactNode;
  action?: ReactNode;
  /** 'alert' only for the conflict state and the account pause (§10); everything else is polite or none. */
  live?: 'status' | 'alert' | 'none';
  icon?: keyof typeof ICONS;
  /** The heading can take focus (business-rule refusal, sign-in failure). */
  headingId?: string;
}

/** Colour never carries the meaning alone: every banner has a heading in words and an icon (§2 item 10). */
export const Banner = forwardRef<HTMLHeadingElement, BannerProps>(function Banner(
  { tone, heading, children, action, live = 'none', icon, headingId },
  ref,
) {
  const Icon = ICONS[icon ?? tone];
  const role = live === 'none' ? undefined : live;
  return (
    <div className={`${styles.banner} ${styles[tone]}`} role={role} aria-live={live === 'status' ? 'polite' : undefined}>
      <Icon aria-hidden="true" width={16} height={16} strokeWidth={1.5} className={styles.icon} />
      <div className={styles.text}>
        <p className={styles.heading} id={headingId} ref={ref} tabIndex={-1}>
          {heading}
        </p>
        {children}
      </div>
      {action ? <div className={styles.action}>{action}</div> : null}
    </div>
  );
});
