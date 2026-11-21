import type { Metadata } from 'next';
import { notFound, redirect } from 'next/navigation';
import { cache } from 'react';
import { PURCHASE_ORDERS_LIST, RECEIVE } from '@/copy/purchasing';
import { isRecordId } from '@/lib/bff/paths';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadLines, loadLocationChoices, loadPurchaseOrder } from '@/lib/purchasing/load';
import { isReceivable } from '@/lib/purchasing/results';
import { currentViewer, sessionEndedPath } from '../../../currentViewer';
import { PurchaseOrdersDenied, RECEIVE_GATES, ReceiveDenied } from '../../PurchaseOrderStates';
import { ReceivePage } from './ReceivePage';

// `/purchase-orders/[id]/receive` (GR-2; purchase-orders-plan.md §2.6, Revision 1 R1-3; UX B-8 / R1-B `RECEIVE`; PO D-12).
// The gates, from the contract, in order: GetPurchaseOrder first (it also gates the lines read), then `RECEIVE_GATES`
// (CreateGoodsReceipt, ListStorageLocations), each refused with ST-4b naming its permission. Then GetPurchaseOrder over the
// server transport, once per request (shared with the title): 401 → the session-ended notice; 403 → ST-4b; 404 or a
// non-record id → ST-5 (the order's `not-found.tsx`); any other failure throws to the error page. An order nothing can be
// received on (a draft, received in full, cancelled, deleted) shows the page's no-form state (`nothingToReceive`) with a
// link back. Otherwise its live lines and its warehouse's LIVE locations are read, in that order: 401 → the session-ended
// notice; a 403 of the locations read → ST-4b for ListStorageLocations; any other failure of either throws to the error
// page (no row can be received without both), and so does a lines read that is not the whole set (gr-2-red.md L-2). A warehouse
// with no live location shows the page's no-locations state (M-1). Never a stock figure (G-47).
export const dynamic = 'force-dynamic';

type Params = { params: Promise<{ id: string }> };

const orderFor = cache(async (id: string) => {
  const { session } = await currentViewer(sessionEndedPath(`/purchase-orders/${encodeURIComponent(id)}/receive`));
  return loadPurchaseOrder(serverRead(session), id);
});

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { id } = await params;
  const { viewer } = await currentViewer(sessionEndedPath(`/purchase-orders/${encodeURIComponent(id)}/receive`));
  if (!isRecordId(id) || !viewerMay('GetPurchaseOrder', viewer.permissionCodes)) return { title: PURCHASE_ORDERS_LIST.heading(false) };
  const o = await orderFor(id);
  return { title: o.kind === 'ok' ? RECEIVE.heading(o.order.order_number) : PURCHASE_ORDERS_LIST.heading(false) };
}

export default async function Page({ params }: Params) {
  const { id } = await params;
  const { session, viewer } = await currentViewer(sessionEndedPath(`/purchase-orders/${encodeURIComponent(id)}/receive`));
  const back = sessionEndedPath(`/purchase-orders/${encodeURIComponent(id)}/receive`);
  const codes = viewer.permissionCodes;
  if (!viewerMay('GetPurchaseOrder', codes)) return <PurchaseOrdersDenied />;
  const missing = RECEIVE_GATES.find((operation) => !viewerMay(operation, codes));
  if (missing !== undefined) return <ReceiveDenied operation={missing} />;
  if (!isRecordId(id)) notFound();
  const first = await orderFor(id);
  if (first.kind === 'session-ended') redirect(back);
  if (first.kind === 'forbidden') return <PurchaseOrdersDenied />;
  if (first.kind === 'not-found') notFound();
  if (first.kind !== 'ok') throw new Error('The purchase order could not be read.');
  if (!isReceivable(first.order)) return <ReceivePage order={first.order} lines={[]} locations={[]} />;
  const lines = await loadLines(serverRead(session), id);
  if (lines.kind === 'session-ended') redirect(back);
  if (lines.kind === 'forbidden') return <ReceiveDenied operation="ListPurchaseOrderLines" />;
  // gr-2-red.md L-2: the lines read is whole today (no cursor served); a page that is not the whole set throws rather than
  // offering part of the order as if it were all of it.
  if (lines.kind !== 'ok' || lines.nextCursor !== null) throw new Error("The purchase order's lines could not be read.");
  const locations = await loadLocationChoices(serverRead(session), first.order.warehouse.id);
  if (locations.kind === 'session-ended') redirect(back);
  if (locations.kind === 'forbidden') return <ReceiveDenied operation="ListStorageLocations" />;
  if (locations.kind !== 'ok') throw new Error("The warehouse's locations could not be read.");
  return <ReceivePage order={first.order} lines={lines.lines} locations={locations.locations} />;
}
