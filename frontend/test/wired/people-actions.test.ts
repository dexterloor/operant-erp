import { afterEach, describe, expect, it, vi } from 'vitest';
import { loadPerson, loadUsers } from '@/lib/people/load';
import { personActions } from '@/lib/people/wired';
import { readOf } from '@/lib/bff/reads';
import { operation } from '@/lib/bff/surface';
import { ACTION_DEADLINES_MS } from '@/lib/bff/deadline';
import { ID, SID, empty, json, later, problem, refused, stubFetch, testCaller, user } from './harness';

// b3b-design.md §9.1 (Delete / Restore person), §10 (the re-read), §6.2 R-2 item 2 (T-R2: the 30 s action deadline),
// §7 rule I-1 (T-I1 per wired write). Every answer is shaped as the backend serves it.

afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

const wiring = () => {
  const order: string[] = [];
  // Resolves a little later, like a commit: the action must not resolve before it (the caller contract).
  const setPerson = vi.fn(async (p: unknown) => {
    order.push(`set:${(p as { archived_at: string | null }).archived_at ?? 'live'}`);
    await new Promise((r) => setTimeout(r, 5));
    order.push('committed');
  });
  const onRereadFailed = vi.fn((f: string) => order.push(`failed:${f}`));
  const actions = personActions({ caller: testCaller(), id: ID, setPerson, onRereadFailed });
  return { actions, setPerson, onRereadFailed, order };
};

describe('loadUsers / loadPerson — the pure loaders (§10)', () => {
  it('ListUsers: the live view by default, `archived=true` for the Archived view; the served items', async () => {
    const calls = stubFetch([json(200, { items: [user()], next_cursor: null, previous_cursor: null, tenant_has_rows: true }), json(200, { items: [], next_cursor: null, previous_cursor: null, tenant_has_rows: true })]);
    const read = readOf(testCaller());
    expect(await loadUsers(read, false)).toEqual({ kind: 'ok', users: [user()] });
    expect(await loadUsers(read, true)).toEqual({ kind: 'ok', users: [] });
    expect(calls.map((c) => c.url)).toEqual(['/bff/api/users', '/bff/api/users?archived=true']);
    expect(calls[0]!.headers.get('x-erp-session')).toBe(SID);
  });

  it('a body that is not a list is server-error; each failure kind passes through', async () => {
    stubFetch([json(200, { nope: true }), problem(403, 'permission-denied'), problem(404, 'not-found'), json(503, {})]);
    const read = readOf(testCaller());
    expect(await loadUsers(read, false)).toEqual({ kind: 'server-error' });
    expect(await loadPerson(read, ID)).toEqual({ kind: 'forbidden' });
    expect(await loadPerson(read, ID)).toEqual({ kind: 'not-found' });
    expect(await loadPerson(read, ID)).toEqual({ kind: 'server-error' });
  });
});

/**
 * The deadline T-R2 names (§6.2 R-2 item 2), typed here on purpose. Advancing the fake clock by
 * `ACTION_DEADLINES_MS.personAction` instead would move with the table, so a 20 s deadline would pass as well.
 */
const THIRTY_SECONDS = 30_000;
/** The per-request limit (§6.2 R-2 item 1), typed here for the same reason. */
const TWENTY_SECONDS = 20_000;

