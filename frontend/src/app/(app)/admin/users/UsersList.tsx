'use client';

import Link from 'next/link';
import { DateTime } from '@/components/ui/DateTime';
import { PERSON, USERS_LIST } from '@/copy/people';
import type { User } from '@/lib/api/users';
import type { Read } from '@/lib/bff/reads';
import { formatList } from '@/lib/format/lists';
import { loadUsers, type UsersLoad } from '@/lib/people/load';
import { useViewerTimeZone } from '../../BffProvider';
import { ArchivableList, searchEmptyStates, type Column, type ListLoad } from '../../ArchivableList';
import { useArchivedAddress } from '../../useArchivedAddress';
import { PeopleDenied } from './PeopleStates';

export { SEARCH_SETTLE_MS } from '../../ArchivableList';

/** C5-1: the live view has no empty state (the viewer is always a live row). */
const EMPTY = searchEmptyStates({ ...USERS_LIST, noneLive: null });
const ARCHIVED_VIEW = { order: 'newest-deleted-first', caption: USERS_LIST.archivedOrder, rows: 'plain' } as const;

/** The server's `q` is a case-insensitive prefix of the email (UserReader.php); the client lower-cases the same way. */
const matches = (u: User, lowerQ: string) => u.email.toLowerCase().startsWith(lowerQ);
const asList = (r: UsersLoad): ListLoad<User> => (r.kind === 'ok' ? { kind: 'ok', items: r.users } : r);
const load = async (read: Read, archived: boolean, signal: AbortSignal) => asList(await loadUsers(read, archived, signal));

export interface UsersListProps {
  /** The first render's ListUsers answer (the view `initialArchived` names). A 401 and a 403 never reach here: the server page handles them. */
  initial: UsersLoad;
  /** The view `initial` holds, from the address (b3b-followup C-4). */
  initialArchived?: boolean;
  viewerId: string;
}

/**
 * `/admin/users` (ux-copy-b3b.md C5-1): Name · Email · Roles · Status live; Name · Email · Deleted on archived (a deleted
 * person holds no roles, E-15g). The live view has no empty state: the viewer is always a live row.
 */
export function UsersList({ initial, initialArchived = false, viewerId }: UsersListProps) {
  const timeZone = useViewerTimeZone();
  const onViewLoaded = useArchivedAddress();
  const columns = (archived: boolean): Column<User>[] => [
    { header: USERS_LIST.name, cell: (u) => <Link href={`/admin/users/${u.id}`}>{u.id === viewerId ? PERSON.ownName(u.display_name) : u.display_name}</Link> },
    { header: USERS_LIST.email, cell: (u) => u.email },
    ...(archived
      ? [{ header: PERSON.deletedOnColumn, cell: (u: User) => (u.archived_at !== null ? <DateTime value={u.archived_at} form="date" timeZone={timeZone} /> : null) }]
      : [
          { header: USERS_LIST.roles, cell: (u: User) => (u.roles.length > 0 ? formatList(u.roles.map((r) => r.name)) : USERS_LIST.noRoles) },
          { header: USERS_LIST.status, cell: (u: User) => (u.is_active ? USERS_LIST.active : USERS_LIST.switchedOff) },
        ]),
  ];
  return (
    <ArchivableList
      initial={asList(initial)}
      initialArchived={initialArchived}
      onViewLoaded={onViewLoaded}
      load={load}
      matches={matches}
      columns={columns}
      copy={USERS_LIST}
      empty={EMPTY}
      archivedView={ARCHIVED_VIEW}
      denied={<PeopleDenied />}
    />
  );
}
