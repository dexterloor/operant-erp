'use client';

import { useCallback, useMemo, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { PermissionChecklist } from '@/components/permissions/PermissionChecklist';
import { RoleRestoreActions } from '@/components/permissions/RoleRestoreActions';
import { RegionError } from '@/components/states/PageStates';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { ROLE_PAGE } from '@/copy/roles';
import type { Permission } from '@/lib/api/permissions';
import type { Role } from '@/lib/api/roles';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { loadRole } from '@/lib/permissions/load';
import { roleRestore, roleSave } from '@/lib/permissions/wired';
import { useApiCaller, useViewerTimeZone } from '../../../BffProvider';
import { useCommittedState } from '../../../useCommittedState';
import { RoleNotFound, RolesDenied } from '../RoleStates';
import styles from '../../Lists.module.css';

export interface RolePageProps {
  /** GetRole + ListPermissions on first render. */
  initial: { role: Role; permissions: Permission[] };
  /** Holds UpdateRole's codes (from the contract): ST-4c. */
  canEdit: boolean;
  /** Holds RestoreRole's codes (from the contract): ST-4c. Without it a deleted role shows its banner and no button. */
  canRestore: boolean;
}

type Cause = 'unreachable' | 'server-error';

/**
 * The role page's container (b3b-design.md §9.4, §10; ux-copy-b3b.md C7-2). It holds the role in state and passes the
 * re-read role to the checklist without a remount. A failed re-read → the region error (focus on its heading); 403 →
 * ST-4b; 404 → ST-5. The checklist renders nothing for `session-ended`.
 *
 * A deleted role (`archived_at` set) is read-only whatever the viewer holds — the API refuses its update (409
 * `role.is_archived`) — so the checklist shows its read-only list, under a persistent banner with the deletion date
 * (b3b-followup C-7, D-28; no live role: it is page content, not an announcement). The banner and its **Restore role**
 * action are `RoleRestoreActions` (restore-role-design.md Revision 2 F-2): a restore sets the served role here, so the
 * checklist turns live without a remount. After a restore's message is on screen the router cache is refreshed once
 * (F-1 L-13, branch R: the Archived list was measured stale after Back); `useCommittedState` stays the commit signal.
 */
export function RolePage({ initial, canEdit, canRestore }: RolePageProps) {
  const caller = useApiCaller();
  const router = useRouter();
  const timeZone = useViewerTimeZone();
  const [role, setRole] = useCommittedState(initial.role);
  const [swap, setSwap] = useState<'forbidden' | 'not-found' | null>(null);
  const [rereadError, setRereadError] = useState<{ gen: number; cause: Cause } | null>(null);
  const [retrying, setRetrying] = useState(false);
  const h1 = useRef<HTMLHeadingElement>(null);
  const id = initial.role.id;

  const failed = useCallback((f: ReadFailure) => {
    if (f === 'session-ended') return;
    if (f === 'forbidden' || f === 'not-found') {
      setSwap(f);
      return;
    }
    // After the checklist's own after-save focus, so the region error's heading takes it.
    setTimeout(() => setRereadError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: f })), 0);
  }, []);

  const save = useMemo(() => roleSave({ caller, id, setRole, onRereadFailed: failed }), [caller, id, setRole, failed]);
  const restoreRole = useMemo(() => roleRestore({ caller, id, setRole, onRereadFailed: failed }), [caller, id, setRole, failed]);
  // Cache invalidation only (never the commit signal): the Archived list must not show this role after Back.
  const refreshAfterRestore = useCallback(() => router.refresh(), [router]);

  const retry = async () => {
    if (retrying) return;
    setRetrying(true);
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadRole(readOf(caller), id, d.signal);
    d.cancel();
    setRetrying(false);
    if (r.kind === 'ok') {
      await setRole(r.role);
      setRereadError(null);
      h1.current?.focus();
    } else failed(r.kind);
  };

  if (swap === 'forbidden') return <RolesDenied />;
  if (swap === 'not-found') return <RoleNotFound />;

  return (
    <section className={styles.page}>
      <header className={styles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {role.name}
        </h1>
      </header>
      {rereadError ? (
        <RegionError key={rereadError.gen} heading={rereadError.cause === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={ROLE_PAGE.rereadFailed} retrying={retrying} onRetry={() => void retry()} />
      ) : null}
      <RoleRestoreActions role={role} canRestore={canRestore} restoreRole={restoreRole} fallbackFocusRef={h1} timeZone={timeZone} onRestored={refreshAfterRestore} />
      <PermissionChecklist role={role} permissions={initial.permissions} canEdit={canEdit && role.archived_at === null} save={save} fallbackFocusRef={h1} />
    </section>
  );
}
