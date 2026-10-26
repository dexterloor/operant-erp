'use client';

import { useEffect, useId, useRef, type ReactNode } from 'react';
import { Lock, SearchX, RefreshCcw } from 'lucide-react';
import { PAGE_STATES } from '@/copy/shell';
import { Button } from '@/components/ui/Button';
import { Banner } from '@/components/ui/Banner';
import { Spinner } from '@/components/ui/Spinner';
import styles from './PageStates.module.css';

type LinkRenderer = (props: { href: string; className: string | undefined; children: ReactNode }) => ReactNode;
const plainLink: LinkRenderer = ({ href, className, children }) => (
  <a href={href} className={className}>
    {children}
  </a>
);

/** Focus the page <h1> on arrival (§10: permission denied / not found → the h1, tabindex −1). */
function useFocusOnMount<T extends HTMLElement>(enabled: boolean) {
  const ref = useRef<T>(null);
  useEffect(() => {
    if (enabled) ref.current?.focus();
  }, [enabled]);
  return ref;
}

/**
 * ST-4b, RULED copy: "You don't have access to <resource>." / "This needs the <permission> permission. Your workspace
 * administrator can grant it." / "Back to Overview". Calm, in the content area; the sidebar stays.
 */
export function PermissionDenied({ resourcePlural, permissionName, overviewHref = '/overview', autoFocus = true, renderLink = plainLink }: { resourcePlural: string; permissionName: string; overviewHref?: string; autoFocus?: boolean; renderLink?: LinkRenderer }) {
  const h1 = useFocusOnMount<HTMLHeadingElement>(autoFocus);
  return (
    <section className={styles.full}>
      <span className={styles.motif} aria-hidden="true">
        <Lock width={28} height={28} strokeWidth={1.5} />
      </span>
      <h1 ref={h1} tabIndex={-1}>
        {PAGE_STATES.deniedHeading(resourcePlural)}
      </h1>
      <p className={styles.body}>
        {PAGE_STATES.deniedBodyBefore}
        <em>{permissionName}</em>
        {PAGE_STATES.deniedBodyAfter}
      </p>
      {renderLink({ href: overviewHref, className: styles.back, children: PAGE_STATES.deniedBack })}
    </section>
  );
}

/** ST-5, RULED copy. Not found and not yours look identical. */
export function NotFound({ listHref, autoFocus = true, renderLink = plainLink }: { listHref: string; autoFocus?: boolean; renderLink?: LinkRenderer }) {
  const h1 = useFocusOnMount<HTMLHeadingElement>(autoFocus);
  return (
    <section className={styles.full}>
      <span className={styles.motif} aria-hidden="true">
        <SearchX width={28} height={28} strokeWidth={1.5} />
      </span>
      <h1 ref={h1} tabIndex={-1}>
        {PAGE_STATES.notFoundHeading}
      </h1>
      <p className={styles.body}>{PAGE_STATES.notFoundBody}</p>
      {renderLink({ href: listHref, className: styles.back, children: PAGE_STATES.notFoundBack })}
    </section>
  );
}

/**
 * A server error bounded to one region, never blanking the page (§5.11). Focus moves to the region's heading and the
 * announcement is polite, inside the region (§10). Heading copy is the caller's (PROPOSED per region).
 */
export function RegionError({ heading, onRetry, retrying = false, autoFocus = true }: { heading: string; onRetry: () => void; retrying?: boolean; autoFocus?: boolean }) {
  const ref = useFocusOnMount<HTMLHeadingElement>(autoFocus);
  const id = useId();
  return (
    <section className={styles.region} aria-labelledby={id} role="status" aria-live="polite">
      <RefreshCcw aria-hidden="true" width={16} height={16} strokeWidth={1.5} className={styles.regionIcon} />
      <div className={styles.regionText}>
        <h2 id={id} ref={ref} tabIndex={-1} className={styles.regionHeading}>
          {heading}
        </h2>
        <p className={styles.muted}>{PAGE_STATES.regionErrorBody}</p>
      </div>
      <Button size="sm" variant="secondary" loading={retrying} onClick={onRetry}>
        {PAGE_STATES.retry}
      </Button>
    </section>
  );
}

/** Stale / offline (§5.6, warning): the last good data stays on the page; announced politely; focus does not move. */
export function OfflineBanner({ loadedAt, onRetry, retrying = false }: { loadedAt: string; onRetry: () => void; retrying?: boolean }) {
  return (
    <Banner
      tone="warning"
      icon="offline"
      live="status"
      heading={PAGE_STATES.offlineHeading}
      action={
        <Button size="sm" variant="secondary" loading={retrying} onClick={onRetry}>
          {PAGE_STATES.retry}
        </Button>
      }
    >
      <p>{PAGE_STATES.offlineBody(loadedAt)}</p>
    </Banner>
  );
}

/** First paint (ST-1): blocks that match the real layout. Shimmer stops under reduced motion. */
export function Skeleton({ rows = 3, label }: { rows?: number; label: string }) {
  return (
    <div className={styles.skeleton} aria-busy="true" aria-label={label} role="status">
      {Array.from({ length: rows }, (_, i) => (
        <span key={i} className={styles.skeletonRow} style={{ width: `${92 - ((i * 17) % 40)}%` }} />
      ))}
      <span className="visually-hidden">{PAGE_STATES.loading}</span>
    </div>
  );
}

/**
 * Refetching (ST-1a): existing content stays at full contrast and clickable — no overlay, no dimming, no skeleton
 * swap. aria-busy is set on the region while loading; progress shows on the control that caused it (`indicator`).
 * When it settles, `announcement` (the ruled refresh sentence, supplied by the caller) is read politely.
 */
export function Refetchable({ busy, announcement, children }: { busy: boolean; announcement?: string; children: ReactNode }) {
  return (
    <div className={styles.refetchable} aria-busy={busy || undefined}>
      {children}
      <span className="visually-hidden" role="status" aria-live="polite">
        {busy ? '' : (announcement ?? '')}
      </span>
    </div>
  );
}

/** Region-level loading text/spinner (never a centred full-page spinner). */
export function RegionLoading() {
  return <Spinner size={16} variant="region" />;
}
