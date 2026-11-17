import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { PURCHASE_ORDERS_LIST } from '@/copy/purchasing';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { addressedView, loadPurchaseOrders } from '@/lib/purchasing/load';
import { currentViewer, sessionEndedPath } from '../currentViewer';
import { NEW_ORDER_GATES, PurchaseOrdersDenied } from './PurchaseOrderStates';
import { PurchaseOrdersList } from './PurchaseOrdersList';

// `/purchase-orders` (purchase-orders-ux-copy.md R1-B / R1a-1 row 1; purchase-orders-plan.md §2.1, §4 PO-1; Revision 8
// R8-1, R8-2; Revision 10 R10-1). The gate is the contract's; the first read is the first page of the view and segment
// the address names (no search, no cursor: cursors and `q` are never in the address, R8-1 item 6), run by the pure
// loader over the server transport. 401 → the session-ended notice; 403 → ST-4b; any other failure renders the list's
// region error (first load) with Retry. From PO-3 the list offers New purchase order / Create purchase order to a viewer
// who would reach `/purchase-orders/new` (GetPurchaseOrder and `NEW_ORDER_GATES`), so a link never leads to ST-4b.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: PURCHASE_ORDERS_LIST.heading(false) };

export default async function Page({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const query = await searchParams;
  const { session, viewer } = await currentViewer(sessionEndedPath('/purchase-orders', query));
  if (!viewerMay('ListPurchaseOrders', viewer.permissionCodes)) return <PurchaseOrdersDenied />;
  const { archived, segment } = addressedView(query);
  const first = await loadPurchaseOrders(serverRead(session), archived, { q: '', cursor: null }, undefined, archived ? undefined : segment);
  if (first.kind === 'session-ended') redirect(sessionEndedPath('/purchase-orders', query));
  if (first.kind === 'forbidden') return <PurchaseOrdersDenied />;
  const canCreate = viewerMay('GetPurchaseOrder', viewer.permissionCodes) && NEW_ORDER_GATES.every((operation) => viewerMay(operation, viewer.permissionCodes));
  return <PurchaseOrdersList initial={first} initialArchived={archived} initialSegment={segment} canCreate={canCreate} />;
}
