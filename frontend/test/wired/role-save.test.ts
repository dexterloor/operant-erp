import { afterEach, describe, expect, it, vi } from 'vitest';
import { ACTION_DEADLINES_MS } from '@/lib/bff/deadline';
import { readOf } from '@/lib/bff/reads';
import { operation } from '@/lib/bff/surface';
import { loadRolePage, loadRoles } from '@/lib/permissions/load';
import { roleRestore, roleSave } from '@/lib/permissions/wired';
import { createApiCaller } from '@/lib/bff/apiCall';
import { ROLE_A, SID, json, later, page, problem, refused, role, stubFetch, testCaller } from './harness';

// b3b-design.md §9.4 (UpdateRole: PATCH, `{permission_codes: [...sorted]}`, 200 → saved, then GetRole re-read without a
// remount), §6.2 (Checklist save: 30 s for request + re-read), T-I1, and the role loaders (§10).

afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

const PERM = { code: 'role.view', module: 'Modules\\UserManagement', description: 'role: view', sod_rows: [] };

const wiring = () => {
  const order: string[] = [];
  const setRole = vi.fn(async (r: { permission_codes: string[] }) => {
    order.push(`set:${r.permission_codes.join(',')}`);
    await new Promise((res) => setTimeout(res, 5));
    order.push('committed');
  });
  const onRereadFailed = vi.fn((f: string) => order.push(`failed:${f}`));
  return { order, setRole, onRereadFailed, save: roleSave({ caller: testCaller(), id: ROLE_A, setRole, onRereadFailed }) };
};

describe('loadRoles / loadRolePage', () => {
  it('ListRoles live and ?archived=true; GetRole + ListPermissions for a role page', async () => {
    const calls = stubFetch([json(200, page([role(ROLE_A, 'Buyer')])), json(200, page([])), json(200, role(ROLE_A, 'Buyer')), json(200, page([PERM]))]);
    const read = readOf(testCaller());
    expect(await loadRoles(read, false)).toEqual({ kind: 'ok', roles: [role(ROLE_A, 'Buyer')] });
    expect(await loadRoles(read, true)).toEqual({ kind: 'ok', roles: [] });
    expect(await loadRolePage(read, ROLE_A)).toEqual({ kind: 'ok', role: role(ROLE_A, 'Buyer'), permissions: [PERM] });
    expect(calls.map((c) => c.url)).toEqual(['/bff/api/roles', '/bff/api/roles?archived=true', `/bff/api/roles/${ROLE_A}`, '/bff/api/permissions']);
  });

  it('GetRole 403 / 404 pass through; a non-role body is server-error', async () => {
    stubFetch([problem(403, 'permission-denied'), problem(404, 'not-found'), json(200, { nope: 1 })]);
    const read = readOf(testCaller());
    expect(await loadRolePage(read, ROLE_A)).toEqual({ kind: 'forbidden' });
    expect(await loadRolePage(read, ROLE_A)).toEqual({ kind: 'not-found' });
    expect(await loadRolePage(read, ROLE_A)).toEqual({ kind: 'server-error' });
  });
});

describe('Save changes (UpdateRole) — §9.4', () => {
  it('PATCH /roles/{id} with the codes SORTED, application/json, no key; then GetRole; set (committed) before saved resolves', async () => {
    const saved = role(ROLE_A, 'Buyer', ['a.b', 'c.d']);
    const calls = stubFetch([json(200, saved), json(200, saved)]);
    const w = wiring();
    const r = await w.save(['c.d', 'a.b']);
    w.order.push('resolved');
    expect(r).toEqual({ kind: 'saved', role: saved });
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([`PATCH /bff/api/roles/${ROLE_A}`, `GET /bff/api/roles/${ROLE_A}`]);
    expect(calls[0]!.body).toBe('{"permission_codes":["a.b","c.d"]}');
    expect(calls[0]!.headers.get('content-type')).toBe('application/json');
    expect(calls[0]!.headers.has('idempotency-key')).toBe(operation('UpdateRole').idempotencyKeyRequired);
    expect(w.order).toEqual(['set:a.b,c.d', 'committed', 'resolved']);
  });

  it('a refusal is the server sentence; nothing is re-read', async () => {
    const calls = stubFetch([refused('role.missing_companion_view', 'It needs its view permission too.')]);
    const w = wiring();
    expect(await w.save(['a.b'])).toEqual({ kind: 'refused', rule: 'role.missing_companion_view', message: 'It needs its view permission too.' });
    expect(calls).toHaveLength(1);
  });

  it('200 but the re-read fails: still saved (from the 200), and the page is told (its region error)', async () => {
    stubFetch([json(200, role(ROLE_A, 'Buyer', ['a.b'])), json(500, {})]);
    const w = wiring();
    expect((await w.save(['a.b'])).kind).toBe('saved');
    expect(w.order).toEqual(['failed:server-error']);
  });

  it('T-R2: the save answers at 19 s and the re-read answers at 29 s, then nothing more is waited for: one 30 s bound covers both', async () => {
    vi.useFakeTimers();
    stubFetch([later(19_000, () => json(200, role(ROLE_A, 'Buyer'))), later(12_000, () => json(200, role(ROLE_A, 'Buyer')))]);
    const w = wiring();
    let r: unknown = null;
    void w.save([]).then((x) => (r = x));
    await vi.advanceTimersByTimeAsync(ACTION_DEADLINES_MS.checklistSave - 1);
    expect(r).toBeNull();
    await vi.advanceTimersByTimeAsync(1);
    expect(r).toEqual({ kind: 'saved', role: role(ROLE_A, 'Buyer') });
    expect(w.order).toEqual(['failed:unreachable']);
  });
});

