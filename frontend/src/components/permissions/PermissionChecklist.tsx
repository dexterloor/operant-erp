'use client';

import { useId, useLayoutEffect, useMemo, useRef, useState, type ReactNode, type RefObject } from 'react';
import type { Permission } from '@/lib/api/permissions';
import type { Role } from '@/lib/api/roles';
import { formatList } from '@/lib/format/lists';
import { plural } from '@/lib/format/plural';
import { changeCount, groupPermissions, toggleGroup } from '@/lib/permissions/checklist';
import type { SaveRoleAction, SaveRoleResult } from '@/lib/permissions/results';
import { CHECKLIST, CHECKLIST_FORBIDDEN, CHECKLIST_INTERRUPTED, MODULE_HEADINGS, PERMISSION_LABELS } from '@/copy/permissionLabels';
import { COULD_NOT_REACH, NOT_SAVED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PAGE_STATES } from '@/copy/shell';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { PermissionGroup, type GroupMode } from './PermissionGroup';
import styles from './PermissionChecklist.module.css';

export interface PermissionChecklistProps {
  /** The served role (`GetRole`). Built-in read-only comes from its `is_system`, never from its name (RED M-9). */
  role: Role;
  /** `ListPermissions`' items, the whole catalogue (`role.view`). `{total}` is its length. */
  permissions: readonly Permission[];
  /** The viewer holds `role.edit` (ST-4c). False: no box is offered; the role's permissions are listed. */
  canEdit: boolean;
  save: SaveRoleAction;
  /** The page's `<h1>`, owned by the route: focus goes there when a save succeeds and the dirty bar goes. */
  fallbackFocusRef: RefObject<HTMLElement | null>;
}

interface Notice {
  id: number;
  heading: string;
  body: ReactNode;
}

const MODULE_ORDER = MODULE_HEADINGS.map((m) => m.module);
const CODE_ORDER = Object.keys(PERMISSION_LABELS);
const headingOf = (module: string) => MODULE_HEADINGS.find((m) => m.module === module)?.heading ?? module;
const keyOf = (role: Role) => `${role.id}|${[...role.permission_codes].sort().join(',')}`;

/**
 * The banner for a non-success answer, or null for a 401 (R1-4: no in-page copy; the caller navigates to sign-in). A
 * refusal's `message` is the server's sentence, rendered as sent (ST-3, R1-7 step 4); no refusal code is branched on.
 */
function noticeFor(result: Exclude<SaveRoleResult, { kind: 'saved' }>, id: number): Notice | null {
  switch (result.kind) {
    case 'refused':
      return { id, heading: NOT_SAVED, body: result.message !== '' ? result.message : REFUSED_WITHOUT_MESSAGE };
    case 'not-found':
      return { id, heading: NOT_SAVED, body: `${PAGE_STATES.notFoundHeading} ${PAGE_STATES.notFoundBody}` };
    case 'forbidden':
      return {
        id,
        heading: NOT_SAVED,
        body: (
          <>
            {CHECKLIST_FORBIDDEN.before}
            <em>{CHECKLIST_FORBIDDEN.label}</em>
            {CHECKLIST_FORBIDDEN.after}
          </>
        ),
      };
    case 'session-ended':
      return null;
    case 'unreachable':
      return { id, heading: COULD_NOT_REACH, body: CHECKLIST_INTERRUPTED.body };
    case 'server-error':
      return { id, heading: WENT_WRONG, body: CHECKLIST_INTERRUPTED.body };
  }
}

/**
 * The permission checklist (§5.15; round-6 §2b), props only. Groups the served catalogue by module in the sidebar's
 * order; each box's refusal comes only from served `sod_rows` through `completesSplit` (principle 2 (b)). Nothing here
 * reads a role name or a permission-code literal: built-in is the served `is_system`, the margin banner is the served
 * `margin_exposure` of the role as last read or saved (R4-4 L-10), and companions, ceilings and the owner marker are
 * the server's to refuse after the click (banner **Not saved**, server text).
 *
 * State is the ticked set, keyed by the role it was edited from: a new role, or the same role with new codes (a re-read
 * after a save), resets it in render (RED M-8's derived reset) — an unchanged re-read keeps the edits.
 */
