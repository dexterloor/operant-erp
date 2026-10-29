import 'server-only';
import { safeReturnPath } from '../../session';
import { DECLARED_ROUTES } from '../../routes';
import { apiFetch } from './apiClient';
import { NO_STORE, bffProblem, readRequestBody, refuseCrossSiteMutation } from './origin';
import { clearedSessionCookie, cookieValue, newSessionPayload, sealSession, sessionCookie, tokenOf, unsealSession } from './session';

// The named handlers (bff-auth-note.md D-4.1, §4, §5, R1-§4, R1-§6.1; b3b-design.md §5 items 3–7, 15). Each is a
// `Request → Response` function; the route files under src/app/bff are thin adapters. Every one goes through the Origin
// wrapper first, maps its operation's answer on problem `type` (never status alone: both 429s share one), sends the
// browser only BFF-owned bodies, and logs nothing.

const FIELD_MAX = 255;
const RETURN_TO_MAX = 2048;

const SIGN_IN_PASSED_THROUGH: Readonly<Record<number, readonly string[]>> = {
  401: ['urn:erp:problem:authentication-failed'],
  429: ['urn:erp:problem:too-many-attempts', 'urn:erp:problem:sign-in-paused'],
  422: ['urn:erp:problem:validation-failed'],
};

const serverError = () => bffProblem(502, 'urn:erp:bff:server-error');
const localRefusal = () => bffProblem(422, 'urn:erp:problem:validation-failed', { errors: [] });
/**
 * A body this server will not forward, refused before any API call (c10 FINAL A.1): the browser reads it as "not sent".
 * Sign-in keeps `localRefusal()` (its page maps a local 422 to the ruled wrong-details failure).
 */
const notSentRefusal = () => bffProblem(422, 'urn:erp:bff:request-invalid');

/**
 * The request's JSON object; null when it is not one; a Response (413 / 408) when the body is over the cap or does not
 * arrive in time (code RED L-2, origin.ts).
 */
async function jsonBody(request: Request): Promise<Record<string, unknown> | Response | null> {
  const read = await readRequestBody(request);
  if ('refused' in read) return read.refused;
  try {
    const b: unknown = JSON.parse(new TextDecoder().decode(read.bytes));
    return b && typeof b === 'object' && !Array.isArray(b) ? (b as Record<string, unknown>) : null;
  } catch {
    return null;
  }
}

const field = (b: Record<string, unknown>, k: string): string | null => {
  const v = b[k];
  return typeof v === 'string' && v !== '' && v.length <= FIELD_MAX ? v : null;
};

/**
 * The API's own password bound (PasswordRules: at most 4096 bytes), so no password the reset flow accepts is refused at
 * sign-in (code RED M-2). Measured in UTF-8 bytes, as the API measures it.
 */
const CREDENTIAL_MAX = 4096;
const utf8Bytes = (v: string): number => new TextEncoder().encode(v).length;

/** A non-empty string of at most CREDENTIAL_MAX UTF-8 bytes, else null. */
const credential = (b: Record<string, unknown>, k: string, allowEmpty = false): string | null => {
  const v = b[k];
  return typeof v === 'string' && (allowEmpty || v !== '') && utf8Bytes(v) <= CREDENTIAL_MAX ? v : null;
};

/**
 * Control and format characters (Unicode Cc / Cf: bidi overrides, zero-width marks, BOM). The typed workspace name is
 * shown in the top bar (D-9), so one carrying them could read as another workspace's name (code RED L-5). Refused at
 * sign-in; a name holding them would not resolve at the API either.
 */
const CONTROL_OR_FORMAT = /[\p{Cc}\p{Cf}]/u;

/**
 * PHP's `trim()` with its default set — exactly space, tab, newline, carriage return, NUL and vertical tab, from both ends —
 * which is what the API applies to the workspace name (PreAuthKeys.php; b3b-followup C-5a, R-1). Not JS `trim()`, which
 * also strips U+00A0, U+FEFF and other spaces the API keeps.
 */
