// The effective-permissions preview (round-6 §2a; R4-1 panel summary + flags). Effective set = union of `permission_codes`
// of the after-save set's roles, PROPOSALS EXCLUDED (a pending grant confers nothing, before or after saving).
// `person.roles[]` is live only (`UserReader`), so no archived role contributes. Framework-free.

import type { Role } from '../api/roles';
import { giveKindOf, type Intents } from './intents';
import type { OfferedRole } from '../api/roles';

export interface Preview {
  /** The codes after saving (Will be given / Will be taken away rows only). */
  after: Set<string>;
  /** In `after` and not held now. */
  added: Set<string>;
  /** Held now and not in `after`. */
  takenAway: Set<string>;
}

export function previewOf(roles: readonly Role[], heldIds: ReadonlySet<string>, intents: Intents, offered: readonly OfferedRole[]): Preview {
  const codesOf = new Map(roles.map((r) => [r.id, r.permission_codes]));
  const kindOf = new Map(offered.map((r) => [r.id, giveKindOf(r)]));
  const union = (ids: Iterable<string>) => new Set([...ids].flatMap((id) => codesOf.get(id) ?? []));
  const nowIds = [...heldIds];
  const afterIds = new Set(heldIds);
  for (const [roleId, intent] of intents) {
    if (intent === 'take-away') afterIds.delete(roleId);
    else if (kindOf.get(roleId) === 'give') afterIds.add(roleId);
  }
  const now = union(nowIds);
  const after = union(afterIds);
  return {
    after,
    added: new Set([...after].filter((c) => !now.has(c))),
    takenAway: new Set([...now].filter((c) => !after.has(c))),
  };
}
