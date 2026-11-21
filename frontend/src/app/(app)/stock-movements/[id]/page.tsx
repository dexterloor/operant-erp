import type { Metadata } from 'next';
import { notFound, redirect } from 'next/navigation';
import { cache } from 'react';
import { reasonWords, STOCK_MOVEMENT_PAGE, STOCK_MOVEMENTS_LIST } from '@/copy/stock';
import { isRecordId } from '@/lib/bff/paths';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadMovement, loadReceiptNumber } from '@/lib/stock/load';
import { sourceOf } from '@/lib/stock/source';
import { currentViewer, sessionEndedPath } from '../../currentViewer';
import { StockMovementsDenied } from '../StockMovementStates';
import { StockMovementPage } from './StockMovementPage';

// `/stock-movements/[id]` (stock-plan.md §2.2; UX B-6; b4-architecture.md Revision 11 R11-3: a read-only record page). A
// value that is not a record id is ST-5 with no read. First render: GetStockMovement over the server transport, once per
// request (shared with the title). 401 → the session-ended notice; 403 → ST-4b; 404 → ST-5 (`not-found.tsx`); any other
// failure throws to the error page (a record page whose one read failed has nothing to show). At most ONE secondary read,
// fail-soft, and only where the source is a link the viewer may follow (R11-4): a goods receipt's number (GetGoodsReceipt),
// or a transfer leg's partner, for its location (GetStockMovement). A secondary 401 ends the session; any other failure
// leaves the plain words (UX S-13), never an error. Nothing else is read.
export const dynamic = 'force-dynamic';

type Params = { params: Promise<{ id: string }> };

const movementFor = cache(async (id: string) => {
  const { session } = await currentViewer(sessionEndedPath(`/stock-movements/${encodeURIComponent(id)}`));
  return loadMovement(serverRead(session), id);
});

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { id } = await params;
  const { viewer } = await currentViewer(sessionEndedPath(`/stock-movements/${encodeURIComponent(id)}`));
  if (!isRecordId(id) || !viewerMay('GetStockMovement', viewer.permissionCodes)) return { title: STOCK_MOVEMENTS_LIST.heading };
  const m = await movementFor(id);
  return { title: m.kind === 'ok' ? STOCK_MOVEMENT_PAGE.title(reasonWords(m.movement.reason).label, m.movement.inventory_item.sku) : STOCK_MOVEMENTS_LIST.heading };
}

export default async function Page({ params }: Params) {
  const { id } = await params;
  const { session, viewer } = await currentViewer(sessionEndedPath(`/stock-movements/${encodeURIComponent(id)}`));
  const codes = viewer.permissionCodes;
  if (!viewerMay('GetStockMovement', codes)) return <StockMovementsDenied />;
  if (!isRecordId(id)) notFound();
  const first = await movementFor(id);
  if (first.kind === 'session-ended') redirect(sessionEndedPath(`/stock-movements/${encodeURIComponent(id)}`));
  if (first.kind === 'forbidden') return <StockMovementsDenied />;
  if (first.kind === 'not-found') notFound();
  if (first.kind !== 'ok') throw new Error('The stock movement could not be read.');
  const movement = first.movement;
  const gates = { goodsReceipt: viewerMay('GetGoodsReceipt', codes), movement: viewerMay('GetStockMovement', codes) };
  const source = sourceOf(movement, gates);
  let receiptNumber: string | null = null;
  let partnerLocation: string | null = null;
  // The source is a link only with its gate and a record id (R11-4), so the read below is always one the viewer may make.
  if (source.kind === 'link' && movement.source_id !== null) {
    const read = serverRead(session);
    if (source.word === 'goodsReceipt') {
      const r = await loadReceiptNumber(read, movement.source_id);
      if (r.kind === 'session-ended') redirect(sessionEndedPath(`/stock-movements/${encodeURIComponent(id)}`));
      if (r.kind === 'ok') receiptNumber = r.receiptNumber;
    } else {
      const p = await loadMovement(read, movement.source_id);
      if (p.kind === 'session-ended') redirect(sessionEndedPath(`/stock-movements/${encodeURIComponent(id)}`));
      if (p.kind === 'ok') partnerLocation = p.movement.storage_location.code;
    }
  }
  return (
    <StockMovementPage
      movement={movement}
      source={source}
      receiptNumber={receiptNumber}
      partnerLocation={partnerLocation}
      canOpenItem={viewerMay('GetItem', codes)}
      canListMovements={viewerMay('ListStockMovements', codes)}
    />
  );
}
