import { afterEach, describe, expect, it, vi } from 'vitest';
import type { SurfaceOperation } from '@/lib/bff/surface';
import { bffPathFor } from '@/lib/bff/paths';
import { classify } from '@/lib/bff/problem';
import { createApiCaller, type CallerDeps } from '@/lib/bff/apiCall';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';

// The browser half of the proxy contract (b3b-design.md §6, §7): bffPath (UUID-checked), problem.ts (T-P, one case per
// §6.3 row incl. the default row), callApi (T-AU, T-I1, T-SC and the session-ended hook) and deadline.ts (T-R2).

const ID = '0b7c1f9e-3f4a-4c8e-9d2b-5a6e7f8091a2';
const op = (over: Partial<SurfaceOperation> = {}): SurfaceOperation => ({
  method: 'GET',
  template: '/api/v1/users/{id}',
  pathParams: ['id'],
  idempotencyKeyRequired: false,
  permission: { kind: 'all_of', codes: ['app_user.view'] },
  ...over,
});

const problem = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/problem+json' } });

afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe('bffPath — the one client-side /bff/api path builder', () => {
  it('maps /api/v1/ to /bff/api/ and encodes a canonical id', () => {
    expect(bffPathFor(op(), { id: ID })).toBe(`/bff/api/users/${ID}`);
    expect(bffPathFor(op({ template: '/api/v1/users/{id}/roles/{roleId}', pathParams: ['id', 'roleId'] }), { id: ID, roleId: ID })).toBe(`/bff/api/users/${ID}/roles/${ID}`);
    expect(bffPathFor(op({ template: '/api/v1/me', pathParams: [] }), {})).toBe('/bff/api/me');
  });

  it('a bad id fails before any request (RED M-7)', () => {
    for (const bad of ['..', '../auth/token', 'abc', ID.toUpperCase(), `${ID}/x`, '%2e%2e', '']) expect(() => bffPathFor(op(), { id: bad }), bad).toThrow();
    expect(() => bffPathFor(op(), {})).toThrow();
    expect(() => bffPathFor(op(), { id: ID, extra: ID })).toThrow();
    expect(() => bffPathFor(op({ template: '/api/v1/auth/token', pathParams: [] }), {})).toThrow();
  });
});

