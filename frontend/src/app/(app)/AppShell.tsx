'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { AppFrame } from '@/components/shell/AppFrame';
import { Banner } from '@/components/ui/Banner';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { SHELL } from '@/copy/shell';
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
 * design §8 L-11; CONFIRMED by SEC, confirm-pass-2026-10-30.md §f). `reload` is injectable because jsdom cannot patch
 * `location.reload`.
 *
 * A Sign out that got no answer or a failure never navigates (b3b-followup C-8): a banner above the page says the
 * person may still be signed in here, under R1-1's heading by cause. Its heading takes focus — after the menu's own
 * return-focus, which Radix runs on a timer when the menu unmounts — so it carries no live role (a surface that moves
 * focus takes none, R1-1; final-red M-3). Each failure bumps a counter, so a second identical one moves focus again.
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
  const [signOutFailure, setSignOutFailure] = useState<{ n: number; cause: 'unreachable' | 'server-error' } | null>(null);
  const failureHeading = useRef<HTMLParagraphElement>(null);
  const leave = async () => {
    const r = await signOut();
    if (r.kind === 'signed-out') assignLocation(r.redirect);
    else setSignOutFailure((cur) => ({ n: (cur?.n ?? 0) + 1, cause: r.kind }));
  };
  const failureCount = signOutFailure?.n ?? 0;
  useEffect(() => {
    if (failureCount === 0) return;
    // A task later than the menu's unmount return-focus (a 0 ms timer of its own), so focus lands here and stays.
    const t = setTimeout(() => failureHeading.current?.focus(), 0);
    return () => clearTimeout(t);
  }, [failureCount]);
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
      {signOutFailure ? (
        <Banner key={signOutFailure.n} ref={failureHeading} tone="danger" icon={signOutFailure.cause === 'unreachable' ? 'offline' : undefined} live="none" heading={signOutFailure.cause === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG}>
          <p>{SHELL.signOutFailedBody}</p>
        </Banner>
      ) : null}
      {children}
    </AppFrame>
  );
}
