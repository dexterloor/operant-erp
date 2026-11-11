import type { Metadata } from 'next';
import { NEW_ITEM } from '@/copy/items';
import { viewerMay } from '@/lib/bff/surface';
import { currentViewer, sessionEndedPath } from '../../currentViewer';
import { ItemCreateDenied, ItemsDenied } from '../ItemStates';
import { NewItemPage } from './NewItemPage';

// `/items/new` (items-ux-copy.md B-4; items-i2-design.md Revision 1 — FINAL A-8). Two gates, from the contract: `GetItem`
// first (the warehouses step-4.3 RED M-1 precedent: a creator who cannot view items would land on ST-4b right after a
// successful create), then `CreateItem`. 401 → the session-ended notice; 403 → ST-4b. No first read.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: NEW_ITEM.heading };

export default async function Page() {
  const { viewer } = await currentViewer(sessionEndedPath('/items/new'));
  if (!viewerMay('GetItem', viewer.permissionCodes)) return <ItemsDenied />;
  if (!viewerMay('CreateItem', viewer.permissionCodes)) return <ItemCreateDenied />;
  return <NewItemPage />;
}
