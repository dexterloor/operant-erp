// The margin warning, from served data only (round-6 §2a; ux-copy-frontend.md R4-2 as amended by 4b-2). A warning, never a
// refusal: it never disables anything and never blocks Save roles. Framework-free; the sentences are `copy/roles.ts`'s.
//
// - Held form: the person's served `margin_exposure` (it wins over the pair form).
// - Pair form: one per STAGED role (a staged proposal included — the sentence says "could") whose served
//   `margin_exposure_with` names a role in the after-save set, in served order; `{role}` is that staged role.
// No cost/sale code list exists in the client (R4-4 L-10). A role that exposes on its own shows nothing before saving
// (4b-8, a recorded gap for BE-LEAD + SEC).

import type { OfferedRole } from '../api/roles';
import type { Intents } from './intents';

export type MarginWarning = { form: 'none' } | { form: 'held' } | { form: 'pair'; roleNames: string[] };

export function marginWarningOf(personExposed: boolean, offered: readonly OfferedRole[], intents: Intents, afterSet: ReadonlySet<string>): MarginWarning {
  if (personExposed) return { form: 'held' };
  const roleNames = offered
    .filter((r) => intents.get(r.id) === 'give' && r.margin_exposure_with.some((m) => afterSet.has(m.id)))
    .map((r) => r.name);
  return roleNames.length > 0 ? { form: 'pair', roleNames } : { form: 'none' };
}

/** A stable key for "appears or changes form" (L-3): equal keys = no announcement. */
export const marginKey = (w: MarginWarning) => (w.form === 'pair' ? `pair:${w.roleNames.join('|')}` : w.form);
