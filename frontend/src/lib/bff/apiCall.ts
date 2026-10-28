import { bffFetch } from './transport';
import { bffPathFor } from './paths';
import { classify, type ApiFailure } from './problem';
import { operation, type SurfaceOperation } from './surface';

// The ONE caller for /bff/api/* (b3b-design.md §6.1, §6.3, §7). It builds the path with bffPath, sends X-ERP-Session on
// every call (GET included), applies problem.ts, and runs the session handling:
// - 401 urn:erp:bff:session-ended → one navigation to /login?reason=session_ended&return_to=<path+search>, then every
//   call resolves `session-ended` (components render nothing for it);
// - 409 urn:erp:bff:session-changed → the FIRST in a document reloads it (a marker in sessionStorage says so); while that
//   reload is pending every further 409, and every later call, resolves `session-ended` silently and sends nothing
//   (copy RED M-3). A 409 in the document that reload produced → the fixed page (R2-4). A success clears the marker.
// Rule I-1: an operation that requires an Idempotency-Key and gets none throws in development and tests; in production
// it sends nothing and resolves `server-error`. A key is sent only when the operation requires one. No automatic retry.

export type ApiResult = { kind: 'ok'; status: number; body: unknown } | ApiFailure;

export interface CallOptions {
  readonly idempotencyKey?: string;
  readonly signal?: AbortSignal;
  readonly query?: URLSearchParams;
}

export interface MarkerStore {
  get(key: string): string | null | undefined;
  set(key: string, value: string): void;
  delete(key: string): void;
}

export interface CallerDeps {
  readonly sid: string;
  /** Full document load (never router.push). */
  readonly navigate: (url: string) => void;
  readonly reload: () => void;
  /** Called once when the second session change in a row must show the fixed page. */
  readonly onFixedError: () => void;
  /** The current path + search, for the return path. */
  readonly here: () => string;
  /** Survives the reload: sessionStorage in the browser. */
  readonly storage: MarkerStore;
  readonly development: boolean;
}

export const SESSION_CHANGED_MARKER = 'erp.session-changed-reload';

export interface ApiCaller {
  call(operationId: string, params?: Readonly<Record<string, string>>, body?: unknown, options?: CallOptions): Promise<ApiResult>;
  operation(op: SurfaceOperation, params?: Readonly<Record<string, string>>, body?: unknown, options?: CallOptions): Promise<ApiResult>;
}

export function createApiCaller(deps: CallerDeps): ApiCaller {
  let ended = false;
  let reloading = false;
  let fixed = false;

  const fault = (what: string) => {
    if (deps.development) console.error(`BFF call refused as a programming error: ${what}`);
  };

  async function run(op: SurfaceOperation, params: Readonly<Record<string, string>> = {}, body?: unknown, options: CallOptions = {}): Promise<ApiResult> {
    if (ended || reloading || fixed) return { kind: 'session-ended' };
    if (op.idempotencyKeyRequired && !options.idempotencyKey) {
      if (deps.development) throw new Error(`${op.method} ${op.template} requires an Idempotency-Key.`);
      return { kind: 'server-error' };
    }
    const path = bffPathFor(op, params) + (options.query && [...options.query.keys()].length ? `?${new URLSearchParams(options.query).toString()}` : '');
    const headers: Record<string, string> = { accept: 'application/json', 'x-erp-session': deps.sid };
    if (op.method !== 'GET') headers['content-type'] = 'application/json';
    if (op.idempotencyKeyRequired && options.idempotencyKey) headers['idempotency-key'] = options.idempotencyKey;
    const init: RequestInit = { method: op.method, headers };
    if (op.method !== 'GET' && body !== undefined) init.body = JSON.stringify(body);
    const out = await bffFetch(path, init, options.signal);
    if (!out.ok) return { kind: 'unreachable' };
    const r = out.response;
    if (r.status >= 200 && r.status < 300) {
      deps.storage.delete(SESSION_CHANGED_MARKER);
      if (r.status === 204) return { kind: 'ok', status: 204, body: null };
      try {
        const text = await r.text();
        return { kind: 'ok', status: r.status, body: text === '' ? null : (JSON.parse(text) as unknown) };
      } catch {
        return { kind: 'server-error' };
      }
    }
    const c = await classify(r);
    if (c.kind === 'session-ended-navigate') {
      if (!ended) {
        ended = true;
        deps.navigate(`/login?reason=session_ended&return_to=${encodeURIComponent(deps.here())}`);
      }
      return { kind: 'session-ended' };
    }
    if (c.kind === 'session-changed') {
      if (reloading || fixed) return { kind: 'session-ended' };
      if (deps.storage.get(SESSION_CHANGED_MARKER)) {
        fixed = true;
        deps.storage.delete(SESSION_CHANGED_MARKER);
        deps.onFixedError();
      } else {
        reloading = true;
        deps.storage.set(SESSION_CHANGED_MARKER, '1');
        deps.reload();
      }
      return { kind: 'session-ended' };
    }
    if (c.kind === 'server-error') {
      if ('fault' in c && c.fault) fault(`${r.status} ${op.method} ${op.template}`);
      return { kind: 'server-error' };
    }
    return c;
  }

  return {
    call: (operationId, params, body, options) => run(operation(operationId), params, body, options),
    operation: run,
  };
}
