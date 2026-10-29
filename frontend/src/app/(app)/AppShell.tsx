'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useEffect, type ReactNode } from 'react';
import { AppFrame } from '@/components/shell/AppFrame';
import { useThemePreference } from '@/components/theme/useThemePreference';
import { assignLocation } from '@/lib/auth/results';
import { signOut } from '@/lib/bff/authCalls';

const reloadDocument = () => window.location.reload();

/**
 * The authenticated frame's client half: the frame itself, the theme, Change password (its own page) and Sign out
 * (POST /bff/session/sign-out, then a full document load of the server's redirect, R2-4). It receives the served viewer
 * and the typed workspace name (for the D14 label) from the server layout, never the session itself.
 *
 * A page that Back restores from the browser's back-forward cache (`pageshow` with `persisted`) is reloaded, so the
 * server checks the session again and the page never shows what an earlier sign-in left on screen (b3b-followup C-5c,
 * design §8 L-11; SEC to confirm). `reload` is injectable because jsdom cannot patch `location.reload`.
 */
export function AppShell({ viewer, workspaceSignInName, children, reload = reloadDocument }: { viewer: { displayName: string; email: string; permissionCodes: readonly string[] }; workspaceSignInName: string; children: ReactNode; reload?: () => void }) {
  const pathname = usePathname();
  const [theme, setTheme] = useThemePreference();
  useEffect(() => {
    const onPageShow = (event: PageTransitionEvent) => {
      if (event.persisted) reload();
    };
    window.addEventListener('pageshow', onPageShow);
    return () => window.removeEventListener('pageshow', onPageShow);
  }, [reload]);
  const leave = async () => {
    const r = await signOut();
    if (r.kind === 'signed-out') assignLocation(r.redirect);
  };
  return (
    <AppFrame
      permissionCodes={viewer.permissionCodes}
      currentPath={pathname}
      user={{ displayName: viewer.displayName, email: viewer.email }}
      workspace={{ displayName: null, signInName: workspaceSignInName }}
      theme={theme}
      onThemeChange={setTheme}
      onChangePassword={() => assignLocation('/account/password')}
      onSignOut={() => void leave()}
      Link={Link}
    >
      {children}
    </AppFrame>
  );
}
