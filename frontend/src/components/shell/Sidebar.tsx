'use client';

import { useId } from 'react';
import { activeHref, deriveNavigation, type NavGroupDef, type PermissionCodes } from '@/lib/navigation';
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
}

function useNav(permissionCodes: PermissionCodes, currentPath: string): { groups: NavGroupDef[]; active: string | null } {
  const groups = deriveNavigation(permissionCodes);
  return { groups, active: activeHref(currentPath, groups) };
}

function BrandMark() {
  return <span className={styles.mark} aria-hidden="true" />;
}

/** Expanded sidebar, 248 px. Items absent (not greyed) when the code is missing (ST-4c, brief §3). */
export function Sidebar({ permissionCodes, currentPath, Link = PlainLink, onNavigate }: SidebarProps) {
  const { groups, active } = useNav(permissionCodes, currentPath);
  const uid = useId();
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
export function SidebarRail({ permissionCodes, currentPath, Link = PlainLink }: SidebarProps) {
  const { groups, active } = useNav(permissionCodes, currentPath);
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
