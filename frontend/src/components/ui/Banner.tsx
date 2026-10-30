import { forwardRef, useId, type ReactNode } from 'react';
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
  /**
   * Opt-in, default off: ties the heading (the focus target) to the body with `aria-describedby`, so moving focus to
   * the heading also reads the body (forgot-heading-ux.md Revision 1 R1-6; RED round 1 A2/A3). The body is wrapped in a
   * real element that keeps the column gap between paragraphs; never `display: contents`, which some browsers drop
   * from the accessibility tree. Used on the three Forgot password banners only; a house-wide default waits for the
   * real-browser screen-reader pass.
   */
  describeBody?: boolean;
}

/** Colour never carries the meaning alone: every banner has a heading in words and an icon (§2 item 10). */
export const Banner = forwardRef<HTMLHeadingElement, BannerProps>(function Banner(
  { tone, heading, children, action, live = 'none', icon, headingId, describeBody = false },
  ref,
) {
  const bodyId = useId();
  const described = describeBody && children != null;
  const Icon = ICONS[icon ?? tone];
  const role = live === 'none' ? undefined : live;
  return (
    <div className={`${styles.banner} ${styles[tone]}`} role={role} aria-live={live === 'status' ? 'polite' : undefined}>
      <Icon aria-hidden="true" width={16} height={16} strokeWidth={1.5} className={styles.icon} />
      <div className={styles.text}>
        <p className={styles.heading} id={headingId} ref={ref} tabIndex={-1} aria-describedby={described ? bodyId : undefined}>
          {heading}
        </p>
        {described ? (
          <div id={bodyId} className={styles.body}>
            {children}
          </div>
        ) : (
          children
        )}
      </div>
      {action ? <div className={styles.action}>{action}</div> : null}
    </div>
  );
});
