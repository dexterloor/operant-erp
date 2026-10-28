import 'server-only';
import { safeReturnPath } from '../../session';
import { DECLARED_ROUTES } from '../../routes';
import { apiFetch } from './apiClient';
import { NO_STORE, bffProblem, readRequestBody, refuseCrossSiteMutation } from './origin';
import { clearedSessionCookie, newSessionPayload, sealSession, sessionCookie } from './session';

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
  if (workspace === null || email === null || password === null || CONTROL_OR_FORMAT.test(workspace)) return localRefusal();
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
      sealed = await sealSession(newSessionPayload({ jwt: token.access_token, exp, workspaceTyped: workspace.trim(), timeZone: body.time_zone }));
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
  if (workspace === null || email === null || !body) return localRefusal();
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
  const token = body && credential(body, 'token', true);
  const password = body && credential(body, 'password', true);
  if (!token || password === null || !body) return localRefusal();
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
