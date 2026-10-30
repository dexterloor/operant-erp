import type { Metadata } from 'next';
import { notFound, redirect } from 'next/navigation';
import { cache } from 'react';
import { ROLES_LIST } from '@/copy/roles';
import { isRecordId } from '@/lib/bff/paths';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadRolePage } from '@/lib/permissions/load';
import { currentViewer, sessionEndedPath } from '../../../currentViewer';
import { RolesDenied } from '../RoleStates';
import { RolePage } from './RolePage';

// `/admin/roles/[id]` (b3b-design.md §9.4, §10; ux-copy-b3b.md C7-2). First render: GetRole + ListPermissions (both
// `role.view`) over the server transport, once per request (shared with the title). `canEdit` = UpdateRole's codes.
// 401 → the session-ended notice; 403 → ST-4b; 404 or a non-record id → ST-5 (`not-found.tsx`); any other failure throws
// to the error page (there is no role to show).
export const dynamic = 'force-dynamic';

type Params = { params: Promise<{ id: string }> };

const roleFor = cache(async (id: string) => {
  const { session } = await currentViewer(sessionEndedPath(`/admin/roles/${id}`));
  return loadRolePage(serverRead(session), id);
});

const mayRead = (codes: readonly string[]) => viewerMay('GetRole', codes) && viewerMay('ListPermissions', codes);

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { id } = await params;
  const { viewer } = await currentViewer(sessionEndedPath(`/admin/roles/${id}`));
  if (!isRecordId(id) || !mayRead(viewer.permissionCodes)) return { title: ROLES_LIST.heading };
  const r = await roleFor(id);
  return { title: r.kind === 'ok' ? r.role.name : ROLES_LIST.heading };
}

export default async function Page({ params }: Params) {
  const { id } = await params;
  const { viewer } = await currentViewer(sessionEndedPath(`/admin/roles/${id}`));
  if (!mayRead(viewer.permissionCodes)) return <RolesDenied />;
  if (!isRecordId(id)) notFound();
  const first = await roleFor(id);
  if (first.kind === 'session-ended') redirect(sessionEndedPath(`/admin/roles/${id}`));
  if (first.kind === 'forbidden') return <RolesDenied />;
  if (first.kind === 'not-found') notFound();
  if (first.kind !== 'ok') throw new Error('The role could not be read.');
  return <RolePage initial={{ role: first.role, permissions: first.permissions }} canEdit={viewerMay('UpdateRole', viewer.permissionCodes)} />;
}
