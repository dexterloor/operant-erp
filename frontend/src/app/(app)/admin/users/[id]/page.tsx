import type { Metadata } from 'next';
import { notFound, redirect } from 'next/navigation';
import { cache } from 'react';
import { USERS_LIST } from '@/copy/people';
import { isRecordId } from '@/lib/bff/paths';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadPerson } from '@/lib/people/load';
import { currentViewer, sessionEndedPath } from '../../../currentViewer';
import { PeopleDenied } from '../PeopleStates';
import { PersonPage } from './PersonPage';

// `/admin/users/[id]` (b3b-design.md §9.1, §10; ux-copy-b3b.md C5-2). First render: GetUser over the server transport,
// once per request (shared with the title). 401 → the session-ended notice; 403 → ST-4b; 404, or an id that is not a
// record id → ST-5 (`not-found.tsx`); any other failure throws to the error page (there is no person to show).
export const dynamic = 'force-dynamic';

type Params = { params: Promise<{ id: string }> };

const personFor = cache(async (id: string) => {
  const { session } = await currentViewer();
  return loadPerson(serverRead(session), id);
});

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { id } = await params;
  const { viewer } = await currentViewer();
  if (!isRecordId(id) || !viewerMay('GetUser', viewer.permissionCodes)) return { title: USERS_LIST.heading };
  const p = await personFor(id);
  return { title: p.kind === 'ok' ? p.person.display_name : USERS_LIST.heading };
}

export default async function Page({ params }: Params) {
  const { id } = await params;
  const { viewer } = await currentViewer();
  if (!viewerMay('GetUser', viewer.permissionCodes)) return <PeopleDenied />;
  if (!isRecordId(id)) notFound();
  const first = await personFor(id);
  if (first.kind === 'session-ended') redirect(sessionEndedPath(`/admin/users/${id}`));
  if (first.kind === 'forbidden') return <PeopleDenied />;
  if (first.kind === 'not-found') notFound();
  if (first.kind !== 'ok') throw new Error('The person could not be read.');
  return <PersonPage initial={first.person} viewerId={viewer.id} canDelete={viewerMay('DeleteUser', viewer.permissionCodes)} />;
}
