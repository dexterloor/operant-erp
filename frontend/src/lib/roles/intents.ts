// Staging as INTENTS (RED H-1, Revision 3 A-1): `Map<roleId, 'give' | 'take-away'>`, never a derived change list. Every
// other value is derived in render from the intents and the props, so a re-read that changes the served facts changes what
// is shown and what would be sent. Framework-free; never imports copy (display order and words are the caller's).

import type { OfferedRole } from '../api/roles';

export type Intent = 'give' | 'take-away';
export type Intents = ReadonlyMap<string, Intent>;
/** The request a `give` intent becomes: derived from the served tag on every render, never stored. */
export type GiveKind = 'give' | 'propose';

export const giveKindOf = (role: Pick<OfferedRole, 'requires_owner_confirmation'>): GiveKind =>
  role.requires_owner_confirmation ? 'propose' : 'give';

/** The facts a reconcile reads, all served. `heldIds` = the person's `roles[]` ids (round-6 §2a S-1: never `refusal`). */
export interface ServedFacts {
  heldIds: ReadonlySet<string>;
  offered: readonly OfferedRole[];
  /**
   * A-1: `role.id` of every row with `is_expired` false in the WHOLE `ListRoleGrantProposals?user={id}&status=pending` walk
   * (never the visible page). An expired pending proposal is not in it, so its intent stays (a re-tick replaces it, 4b-3).
   */
  pendingRoleIds: ReadonlySet<string>;
}

/**
 * Reconcile against fresh props, in render (never in an effect): drop a `give` intent whose role is now held, **or whose
 * derived kind is `propose` and whose role is in `pendingRoleIds`**, or that is no longer offered; drop a `take-away`
 * intent whose role is no longer held. So a request that committed vanishes from staging by itself, and a refused one
 * stays. Returns the same map object when nothing is dropped.
 */
export function reconcile(intents: Intents, facts: ServedFacts): Intents {
  const byId = new Map(facts.offered.map((r) => [r.id, r]));
  let out: Map<string, Intent> | null = null;
  for (const [roleId, intent] of intents) {
    let drop: boolean;
    if (intent === 'take-away') drop = !facts.heldIds.has(roleId);
    else {
      const role = byId.get(roleId);
      drop = role === undefined || facts.heldIds.has(roleId) || (giveKindOf(role) === 'propose' && facts.pendingRoleIds.has(roleId));
    }
    if (drop) {
      out ??= new Map(intents);
      out.delete(roleId);
    }
  }
  return out ?? intents;
}

/**
 * A tick or untick of one row. Ticking an unheld role stages `give`; unticking it clears that. Unticking a held role
 * stages `take-away`; re-ticking it clears that. The picker never unticks anything for the person.
 */
export function toggle(intents: Intents, roleId: string, next: boolean, heldIds: ReadonlySet<string>): Intents {
  const out = new Map(intents);
  const held = heldIds.has(roleId);
  if (held) {
    if (next) out.delete(roleId);
    else out.set(roleId, 'take-away');
  } else if (next) out.set(roleId, 'give');
  else out.delete(roleId);
  return out;
}

/** RED L-6: "held or staged" = the after-save set — held, minus staged take-aways, plus staged gives (proposals included). */
export function afterSetOf(heldIds: ReadonlySet<string>, intents: Intents): Set<string> {
  const out = new Set(heldIds);
  for (const [roleId, intent] of intents) {
    if (intent === 'take-away') out.delete(roleId);
    else out.add(roleId);
  }
  return out;
}

/** Removes one intent (A-2: a 2xx answer clears its own intent at once). */
export function without(intents: Intents, roleId: string): Intents {
  if (!intents.has(roleId)) return intents;
  const out = new Map(intents);
  out.delete(roleId);
  return out;
}