export function PermissionChecklist({ role, permissions, canEdit, save, fallbackFocusRef }: PermissionChecklistProps) {
  const [saved, setSaved] = useState<{ for: Role; role: Role } | null>(null);
  // The role as last read or saved. A save's 200 counts until the caller passes a re-read role.
  const current = saved && saved.for === role ? saved.role : role;
  const key = keyOf(current);
  const savedCodes = current.permission_codes;

  const [edit, setEdit] = useState<{ key: string; ticked: Set<string> } | null>(null);
  const ticked = useMemo(() => (edit && edit.key === key ? edit.ticked : new Set(savedCodes)), [edit, key, savedCodes]);
  const [leftOut, setLeftOut] = useState<{ key: string; byModule: Record<string, string> }>({ key: '', byModule: {} });
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const noticeHeading = useRef<HTMLParagraphElement>(null);
  // ux-confirm-b4.md §2.6: the after-save line. Written after the h1 focus, in a layout effect gated on a generation.
  const savedStatus = useLiveRegion();
  const [savedNote, setSavedNote] = useState<{ gen: number; withMargin: boolean } | null>(null);
  // Scoped by useId (b4-final-red L-4): two checklists on one page never share an id.
  const uid = useId();
  const builtInLineId = `${uid}-role-${role.id}-built-in`;

  // The served catalogue goes UNCHANGED to grouping, the group tick and every box (b4-step4-gaps.md Revision 2, G-c):
  // behaviour follows served `sod_rows`, never whether `copy/sod.ts` has a clause. An id with no clause still refuses its
  // box, keeps its marker and is left out by a group tick; only its reason line's wording falls back (PermissionGroup).
  const catalogue = permissions;
  const groups = useMemo(() => groupPermissions(catalogue, MODULE_ORDER, CODE_ORDER), [catalogue]);
  const descriptions = useMemo(() => new Map(permissions.map((p) => [p.code, p.description])), [permissions]);
  const labelOf = (code: string) => PERMISSION_LABELS[code]?.label ?? descriptions.get(code) ?? code;

  // Layout effect: focus moves in the commit that shows the banner, never a task later (b4-step3-progress.md "Flake").
  const noticeId = notice?.id ?? null;
  useLayoutEffect(() => {
    if (noticeId !== null) noticeHeading.current?.focus();
  }, [noticeId]);
  const savedGen = savedNote?.gen ?? null;
  useLayoutEffect(() => {
    if (savedGen === null || !savedNote) return;
    fallbackFocusRef.current?.focus();
    savedStatus.announce(CHECKLIST.savedAnnouncement(savedNote.withMargin));
    // Runs once per successful save only.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [savedGen]);

  const mode: GroupMode = !canEdit ? 'read' : current.is_system ? 'built-in' : 'edit';
  const editable = mode === 'edit';
  const inCatalogue = permissions.filter((p) => ticked.has(p.code)).length;
  const changes = changeCount(savedCodes, ticked);
  const dirty = editable && changes > 0;

  const write = (next: Set<string>, byModule: Record<string, string> = {}) => {
    setEdit({ key, ticked: next });
    setLeftOut({ key, byModule });
    // §2.6 (M-4): the saved sentence never stays beside a dirty bar or after Discard.
    savedStatus.clear();
  };
  const onToggle = (code: string, next: boolean) => {
    if (!editable || busy) return;
    const out = new Set(ticked);
    if (next) out.add(code);
    else out.delete(code);
    write(out);
  };
  const onToggleGroup = (module: string, codes: readonly string[], next: boolean) => {
    if (!editable || busy) return;
    const result = toggleGroup(codes, next, ticked, catalogue);
    write(result.ticked, result.leftOut.length > 0 ? { [module]: CHECKLIST.leftOut(formatList(result.leftOut.map(labelOf))) } : {});
  };
  const discard = () => {
    write(new Set(savedCodes));
    setNotice(null);
  };
  const onSave = async () => {
    if (busy) return;
    // §2.6 (M-4): empty at save start, so a second save in a row is announced too.
    savedStatus.clear();
    const marginBefore = current.margin_exposure;
    setBusy(true);
    let result: SaveRoleResult;
    try {
      result = await save([...ticked].sort());
    } catch {
      result = { kind: 'unreachable' };
    }
    setBusy(false);
    if (result.kind === 'saved') {
      setSaved({ for: role, role: result.role });
      setNotice(null);
      setLeftOut({ key: '', byModule: {} });
      setSavedNote((cur) => ({ gen: (cur?.gen ?? 0) + 1, withMargin: !marginBefore && result.role.margin_exposure }));
      return;
    }
    const next = noticeFor(result, (notice?.id ?? 0) + 1);
    if (next) setNotice(next);
  };

  const leftOutOf = (module: string) => (leftOut.key === key ? (leftOut.byModule[module] ?? '') : '');

  return (
    <div className={styles.checklist}>
      <div className={styles.header}>
        {current.is_system ? (
          <>
            <Chip tone="neutral">{CHECKLIST.builtInChip}</Chip>
            <p id={builtInLineId} className={styles.builtInLine}>
              {CHECKLIST.builtInLine}
            </p>
          </>
        ) : null}
        <span className={styles.total}>{CHECKLIST.headerCount(inCatalogue, permissions.length)}</span>
      </div>

      {notice ? (
        <Banner tone="danger" live="none" heading={notice.heading} ref={noticeHeading}>
          <p>{notice.body}</p>
        </Banner>
      ) : null}
      {current.margin_exposure ? <Banner tone="warning" live="none" heading={CHECKLIST.margin} /> : null}

      <div className={styles.groups}>
        {groups.map((g) => (
          <PermissionGroup
            key={g.module}
            heading={headingOf(g.module)}
            permissions={g.permissions}
            catalogue={catalogue}
            ticked={ticked}
            mode={mode}
            builtInLineId={builtInLineId}
            labelOf={labelOf}
            onToggle={onToggle}
            onToggleGroup={(next) => onToggleGroup(g.module, g.permissions.map((p) => p.code), next)}
            leftOutMessage={leftOutOf(g.module)}
          />
        ))}
      </div>

      {dirty ? (
        <div className={styles.dirtyBar}>
          <span className={styles.changeCount}>{plural(changes, CHECKLIST.changes)}</span>
          <Button onClick={discard}>{CHECKLIST.discard}</Button>
          <Button variant="primary" loading={busy} onClick={() => void onSave()}>
            {CHECKLIST.save}
          </Button>
        </div>
      ) : null}
      {canEdit ? <LiveRegion message={savedStatus.message} className={styles.status} /> : null}
    </div>
  );
}