describe('T-P — problem.ts, one case per §6.3 row', () => {
  const rows: [string, number, unknown, string, object?][] = [
    ['401 session-ended', 401, { type: 'urn:erp:bff:session-ended' }, 'session-ended-navigate'],
    ['409 session-changed', 409, { type: 'urn:erp:bff:session-changed' }, 'session-changed'],
    ['403 permission-denied', 403, { type: 'urn:erp:problem:permission-denied' }, 'forbidden'],
    ['403 request-refused', 403, { type: 'urn:erp:bff:request-refused' }, 'server-error'],
    ['404 not-routable', 404, { type: 'urn:erp:bff:not-routable' }, 'server-error'],
    ['415', 415, { type: 'urn:erp:problem:unsupported-media-type' }, 'server-error'],
    ['405', 405, { type: 'urn:erp:problem:method-not-allowed' }, 'server-error'],
    ['406', 406, { type: 'urn:erp:problem:not-acceptable' }, 'server-error'],
    ['404 not-found', 404, { type: 'urn:erp:problem:not-found' }, 'not-found'],
    ['409 business rule', 409, { type: 'urn:erp:problem:business-rule-violated', rule: 'user_role.already_held', message: 'Already has it.' }, 'refused', { rule: 'user_role.already_held', message: 'Already has it.' }],
    ['409 business rule, no message', 409, { type: 'urn:erp:problem:business-rule-violated', rule: 'x.y' }, 'refused', { rule: 'x.y', message: '' }],
    ['409 idempotency-key-conflict', 409, { type: 'urn:erp:problem:idempotency-key-conflict', detail: 'internal' }, 'refused', { rule: '', message: '' }],
    ['409 stale-record', 409, { type: 'urn:erp:problem:stale-record', current: {} }, 'refused', { rule: '', message: '' }],
    ['422 key expired', 422, { type: 'urn:erp:problem:validation-failed', errors: [{ pointer: '#Idempotency-Key', code: 'key_expired', message: 'x' }] }, 'key-expired'],
    ['422 field', 422, { type: 'urn:erp:problem:validation-failed', errors: [{ pointer: '/name', code: 'name_taken', message: 'That name is taken.' }] }, 'refused', { rule: 'name_taken', message: 'That name is taken.' }],
    ['422 no errors', 422, { type: 'urn:erp:problem:validation-failed' }, 'refused', { rule: '', message: '' }],
    ['428', 428, { type: 'urn:erp:problem:idempotency-key-required' }, 'server-error'],
    ['412 (never served)', 412, { type: 'x' }, 'server-error'],
    ['429', 429, { type: 'urn:erp:problem:too-many-attempts' }, 'rate-limited'],
    ['500', 500, { type: 'urn:erp:bff:server-error' }, 'server-error'],
    ['502', 502, { type: 'urn:erp:bff:server-error' }, 'server-error'],
    ['504 upstream-timeout', 504, { type: 'urn:erp:bff:upstream-timeout' }, 'server-error'],
    // config-refusal Option A: the proxy now answers 503 not-sent for a configuration refusal, but the proxy screens keep
    // server-error until Option B (config-refusal-not-sent.md §8 R-c). Changing this is a deliberate pin move.
    ['503 not-sent (Option B deferred)', 503, { type: 'urn:erp:bff:not-sent' }, 'server-error'],
    ['default: 401 of another type', 401, { type: 'urn:erp:problem:authentication-failed' }, 'server-error'],
    ['default: an unlisted status', 418, { type: 'urn:erp:problem:x' }, 'server-error'],
    ['default: 409 unknown type', 409, { type: 'urn:erp:problem:other' }, 'server-error'],
    ['default: 403 unknown type', 403, { type: 'x' }, 'server-error'],
  ];

  it.each(rows)('%s → %s', async (_n, status, body, kind, extra) => {
    const got = await classify(problem(status, body));
    expect(got.kind).toBe(kind);
    if (extra) expect(got).toMatchObject(extra);
  });

  it('a body that is not a problem document, or that does not parse, is server-error', async () => {
    expect((await classify(new Response('<html>', { status: 403, headers: { 'content-type': 'text/html' } }))).kind).toBe('server-error');
    expect((await classify(new Response('{', { status: 409, headers: { 'content-type': 'application/problem+json' } }))).kind).toBe('server-error');
  });

  it('a refused message is the server sentence only; nothing else from the body is carried', async () => {
    const got = await classify(problem(409, { type: 'urn:erp:problem:business-rule-violated', rule: 'r.s', message: 'm', detail: 'internal', trace: 'x' }));
    expect(got).toEqual({ kind: 'refused', rule: 'r.s', message: 'm' });
  });

  // Revision 7 A-19 item 3: a 422 carries every well-formed entry of errors[], in order, as `violations`.
  it('a 422 keeps every string-only entry in order and drops a malformed one; the first entry still drives rule and message', async () => {
    const got = await classify(
      problem(422, {
        type: 'urn:erp:problem:validation-failed',
        errors: [
          { pointer: '/code', code: 'code_taken', message: 'That code is taken.' },
          { pointer: '/name', code: 42, message: 'not carried' },
          { pointer: '/name', code: 'name_blank', message: 'Enter a name.' },
          'not an object',
          null,
          { pointer: '', code: 'whole', message: 'Something about the whole form.' },
          { pointer: '/country_code', code: 'country_unknown' },
        ],
      }),
    );
    expect(got).toMatchObject({ kind: 'refused', rule: 'code_taken', message: 'That code is taken.' });
    expect(got.kind === 'refused' ? got.violations : 'none').toEqual([
      { pointer: '/code', code: 'code_taken', message: 'That code is taken.' },
      { pointer: '/name', code: 'name_blank', message: 'Enter a name.' },
      { pointer: '', code: 'whole', message: 'Something about the whole form.' },
    ]);
  });

  it('a 422 whose first entry is malformed: rule and message are still that raw entry\'s (D-1), and it is not a violation', async () => {
    const got = await classify(problem(422, { type: 'urn:erp:problem:validation-failed', errors: [
      { pointer: '/code', code: 42, message: 'First, malformed.' },
      { pointer: '/name', code: 'name_blank', message: 'Enter a name.' },
    ] }));
    expect(got).toEqual({ kind: 'refused', rule: '', message: 'First, malformed.', violations: [{ pointer: '/name', code: 'name_blank', message: 'Enter a name.' }] });
  });

  it('a 422 is projected exactly: nothing else from the body or an entry is carried (L-2, twin of the 409 case)', async () => {
    const got = await classify(
      problem(422, {
        type: 'urn:erp:problem:validation-failed',
        detail: 'internal',
        errors: [{ pointer: '/name', code: 'name_taken', message: 'That name is taken.', hint: 'x', payload: { a: 1 } }],
      }),
    );
    expect(got).toEqual({ kind: 'refused', rule: 'name_taken', message: 'That name is taken.', violations: [{ pointer: '/name', code: 'name_taken', message: 'That name is taken.' }] });
  });

  it('a 422 with no errors carries an empty violations list; a 409 refused carries none', async () => {
    expect(await classify(problem(422, { type: 'urn:erp:problem:validation-failed' }))).toEqual({ kind: 'refused', rule: '', message: '', violations: [] });
    const c409 = await classify(problem(409, { type: 'urn:erp:problem:business-rule-violated', rule: 'r.s', message: 'm', errors: [{ pointer: '/x', code: 'c', message: 'm' }] }));
    expect(c409).toEqual({ kind: 'refused', rule: 'r.s', message: 'm' });
    expect('violations' in c409).toBe(false);
  });
});

