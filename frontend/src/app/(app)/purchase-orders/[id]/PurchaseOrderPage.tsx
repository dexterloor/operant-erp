'use client';

import { useCallback, useLayoutEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { AddLineRow } from '@/components/purchasing/AddLineRow';
import { OrderHeaderForm } from '@/components/purchasing/OrderHeaderForm';
import { DeleteDialog } from '@/components/records/DeleteDialog';
import { RestoreActions } from '@/components/records/RestoreActions';
import { RegionError } from '@/components/states/PageStates';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DateTime } from '@/components/ui/DateTime';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { COULD_NOT_REACH, NOT_ADDED, NOT_DELETED, NOT_SAVED, WENT_WRONG } from '@/copy/headings';
import { ADD_LINE, DELETE_ORDER, ORDER_LINES, PURCHASE_ORDER_PAGE, PURCHASING_FORBIDDEN, RESTORE_ORDER, statusLabel } from '@/copy/purchasing';
import type { PurchaseOrder, PurchaseOrderLine } from '@/lib/api/purchasing';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { renderMoney } from '@/lib/format/money';
import { loadCatalogueChoices, loadLines, loadPurchaseOrder, loadWarehouseChoices, type CatalogueChoicesLoad, type LinesLoad, type WarehouseChoicesLoad } from '@/lib/purchasing/load';
import type { AddLineValues, OrderChange } from '@/lib/purchasing/results';
import { lineCreate, lineDelete, orderDelete, orderRestore, orderSave } from '@/lib/purchasing/wired';
import type { DeleteResult } from '@/lib/results';
import { useArrivalMessage } from '../../arrivalMessage';
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
  /**
   * PO-4a: the draft header editor, or null. Given by the server only for a live draft and a viewer holding
   * UpdatePurchaseOrder's and ListWarehouses' codes, with the live warehouses read for its Deliver to (a failed read is the
   * field's own state). Absent or null: the header is read-only text. A draft that LOADED deleted gets it from the router
   * refresh after the restore; a draft deleted and restored on this page keeps the editor's first list (P4 re-reads it).
   * (po-4a-red.md L-2.)
   */
  edit?: { warehouses: WarehouseChoicesLoad } | null;
  /**
   * PO-5: Add line, or null. Given by the server only for a live draft whose lines were read and a viewer holding
   * CreatePurchaseOrderLine's AND ListSupplierProducts' codes, with the order's supplier's catalogue read for the new row's
   * Item (a failed read is the row's own state). Like `edit`, a draft that LOADED deleted gets it from the refresh after
   * the restore.
   */
  add?: { catalogue: CatalogueChoicesLoad } | null;
  /** PO-5: holds DeletePurchaseOrderLine's codes — each live row of a live draft offers **Delete** (X-4: no dialog). */
  canDeleteLine?: boolean;
  /** PO-6: holds DeletePurchaseOrder's codes — a live draft offers **Delete purchase order** (UX B-14; plan R1-3). */
  canDelete?: boolean;
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
 * The page owns one region (the restore's after-sentence and the lines Retry's count speak there). After a create (PO-3)
 * the page speaks *"{number} was created as a draft."* once, in that region, after `RouteFocus` has moved focus to its h1.
 *
 * From PO-4a a live draft's header is its editor (`components/purchasing/OrderHeaderForm`) when the server gave `edit`
 * (UpdatePurchaseOrder and ListWarehouses): Supplier and Currency as text, Deliver to as a select, **Save**. A Save press
 * empties the page's region (30d2edb), a saved answer says *"{number} was saved."* there, and the router cache is refreshed
 * once. A refused Save whose re-read found the order no longer an editable draft commits that reading (the editor goes in
 * the same mount: read-only, or the deleted banner with Restore) and the page shows the sentence under **Not saved**, its
 * heading focused — UX D-2's `noLongerDraft` for an order approved or cancelled elsewhere, the server's for one deleted
 * elsewhere — unless a newer editable reading was committed first (FE-R7). A restore clears it.
 *
 * From PO-5 a live draft's lines section offers **Add line** (`add`; the new line row, `components/purchasing/AddLineRow`)
 * and **Delete** on each line (`canDeleteLine`). Each write's re-read (the order and its lines, stamped) is committed in one
 * commit through the same `commit`; the section is not remounted by it. A line write refused because the order is no longer
 * an editable draft shows its sentence on the same banner as Save's, under **Not added** / **Not deleted**. No page write
 * counter: each line write speaks once, after its own re-read has answered, so no sentence is corrected later
 * (po-4a-progress.md D-4's owed decision, taken at PO-5).
 *
 * From PO-6 a live draft offers **Delete purchase order** in the header (`canDelete`, UX B-14) through the shared
 * `components/records/DeleteDialog`, rendered on its open state alone. A 204 raises the page's floor to the delete's
 * re-read stamp (`onDeleted`: a reading asked for before the delete, or a Save's own answer body sent before it, cannot
 * turn the page live again), commits the re-read (deleted: the banner with Restore; live: restored in between, S-2) and,
 * after the dialog has closed, focuses the h1, then speaks *"{number} was deleted. It's now in the archived purchase
 * orders."* (or *"…and has since been restored."*), then refreshes the router cache once (FE-D1). A 204 whose re-read failed
 * leaves the page read-only (no actions, no note) with its region error until a later commit. P9's rule (an order approved
 * or cancelled elsewhere) shows UX D-2's sentence inside the dialog; P10 the server's; both leave Close, which re-reads.
 * While a line write is in flight the press opens nothing (one write at a time, po-6-progress.md D-3).
 */
