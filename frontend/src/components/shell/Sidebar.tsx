'use client';

import { useId } from 'react';
import { NAVIGATION, activeHref, deriveNavigation, type NavGroupDef, type PermissionCodes } from '@/lib/navigation';
import { BUILT_PAGE_HREFS } from '@/lib/routes';
import { SHELL } from '@/copy/shell';
import { Tooltip } from '@/components/ui/Tooltip';
import { NAV_ICONS } from './icons';
import { PlainLink, type LinkComponent } from './types';
import styles from './Sidebar.module.css';

export interface SidebarProps {
  permissionCodes: PermissionCodes;
  currentPath: string;
  Link?: LinkComponent;
  /** Called after a link is followed (the 320 px menu closes itself with it). */
  onNavigate?: () => void;
  /** The hrefs whose page exists (b3b-followup C-3); only these are offered. Tests may pass every href. */
  builtHrefs?: readonly string[];
}

/** The groups the menu shows: held codes AND built pages. Zero groups → no menu landmark at all (no empty `<nav>`). */
export function navGroups(permissionCodes: PermissionCodes, builtHrefs: readonly string[] = BUILT_PAGE_HREFS): NavGroupDef[] {
  return deriveNavigation(permissionCodes, NAVIGATION, builtHrefs);
}

function useNav(permissionCodes: PermissionCodes, currentPath: string, builtHrefs?: readonly string[]): { groups: NavGroupDef[]; active: string | null } {
  const groups = navGroups(permissionCodes, builtHrefs);
  return { groups, active: activeHref(currentPath, groups) };
}

function BrandMark() {
  return <span className={styles.mark} aria-hidden="true" />;
}

/**
 * Expanded sidebar, 248 px. Items absent (not greyed) when the code is missing (ST-4c, brief §3) or the page is not built
 * (C-3). With nothing to offer it keeps the brand mark and renders no `<nav>` (an empty landmark is noise).
 */
export function Sidebar({ permissionCodes, currentPath, Link = PlainLink, onNavigate, builtHrefs }: SidebarProps) {
  const { groups, active } = useNav(permissionCodes, currentPath, builtHrefs);
  const uid = useId();
  if (groups.length === 0) {
    return (
      <div className={styles.sidebar}>
        <div className={styles.brand}>
          <BrandMark />
        </div>
      </div>
    );
  }
  return (
    <nav aria-label={SHELL.mainNav} className={styles.sidebar}>
      <div className={styles.brand}>
        <BrandMark />
      </div>
      {groups.map((g, gi) => (
        <div key={g.label ?? `top-${gi}`} className={styles.group}>
          {g.label ? (
            <span className={styles.groupLabel} id={`${uid}-g${gi}`}>
              {g.label}
            </span>
          ) : null}
          <ul className={styles.list} aria-labelledby={g.label ? `${uid}-g${gi}` : undefined}>
            {g.items.map((item) => {
              const Icon = NAV_ICONS[item.icon];
              const isActive = item.href === active;
              return (
                <li key={item.href}>
                  <Link href={item.href} className={styles.item} aria-current={isActive ? 'page' : undefined} onClick={onNavigate}>
                    {isActive ? <span className={styles.indicator} aria-hidden="true" /> : null}
                    <Icon aria-hidden="true" width={20} height={20} strokeWidth={1.5} className={styles.icon} />
                    <span>{item.label}</span>
                  </Link>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );
}

/**
 * Collapsed rail, 64 px. Each icon link carries its name as aria-label and shows it in a tooltip on hover and
 * keyboard focus, with WCAG 1.4.13 behaviour (Esc dismisses, hoverable, persistent). Group labels become dividers.
 */
export function SidebarRail({ permissionCodes, currentPath, Link = PlainLink, builtHrefs }: SidebarProps) {
  const { groups, active } = useNav(permissionCodes, currentPath, builtHrefs);
  if (groups.length === 0) {
    return (
      <div className={styles.rail}>
        <div className={styles.brandRail}>
          <BrandMark />
        </div>
      </div>
    );
  }
  return (
    <nav aria-label={SHELL.mainNav} className={styles.rail}>
      <div className={styles.brandRail}>
        <BrandMark />
      </div>
      {groups.map((g, gi) => (
        <div key={g.label ?? `top-${gi}`} className={styles.railGroup}>
          {gi > 0 ? <span className={styles.divider} aria-hidden="true" /> : null}
          <ul className={styles.list}>
            {g.items.map((item) => {
              const Icon = NAV_ICONS[item.icon];
              const isActive = item.href === active;
              return (
                <li key={item.href}>
                  <Tooltip content={item.label} side="right">
                    <Link href={item.href} className={styles.railItem} aria-label={item.label} aria-current={isActive ? 'page' : undefined}>
                      {isActive ? <span className={styles.railIndicator} aria-hidden="true" /> : null}
                      <Icon aria-hidden="true" width={20} height={20} strokeWidth={1.5} />
                    </Link>
                  </Tooltip>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );
}