type FetchCall = [string, RequestInit];

function deps(over: Partial<CallerDeps> = {}) {
  const d: CallerDeps & { navigate: ReturnType<typeof vi.fn>; reload: ReturnType<typeof vi.fn>; onFixedError: ReturnType<typeof vi.fn> } = {
    sid: 'sid-1',
    navigate: vi.fn(),
    reload: vi.fn(),
    onFixedError: vi.fn(),
    here: () => '/admin/users?q=a',
    storage: new Map<string, string>(),
    development: true,
    ...over,
  } as never;
  return d;
}

function stubFetch(...answers: (() => Response)[]) {
  const calls: FetchCall[] = [];
  let i = 0;
  vi.stubGlobal(
    'fetch',
    vi.fn(async (u: string, init: RequestInit) => {
      calls.push([u, init]);
      return answers[Math.min(i++, answers.length - 1)]!();
    }),
  );
  return calls;
}

describe('callApi — T-AU, T-I1 and the session handling', () => {
  it('sends X-ERP-Session and JSON on every call, the body only when there is one, and resolves ok with the parsed body', async () => {
    const calls = stubFetch(() => Response.json({ id: ID }));
    const call = createApiCaller(deps());
    const r = await call.operation(op(), { id: ID });
    expect(r).toEqual({ kind: 'ok', status: 200, body: { id: ID } });
    const [url, init] = calls[0]!;
    expect(url).toBe(`/bff/api/users/${ID}`);
    const h = new Headers(init.headers);
    expect(h.get('x-erp-session')).toBe('sid-1');
    expect(h.get('accept')).toBe('application/json');
    expect(init.body).toBeUndefined();
    expect(init.method).toBe('GET');
  });

  it('a write sends application/json; a 204 resolves ok with no body', async () => {
    const calls = stubFetch(() => new Response(null, { status: 204 }));
    const call = createApiCaller(deps());
    const r = await call.operation(op({ method: 'DELETE' }), { id: ID });
    expect(r).toEqual({ kind: 'ok', status: 204, body: null });
    expect(new Headers(calls[0]![1].headers).get('content-type')).toBe('application/json');
  });

  it('T-I1: the Idempotency-Key header is present exactly when the operation requires it', async () => {
    const calls = stubFetch(() => Response.json({}, { status: 201 }));
    const call = createApiCaller(deps());
    await call.operation(op({ method: 'POST', idempotencyKeyRequired: true }), { id: ID }, { role_id: ID }, { idempotencyKey: 'k-1' });
    expect(new Headers(calls[0]![1].headers).get('idempotency-key')).toBe('k-1');
    expect(calls[0]![1].body).toBe(JSON.stringify({ role_id: ID }));
    await call.operation(op({ method: 'POST' }), { id: ID }, {}, { idempotencyKey: 'k-2' });
    expect(new Headers(calls[1]![1].headers).has('idempotency-key')).toBe(false);
  });

  it('T-I1: a required key that is missing throws in development, and in production sends nothing and resolves server-error', async () => {
    const calls = stubFetch(() => Response.json({}));
    await expect(createApiCaller(deps()).operation(op({ method: 'POST', idempotencyKeyRequired: true }), { id: ID }, {})).rejects.toThrow(/Idempotency-Key/);
    const prod = createApiCaller(deps({ development: false }));
    expect(await prod.operation(op({ method: 'POST', idempotencyKeyRequired: true }), { id: ID }, {})).toEqual({ kind: 'server-error' });
    expect(calls).toHaveLength(0);
  });

  it('an unreachable server resolves unreachable', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => Promise.reject(new TypeError('x'))));
    expect(await createApiCaller(deps()).operation(op(), { id: ID })).toEqual({ kind: 'unreachable' });
  });

  it('401 session-ended: navigates once to /login with the reason and the current path, then resolves session-ended', async () => {
    stubFetch(() => problem(401, { type: 'urn:erp:bff:session-ended' }));
    const d = deps();
    const call = createApiCaller(d);
    const [a, b] = await Promise.all([call.operation(op(), { id: ID }), call.operation(op(), { id: ID })]);
    expect(a).toEqual({ kind: 'session-ended' });
    expect(b).toEqual({ kind: 'session-ended' });
    expect(d.navigate).toHaveBeenCalledTimes(1);
    expect(d.navigate).toHaveBeenCalledWith('/login?reason=session_ended&return_to=%2Fadmin%2Fusers%3Fq%3Da');
  });

  it('a dev console error accompanies a server-error that means a programming fault (not-routable, request-refused, 428)', async () => {
    const err = vi.spyOn(console, 'error').mockImplementation(() => {});
    stubFetch(() => problem(404, { type: 'urn:erp:bff:not-routable' }));
    await createApiCaller(deps()).operation(op(), { id: ID });
    expect(err).toHaveBeenCalledTimes(1);
    err.mockClear();
    stubFetch(() => problem(404, { type: 'urn:erp:bff:not-routable' }));
    await createApiCaller(deps({ development: false })).operation(op(), { id: ID });
    expect(err).not.toHaveBeenCalled();
    err.mockRestore();
  });
});

