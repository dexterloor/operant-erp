import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { ROLES_LIST } from '@/copy/roles';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadRoles } from '@/lib/permissions/load';
import { currentViewer, sessionEndedPath } from '../../currentViewer';
import { RolesDenied } from './RoleStates';
import { RolesList } from './RolesList';

// `/admin/roles` (b3b-design.md §10, §10b; ux-copy-b3b.md C7-1). The gate is the contract's; the first read runs the
// pure loader over the server transport. 401 → the session-ended notice; 403 → ST-4b; any other failure renders the
// list's region error (first load) with Retry.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: ROLES_LIST.heading };

/** The address names the view (b3b-followup C-4): exactly `?archived=true` is the Archived view, anything else live. */
export default async function Page({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const query = await searchParams;
  const { session, viewer } = await currentViewer(sessionEndedPath('/admin/roles', query));
  const archived = query.archived === 'true';
  if (!viewerMay('ListRoles', viewer.permissionCodes)) return <RolesDenied />;
  const first = await loadRoles(serverRead(session), archived);
  if (first.kind === 'session-ended') redirect(sessionEndedPath('/admin/roles', query));
  if (first.kind === 'forbidden') return <RolesDenied />;
  return <RolesList initial={first} initialArchived={archived} />;
}
