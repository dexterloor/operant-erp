import type { Metadata } from 'next';
import { notFound, redirect } from 'next/navigation';
import { cache } from 'react';
import { WAREHOUSE_PAGE, WAREHOUSES_LIST } from '@/copy/warehouses';
import { isRecordId } from '@/lib/bff/paths';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadWarehouse } from '@/lib/warehouses/load';
import { currentViewer, sessionEndedPath } from '../../currentViewer';
import { WarehousesDenied } from '../WarehouseStates';
import { WarehousePage } from './WarehousePage';

// `/warehouses/[id]` (warehouses-ux-copy.md B-3; Revision 7 A-15, A-20). First render: GetWarehouse over the server
// transport, once per request (shared with the title). `canEdit` = UpdateWarehouse's codes. 401 → the session-ended
// notice; 403 → ST-4b; 404 or a non-record id → ST-5 (`not-found.tsx`); any other failure throws to the error page.
// No Locations section, Restore or Delete yet (§2 steps 4.2, 4.4, 4.6).
export const dynamic = 'force-dynamic';

type Params = { params: Promise<{ id: string }> };

const warehouseFor = cache(async (id: string) => {
  const { session } = await currentViewer(sessionEndedPath(`/warehouses/${encodeURIComponent(id)}`));
  return loadWarehouse(serverRead(session), id);
});

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { id } = await params;
  const { viewer } = await currentViewer(sessionEndedPath(`/warehouses/${encodeURIComponent(id)}`));
  if (!isRecordId(id) || !viewerMay('GetWarehouse', viewer.permissionCodes)) return { title: WAREHOUSES_LIST.heading(false) };
  const w = await warehouseFor(id);
  return { title: w.kind === 'ok' ? WAREHOUSE_PAGE.title(w.warehouse.code, w.warehouse.name) : WAREHOUSES_LIST.heading(false) };
}

export default async function Page({ params }: Params) {
  const { id } = await params;
  const { viewer } = await currentViewer(sessionEndedPath(`/warehouses/${encodeURIComponent(id)}`));
  if (!viewerMay('GetWarehouse', viewer.permissionCodes)) return <WarehousesDenied />;
  if (!isRecordId(id)) notFound();
  const first = await warehouseFor(id);
  if (first.kind === 'session-ended') redirect(sessionEndedPath(`/warehouses/${encodeURIComponent(id)}`));
  if (first.kind === 'forbidden') return <WarehousesDenied />;
  if (first.kind === 'not-found') notFound();
  if (first.kind !== 'ok') throw new Error('The warehouse could not be read.');
  return <WarehousePage initial={first.warehouse} canEdit={viewerMay('UpdateWarehouse', viewer.permissionCodes)} />;
}
