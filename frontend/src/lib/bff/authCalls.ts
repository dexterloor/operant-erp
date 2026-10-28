import type { SignInRequest, SignInResult } from '../auth/results';
import { bffFetch } from './transport';

// The named credential calls (b3b-design.md §6.1; bff-auth-note.md §4, §6.1). They call bffFetch directly with their
// own mapping: no X-ERP-Session (these pages have no session), and a 401 here means "wrong details" or "bad link",
// never "session ended", so nothing here navigates.

const JSON_HEADERS = { 'content-type': 'application/json', accept: 'application/json' } as const;

async function typeOf(response: Response): Promise<string | null> {
  try {
    const b = (await response.json()) as { type?: unknown };
    return typeof b.type === 'string' ? b.type : null;
  } catch {
    return null;
  }
}

/** A same-site path with exactly one leading slash, else `/` (the page navigates only to what the server says). */
const sitePath = (x: unknown): string => (typeof x === 'string' && x.startsWith('/') && !x.startsWith('//') && !x.includes('\\') ? x : '/');

export async function signIn(request: SignInRequest): Promise<SignInResult> {
  const out = await bffFetch('/bff/session', {
    method: 'POST',
    headers: JSON_HEADERS,
    body: JSON.stringify({ workspace: request.workspace, email: request.email, password: request.password, return_to: request.returnTo, time_zone: request.timeZone }),
  });
  if (!out.ok) return { kind: 'unreachable' };
  const r = out.response;
  if (r.status === 200) {
    try {
      const b = (await r.json()) as { redirect?: unknown };
      return { kind: 'signed-in', redirect: sitePath(b.redirect) };
    } catch {
      return { kind: 'server-error' };
    }
  }
  const type = await typeOf(r);
  if (r.status === 401 && type === 'urn:erp:problem:authentication-failed') return { kind: 'failure' };
  if (r.status === 422 && type === 'urn:erp:problem:validation-failed') return { kind: 'failure' };
  if (r.status === 429 && type === 'urn:erp:problem:too-many-attempts') return { kind: 'rate-limited' };
  if (r.status === 429 && type === 'urn:erp:problem:sign-in-paused') return { kind: 'paused' };
  return { kind: 'server-error' };
}
