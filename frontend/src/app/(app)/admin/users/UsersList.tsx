'use client';

import Link from 'next/link';
import { DateTime } from '@/components/ui/DateTime';
import { PERSON, USERS_LIST } from '@/copy/people';
import type { User } from '@/lib/api/users';
import type { Read } from '@/lib/bff/reads';
import { formatList } from '@/lib/format/lists';
import { loadUsers, type UsersLoad } from '@/lib/people/load';
import { useViewerTimeZone } from '../../BffProvider';
import { ArchivableList, type Column, type ListLoad } from '../ArchivableList';
import { PeopleDenied } from './PeopleStates';

export { SEARCH_SETTLE_MS } from '../ArchivableList';

const COPY = { ...USERS_LIST, noneLive: null };

/** The server's `q` is a case-insensitive prefix of the email (UserReader.php); the client lower-cases the same way. */
const matches = (u: User, lowerQ: string) => u.email.toLowerCase().startsWith(lowerQ);
const asList = (r: UsersLoad): ListLoad<User> => (r.kind === 'ok' ? { kind: 'ok', items: r.users } : r);
const load = async (read: Read, archived: boolean, signal: AbortSignal) => asList(await loadUsers(read, archived, signal));

export interface UsersListProps {
  /** The first render's ListUsers answer (live view). A 401 and a 403 never reach here: the server page handles them. */
  initial: UsersLoad;
  viewerId: string;
}

/**
 * `/admin/users` (ux-copy-b3b.md C5-1): Name · Email · Roles · Status live; Name · Email · Deleted on archived (a deleted
 * person holds no roles, E-15g). The live view has no empty state: the viewer is always a live row.
 */
export function UsersList({ initial, viewerId }: UsersListProps) {
  const timeZone = useViewerTimeZone();
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
  return <ArchivableList initial={asList(initial)} load={load} matches={matches} columns={columns} copy={COPY} denied={<PeopleDenied />} />;
}
