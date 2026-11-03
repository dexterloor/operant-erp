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
 * administrator can grant it." / "Go to your start page" → `/` (ux-copy-b3b.md C2-3: `/` never sends the viewer to a
 * page they lack, so this link cannot loop back here). Calm, in the content area; the sidebar stays.
 */
export function PermissionDenied({ resourcePlural, permissionName, startHref = '/', autoFocus = true, renderLink = plainLink }: { resourcePlural: string; permissionName: string; startHref?: string; autoFocus?: boolean; renderLink?: LinkRenderer }) {
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
      {renderLink({ href: startHref, className: styles.back, children: PAGE_STATES.deniedBack })}
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
 * A failed read bounded to one region, never blanking the page (§5.11). The heading is R1-1's by cause and the body is
 * the region's own (ux-copy-b3b.md C5; RED M-4), both the caller's. **Focus moves to the heading** (the R1-1 rule:
 * a surface that moves focus takes no live role, so the region has none — the heading is read when it takes focus).
 * `autoFocus={false}` on first paint (ST-2a: ordinary content, not announced).
 */
export function RegionError({ heading, body, onRetry, retrying = false, autoFocus = true }: { heading: string; body: string; onRetry: () => void; retrying?: boolean; autoFocus?: boolean }) {
  const ref = useFocusOnMount<HTMLHeadingElement>(autoFocus);
  const id = useId();
  return (
    <section className={styles.region} aria-labelledby={id}>
      <RefreshCcw aria-hidden="true" width={16} height={16} strokeWidth={1.5} className={styles.regionIcon} />
      <div className={styles.regionText}>
        <h2 id={id} ref={ref} tabIndex={-1} className={styles.regionHeading}>
          {heading}
        </h2>
        <p className={styles.muted}>{body}</p>
      </div>
      <Button size="sm" variant="secondary" loading={retrying} onClick={onRetry}>
        {PAGE_STATES.retry}
      </Button>
    </section>
  );
}

/**
 * A refetch failed (ST-3 network row; §5.6 warning): the earlier data stays on the page; announced politely; focus does
 * not move. The heading is R1-1's by cause, the body the caller's (`LIST_STATES.refetchFailed`).
 */
export function RefetchFailedBanner({ heading, body, onRetry, retrying = false }: { heading: string; body: string; onRetry: () => void; retrying?: boolean }) {
  return (
    <Banner
      tone="warning"
      icon="offline"
      live="status"
      heading={heading}
      action={
        <Button size="sm" variant="secondary" loading={retrying} onClick={onRetry}>
          {PAGE_STATES.retry}
        </Button>
      }
    >
      <p>{body}</p>
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
 * `liveRegion={false}`: the caller owns a `LiveRegion` mounted from first render above any branch that can hide this
 * wrapper (principle 6; the users/roles lists, whose wrapper is absent while a first load has failed — RED M-3).
 */
export function Refetchable({ busy, announcement, liveRegion = true, children }: { busy: boolean; announcement?: string; liveRegion?: boolean; children: ReactNode }) {
  return (
    <div className={styles.refetchable} aria-busy={busy || undefined}>
      {children}
      {liveRegion ? (
        <span className="visually-hidden" role="status" aria-live="polite">
          {busy ? '' : (announcement ?? '')}
        </span>
      ) : null}
    </div>
  );
}

/** Region-level loading text/spinner (never a centred full-page spinner). */
export function RegionLoading() {
  return <Spinner size={16} variant="region" />;
}
