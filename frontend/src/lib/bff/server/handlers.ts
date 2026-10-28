import 'server-only';
import { safeReturnPath } from '../../session';
import { DECLARED_ROUTES } from '../../routes';
import { apiFetch } from './apiClient';
import { NO_STORE, bffProblem, refuseCrossSiteMutation } from './origin';
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

async function jsonBody(request: Request): Promise<Record<string, unknown> | null> {
  try {
    const b: unknown = await request.json();
    return b && typeof b === 'object' && !Array.isArray(b) ? (b as Record<string, unknown>) : null;
  } catch {
    return null;
  }
}

const field = (b: Record<string, unknown>, k: string): string | null => {
  const v = b[k];
  return typeof v === 'string' && v !== '' && v.length <= FIELD_MAX ? v : null;
};

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
  if (!body) return localRefusal();
  const workspace = field(body, 'workspace');
  const email = field(body, 'email');
  const password = field(body, 'password');
  const returnTo = body.return_to;
  if (workspace === null || email === null || password === null) return localRefusal();
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
