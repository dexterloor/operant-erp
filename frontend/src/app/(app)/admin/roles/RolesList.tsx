'use client';

import Link from 'next/link';
import { Chip } from '@/components/ui/Chip';
import { DateTime } from '@/components/ui/DateTime';
import { PERSON } from '@/copy/people';
import { CHECKLIST } from '@/copy/permissionLabels';
import { ROLES_LIST } from '@/copy/roles';
import type { Role } from '@/lib/api/roles';
import type { Read } from '@/lib/bff/reads';
import { loadRoles, type RolesLoad } from '@/lib/permissions/load';
import { useViewerTimeZone } from '../../BffProvider';
import { ArchivableList, type Column, type ListLoad } from '../../ArchivableList';
import { RolesDenied } from './RoleStates';
import styles from '../../Lists.module.css';

/** The server's `q` is a case-insensitive prefix of `role.name` (RoleReader.php); the client lower-cases the same way. */
const matches = (r: Role, lowerQ: string) => r.name.toLowerCase().startsWith(lowerQ);
const asList = (r: RolesLoad): ListLoad<Role> => (r.kind === 'ok' ? { kind: 'ok', items: r.roles } : r);
const load = async (read: Read, archived: boolean, signal: AbortSignal) => asList(await loadRoles(read, archived, signal));

/**
 * `/admin/roles` (ux-copy-b3b.md C7-1): Name · People · Permissions live (a count of holders, never names; the count of
 * the served codes — the list loads no catalogue); Name · Deleted on archived. `Built-in` comes from the served
 * `is_system`, never from a name.
 */
export function RolesList({ initial, initialArchived = false }: { initial: RolesLoad; initialArchived?: boolean }) {
  const timeZone = useViewerTimeZone();
  const name: Column<Role> = {
    header: ROLES_LIST.name,
    cell: (r) => (
      <>
        <Link href={`/admin/roles/${r.id}`}>{r.name}</Link>
        {r.is_system ? (
          <>
            {' '}
            <Chip tone="neutral">{CHECKLIST.builtInChip}</Chip>
          </>
        ) : null}
        {r.description !== '' ? <div className={styles.muted}>{r.description}</div> : null}
      </>
    ),
  };
  const columns = (archived: boolean): Column<Role>[] =>
    archived
      ? [name, { header: PERSON.deletedOnColumn, cell: (r) => (r.archived_at !== null ? <DateTime value={r.archived_at} form="date" timeZone={timeZone} /> : null) }]
      : [name, { header: ROLES_LIST.people, cell: (r) => String(r.holder_count) }, { header: ROLES_LIST.permissions, cell: (r) => String(r.permission_codes.length) }];
  return <ArchivableList initial={asList(initial)} initialArchived={initialArchived} load={load} matches={matches} columns={columns} copy={ROLES_LIST} denied={<RolesDenied />} />;
}
