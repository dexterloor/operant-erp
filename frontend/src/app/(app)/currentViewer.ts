import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { cache } from 'react';
import { apiFetch } from '@/lib/bff/server/apiClient';
import { SESSION_COOKIE, readSession, tokenOf, type Session } from '@/lib/bff/server/session';
import { sessionEndedHref } from '@/lib/session';

// The request's session and signed-in person, read ONCE per request (react `cache` is per request on the server) and
// shared by the (app) layout and its pages (b3b-design.md §10 "Viewer"). No cookie → /login. A cookie that does not
// open or has passed its sealed exp, or an API 401 → the session-ended notice, returning to the page the person was on:
// each page names that address itself (`currentViewer(sessionEndedPath(…))`), because the layout cannot know the page
// it wraps (Next layouts get no pathname, and layouts and pages render in parallel). The layout asks `sessionHasEnded()`
// first and, if so, hands the browser's own address to the notice (`SessionEndedReturn`) instead of redirecting without
// one. A server component never deletes the cookie (bff-auth-note.md R1-§6.1); the next sign-in replaces it. Any other
// failure throws to the error page.

export interface Viewer {
  /** GetCurrentUser's id: a person page compares it with the person's (own page, ST-4f row 11). */
  readonly id: string;
  readonly displayName: string;
  readonly email: string;
  readonly permissionCodes: readonly string[];
}

/**
 * The session-ended notice, returning to `path` and the page's own query after sign-in, so a person on the Archived
 * view comes back to it. `query` is the page's `searchParams` as Next hands it over (every value, in order). Only the
 * page's fixed path and its query go in; sign-in re-checks the whole address (SD-1), which a query cannot move off-site.
 */
export function sessionEndedPath(path: string, query: Readonly<Record<string, string | string[] | undefined>> = {}): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    for (const one of Array.isArray(value) ? value : value === undefined ? [] : [value]) search.append(key, one);
  }
  const s = search.toString();
  return sessionEndedHref(s === '' ? path : `${path}?${s}`);
}

const strings = (x: unknown): string[] => (Array.isArray(x) ? x.filter((c): c is string => typeof c === 'string') : []);

type ViewerRead = { kind: 'signed-out' } | { kind: 'ended' } | { kind: 'ok'; session: Session; viewer: Viewer };

const readViewer = cache(async (): Promise<ViewerRead> => {
  const value = (await cookies()).get(SESSION_COOKIE)?.value;
  if (value === undefined) return { kind: 'signed-out' };
  const session = await readSession(value);
  if (session === null) return { kind: 'ended' };
  const me = await apiFetch('GetCurrentUser', { token: tokenOf(session) });
  if (me.status === 401) return { kind: 'ended' };
  if (me.status !== 200) throw new Error('The signed-in person could not be read.');
  const b = (await me.json()) as { id?: unknown; display_name?: unknown; email?: unknown; permission_codes?: unknown };
  return {
    kind: 'ok',
    session,
    viewer: { id: typeof b.id === 'string' ? b.id : '', displayName: typeof b.display_name === 'string' ? b.display_name : '', email: typeof b.email === 'string' ? b.email : '', permissionCodes: strings(b.permission_codes) },
  };
});

/**
 * The signed-in person. No cookie → /login. An ended session → `ended`, the page's own `sessionEndedPath(…)`, so
 * sign-in returns there (required: a page cannot forget it).
 */
export async function currentViewer(ended: string): Promise<{ session: Session; viewer: Viewer }> {
  const r = await readViewer();
  if (r.kind === 'signed-out') redirect('/login');
  if (r.kind === 'ended') redirect(ended);
  return { session: r.session, viewer: r.viewer };
}

/** True when a cookie was sent but its session has ended (the frame then hands over to `SessionEndedReturn`). */
export async function sessionHasEnded(): Promise<boolean> {
  return (await readViewer()).kind === 'ended';
}
