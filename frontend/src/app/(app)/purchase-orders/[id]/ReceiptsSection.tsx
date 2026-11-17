'use client';

import { useId } from 'react';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { GOODS_RECEIPTS_LIST, PURCHASING_FORBIDDEN } from '@/copy/purchasing';
import type { GoodsReceipt } from '@/lib/api/purchasing';
import type { PageRequest, Read } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { loadReceipts, type ReceiptsLoad } from '@/lib/purchasing/load';
import { ArchivableList, type EmptyState, type PagedLoad } from '../../ArchivableList';
import { useViewerTimeZone } from '../../BffProvider';
import { receiptColumns, RECEIPTS_PAGING } from '../../goods-receipts/GoodsReceiptsList';

export interface ReceiptsSectionProps {
  /** The order's id: the list's one fixed filter, `purchase_order_id` (Revision 10 R10-4). */
  orderId: string;
  /** The order's number, for the section heading (`forOrder`). */
  orderNumber: string;
  /** ListGoodsReceipts' first page for this order, read on the server (a failed read is the section's own region error). */
  initial: ReceiptsLoad;
  /** Holds GetGoodsReceipt's codes (ST-4c): each receipt number is a link to its page only then. */
  canOpenReceipt: boolean;
}

const asPaged = (r: ReceiptsLoad): PagedLoad<GoodsReceipt> => r;
/** One order's receipts are never searched here; whatever reaches the empty state, it is the order's own (none yet). */
const empty: EmptyState = () => ({ heading: GOODS_RECEIPTS_LIST.forOrderEmpty });

/**
 * The order page's Receipts section (purchase-orders-plan.md §2.2 "Data", §2.7; GR-1; UX B-9 `forOrder` / `forOrderEmpty`):
 * the same receipt rows as the register, for this order only — Receipt · Received · Received by · Lines (the order column
 * would repeat the page) — paged inside the page (R10-4: receipts accumulate on a partly delivered order), newest received
 * first as served. **One fixed filter** (Revision 10 R10-4): every request of this list — the first (on the server), a page
 * press, a Retry — carries `purchase_order_id` = this order's id; it is never written to the address (the section has no
 * address writer) and never changes with a view switch. **No Archived view** (R10-3: no control, never `archived`) and no
 * search. The page renders the section only for a ListGoodsReceipts holder, whose first page the server read (A-20: absent
 * and not fetched otherwise). A 403 later → the lost-permission sentence with the register's view label, under the heading.
 */
export function ReceiptsSection({ orderId, orderNumber, initial, canOpenReceipt }: ReceiptsSectionProps) {
  const timeZone = useViewerTimeZone();
  const headingId = useId();
  const fixed = { name: 'purchase_order_id', value: orderId } as const;
  const load = async (read: Read, _archived: boolean, page: PageRequest, signal: AbortSignal) => asPaged(await loadReceipts(read, page, signal, fixed));
  const viewCode = gateCode('ListGoodsReceipts');
  return (
    <section aria-labelledby={headingId}>
      <h2 id={headingId} tabIndex={-1}>
        {GOODS_RECEIPTS_LIST.forOrder(orderNumber)}
      </h2>
      <ArchivableList<GoodsReceipt>
        paging={RECEIPTS_PAGING}
        initial={asPaged(initial)}
        load={load}
        search={false}
        columns={() => receiptColumns({ timeZone, canOpenReceipt, canOpenOrder: false, withOrder: false })}
        copy={{ heading: null, archived: '', loadFailed: GOODS_RECEIPTS_LIST.loadFailed, shown: (n) => GOODS_RECEIPTS_LIST.shown(n, '') }}
        headingId={headingId}
        empty={empty}
        denied={
          <p>
            {PURCHASING_FORBIDDEN.before}
            <em>{(viewCode !== null ? PERMISSION_LABELS[viewCode]?.label : undefined) ?? ''}</em>
            {PURCHASING_FORBIDDEN.after}
          </p>
        }
      />
    </section>
  );
}