export function PurchaseOrderPage({ order: initial, lines: initialLines, viewerId, canRestore, canReadLines, canOpenSupplier, canOpenWarehouse, canOpenItem, edit = null, add = null, canDeleteLine = false, canDelete = false }: PurchaseOrderPageProps) {
  const caller = useApiCaller();
  const router = useRouter();
  const timeZone = useViewerTimeZone();
  const { message, announce, clear } = useLiveRegion();
  // A deleted order never carries lines, from the first render on (the server reads none for it; `commit` keeps the rule).
  const firstLines: Shown['lines'] = initial.archived_at === null && initialLines !== null ? { gen: 0, value: initialLines } : null;
  const [shown, setShown] = useCommittedState<Shown>({ order: initial, lines: firstLines });
  const [swap, setSwap] = useState<'forbidden' | 'not-found' | null>(null);
  const [rereadError, setRereadError] = useState<{ gen: number; cause: Cause } | null>(null);
  const [retrying, setRetrying] = useState(false);
  // PO-6: the Delete dialog is open (it renders on this alone, never on what the page shows under it).
  const [deleting, setDeleting] = useState(false);
  // PO-6 (the warehouse page's RED M-1): a 204 here is a fact that outranks a stale snapshot. If the re-read after it failed,
  // the order is still treated as deleted for the actions (no Delete purchase order, no editor, no line actions, no note)
  // until a commit that is not dropped says otherwise.
  const [deletedHere, setDeletedHere] = useState(false);
  // PO-4a: a refused Save whose re-read found the order no longer an editable draft (the editor is gone): its sentence,
  // shown under **Not saved** while the page shows an order that is not an editable draft.
  const [staleRefusal, setStaleRefusal] = useState<{ gen: number; heading: string; message: string } | null>(null);
  const staleHeading = useRef<HTMLHeadingElement>(null);
  const h1 = useRef<HTMLHeadingElement>(null);
  const linesHeading = useRef<HTMLHeadingElement>(null);
  // PO-5 (po-5-red.md L-3): one line write at a time across the lines section — an Add and a Delete never run together (a
  // header Save or a page Retry may; their stamped commits win, and a line write's sentence may then say 'may be out of date'
  // over a current screen — D-11's limit).
  const lineWriting = useRef(false);
  const beginLineWrite = useCallback(() => {
    if (lineWriting.current) return false;
    lineWriting.current = true;
    return true;
  }, []);
  const endLineWrite = useCallback(() => {
    lineWriting.current = false;
  }, []);
  const id = initial.id;
  // After a create (PO-3; A-19 item 7): the create page left the sentence for this address. A later writer replaces it.
  useArrivalMessage(`/purchase-orders/${id}`, announce);

  // The stamp rule (items-i3-design.md Revision 1 — FINAL §0a; the supplier page's): every commit is stamped with the
  // moment its reading was requested, and one older than what is on screen is dropped.
  const stamps = useRef(0);
  const committedAsOf = useRef(0);
  // The lines last committed (a commit that keeps them reuses them; a Retry reads lines only when none are shown).
  const committedLines = useRef<Shown['lines']>(firstLines);
  const linesReads = useRef(0);
  // Whether the reading last committed is an editable draft (live, `draft`), for the stale-refusal rule (FE-R7, the supplier
  // page's): decided from what was committed, never from the render the Save was pressed in.
  const committedEditable = useRef(initial.archived_at === null && initial.status === 'draft');
  // PO-6, the 204 floor (items-i3-design.md Revision 1 — FINAL §0a; the warehouse page's): the stamp of the delete's
  // re-read. A write's own answer body sent before it describes the order before the delete and is dropped (`sentAsOf`).
  const deletedAsOf = useRef(0);
  // PO-6 (S-2, items-i4-red.md L-4): whether the reading last committed is deleted, for the delete's after-sentence;
  // decided from what was committed, never from the render the dialog was opened in. A 204 sets it too, until a later
  // commit says otherwise.
  const committedArchived = useRef(initial.archived_at !== null);
  const stamp = useCallback(() => ++stamps.current, []);
  const failed = useCallback((f: ReadFailure, asOf?: number, sentAsOf?: number) => {
    if (asOf !== undefined && asOf < committedAsOf.current) return;
    if (f === 'session-ended') return;
    if (f === 'forbidden' || f === 'not-found') {
      setSwap(f);
      return;
    }
    // PO-6 (po-6-red.md, spar on :384; the items UX ruling L-1): a Save sent before a 204 of the person's own Delete does not
    // put its failed re-read over what the delete left; the delete's reading, sentence and focus stand. Compared only with
    // the 204 floor. Swaps are kept: they say what the viewer may see now.
    if (sentAsOf !== undefined && sentAsOf < deletedAsOf.current) return;
    // After RestoreActions' own after-restore focus, so the region error's heading takes it.
    setTimeout(() => setRereadError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: f })), 0);
  }, []);

  /**
   * Commits a reading. A deleted order drops its lines (none are shown on a deleted draft: the state holds the rule, from
   * `firstLines` on, and the render shows whatever lines are committed); a live one with `lines` read shows them under a new
   * generation; a live one read without lines keeps what is shown.
   */
  const commit = useCallback(
    async (order: PurchaseOrder, lines: LinesLoad | null, asOf: number, sentAsOf?: number) => {
      if (asOf < committedAsOf.current) return;
      if (sentAsOf !== undefined && sentAsOf < deletedAsOf.current) return;
      committedAsOf.current = asOf;
      committedEditable.current = order.archived_at === null && order.status === 'draft';
      committedArchived.current = order.archived_at !== null;
      // A commit that is not dropped is newer than a 204 seen here: it clears that fact (a restore then turns the page live).
      setDeletedHere(false);
      setRereadError(null);
      // An editable draft by any path (a restore): a stale Save's sentence describes nothing on screen.
      if (committedEditable.current) setStaleRefusal(null);
      const next = order.archived_at !== null ? null : lines !== null ? { gen: ++linesReads.current, value: lines } : committedLines.current;
      committedLines.current = next;
      await setShown({ order, lines: next });
    },
    [setShown],
  );

  // The wiring is built when the action runs, not during render: `commit` writes the stamp refs (react-hooks/refs).
  const restore = useCallback(() => orderRestore({ caller, id, stamp, readLines: canReadLines, setOrder: commit, onRereadFailed: failed })(), [caller, id, stamp, canReadLines, commit, failed]);
  const onRestored = useCallback(() => {
    setStaleRefusal(null);
    router.refresh();
  }, [router]);
  const save = useCallback((changes: OrderChange) => orderSave({ caller, id, stamp, readLines: canReadLines, setOrder: commit, onRereadFailed: failed })(changes), [caller, id, stamp, canReadLines, commit, failed]);
  const onSaved = useCallback(() => router.refresh(), [router]);
  const reloadWarehouses = useCallback(async () => {
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    try {
      return await loadWarehouseChoices(readOf(caller), d.signal);
    } finally {
      d.cancel();
    }
  }, [caller]);
  const refusedStale = useCallback((heading: string, text: string) => {
    // FE-R7: the page shows an editable draft again (a newer reading was committed first): the refusal describes nothing
    // on screen and is not kept.
    if (committedEditable.current) return;
    setStaleRefusal((cur) => ({ gen: (cur?.gen ?? 0) + 1, heading, message: text }));
  }, []);
  // PO-6 (po-6-red.md M-1; the items page's UX ruling T-17, `ItemPage.tsx`): a Save sent before a 204 of the person's own
  // Delete describes the order before the delete — its refusal is dropped (no **Not saved**, focus stays where the delete
  // put it), and so is its "saved" sentence (M-3: "the person's own Delete outranks their own Save"; the refresh stays).
  // `sentAsOf` is compared only with the 204 floor, never with the commit stamp.
  const onRefusedStale = useCallback(
    (text: string, sentAsOf: number) => {
      if (sentAsOf < deletedAsOf.current) return;
      refusedStale(NOT_SAVED, text);
    },
    [refusedStale],
  );
  const announceSaved = useCallback(
    (text: string, sentAsOf: number) => {
      if (sentAsOf < deletedAsOf.current) return;
      announce(text);
    },
    [announce],
  );
  const onAddRefusedStale = useCallback((text: string) => refusedStale(NOT_ADDED, text), [refusedStale]);
  const onDeleteRefusedStale = useCallback((text: string) => refusedStale(NOT_DELETED, text), [refusedStale]);
  // PO-5: the line writes (the same wiring, stamp and commit as Save's).
  const addLine = useCallback((values: AddLineValues) => lineCreate({ caller, id, stamp, readLines: canReadLines, setOrder: commit, onRereadFailed: failed })(values), [caller, id, stamp, canReadLines, commit, failed]);
  const deleteLine = useCallback((line: PurchaseOrderLine) => lineDelete({ caller, id, stamp, readLines: canReadLines, setOrder: commit, onRereadFailed: failed })(line), [caller, id, stamp, canReadLines, commit, failed]);
  // PO-6: the 204 floor, raised before the delete's re-read is sent, so a reading requested earlier is dropped even when that
  // re-read fails.
  const onDeleted = useCallback((s: number) => {
    // Not `Math.max`: this route dir is in the quantity scan (QR-2 backstop), which reads the token, not its meaning.
    if (s > committedAsOf.current) committedAsOf.current = s;
    deletedAsOf.current = s;
    committedArchived.current = true;
    setDeletedHere(true);
    // T-17 (items): the draft is deleted by the person's own act; a stale Save refusal describes an edit they can no longer make.
    setStaleRefusal(null);
  }, []);
  // PO-6: Delete purchase order. P9's two stale kinds become the dialog's own refusal (Close only) in UX D-2's words, built
  // from the re-read (`noLongerDraft`) or, when it failed, `changedSinceLoaded`; the order number never changes.
  const remove = useCallback(async (): Promise<DeleteResult> => {
    const r = await orderDelete({ caller, id, stamp, readLines: canReadLines, setOrder: commit, onRereadFailed: failed, onDeleted })();
    if (r.kind === 'no-longer-draft') return { kind: 'refused', rule: '', message: PURCHASE_ORDER_PAGE.noLongerDraft(r.order.order_number, r.order.status) };
    if (r.kind === 'changed-since-loaded') return { kind: 'refused', rule: '', message: PURCHASE_ORDER_PAGE.changedSinceLoaded(initial.order_number) };
    return r;
  }, [caller, id, stamp, canReadLines, commit, failed, onDeleted, initial.order_number]);
  // PO-6 (po-6-progress.md D-3): Delete purchase order joins the one line write at a time — while an Add or a line Delete is
  // in flight the press opens nothing and sends nothing (PO-5 L-3's refused press). While the dialog is open the lines are
  // under it, so no line write starts.
  const openDelete = () => {
    if (lineWriting.current) return;
    setDeleting(true);
  };
  const reloadCatalogue = useCallback(async () => {
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    try {
      return await loadCatalogueChoices(readOf(caller), initial.supplier.id, d.signal);
    } finally {
      d.cancel();
    }
  }, [caller, initial.supplier.id]);

  const order = shown.order;
  const archived = order.archived_at !== null;
  const liveDraft = !archived && order.status === 'draft';
  // A live draft that may be acted on: not after a 204 seen here whose re-read failed (`deletedHere`).
  const actionable = liveDraft && !deletedHere;
  const editorShown = edit !== null && actionable;

  // After the commit that renders the stale banner (focus never runs ahead of what it lands on).
  const staleGen = staleRefusal?.gen ?? null;
  useLayoutEffect(() => {
    if (staleGen !== null) staleHeading.current?.focus();
  }, [staleGen]);

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

  // PO-6 (plan §4 PO-6; the warehouse page's FE-D1 order). After a 204 the page stays: the draft is deleted, not gone, and
  // `orderDelete` has already committed the re-read (deleted: the banner and Restore; or live with its lines if someone
  // restored it in between, S-2). FE-D1: commit the close, focus the h1 (the opener is gone with the live state), speak
  // B-14's "After" — or, when the committed reading is live, "…and has since been restored." — then refresh the router
  // cache once (the list then shows it under Archived). Any other close after an answer re-reads the order (and, live, its
  // lines), so what is under the dialog is the server's (an interrupted press may have committed; P10 means it was deleted
  // elsewhere). A re-read that is not a live draft removed the button focus goes back to, so the h1 takes focus.
  const closeDelete = async ({ deleted, reread }: { deleted: boolean; reread: boolean }) => {
    flushSync(() => setDeleting(false));
    if (deleted) {
      h1.current?.focus();
      announce(committedArchived.current ? DELETE_ORDER.after(initial.order_number) : DELETE_ORDER.afterRestored(initial.order_number));
      router.refresh();
      return;
    }
    if (!reread) return;
    const mine = ++stamps.current;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadPurchaseOrder(readOf(caller), id, d.signal);
    const lines = r.kind === 'ok' && r.order.archived_at === null && canReadLines ? await loadLines(readOf(caller), id, d.signal) : null;
    d.cancel();
    if (committedAsOf.current > mine) return;
    if (r.kind !== 'ok') {
      failed(r.kind, mine);
      return;
    }
    await commit(r.order, lines, mine);
    // The warehouse page's RED H-1, widened to every reading that is not a live draft (deleted, or approved / cancelled
    // elsewhere): it unmounts the Delete purchase order button the dialog returns focus to, so the h1 takes it. A live
    // draft keeps the button, and the dialog's own return puts focus back on it (a check of `document.activeElement` here
    // is not used: under StrictMode the dialog's return has not happened yet, so it would read a lost focus that is not).
    if (r.order.archived_at !== null || r.order.status !== 'draft') h1.current?.focus();
    if (r.order.archived_at !== null) router.refresh();
  };

  if (swap === 'forbidden') return <PurchaseOrdersDenied />;
  if (swap === 'not-found') return <PurchaseOrderNotFound />;

  const deliverTo = canOpenWarehouse ? <Link href={`/warehouses/${order.warehouse.id}`}>{order.warehouse.name}</Link> : order.warehouse.name;
  const supplierName = canOpenSupplier ? <Link href={`/suppliers/${order.supplier.id}`}>{order.supplier.name}</Link> : order.supplier.name;
  const raisedBy = order.created_by === null ? PURCHASE_ORDER_PAGE.raisedByUnknown : order.created_by.id === viewerId ? PURCHASE_ORDER_PAGE.raisedByYou(order.created_by.display_name) : order.created_by.display_name;
  const s = order.status;
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {PURCHASE_ORDER_PAGE.title(order.order_number, order.supplier.name)}
        </h1>
        <Chip>{statusLabel(s)}</Chip>
        {canDelete && actionable ? (
          <Button variant="destructive" onClick={openDelete}>
            {DELETE_ORDER.action}
          </Button>
        ) : null}
      </header>
      {/* Mounted from first render, above every branch below, so it never arrives already speaking. */}
      <LiveRegion message={message} visuallyHidden />
      {rereadError ? (
        <RegionError key={`reread-${rereadError.gen}`} heading={rereadError.cause === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={PURCHASE_ORDER_PAGE.rereadFailed} retrying={retrying} onRetry={() => void retry()} />
      ) : null}
      {staleRefusal && !editorShown ? (
        <Banner key={`stale-${staleRefusal.gen}`} tone="danger" live="none" heading={staleRefusal.heading} ref={staleHeading}>
          <p>{staleRefusal.message}</p>
        </Banner>
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
      {editorShown ? (
        <OrderHeaderForm
          order={order}
          warehouses={edit.warehouses}
          reloadWarehouses={reloadWarehouses}
          save={save}
          editCode={gateCode('UpdatePurchaseOrder')}
          warehousesViewCode={gateCode('ListWarehouses')}
          supplier={supplierName}
          deliverTo={deliverTo}
          announce={announceSaved}
          fallbackFocusRef={h1}
          onSaved={onSaved}
          onSaveStarted={clear}
          onRefusedStale={onRefusedStale}
        />
      ) : null}
      <dl className={styles.fields}>
        {!editorShown ? (
          <>
            <dt>{PURCHASE_ORDER_PAGE.supplier}</dt>
            <dd>{supplierName}</dd>
            <dt>{PURCHASE_ORDER_PAGE.deliverTo}</dt>
            <dd>{deliverTo}</dd>
            <dt>{PURCHASE_ORDER_PAGE.currency}</dt>
            <dd>{order.currency_code}</dd>
          </>
        ) : null}
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
      {!archived && !deletedHere ? (
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
      {shown.lines !== null ? (
        <LinesSection
          orderId={order.id}
          orderNumber={order.order_number}
          lines={shown.lines}
          status={s}
          currency={order.currency_code}
          canOpenItem={canOpenItem}
          announce={announce}
          headingRef={linesHeading}
          add={
            add !== null && actionable
              ? {
                  emptyNote: ORDER_LINES.emptyNote(order.supplier.name),
                  row: (columns: number) => (
                    <AddLineRow
                      order={order}
                      catalogue={add.catalogue}
                      reloadCatalogue={reloadCatalogue}
                      add={addLine}
                      addCode={gateCode('CreatePurchaseOrderLine')}
                      catalogueViewCode={gateCode('ListSupplierProducts')}
                      supplierLink={canOpenSupplier ? <Link href={`/suppliers/${order.supplier.id}`}>{ADD_LINE.openSupplier(order.supplier.name)}</Link> : null}
                      columns={columns}
                      announce={announce}
                      fallbackFocusRef={linesHeading}
                      onRefusedStale={onAddRefusedStale}
                      linesGen={shown.lines!.gen}
                      lineWrites={{ begin: beginLineWrite, end: endLineWrite }}
                    />
                  ),
                }
              : null
          }
          remove={canDeleteLine && actionable ? deleteLine : null}
          deleteCode={gateCode('DeletePurchaseOrderLine')}
          onRefusedStale={onDeleteRefusedStale}
          lineWrites={{ begin: beginLineWrite, end: endLineWrite }}
        />
      ) : null}
      {deleting ? (
        <DeleteDialog
          copy={{
            title: DELETE_ORDER.title(order.order_number),
            body: DELETE_ORDER.body,
            confirm: DELETE_ORDER.confirm,
            cancel: DELETE_ORDER.dismiss,
            close: DELETE_ORDER.close,
            interrupted: DELETE_ORDER.interrupted(order.order_number),
            forbidden: PURCHASING_FORBIDDEN,
          }}
          run={remove}
          onDeleteStarted={clear}
          deleteCode={gateCode('DeletePurchaseOrder')}
          onClose={(o) => void closeDelete(o)}
          fallbackFocusRef={h1}
        />
      ) : null}
    </section>
  );
}
