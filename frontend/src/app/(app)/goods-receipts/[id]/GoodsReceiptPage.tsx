'use client';

import { useId } from 'react';
import Link from 'next/link';
import { DateTime } from '@/components/ui/DateTime';
import { GOODS_RECEIPT_PAGE, GOODS_RECEIPTS_LIST, ORDER_LINES } from '@/copy/purchasing';
import type { GoodsReceipt } from '@/lib/api/purchasing';
import { renderQuantity } from '@/lib/format/quantity';
import { useViewerTimeZone } from '../../BffProvider';
import listStyles from '../../Lists.module.css';
import styles from '../../purchase-orders/PurchaseOrders.module.css';

export interface GoodsReceiptPageProps {
  /** GetGoodsReceipt on first render. */
  receipt: GoodsReceipt;
  /** Holds GetPurchaseOrder's codes (ST-4c): the order number is a link only then. */
  canOpenOrder: boolean;
  /** Holds GetItem's codes (ST-4c): each line's item is a link only then. */
  canOpenItem: boolean;
}

/**
 * The receipt page (purchase-orders-plan.md §2.7 "Detail"; UX B-10 `GOODS_RECEIPT_PAGE`; IA L-5a): read-only, no action —
 * a receipt is never changed or deleted (deletion class none), which `readOnlyNote` says. The h1 is the receipt number;
 * the facts are Purchase order (its number, a link to `/purchase-orders/[id]` only for a GetPurchaseOrder holder), Received
 * (date and time, the viewer's zone) and Received by (`received_by.display_name`, or *Not recorded*). The lines table, in
 * served order: Order line (`purchase_order_line.line_number`) · Item (`{sku} — {name}`, a link only for a GetItem holder) ·
 * Quantity (QR-2 by `renderQuantity`; its heading carries the interim *item's unit* until BK-33, final RED L-11) ·
 * Location (`storage_location.code`, never a link: locations live on the warehouse page and the receipt serves no warehouse
 * id). No cost is served or shown (UX D-6).
 */
export function GoodsReceiptPage({ receipt, canOpenOrder, canOpenItem }: GoodsReceiptPageProps) {
  const timeZone = useViewerTimeZone();
  const linesHeading = useId();
  const order = receipt.purchase_order;
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 tabIndex={-1}>{GOODS_RECEIPT_PAGE.title(receipt.receipt_number)}</h1>
      </header>
      <dl className={styles.fields}>
        <dt>{GOODS_RECEIPT_PAGE.purchaseOrder}</dt>
        <dd>{canOpenOrder ? <Link href={`/purchase-orders/${order.id}`}>{order.order_number}</Link> : order.order_number}</dd>
        <dt>{GOODS_RECEIPT_PAGE.received}</dt>
        <dd>
          <DateTime value={receipt.received_at} form="date-time" timeZone={timeZone} />
        </dd>
        <dt>{GOODS_RECEIPT_PAGE.receivedBy}</dt>
        <dd>{receipt.received_by?.display_name ?? GOODS_RECEIPTS_LIST.receivedByUnknown}</dd>
      </dl>
      <div className={styles.notes}>
        <p>{GOODS_RECEIPT_PAGE.readOnlyNote}</p>
      </div>
      <section aria-labelledby={linesHeading}>
        <h2 id={linesHeading}>{GOODS_RECEIPT_PAGE.linesHeading}</h2>
        <table className={listStyles.table}>
          <thead>
            <tr>
              <th scope="col">{GOODS_RECEIPT_PAGE.orderLine}</th>
              <th scope="col">{GOODS_RECEIPT_PAGE.item}</th>
              <th scope="col">{GOODS_RECEIPT_PAGE.quantity}</th>
              <th scope="col">{GOODS_RECEIPT_PAGE.location}</th>
            </tr>
          </thead>
          <tbody>
            {receipt.lines.map((l) => {
              const item = ORDER_LINES.itemCell(l.inventory_item.sku, l.inventory_item.name);
              return (
                <tr key={l.id}>
                  <td>{l.purchase_order_line.line_number}</td>
                  <td>{canOpenItem ? <Link href={`/items/${l.inventory_item.id}`}>{item}</Link> : item}</td>
                  <td>{renderQuantity(l.quantity)}</td>
                  <td>{l.storage_location.code}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </section>
    </section>
  );
}
