import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { NEW_PURCHASE_ORDER } from '@/copy/purchasing';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadWarehouseChoices } from '@/lib/purchasing/load';
import { currentViewer, sessionEndedPath } from '../../currentViewer';
import { NEW_ORDER_GATES, NewPurchaseOrderDenied, PurchaseOrdersDenied } from '../PurchaseOrderStates';
import { NewPurchaseOrderPage } from './NewPurchaseOrderPage';

// `/purchase-orders/new` (purchase-orders-plan.md §2.4, Revision 0 §4 PO-3; UX B-2 `NEW_PURCHASE_ORDER`). The gates, from
// the contract, in order: `GetPurchaseOrder` first (the suppliers S-2 / warehouse 4.3 RED M-1 precedent: a creator who
// cannot open the order would land on ST-4b right after the create), then `CreatePurchaseOrder`, then the two reads the
// choices need — `ListSuppliers` and `ListWarehouses` — each refused with ST-4b naming its permission, never an empty
// choice. The one first read is the LIVE warehouses, over the server transport: 401 → the session-ended notice; 403 →
// ST-4b for ListWarehouses; any other failure reaches the page, whose Deliver to field shows its own Retry.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: NEW_PURCHASE_ORDER.heading };

export default async function Page() {
  const { session, viewer } = await currentViewer(sessionEndedPath('/purchase-orders/new'));
  if (!viewerMay('GetPurchaseOrder', viewer.permissionCodes)) return <PurchaseOrdersDenied />;
  const missing = NEW_ORDER_GATES.find((operation) => !viewerMay(operation, viewer.permissionCodes));
  if (missing !== undefined) return <NewPurchaseOrderDenied operation={missing} />;
  const warehouses = await loadWarehouseChoices(serverRead(session));
  if (warehouses.kind === 'session-ended') redirect(sessionEndedPath('/purchase-orders/new'));
  if (warehouses.kind === 'forbidden') return <NewPurchaseOrderDenied operation="ListWarehouses" />;
  return <NewPurchaseOrderPage warehouses={warehouses} />;
}
