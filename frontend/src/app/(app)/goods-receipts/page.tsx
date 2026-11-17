import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { GOODS_RECEIPTS_LIST } from '@/copy/purchasing';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadReceipts } from '@/lib/purchasing/load';
import { currentViewer, sessionEndedPath } from '../currentViewer';
import { GoodsReceiptsDenied } from './GoodsReceiptStates';
import { GoodsReceiptsList } from './GoodsReceiptsList';

// `/goods-receipts` (purchase-orders-plan.md §2.7, Revision 0 §4 GR-1; UX B-9 / R1a-1 row 8; Revision 8 R8-1; Revision 10
// R10-2, R10-3, R10-4). The gate is the contract's (`ListGoodsReceipts`); the first read is page 1 — no search, no cursor,
// no view (a receipt has no Archived view, R10-3) and no fixed filter (R10-4: only the order page's section carries one) —
// run by the pure loader over the server transport. Nothing here is in the address. 401 → the session-ended notice; 403 →
// ST-4b; any other failure renders the list's region error (first load) with Retry. The receipt number links to its page
// for a GetGoodsReceipt holder, the order number to the order for a GetPurchaseOrder holder (viewer holds the receipts'
// read without the orders', `SECURITY_MODEL.md` §4).
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: GOODS_RECEIPTS_LIST.heading };

export default async function Page() {
  const { session, viewer } = await currentViewer(sessionEndedPath('/goods-receipts'));
  if (!viewerMay('ListGoodsReceipts', viewer.permissionCodes)) return <GoodsReceiptsDenied />;
  const first = await loadReceipts(serverRead(session), { q: '', cursor: null });
  if (first.kind === 'session-ended') redirect(sessionEndedPath('/goods-receipts'));
  if (first.kind === 'forbidden') return <GoodsReceiptsDenied />;
  return <GoodsReceiptsList initial={first} canOpenReceipt={viewerMay('GetGoodsReceipt', viewer.permissionCodes)} canOpenOrder={viewerMay('GetPurchaseOrder', viewer.permissionCodes)} />;
}
