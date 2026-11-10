import type { Metadata } from 'next';
import { notFound, redirect } from 'next/navigation';
import { cache } from 'react';
import { ITEM_PAGE, ITEMS_LIST } from '@/copy/items';
import { isRecordId } from '@/lib/bff/paths';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadItem } from '@/lib/items/load';
import { currentViewer, sessionEndedPath } from '../../currentViewer';
import { ItemsDenied } from '../ItemStates';
import { ItemPage } from './ItemPage';

// `/items/[id]` (items-ux-copy.md B-3; Revision 8 R8-2; Revision 7 A-15, A-20). First render: GetItem over the server
// transport, once per request (shared with the title). `canEdit` = UpdateItem's codes; `canRestore` = RestoreItem's;
// `canDelete` = DeleteItem's (items I-3).
// 401 → the session-ended notice; 403 → ST-4b; 404 or a non-record id → ST-5 (`not-found.tsx`); any other failure throws
// to the error page. No stock and no movements are read or linked here (G-47).
export const dynamic = 'force-dynamic';

type Params = { params: Promise<{ id: string }> };

const itemFor = cache(async (id: string) => {
  const { session } = await currentViewer(sessionEndedPath(`/items/${encodeURIComponent(id)}`));
  return loadItem(serverRead(session), id);
});

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { id } = await params;
  const { viewer } = await currentViewer(sessionEndedPath(`/items/${encodeURIComponent(id)}`));
  if (!isRecordId(id) || !viewerMay('GetItem', viewer.permissionCodes)) return { title: ITEMS_LIST.heading(false) };
  const i = await itemFor(id);
  return { title: i.kind === 'ok' ? ITEM_PAGE.title(i.item.sku, i.item.name) : ITEMS_LIST.heading(false) };
}

export default async function Page({ params }: Params) {
  const { id } = await params;
  const { viewer } = await currentViewer(sessionEndedPath(`/items/${encodeURIComponent(id)}`));
  if (!viewerMay('GetItem', viewer.permissionCodes)) return <ItemsDenied />;
  if (!isRecordId(id)) notFound();
  const first = await itemFor(id);
  if (first.kind === 'session-ended') redirect(sessionEndedPath(`/items/${encodeURIComponent(id)}`));
  if (first.kind === 'forbidden') return <ItemsDenied />;
  if (first.kind === 'not-found') notFound();
  if (first.kind !== 'ok') throw new Error('The item could not be read.');
  const codes = viewer.permissionCodes;
  return <ItemPage initial={first.item} canEdit={viewerMay('UpdateItem', codes)} canRestore={viewerMay('RestoreItem', codes)} canDelete={viewerMay('DeleteItem', codes)} />;
}