describe('Delete person (DeleteUser) — §9.1', () => {
  it('the configured Delete / Restore person deadline is the 30 s T-R2 names', () => {
    expect(ACTION_DEADLINES_MS.personAction).toBe(THIRTY_SECONDS);
  });

  it('204, then re-reads GetUser (a deleted person answers 200 with archived_at), sets it, THEN resolves deleted', async () => {
    const calls = stubFetch([empty(204), json(200, user({ archived_at: '2026-10-28T10:00:00Z' }))]);
    const w = wiring();
    const result = await w.actions.deletePerson();
    w.order.push('resolved');
    expect(result).toEqual({ kind: 'deleted' });
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([`DELETE /bff/api/users/${ID}`, `GET /bff/api/users/${ID}`]);
    expect(calls[0]!.headers.get('idempotency-key')).toBeNull();
    expect(calls[0]!.body).toBeNull();
    expect(w.order).toEqual(['set:2026-10-28T10:00:00Z', 'committed', 'resolved']);
  });

  it('a refusal is the server sentence, verbatim; nothing is re-read', async () => {
    const calls = stubFetch([refused('app_user.last_owner', 'They are the last owner.')]);
    const w = wiring();
    expect(await w.actions.deletePerson()).toEqual({ kind: 'refused', rule: 'app_user.last_owner', message: 'They are the last owner.' });
    expect(calls).toHaveLength(1);
    expect(w.setPerson).not.toHaveBeenCalled();
  });

  it.each([
    [problem(403, 'permission-denied'), 'forbidden'],
    [problem(404, 'not-found'), 'not-found'],
    [json(500, {}), 'server-error'],
    [problem(429, 'too-many-attempts'), 'server-error'],
  ] as const)('a failed request maps to its kind (%#)', async (reply, kind) => {
    stubFetch([reply]);
    expect((await wiring().actions.deletePerson()).kind).toBe(kind);
  });

  it('204 but the re-read fails: still deleted, the page is told the read failed and takes over (FE-R1); a 401 re-read does not take over', async () => {
    stubFetch([empty(204), json(500, {})]);
    const w = wiring();
    expect(await w.actions.deletePerson()).toEqual({ kind: 'deleted', pageTookOver: true });
    expect(w.order).toEqual(['failed:server-error']);
    for (const [reread, failure] of [
      [problem(403, 'permission-denied'), 'forbidden'],
      [problem(404, 'not-found'), 'not-found'],
    ] as const) {
      stubFetch([empty(204), reread]);
      const v = wiring();
      expect(await v.actions.deletePerson()).toEqual({ kind: 'deleted', pageTookOver: true });
      expect(v.order).toEqual([`failed:${failure}`]);
    }
    stubFetch([empty(204), new Response(JSON.stringify({ type: 'urn:erp:bff:session-ended', title: 'x', status: 401 }), { status: 401, headers: { 'content-type': 'application/problem+json' } })]);
    const v = wiring();
    expect(await v.actions.deletePerson()).toEqual({ kind: 'deleted' });
    expect(v.order).toEqual(['failed:session-ended']);
  });

  it('T-R2: the request answers (after 15 s) and the re-read hangs → the action resolves at 30 s, not at the re-read\'s own 20 s limit', async () => {
    vi.useFakeTimers();
    stubFetch([later(15_000, () => empty(204)), 'hang']);
    const w = wiring();
    let done: unknown = null;
    void w.actions.deletePerson().then((r) => (done = r));
    await vi.advanceTimersByTimeAsync(THIRTY_SECONDS - 1);
    expect(done).toBeNull();
    await vi.advanceTimersByTimeAsync(1);
    expect(done).toEqual({ kind: 'deleted', pageTookOver: true });
    expect(w.order).toEqual(['failed:unreachable']);
  });

  // A lone request never reaches the 30 s action deadline: the request's own 20 s limit (`transport.ts`) passes first
  // (b3b-design.md §14 T-R2, amended 2026-10-29, D-24). Measured 2026-10-30: this used to advance 30 s in one step
  // and so could not tell which bound ended it.
  it('T-R2: the request itself hangs → unreachable at the request\'s own 20 s limit, the first bound to pass (the dialog then says R1-3: we can\'t tell)', async () => {
    vi.useFakeTimers();
    stubFetch(['hang']);
    let done: unknown = null;
    void wiring().actions.deletePerson().then((r) => (done = r));
    await vi.advanceTimersByTimeAsync(TWENTY_SECONDS - 1);
    expect(done).toBeNull();
    await vi.advanceTimersByTimeAsync(1);
    expect(done).toEqual({ kind: 'unreachable' });
  });
});

describe('Restore person (RestoreUser) — §9.1', () => {
  it('POST …/restoration with no body; the served 200 person is set before restored resolves', async () => {
    const restored = user({ is_active: false });
    const calls = stubFetch([json(200, restored)]);
    const w = wiring();
    const result = await w.actions.restorePerson();
    w.order.push('resolved');
    expect(result).toEqual({ kind: 'restored', person: restored });
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([`POST /bff/api/users/${ID}/restoration`]);
    expect(calls[0]!.body).toBeNull();
    expect(w.order).toEqual(['set:live', 'committed', 'resolved']);
  });

  it('a refusal (app_user.email_taken) is the server sentence', async () => {
    stubFetch([refused('app_user.email_taken', 'Someone else now uses that email.')]);
    expect(await wiring().actions.restorePerson()).toEqual({ kind: 'refused', rule: 'app_user.email_taken', message: 'Someone else now uses that email.' });
  });

  it('a 200 that is not a person is server-error', async () => {
    stubFetch([json(200, { nope: 1 })]);
    expect(await wiring().actions.restorePerson()).toEqual({ kind: 'server-error' });
  });
});

describe('T-I1 — the key header is sent exactly when the wired write requires it', () => {
  it.each([
    ['DeleteUser', () => wiring().actions.deletePerson(), [empty(204), json(200, user())]],
    ['RestoreUser', () => wiring().actions.restorePerson(), [json(200, user())]],
  ] as const)('%s', async (opId, run, replies) => {
    const calls = stubFetch([...replies]);
    await run();
    expect(calls[0]!.headers.has('idempotency-key')).toBe(operation(opId).idempotencyKeyRequired);
  });
});
