'use client';

import Link from 'next/link';
import { DateTime } from '@/components/ui/DateTime';
import { reasonWords, STOCK_MOVEMENT_SOURCE, STOCK_MOVEMENTS_FILTER, STOCK_MOVEMENTS_LIST, STOCK_MOVEMENTS_PAGING } from '@/copy/stock';
import type { StockMovement, StockMovementItemReference } from '@/lib/api/stock';
import type { PageRequest, Read } from '@/lib/bff/reads';
import { formatDateTime } from '@/lib/format/dates';
import { renderSignedQuantity } from '@/lib/format/quantity';
import { loadMovements, type MovementsFilter, type MovementsLoad } from '@/lib/stock/load';
import { sourceOf, type SourceGates } from '@/lib/stock/source';
import { useViewerTimeZone } from '../BffProvider';
import { ArchivableList, type Column, type EmptyState, type PagedLoad, type Paging } from '../ArchivableList';
import listStyles from '../Lists.module.css';
import { StockMovementsDenied } from './StockMovementStates';

export interface StockMovementsListProps {
  /** `ListStockMovements`' first page, read on the server (with the filter when the address names a valid item). */
  initial: MovementsLoad;
  /** The item the address names (R11-2), or null for every movement. The page keys this list by it. */
  itemId: string | null;
  /**
   * The filtered item's SKU and name, computed by the server page (stock-st1-red.md M-1): the first row's reference when
   * the first page has rows, else the item's own read when it was allowed and answered; null otherwise (and on a failed
   * first read). The notice and the empty state read only this, never the rows.
   */
  itemName: Pick<StockMovementItemReference, 'sku' | 'name'> | null;
  /** The source links' gates, read on the server (R11-4, RED L-2): GetGoodsReceipt's and GetStockMovement's. */
  gates: SourceGates;
  /** Holds GetItem's codes (ST-4c): each row's item is a link to its page only then. */
  canOpenItem: boolean;
  /** Holds ListPurchaseOrders' codes: the empty workspace's `noneYetLink` only then (viewer gets none). */
  canListOrders: boolean;
}

const asPaged = (r: MovementsLoad): PagedLoad<StockMovement> => r;

/**
 * One movement row's columns (UX B-3): Date (DT date and time in the viewer's zone; the row's link to its page, named by
 * `rowLinkName`, for a GetStockMovement holder) · What happened (the reason's label; any other value *Other change*) · Item
 * (`{sku} — {name}`, a link only for a GetItem holder) · Location (the code alone, never a link: no warehouse is served) ·
 * Change (signed, R11-5; its heading says the figure is in the item's unit until BK-41) · Source (R11-4's one table).
 */
export function movementColumns({ timeZone, gates, canOpenItem }: { timeZone: string; gates: SourceGates; canOpenItem: boolean }): Column<StockMovement>[] {
  return [
    {
      header: STOCK_MOVEMENTS_LIST.date,
      cell: (m) => {
        if (!gates.movement) return <DateTime value={m.occurred_at} form="date-time" timeZone={timeZone} />;
        const when = formatDateTime(m.occurred_at, timeZone);
        return (
          <Link href={`/stock-movements/${m.id}`} aria-label={STOCK_MOVEMENTS_LIST.rowLinkName(when, reasonWords(m.reason).spoken, renderSignedQuantity(m.quantity), m.inventory_item.sku, m.storage_location.code)}>
            <time dateTime={m.occurred_at}>{when}</time>
          </Link>
        );
      },
    },
    { header: STOCK_MOVEMENTS_LIST.whatHappened, cell: (m) => reasonWords(m.reason).label },
    {
      header: STOCK_MOVEMENTS_LIST.item,
      cell: (m) => {
        const text = STOCK_MOVEMENTS_LIST.itemCell(m.inventory_item.sku, m.inventory_item.name);
        return canOpenItem ? <Link href={`/items/${m.inventory_item.id}`}>{text}</Link> : text;
      },
    },
    { header: STOCK_MOVEMENTS_LIST.location, cell: (m) => m.storage_location.code },
    { header: STOCK_MOVEMENTS_LIST.changeInItemUnit, cell: (m) => renderSignedQuantity(m.quantity) },
    {
      header: STOCK_MOVEMENTS_LIST.source,
      cell: (m) => {
        const s = sourceOf(m, gates);
        const word = STOCK_MOVEMENT_SOURCE[s.word];
        if (s.kind === 'text') return word;
        return (
          <Link href={s.href} aria-label={STOCK_MOVEMENTS_LIST.sourceLinkName(word, m.inventory_item.sku, formatDateTime(m.occurred_at, timeZone))}>
            {word}
          </Link>
        );
      },
    },
  ];
}

/** The filter's own line (R11-2): the item when it is named, else "one item"; and the way back to every movement. */
const filterLine = (itemName: StockMovementsListProps['itemName']) => (itemName !== null ? STOCK_MOVEMENTS_FILTER.filteredTo(itemName.sku, itemName.name) : STOCK_MOVEMENTS_FILTER.filteredUnnamed);

