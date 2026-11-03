import { describe, expect, it } from 'vitest';
import { OFFERED_HELD_PAIR_BY_ADMIN, OFFERED_JONAS_BY_ADMIN, ROLE_ID } from '@/components/preview/fixtures/roles';
import { diff, isUnavailable, saveBlocked, unavailableIds } from '@/lib/roles/availability';
import { afterSetOf, type Intents } from '@/lib/roles/intents';
import { conflictLinesOf } from '@/lib/roles/conflictLines';

const ids = (...names: string[]) => new Set(names.map((n) => ROLE_ID[n]!));
const names = (set: Iterable<string>) => [...set].map((id) => Object.keys(ROLE_ID).find((n) => ROLE_ID[n] === id)).sort();
const m = (entries: Array<[string, 'give' | 'take-away']>): Intents => new Map(entries.map(([n, i]) => [ROLE_ID[n]!, i]));
const factsFor = (offered = OFFERED_JONAS_BY_ADMIN, heldNames = ['buyer'], intents: Intents = new Map(), ownPage = false) => {
  const heldIds = ids(...heldNames);
  return { offered, heldIds, afterSet: afterSetOf(heldIds, intents), ownPage };
};

describe('availability — principle 2 (a), RED H-2 pinned rule', () => {
  it('F-7 step 1: only the served refusals are unavailable at load (owner, Stock controller, warehouse_operator)', () => {
    expect(names(unavailableIds(factsFor()))).toEqual(['Stock controller', 'owner', 'warehouse_operator']);
  });

  it('F-7 step 2: ticking finance makes sales_rep and admin unavailable; diff names only those', () => {
    const before = unavailableIds(factsFor());
    const after = unavailableIds(factsFor(OFFERED_JONAS_BY_ADMIN, ['buyer'], m([['finance', 'give']])));
    const d = diff(OFFERED_JONAS_BY_ADMIN, before, after);
    expect(names(d.newlyUnavailable)).toEqual(['admin', 'sales_rep']);
    expect(d.availableAgain).toEqual([]);
  });

  it('a held role is never unavailable, whatever its code', () => {
    const f = factsFor(OFFERED_HELD_PAIR_BY_ADMIN, ['finance', 'sales_rep']);
    expect(isUnavailable(OFFERED_HELD_PAIR_BY_ADMIN.find((r) => r.name === 'finance')!, f)).toBe(false);
  });

  it('staging the take-away of the blocking held role makes a served SoD refusal available again (Q-2)', () => {
    const wo = OFFERED_JONAS_BY_ADMIN.find((r) => r.name === 'warehouse_operator')!;
    expect(isUnavailable(wo, factsFor(OFFERED_JONAS_BY_ADMIN, ['buyer'], m([['buyer', 'take-away']])))).toBe(false);
  });

  it('a non-SoD served refusal is never overridden', () => {
    const owner = OFFERED_JONAS_BY_ADMIN.find((r) => r.name === 'owner')!;
    expect(isUnavailable(owner, factsFor(OFFERED_JONAS_BY_ADMIN, ['buyer'], m([['buyer', 'take-away']])))).toBe(true);
  });

  it('own page: every row is unavailable, held ones too', () => {
    const f = factsFor(OFFERED_JONAS_BY_ADMIN, ['buyer'], new Map(), true);
    expect(OFFERED_JONAS_BY_ADMIN.every((r) => isUnavailable(r, f))).toBe(true);
  });

  it('F-7 step 6: a held conflicting pair blocks Save; unticking one re-enables it (F-7a-8 kept)', () => {
    expect(saveBlocked(factsFor(OFFERED_HELD_PAIR_BY_ADMIN, ['finance', 'sales_rep']))).toBe(true);
    expect(saveBlocked(factsFor(OFFERED_HELD_PAIR_BY_ADMIN, ['finance', 'sales_rep'], m([['finance', 'take-away']])))).toBe(false);
  });

  it('an SoD id with no clause counts exactly like a known one (b4-step4-gaps Rev 2)', () => {
    const offered = OFFERED_JONAS_BY_ADMIN.map((r) => ({ ...r, conflicts_with: r.conflicts_with.map((c) => ({ ...c, sod_row: `X-${c.sod_row}` })) }));
    const after = unavailableIds({ ...factsFor(offered, ['buyer'], m([['finance', 'give']])) });
    expect(names(after)).toEqual(['Stock controller', 'admin', 'owner', 'sales_rep', 'warehouse_operator']);
  });
});

describe('conflictLines — held first, then SoD id order, over the after-save set (RED L-6)', () => {
  it('F-7 step 2: warehouse_operator shows buyer SoD-2, then finance SoD-5', () => {
    const wo = OFFERED_JONAS_BY_ADMIN.find((r) => r.name === 'warehouse_operator')!;
    const lines = conflictLinesOf(wo, ids('buyer'), afterSetOf(ids('buyer'), m([['finance', 'give']])));
    expect(lines.map((c) => `${c.role.name} ${c.sod_row}`)).toEqual(['buyer SoD-2', 'finance SoD-5']);
  });

  it('admin under staged finance shows SoD-3 then SoD-5', () => {
    const admin = OFFERED_JONAS_BY_ADMIN.find((r) => r.name === 'admin')!;
    const lines = conflictLinesOf(admin, ids('buyer'), afterSetOf(ids('buyer'), m([['finance', 'give']])));
    expect(lines.map((c) => `${c.role.name} ${c.sod_row}`)).toEqual(['finance SoD-3', 'finance SoD-5']);
  });

  it('a held role staged for taking away is not in the set, so its lines go (F-7 step 6)', () => {
    const sales = OFFERED_HELD_PAIR_BY_ADMIN.find((r) => r.name === 'sales_rep')!;
    expect(conflictLinesOf(sales, ids('finance', 'sales_rep'), afterSetOf(ids('finance', 'sales_rep'), m([['finance', 'take-away']])))).toEqual([]);
  });
});
