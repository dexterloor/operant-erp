import { render } from '@testing-library/react';
import type { ReactElement } from 'react';
import { vi } from 'vitest';
import { BffProvider } from '@/app/(app)/BffProvider';
import { TooltipProvider } from '@/components/ui/Tooltip';
import { createApiCaller, type ApiCaller } from '@/lib/bff/apiCall';
import type { User } from '@/lib/api/users';

// Shared harness for the wired surfaces (b3b-design.md §14 "Wiring per surface"): a sequential fetch stub that records
// every request and answers in order (or never, for a deadline case), problem documents shaped as the backend serves
// them (`ApiProblem.php`: `type`, and `rule` + `message` on a business-rule refusal), and a BffProvider wrapper.

export const SID = 'sid-0000000000000000000000';
/** Far from any CI or laptop zone, so a date rendered in the browser's own zone would differ. */
export const TZ = 'Pacific/Kiritimati';
export const ID = '0b7c1f9e-3f4a-4c8e-9d2b-5a6e7f8091a2';
export const VIEWER_ID = '1c8d2a0f-4b5a-4d9f-8e3c-6b7f80912a3b';
export const ROLE_A = '2d9e3b1a-5c6b-4e0a-9f4d-7c8091a23b4c';
export const ROLE_B = '3e0f4c2b-6d7c-4f1b-8a5e-8d91a2b34c5d';
export const PROPOSAL_1 = '4f105d3c-7e8d-4a2c-9b6f-9ea2b3c45d6e';

export interface Recorded {
  readonly url: string;
  readonly method: string;
  readonly headers: Headers;
  readonly body: string | null;
}

export type Reply = Response | 'hang' | ((req: Recorded) => Response | 'hang' | Promise<Response>);

/** A reply that arrives after `ms` (timer time, so fake timers drive it). */
export const later = (ms: number, r: () => Response): Reply => () => new Promise<Response>((resolve) => setTimeout(() => resolve(r()), ms));

export const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
export const empty = (status = 204) => new Response(null, { status });
export const problem = (status: number, type: string, extra: Record<string, unknown> = {}) =>
  new Response(JSON.stringify({ type: `urn:erp:problem:${type}`, title: 'x', status, ...extra }), { status, headers: { 'content-type': 'application/problem+json' } });
/** `ApiProblem::businessRuleViolated($rule, $message)` (backend/src/Platform/Http/Problem/ApiProblem.php). */
export const refused = (rule: string, message: string) => problem(409, 'business-rule-violated', { rule, message });

/** Answers each request with the next reply, in order. An unexpected extra request answers 500 and is recorded. */
export function stubFetch(replies: Reply[]): Recorded[] {
  const queue = [...replies];
  const calls: Recorded[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
      const req: Recorded = {
        url: String(input),
        method: init.method ?? 'GET',
        headers: new Headers(init.headers),
        body: typeof init.body === 'string' ? init.body : null,
      };
      calls.push(req);
      // As the real fetch does: an already-aborted signal rejects at once, and consumes no reply.
      if (init.signal?.aborted) throw new DOMException('aborted', 'AbortError');
      const next = queue.shift() ?? json(500, { unexpected: req.url });
      const r = typeof next === 'function' ? next(req) : next;
      // As the real fetch does: an abort rejects a request still in flight (a hanging one, or a later reply).
      if (r === 'hang' || r instanceof Promise) {
        return new Promise<Response>((resolve, reject) => {
          init.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')), { once: true });
          if (r instanceof Promise) void r.then(resolve);
        });
      }
      return r;
    }),
  );
  return calls;
}

export function testCaller(): ApiCaller {
  return createApiCaller({
    sid: SID,
    navigate: vi.fn(),
    reload: vi.fn(),
    onFixedError: vi.fn(),
    here: () => '/admin/users',
    storage: { get: () => null, set: () => {}, delete: () => {} },
    development: true,
  });
}

export function renderWired(ui: ReactElement) {
  return render(
    <TooltipProvider>
      <BffProvider session={{ sid: SID, tz: TZ }} reload={() => {}}>
        {ui}
      </BffProvider>
    </TooltipProvider>,
  );
}

export const user = (over: Partial<User> = {}): User => ({
  id: ID,
  email: 'ada@example.test',
  display_name: 'Ada Lovelace',
  is_active: true,
  archived_at: null,
  roles: [],
  margin_exposure: false,
  ...over,
});

export const page = <T,>(items: T[], over: Record<string, unknown> = {}) => ({ items, next_cursor: null, previous_cursor: null, tenant_has_rows: items.length > 0, ...over });

export const offered = (id: string, name: string, over: Record<string, unknown> = {}) => ({
  id,
  name,
  conflicts_with: [],
  margin_exposure_with: [],
  refusal: null,
  requires_owner_confirmation: false,
  ...over,
});
export const role = (id: string, name: string, codes: string[] = []) => ({
  id,
  name,
  description: '',
  permission_codes: codes,
  is_system: false,
  holder_count: 0,
  margin_exposure: false,
  archived_at: null,
});
export const proposal = (id: string, roleId: string, roleName: string, over: Record<string, unknown> = {}) => ({
  id,
  user: { id: ID, display_name: 'Ada Lovelace' },
  role: { id: roleId, name: roleName },
  permission_codes: [],
  status: 'pending',
  is_expired: false,
  proposed_by: { id: VIEWER_ID, display_name: 'Me Myself' },
  proposed_at: '2026-10-27T10:00:00Z',
  expires_at: '2026-11-10T10:00:00Z',
  decided_by: null,
  decided_at: null,
  reason: null,
  lapse_reason: null,
  ...over,
});
