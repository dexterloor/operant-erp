import type { Metadata } from 'next';
import { notFound, redirect } from 'next/navigation';
import { cache } from 'react';
import { PURCHASE_ORDER_PAGE, PURCHASE_ORDERS_LIST } from '@/copy/purchasing';
import { isRecordId } from '@/lib/bff/paths';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadCatalogueChoices, loadLines, loadPurchaseOrder, loadWarehouseChoices, type CatalogueChoicesLoad, type WarehouseChoicesLoad } from '@/lib/purchasing/load';
import { currentViewer, sessionEndedPath } from '../../currentViewer';
import { PurchaseOrdersDenied } from '../PurchaseOrderStates';
import { PurchaseOrderPage } from './PurchaseOrderPage';

// `/purchase-orders/[id]` (purchase-orders-plan.md §2.2, §4 PO-1; UX B-3 / R1-B, R1a-1 row 1; Revision 8 R8-2; Revision 7
// A-15, A-20). First render: GetPurchaseOrder over the server transport, once per request (shared with the title), then
// the order's live lines (ListPurchaseOrderLines, the same gate) unless the order is a deleted draft. The supplier,
// warehouse and item links follow GetSupplier, GetWarehouse and GetItem. The viewer's id comes from `currentViewer()`
// here, on the server (final RED L-2), for *Raised by*'s "(you)". 401 → the session-ended notice; 403 → ST-4b; 404 or a
// non-record id → ST-5 (`not-found.tsx`); any other failure of the order read throws to the error page (a failed lines
// read is the section's own state). The actions: Restore purchase order on a deleted draft (PO-2), offered for a
// RestorePurchaseOrder holder (a restore reads the lines in the browser only for a ListPurchaseOrderLines holder); and,
// from PO-4a, the header editor on a live draft for a viewer holding UpdatePurchaseOrder's AND ListWarehouses' codes (its
// one editable field is Deliver to, so without the warehouse read there is nothing to change). Its live warehouses are
// read here, after the lines: 401 → the session-ended notice; any other failure (403 included) is the field's own state.
// From PO-5, Add line on a live draft whose lines were read, for a viewer holding CreatePurchaseOrderLine's AND
// ListSupplierProducts' codes (the new row chooses from the order's supplier's catalogue, so without that read there is
// nothing to choose): the catalogue is read here, last; 401 → the session-ended notice; any other failure is the row's own
// state. Delete line follows DeletePurchaseOrderLine's codes (the page offers it only on a live draft).
export const dynamic = 'force-dynamic';

type Params = { params: Promise<{ id: string }> };

const orderFor = cache(async (id: string) => {
  const { session } = await currentViewer(sessionEndedPath(`/purchase-orders/${encodeURIComponent(id)}`));
  return loadPurchaseOrder(serverRead(session), id);
});

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { id } = await params;
  const { viewer } = await currentViewer(sessionEndedPath(`/purchase-orders/${encodeURIComponent(id)}`));
  if (!isRecordId(id) || !viewerMay('GetPurchaseOrder', viewer.permissionCodes)) return { title: PURCHASE_ORDERS_LIST.heading(false) };
  const o = await orderFor(id);
  return { title: o.kind === 'ok' ? PURCHASE_ORDER_PAGE.title(o.order.order_number, o.order.supplier.name) : PURCHASE_ORDERS_LIST.heading(false) };
}

export default async function Page({ params }: Params) {
  const { id } = await params;
  const { session, viewer } = await currentViewer(sessionEndedPath(`/purchase-orders/${encodeURIComponent(id)}`));
  const codes = viewer.permissionCodes;
  if (!viewerMay('GetPurchaseOrder', codes)) return <PurchaseOrdersDenied />;
  if (!isRecordId(id)) notFound();
  const first = await orderFor(id);
  if (first.kind === 'session-ended') redirect(sessionEndedPath(`/purchase-orders/${encodeURIComponent(id)}`));
  if (first.kind === 'forbidden') return <PurchaseOrdersDenied />;
  if (first.kind === 'not-found') notFound();
  if (first.kind !== 'ok') throw new Error('The purchase order could not be read.');
  let lines = null;
  // A deleted draft's lines were deleted with it (the cascade): the live list would be empty, so it is not read. Nor is
  // `?archived=true`: it serves every deleted line, also those deleted on their own, which a restore leaves deleted
  // (po-2-progress.md D-1). The restore reads the live lines that came back.
  if (first.order.archived_at === null && viewerMay('ListPurchaseOrderLines', codes)) {
    lines = await loadLines(serverRead(session), id);
    if (lines.kind === 'session-ended') redirect(sessionEndedPath(`/purchase-orders/${encodeURIComponent(id)}`));
  }
  let edit: { warehouses: WarehouseChoicesLoad } | null = null;
  if (first.order.archived_at === null && first.order.status === 'draft' && viewerMay('UpdatePurchaseOrder', codes) && viewerMay('ListWarehouses', codes)) {
    const warehouses = await loadWarehouseChoices(serverRead(session));
    if (warehouses.kind === 'session-ended') redirect(sessionEndedPath(`/purchase-orders/${encodeURIComponent(id)}`));
    edit = { warehouses };
  }
  let add: { catalogue: CatalogueChoicesLoad } | null = null;
  if (lines !== null && first.order.archived_at === null && first.order.status === 'draft' && viewerMay('CreatePurchaseOrderLine', codes) && viewerMay('ListSupplierProducts', codes)) {
    const catalogue = await loadCatalogueChoices(serverRead(session), first.order.supplier.id);
    if (catalogue.kind === 'session-ended') redirect(sessionEndedPath(`/purchase-orders/${encodeURIComponent(id)}`));
    add = { catalogue };
  }
  return <PurchaseOrderPage order={first.order} lines={lines} edit={edit} add={add} canDeleteLine={viewerMay('DeletePurchaseOrderLine', codes)} viewerId={viewer.id} canRestore={viewerMay('RestorePurchaseOrder', codes)} canReadLines={viewerMay('ListPurchaseOrderLines', codes)} canOpenSupplier={viewerMay('GetSupplier', codes)} canOpenWarehouse={viewerMay('GetWarehouse', codes)} canOpenItem={viewerMay('GetItem', codes)} />;
}
