import 'server-only';
import type { Read } from '../reads';
import { apiFetch } from './apiClient';
import { tokenOf, type Session } from './session';

// The server transport for first render (b3b-design.md §10 "First render"): the page's RSC runs the same pure loader
// over apiFetch with this session's token. Keyed on the problem `type`, never on status alone (§6.3): 2xx → the JSON
// body; 401 → session-ended (the page redirects to sign-in); 403 permission-denied → forbidden (ST-4b); 404 not-found →
// not-found (ST-5); anything else — a BFF 502/504, a body that does not parse, a URL that cannot be built — server-error.

async function problemType(response: Response): Promise<string> {
  if (!(response.headers.get('content-type') ?? '').includes('application/problem+json')) return '';
  try {
    const b = (await response.json()) as { type?: unknown };
    return typeof b?.type === 'string' ? b.type : '';
  } catch {
    return '';
  }
}

export function serverRead(session: Session): Read {
  return async (operationId, request = {}) => {
    let response: Response;
    try {
      response = await apiFetch(operationId, { token: tokenOf(session), params: request.params, query: request.query });
    } catch {
      return { kind: 'server-error' };
    }
    if (response.status >= 200 && response.status < 300) {
      try {
        return { kind: 'ok', body: (await response.json()) as unknown };
      } catch {
        return { kind: 'server-error' };
      }
    }
    if (response.status === 401) return { kind: 'session-ended' };
    const type = await problemType(response);
    if (response.status === 403 && type === 'urn:erp:problem:permission-denied') return { kind: 'forbidden' };
    if (response.status === 404 && type === 'urn:erp:problem:not-found') return { kind: 'not-found' };
    return { kind: 'server-error' };
  };
}
