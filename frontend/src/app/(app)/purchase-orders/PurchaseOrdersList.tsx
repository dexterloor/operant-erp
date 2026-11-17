'use client';

import Link from 'next/link';
import { Button } from '@/components/ui/Button';
import { DateTime } from '@/components/ui/DateTime';
import { PURCHASE_ORDERS_LIST, PURCHASE_ORDERS_PAGING, statusLabel } from '@/copy/purchasing';
import { isPurchaseOrderSegment, PURCHASE_ORDER_SEGMENTS, type PurchaseOrder, type PurchaseOrderSegment } from '@/lib/api/purchasing';
import type { PageRequest, Read } from '@/lib/bff/reads';
import { formatDate } from '@/lib/format/dates';
import { renderMoney } from '@/lib/format/money';
import { loadPurchaseOrders, type PurchaseOrdersLoad } from '@/lib/purchasing/load';
import { useViewerTimeZone } from '../BffProvider';
import { ArchivableList, ArchivedToggle, type Column, type EmptyState, type HeaderView, type PagedLoad, type Paging } from '../ArchivableList';
import { useSegmentAddress } from '../useSegmentAddress';
import listStyles from '../Lists.module.css';
import { PurchaseOrdersDenied } from './PurchaseOrderStates';

const DEFAULT_SEGMENT: PurchaseOrderSegment = PURCHASE_ORDER_SEGMENTS[0];
/** The core hands a segment back as a string; it is always one of ours (the keys below), else the default. */
const segmentOf = (s: string | undefined): PurchaseOrderSegment => (isPurchaseOrderSegment(s) ? s : DEFAULT_SEGMENT);
const asPaged = (r: PurchaseOrdersLoad): PagedLoad<PurchaseOrder> => r;
/** Revision 10 R10-1: the core passes the live segment after `signal` (none in the Archived view). */
const load = async (read: Read, archived: boolean, page: PageRequest, signal: AbortSignal, segment?: string) =>
  asPaged(await loadPurchaseOrders(read, archived, page, signal, archived || segment === undefined ? undefined : segmentOf(segment)));
/** The server's one Archived order, newest deleted first, named by the caption; archived rows struck through (ST-9a). */
const ARCHIVED_VIEW = { order: 'as-served', caption: PURCHASE_ORDERS_LIST.archivedCaption, rows: 'struck' } as const;
const SEARCH = { label: PURCHASE_ORDERS_LIST.searchFor, placeholder: PURCHASE_ORDERS_LIST.searchPlaceholder, hint: PURCHASE_ORDERS_LIST.searchHint };
const SEGMENTS = { keys: PURCHASE_ORDER_SEGMENTS, initial: DEFAULT_SEGMENT, label: (key: string) => PURCHASE_ORDERS_LIST.segment(segmentOf(key)), name: PURCHASE_ORDERS_LIST.segmentsName };

/**
 * ST-9a's rows per segment (UX B-1, R1-B, R1a-1 row 1). A search with no match: `noMatch` / `noArchivedMatch` with Clear
 * search. The Archived view: `noneArchived` (PO-1 form). A live segment of a workspace with no order at all
 * (`tenant_has_rows` false): `noneYet` (PO-1 form). Otherwise the segment's own sentence. Nothing here offers an action
 * but Clear search: PO-1 ships none (creating arrives at PO-3).
 */
export const emptyFor: EmptyState = ({ archived, q, tenantHasRows, clearSearch }, segment) => {
  if (q !== '') {
    return {
      heading: archived ? PURCHASE_ORDERS_LIST.noArchivedMatch(q) : PURCHASE_ORDERS_LIST.noMatch(q, segmentOf(segment)),
      actions: (
        <Button size="sm" onClick={clearSearch}>
          {PURCHASE_ORDERS_LIST.clearSearch}
        </Button>
      ),
    };
  }
  if (archived) return { heading: PURCHASE_ORDERS_LIST.noneArchived };
  if (tenantHasRows === false) return { heading: PURCHASE_ORDERS_LIST.noneYet };
  const s = segmentOf(segment);
  return { heading: s === 'open' ? PURCHASE_ORDERS_LIST.emptyOpen : s === 'draft' ? PURCHASE_ORDERS_LIST.emptyDraft : s === 'received' ? PURCHASE_ORDERS_LIST.emptyReceived : PURCHASE_ORDERS_LIST.emptyCancelled };
};

/**
 * **Archived** in the live view (the core renders the segment control before it, R10-1); **Back to purchase orders**
 * replaces it in the Archived view, and only once a view has loaded (R3-1). No New purchase order before PO-3.
 */
export function header({ archived, busy, loaded, switchTo, viewControlRef }: HeaderView) {
  if (!archived) return <ArchivedToggle ref={viewControlRef} archived={false} busy={busy} onClick={() => switchTo(true)} label={PURCHASE_ORDERS_LIST.archived} />;
  if (!loaded) return null;
  return (
    <Button ref={viewControlRef} loading={busy} onClick={() => switchTo(false)}>
      {PURCHASE_ORDERS_LIST.backToList}
    </Button>
  );
}