/**
 * `/stock-movements` (stock-plan.md §2.1; UX B-3, B-4, Part C; b4-architecture.md Revision 11 R11-2, R11-4, R11-5, R11-8).
 * Paged by the server (R8-1), newest first by business time as served (never re-sorted), named by the caption. **No
 * Archived view** (a movement is never deleted, R10-3) and **no search** (A-1). **One fixed filter** when the address names
 * an item (R10-4 / A-2; R11-2): every request of this list — the first (on the server), a page press, a Retry — carries it;
 * the list never writes the address, and the page keys this list by the filter, so a new address mounts a new list. The
 * one notice slot (A-6) holds the caption, the standing location note and, while filtered, the filter's line with **Show
 * all stock movements** (the one way back, so an empty filtered page offers it once). Empty states (TB-8, M-1's order):
 * no movement in the workspace → `noneYet` (filtered or not; its link only for a ListPurchaseOrders holder); filtered and
 * empty while the workspace has movements → `noneForItem(sku)` when the item is named, else `noneForThisItem`.
 * Limit, stated (commit RED L-5; UX `stock-st1-ux-confirm.md`): when a filtered list's first read fails, the page shows only
 * the shared load error (its heading names the cause; its sentence is "The list of stock movements couldn't be loaded."),
 * with Retry; it does not say the list is one item's and offers no Show all stock movements, because the shared list core
 * shows its notice only once a page has loaded. A Retry that succeeds brings both back; the menu's
 * Stock movements entry opens every movement.
 */
export function StockMovementsList({ initial, itemId, itemName, gates, canOpenItem, canListOrders }: StockMovementsListProps) {
  const timeZone = useViewerTimeZone();
  const fixed: MovementsFilter | undefined = itemId !== null ? { name: 'inventory_item_id', value: itemId } : undefined;
  const load = async (read: Read, _archived: boolean, page: PageRequest, signal: AbortSignal) => asPaged(await loadMovements(read, page, signal, fixed));
  const filtered = (sentence: string) => (itemId !== null ? `${sentence} ${filterLine(itemName)}` : sentence);
  const paging: Paging<StockMovement> = {
    pager: () => STOCK_MOVEMENTS_PAGING.pager,
    noMore: () => STOCK_MOVEMENTS_PAGING.noMore,
    pageShown: (rows) => {
      const first = rows[0]!;
      const newest = formatDateTime(first.occurred_at, timeZone);
      const oldest = formatDateTime(rows[rows.length - 1]!.occurred_at, timeZone);
      // DT-1 has no seconds, so the rendered strings are compared; the first row's item and location are the position cue.
      const sentence =
        rows.length === 1
          ? STOCK_MOVEMENTS_PAGING.liveOne(newest, first.inventory_item.sku, first.storage_location.code)
          : newest === oldest
            ? STOCK_MOVEMENTS_PAGING.liveRangeSameTime(rows.length, newest, first.inventory_item.sku, first.storage_location.code)
            : STOCK_MOVEMENTS_PAGING.liveRange(rows.length, newest, oldest, first.inventory_item.sku, first.storage_location.code);
      return filtered(sentence);
    },
  };
  const empty: EmptyState = ({ tenantHasRows }) => {
    if (tenantHasRows === false) return canListOrders ? { heading: STOCK_MOVEMENTS_LIST.noneYet, actions: <Link href="/purchase-orders">{STOCK_MOVEMENTS_LIST.noneYetLink}</Link> } : { heading: STOCK_MOVEMENTS_LIST.noneYet };
    // An unfiltered first page of a workspace that has movements is never empty.
    if (itemId === null) return null;
    return { heading: itemName !== null ? STOCK_MOVEMENTS_FILTER.noneForItem(itemName.sku) : STOCK_MOVEMENTS_FILTER.noneForThisItem };
  };
  return (
    <ArchivableList<StockMovement>
      paging={paging}
      initial={asPaged(initial)}
      load={load}
      search={false}
      columns={() => movementColumns({ timeZone, gates, canOpenItem })}
      copy={{ heading: STOCK_MOVEMENTS_LIST.heading, archived: '', loadFailed: STOCK_MOVEMENTS_LIST.loadFailed, shown: (n) => filtered(STOCK_MOVEMENTS_LIST.shown(n)) }}
      empty={empty}
      notice={() => (
        <>
          <p className={listStyles.muted}>{STOCK_MOVEMENTS_LIST.caption}</p>
          <p>{STOCK_MOVEMENTS_LIST.locationNote}</p>
          {itemId !== null ? (
            <p>
              {filterLine(itemName)} <Link href="/stock-movements">{STOCK_MOVEMENTS_FILTER.clear}</Link>
            </p>
          ) : null}
        </>
      )}
      footer={(rows) => (rows.length > 0 ? <p className={listStyles.footer}>{STOCK_MOVEMENTS_LIST.footer(rows.length)}</p> : null)}
      denied={<StockMovementsDenied />}
    />
  );
}
