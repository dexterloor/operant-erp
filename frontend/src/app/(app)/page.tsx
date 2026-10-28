import type { Metadata } from 'next';
import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { StartPage } from '@/components/shell/StartPage';
import { START } from '@/copy/shell';
import { startDestination } from '@/lib/routes';
import { apiFetch } from '@/lib/bff/server/apiClient';
import { SESSION_COOKIE, readSession, tokenOf } from '@/lib/bff/server/session';

// The `/` start page (ruling Q-6; b3b-design.md §10a). Sign-in always returns here; this server component sends each
// person to the first built page, in navigation order, whose gate they hold, and otherwise renders the start page.
// No cookie → /login. A cookie that does not open (or has passed its sealed exp), or an API 401 → the session-ended
// notice. A server component never deletes the cookie (bff-auth-note.md R1-§6.1): the next sign-in replaces it.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: START.title };

const SESSION_ENDED = '/login?reason=session_ended&return_to=%2F';

export default async function Page() {
  const value = (await cookies()).get(SESSION_COOKIE)?.value;
  if (value === undefined) redirect('/login');
  const session = await readSession(value);
  if (session === null) redirect(SESSION_ENDED);
  const me = await apiFetch('GetCurrentUser', { token: tokenOf(session) });
  if (me.status === 401) redirect(SESSION_ENDED);
  if (me.status !== 200) throw new Error('The signed-in person could not be read.');
  const viewer = (await me.json()) as { permission_codes?: unknown };
  const codes = Array.isArray(viewer.permission_codes) ? viewer.permission_codes.filter((c): c is string => typeof c === 'string') : [];
  const destination = startDestination(codes);
  if (destination !== null) redirect(destination);
  return <StartPage permissionCodes={codes} />;
}
