import type { FieldErrors, RedeemResetResult, RequestResetResult, RequestResetValues, SignInRequest, SignInResult } from '../auth/results';
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

export type SignOutResult = { kind: 'signed-out'; redirect: string } | { kind: 'unreachable' } | { kind: 'server-error' };

/** POST /bff/session/sign-out. This browser only: the token stays valid at the API until it expires (ruling a1). */
export async function signOut(): Promise<SignOutResult> {
  const out = await bffFetch('/bff/session/sign-out', { method: 'POST', headers: JSON_HEADERS, body: '{}' });
  if (!out.ok) return { kind: 'unreachable' };
  if (out.response.status !== 200) return { kind: 'server-error' };
  try {
    const b = (await out.response.json()) as { redirect?: unknown };
    return { kind: 'signed-out', redirect: sitePath(b.redirect) };
  } catch {
    return { kind: 'server-error' };
  }
}

/** The server's field sentences for the named fields, or null when the 422 names none of them. */
async function fieldErrorsOf<F extends string>(response: Response, fields: readonly F[]): Promise<FieldErrors<F> | null> {
  try {
    const b = (await response.json()) as { type?: unknown; errors?: unknown };
    if (b.type !== 'urn:erp:problem:validation-failed' || !Array.isArray(b.errors)) return null;
    const out: FieldErrors<F> = {};
    for (const e of b.errors as { pointer?: unknown; message?: unknown }[]) {
      const f = typeof e?.pointer === 'string' ? (e.pointer.replace(/^\//, '') as F) : null;
      if (f !== null && fields.includes(f) && typeof e.message === 'string' && out[f] === undefined) out[f] = e.message;
    }
    return Object.keys(out).length > 0 ? out : null;
  } catch {
    return null;
  }
}

/** POST /bff/password-resets. 202 → accepted, for every well-formed input; it says nothing about whether mail is sent. */
export async function requestReset(values: RequestResetValues): Promise<RequestResetResult> {
  const out = await bffFetch('/bff/password-resets', { method: 'POST', headers: JSON_HEADERS, body: JSON.stringify({ workspace: values.workspace, email: values.email }) });
  if (!out.ok) return { kind: 'unreachable' };
  const r = out.response;
  if (r.status === 202) return { kind: 'accepted' };
  if (r.status === 422) {
    const fieldErrors = await fieldErrorsOf(r, ['workspace', 'email'] as const);
    return fieldErrors ? { kind: 'invalid', fieldErrors } : { kind: 'server-error' };
  }
  if (r.status === 429 && (await typeOf(r)) === 'urn:erp:problem:too-many-attempts') return { kind: 'rate-limited' };
  return { kind: 'server-error' };
}

/**
 * POST /bff/password-resets/redemption. The token travels only in this body, as the argument it was given. 200 →
 * changed, with `receivedAt` = performance.now() when the response arrived (DT-6). 401 → link refused: never "session
 * ended", and nothing navigates.
 */
export async function redeemReset(values: { token: string; password: string }): Promise<RedeemResetResult> {
  const out = await bffFetch('/bff/password-resets/redemption', { method: 'POST', headers: JSON_HEADERS, body: JSON.stringify({ token: values.token, password: values.password }) });
  if (!out.ok) return { kind: 'unreachable' };
  const receivedAt = performance.now();
  const r = out.response;
  if (r.status === 200) {
    try {
      const b = (await r.json()) as { changed_at?: unknown };
      return typeof b.changed_at === 'string' ? { kind: 'changed', changedAt: b.changed_at, receivedAt } : { kind: 'server-error' };
    } catch {
      return { kind: 'server-error' };
    }
  }
  if (r.status === 401) return (await typeOf(r)) === 'urn:erp:problem:authentication-failed' ? { kind: 'link-refused' } : { kind: 'server-error' };
  if (r.status === 422) {
    const fieldErrors = await fieldErrorsOf(r, ['password'] as const);
    return fieldErrors ? { kind: 'invalid', fieldErrors } : { kind: 'server-error' };
  }
  if (r.status === 429 && (await typeOf(r)) === 'urn:erp:problem:too-many-attempts') return { kind: 'rate-limited' };
  return { kind: 'server-error' };
}
