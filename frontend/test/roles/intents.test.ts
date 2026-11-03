import { describe, expect, it } from 'vitest';
import { OFFERED_JONAS_BY_ADMIN, ROLE_ID } from '@/components/preview/fixtures/roles';
import { afterSetOf, giveKindOf, reconcile, toggle, type Intents } from '@/lib/roles/intents';

const held = (...names: string[]) => new Set(names.map((n) => ROLE_ID[n]!));
const facts = (heldIds: Set<string>, pending: string[] = []) => ({ heldIds, offered: OFFERED_JONAS_BY_ADMIN, pendingRoleIds: new Set(pending.map((n) => ROLE_ID[n]!)) });
const m = (entries: Array<[string, 'give' | 'take-away']>): Intents => new Map(entries.map(([n, i]) => [ROLE_ID[n]!, i]));

describe('intents (RED H-1; Revision 3 A-1)', () => {
  it('toggle: tick an unheld role stages give; untick clears; untick a held role stages take-away; re-tick clears', () => {
    const h = held('buyer');
    let i: Intents = new Map();
    i = toggle(i, ROLE_ID.viewer!, true, h);
    expect(i.get(ROLE_ID.viewer!)).toBe('give');
    i = toggle(i, ROLE_ID.viewer!, false, h);
    expect(i.size).toBe(0);
    i = toggle(i, ROLE_ID.buyer!, false, h);
    expect(i.get(ROLE_ID.buyer!)).toBe('take-away');
    i = toggle(i, ROLE_ID.buyer!, true, h);
    expect(i.size).toBe(0);
  });

  it('afterSet = held − take-away + give (proposals included)', () => {
    const after = afterSetOf(held('buyer'), m([['buyer', 'take-away'], ['finance', 'give']]));
    expect([...after]).toEqual([ROLE_ID.finance]);
  });

  it('give kind is derived from the served tag, never stored', () => {
    const finance = OFFERED_JONAS_BY_ADMIN.find((r) => r.name === 'finance')!;
    expect(giveKindOf(finance)).toBe('propose');
    expect(giveKindOf({ ...finance, requires_owner_confirmation: false })).toBe('give');
  });

  it('(a) one success + one refusal: new props hold the success → only the refused intent stays', () => {
    const i = m([['viewer', 'give'], ['sales_rep', 'give']]);
    const out = reconcile(i, facts(held('buyer', 'viewer')));
    expect([...out.keys()]).toEqual([ROLE_ID.sales_rep]);
  });

  it('(b) the staged role is now held through someone else\'s action → no staged row', () => {
    expect(reconcile(m([['viewer', 'give']]), facts(held('buyer', 'viewer'))).size).toBe(0);
  });

  it('a take-away whose role is no longer held is dropped', () => {
    expect(reconcile(m([['buyer', 'take-away']]), facts(held())).size).toBe(0);
  });

  it('(d) A-1: a propose intent whose role is in pendingRoleIds is dropped; an expired pending one (absent) stays', () => {
    const i = m([['finance', 'give']]);
    expect(reconcile(i, facts(held('buyer'), ['finance'])).size).toBe(0);
    expect(reconcile(i, facts(held('buyer'), [])).size).toBe(1);
  });

  it('A-1 is only for proposals: a direct give whose role has a pending proposal is not dropped by it', () => {
    const i = m([['viewer', 'give']]);
    expect(reconcile(i, facts(held('buyer'), ['viewer'])).size).toBe(1);
  });

  it('returns the same map when nothing is dropped (no needless re-render)', () => {
    const i = m([['viewer', 'give']]);
    expect(reconcile(i, facts(held('buyer')))).toBe(i);
  });
});
