import type { Metadata } from 'next';
import { notFound, redirect } from 'next/navigation';
import { cache } from 'react';
import { GOODS_RECEIPT_PAGE, GOODS_RECEIPTS_LIST } from '@/copy/purchasing';
import { isRecordId } from '@/lib/bff/paths';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadReceipt } from '@/lib/purchasing/load';
import { currentViewer, sessionEndedPath } from '../../currentViewer';
import { GoodsReceiptsDenied } from '../GoodsReceiptStates';
import { GoodsReceiptPage } from './GoodsReceiptPage';

// `/goods-receipts/[id]` (purchase-orders-plan.md §2.7 "Detail", Revision 0 §4 GR-1; UX B-10; IA L-5a: read-only). First
// render: GetGoodsReceipt over the server transport, once per request (shared with the title). The order and item links
// follow GetPurchaseOrder and GetItem (viewer holds the receipt's read without the order's). 401 → the session-ended
// notice; 403 → ST-4b; 404 or a non-record id → ST-5 (`not-found.tsx`); any other failure throws to the error page (the
// order page's rule: a record page whose one read failed has nothing to show).
export const dynamic = 'force-dynamic';

type Params = { params: Promise<{ id: string }> };

const receiptFor = cache(async (id: string) => {
  const { session } = await currentViewer(sessionEndedPath(`/goods-receipts/${encodeURIComponent(id)}`));
  return loadReceipt(serverRead(session), id);
});

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { id } = await params;
  const { viewer } = await currentViewer(sessionEndedPath(`/goods-receipts/${encodeURIComponent(id)}`));
  if (!isRecordId(id) || !viewerMay('GetGoodsReceipt', viewer.permissionCodes)) return { title: GOODS_RECEIPTS_LIST.heading };
  const r = await receiptFor(id);
  return { title: r.kind === 'ok' ? GOODS_RECEIPT_PAGE.title(r.receipt.receipt_number) : GOODS_RECEIPTS_LIST.heading };
}

export default async function Page({ params }: Params) {
  const { id } = await params;
  const { viewer } = await currentViewer(sessionEndedPath(`/goods-receipts/${encodeURIComponent(id)}`));
  const codes = viewer.permissionCodes;
  if (!viewerMay('GetGoodsReceipt', codes)) return <GoodsReceiptsDenied />;
  if (!isRecordId(id)) notFound();
  const first = await receiptFor(id);
  if (first.kind === 'session-ended') redirect(sessionEndedPath(`/goods-receipts/${encodeURIComponent(id)}`));
  if (first.kind === 'forbidden') return <GoodsReceiptsDenied />;
  if (first.kind === 'not-found') notFound();
  if (first.kind !== 'ok') throw new Error('The goods receipt could not be read.');
  return <GoodsReceiptPage receipt={first.receipt} canOpenOrder={viewerMay('GetPurchaseOrder', codes)} canOpenItem={viewerMay('GetItem', codes)} />;
}
