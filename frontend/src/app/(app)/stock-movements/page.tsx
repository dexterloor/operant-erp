import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { STOCK_MOVEMENTS_LIST } from '@/copy/stock';
import type { StockMovementItemReference } from '@/lib/api/stock';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { addressedItem, loadItemName, loadMovements } from '@/lib/stock/load';
import { currentViewer, sessionEndedPath } from '../currentViewer';
import { StockMovementsDenied, StockMovementsUnknownItem } from './StockMovementStates';
import { StockMovementsList } from './StockMovementsList';

// `/stock-movements` (stock-plan.md §2.1, §2.3; UX B-3, B-4, Part C; b4-architecture.md Revision 11 R11-2). The gate is the
// contract's (`ListStockMovements`). The address may name one item (`?inventory_item_id=`, the served name); the page
// applies stock-st1-red.md M-1's order exactly:
//   1. a value that is not one record id → the unknown-item state, and nothing is read;
//   2. the list's first page (no cursor, no search, the filter when named); 401 → the session-ended notice WITH the query
//      (so the filter survives sign-in); 403 → ST-4b; any other failure → the list's region error (item name null);
//   3. rows → the item is named from the first row (every row is that item); no other read;
//   4. empty and the workspace has no movement → `noneYet` (with the way back when filtered); no item read;
//   5. empty, the workspace has movements, filtered, GetItem held → the item's own narrow read: ok → named; 404 → the
//      unknown-item state; 401 → the session-ended notice; anything else → unnamed;
//   6. empty, filtered, no GetItem → unnamed.
// The list is keyed by the filter (R11-2): a navigation to another address mounts a new list with its own first page.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: STOCK_MOVEMENTS_LIST.heading };

export default async function Page({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const query = await searchParams;
  const { session, viewer } = await currentViewer(sessionEndedPath('/stock-movements', query));
  const codes = viewer.permissionCodes;
  if (!viewerMay('ListStockMovements', codes)) return <StockMovementsDenied />;
  const addressed = addressedItem(query);
  if (addressed.kind === 'invalid') return <StockMovementsUnknownItem />;
  const itemId = addressed.kind === 'item' ? addressed.id : null;
  const read = serverRead(session);
  const first = await loadMovements(read, { q: '', cursor: null }, undefined, itemId !== null ? { name: 'inventory_item_id', value: itemId } : undefined);
  if (first.kind === 'session-ended') redirect(sessionEndedPath('/stock-movements', query));
  if (first.kind === 'forbidden') return <StockMovementsDenied />;
  let itemName: Pick<StockMovementItemReference, 'sku' | 'name'> | null = null;
  if (itemId !== null && first.kind === 'ok') {
    const row = first.items[0];
    if (row !== undefined) itemName = { sku: row.inventory_item.sku, name: row.inventory_item.name };
    else if (first.tenantHasRows && viewerMay('GetItem', codes)) {
      const named = await loadItemName(read, itemId);
      if (named.kind === 'session-ended') redirect(sessionEndedPath('/stock-movements', query));
      if (named.kind === 'not-found') return <StockMovementsUnknownItem />;
      if (named.kind === 'ok') itemName = { sku: named.item.sku, name: named.item.name };
    }
  }
  return (
    <StockMovementsList
      key={itemId ?? 'all'}
      initial={first}
      itemId={itemId}
      itemName={itemName}
      gates={{ goodsReceipt: viewerMay('GetGoodsReceipt', codes), movement: viewerMay('GetStockMovement', codes) }}
      canOpenItem={viewerMay('GetItem', codes)}
      canListOrders={viewerMay('ListPurchaseOrders', codes)}
    />
  );
}