function phpTrim(value: string): string {
  return value.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
}

async function problemType(response: Response): Promise<{ type: string | null; errors: unknown }> {
  if (!(response.headers.get('content-type') ?? '').includes('application/problem+json')) return { type: null, errors: undefined };
  try {
    const b = (await response.json()) as { type?: unknown; errors?: unknown };
    return { type: typeof b.type === 'string' ? b.type : null, errors: b.errors };
  } catch {
    return { type: null, errors: undefined };
  }
}

/** The API's field errors reduced to pointer + code: no server sentence crosses this handler. */
const pointers = (errors: unknown) =>
  Array.isArray(errors)
    ? errors.flatMap((e) => (e && typeof e === 'object' && typeof (e as { pointer?: unknown }).pointer === 'string' ? [{ pointer: (e as { pointer: string }).pointer, code: String((e as { code?: unknown }).code ?? '') }] : []))
    : [];

/**
 * POST /bff/session. Body `{workspace, email, password}` plus two optional fields that are never forwarded: `return_to`
 * (≤ 2048) and `time_zone`. On 200 the token is sealed into the cookie and the reply is `{redirect}`, the return path
 * validated against the built pages with `/` as the fixed fallback.
 */
export async function handleSignIn(request: Request): Promise<Response> {
  const refused = refuseCrossSiteMutation(request);
  if (refused) return refused;
  const body = await jsonBody(request);
  if (body instanceof Response) return body;
  if (!body) return localRefusal();
  const workspace = field(body, 'workspace');
  const email = field(body, 'email');
  const password = credential(body, 'password');
  const returnTo = body.return_to;
  if (workspace === null || email === null || password === null || CONTROL_OR_FORMAT.test(phpTrim(workspace))) return localRefusal();
  if (returnTo !== undefined && returnTo !== null && (typeof returnTo !== 'string' || returnTo.length > RETURN_TO_MAX)) return localRefusal();

  let upstream: Response;
  try {
    upstream = await apiFetch('IssueToken', { body: { workspace, email, password } });
  } catch {
    return serverError();
  }

  if (upstream.status === 200) {
    let token: { access_token?: unknown; expires_at?: unknown };
    try {
      token = (await upstream.json()) as typeof token;
    } catch {
      return serverError();
    }
    const exp = typeof token.expires_at === 'string' ? Date.parse(token.expires_at) / 1000 : NaN;
    if (typeof token.access_token !== 'string' || token.access_token === '' || !Number.isFinite(exp) || exp <= Date.now() / 1000) return serverError();
    let sealed: string;
    try {
      sealed = await sealSession(newSessionPayload({ jwt: token.access_token, exp, workspaceTyped: phpTrim(workspace), timeZone: body.time_zone }));
    } catch {
      return serverError();
    }
    const redirect = safeReturnPath(typeof returnTo === 'string' ? returnTo : null, { declaredRoutes: DECLARED_ROUTES, fallback: '/' });
    return new Response(JSON.stringify({ redirect }), {
      status: 200,
      headers: { 'content-type': 'application/json', 'cache-control': NO_STORE, 'set-cookie': sessionCookie(sealed) },
    });
  }

  const allowed = SIGN_IN_PASSED_THROUGH[upstream.status];
  const { type, errors } = await problemType(upstream);
  if (!allowed || type === null || !allowed.includes(type)) return serverError();
  return bffProblem(upstream.status, type, upstream.status === 422 ? { errors: pointers(errors) } : {});
}

/**
 * POST /bff/session/sign-out. Deletes this browser's cookie; no API call (none exists, F-10). The token stays valid at
 * the API until it expires (owner ruling a1), so nothing here may say "signed out everywhere".
 */
export async function handleSignOut(request: Request): Promise<Response> {
  const refused = refuseCrossSiteMutation(request);
  if (refused) return refused;
  return new Response(JSON.stringify({ redirect: '/login' }), {
    status: 200,
    headers: { 'content-type': 'application/json', 'cache-control': NO_STORE, 'set-cookie': clearedSessionCookie() },
  });
}

