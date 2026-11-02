import { afterEach, describe, expect, it, vi } from 'vitest';
import { ACTION_DEADLINES_MS } from '@/lib/bff/deadline';
import { operation } from '@/lib/bff/surface';
import type { RereadResult } from '@/lib/roles/results';
import { runSave } from '@/lib/roles/save';
import { pickerActions, proposalActions, type RereadContext } from '@/lib/roles/wired';
import { ID, PROPOSAL_1, ROLE_A, ROLE_B, empty, json, later, problem, proposal, refused, role, stubFetch, testCaller } from './harness';

// b3b-design.md §9.2, §9.3 (the requests), §7 rule I-1 + T-I1 (the key header per wired write), §6.2 R-2 item 2 + final
// RED M-1 (T-R2: the 60 s / 30 s action deadlines, shared with the next re-read, standalone otherwise).

afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

/**
 * The page's re-read, as the container makes it: several GETs under the one given signal (here three), stopping at the
 * first failure; records the context it was given.
 */
function pageReread(caller = testCaller()) {
  const contexts: RereadContext[] = [];
  const reread = async (signal: AbortSignal, context: RereadContext): Promise<RereadResult> => {
    contexts.push(context);
    for (let i = 0; i < 3; i += 1) {
      const r = await caller.call('GetUser', { id: ID }, undefined, { signal });
      if (r.kind !== 'ok') return { kind: 'failed', failure: r.kind === 'unreachable' ? 'unreachable' : 'server-error' };
    }
    return { kind: 'ok' };
  };
  return { caller, reread, contexts };
}

const picker = () => {
  const p = pageReread();
  return { ...p, actions: pickerActions({ caller: p.caller, personId: ID, reread: p.reread }) };
};
const group = () => {
  const p = pageReread();
  const pages: string[] = [];
  return { ...p, pages, actions: proposalActions({ caller: p.caller, personId: ID, reread: p.reread, onPage: async (d, c) => void pages.push(`${d}:${c}`) }) };
};

describe('the picker writes (§9.2)', () => {
  it('give: POST …/users/{id}/roles {role_id}, a NEW Idempotency-Key per call; 201 → given with the served role', async () => {
    const calls = stubFetch([json(201, role(ROLE_A, 'Buyer')), json(201, role(ROLE_B, 'Finance'))]);
    const { actions } = picker();
    expect(await actions.assign(ROLE_A)).toEqual({ kind: 'given', role: role(ROLE_A, 'Buyer') });
    await actions.assign(ROLE_B);
    expect(calls.map((c) => `${c.method} ${c.url} ${c.body}`)).toEqual([
      `POST /bff/api/users/${ID}/roles {"role_id":"${ROLE_A}"}`,
      `POST /bff/api/users/${ID}/roles {"role_id":"${ROLE_B}"}`,
    ]);
    const keys = calls.map((c) => c.headers.get('idempotency-key'));
    expect(keys[0]).toMatch(/^[0-9a-f-]{36}$/);
    expect(keys[0]).not.toBe(keys[1]);
  });

  it('propose: POST …/role-grant-proposals {role_id} with a key; 201 → proposed; a refusal is the server sentence', async () => {
    const calls = stubFetch([json(201, proposal(PROPOSAL_1, ROLE_A, 'Buyer')), refused('role_grant_proposal.already_pending', 'Already waiting.')]);
    const { actions } = picker();
    expect((await actions.propose(ROLE_A)).kind).toBe('proposed');
    expect(await actions.propose(ROLE_A)).toEqual({ kind: 'refused', rule: 'role_grant_proposal.already_pending', message: 'Already waiting.' });
    expect(calls[0]!.url).toBe(`/bff/api/users/${ID}/role-grant-proposals`);
    expect(calls[0]!.headers.has('idempotency-key')).toBe(true);
  });

  it('take away: DELETE …/users/{id}/roles/{roleId}, no key; 204 → taken-away; 404 → not-found', async () => {
    const calls = stubFetch([empty(204), problem(404, 'not-found')]);
    const { actions } = picker();
    expect(await actions.revoke(ROLE_A)).toEqual({ kind: 'taken-away' });
    expect(await actions.revoke(ROLE_A)).toEqual({ kind: 'not-found' });
    expect(calls[0]!.method).toBe('DELETE');
    expect(calls[0]!.url).toBe(`/bff/api/users/${ID}/roles/${ROLE_A}`);
    expect(calls[0]!.headers.has('idempotency-key')).toBe(false);
  });
});

