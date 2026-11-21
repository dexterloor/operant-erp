'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ReceiveForm } from '@/components/purchasing/ReceiveForm';
import { RECEIVE, statusSpoken } from '@/copy/purchasing';
import type { GoodsReceipt, PurchaseOrder, PurchaseOrderLine } from '@/lib/api/purchasing';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { loadLocationChoices, type LocationChoice } from '@/lib/purchasing/load';
import { isReceivable } from '@/lib/purchasing/results';
import { receiptCreate } from '@/lib/purchasing/wired';
import { leaveArrivalMessage } from '../../../arrivalMessage';
import { useApiCaller } from '../../../BffProvider';
import listStyles from '../../../Lists.module.css';

/**
 * The receive page (UX B-8; plan §2.6; S-11). It wires Record receipt (`receiptCreate`, created ONCE per page instance in a
 * `useState` initializer, so its held Idempotency-Key lives exactly as long as this page — GR-2 H-1, RED L-4) and the
 * locations' re-read after R2 over the browser transport, each under its deadline, and hands both to the props-only
 * `ReceiveForm`. On a 201 it leaves `after(receipt, n, warehouse)` — plus `afterStatus` from the order's re-read, dropped
 * when that re-read failed — for the order's page (A-19 item 7) and navigates there with `router.push`; that page speaks it
 * after `RouteFocus` has moved focus to its `h1`. When the order can no longer be received on (a refusal's re-read, R6), the
 * page shows its no-form state: `orderChanged` above `nothingToReceive`, focus on the `h1` — or, when that refusal was an
 * idempotency-key conflict (an earlier attempt from this page was recorded, H-1 d2), `earlierRecordedNoForm` in its place. When the warehouse has no live
 * location (first read, or a re-read after R2), the page shows `noLocations` and Back to {number} (gr-2-red.md M-1). A 201
 * answering after the page unmounted leaves its sentence but does not navigate (L-8).
 */
export function ReceivePage({ order: initial, lines, locations }: { order: PurchaseOrder; lines: PurchaseOrderLine[]; locations: LocationChoice[] }) {
  const caller = useApiCaller();
  const router = useRouter();
  const [order, setOrder] = useState(initial);
  const [changed, setChanged] = useState<'changed' | 'earlier-recorded' | null>(null);
  // gr-2-red.md M-1: the order's warehouse has no live location (first read, or a re-read after R2 that lists none).
  const [noLocations, setNoLocations] = useState(locations.length === 0);
  // gr-2-red.md L-8: a 201 that answers after the person left this page leaves its sentence but pulls nobody back.
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);
  // GR-2 H-1 (RED L-4): a `useState` initializer, never `useMemo` (a cache React may drop, which would mint a new key).
  const [record] = useState(() => receiptCreate(caller, initial.id));
  const reloadLocations = useCallback(async () => {
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    try {
      return await loadLocationChoices(readOf(caller), initial.warehouse.id, d.signal);
    } finally {
      d.cancel();
    }
  }, [caller, initial.warehouse.id]);
  const onRecorded = useCallback(
    (receipt: GoodsReceipt, reread: PurchaseOrder | null, n: number) => {
      const href = `/purchase-orders/${initial.id}`;
      const head = RECEIVE.after(receipt.receipt_number, n, initial.warehouse.name);
      leaveArrivalMessage(href, reread !== null ? `${head} ${RECEIVE.afterStatus(statusSpoken(reread.status))}` : head);
      if (mounted.current) router.push(href);
    },
    [router, initial.id, initial.warehouse.name],
  );
  const onOrderChanged = useCallback((o: PurchaseOrder, why: 'changed' | 'earlier-recorded') => {
    setOrder(o);
    setChanged(why);
    // Focus before the sentence: the h1 of the no-form state takes focus once it has rendered.
    requestAnimationFrame(() => {
      const h1 = document.getElementById('receive-heading');
      if (h1 !== null && h1.isConnected) h1.focus();
    });
  }, []);
  const onNoLocations = useCallback(() => {
    setNoLocations(true);
    requestAnimationFrame(() => {
      const h1 = document.getElementById('receive-heading');
      if (h1 !== null && h1.isConnected) h1.focus();
    });
  }, []);
  const receivable = isReceivable(order) && changed === null;
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 id="receive-heading" tabIndex={-1}>
          {RECEIVE.heading(order.order_number)}
        </h1>
      </header>
      {receivable && noLocations ? (
        <>
          <p>{RECEIVE.noLocations(order.warehouse.name)}</p>
          <p>
            <Link href={`/purchase-orders/${order.id}`}>{RECEIVE.backToOrder(order.order_number)}</Link>
          </p>
        </>
      ) : receivable ? (
        <>
          <p>{RECEIVE.intro(order.supplier.name, order.warehouse.name)}</p>
          <ReceiveForm
            order={order}
            lines={lines}
            locations={locations}
            record={record}
            reloadLocations={reloadLocations}
            createCode={gateCode('CreateGoodsReceipt')}
            onRecorded={onRecorded}
            onOrderChanged={onOrderChanged}
            onOrder={setOrder}
            onNoLocations={onNoLocations}
          />
          <p>
            <Link href={`/purchase-orders/${order.id}`}>{RECEIVE.dismiss}</Link>
          </p>
        </>
      ) : (
        <>
          {changed === 'changed' ? <p>{RECEIVE.orderChanged(order.order_number, statusSpoken(order.status))}</p> : null}
          {changed === 'earlier-recorded' ? <p>{RECEIVE.earlierRecordedNoForm}</p> : null}
          <p>{RECEIVE.nothingToReceive(order.order_number, order.archived_at !== null ? RECEIVE.deletedDraftWords : statusSpoken(order.status))}</p>
          <p>
            <Link href={`/purchase-orders/${order.id}`}>{RECEIVE.backToOrder(order.order_number)}</Link>
          </p>
        </>
      )}
    </section>
  );
}
