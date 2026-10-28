'use client';

import { useId } from 'react';
import type { Permission } from '@/lib/api/permissions';
import type { Role } from '@/lib/api/roles';
import { plural } from '@/lib/format/plural';
import type { MarginWarning } from '@/lib/roles/margin';
import type { Preview } from '@/lib/roles/effectivePreview';
import { PICKER } from '@/copy/roles';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { Banner } from '@/components/ui/Banner';
import { Chip } from '@/components/ui/Chip';
import styles from './RolePicker.module.css';

/** The margin warning's sentences, one per staged pair role, or the held form (4b-2). */
export function marginSentences(w: MarginWarning): string[] {
  if (w.form === 'held') return [PICKER.marginHeld];
  if (w.form === 'pair') return w.roleNames.map((n) => PICKER.marginPair(n));
  return [];
}

export interface EffectivePermissionsProps {
  /**
   * `ListRoles` + `ListPermissions` items, both present together and only with `role.view` (RED M-10). Absent: the
   * section shows only the margin warning and "Permissions aren't shown…" (R4-2, 4b-2) — and no summary or flags.
   */
  roles?: readonly Role[];
  permissions?: readonly Permission[];
  /** `lib/roles/effectivePreview.ts` (proposals never change it), or null without `role.view`. */
  preview: Preview | null;
  warning: MarginWarning;
}

/**
 * The effective-permissions panel (round-6 §2a; R4-1; R4-2; 4b-2). The margin warning sits at the top of the section,
 * directly above the list or the "aren't shown" line, never on a role option and never `aria-disabled`. It is announced
 * through the picker's ONE status line (L-3), so its banner takes no live role.
 */
export function EffectivePermissions({ roles, permissions, preview, warning }: EffectivePermissionsProps) {
  const headingId = useId();
  const shown = roles !== undefined && permissions !== undefined && preview !== null;
  const sentences = marginSentences(warning);
  const descriptions = new Map((permissions ?? []).map((p) => [p.code, p.description]));
  const labelOf = (code: string) => PERMISSION_LABELS[code]?.label ?? descriptions.get(code) ?? code;

  let summary = '';
  let rows: Array<{ code: string; flag: 'added' | 'taken-away' | null }> = [];
  if (shown) {
    const count = plural(preview.after.size, PICKER.permissionCount);
    const changed = preview.added.size > 0 || preview.takenAway.size > 0;
    summary = changed ? PICKER.afterSaving(count, preview.added.size, preview.takenAway.size) : count;
    const order = permissions.map((p) => p.code);
    const known = new Set(order);
    const all = [...new Set([...preview.after, ...preview.takenAway])];
    const sorted = [...all.filter((c) => known.has(c)).sort((a, b) => order.indexOf(a) - order.indexOf(b)), ...all.filter((c) => !known.has(c)).sort()];
    rows = sorted.map((code) => ({ code, flag: preview.added.has(code) ? 'added' : preview.takenAway.has(code) ? 'taken-away' : null }));
  }

  return (
    <section aria-labelledby={headingId} className={styles.panel}>
      <h2 id={headingId} className={styles.panelHeading}>
        {PICKER.effectiveHeading}
      </h2>
      {sentences.length > 0 ? (
        <Banner tone="warning" live="none" heading={sentences[0]}>
          {sentences.slice(1).map((s) => (
            <p key={s}>{s}</p>
          ))}
        </Banner>
      ) : null}
      {shown ? (
        <>
          <p className={styles.summary}>{summary}</p>
          <ul className={styles.permissionList}>
            {rows.map((r) => (
              <li key={r.code} className={r.flag === 'taken-away' ? styles.struck : undefined}>
                <span className={styles.permissionLabel}>{labelOf(r.code)}</span>
                {r.flag === 'added' ? <Chip tone="success">{PICKER.addedFlag}</Chip> : null}
                {r.flag === 'taken-away' ? <Chip tone="warning">{PICKER.takenAwayFlag}</Chip> : null}
              </li>
            ))}
          </ul>
        </>
      ) : (
        <p className={styles.hidden}>{PICKER.permissionsHidden}</p>
      )}
    </section>
  );
}
