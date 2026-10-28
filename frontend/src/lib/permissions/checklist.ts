// The permission checklist's pure logic (b4-architecture.md tree: `lib/permissions/checklist.ts`). Framework-free: no
// react, next or component import (architecture.test.ts).
//
// Principle 2 (Revision 1, RED H-2): the client never decides owner-only, ceiling, companions, margin or confirmation.
// The ONE separation-of-duty derivation here is `completesSplit(code, ticked, permissions)`, over the SERVED
// `sod_rows[{id, half}]` only (PC-4, `SeparationOfDuty::rowsSpannedBy`, re-applied at selection time). `tickGroup`'s
// "left out" rule is built on it, not beside it. Nothing here reads a role name or a permission-code literal: the
// invariance test (test/invariance.test.tsx) renames every code and must see the same structure.
//
// Display order is a parameter (`copy/permissionLabels.ts` owns module headings, their order and the label order); this
// file never imports copy.

import type { Permission, SodRowRef } from '../api/permissions';

/** One module's boxes, in display order. */
export interface PermissionGroupData {
  /** The served `module` value, e.g. `Modules\Purchasing`. */
  module: string;
  permissions: Permission[];
}

/**
 * Groups the served catalogue by `module`. Known modules come first in `moduleOrder`; an unknown module follows, in
 * served order, so a new module is visible rather than dropped (permission-labels.md "Module headings"). Inside a
 * group, codes in `codeOrder` come first in that order; any other code follows in served order (its row shows the
 * server's `description`).
 */
export function groupPermissions(permissions: readonly Permission[], moduleOrder: readonly string[], codeOrder: readonly string[]): PermissionGroupData[] {
  const byModule = new Map<string, Permission[]>();
  for (const p of permissions) {
    const list = byModule.get(p.module);
    if (list) list.push(p);
    else byModule.set(p.module, [p]);
  }
  const rank = (order: readonly string[], value: string) => {
    const i = order.indexOf(value);
    return i === -1 ? Number.POSITIVE_INFINITY : i;
  };
  const servedIndex = new Map(permissions.map((p, i) => [p.code, i]));
  const modules = [...byModule.keys()];
  const moduleServed = new Map(modules.map((m, i) => [m, i]));
  modules.sort((a, b) => rank(moduleOrder, a) - rank(moduleOrder, b) || moduleServed.get(a)! - moduleServed.get(b)!);
  return modules.map((module) => ({
    module,
    permissions: [...byModule.get(module)!].sort(
      (a, b) => rank(codeOrder, a.code) - rank(codeOrder, b.code) || servedIndex.get(a.code)! - servedIndex.get(b.code)!,
    ),
  }));
}

/** One split an unticked box would complete: the row, the box's own half of it, and the ticked box on the other half. */
export interface SplitConflict {
  sodId: string;
  /** The half of `sodId` that the box under test is on. */
  half: SodRowRef['half'];
  /** The ticked code on the other half of the same row. */
  withCode: string;
}

/**
 * The one ruled SoD derivation (principle 2 (b)). For a box NOT ticked: every ticked box on the other half of one of
 * its served `sod_rows`. Non-empty means ticking it would complete a split, so it is `aria-disabled` with one reason
 * line per entry. A ticked box always answers `[]`: a built-in role may hold both halves (owner, purchasing_manager),
 * and its read-only checklist shows both ticked with the SoD marker and no conflict reason (round-6 §2b).
 * Ordered by SoD id, then by the other code's served position.
 */
export function completesSplit(code: string, ticked: ReadonlySet<string>, permissions: readonly Permission[]): SplitConflict[] {
  if (ticked.has(code)) return [];
  const own = permissions.find((p) => p.code === code);
  if (!own) return [];
  const out: SplitConflict[] = [];
  const rows = [...own.sod_rows].sort((a, b) => a.id.localeCompare(b.id, 'en', { numeric: true }));
  for (const row of rows) {
    for (const other of permissions) {
      if (other.code === code || !ticked.has(other.code)) continue;
      if (other.sod_rows.some((r) => r.id === row.id && r.half !== row.half)) out.push({ sodId: row.id, half: row.half, withCode: other.code });
    }
  }
  return out;
}

/** Tri-state of a group box: every box ticked → true; none → false; otherwise mixed. */
export function groupState(groupCodes: readonly string[], ticked: ReadonlySet<string>): boolean | 'mixed' {
  const n = groupCodes.filter((c) => ticked.has(c)).length;
  if (n === 0) return false;
  return n === groupCodes.length ? true : 'mixed';
}

export interface GroupToggle {
  ticked: Set<string>;
  /** Codes the group rule left out (Rev 3 item 6), in group order — never a box that was already refused. */
  leftOut: string[];
}

/**
 * The group checkbox (ux-copy-frontend.md Revision 3 item 6; round-6 §2b + RED S-4). Ticking: every box in the group that
 * is not refused is ticked, except each box on the CONTROLLED half of a row whose other half is ticked or being ticked;
 * those are reported as `leftOut`. A box refused before the click stays as it was. Unticking: every box in the group.
 *
 * When no box in the group can be ticked (every box is ticked or refused), the click unticks the whole group, including
 * boxes ticked by hand (b4-step4-gaps.md Revision 2, G-a). This simplifies the WAI-ARIA APG mixed checkbox; it is not
 * that pattern: "all" is often unreachable (a group holding both halves of a split), so Purchasing and Sales alternate
 * between mixed and none. **Residual, recorded not built:** a group with nothing ticked whose every box is refused by
 * ticks in OTHER modules would change nothing on a click. No group in today's catalogue can reach it, because each has a
 * code on no split — pinned by the tripwire in test/permissions/checklist.test.ts (backend carry BK-12). `UX` to confirm,
 * together with the loss of hand-ticked boxes on untick.
 */
export function toggleGroup(groupCodes: readonly string[], next: boolean, ticked: ReadonlySet<string>, permissions: readonly Permission[]): GroupToggle {
  const untickAll = (): GroupToggle => {
    const out = new Set(ticked);
    for (const c of groupCodes) out.delete(c);
    return { ticked: out, leftOut: [] };
  };
  if (!next) return untickAll();
  const candidates = groupCodes.filter((c) => !ticked.has(c) && completesSplit(c, ticked, permissions).length === 0);
  if (candidates.length === 0) return untickAll();
  const being = new Set([...ticked, ...candidates]);
  const leftOut = candidates.filter((c) => {
    const others = new Set(being);
    others.delete(c);
    return completesSplit(c, others, permissions).some((x) => x.half === 'controlled');
  });
  const out = new Set(ticked);
  for (const c of candidates) if (!leftOut.includes(c)) out.add(c);
  return { ticked: out, leftOut };
}

/** The dirty bar's count: codes added plus codes taken away against the saved set. */
export function changeCount(saved: readonly string[], ticked: ReadonlySet<string>): number {
  const savedSet = new Set(saved);
  let n = 0;
  for (const c of ticked) if (!savedSet.has(c)) n += 1;
  for (const c of savedSet) if (!ticked.has(c)) n += 1;
  return n;
}
