'use client';

import { useId } from 'react';
import type { Permission } from '@/lib/api/permissions';
import { completesSplit, groupState } from '@/lib/permissions/checklist';
import { CHECKLIST, PERMISSION_LABELS } from '@/copy/permissionLabels';
import { checklistConflict, checklistConflictUnknown, SOD_CLAUSES } from '@/copy/sod';
import { Checkbox } from '@/components/ui/Checkbox';
import { Chip } from '@/components/ui/Chip';
import { LiveRegion } from '@/components/ui/LiveRegion';
import styles from './PermissionChecklist.module.css';

// Development-only warning, once per SoD id per page load (b4-owed-copy.md R1-7; b4-step4-gaps.md Revision 2, G-c).
// Mirrors the picker's `warnOnce` (`components/roles/RoleOption.tsx`): sharing it would need a new file outside the
// binding tree, and `lib/**` holds no UI side effects.
const warned = new Set<string>();
function warnOnce(key: string, message: string) {
  if (process.env.NODE_ENV === 'production' || warned.has(key)) return;
  warned.add(key);
  console.warn(message);
}

/** The reason line; an SoD id with no clause keeps its line (and its refusal) and only its wording falls back. */
function conflictSentence(label: string, sodId: string): string {
  const clause = SOD_CLAUSES[sodId];
  if (clause === undefined) {
    warnOnce(`sod:${sodId}`, `[permission checklist] no clause for served SoD id ${sodId}; showing the fallback wording.`);
    return checklistConflictUnknown(label, sodId);
  }
  return checklistConflict(label, clause, sodId);
}

export type GroupMode =
  /** A custom role the viewer may edit. */
  | 'edit'
  /** A built-in role (served `is_system`): every box read-only, each described by the built-in line (R4-4 L-7). */
  | 'built-in'
  /** The viewer lacks `role.edit` (ST-4c): no control is offered at all, so the role's permissions are listed, no box. */
  | 'read';

export interface PermissionGroupProps {
  heading: string;
  /** This group's boxes, in display order. */
  permissions: readonly Permission[];
  /** The whole served catalogue, unchanged. A split across modules is still a split, so `completesSplit` reads all of it. */
  catalogue: readonly Permission[];
  ticked: ReadonlySet<string>;
  mode: GroupMode;
  /** The id of the "Built-in role. It can't be changed or deleted." line (built-in mode). */
  builtInLineId?: string;
  /** Label, or the server's `description` for a code with no label. */
  labelOf: (code: string) => string;
  onToggle: (code: string, next: boolean) => void;
  onToggleGroup: (next: boolean) => void;
  /** The group's "Left out" line; '' when it has nothing to say. Its region is mounted empty (R4-4 L-2). */
  leftOutMessage: string;
}

/**
 * One module: heading, tri-state group box, "{n} of {m}", the always-mounted "Left out" line, and its boxes (round-6
 * §2b). A box that would complete a split with a ticked box is `aria-disabled` with one reason line per split, from
 * `completesSplit` over served `sod_rows`; a box on a split carries its SoD id marker. No box is ever disabled for a
 * permission the editor lacks, and none for a companion: the server refuses after the click.
 */
export function PermissionGroup({ heading, permissions, catalogue, ticked, mode, builtInLineId, labelOf, onToggle, onToggleGroup, leftOutMessage }: PermissionGroupProps) {
  const base = useId();
  const codes = permissions.map((p) => p.code);
  const held = codes.filter((c) => ticked.has(c)).length;
  const headingId = `${base}-h`;

  if (mode === 'read') {
    const heldPermissions = permissions.filter((p) => ticked.has(p.code));
    return (
      <section className={styles.group} aria-labelledby={headingId}>
        <div className={styles.groupHead}>
          <h2 id={headingId} className={styles.groupHeading}>
            {heading}
          </h2>
          <span className={styles.count}>{CHECKLIST.groupCount(held, codes.length)}</span>
        </div>
        {heldPermissions.length > 0 ? (
          <ul className={styles.readList}>
            {heldPermissions.map((p) => (
              <li key={p.code}>{labelOf(p.code)}</li>
            ))}
          </ul>
        ) : null}
      </section>
    );
  }

  const builtIn = mode === 'built-in';
  return (
    <section className={styles.group} aria-labelledby={headingId}>
      <div className={styles.groupHead}>
        <Checkbox
          label={CHECKLIST.groupCheckbox(heading)}
          hideLabel
          checked={groupState(codes, ticked)}
          onCheckedChange={onToggleGroup}
          unavailable={builtIn}
          describedBy={builtIn ? builtInLineId : undefined}
        />
        <h2 id={headingId} className={styles.groupHeading}>
          {heading}
        </h2>
        <span className={styles.count}>{CHECKLIST.groupCount(held, codes.length)}</span>
      </div>
      <LiveRegion message={leftOutMessage} className={styles.leftOut} />
      <ul className={styles.rows}>
        {permissions.map((p, i) => {
          const rowId = `${base}-${i}`;
          // On a built-in role every box is read-only for one reason, the built-in line; a split reason would be a second,
          // irrelevant cause (nothing can be ticked there), so none is shown.
          const conflicts = builtIn ? [] : completesSplit(p.code, ticked, catalogue);
          const help = PERMISSION_LABELS[p.code]?.help;
          const markerIds = p.sod_rows.map((_, j) => `${rowId}-m${j}`);
          const reasonIds = conflicts.map((_, j) => `${rowId}-r${j}`);
          const describedBy = [...markerIds, help ? `${rowId}-help` : null, ...reasonIds, builtIn ? builtInLineId : null].filter(Boolean).join(' ');
          return (
            <li key={p.code} className={styles.row}>
              <Checkbox
                label={labelOf(p.code)}
                checked={ticked.has(p.code)}
                onCheckedChange={(next) => onToggle(p.code, next)}
                unavailable={builtIn || conflicts.length > 0}
                describedBy={describedBy}
              />
              {p.sod_rows.length > 0 ? (
                <span className={styles.markers}>
                  {p.sod_rows.map((r, j) => (
                    <Chip key={`${r.id}-${r.half}`} id={markerIds[j]} tone="violet">
                      {r.id}
                    </Chip>
                  ))}
                </span>
              ) : null}
              {help ? (
                <p id={`${rowId}-help`} className={styles.help}>
                  {help}
                </p>
              ) : null}
              {conflicts.map((c, j) => (
                <p key={`${c.sodId}-${c.withCode}`} id={reasonIds[j]} className={styles.reason}>
                  {conflictSentence(labelOf(c.withCode), c.sodId)}
                </p>
              ))}
            </li>
          );
        })}
      </ul>
    </section>
  );
}
