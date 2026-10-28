// Which conflict lines a role shows (round-6 §2a, ux-copy-frontend.md Revision 3 item 5): one line per `conflicts_with`
// entry whose role is held or staged — RED L-6: that means in the AFTER-SAVE set (a held role staged for taking away is
// not in it) — held roles first, then SoD id order. Framework-free; words are the caller's (`copy/sod.ts`).

import type { OfferedRole, RoleConflict } from '../api/roles';

const sodOrder = (a: string, b: string) => a.localeCompare(b, 'en', { numeric: true });

export function conflictLinesOf(role: Pick<OfferedRole, 'conflicts_with'>, heldIds: ReadonlySet<string>, afterSet: ReadonlySet<string>): RoleConflict[] {
  const rank = (c: RoleConflict) => (heldIds.has(c.role.id) ? 0 : 1);
  return role.conflicts_with
    .map((c, i) => ({ c, i }))
    .filter(({ c }) => afterSet.has(c.role.id))
    .sort((x, y) => rank(x.c) - rank(y.c) || sodOrder(x.c.sod_row, y.c.sod_row) || x.i - y.i)
    .map(({ c }) => c);
}