/**
 * The paged list's words (R8-1; UX B-1 `PURCHASE_ORDERS_PAGING`). The position cue: a live segment (key-ordered) names
 * its first and last order numbers; the Archived view (date-ordered) names its deletion dates, DT-1 in the viewer's zone,
 * and its first order number as an identifier.
 */
export function pagingFor(timeZone: string): Paging<PurchaseOrder> {
  return {
    pager: PURCHASE_ORDERS_PAGING.pager,
    noMore: (archived) => PURCHASE_ORDERS_PAGING.noMore(archived),
    pageShown: (rows, q, archived) => {
      const first = rows[0]!;
      const last = rows[rows.length - 1]!;
      let sentence: string;
      if (!archived) sentence = PURCHASE_ORDERS_PAGING.liveRange(rows.length, first.order_number, last.order_number);
      else {
        const newest = formatDate(first.archived_at ?? '', timeZone);
        const oldest = formatDate(last.archived_at ?? '', timeZone);
        sentence = rows.length === 1 ? PURCHASE_ORDERS_PAGING.archivedOne(first.order_number, newest) : newest === oldest ? PURCHASE_ORDERS_PAGING.archivedOneDate(rows.length, newest, first.order_number) : PURCHASE_ORDERS_PAGING.archivedRange(rows.length, newest, oldest, first.order_number);
      }
      return q !== '' ? `${sentence} ${PURCHASE_ORDERS_PAGING.searched(q)}` : sentence;
    },
  };
}

/**
 * `/purchase-orders` (UX B-1 / R1-B; purchase-orders-plan.md §2.1; Revision 10 R10-1). Paged and searched by the server
 * (R8-1), one live segment at a time — Open (the default) · Draft · Received · Cancelled, sent as `status` — or the
 * Archived view (`archived=true`, no `status`). The order number is the row's link. The segment and the view live in the
 * address (`useSegmentAddress`: `?status=` for a segment other than Open, `?archived=true`), never a cursor or `q`. No
 * sort control and no supplier filter (§0): each segment's served default order, named by its caption.
 */
export function PurchaseOrdersList({ initial, initialArchived = false, initialSegment = DEFAULT_SEGMENT }: { initial: PurchaseOrdersLoad; initialArchived?: boolean; initialSegment?: PurchaseOrderSegment }) {
  const timeZone = useViewerTimeZone();
  const onViewLoaded = useSegmentAddress(DEFAULT_SEGMENT);
  const date = (value: string | null, none: string) => (value !== null ? <DateTime value={value} form="date" timeZone={timeZone} /> : none);
  const order: Column<PurchaseOrder> = { header: PURCHASE_ORDERS_LIST.order, cell: (o) => <Link href={`/purchase-orders/${o.id}`}>{o.order_number}</Link> };
  const supplier: Column<PurchaseOrder> = { header: PURCHASE_ORDERS_LIST.supplier, cell: (o) => o.supplier.name };
  const warehouse: Column<PurchaseOrder> = { header: PURCHASE_ORDERS_LIST.warehouse, cell: (o) => o.warehouse.name };
  const status: Column<PurchaseOrder> = { header: PURCHASE_ORDERS_LIST.status, cell: (o) => statusLabel(o.status) };
  const orderedOn: Column<PurchaseOrder> = { header: PURCHASE_ORDERS_LIST.orderedOn, cell: (o) => date(o.ordered_at, PURCHASE_ORDERS_LIST.notOrdered) };
  const expected: Column<PurchaseOrder> = { header: PURCHASE_ORDERS_LIST.expected, cell: (o) => date(o.expected_at, PURCHASE_ORDERS_LIST.notSet) };
  const total: Column<PurchaseOrder> = { header: PURCHASE_ORDERS_LIST.total, cell: (o) => renderMoney(o.total_amount, o.currency_code) };
  const deletedOn: Column<PurchaseOrder> = { header: PURCHASE_ORDERS_LIST.deletedOn, cell: (o) => date(o.archived_at, '') };
  const columns = (archived: boolean, segment?: string): Column<PurchaseOrder>[] => {
    if (archived) return [order, supplier, deletedOn];
    const s = segmentOf(segment);
    if (s === 'open') return [order, supplier, warehouse, status, orderedOn, expected, total];
    if (s === 'draft') return [order, supplier, warehouse, expected, total];
    return [order, supplier, warehouse, orderedOn, total];
  };
  return (
    <ArchivableList
      paging={pagingFor(timeZone)}
      segments={{ ...SEGMENTS, initial: initialSegment }}
      initial={asPaged(initial)}
      initialArchived={initialArchived}
      onViewLoaded={onViewLoaded}
      load={load}
      search={SEARCH}
      columns={columns}
      copy={PURCHASE_ORDERS_LIST}
      header={header}
      empty={emptyFor}
      archivedView={ARCHIVED_VIEW}
      // R10-1: a live segment's caption (its served order) is rendered through `notice`; Archived has the table's caption.
      notice={(archived, _bannerUp, segment) => (archived ? null : <p className={listStyles.muted}>{PURCHASE_ORDERS_LIST.caption(segmentOf(segment))}</p>)}
      footer={(rows) => (rows.length > 0 ? <p className={listStyles.footer}>{PURCHASE_ORDERS_LIST.footer(rows.length)}</p> : null)}
      denied={<PurchaseOrdersDenied />}
    />
  );
}
