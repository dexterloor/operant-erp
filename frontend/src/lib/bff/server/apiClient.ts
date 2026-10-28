import 'server-only';
import { isLoopbackBase, isLoopbackOrigin } from '../loopback';
import { bffProblem, readBodyWithin } from './origin';
import { serverOperation } from './surface';

// The ONE server-side builder of API URLs and the ONE fetch against the API (bff-auth-note.md R2-1 item 0, R1-D4
// items 1–3, R1-D5, R1-D6; b3b-design.md §5 items 8, 11, 12, 14, 17, 18).
// - The template comes from the generated OPERATIONS; every path parameter must be a canonical lowercase UUID and is
//   encoded; the URL is built with `new URL(base)` plus a pathname, never by concatenating a client string.
// - Deployment guard, run-time half (ruling Q-7): the base URL is read and checked lazily, on the first call, never at
//   module scope (`next build` loads every route module). Unset, empty or not exact-host loopback → the call throws,
//   and no request is made. The same holds for APP_PUBLIC_ORIGIN (code RED M-3): until the deployment commit the app
//   may only be served from this machine, so a self-hosted origin makes every API call throw too.
// - 15 s per call, from setTimeout + AbortController, covering the response headers AND the whole body (code RED M-1):
//   the body is read inside the timed section and handed back as a buffered Response, so no caller reads an upstream
//   body after the limit has gone. A timeout at either stage answers 504 urn:erp:bff:upstream-timeout. Never retries.
//   A body over API_RESPONSE_MAX_BYTES, or a status a Response cannot carry (1xx), answers 502 urn:erp:bff:server-error.
// - Sends only Accept, Content-Type, Idempotency-Key and, when a token is given, Authorization. `cache: 'no-store'`.

export const API_TIMEOUT_MS = 15_000;
/** The largest upstream body the BFF buffers (no operation it serves returns a file). */
export const API_RESPONSE_MAX_BYTES = 8 * 1024 * 1024;

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

// Refused at module load (R1-§10, R1-§7): a process that skips TLS verification, and the E2E-only client-address
// source on a hosted platform.
if (process.env.NODE_TLS_REJECT_UNAUTHORIZED === '0') throw new Error('Refusing to run with TLS verification switched off.');
if (process.env.BFF_CLIENT_IP_SOURCE === 'test-header' && process.env.VERCEL_ENV !== undefined) {
  throw new Error('Refusing the test client-address source on a hosted platform.');
}

function apiBase(): URL {
  const raw = process.env.ERP_API_BASE_URL;
  if (!isLoopbackBase(raw)) throw new Error('The API base URL is unset or not loopback; this build may only call a local API.');
  return new URL(raw as string);
}

/** The configured API origin (same lazy loopback check as every call). */
export function apiBaseOrigin(): string {
  return apiBase().origin;
}

function publicOriginIsLoopback(): void {
  if (!isLoopbackOrigin(process.env.APP_PUBLIC_ORIGIN)) throw new Error('The public origin is unset or not loopback; this build may only be served locally.');
}

function clientIpSource(): void {
  const src = process.env.BFF_CLIENT_IP_SOURCE;
  // Only `none` is built: the signed client-address pair (bff-auth-note.md R1-§7, P-2) needs the backend half first.
  if (src !== undefined && src !== '' && src !== 'none') throw new Error('Only BFF_CLIENT_IP_SOURCE=none is supported by this build.');
}

/** The API URL for one operation. Throws on an unknown operation, a bad or missing parameter, or a refused base. */
export function apiUrl(operationId: string, params: Readonly<Record<string, string>> = {}, query?: URLSearchParams): URL {
  const op = serverOperation(operationId);
  const base = apiBase();
  const given = Object.keys(params);
  if (given.length !== op.pathParams.length || given.some((k) => !op.pathParams.includes(k))) throw new Error(`Wrong parameters for ${operationId}.`);
  let pathname = op.template;
  for (const name of op.pathParams) {
    const value = params[name];
    if (typeof value !== 'string' || !UUID.test(value)) throw new Error(`Parameter ${name} of ${operationId} is not a record id.`);
    pathname = pathname.replace(`{${name}}`, encodeURIComponent(value));
  }
  const url = new URL(base.toString());
  url.pathname = pathname;
  url.search = query ? new URLSearchParams(query).toString() : '';
  if (url.origin !== base.origin || !url.pathname.startsWith('/api/v1/') || url.pathname !== pathname) throw new Error('Refused API URL.');
  return url;
}

export interface ApiCall {
  readonly token?: string;
  readonly params?: Readonly<Record<string, string>>;
  readonly query?: URLSearchParams;
  readonly body?: unknown;
  /** A raw body (the proxy forwards the browser's bytes); its Content-Type is passed as given. */
  readonly rawBody?: { readonly bytes: ArrayBuffer | Uint8Array<ArrayBuffer>; readonly contentType: string | null };
  readonly idempotencyKey?: string;
}

/**
 * One API call. Resolves to the upstream Response, or to a BFF problem for a timeout (504) or a network failure (502).
 * Throws (with no request made) when the URL or the base cannot be built: callers turn that into their generic problem.
 */
export async function apiFetch(operationId: string, call: ApiCall = {}): Promise<Response> {
  clientIpSource();
  publicOriginIsLoopback();
  const op = serverOperation(operationId);
  const url = apiUrl(operationId, call.params ?? {}, call.query);
  const headers = new Headers({ accept: 'application/json' });
  if (call.token !== undefined) headers.set('authorization', `Bearer ${call.token}`);
  if (call.idempotencyKey !== undefined) headers.set('idempotency-key', call.idempotencyKey);
  let body: BodyInit | undefined;
  if (call.rawBody) {
    body = call.rawBody.bytes;
    if (call.rawBody.contentType) headers.set('content-type', call.rawBody.contentType);
  } else if (call.body !== undefined) {
    body = JSON.stringify(call.body);
    headers.set('content-type', 'application/json');
  }
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), API_TIMEOUT_MS);
  try {
    const upstream = await fetch(url, { method: op.method, headers, body, cache: 'no-store', redirect: 'manual', signal: controller.signal });
    if (upstream.status < 200 || upstream.status > 599) {
      void upstream.body?.cancel().catch(() => {});
      return bffProblem(502, 'urn:erp:bff:server-error');
    }
    const bytes = await readBodyWithin(upstream.body, controller.signal, API_RESPONSE_MAX_BYTES);
    if (bytes === null) return bffProblem(502, 'urn:erp:bff:server-error');
    const empty = upstream.status === 204 || upstream.status === 205 || upstream.status === 304;
    return new Response(empty ? null : bytes, { status: upstream.status, headers: upstream.headers });
  } catch {
    return controller.signal.aborted ? bffProblem(504, 'urn:erp:bff:upstream-timeout') : bffProblem(502, 'urn:erp:bff:server-error');
  } finally {
    clearTimeout(timer);
  }
}