describe('the Waiting group writes (§9.3)', () => {
  it('Confirm / Reject / Withdraw: POST …/confirmation (no body), …/rejection ({}), …/cancellation (no body)', async () => {
    const p = proposal(PROPOSAL_1, ROLE_A, 'Buyer');
    const calls = stubFetch([json(200, { ...p, status: 'confirmed' }), json(200, { ...p, status: 'rejected' }), json(200, { ...p, status: 'cancelled' })]);
    const { actions } = group();
    expect((await actions.confirm(PROPOSAL_1)).kind).toBe('confirmed');
    expect((await actions.reject(PROPOSAL_1)).kind).toBe('rejected');
    expect((await actions.withdraw(PROPOSAL_1)).kind).toBe('withdrawn');
    expect(calls.map((c) => `${c.method} ${c.url} ${c.body}`)).toEqual([
      `POST /bff/api/role-grant-proposals/${PROPOSAL_1}/confirmation null`,
      `POST /bff/api/role-grant-proposals/${PROPOSAL_1}/rejection {}`,
      `POST /bff/api/role-grant-proposals/${PROPOSAL_1}/cancellation null`,
    ]);
  });

  it('Older / Newer hand the cursor to the page', () => {
    const g = group();
    g.actions.onPage('after', 'c1');
    expect(g.pages).toEqual(['after:c1']);
  });
});

describe('T-I1 — the key header is sent exactly when each wired write requires it', () => {
  const p = proposal(PROPOSAL_1, ROLE_A, 'Buyer');
  it.each([
    ['AssignRole', () => picker().actions.assign(ROLE_A), json(201, role(ROLE_A, 'Buyer'))],
    ['CreateRoleGrantProposal', () => picker().actions.propose(ROLE_A), json(201, p)],
    ['RevokeRole', () => picker().actions.revoke(ROLE_A), empty(204)],
    ['ConfirmRoleGrantProposal', () => group().actions.confirm(PROPOSAL_1), json(200, p)],
    ['RejectRoleGrantProposal', () => group().actions.reject(PROPOSAL_1), json(200, p)],
    ['CancelRoleGrantProposal', () => group().actions.withdraw(PROPOSAL_1), json(200, p)],
  ] as const)('%s', async (opId, run, reply) => {
    const calls = stubFetch([reply]);
    await run();
    expect(calls[0]!.headers.has('idempotency-key')).toBe(operation(opId).idempotencyKeyRequired);
  });
});

describe('the re-read context (what the page heads its banner with when it takes over, §10)', () => {
  it('after a save: how many sent requests did not answer 2xx, and how many did (FE-R1); a standalone re-read: none', async () => {
    stubFetch([refused('user_role.separation_of_duty', 'x'), json(201, role(ROLE_B, 'Finance')), ...Array.from({ length: 6 }, () => json(200, {}))]);
    const p = picker();
    await p.actions.assign(ROLE_A);
    await p.actions.assign(ROLE_B);
    await p.actions.reread();
    await p.actions.reread();
    expect(p.contexts).toEqual([{ kind: 'save', notDone: 1, done: 1 }, { kind: 'none' }]);
  });

  it('after a group action: which, and whether it succeeded', async () => {
    stubFetch([refused('role_grant_proposal.decider_not_owner', 'x'), json(200, {}), json(200, {}), json(200, {})]);
    const g = group();
    await g.actions.confirm(PROPOSAL_1);
    await g.actions.reread();
    expect(g.contexts).toEqual([{ kind: 'proposal', act: 'confirm', ok: false }]);
  });
});

