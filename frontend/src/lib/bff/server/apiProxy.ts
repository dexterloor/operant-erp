import 'server-only';
import { apiFetch } from './apiClient';
import { NO_STORE, bffProblem, isBffProblem, refuseCrossSiteMutation } from './origin';
import { SESSION_COOKIE, clearedSessionCookie, tokenOf, unsealSession } from './session';
import { allOperations, proxyAllowlist } from './surface';

// The authenticated proxy, /bff/api/[...path] (bff-auth-note.md D-4.2, R1-D4, R1-D5, R1-D6, R1-§6.1, R2-1; b3b-design.md
// §5 items 9–13). Order, each refusal making no API call:
//   1. an incoming `x-middleware-subrequest` → 404 (the proxy never relies on middleware having run);
//   2. X-ERP-Session missing, `Sec-Fetch-Mode: navigate`, or a cross-site `Sec-Fetch-Site` → 403 request-refused;
//   3. a write: the Origin / JSON wrapper → 403 / 415;
//   4. the raw path: every segment non-empty, not `.`/`..`, free of `% / \ @ :` and control characters, and method +
//      `/api/v1/` + segments equal to an allowlist template (record ids in `{…}` places) → else 404 not-routable;
//   5. the cookie: none → 401 session-ended; one that does not open or has passed its sealed exp → 401 session-ended
//      and the cookie deleted;
//   6. X-ERP-Session ≠ the cookie's sid → 409 session-changed.
// Then one apiFetch with the session's bearer. The answer keeps its status, Content-Type, Content-Disposition and body;
// Set-Cookie / WWW-Authenticate and every other upstream header are dropped; Location is rewritten from /api/v1/… to
// /bff/api/… (Content-Location dropped). An API 401 becomes 401 session-ended WITHOUT deleting the cookie. A 5xx, or
// an error whose body is not a problem document, becomes the BFF's generic problem (L-2). Every answer: no-store, private.

const notRoutable = () => bffProblem(404, 'urn:erp:bff:not-routable');
const refused = () => bffProblem(403, 'urn:erp:bff:request-refused');
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
const BAD_SEGMENT = /[%/\\@:\u0000-\u001f\u007f]/;
const PREFIX = '/bff/api/';

interface Route {
  readonly operationId: string;
  readonly params: Record<string, string>;
}

/** The allowlisted operation a method + raw path names, or null. */
function route(method: string, rawPath: string): Route | null {
  if (!rawPath.startsWith(PREFIX)) return null;
  const segments = rawPath.slice(PREFIX.length).split('/');
  for (const s of segments) {
    if (s === '' || s === '.' || s === '..' || BAD_SEGMENT.test(s)) return null;
  }
  const allowed = new Set(proxyAllowlist().map((r) => `${r.method} ${r.template}`));
  for (const [operationId, op] of Object.entries(allOperations())) {
    if (op.method !== method || !allowed.has(`${op.method} ${op.template}`)) continue;
    const parts = op.template.replace(/^\/api\/v1\//, '').split('/');
    if (parts.length !== segments.length) continue;
    const params: Record<string, string> = {};
    let match = true;
    for (let i = 0; i < parts.length && match; i++) {
      const t = parts[i]!;
      const s = segments[i]!;
      if (t.startsWith('{') && t.endsWith('}')) {
        if (UUID.test(s)) params[t.slice(1, -1)] = s;
        else match = false;
      } else if (t !== s) match = false;
    }
    if (match) return { operationId, params };
  }
  return null;
}

function cookieValue(request: Request): string | undefined {
  const header = request.headers.get('cookie');
  if (!header) return undefined;
  for (const part of header.split(';')) {
    const at = part.indexOf('=');
    if (at > 0 && part.slice(0, at).trim() === SESSION_COOKIE) return part.slice(at + 1).trim();
  }
  return undefined;
}

function sessionEnded(deleteCookie: boolean): Response {
  const r = bffProblem(401, 'urn:erp:bff:session-ended');
  if (deleteCookie) r.headers.set('set-cookie', clearedSessionCookie());
  return r;
}

export async function handleApiProxy(request: Request): Promise<Response> {
  const h = request.headers;
  if (h.has('x-middleware-subrequest')) return notRoutable();
  const site = h.get('sec-fetch-site');
  if (!h.get('x-erp-session') || h.get('sec-fetch-mode') === 'navigate' || (site !== null && site !== 'same-origin')) return refused();
  const method = request.method.toUpperCase();
  if (method !== 'GET') {
    const r = refuseCrossSiteMutation(request);
    if (r) return r;
  }
  const url = new URL(request.url);
  const target = route(method, url.pathname);
  if (target === null) return notRoutable();

  const value = cookieValue(request);
  if (value === undefined) return sessionEnded(false);
  const session = await unsealSession(value);
  if (session === null) return sessionEnded(true);
  if (h.get('x-erp-session') !== session.sid) return bffProblem(409, 'urn:erp:bff:session-changed');

  const key = h.get('idempotency-key');
  let upstream: Response;
  try {
    upstream = await apiFetch(target.operationId, {
      token: tokenOf(session),
      params: target.params,
      query: new URLSearchParams(url.search),
      rawBody: method === 'GET' ? undefined : { bytes: await request.arrayBuffer(), contentType: h.get('content-type') },
      idempotencyKey: key === null ? undefined : key,
    });
  } catch {
    return bffProblem(502, 'urn:erp:bff:server-error');
  }
  return translate(upstream);
}

function translate(upstream: Response): Response {
  const status = upstream.status;
  const type = upstream.headers.get('content-type');
  const isProblem = (type ?? '').includes('application/problem+json');
  if (status === 401) return sessionEnded(false);
  if (status >= 500) {
    // apiFetch's own 504 / 502 are already BFF problems; any upstream 5xx body is replaced (L-2).
    return isBffProblem(upstream) ? upstream : bffProblem(502, 'urn:erp:bff:server-error');
  }
  if (status >= 300 && !isProblem) return bffProblem(502, 'urn:erp:bff:server-error');
  const headers = new Headers({ 'cache-control': NO_STORE });
  if (type) headers.set('content-type', type);
  const disposition = upstream.headers.get('content-disposition');
  if (disposition) headers.set('content-disposition', disposition);
  const location = upstream.headers.get('location');
  if (location && location.startsWith('/api/v1/') && !location.startsWith('/api/v1/auth/')) headers.set('location', `/bff/api/${location.slice('/api/v1/'.length)}`);
  return new Response(status === 204 || status === 304 ? null : upstream.body, { status, headers });
}
