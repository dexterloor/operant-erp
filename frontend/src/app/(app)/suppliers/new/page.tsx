import type { Metadata } from 'next';
import { NEW_SUPPLIER } from '@/copy/suppliers';
import { viewerMay } from '@/lib/bff/surface';
import { currentViewer, sessionEndedPath } from '../../currentViewer';
import { SupplierCreateDenied, SuppliersDenied } from '../SupplierStates';
import { NewSupplierPage } from './NewSupplierPage';

// `/suppliers/new` (suppliers-ux-copy.md Revision 1 — FINAL S-2; suppliers-plan.md §R1-4 S-2). Two gates, from the
// contract: `GetSupplier` first (the warehouse 4.3 RED M-1 precedent: a creator who cannot view suppliers would land on
// ST-4b right after a successful create), then `CreateSupplier`. 401 → the session-ended notice; 403 → ST-4b. No first read.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: NEW_SUPPLIER.heading };

export default async function Page() {
  const { viewer } = await currentViewer(sessionEndedPath('/suppliers/new'));
  if (!viewerMay('GetSupplier', viewer.permissionCodes)) return <SuppliersDenied />;
  if (!viewerMay('CreateSupplier', viewer.permissionCodes)) return <SupplierCreateDenied />;
  return <NewSupplierPage />;
}
