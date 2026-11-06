import type { Metadata } from 'next';
import { NEW_WAREHOUSE } from '@/copy/warehouses';
import { viewerMay } from '@/lib/bff/surface';
import { currentViewer, sessionEndedPath } from '../../currentViewer';
import { WarehouseCreateDenied, WarehousesDenied } from '../WarehouseStates';
import { NewWarehousePage } from './NewWarehousePage';

// `/warehouses/new` (warehouses-ux-copy.md B-4; Revision 7 §2 step 4.3). Two gates, from the contract: `GetWarehouse`
// first (step-4.3 RED M-1: a creator who cannot view warehouses would land on ST-4b right after a successful create),
// then `CreateWarehouse`. 401 → the session-ended notice; 403 → ST-4b. No first read.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: NEW_WAREHOUSE.heading };

export default async function Page() {
  const { viewer } = await currentViewer(sessionEndedPath('/warehouses/new'));
  if (!viewerMay('GetWarehouse', viewer.permissionCodes)) return <WarehousesDenied />;
  if (!viewerMay('CreateWarehouse', viewer.permissionCodes)) return <WarehouseCreateDenied />;
  return <NewWarehousePage />;
}
