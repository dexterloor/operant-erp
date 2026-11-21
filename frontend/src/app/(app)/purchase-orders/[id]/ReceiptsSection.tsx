'use client';

import { useId } from 'react';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { GOODS_RECEIPTS_FOR_ORDER_PAGING, GOODS_RECEIPTS_LIST, PURCHASING_FORBIDDEN } from '@/copy/purchasing';
import type { GoodsReceipt } from '@/lib/api/purchasing';
import type { PageRequest, Read } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { loadReceipts, type ReceiptsLoad } from '@/lib/purchasing/load';
import { ArchivableList, type EmptyState, type PagedLoad, type Paging } from '../../ArchivableList';
import { useViewerTimeZone } from '../../BffProvider';
import { receiptColumns } from '../../goods-receipts/GoodsReceiptsList';
import listStyles from '../../Lists.module.css';

export interface ReceiptsSectionProps {
  /** The order's id: the list's one fixed filter, `purchase_order_id` (Revision 10 R10-4). */
  orderId: string;
  /** The order's number, for the section heading (`forOrder`). */
  orderNumber: string;
  /** ListGoodsReceipts' first page for this order, read on the server (a failed read is the section's own region error). */
  initial: ReceiptsLoad;
  /** Holds GetGoodsReceipt's codes (ST-4c): each receipt number is a link to its page only then. */
  canOpenReceipt: boolean;
  /** The page's CURRENT status is `cancelled` (UX §4): the empty sentence follows it, so a cancel on the page switches it. */
  cancelled: boolean;
}

const asPaged = (r: ReceiptsLoad): PagedLoad<GoodsReceipt> => r;

/**
 * The section's paging (UX §2; R8-1 item 3 as amended): served newest received first, so Newer · Older — Older sends
 * `next_cursor`. The section's only, so it lives here (A-3: a part only one folder uses lives in that folder). No search,
 * so the page sentence never names one.
 */
const ORDER_RECEIPTS_PAGING: Paging<GoodsReceipt> = {
  pager: () => GOODS_RECEIPTS_FOR_ORDER_PAGING.pager,
  noMore: () => GOODS_RECEIPTS_FOR_ORDER_PAGING.noMore,
  pageShown: (rows) => GOODS_RECEIPTS_FOR_ORDER_PAGING.liveRange(rows.length, rows[0]!.receipt_number, rows[rows.length - 1]!.receipt_number),
};

/**
 * The order page's Receipts section (purchase-orders-plan.md §2.2 "Data", §2.7; GR-1; UX B-9 `forOrder` / `forOrderEmpty`;
 * GR-1b: `gr-1-followups-ux.md` §2/§4, `gr-1-followups-arch.md` items 3–4, as corrected by `gr-1-followups-red.md`): the
 * same receipt rows as the register, for this order only — Receipt · Received · Received by · Lines (the order column would
 * repeat the page) — paged inside the page (R10-4: receipts accumulate on a partly delivered order), newest received first
 * as served and as the caption says. **One fixed filter** (Revision 10 R10-4): every request of this list — the first (on
 * the server), a page press, a Retry — carries `purchase_order_id` = this order's id; it is never written to the address
 * (the section has no address writer) and never changes with a view switch. **No Archived view** (R10-3: no control, never
 * `archived`) and no search. **One region:** the heading goes through the core's `header` (the core's section is named by
 * it, the `LocationsSection` shape), and is repeated in `denied`, which replaces the whole list on a 403, so the
 * lost-permission sentence stays under the heading. The page renders the section only on a placed order (never a draft:
 * nothing can be received on one) for a ListGoodsReceipts holder, whose first page the server read (A-20: absent and not
 * fetched otherwise). Its empty sentence follows the page's current status (`cancelled`).
 */
export function ReceiptsSection({ orderId, orderNumber, initial, canOpenReceipt, cancelled }: ReceiptsSectionProps) {
  const timeZone = useViewerTimeZone();
  const headingId = useId();
  const fixed = { name: 'purchase_order_id', value: orderId } as const;
  const load = async (read: Read, _archived: boolean, page: PageRequest, signal: AbortSignal) => asPaged(await loadReceipts(read, page, signal, fixed));
  const viewCode = gateCode('ListGoodsReceipts');
  /** One order's receipts are never searched here; whatever reaches the empty state, it is the order's own (none). */
  const empty: EmptyState = () => ({ heading: cancelled ? GOODS_RECEIPTS_LIST.forOrderEmptyCancelled : GOODS_RECEIPTS_LIST.forOrderEmpty });
  const heading = (
    <h2 id={headingId} tabIndex={-1}>
      {GOODS_RECEIPTS_LIST.forOrder(orderNumber)}
    </h2>
  );
  return (
    <ArchivableList<GoodsReceipt>
      paging={ORDER_RECEIPTS_PAGING}
      initial={asPaged(initial)}
      load={load}
      search={false}
      columns={() => receiptColumns({ timeZone, canOpenReceipt, canOpenOrder: false, withOrder: false })}
      copy={{ heading: null, archived: '', loadFailed: GOODS_RECEIPTS_LIST.loadFailed, shown: (n) => GOODS_RECEIPTS_LIST.shown(n, '') }}
      headingId={headingId}
      header={() => heading}
      // R10-1 / A-6: the section's live caption goes through the one `notice` slot, as the register's does.
      notice={() => <p className={listStyles.muted}>{GOODS_RECEIPTS_LIST.forOrderCaption}</p>}
      empty={empty}
      denied={
        <>
          {heading}
          <p>
            {PURCHASING_FORBIDDEN.before}
            <em>{(viewCode !== null ? PERMISSION_LABELS[viewCode]?.label : undefined) ?? ''}</em>
            {PURCHASING_FORBIDDEN.after}
          </p>
        </>
      }
    />
  );
}