describe('T-SC — session-changed: reload once, stay silent while it is pending, then the fixed error', () => {
  const changed = () => problem(409, { type: 'urn:erp:bff:session-changed' });

  it('two calls in flight both get 409 → exactly one reload, no fixed error, both resolve session-ended', async () => {
    stubFetch(changed);
    const d = deps();
    const call = createApiCaller(d);
    const out = await Promise.all([call.operation(op(), { id: ID }), call.operation(op(), { id: ID })]);
    expect(out).toEqual([{ kind: 'session-ended' }, { kind: 'session-ended' }]);
    expect(d.reload).toHaveBeenCalledTimes(1);
    expect(d.onFixedError).not.toHaveBeenCalled();
  });

  it('while the reload is pending, every later call resolves session-ended silently and sends nothing', async () => {
    const calls = stubFetch(changed, () => Response.json({}));
    const d = deps();
    const call = createApiCaller(d);
    await call.operation(op(), { id: ID });
    expect(await call.operation(op(), { id: ID })).toEqual({ kind: 'session-ended' });
    expect(calls).toHaveLength(1);
    expect(d.onFixedError).not.toHaveBeenCalled();
  });

  it('a 409 in the new document after that reload → the fixed error, no second reload', async () => {
    stubFetch(changed);
    const storage = new Map<string, string>();
    const first = deps({ storage });
    await createApiCaller(first).operation(op(), { id: ID });
    expect(first.reload).toHaveBeenCalledTimes(1);
    const second = deps({ storage, sid: 'sid-2' });
    expect(await createApiCaller(second).operation(op(), { id: ID })).toEqual({ kind: 'session-ended' });
    expect(second.reload).not.toHaveBeenCalled();
    expect(second.onFixedError).toHaveBeenCalledTimes(1);
  });

  it('a successful call in the new document clears the marker, so a later change reloads again', async () => {
    const storage = new Map<string, string>();
    stubFetch(changed);
    await createApiCaller(deps({ storage })).operation(op(), { id: ID });
    stubFetch(() => Response.json({}), changed);
    const next = deps({ storage, sid: 'sid-2' });
    const c = createApiCaller(next);
    await c.operation(op(), { id: ID });
    await c.operation(op(), { id: ID });
    expect(next.reload).toHaveBeenCalledTimes(1);
    expect(next.onFixedError).not.toHaveBeenCalled();
  });
});

describe('T-R2 — deadline.ts', () => {
  it('names each per-action bound once', () => {
    expect(ACTION_DEADLINES_MS).toEqual({ personAction: 30_000, proposalAction: 30_000, checklistSave: 30_000, roleRestore: 30_000, saveRoles: 60_000, bulkRun: 30_000, reread: 30_000 });
  });

  it('a deadline aborts its signal at its bound, not before; cancel clears it', async () => {
    vi.useFakeTimers();
    const d = startDeadline(ACTION_DEADLINES_MS.saveRoles);
    await vi.advanceTimersByTimeAsync(59_999);
    expect(d.signal.aborted).toBe(false);
    await vi.advanceTimersByTimeAsync(1);
    expect(d.signal.aborted).toBe(true);
    const e = startDeadline(1_000);
    e.cancel();
    await vi.advanceTimersByTimeAsync(5_000);
    expect(e.signal.aborted).toBe(false);
  });

  it('a call made under a deadline that passes resolves unreachable at the deadline (the client cannot tell whether it committed)', async () => {
    vi.useFakeTimers();
    vi.stubGlobal(
      'fetch',
      vi.fn((_u: string, init: RequestInit) => new Promise((_, reject) => init.signal!.addEventListener('abort', () => reject(new DOMException('a', 'AbortError'))))),
    );
    const d = startDeadline(ACTION_DEADLINES_MS.personAction);
    const p = createApiCaller(deps()).operation(op({ method: 'POST' }), { id: ID }, {}, { signal: d.signal });
    let out: unknown = null;
    void p.then((o) => (out = o));
    await vi.advanceTimersByTimeAsync(ACTION_DEADLINES_MS.personAction);
    expect(out).toEqual({ kind: 'unreachable' });
  });
});
