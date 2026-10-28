import type { Forbidden, NotFound, RateLimited, Refused, ServerError, SessionEnded, Unreachable } from '../results';

// Pure: a /bff/api/* response → its result kind (b3b-design.md §6.3, keyed on problem `type`, never on status alone).
// Two members are instructions for apiCall rather than results: `session-ended-navigate` (go to /login, then resolve
// `session-ended`) and `session-changed` (reload once, then the fixed page). `fault: true` marks a server-error that can
// only come from a programming mistake (a refused route, a missing key), for a development console line.

export interface KeyExpired {
  kind: 'key-expired';
}

export type Classified =
  | { kind: 'session-ended-navigate' }
  | { kind: 'session-changed' }
  | Forbidden
  | NotFound
  | Refused
  | KeyExpired
  | RateLimited
  | (ServerError & { fault?: true });

export type ApiFailure = SessionEnded | Forbidden | NotFound | Refused | KeyExpired | RateLimited | ServerError | Unreachable;

const P = 'urn:erp:problem:';
const B = 'urn:erp:bff:';

interface ProblemBody {
  type?: unknown;
  rule?: unknown;
  message?: unknown;
  errors?: unknown;
}

const str = (x: unknown) => (typeof x === 'string' ? x : '');

async function bodyOf(response: Response): Promise<ProblemBody | null> {
  if (!(response.headers.get('content-type') ?? '').includes('application/problem+json')) return null;
  try {
    const b: unknown = await response.json();
    return b && typeof b === 'object' ? (b as ProblemBody) : null;
  } catch {
    return null;
  }
}

/** Classifies a non-2xx answer from the proxy. */
export async function classify(response: Response): Promise<Classified> {
  const body = await bodyOf(response);
  const status = response.status;
  if (status >= 500) return { kind: 'server-error' };
  if (body === null) return { kind: 'server-error', fault: true };
  const type = str(body.type);
  switch (status) {
    case 401:
      return type === `${B}session-ended` ? { kind: 'session-ended-navigate' } : { kind: 'server-error', fault: true };
    case 403:
      if (type === `${P}permission-denied`) return { kind: 'forbidden' };
      return { kind: 'server-error', fault: true };
    case 404:
      if (type === `${P}not-found`) return { kind: 'not-found' };
      return { kind: 'server-error', fault: true };
    case 409:
      if (type === `${B}session-changed`) return { kind: 'session-changed' };
      if (type === `${P}business-rule-violated`) return { kind: 'refused', rule: str(body.rule), message: str(body.message) };
      if (type === `${P}idempotency-key-conflict` || type === `${P}stale-record`) return { kind: 'refused', rule: str(body.rule), message: '' };
      return { kind: 'server-error', fault: true };
    case 422: {
      if (type !== `${P}validation-failed`) return { kind: 'server-error', fault: true };
      const first = Array.isArray(body.errors) ? (body.errors[0] as { pointer?: unknown; code?: unknown; message?: unknown } | undefined) : undefined;
      if (first && first.pointer === '#Idempotency-Key' && first.code === 'key_expired') return { kind: 'key-expired' };
      return { kind: 'refused', rule: str(first?.code), message: str(first?.message) };
    }
    case 429:
      return { kind: 'rate-limited' };
    default:
      // 405, 406, 412, 415, 428 and anything unlisted (final RED L-4).
      return { kind: 'server-error', fault: true };
  }
}
