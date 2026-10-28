import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { USERS_LIST } from '@/copy/people';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadUsers } from '@/lib/people/load';
import { currentViewer, sessionEndedPath } from '../../currentViewer';
import { PeopleDenied } from './PeopleStates';
import { UsersList } from './UsersList';

// `/admin/users` (b3b-design.md §10 "First render", §10b; ux-copy-b3b.md C5-1). The gate is the contract's; the first
// read runs the pure loader over the server transport. 401 → the session-ended notice; 403 → ST-4b; any other failure
// renders the list's region error (first load) with Retry.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: USERS_LIST.heading };

export default async function Page() {
  const { session, viewer } = await currentViewer();
  if (!viewerMay('ListUsers', viewer.permissionCodes)) return <PeopleDenied />;
  const first = await loadUsers(serverRead(session), false);
  if (first.kind === 'session-ended') redirect(sessionEndedPath('/admin/users'));
  if (first.kind === 'forbidden') return <PeopleDenied />;
  return <UsersList initial={first} viewerId={viewer.id} />;
}