// A single request always ends by the 20 s per-request limit (unreachable), inside every action bound. So each case
// builds the time out of several answers, each under 20 s, until the ACTION deadline is the limit that ends it
// (deviation D-24). `later(19_000, …)` is the slowest answer a request can still give.
const ok19 = () => later(19_000, () => json(200, {}));
describe('T-R2 — the action deadlines (§6.2 item 2, final RED M-1)', () => {
  it('Save roles, 5 requests, three answered at 19 s each, the fourth hanging → at 60 s it is unreachable, the fifth not-sent, and the re-read fails (R1-2 case C)', async () => {
    vi.useFakeTimers();
    stubFetch([later(19_000, () => json(201, role(ROLE_A, 'A'))), later(19_000, () => json(201, role(ROLE_A, 'A'))), later(19_000, () => json(201, role(ROLE_A, 'A'))), 'hang']);
    const p = picker();
    const plan = ['r1', 'r2', 'r3', 'r4', 'r5'].map((r) => ({ roleId: ROLE_A, roleName: r, kind: 'give' as const }));
    let run: Awaited<ReturnType<typeof runSave>> | null = null;
    let reread: RereadResult | null = null;
    void runSave(plan, p.actions).then(async (r) => {
      run = r;
      reread = await p.actions.reread();
    });
    await vi.advanceTimersByTimeAsync(ACTION_DEADLINES_MS.saveRoles - 1);
    expect(run).toBeNull();
    await vi.advanceTimersByTimeAsync(1);
    expect(run!.outcomes.map((o) => o.result.kind)).toEqual(['given', 'given', 'given', 'unreachable', 'not-sent']);
    expect(reread).toEqual({ kind: 'failed', failure: 'unreachable' });
  });

  it("shared: a give answered at 19 s, then a re-read whose reads answer at 38 s and 57 s and then hang → it ends at the save's 60 s (a fresh 30 s would end at 49 s)", async () => {
    vi.useFakeTimers();
    stubFetch([later(19_000, () => json(201, role(ROLE_A, 'A'))), ok19(), ok19(), 'hang', later(15_000, () => json(200, {})), 'hang']);
    const p = picker();
    let reread: RereadResult | null = null;
    void p.actions.assign(ROLE_A).then(async () => (reread = await p.actions.reread()));
    await vi.advanceTimersByTimeAsync(59_999);
    expect(reread).toBeNull();
    await vi.advanceTimersByTimeAsync(1);
    expect(reread).toEqual({ kind: 'failed', failure: 'unreachable' });
    // Standalone afterwards (Load roles again): its own 30 s — its first read answers at 15 s, the next hangs.
    let again: RereadResult | null = null;
    void p.actions.reread().then((r) => (again = r));
    await vi.advanceTimersByTimeAsync(ACTION_DEADLINES_MS.reread - 1);
    expect(again).toBeNull();
    await vi.advanceTimersByTimeAsync(1);
    expect(again).toEqual({ kind: 'failed', failure: 'unreachable' });
  });

  it('Confirm answered at 19 s, the re-read answers once at 28 s then hangs → done but stale at 30 s (a fresh 30 s would end at 49 s)', async () => {
    vi.useFakeTimers();
    stubFetch([later(19_000, () => json(200, proposal(PROPOSAL_1, ROLE_A, 'A'))), later(9_000, () => json(200, {})), 'hang', later(15_000, () => json(200, {})), 'hang']);
    const g = group();
    let confirmed: string | null = null;
    let reread: RereadResult | null = null;
    void g.actions.confirm(PROPOSAL_1).then(async (r) => {
      confirmed = r.kind;
      reread = await g.actions.reread();
    });
    await vi.advanceTimersByTimeAsync(29_999);
    expect(confirmed).toBe('confirmed');
    expect(reread).toBeNull();
    await vi.advanceTimersByTimeAsync(1);
    expect(reread).toEqual({ kind: 'failed', failure: 'unreachable' });
    let again: RereadResult | null = null;
    void g.actions.reread().then((r) => (again = r));
    await vi.advanceTimersByTimeAsync(ACTION_DEADLINES_MS.reread - 1);
    expect(again).toBeNull();
    await vi.advanceTimersByTimeAsync(1);
    expect(again).toEqual({ kind: 'failed', failure: 'unreachable' });
  });

  it('Load roles again: its first read answers at 15 s, the next hangs → failed at 30 s (the 20 s limit would end it at 35 s)', async () => {
    vi.useFakeTimers();
    stubFetch([later(15_000, () => json(200, {})), 'hang']);
    let r: RereadResult | null = null;
    void picker().actions.reread().then((x) => (r = x));
    await vi.advanceTimersByTimeAsync(ACTION_DEADLINES_MS.reread - 1);
    expect(r).toBeNull();
    await vi.advanceTimersByTimeAsync(1);
    expect(r).toEqual({ kind: 'failed', failure: 'unreachable' });
  });
});
