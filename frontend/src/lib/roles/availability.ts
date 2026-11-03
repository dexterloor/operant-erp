// Principle 2 (a) — the ONE SoD derivation the role picker makes (b4-architecture.md Revision 1, RED H-2), over served
// data only. Reads no role name and no permission-code literal: only ids, `refusal`, `conflicts_with`, the held set and
// the intents. Framework-free.
//
// The pinned rule, RED H-2, verbatim:
// ```
// pageLevel  = person.archived_at ≠ null (picker not rendered) | !person.is_active (not rendered) | viewer = person (all aria-disabled)
// afterSet   = (held − takeAway) ∪ give            // give includes would-be proposals
// unavailable(r) = r ∉ held ∧ ( (r.refusal ∉ {null, 'user_role.separation_of_duty'})
//                              ∨ ∃ c ∈ r.conflicts_with : c.role.id ∈ afterSet )
// saveBlocked    = ∃ r ∈ afterSet, c ∈ r.conflicts_with : c.role.id ∈ afterSet
// newlyUnavailable / availableAgain = diff of unavailable() between (props, intentsBefore) and (props, intentsAfter)
// ```
// Note (RED H-2, kept by ruling item 7): for a person already holding a conflicting pair, `conflicts_with` (pairwise,
// `OfferedRoleEvaluator::conflictsWith`) is stricter than the server's union-based phase 4 — the known F-7a-8 / G-91 case.
// This function must NOT "fix" it: Save roles stays aria-disabled while a held conflicting pair is selected.
//
// An SoD id the copy has no clause for counts exactly like a known one (b4-step4-gaps.md Revision 2): behaviour follows
// served data, never whether copy exists. Only its wording falls back (`copy/sod.ts`).

import { REFUSAL, type OfferedRole } from '../api/roles';

export interface AvailabilityFacts {
  offered: readonly OfferedRole[];
  heldIds: ReadonlySet<string>;
  /** `afterSetOf(heldIds, intents)`. */
  afterSet: ReadonlySet<string>;
  /** pageLevel's third arm: the viewer is the person (served `GetCurrentUser` id = person id): every row aria-disabled. */
  ownPage: boolean;
}

/** Whether one row is `aria-disabled`. */
export function isUnavailable(role: OfferedRole, facts: Omit<AvailabilityFacts, 'offered'>): boolean {
  if (facts.ownPage) return true;
  if (facts.heldIds.has(role.id)) return false;
  if (role.refusal !== null && role.refusal !== REFUSAL.separationOfDuty) return true;
  return role.conflicts_with.some((c) => facts.afterSet.has(c.role.id));
}

/** The set of unavailable role ids, for the status line's diff. */
export function unavailableIds(facts: AvailabilityFacts): Set<string> {
  return new Set(facts.offered.filter((r) => isUnavailable(r, facts)).map((r) => r.id));
}

/** Save roles is aria-disabled while the after-save set holds a conflicting pair (F-7a-8). */
export function saveBlocked(facts: Pick<AvailabilityFacts, 'offered' | 'afterSet'>): boolean {
  return facts.offered.some((r) => facts.afterSet.has(r.id) && r.conflicts_with.some((c) => facts.afterSet.has(c.role.id)));
}

/**
 * R4-4 L-1: "newly unavailable" = aria-disabled false before the action and true after; "available again" = the reverse.
 * Computed from the rendered state, so a role refused when the page loaded is never named. Ids in `offered` order.
 */
export function diff(offered: readonly OfferedRole[], before: ReadonlySet<string>, after: ReadonlySet<string>): { newlyUnavailable: string[]; availableAgain: string[] } {
  return {
    newlyUnavailable: offered.filter((r) => !before.has(r.id) && after.has(r.id)).map((r) => r.id),
    availableAgain: offered.filter((r) => before.has(r.id) && !after.has(r.id)).map((r) => r.id),
  };
}
