import type { ApiCaller, ApiResult } from './apiCall';

// The transport the pure loaders (`lib/<surface>/load.ts`) read through (b3b-design.md §10): the same loader runs over
// the server transport on first render (`lib/bff/server/reads.ts`, apiFetch with the session's token) and over the
// browser one for every re-read (`readOf(caller)`, through `/bff/api`). A read answers its JSON body, or one of the five
// kinds the pages branch on.

export type ReadFailure = 'unreachable' | 'server-error' | 'forbidden' | 'not-found' | 'session-ended';
export type ReadAnswer = { readonly kind: 'ok'; readonly body: unknown } | { readonly kind: ReadFailure };

export interface ReadRequest {
  readonly params?: Readonly<Record<string, string>>;
  readonly query?: URLSearchParams;
  readonly signal?: AbortSignal;
}

export type Read = (operationId: string, request?: ReadRequest) => Promise<ReadAnswer>;

/** Every ApiResult failure as a read kind; the rest (a refusal, an expired key, 429) cannot come from a read: server-error. */
export function readFailureOf(kind: Exclude<ApiResult['kind'], 'ok'>): ReadFailure {
  return kind === 'unreachable' || kind === 'forbidden' || kind === 'not-found' || kind === 'session-ended' ? kind : 'server-error';
}

/** The browser transport: one call through the document's caller (X-ERP-Session, problem.ts, the session hooks). */
export function readOf(caller: ApiCaller): Read {
  return async (operationId, request = {}) => {
    const r = await caller.call(operationId, request.params ?? {}, undefined, { signal: request.signal, query: request.query });
    return r.kind === 'ok' ? { kind: 'ok', body: r.body } : { kind: readFailureOf(r.kind) };
  };
}
