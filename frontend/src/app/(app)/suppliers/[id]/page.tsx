import type { Metadata } from 'next';
import { notFound, redirect } from 'next/navigation';
import { cache } from 'react';
import { SUPPLIER_PAGE, SUPPLIERS_LIST } from '@/copy/suppliers';
import { isRecordId } from '@/lib/bff/paths';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadCatalogue, loadSupplier } from '@/lib/suppliers/load';
import { currentViewer, sessionEndedPath } from '../../currentViewer';
import { SuppliersDenied } from '../SupplierStates';
import { SupplierPage } from './SupplierPage';

// `/suppliers/[id]` (suppliers-ux-copy.md Revision 1 S-1; suppliers-plan.md Revision 0 §2.2; Revision 8 R8-2; Revision 7
// A-15, A-20). First render: GetSupplier over the server transport, once per request (shared with the title).
// `canEdit` = UpdateSupplier's codes; `canRestore` = RestoreSupplier's. The catalogue section (S-3) is read and rendered
// only for a viewer holding ListSupplierProducts' codes (A-20; suppliers-plan.md §R1-2): for anyone else — the viewer
// shape, or supplier_product.edit without .view — it is absent and not fetched; its item link follows GetItem's codes. A
// 401 on that read → the session-ended notice; any other failure is the section's own state. No Delete supplier before S-7. 401 → the session-ended notice; 403 → ST-4b; 404 or a non-record id → ST-5
// (`not-found.tsx`); any other failure throws to the error page.
export const dynamic = 'force-dynamic';

type Params = { params: Promise<{ id: string }> };

const supplierFor = cache(async (id: string) => {
  const { session } = await currentViewer(sessionEndedPath(`/suppliers/${encodeURIComponent(id)}`));
  return loadSupplier(serverRead(session), id);
});

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { id } = await params;
  const { viewer } = await currentViewer(sessionEndedPath(`/suppliers/${encodeURIComponent(id)}`));
  if (!isRecordId(id) || !viewerMay('GetSupplier', viewer.permissionCodes)) return { title: SUPPLIERS_LIST.heading(false) };
  const s = await supplierFor(id);
  return { title: s.kind === 'ok' ? SUPPLIER_PAGE.title(s.supplier.code, s.supplier.name) : SUPPLIERS_LIST.heading(false) };
}

export default async function Page({ params }: Params) {
  const { id } = await params;
  const { viewer } = await currentViewer(sessionEndedPath(`/suppliers/${encodeURIComponent(id)}`));
  if (!viewerMay('GetSupplier', viewer.permissionCodes)) return <SuppliersDenied />;
  if (!isRecordId(id)) notFound();
  const first = await supplierFor(id);
  if (first.kind === 'session-ended') redirect(sessionEndedPath(`/suppliers/${encodeURIComponent(id)}`));
  if (first.kind === 'forbidden') return <SuppliersDenied />;
  if (first.kind === 'not-found') notFound();
  if (first.kind !== 'ok') throw new Error('The supplier could not be read.');
  const codes = viewer.permissionCodes;
  let catalogue = null;
  if (viewerMay('ListSupplierProducts', codes)) {
    const { session } = await currentViewer(sessionEndedPath(`/suppliers/${encodeURIComponent(id)}`));
    const initial = await loadCatalogue(serverRead(session), id);
    if (initial.kind === 'session-ended') redirect(sessionEndedPath(`/suppliers/${encodeURIComponent(id)}`));
    catalogue = { initial, canOpenItem: viewerMay('GetItem', codes) };
  }
  return <SupplierPage initial={first.supplier} canEdit={viewerMay('UpdateSupplier', codes)} canRestore={viewerMay('RestoreSupplier', codes)} catalogue={catalogue} />;
}