const text = (b: Record<string, unknown>, k: string, max: number): string | null => {
  const v = b[k];
  return typeof v === 'string' && v.length <= max ? v : null;
};

/** The API's field errors with their sentences: the reset pages render a server 422 sentence verbatim on its field. */
const fieldErrors = (errors: unknown) =>
  Array.isArray(errors)
    ? errors.flatMap((e) =>
        e && typeof e === 'object' && typeof (e as { pointer?: unknown }).pointer === 'string' && typeof (e as { message?: unknown }).message === 'string'
          ? [{ pointer: (e as { pointer: string }).pointer, code: String((e as { code?: unknown }).code ?? ''), message: (e as { message: string }).message }]
          : [],
      )
    : [];

/** Passes through only the named problem types, reduced to `type` (422: field errors); anything else is the generic problem. */
async function credentialProblem(upstream: Response, allowed: Readonly<Record<number, readonly string[]>>): Promise<Response> {
  const types = allowed[upstream.status];
  const { type, errors } = await problemType(upstream);
  if (!types || type === null || !types.includes(type)) return serverError();
  return bffProblem(upstream.status, type, upstream.status === 422 ? { errors: fieldErrors(errors) } : {});
}

const VALIDATION = 'urn:erp:problem:validation-failed';
const TOO_MANY = 'urn:erp:problem:too-many-attempts';

/**
 * POST /bff/password-resets: `{workspace, email}` → the API's reset request. 202 (for every well-formed input, F-11) →
 * 202 with no body. No session is read or touched.
 */
export async function handleResetRequest(request: Request): Promise<Response> {
  const refused = refuseCrossSiteMutation(request);
  if (refused) return refused;
  const body = await jsonBody(request);
  if (body instanceof Response) return body;
  const workspace = body && text(body, 'workspace', FIELD_MAX);
  const email = body && text(body, 'email', FIELD_MAX);
  if (workspace === null || email === null || !body) return notSentRefusal();
  let upstream: Response;
  try {
    upstream = await apiFetch('CreatePasswordReset', { body: { workspace, email } });
  } catch {
    return serverError();
  }
  if (upstream.status === 202) return new Response(null, { status: 202, headers: { 'cache-control': NO_STORE } });
  return credentialProblem(upstream, { 422: [VALIDATION], 429: [TOO_MANY] });
}

/**
 * POST /bff/password-resets/redemption: `{token, password}` → the API's redemption. 200 `{changed_at}` → the same body,
 * and this browser's session cookie is deleted in the same response (the reset has just ended every session of that
 * person, bff-auth-note.md §8). A 401 is a bad, used or expired link: never "session ended", and no cookie change.
 */
export async function handleResetRedemption(request: Request): Promise<Response> {
  const refused = refuseCrossSiteMutation(request);
  if (refused) return refused;
  const body = await jsonBody(request);
  if (body instanceof Response) return body;
  // The token keeps the 4096-byte bound (it is never typed). The password is not capped here: the API refuses an
  // over-long one with its own 422 "The new password is too long." (measured, c10-progress M-3), so the page can say so.
  const token = body && credential(body, 'token', true);
  const password = body && typeof body.password === 'string' ? body.password : null;
  if (!token || password === null || !body) return notSentRefusal();
  let upstream: Response;
  try {
    upstream = await apiFetch('CreatePasswordResetRedemption', { body: { token, password } });
  } catch {
    return serverError();
  }
  if (upstream.status === 200) {
    let changedAt: unknown;
    try {
      changedAt = ((await upstream.json()) as { changed_at?: unknown }).changed_at;
    } catch {
      return serverError();
    }
    if (typeof changedAt !== 'string') return serverError();
    return new Response(JSON.stringify({ changed_at: changedAt }), {
      status: 200,
      headers: { 'content-type': 'application/json', 'cache-control': NO_STORE, 'set-cookie': clearedSessionCookie() },
    });
  }
  return credentialProblem(upstream, { 401: ['urn:erp:problem:authentication-failed'], 422: [VALIDATION], 429: [TOO_MANY] });
}

