'use client';

import type { ReactNode } from 'react';
import type { ThemePreference } from '@/styles/tokens';
import type { PermissionCodes } from '@/lib/navigation';
import { workspaceLabel } from '@/lib/session';
import { SHELL } from '@/copy/shell';
import { TooltipProvider } from '@/components/ui/Tooltip';
import { Sidebar, SidebarRail } from './Sidebar';
import { MobileNav } from './MobileNav';
import { UserMenu } from './UserMenu';
import { RouteFocus } from './RouteFocus';
import { PlainLink, type LinkComponent } from './types';
import styles from './AppFrame.module.css';

export interface AppFrameProps {
  /** The signed-in person's permission codes (from GetCurrentUser in B-3b). The sidebar is derived from these alone. */
  permissionCodes: PermissionCodes;
  currentPath: string;
  user: { displayName: string; email: string };
  /** D14: the workspace display name (shown only to tenant_settings.view holders) and the sign-in name typed at login. */
  workspace: { displayName?: string | null; signInName: string };
  theme: ThemePreference;
  onThemeChange: (pref: ThemePreference) => void;
  onChangePassword: () => void;
  onSignOut: () => void;
  Link?: LinkComponent;
  children: ReactNode;
  /** Status-message stack and other fixed layers. */
  overlays?: ReactNode;
}

/**
 * The app frame: skip link (first tab stop) → sidebar (≥ 1280 px), 64 px rail (768–1279 px) or menu button (< 768 px)
 * → top bar (workspace label per D14, user menu; no Ask button: FUTURE) → <main id="main-content">.
 */
export function AppFrame({ permissionCodes, currentPath, user, workspace, theme, onThemeChange, onChangePassword, onSignOut, Link = PlainLink, children, overlays }: AppFrameProps) {
  const label = workspaceLabel({ codes: permissionCodes, displayName: workspace.displayName, signInName: workspace.signInName });
  return (
    <TooltipProvider>
      <a href="#main-content" className={styles.skip}>
        {SHELL.skipLink}
      </a>
      <div className={styles.frame}>
        <div className={styles.sidebarSlot} data-shell-slot="sidebar">
          <Sidebar permissionCodes={permissionCodes} currentPath={currentPath} Link={Link} />
        </div>
        <div className={styles.railSlot} data-shell-slot="rail">
          <SidebarRail permissionCodes={permissionCodes} currentPath={currentPath} Link={Link} />
        </div>
        <div className={styles.column}>
          <header className={styles.topbar}>
            <div className={styles.menuSlot} data-shell-slot="menu">
              <MobileNav permissionCodes={permissionCodes} currentPath={currentPath} Link={Link} />
            </div>
            <span className={styles.workspace} title={label}>
              {label}
            </span>
            <UserMenu displayName={user.displayName} email={user.email} theme={theme} onThemeChange={onThemeChange} onChangePassword={onChangePassword} onSignOut={onSignOut} />
          </header>
          <main id="main-content" tabIndex={-1} className={styles.main}>
            {children}
          </main>
        </div>
      </div>
      <RouteFocus routeKey={currentPath} />
      {overlays}
    </TooltipProvider>
  );
}
