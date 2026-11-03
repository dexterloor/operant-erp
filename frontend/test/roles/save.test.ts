import { describe, expect, it, vi } from 'vitest';
import { OFFERED_JONAS_BY_ADMIN, ROLE_ID, ROLES, SAVE_REFUSALS } from '@/components/preview/fixtures/roles';
import type { Intents } from '@/lib/roles/intents';
import { planSave, runSave, type SaveRequest } from '@/lib/roles/save';
import type { RolePickerActions } from '@/lib/roles/results';

const m = (entries: Array<[string, 'give' | 'take-away']>): Intents => new Map(entries.map(([n, i]) => [ROLE_ID[n]!, i]));
const nameOf = (id: string) => ROLES.find((r) => r.id === id)?.name ?? id;
const role = (n: string) => ROLES.find((r) => r.name === n)!;

describe('planSave — take-aways, then gives, then proposals (RED Q-2)', () => {
  it('orders by kind, then by the offered order, whatever order the intents were made in', () => {
    const plan = planSave(m([['finance', 'give'], ['viewer', 'give'], ['buyer', 'take-away'], ['admin', 'give']]), OFFERED_JONAS_BY_ADMIN, nameOf);
    expect(plan.map((r) => `${r.kind} ${r.roleName}`)).toEqual(['take-away buyer', 'give admin', 'give viewer', 'propose finance']);
  });
});

describe('runSave — sequential, stops on transport failure or 403 (Revision 3 A-4)', () => {
  it('Q-2: take-away of the blocking held role, then the give — revoke → assign, the second only after the first resolves', async () => {
    const log: string[] = [];
    let release!: () => void;
    const actions: Pick<RolePickerActions, 'assign' | 'propose' | 'revoke'> = {
      revoke: vi.fn(() => {
        log.push('revoke:start');
        return new Promise<{ kind: 'taken-away' }>((resolve) => {
          release = () => {
            log.push('revoke:end');
            resolve({ kind: 'taken-away' });
          };
        });
      }),
      assign: vi.fn(async () => {
        log.push('assign:start');
        return { kind: 'given', role: role('warehouse_operator') } as const;
      }),
      propose: vi.fn(),
    };
    const plan = planSave(m([['warehouse_operator', 'give'], ['buyer', 'take-away']]), OFFERED_JONAS_BY_ADMIN, nameOf);
    const run = runSave(plan, actions);
    await Promise.resolve();
    await Promise.resolve();
    expect(log).toEqual(['revoke:start']);
    release();
    const result = await run;
    expect(log).toEqual(['revoke:start', 'revoke:end', 'assign:start']);
    expect(result.stop).toBeNull();
    expect(result.outcomes.map((o) => o.result.kind)).toEqual(['taken-away', 'given']);
  });

  const plan3: SaveRequest[] = [
    { roleId: 'a', roleName: 'a', kind: 'take-away' },
    { roleId: 'b', roleName: 'b', kind: 'give' },
    { roleId: 'c', roleName: 'c', kind: 'propose' },
  ];

  it('a refusal (409 or 404) continues to the next request', async () => {
    const actions = { revoke: vi.fn(async () => ({ kind: 'not-found' }) as const), assign: vi.fn(async () => SAVE_REFUSALS.assignSod), propose: vi.fn(async () => ({ kind: 'unreachable' }) as const) };
    const r = await runSave(plan3, actions);
    expect(actions.propose).toHaveBeenCalledTimes(1);
    expect(r.stop).toBe('unreachable');
  });

  it.each(['unreachable', 'server-error', 'session-ended', 'forbidden'] as const)('%s stops: no further action is called, the rest are not-sent', async (kind) => {
    const actions = { revoke: vi.fn(async () => ({ kind }) as never), assign: vi.fn(), propose: vi.fn() };
    const r = await runSave(plan3, actions);
    expect(actions.assign).not.toHaveBeenCalled();
    expect(actions.propose).not.toHaveBeenCalled();
    expect(r.stop).toBe(kind);
    expect(r.outcomes.map((o) => o.result.kind)).toEqual([kind, 'not-sent', 'not-sent']);
  });

  it('a thrown action counts as unreachable; onOutcome fires per answered request, in order', async () => {
    const seen: string[] = [];
    const actions = { revoke: vi.fn(async () => ({ kind: 'taken-away' }) as const), assign: vi.fn(async () => { throw new Error('x'); }), propose: vi.fn() };
    const r = await runSave(plan3, actions, (o) => seen.push(`${o.request.roleId}:${o.result.kind}`));
    expect(seen).toEqual(['a:taken-away', 'b:unreachable']);
    expect(r.stop).toBe('unreachable');
  });
});
