'use client';

import Link from 'next/link';
import { Button } from '@/components/ui/Button';
import { DateTime } from '@/components/ui/DateTime';
import { GOODS_RECEIPTS_LIST, GOODS_RECEIPTS_PAGING } from '@/copy/purchasing';
import type { GoodsReceipt } from '@/lib/api/purchasing';
import type { PageRequest, Read } from '@/lib/bff/reads';
import { loadReceipts, type ReceiptsLoad } from '@/lib/purchasing/load';
import { useViewerTimeZone } from '../BffProvider';
import { ArchivableList, type Column, type EmptyState, type PagedLoad, type Paging } from '../ArchivableList';
import listStyles from '../Lists.module.css';
import { GoodsReceiptsDenied } from './GoodsReceiptStates';

const asPaged = (r: ReceiptsLoad): PagedLoad<GoodsReceipt> => r;
/** The register's read: no fixed filter (Revision 10 R10-4: only the order page's section carries one). */
const load = async (read: Read, _archived: boolean, page: PageRequest, signal: AbortSignal) => asPaged(await loadReceipts(read, page, signal));
const SEARCH = { label: GOODS_RECEIPTS_LIST.searchFor, placeholder: GOODS_RECEIPTS_LIST.searchPlaceholder, hint: GOODS_RECEIPTS_LIST.searchHint };

/**
 * One receipt row's columns (UX B-9), shared by the register and the order page's Receipts section (the same `purchasing`
 * family, R10-2 as amended by A-3: the one part that crosses the two route folders): Receipt (its number, a link to `/goods-receipts/[id]` only for a GetGoodsReceipt holder) · Purchase order
 * (the register only: its number, a link only for a GetPurchaseOrder holder — viewer reads receipts without orders) ·
 * Received (date and time, the viewer's zone) · Received by (`received_by.display_name`, or *Not recorded*) · Lines (a
 * count of the served lines, never a quantity sum). No cost: a receipt serves none (UX D-6).
 */
export function receiptColumns({ timeZone, canOpenReceipt, canOpenOrder, withOrder }: { timeZone: string; canOpenReceipt: boolean; canOpenOrder: boolean; withOrder: boolean }): Column<GoodsReceipt>[] {
  const receipt: Column<GoodsReceipt> = { header: GOODS_RECEIPTS_LIST.receipt, cell: (r) => (canOpenReceipt ? <Link href={`/goods-receipts/${r.id}`}>{r.receipt_number}</Link> : r.receipt_number) };
  const order: Column<GoodsReceipt> = { header: GOODS_RECEIPTS_LIST.purchaseOrder, cell: (r) => (canOpenOrder ? <Link href={`/purchase-orders/${r.purchase_order.id}`}>{r.purchase_order.order_number}</Link> : r.purchase_order.order_number) };
  const received: Column<GoodsReceipt> = { header: GOODS_RECEIPTS_LIST.received, cell: (r) => <DateTime value={r.received_at} form="date-time" timeZone={timeZone} /> };
  const receivedBy: Column<GoodsReceipt> = { header: GOODS_RECEIPTS_LIST.receivedBy, cell: (r) => r.received_by?.display_name ?? GOODS_RECEIPTS_LIST.receivedByUnknown };
  const lines: Column<GoodsReceipt> = { header: GOODS_RECEIPTS_LIST.lines, cell: (r) => String(r.lines.length) };
  return withOrder ? [receipt, order, received, receivedBy, lines] : [receipt, received, receivedBy, lines];
}

/**
 * The register's paging (UX §2; R8-1 item 3 as amended: the words follow the served order): receipt number, so Previous ·
 * Next; the page sentence names its first and last receipt numbers. The register's only — the order page's section,
 * served newest received first, has its own (`ORDER_RECEIPTS_PAGING` in `purchase-orders/[id]/ReceiptsSection.tsx`, A-3).
 */
export const RECEIPTS_PAGING: Paging<GoodsReceipt> = {
  pager: () => GOODS_RECEIPTS_PAGING.pager,
  noMore: () => GOODS_RECEIPTS_PAGING.noMore,
  pageShown: (rows, q) => {
    const sentence = GOODS_RECEIPTS_PAGING.liveRange(rows.length, rows[0]!.receipt_number, rows[rows.length - 1]!.receipt_number);
    return q !== '' ? `${sentence} ${GOODS_RECEIPTS_LIST.searched(q)}` : sentence;
  },
};

/**
 * The register's empty states (UX B-9; R1a-1 row 8). A search with no match: `noMatch(q)` with Clear search. An empty
 * workspace (`tenant_has_rows` false): `noneYet` in its GR-1 form — slot 1 only, **no link** (final RED L-3 Note A: the
 * *Go to purchase orders* link arrives with its reason at GR-2). The register is the whole live set, so an empty first page
 * of a workspace that has receipts cannot occur: null.
 */
export const registerEmpty: EmptyState = ({ q, tenantHasRows, clearSearch }) => {
  if (q !== '') {
    return {
      heading: GOODS_RECEIPTS_LIST.noMatch(q),
      actions: (
        <Button size="sm" onClick={clearSearch}>
          {GOODS_RECEIPTS_LIST.clearSearch}
        </Button>
      ),
    };
  }
  return tenantHasRows === false ? { heading: GOODS_RECEIPTS_LIST.noneYet } : null;
};

/**
 * `/goods-receipts` (UX B-9; purchase-orders-plan.md §2.7; Revision 10 R10-3). Paged and searched by the server (R8-1), the
 * served order (receipt number, highest first) named by the caption above the rows. **No Archived view** — a receipt is
 * never deleted (class `none`, TB-3 "absent — not disabled") — so no view control renders and no read sends `archived`;
 * no fixed filter (R10-4). Nothing is written to the address: a reload opens page 1.
 */
export function GoodsReceiptsList({ initial, canOpenReceipt, canOpenOrder }: { initial: ReceiptsLoad; canOpenReceipt: boolean; canOpenOrder: boolean }) {
  const timeZone = useViewerTimeZone();
  const columns = () => receiptColumns({ timeZone, canOpenReceipt, canOpenOrder, withOrder: true });
  return (
    <ArchivableList<GoodsReceipt>
      paging={RECEIPTS_PAGING}
      initial={asPaged(initial)}
      load={load}
      search={SEARCH}
      columns={columns}
      copy={{ heading: GOODS_RECEIPTS_LIST.heading, archived: '', loadFailed: GOODS_RECEIPTS_LIST.loadFailed, shown: (n, q) => GOODS_RECEIPTS_LIST.shown(n, q) }}
      empty={registerEmpty}
      // R10-1: a caption naming the served order goes through `notice`.
      notice={() => <p className={listStyles.muted}>{GOODS_RECEIPTS_LIST.caption}</p>}
      denied={<GoodsReceiptsDenied />}
    />
  );
}
