import { afterEach, describe, expect, it, vi } from 'vitest';
import { ACTION_DEADLINES_MS } from '@/lib/bff/deadline';
import { readOf } from '@/lib/bff/reads';
import { operation } from '@/lib/bff/surface';
import { loadRolePage, loadRoles } from '@/lib/permissions/load';
import { roleSave } from '@/lib/permissions/wired';
import { ROLE_A, json, later, page, problem, refused, role, stubFetch, testCaller } from './harness';

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
