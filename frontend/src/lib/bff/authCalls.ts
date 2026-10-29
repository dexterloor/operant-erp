import type { ChangePasswordResult, ChangePasswordValues, FieldErrors, RedeemResetResult, RequestResetResult, RequestResetValues, SignInRequest, SignInResult } from '../auth/results';
import { bffFetch } from './transport';

// The named credential calls (b3b-design.md §6.1; bff-auth-note.md §4, §6.1; b4-architecture.md Revision 6 A-12). They
// call bffFetch directly with their own mapping. Sign-in and the two reset calls send no X-ERP-Session (those pages have
// no session), and a 401 there means "wrong details" or "bad link". The change-password call sends the sid of the app
// frame it was rendered in. Nothing here navigates or reloads: a 401 or 409 is handed to the page, which decides.

const JSON_HEADERS = { 'content-type': 'application/json', accept: 'application/json' } as const;

async function typeOf(response: Response): Promise<string | null> {
  try {
    const b = (await response.json()) as { type?: unknown };
    return typeof b.type === 'string' ? b.type : null;
  } catch {
    return null;
  }
}

/**
 * The answers the web app's server gives WITHOUT calling the API (origin.ts and handlers.ts `notSentRefusal`), keyed by
 * type with the status each comes with (c10 FINAL A.1). Every one is produced before the upstream call, so nothing was
 * sent: the outcome is known.
 */
const NOT_SENT: Readonly<Record<string, readonly number[]>> = {
  'urn:erp:bff:request-refused': [403, 400],
  'urn:erp:bff:unsupported-media-type': [415],
  'urn:erp:bff:payload-too-large': [413],
  'urn:erp:bff:request-timeout': [408],
  'urn:erp:bff:request-invalid': [422],
};

/** True when the answer is one the web app's server made before sending anything to the API. Reads a clone. */
async function isNotSent(response: Response): Promise<boolean> {
  const type = await typeOf(response.clone());
  return type !== null && (NOT_SENT[type]?.includes(response.status) ?? false);
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
  if (await isNotSent(r)) return { kind: 'not-sent' };
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
  if (await isNotSent(r)) return { kind: 'not-sent' };
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

/**
 * POST /bff/me/password with `X-ERP-Session: <sid>` (Revision 6 A-12). 200 → changed, `receivedAt` = performance.now() at
 * arrival (the BFF has deleted this browser's cookie in the same answer); 401 session-ended; 409 session-changed; 422
 * naming either field → its sentences; 429 → rate-limited; a refusal the BFF made before sending → not-sent; anything
 * else, or no answer → the outcome is unknown.
 */
export async function changePassword(values: ChangePasswordValues, sid: string): Promise<ChangePasswordResult> {
  const out = await bffFetch('/bff/me/password', {
    method: 'POST',
    headers: { ...JSON_HEADERS, 'x-erp-session': sid },
    body: JSON.stringify({ current_password: values.current_password, new_password: values.new_password }),
  });
  if (!out.ok) return { kind: 'unreachable' };
  const receivedAt = performance.now();
  const r = out.response;
  if (await isNotSent(r)) return { kind: 'not-sent' };
  if (r.status === 200) {
    try {
      const b = (await r.json()) as { changed_at?: unknown };
      return typeof b.changed_at === 'string' ? { kind: 'changed', receivedAt } : { kind: 'server-error' };
    } catch {
      return { kind: 'server-error' };
    }
  }
  if (r.status === 401) return (await typeOf(r)) === 'urn:erp:bff:session-ended' ? { kind: 'session-ended' } : { kind: 'server-error' };
  if (r.status === 409) return (await typeOf(r)) === 'urn:erp:bff:session-changed' ? { kind: 'session-changed' } : { kind: 'server-error' };
  if (r.status === 422) {
    const fieldErrors = await fieldErrorsOf(r, ['current_password', 'new_password'] as const);
    return fieldErrors ? { kind: 'invalid', fieldErrors } : { kind: 'server-error' };
  }
  if (r.status === 429 && (await typeOf(r)) === 'urn:erp:problem:too-many-attempts') return { kind: 'rate-limited' };
  return { kind: 'server-error' };
}
