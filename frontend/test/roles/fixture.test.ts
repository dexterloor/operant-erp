import { describe, expect, it } from 'vitest';
import { OFFERED_HELD_PAIR_BY_ADMIN, OFFERED_JONAS_BY_ADMIN, OFFERED_JONAS_BY_OWNER, ROLES } from '@/components/preview/fixtures/roles';

// The fixture must be true of the served API (round-6 §2a "The real rules"; r6-build-notes H-1, M-3, M-4). These pins
// fail if the generated values drift from the facts the design docs state.
describe('role fixture — facts of the served API', () => {
  const builtIn = new Set(ROLES.filter((r) => r.is_system).map((r) => r.name));

  it('the seven conflicting built-in pairs, with their rows', () => {
    const pairs = new Set<string>();
    for (const r of OFFERED_JONAS_BY_ADMIN) {
      if (!builtIn.has(r.name)) continue;
      for (const c of r.conflicts_with) if (builtIn.has(c.role.name)) pairs.add(`${[r.name, c.role.name].sort().join(' + ')} ${c.sod_row}`);
    }
    expect([...pairs].sort()).toEqual([
      'admin + finance SoD-3',
      'admin + finance SoD-5',
      'admin + warehouse_operator SoD-2',
      'admin + warehouse_operator SoD-4',
      'buyer + warehouse_operator SoD-2',
      'finance + sales_rep SoD-3',
      'finance + warehouse_operator SoD-5',
      'purchasing_manager + warehouse_operator SoD-2',
      'sales_rep + warehouse_operator SoD-4',
    ]);
  });

  it('for an admin, exactly purchasing_manager, warehouse_operator and finance need confirmation when unrefused', () => {
    const tagged = OFFERED_HELD_PAIR_BY_ADMIN.filter((r) => r.requires_owner_confirmation).map((r) => r.name);
    // warehouse_operator is refused for the held pair (SoD-4 / SoD-5), so it carries no tag (refusal ⇒ no tag).
    expect(tagged.sort()).toEqual(['purchasing_manager']);
    expect(OFFERED_JONAS_BY_ADMIN.filter((r) => r.requires_owner_confirmation).map((r) => r.name).sort()).toEqual(['finance', 'purchasing_manager']);
    for (const list of [OFFERED_JONAS_BY_ADMIN, OFFERED_HELD_PAIR_BY_ADMIN]) for (const r of list) if (r.refusal !== null) expect(r.requires_owner_confirmation).toBe(false);
    expect(OFFERED_JONAS_BY_OWNER.some((r) => r.requires_owner_confirmation)).toBe(false);
  });

  it('the ceiling refuses only the custom role; owner-only refuses owner for a non-owner', () => {
    const ceiling = OFFERED_JONAS_BY_ADMIN.filter((r) => r.refusal === 'user_role.exceeds_actor_permissions');
    expect(ceiling.map((r) => r.name)).toEqual(['Stock controller']);
    expect(ROLES.find((r) => r.name === 'Stock controller')?.is_system).toBe(false);
    expect(OFFERED_JONAS_BY_ADMIN.find((r) => r.name === 'owner')?.refusal).toBe('user_role.marker_needs_owner');
    expect(OFFERED_JONAS_BY_OWNER.find((r) => r.name === 'owner')?.refusal).toBeNull();
  });

  it('F-7 step 1: Jonas (buyer) sees warehouse_operator refused at load with no tag; buyer arrives already_held', () => {
    const wo = OFFERED_JONAS_BY_ADMIN.find((r) => r.name === 'warehouse_operator')!;
    expect(wo.refusal).toBe('user_role.separation_of_duty');
    expect(wo.requires_owner_confirmation).toBe(false);
    expect(OFFERED_JONAS_BY_ADMIN.find((r) => r.name === 'buyer')?.refusal).toBe('user_role.already_held');
    // buyer shows cost and finance shows sale: the pair warning (step 2).
    expect(OFFERED_JONAS_BY_ADMIN.find((r) => r.name === 'finance')?.margin_exposure_with.map((m) => m.name)).toContain('buyer');
  });
});
