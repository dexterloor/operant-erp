'use client';

import type { OfferedRole, RoleConflict } from '@/lib/api/roles';
import { PICKER, REFUSAL_COPY } from '@/copy/roles';
import { SOD_CLAUSES, pickerConflict, pickerConflictUnknown } from '@/copy/sod';
import { Checkbox } from '@/components/ui/Checkbox';
import { Chip } from '@/components/ui/Chip';
import styles from './RolePicker.module.css';

export type StagedLabel = 'give' | 'propose' | 'take-away' | null;
export type OutcomeChip = 'given' | 'taken-away' | null;

// Development-only warnings, once per code / id per page load (b4-owed-copy.md R1-7; b4-step4-gaps.md Revision 2).
// Mirrors PermissionGroup's `warnOnce` (`components/permissions/PermissionGroup.tsx`), kept as a copy on purpose
// (b4-final-red F): sharing it would need a new file outside the binding tree, and `lib/**` holds no UI side effects.
const warned = new Set<string>();
function warnOnce(key: string, message: string) {
  if (process.env.NODE_ENV === 'production' || warned.has(key)) return;
  warned.add(key);
  console.warn(message);
}

/** A served refusal's reason line(s) on an UNHELD row, from the copy table keyed by the full served code. */
function refusalLines(refusal: string | null): { reason?: string; help?: string } {
  if (refusal === null) return {};
  const known = (REFUSAL_COPY as Record<string, (typeof REFUSAL_COPY)[keyof typeof REFUSAL_COPY] | undefined>)[refusal];
  if (!known) {
    warnOnce(`refusal:${refusal}`, `[role picker] no copy for served refusal code ${refusal}; showing the neutral reason (R1-7).`);
    return { reason: PICKER.unknownRefusal };
  }
  switch (known.show) {
    case 'reason':
      return { reason: known.reason, help: 'help' in known ? known.help : undefined };
    case 'not-rendered':
      return { reason: PICKER.unknownRefusal };
    default:
      return {};
  }
}

/** The named conflict sentence; an SoD id with no clause keeps its line and only its wording falls back. */
function conflictSentence(c: RoleConflict): string {
  const clause = SOD_CLAUSES[c.sod_row];
  if (clause === undefined) {
    warnOnce(`sod:${c.sod_row}`, `[role picker] no clause for served SoD id ${c.sod_row}; showing the fallback wording.`);
    return pickerConflictUnknown(c.role.name, c.sod_row);
  }
  return pickerConflict(c.role.name, clause, c.sod_row);
}

export interface RoleOptionProps {
  /** The picker's `useId()`, so two pickers on one page never share an id. */
  idPrefix: string;
  role: OfferedRole;
  held: boolean;
  checked: boolean;
  unavailable: boolean;
  staged: StagedLabel;
  outcome: OutcomeChip;
  /** `lib/roles/conflictLines.ts` over the after-save set. */
  conflicts: readonly RoleConflict[];
  /** Page-level reason ids (the own-page line), added to the box's description. */
  pageReasonIds?: string;
  onToggle: (roleId: string, next: boolean) => void;
}

/**
 * One row of the picker: the box, the role's served name, its staged label or outcome pill, the confirmation tag and help
 * line, and every reason line as visible text tied with `aria-describedby` (round-6 §2a). Everything shown is chosen by a
 * served field; nothing reads the role's name except to print it.
 */
export function RoleOption({ idPrefix, role, held, checked, unavailable, staged, outcome, conflicts, pageReasonIds, onToggle }: RoleOptionProps) {
  const base = `${idPrefix}-role-${role.id}`;
  const tagged = role.requires_owner_confirmation;
  const { reason, help } = held ? {} : refusalLines(role.refusal);
  const ids = [
    pageReasonIds,
    tagged ? `${base}-tag ${base}-tag-help` : null,
    reason ? `${base}-reason` : null,
    help ? `${base}-help` : null,
    ...conflicts.map((_, i) => `${base}-conflict-${i}`),
  ].filter(Boolean);

  return (
    <li className={styles.option} data-role-id={role.id}>
      <div className={styles.optionHead}>
        <Checkbox
          checked={checked}
          unavailable={unavailable}
          describedBy={ids.join(' ')}
          label={role.name}
          onCheckedChange={(next) => onToggle(role.id, next)}
        />
        {staged === 'give' ? <Chip tone="accent">{PICKER.willBeGiven}</Chip> : null}
        {staged === 'propose' ? <Chip tone="violet">{PICKER.willBeProposed}</Chip> : null}
        {staged === 'take-away' ? <Chip tone="warning">{PICKER.willBeTakenAway}</Chip> : null}
        {staged === null && outcome === 'given' ? <Chip tone="success">{PICKER.given}</Chip> : null}
        {staged === null && outcome === 'taken-away' ? <Chip tone="neutral">{PICKER.takenAway}</Chip> : null}
      </div>
      {tagged ? (
        <div className={styles.lines}>
          <Chip tone="info" id={`${base}-tag`}>
            {PICKER.confirmationTag}
          </Chip>
          <p id={`${base}-tag-help`} className={styles.help}>
            {PICKER.confirmationHelp}
          </p>
        </div>
      ) : null}
      {reason || help || conflicts.length > 0 ? (
        <div className={styles.lines}>
          {reason ? (
            <p id={`${base}-reason`} className={styles.reason}>
              {reason}
            </p>
          ) : null}
          {help ? (
            <p id={`${base}-help`} className={styles.help}>
              {help}
            </p>
          ) : null}
          {conflicts.map((c, i) => (
            <p key={`${c.role.id}|${c.sod_row}`} id={`${base}-conflict-${i}`} className={styles.reason}>
              {conflictSentence(c)}
            </p>
          ))}
        </div>
      ) : null}
    </li>
  );
}
