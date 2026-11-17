'use client';

import Link from 'next/link';
import { Banner } from '@/components/ui/Banner';
import { Chip } from '@/components/ui/Chip';
import { DateTime } from '@/components/ui/DateTime';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { PURCHASE_ORDER_PAGE, statusLabel } from '@/copy/purchasing';
import type { PurchaseOrder } from '@/lib/api/purchasing';
import { renderMoney } from '@/lib/format/money';
import type { LinesLoad } from '@/lib/purchasing/load';
import { useViewerTimeZone } from '../../BffProvider';
import listStyles from '../../Lists.module.css';
import styles from '../PurchaseOrders.module.css';
import { LinesSection } from './LinesSection';

export interface PurchaseOrderPageProps {
  /** GetPurchaseOrder on first render. */
  order: PurchaseOrder;
  /** ListPurchaseOrderLines on first render, or null for a deleted draft (its lines went with it; not read). */
  lines: LinesLoad | null;
  /** The signed-in person's id, from `currentViewer()` on the server (final RED L-2): *Raised by* reads "(you)" only when it equals `created_by.id`. */
  viewerId: string;
  /** Holds GetSupplier's / GetWarehouse's / GetItem's codes (ST-4c): the name is a link only then. */
  canOpenSupplier: boolean;
  canOpenWarehouse: boolean;
  canOpenItem: boolean;
}

/**
 * The order page, read-only (purchase-orders-plan.md §2.2, §4 PO-1; UX B-3 / R1-B `PURCHASE_ORDER_PAGE`, R1a-1 row 1).
 * PO-1 ships no action, so every status shows the same read-only page: the title `{number} — {supplier}`, the status
 * badge, and the facts — Supplier (a link for a GetSupplier holder), Deliver to (the warehouse; a link for a GetWarehouse
 * holder), Currency, Expected delivery (DT-3, or *Not set*), Ordered on (once ordered, DT-3), Raised by
 * (`created_by.display_name`; *"{name} (you)"* when it is the viewer, display only — it pre-empts nothing, ST-4f; *Not
 * recorded* when null) and Total (`renderMoney`, the order's currency). Then the status's note (a live draft: "This is a
 * draft."; ordered / partly received: the PO-1 `orderedNote` and `noSupplierContact`; received / cancelled: `closedNote`)
 * and the lines section. A deleted draft shows the ST-9 banner *"This draft was deleted on {date}."* with no Restore
 * (PO-2 adds it) and no lines section: its lines were deleted with it, so the live list would read "no lines yet",
 * which is false (a deviation recorded in po-1-progress.md, owed to UX). The page owns one region (the lines Retry's
 * count speaks there).
 */
export function PurchaseOrderPage({ order, lines, viewerId, canOpenSupplier, canOpenWarehouse, canOpenItem }: PurchaseOrderPageProps) {
  const timeZone = useViewerTimeZone();
  const { message, announce } = useLiveRegion();
  const archived = order.archived_at !== null;
  const raisedBy = order.created_by === null ? PURCHASE_ORDER_PAGE.raisedByUnknown : order.created_by.id === viewerId ? PURCHASE_ORDER_PAGE.raisedByYou(order.created_by.display_name) : order.created_by.display_name;
  const s = order.status;
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 tabIndex={-1}>{PURCHASE_ORDER_PAGE.title(order.order_number, order.supplier.name)}</h1>
        <Chip>{statusLabel(s)}</Chip>
      </header>
      {/* Mounted from first render, above every branch below, so it never arrives already speaking. */}
      <LiveRegion message={message} visuallyHidden />
      {order.archived_at !== null ? (
        <Banner tone="neutral" heading={<>{PURCHASE_ORDER_PAGE.deletedBefore}<DateTime value={order.archived_at} form="date" timeZone={timeZone} />{PURCHASE_ORDER_PAGE.deletedAfter}</>} />
      ) : null}
      <dl className={styles.fields}>
        <dt>{PURCHASE_ORDER_PAGE.supplier}</dt>
        <dd>{canOpenSupplier ? <Link href={`/suppliers/${order.supplier.id}`}>{order.supplier.name}</Link> : order.supplier.name}</dd>
        <dt>{PURCHASE_ORDER_PAGE.deliverTo}</dt>
        <dd>{canOpenWarehouse ? <Link href={`/warehouses/${order.warehouse.id}`}>{order.warehouse.name}</Link> : order.warehouse.name}</dd>
        <dt>{PURCHASE_ORDER_PAGE.currency}</dt>
        <dd>{order.currency_code}</dd>
        <dt>{PURCHASE_ORDER_PAGE.expected}</dt>
        <dd>{order.expected_at !== null ? <DateTime value={order.expected_at} form="date-time" timeZone={timeZone} /> : PURCHASE_ORDER_PAGE.notSet}</dd>
        {order.ordered_at !== null ? (
          <>
            <dt>{PURCHASE_ORDER_PAGE.orderedOn}</dt>
            <dd>
              <DateTime value={order.ordered_at} form="date-time" timeZone={timeZone} />
            </dd>
          </>
        ) : null}
        <dt>{PURCHASE_ORDER_PAGE.raisedBy}</dt>
        <dd>{raisedBy}</dd>
        <dt>{PURCHASE_ORDER_PAGE.total}</dt>
        <dd>{renderMoney(order.total_amount, order.currency_code)}</dd>
      </dl>
      {!archived ? (
        <div className={styles.notes}>
          {s === 'draft' ? <p>{PURCHASE_ORDER_PAGE.draftNote}</p> : null}
          {s === 'ordered' || s === 'partially_received' ? (
            <>
              <p>{PURCHASE_ORDER_PAGE.orderedNote}</p>
              <p>{PURCHASE_ORDER_PAGE.noSupplierContact}</p>
            </>
          ) : null}
          {s === 'received' ? <p>{PURCHASE_ORDER_PAGE.closedReceived}</p> : null}
          {s === 'cancelled' ? <p>{PURCHASE_ORDER_PAGE.closedCancelled}</p> : null}
        </div>
      ) : null}
      {lines !== null ? <LinesSection orderId={order.id} initial={lines} status={s} currency={order.currency_code} canOpenItem={canOpenItem} announce={announce} /> : null}
    </section>
  );
}
