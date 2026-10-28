import { afterEach, describe, expect, it, vi } from 'vitest';
import { readOf } from '@/lib/bff/reads';
import { loadPersonView } from '@/lib/people/load';
import { PENDING_WALK_MAX_PAGES, loadRoleAccess } from '@/lib/roles/load';
import { ID, PROPOSAL_1, ROLE_A, ROLE_B, json, offered, page, problem, proposal, stubFetch, testCaller, user } from './harness';

// The role reads (b3b-design.md §9.2, §10; A-1): the WHOLE pending walk through next_cursor, never a partial set; at
// most 50 pages; and the per-read table's 403 handling in loadPersonView.

afterEach(() => vi.unstubAllGlobals());

const P2 = '5a216e4d-8f9e-4b3d-8c7a-0fb3c4d56e7f';

describe('loadRoleAccess — the pending walk', () => {
  it('follows next_cursor to the end; pending is every page, the shown page is the first', async () => {
    const first = page([proposal(PROPOSAL_1, ROLE_A, 'Buyer')], { next_cursor: 'c2' });
    const calls = stubFetch([json(200, page([offered(ROLE_A, 'Buyer')])), json(200, first), json(200, page([proposal(P2, ROLE_B, 'Finance')]))]);
    const r = await loadRoleAccess(readOf(testCaller()), ID);
    expect(r.kind).toBe('ok');
    if (r.kind !== 'ok') return;
    expect(r.value.pending.map((p) => p.role.id)).toEqual([ROLE_A, ROLE_B]);
    expect(r.value.page.items).toHaveLength(1);
    expect(calls[2]!.url).toBe(`/bff/api/role-grant-proposals?user=${ID}&status=pending&after=c2`);
  });

  it('a failed later page fails the whole read (never a partial set)', async () => {
    stubFetch([json(200, page([])), json(200, page([], { next_cursor: 'c2' })), json(500, {})]);
    expect(await loadRoleAccess(readOf(testCaller()), ID)).toEqual({ kind: 'server-error' });
  });

  it(`stops at ${PENDING_WALK_MAX_PAGES} pages: past that the read fails`, async () => {
    const replies = [json(200, page([])), ...Array.from({ length: PENDING_WALK_MAX_PAGES + 1 }, (_, i) => json(200, page([], { next_cursor: `c${i}` })))];
    const calls = stubFetch(replies);
    expect(await loadRoleAccess(readOf(testCaller()), ID)).toEqual({ kind: 'server-error' });
    expect(calls).toHaveLength(1 + PENDING_WALK_MAX_PAGES);
  });
});

describe('loadPersonView — the per-read table', () => {
  it('reads nothing the gates do not allow (M-4)', async () => {
    const calls = stubFetch([json(200, user())]);
    expect((await loadPersonView(readOf(testCaller()), ID, { assign: false, view: false })).kind).toBe('ok');
    expect(calls).toHaveLength(1);
  });

  it('a 403 on a role read is not a failure: that part is absent and `lost` names it; a 404 anywhere is not-found', async () => {
    stubFetch([json(200, user()), problem(403, 'permission-denied'), problem(403, 'permission-denied')]);
    const r = await loadPersonView(readOf(testCaller()), ID, { assign: true, view: true });
    expect(r).toEqual({ kind: 'ok', view: { person: user(), access: null, catalogue: null }, lost: { assign: true, view: true } });
    stubFetch([json(200, user()), problem(404, 'not-found')]);
    expect(await loadPersonView(readOf(testCaller()), ID, { assign: true, view: false })).toEqual({ kind: 'not-found' });
  });
});