describe('Restore role (RestoreRole) — restore-role-design.md Revision 2 F-2 "The wired action"', () => {
  const DELETED = { ...role(ROLE_A, 'Buyer'), archived_at: '2026-09-02T10:00:00Z' };
  const LIVE = role(ROLE_A, 'Buyer');
  const restoring = () => {
    const w = wiring();
    return { ...w, restore: roleRestore({ caller: testCaller(), id: ROLE_A, setRole: w.setRole, onRereadFailed: w.onRereadFailed }) };
  };

  it('POST …/restoration, no body, no key; the 200 role is set (committed) before restored resolves', async () => {
    const calls = stubFetch([json(200, LIVE)]);
    const w = restoring();
    const r = await w.restore();
    w.order.push('resolved');
    expect(r).toEqual({ kind: 'restored', role: LIVE, already: false });
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([`POST /bff/api/roles/${ROLE_A}/restoration`]);
    expect(calls[0]!.body).toBeNull();
    expect(calls[0]!.headers.has('idempotency-key')).toBe(operation('RestoreRole').idempotencyKeyRequired);
    expect(w.order).toEqual(['set:', 'committed', 'resolved']);
  });

  it('409 is_not_archived → GetRole: live → already; deleted again → refused with no message; failure → page-took-over', async () => {
    stubFetch([refused('role.is_not_archived', 'X-planted sentence'), json(200, LIVE)]);
    expect(await restoring().restore()).toEqual({ kind: 'restored', role: LIVE, already: true });
    stubFetch([refused('role.is_not_archived', 'X-planted sentence'), json(200, DELETED)]);
    expect(await restoring().restore()).toEqual({ kind: 'refused', rule: 'role.is_not_archived', message: '' });
    stubFetch([refused('role.is_not_archived', 'X-planted sentence'), json(500, {})]);
    const w = restoring();
    expect(await w.restore()).toEqual({ kind: 'page-took-over' });
    expect(w.order).toEqual(['failed:server-error']);
  });

  it('any other refusal passes through, never re-read', async () => {
    const calls = stubFetch([refused('role.name_taken', 'X-planted name sentence')]);
    expect(await restoring().restore()).toEqual({ kind: 'refused', rule: 'role.name_taken', message: 'X-planted name sentence' });
    expect(calls).toHaveLength(1);
  });

  it('a 200 whose body is not a role → server-error, nothing set', async () => {
    stubFetch([json(200, { nope: 1 })]);
    const w = restoring();
    expect(await w.restore()).toEqual({ kind: 'server-error' });
    expect(w.order).toEqual([]);
  });

  it('T-15: a 429 and an expired key cannot come from RestoreRole → server-error', async () => {
    stubFetch([problem(429, 'rate-limited')]);
    expect(await restoring().restore()).toEqual({ kind: 'server-error' });
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '#Idempotency-Key', code: 'key_expired', message: 'x' }] })]);
    expect(await restoring().restore()).toEqual({ kind: 'server-error' });
  });

  it('a 401 (session ended) → session-ended, and the caller navigates to sign-in once (T-13, the transport half)', async () => {
    stubFetch([new Response(JSON.stringify({ type: 'urn:erp:bff:session-ended', title: 'x', status: 401 }), { status: 401, headers: { 'content-type': 'application/problem+json' } })]);
    const navigate = vi.fn();
    const caller = createApiCaller({ sid: SID, navigate, reload: vi.fn(), onFixedError: vi.fn(), here: () => `/admin/roles/${ROLE_A}`, storage: { get: () => null, set: () => {}, delete: () => {} }, development: true });
    const w = wiring();
    expect(await roleRestore({ caller, id: ROLE_A, setRole: w.setRole, onRereadFailed: w.onRereadFailed })()).toEqual({ kind: 'session-ended' });
    expect(navigate).toHaveBeenCalledTimes(1);
  });

  // T-10b. A lone request never reaches the 30 s action deadline (the transport's own 20 s limit passes first, as in
  // people-actions T-R2), so the deadline is proven where only it can end the wait: the POST answers at 19 s, the re-read
  // hangs, and at 30 s from the press the shared deadline ends it (without it, the re-read's own limit would end at 39 s).
  it('T-10b: one 30 s bound covers the request and the re-read', async () => {
    vi.useFakeTimers();
    stubFetch([later(19_000, () => refused('role.is_not_archived', 'X-planted sentence')), 'hang']);
    const w = restoring();
    let r: unknown = null;
    void w.restore().then((x) => (r = x));
    await vi.advanceTimersByTimeAsync(ACTION_DEADLINES_MS.roleRestore - 1);
    expect(r).toBeNull();
    await vi.advanceTimersByTimeAsync(1);
    expect(r).toEqual({ kind: 'page-took-over' });
    expect(w.order).toEqual(['failed:unreachable']);
  });

  it('a lone POST that never answers → unreachable (at the transport limit, inside the action deadline)', async () => {
    vi.useFakeTimers();
    stubFetch(['hang']);
    let r: unknown = null;
    void restoring().restore().then((x) => (r = x));
    await vi.advanceTimersByTimeAsync(ACTION_DEADLINES_MS.roleRestore);
    expect(r).toEqual({ kind: 'unreachable' });
  });
});