/**
 * POST /bff/me/password: `{current_password, new_password}` → the API's ChangePassword, as the session's person
 * (c10-design.md "Revision 1 — FINAL" B.1; b4-architecture.md Revision 6 A-12). Order — every refusal before the API call
 * makes none, and the session is checked before the body is read, as the proxy does (c10-final-red L-3):
 *   1. the Origin / JSON wrapper → 403 / 415;
 *   2. `X-ERP-Session` missing, or `Sec-Fetch-Mode: navigate` → 403 request-refused;
 *   3. the cookie: none → 401 session-ended, no delete; a key that cannot be read → 502, no delete; a cookie that does not
 *      open or has passed its sealed exp → 401 session-ended and the cookie deleted (proxy parity);
 *   4. `X-ERP-Session` ≠ the cookie's sid → 409 session-changed (a page rendered for one person never changes another's);
 *   5. the body: both fields strings, else request-invalid ("not sent"). No length or emptiness check: the API owns those
 *      rules and their sentences, and answers 422 for an over-long password (measured, c10-progress M-3);
 *   6. one API call with the session's bearer.
 * The answer: 200 with a string `changed_at` → 200 `{changed_at}` and this browser's cookie deleted (the token is already
 * dead at the API); a 200 without one → 502 and the cookie deleted (the change committed); API 401 → 401 session-ended,
 * the cookie kept (a late 401 must not wipe a newer session from another tab); 422 → the field errors with their
 * sentences; 429 → 429; anything else, including a 504 or a 200 whose body could not be read in time → the generic 502,
 * the cookie kept (outcome unknown; if the change committed, the next call routes to sign-in). Logs nothing.
 */
export async function handleChangePassword(request: Request): Promise<Response> {
  const refused = refuseCrossSiteMutation(request);
  if (refused) return refused;
  const declaredSid = request.headers.get('x-erp-session');
  if (!declaredSid || request.headers.get('sec-fetch-mode') === 'navigate') return bffProblem(403, 'urn:erp:bff:request-refused');

  const value = cookieValue(request);
  if (value === undefined) return bffProblem(401, 'urn:erp:bff:session-ended');
  let session: Awaited<ReturnType<typeof unsealSession>>;
  try {
    session = await unsealSession(value);
  } catch {
    return serverError();
  }
  if (session === null) {
    const ended = bffProblem(401, 'urn:erp:bff:session-ended');
    ended.headers.set('set-cookie', clearedSessionCookie());
    return ended;
  }
  if (declaredSid !== session.sid) return bffProblem(409, 'urn:erp:bff:session-changed');

  const body = await jsonBody(request);
  if (body instanceof Response) return body;
  if (!body || typeof body.current_password !== 'string' || typeof body.new_password !== 'string') return notSentRefusal();

  let upstream: Response;
  try {
    upstream = await apiFetch('ChangePassword', { token: tokenOf(session), body: { current_password: body.current_password, new_password: body.new_password } });
  } catch {
    return serverError();
  }

  if (upstream.status === 200) {
    let changedAt: unknown;
    try {
      changedAt = ((await upstream.json()) as { changed_at?: unknown }).changed_at;
    } catch {
      changedAt = undefined;
    }
    const answer =
      typeof changedAt === 'string'
        ? new Response(JSON.stringify({ changed_at: changedAt }), { status: 200, headers: { 'content-type': 'application/json', 'cache-control': NO_STORE } })
        : serverError();
    answer.headers.set('set-cookie', clearedSessionCookie());
    return answer;
  }
  if (upstream.status === 401) {
    const { type } = await problemType(upstream);
    return type === 'urn:erp:problem:authentication-failed' ? bffProblem(401, 'urn:erp:bff:session-ended') : serverError();
  }
  return credentialProblem(upstream, { 422: [VALIDATION], 429: [TOO_MANY] });
}
