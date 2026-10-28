import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { cache } from 'react';
import { apiFetch } from '@/lib/bff/server/apiClient';
import { SESSION_COOKIE, readSession, tokenOf, type Session } from '@/lib/bff/server/session';

// The request's session and signed-in person, read ONCE per request (react `cache` is per request on the server) and
// shared by the (app) layout and its pages (b3b-design.md §10 "Viewer"). No cookie → /login. A cookie that does not
// open or has passed its sealed exp, or an API 401 → the session-ended notice. A server component never deletes the
// cookie (bff-auth-note.md R1-§6.1); the next sign-in replaces it. Any other failure throws to the error page.

export interface Viewer {
  /** GetCurrentUser's id: a person page compares it with the person's (own page, ST-4f row 11). */
  readonly id: string;
  readonly displayName: string;
  readonly email: string;
  readonly permissionCodes: readonly string[];
}

const SESSION_ENDED = '/login?reason=session_ended';

/** The session-ended notice, returning to `path` after sign-in (only a path; sign-in re-checks it, SD-1). */
export function sessionEndedPath(path: string): string {
  return `${SESSION_ENDED}&return_to=${encodeURIComponent(path)}`;
}

const strings = (x: unknown): string[] => (Array.isArray(x) ? x.filter((c): c is string => typeof c === 'string') : []);

export const currentViewer = cache(async (): Promise<{ session: Session; viewer: Viewer }> => {
  const value = (await cookies()).get(SESSION_COOKIE)?.value;
  if (value === undefined) redirect('/login');
  const session = await readSession(value);
  if (session === null) redirect(SESSION_ENDED);
  const me = await apiFetch('GetCurrentUser', { token: tokenOf(session) });
  if (me.status === 401) redirect(SESSION_ENDED);
  if (me.status !== 200) throw new Error('The signed-in person could not be read.');
  const b = (await me.json()) as { id?: unknown; display_name?: unknown; email?: unknown; permission_codes?: unknown };
  return {
    session,
    viewer: { id: typeof b.id === 'string' ? b.id : '', displayName: typeof b.display_name === 'string' ? b.display_name : '', email: typeof b.email === 'string' ? b.email : '', permissionCodes: strings(b.permission_codes) },
  };
});
