'use client';

import { useCallback, useLayoutEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { RestoreActions } from '@/components/records/RestoreActions';
import { RegionError } from '@/components/states/PageStates';
import { Chip } from '@/components/ui/Chip';
import { DateTime } from '@/components/ui/DateTime';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { PURCHASE_ORDER_PAGE, PURCHASING_FORBIDDEN, RESTORE_ORDER, statusLabel } from '@/copy/purchasing';
import type { PurchaseOrder } from '@/lib/api/purchasing';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { renderMoney } from '@/lib/format/money';
import { loadLines, loadPurchaseOrder, type LinesLoad } from '@/lib/purchasing/load';
import { orderRestore } from '@/lib/purchasing/wired';
import { useApiCaller, useViewerTimeZone } from '../../BffProvider';
import listStyles from '../../Lists.module.css';
import { useCommittedState } from '../../useCommittedState';
import { PurchaseOrderNotFound, PurchaseOrdersDenied } from '../PurchaseOrderStates';
import styles from '../PurchaseOrders.module.css';
import { LinesSection } from './LinesSection';

export interface PurchaseOrderPageProps {
  /** GetPurchaseOrder on first render. */
  order: PurchaseOrder;
  /** ListPurchaseOrderLines on first render, or null for a deleted draft (not read: po-2-progress.md D-1) or a viewer without the read. */
  lines: LinesLoad | null;
  /** The signed-in person's id, from `currentViewer()` on the server (final RED L-2): *Raised by* reads "(you)" only when it equals `created_by.id`. */
  viewerId: string;
  /** Holds RestorePurchaseOrder's codes (ST-4c): without it a deleted draft shows its banner, no button. */
  canRestore: boolean;
  /** Holds ListPurchaseOrderLines' codes: a restore (or a re-read) that turns the page live reads the lines only then. */
  canReadLines: boolean;
  /** Holds GetSupplier's / GetWarehouse's / GetItem's codes (ST-4c): the name is a link only then. */
  canOpenSupplier: boolean;
  canOpenWarehouse: boolean;
  canOpenItem: boolean;
}

type Cause = 'unreachable' | 'server-error';
/** What the page shows: the order and, for a live one whose lines were read, those lines (a generation per read, so a new read remounts the section on its new answer). */
interface Shown {
  order: PurchaseOrder;
  lines: { gen: number; value: LinesLoad } | null;
}

/**
 * The order page (purchase-orders-plan.md §2.2, §4 PO-1 and PO-2; UX B-3 / R1-B `PURCHASE_ORDER_PAGE`, B-13
 * `RESTORE_ORDER`, R1a-1 rows 1–2). Read-only for a live order of any status: the title `{number} — {supplier}`, the
 * status badge, and the facts — Supplier (a link for a GetSupplier holder), Deliver to (the warehouse; a link for a
 * GetWarehouse holder), Currency, Expected delivery (DT-3, or *Not set*), Ordered on (once ordered, DT-3), Raised by
 * (`created_by.display_name`; *"{name} (you)"* when it is the viewer, display only — it pre-empts nothing, ST-4f; *Not
 * recorded* when null) and Total (`renderMoney`, the order's currency). Then the status's note (a live draft: "This is a
 * draft."; ordered / partly received: the PO-1 `orderedNote` and `noSupplierContact`; received / cancelled: `closedNote`)
 * and the lines section.
 *
 * A deleted draft shows the ST-9 banner *"This draft was deleted on {date}."* with **Restore purchase order** for a
 * RestorePurchaseOrder holder (`components/records/RestoreActions`, PO-2), no note and no lines section (po-2-progress.md
 * D-1: `?archived=true` serves every deleted line, also those a restore does not bring back). A restore commits the
 * re-read order — and, for a ListPurchaseOrderLines holder, its live lines, in the same commit — so the page turns live
 * without a remount and shows the lines that came back. The page holds the stamp rule (the supplier page's): a commit or
 * failure older than what is shown is dropped. A failed re-read → the region error (focus on its heading), whose Retry
 * re-reads the order (and its lines, when it turns live with none shown); 403 → ST-4b; 404 → ST-5. A commit that turns
 * the page deleted moves lost focus to the h1. After a restore's message is on screen the router cache is refreshed once.
 * The page owns one region (the restore's after-sentence and the lines Retry's count speak there).
 */
export function PurchaseOrderPage({ order: initial, lines: initialLines, viewerId, canRestore, canReadLines, canOpenSupplier, canOpenWarehouse, canOpenItem }: PurchaseOrderPageProps) {
  const caller = useApiCaller();
  const router = useRouter();
  const timeZone = useViewerTimeZone();
  const { message, announce } = useLiveRegion();
  // A deleted order never carries lines, from the first render on (the server reads none for it; `commit` keeps the rule).
  const firstLines: Shown['lines'] = initial.archived_at === null && initialLines !== null ? { gen: 0, value: initialLines } : null;
  const [shown, setShown] = useCommittedState<Shown>({ order: initial, lines: firstLines });
  const [swap, setSwap] = useState<'forbidden' | 'not-found' | null>(null);
  const [rereadError, setRereadError] = useState<{ gen: number; cause: Cause } | null>(null);
  const [retrying, setRetrying] = useState(false);
  const h1 = useRef<HTMLHeadingElement>(null);
  const id = initial.id;

  // The stamp rule (items-i3-design.md Revision 1 — FINAL §0a; the supplier page's): every commit is stamped with the
  // moment its reading was requested, and one older than what is on screen is dropped.
  const stamps = useRef(0);
  const committedAsOf = useRef(0);
  // The lines last committed (a commit that keeps them reuses them; a Retry reads lines only when none are shown).
  const committedLines = useRef<Shown['lines']>(firstLines);
  const linesReads = useRef(0);
  const stamp = useCallback(() => ++stamps.current, []);
  const failed = useCallback((f: ReadFailure, asOf?: number) => {
    if (asOf !== undefined && asOf < committedAsOf.current) return;
    if (f === 'session-ended') return;
    if (f === 'forbidden' || f === 'not-found') {
      setSwap(f);
      return;
    }
    // After RestoreActions' own after-restore focus, so the region error's heading takes it.
    setTimeout(() => setRereadError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: f })), 0);
  }, []);

  /**
   * Commits a reading. A deleted order drops its lines (none are shown on a deleted draft: the state holds the rule, from
   * `firstLines` on, and the render shows whatever lines are committed); a live one with `lines` read shows them under a new
   * generation; a live one read without lines keeps what is shown.
   */
  const commit = useCallback(
    async (order: PurchaseOrder, lines: LinesLoad | null, asOf: number) => {
      if (asOf < committedAsOf.current) return;
      committedAsOf.current = asOf;
      setRereadError(null);
      const next = order.archived_at !== null ? null : lines !== null ? { gen: ++linesReads.current, value: lines } : committedLines.current;
      committedLines.current = next;
      await setShown({ order, lines: next });
    },
    [setShown],
  );

  // The wiring is built when the action runs, not during render: `commit` writes the stamp refs (react-hooks/refs).
  const restore = useCallback(() => orderRestore({ caller, id, stamp, readLines: canReadLines, setOrder: commit, onRereadFailed: failed })(), [caller, id, stamp, canReadLines, commit, failed]);
  const onRestored = useCallback(() => router.refresh(), [router]);

  const order = shown.order;
  const archived = order.archived_at !== null;
  // A commit that turns the page deleted (a Retry that finds it deleted again) removes what had focus: when focus is then
  // lost (on <body> or a removed element), it moves to the h1.
  useLayoutEffect(() => {
    if (!archived) return;
    const a = document.activeElement;
    if (a === null || a === document.body || !a.isConnected) h1.current?.focus();
  }, [archived]);

  const retry = async () => {
    if (retrying) return;
    setRetrying(true);
    const mine = ++stamps.current;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadPurchaseOrder(readOf(caller), id, d.signal);
    // Live with no lines shown (a restore whose lines were not committed): read them now, with the order.
    const lines = r.kind === 'ok' && r.order.archived_at === null && canReadLines && committedLines.current === null ? await loadLines(readOf(caller), id, d.signal) : null;
    d.cancel();
    setRetrying(false);
    if (committedAsOf.current > mine) return;
    if (r.kind === 'ok') {
      await commit(r.order, lines, mine);
      h1.current?.focus();
    } else failed(r.kind, mine);
  };

  if (swap === 'forbidden') return <PurchaseOrdersDenied />;
  if (swap === 'not-found') return <PurchaseOrderNotFound />;

  const raisedBy = order.created_by === null ? PURCHASE_ORDER_PAGE.raisedByUnknown : order.created_by.id === viewerId ? PURCHASE_ORDER_PAGE.raisedByYou(order.created_by.display_name) : order.created_by.display_name;
  const s = order.status;
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {PURCHASE_ORDER_PAGE.title(order.order_number, order.supplier.name)}
        </h1>
        <Chip>{statusLabel(s)}</Chip>
      </header>
      {/* Mounted from first render, above every branch below, so it never arrives already speaking. */}
      <LiveRegion message={message} visuallyHidden />
      {rereadError ? (
        <RegionError key={`reread-${rereadError.gen}`} heading={rereadError.cause === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={PURCHASE_ORDER_PAGE.rereadFailed} retrying={retrying} onRetry={() => void retry()} />
      ) : null}
      {order.archived_at !== null ? (
        <RestoreActions
          archivedAt={order.archived_at}
          copy={{
            archivedBefore: PURCHASE_ORDER_PAGE.deletedBefore,
            archivedAfter: PURCHASE_ORDER_PAGE.deletedAfter,
            action: RESTORE_ORDER.action,
            after: (r) => RESTORE_ORDER.after(r.order.order_number),
            alreadyLive: (r) => RESTORE_ORDER.alreadyRestored(r.order.order_number),
            interrupted: RESTORE_ORDER.interrupted(order.order_number),
            forbidden: PURCHASING_FORBIDDEN,
          }}
          canRestore={canRestore}
          restore={restore}
          restoreCode={gateCode('RestorePurchaseOrder')}
          timeZone={timeZone}
          announce={announce}
          fallbackFocusRef={h1}
          onRestored={onRestored}
        />
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
      {shown.lines !== null ? <LinesSection key={`lines-${shown.lines.gen}`} orderId={order.id} initial={shown.lines.value} status={s} currency={order.currency_code} canOpenItem={canOpenItem} announce={announce} /> : null}
    </section>
  );
}
