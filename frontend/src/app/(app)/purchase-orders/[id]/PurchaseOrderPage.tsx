'use client';

import { useCallback, useLayoutEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { AddLineRow } from '@/components/purchasing/AddLineRow';
import { CancelOrderDialog } from '@/components/purchasing/CancelOrderDialog';
import { OrderHeaderForm } from '@/components/purchasing/OrderHeaderForm';
import { PlaceOrderDialog, type ApprovedOutcome } from '@/components/purchasing/PlaceOrderDialog';
import { DeleteDialog } from '@/components/records/DeleteDialog';
import { RestoreActions } from '@/components/records/RestoreActions';
import { RegionError } from '@/components/states/PageStates';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DateTime } from '@/components/ui/DateTime';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { COULD_NOT_REACH, NOT_ADDED, NOT_DELETED, NOT_RESTORED, NOT_SAVED, WENT_WRONG } from '@/copy/headings';
import { ADD_LINE, APPROVE_ORDER, CANCEL_ORDER, DELETE_ORDER, ORDER_LINES, PURCHASE_ORDER_PAGE, PURCHASING_FORBIDDEN, RESTORE_ORDER, statusLabel } from '@/copy/purchasing';
import type { PurchaseOrder, PurchaseOrderLine } from '@/lib/api/purchasing';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { renderMoney } from '@/lib/format/money';
import { loadCatalogueChoices, loadDeletedLines, loadLines, loadPurchaseOrder, loadWarehouseChoices, type CatalogueChoicesLoad, type LinesLoad, type ReceiptsLoad, type WarehouseChoicesLoad } from '@/lib/purchasing/load';
import type { AddLineValues, ApproveDialogResult, CancelDialogResult, LineChange, OrderChange } from '@/lib/purchasing/results';
import { lineCreate, lineDelete, lineRestore, lineSave, orderApprove, orderCancel, orderDelete, orderRestore, orderSave } from '@/lib/purchasing/wired';
import type { DeleteResult } from '@/lib/results';
import { useArrivalMessage } from '../../arrivalMessage';
import { useApiCaller, useViewerTimeZone } from '../../BffProvider';
import listStyles from '../../Lists.module.css';
import { useCommittedState } from '../../useCommittedState';
import { PurchaseOrderNotFound, PurchaseOrdersDenied } from '../PurchaseOrderStates';
import styles from '../PurchaseOrders.module.css';
import { LinesSection } from './LinesSection';
import { ReceiptsSection } from './ReceiptsSection';

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
  /** PO-7: holds CancelPurchaseOrder's codes — an ordered or partly received order offers **Cancel order** (UX B-7; plan D-2). */
  canCancel?: boolean;
  /** PO-8: holds ApprovePurchaseOrder's codes — a live draft offers **Approve** (UX B-6; plan §2.5), to its creator too (ST-4f). */
  canApprove?: boolean;
  /**
   * GR-1: the Receipts section's first page (ListGoodsReceipts with this order's id as its fixed filter, R10-4), or null.
   * Given by the server only for a live, placed order (never a draft: nothing can be received on one, `gr-1-followups-ux.md`
   * §4) and a ListGoodsReceipts holder (A-20: absent, not fetched, otherwise). Shown while the order on the page is live and
   * not a draft (the current status; `receipts !== null` stays in the condition, so an order approved on this page shows
   * no section until it is reloaded — UX accepts it, arch 3c). Its empty sentence follows the current status (cancelled).
   */
  receipts?: ReceiptsLoad | null;
  /** GR-1: holds GetGoodsReceipt's codes — each receipt number in the section links to its page. */
  canOpenReceipt?: boolean;
  /**
   * PO-9: holds RestorePurchaseOrderLine's codes (`purchase_order.edit`, Delete line's code) — a live draft whose lines are
   * read shows **Deleted lines** with **Restore** per row, and its line writes that change which lines are deleted read them
   * again.
   */
  canRestoreLine?: boolean;
  /**
   * PO-9: `ListPurchaseOrderLines?archived=true` on first render, or null. Given by the server only for a live draft whose
   * lines were read and a `canRestoreLine` viewer (a failed read is the subsection's own state). A draft that LOADED deleted
   * gets them from its restore's re-read.
   */
  deletedLines?: LinesLoad | null;
  /**
   * PO-4b: holds UpdatePurchaseOrderLine's codes (`purchase_order.edit`) — each live line of a live draft is edited in its row
   * (Quantity, Unit cost, **Save line**).
   */
  canEditLine?: boolean;
}

type Cause = 'unreachable' | 'server-error';
/** PO-7 (plan D-2): Cancel order is offered only on a live order that is ordered or partly received (Delete covers a draft). */
const cancellable = (o: PurchaseOrder) => o.archived_at === null && (o.status === 'ordered' || o.status === 'partially_received');
/** What the page shows: the order and, for a live one whose lines were read, those lines (a generation per read, so a new read remounts the section on its new answer). */
interface Shown {
  order: PurchaseOrder;
  lines: { gen: number; value: LinesLoad; asOf: number } | null;
  /** PO-9: the live draft's deleted lines, or null (not read, or a deleted order). */
  deleted: { gen: number; value: LinesLoad } | null;
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
 *
 * From PO-7 an ordered or partly received order offers **Cancel order** in the header (`canCancel`, UX B-7; never on a
 * draft, D-2; rendered for the person who raised it too — C1 is said after the press, ST-4f's third arm) through
 * `components/purchasing/CancelOrderDialog` (one optional single-line Reason, dismiss **Keep order**), rendered on its open
 * state alone. A 200 raises the page's cancel floor to the cancel's re-read stamp (`onCancelled`: a reading asked for
 * before it is dropped, and a Save sent before it is silenced exactly as before a 204 — its answer body, its sentence, its
 * refusal banner and its failed re-read), commits the re-read (or the 200 body when that failed) and, after the dialog has
 * closed, focuses the h1, then speaks *"{number} was cancelled. …"* (`afterPartly` when the page held a partly received
 * order before the request), then refreshes the router cache once (FE-D1). C2 (cancelled or received in full elsewhere)
 * shows UX D-2's sentence from the re-read inside the dialog; C1 and C3 the server's; all leave Close, which re-reads. While a
 * line write is in flight (a write started on the draft, still answering after the page turned ordered) the press opens
 * nothing (po-7-progress.md, the cancel floor).
 *
 * From PO-8 a live draft offers **Approve** in the header (`canApprove`, UX B-6; rendered and enabled for the person who
 * raised it too — A1 is said after the press, ST-4f's third arm) through `components/purchasing/PlaceOrderDialog` (the
 * summary and B-6's body; no field: no expected date while D-4 is unruled; dismiss **Go back**), rendered on its open state
 * alone. A 200 raises the page's approve floor to the approval's re-read stamp (`onApproved`: a reading asked for before it is
 * dropped), commits the re-read (or the 200 body when that
 * failed) — the editor, the new line row, every line's Delete and Delete purchase order go in the same mount — and, after the
 * dialog has closed, focuses the h1, then speaks *"{number} is approved and now ordered."* with B-6's supplier reminder, then
 * refreshes the router cache once (FE-D1). The Receipts section stays absent until that refresh brings the server's first
 * read (ARCH 3c). A2 (approved or cancelled elsewhere) shows UX D-2's sentence from the re-read inside the dialog; A1, A3 and
 * A6 the server's; all leave Go back, which re-reads. While a line write is in flight the press opens nothing (one write at a
 * time, also while a header Save is in flight — po-8-red.md H-1). While Deliver to holds a change not yet saved, Approve
 * carries a visible reason instead (M-1 (b)); the dialog names the delivery warehouse (M-1 (a)); and when the 200 body's total
 * or warehouse differs from what the dialog opened with, the region message says so between its two sentences (M-2;
 * po-8-m1-m2-recommendation.md Revision 1 — FINAL).
 *
 * From PO-9 a live draft's lines section ends with **Deleted lines** (`canRestoreLine`, `deletedLines`; the server reads them
 * for a live draft whose lines were read) with **Restore** per row (`LinesSection`). Restore line is one of the page's line
 * writes: while it is in flight no other line write sends and Approve / Delete purchase order open nothing (po-8-red.md H-1's
 * shape). Delete line and Restore line commit the deleted lines with the order and its live lines, in one commit, so a
 * restored line leaves Deleted lines and returns to the table in the same mount (Restore purchase order reads them too; Add
 * line deletes none, so it does not). Restore line's re-reads and answer carry the stamp of its send: the own-outcome floors
 * drop what an answer sent before the person's own Delete purchase order, Cancel order or Approve would commit, say or show.
 *
 * From PO-4b each live line of a live draft is edited in its row (`canEditLine`; `LinesSection` → `EditLineRow`): Quantity and
 * Unit cost with the line's unit beside them, **Save line** per row (D-9). Save line is one of the page's line writes (one
 * at a time; Approve and Delete purchase order open nothing while it is in flight, po-8-red.md H-1's shape). After its 200
 * the page reads the order alone (M-9) and commits it with the lines kept as shown, so the Total is the server's and every
 * other row keeps what was typed in it; each lines reading carries its stamp, so the row's own answer stands until a newer
 * reading. Its answers and commits carry the stamp of its send (the own-outcome floors). Every commit of a live draft's live
 * lines here also reads its deleted lines when they are shown (po-9-red.md R3-L-1), the dialogs' re-reads included.
 */
export function PurchaseOrderPage({ order: initial, lines: initialLines, viewerId, canRestore, canReadLines, canOpenSupplier, canOpenWarehouse, canOpenItem, edit = null, add = null, canDeleteLine = false, canDelete = false, canCancel = false, canApprove = false, receipts = null, canOpenReceipt = false, canRestoreLine = false, deletedLines = null, canEditLine = false }: PurchaseOrderPageProps) {
  const caller = useApiCaller();
  const router = useRouter();
  const timeZone = useViewerTimeZone();
  const { message, announce, clear } = useLiveRegion();
  // A deleted order never carries lines, from the first render on (the server reads none for it; `commit` keeps the rule).
  const firstLines: Shown['lines'] = initial.archived_at === null && initialLines !== null ? { gen: 0, value: initialLines, asOf: 0 } : null;
  const firstDeleted: Shown['deleted'] = firstLines !== null && deletedLines !== null ? { gen: 0, value: deletedLines } : null;
  const [shown, setShown] = useCommittedState<Shown>({ order: initial, lines: firstLines, deleted: firstDeleted });
  // PO-9: the deleted lines are read with a write's re-read only while the page can show them.
  const readDeleted = canRestoreLine && canReadLines;
  const [swap, setSwap] = useState<'forbidden' | 'not-found' | null>(null);
  const [rereadError, setRereadError] = useState<{ gen: number; cause: Cause } | null>(null);
  const [retrying, setRetrying] = useState(false);
  // PO-6: the Delete dialog is open (it renders on this alone, never on what the page shows under it).
  const [deleting, setDeleting] = useState(false);
  // PO-7: the Cancel order dialog is open (it renders on this alone, never on what the page shows under it).
  const [cancelling, setCancelling] = useState(false);
  // PO-8: the Approve dialog is open (it renders on this alone, never on what the page shows under it).
  const [approving, setApproving] = useState(false);
  // PO-8 M-1 (b): the header editor holds a change not yet saved (`OrderHeaderForm`'s `onDirtyChange`): Approve says why it is unavailable.
  const [headerDirty, setHeaderDirty] = useState(false);
  // po-4b-red.md H-1 (PO-8 M-1 (b) extended to the line rows): the ids of the live rows holding a change not yet saved
  // (`EditLineRow`'s `onDirtyChange`): Approve says why it is unavailable.
  const [dirtyLines, setDirtyLines] = useState<ReadonlySet<string>>(() => new Set());
  // po-4b-red.md S-2: typed line text hidden by a failed lines reading (`LinesSection`'s own fact).
  const [linesHiddenDirty, setLinesHiddenDirty] = useState(false);
  const onLineDirty = useCallback((lineId: string, dirty: boolean) => {
    setDirtyLines((cur) => {
      if (cur.has(lineId) === dirty) return cur;
      const next = new Set(cur);
      if (dirty) next.add(lineId);
      else next.delete(lineId);
      return next;
    });
  }, []);
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
  const committedDeleted = useRef<Shown['deleted']>(firstDeleted);
  const deletedReads = useRef(0);
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
  // PO-7, the cancel floor (the 204 floor's twin for the person's own Cancel order): the stamp of the cancel's re-read. A
  // Save sent before it describes the order before the cancel; what it would commit, say, show or focus is dropped.
  const cancelledAsOf = useRef(0);
  // PO-8, the approve floor (the cancel floor's twin for the person's own Approve): the stamp of the approval's re-read. A
  // reading asked for before it is dropped. No Save of the person's own can be in flight at the press (`openApprove`,
  // po-8-red.md H-1), so its Save arm in `beforeOwnOutcome` is defence only.
  const approvedAsOf = useRef(0);
  // PO-7: the status of the reading last committed (for `afterPartly`: the status the page held BEFORE the request, decided
  // from what was committed, never from the render the dialog was opened in), and the one captured at the press.
  const committedStatus = useRef(initial.status);
  const cancelFrom = useRef(initial.status);
  // A write sent before the person's own Delete (204), Cancel order (200) or Approve (200) describes the order before it
  // (PO-6 / PO-7 / PO-8).
  const beforeOwnOutcome = useCallback((sentAsOf: number) => sentAsOf < deletedAsOf.current || sentAsOf < cancelledAsOf.current || sentAsOf < approvedAsOf.current, []);
  const stamp = useCallback(() => ++stamps.current, []);
  const failed = useCallback((f: ReadFailure, asOf?: number, sentAsOf?: number) => {
    if (asOf !== undefined && asOf < committedAsOf.current) return;
    if (f === 'session-ended') return;
    if (f === 'forbidden' || f === 'not-found') {
      setSwap(f);
      return;
    }
    // PO-6 (po-6-red.md, spar on :384; the items UX ruling L-1): a Save sent before a 204 of the person's own Delete — or,
    // from PO-7, before a 200 of their own Cancel order — does not put its failed re-read over what that left; its reading,
    // sentence and focus stand. Compared only with the person's own outcomes (`beforeOwnOutcome`: the delete, cancel and approve floors). Swaps are kept: they say what the viewer may see now.
    if (sentAsOf !== undefined && beforeOwnOutcome(sentAsOf)) return;
    // After RestoreActions' own after-restore focus, so the region error's heading takes it.
    setTimeout(() => setRereadError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: f })), 0);
  }, [beforeOwnOutcome]);

  /**
   * Commits a reading. A deleted order drops its lines (none are shown on a deleted draft: the state holds the rule, from
   * `firstLines` on, and the render shows whatever lines are committed); a live one with `lines` read shows them under a new
   * generation; a live one read without lines keeps what is shown.
   */
  const commit = useCallback(
    async (order: PurchaseOrder, lines: LinesLoad | null, asOf: number, sentAsOf?: number, deleted?: LinesLoad) => {
      if (asOf < committedAsOf.current) return;
      if (sentAsOf !== undefined && beforeOwnOutcome(sentAsOf)) return;
      committedAsOf.current = asOf;
      committedStatus.current = order.status;
      committedEditable.current = order.archived_at === null && order.status === 'draft';
      committedArchived.current = order.archived_at !== null;
      // A commit that is not dropped is newer than a 204 seen here: it clears that fact (a restore then turns the page live).
      setDeletedHere(false);
      setRereadError(null);
      // An editable draft by any path (a restore): a stale Save's sentence describes nothing on screen.
      if (committedEditable.current) setStaleRefusal(null);
      // PO-4b: a lines reading carries the stamp it was asked for under, so a line row's own Save answer (stamped when its 200
      // arrived) stands over an older reading and gives way to a newer one.
      const next = order.archived_at !== null ? null : lines !== null ? { gen: ++linesReads.current, value: lines, asOf } : committedLines.current;
      committedLines.current = next;
      // PO-9: the deleted lines read with this reading replace what is shown, in the same commit; unread, they are kept. A line
      // is never drawn both live and deleted (po-9-red.md M-1 (a), N-1, N-2): `LinesSection` holds that rule, where the list is
      // drawn, over every source (this commit, a kept reading, its own Retry).
      const nextDeleted = order.archived_at !== null ? null : deleted !== undefined ? { gen: ++deletedReads.current, value: deleted } : committedDeleted.current;
      committedDeleted.current = nextDeleted;
      await setShown({ order, lines: next, deleted: nextDeleted });
    },
    [setShown, beforeOwnOutcome],
  );

  // The wiring is built when the action runs, not during render: `commit` writes the stamp refs (react-hooks/refs).
  const restore = useCallback(() => orderRestore({ caller, id, stamp, readLines: canReadLines, readDeleted, setOrder: commit, onRereadFailed: failed })(), [caller, id, stamp, canReadLines, readDeleted, commit, failed]);
  const onRestored = useCallback(() => {
    setStaleRefusal(null);
    router.refresh();
  }, [router]);
  // PO-8 (po-8-red.md H-1): a header Save is in flight from its press until its answer has been handled (its re-read
  // included). Approve opens nothing meanwhile: the Save's outcome is part of what an approval would commit to.
  const headerSaving = useRef(false);
  const save = useCallback(
    async (changes: OrderChange) => {
      headerSaving.current = true;
      try {
        return await orderSave({ caller, id, stamp, readLines: canReadLines, readDeleted, setOrder: commit, onRereadFailed: failed })(changes);
      } finally {
        headerSaving.current = false;
      }
    },
    [caller, id, stamp, canReadLines, readDeleted, commit, failed],
  );
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
  // PO-7: the same for a Save sent before a 200 of the person's own Cancel order (the cancel floor).
  const onRefusedStale = useCallback(
    (text: string, sentAsOf: number) => {
      if (beforeOwnOutcome(sentAsOf)) return;
      refusedStale(NOT_SAVED, text);
    },
    [refusedStale, beforeOwnOutcome],
  );
  const announceSaved = useCallback(
    (text: string, sentAsOf: number) => {
      if (beforeOwnOutcome(sentAsOf)) return;
      announce(text);
    },
    [announce, beforeOwnOutcome],
  );
  const onAddRefusedStale = useCallback((text: string) => refusedStale(NOT_ADDED, text), [refusedStale]);
  const onDeleteRefusedStale = useCallback((text: string) => refusedStale(NOT_DELETED, text), [refusedStale]);
  const onRestoreLineRefusedStale = useCallback((text: string) => refusedStale(NOT_RESTORED, text), [refusedStale]);
  // PO-5: the line writes (the same wiring, stamp and commit as Save's).
  // PO-9: every line write's wiring carries `readDeleted`; which writes read the deleted lines is decided once, in
  // `lib/purchasing/wired.ts` (Add line deletes none, so it reads none).
  const addLine = useCallback((values: AddLineValues) => lineCreate({ caller, id, stamp, readLines: canReadLines, readDeleted, setOrder: commit, onRereadFailed: failed })(values), [caller, id, stamp, canReadLines, readDeleted, commit, failed]);
  const deleteLine = useCallback((line: PurchaseOrderLine) => lineDelete({ caller, id, stamp, readLines: canReadLines, readDeleted, setOrder: commit, onRereadFailed: failed })(line), [caller, id, stamp, canReadLines, readDeleted, commit, failed]);
  // PO-9: Restore line (the same wiring, stamp and commit; its re-reads carry the stamp of its send, so the own-outcome floors
  // drop them).
  const restoreLine = useCallback((line: PurchaseOrderLine) => lineRestore({ caller, id, stamp, readLines: canReadLines, readDeleted, setOrder: commit, onRereadFailed: failed })(line), [caller, id, stamp, canReadLines, readDeleted, commit, failed]);
  // PO-4b: Save line (the same wiring, stamp and commit; header-only re-read after a 200, M-9; its answer and commits carry
  // the stamp of its send, so the own-outcome floors drop them).
  const saveLine = useCallback((line: PurchaseOrderLine, changes: LineChange) => lineSave({ caller, id, stamp, readLines: canReadLines, readDeleted, setOrder: commit, onRereadFailed: failed })(line, changes), [caller, id, stamp, canReadLines, readDeleted, commit, failed]);
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
    const r = await orderDelete({ caller, id, stamp, readLines: canReadLines, readDeleted, setOrder: commit, onRereadFailed: failed, onDeleted })();
    if (r.kind === 'no-longer-draft') return { kind: 'refused', rule: '', message: PURCHASE_ORDER_PAGE.noLongerDraft(r.order.order_number, r.order.status) };
    if (r.kind === 'changed-since-loaded') return { kind: 'refused', rule: '', message: PURCHASE_ORDER_PAGE.changedSinceLoaded(initial.order_number) };
    return r;
  }, [caller, id, stamp, canReadLines, readDeleted, commit, failed, onDeleted, initial.order_number]);
  // PO-6 (po-6-progress.md D-3): Delete purchase order joins the one line write at a time — while an Add or a line Delete is
  // in flight the press opens nothing and sends nothing (PO-5 L-3's refused press). While the dialog is open the lines are
  // under it, so no line write starts.
  const openDelete = () => {
    if (lineWriting.current) return;
    setDeleting(true);
  };
  // PO-7: the cancel floor, raised before the cancel's re-read is sent (the 204 floor's shape): a reading requested earlier is
  // dropped, and so is anything a Save sent earlier would commit, say or show. A stale "no longer a draft" banner describes an
  // edit the person can no longer make: it goes (T-17's rule for the person's own act).
  const onCancelled = useCallback((s: number) => {
    // Not `Math.max`: this route dir is in the quantity scan (QR-2 backstop), which reads the token, not its meaning.
    if (s > committedAsOf.current) committedAsOf.current = s;
    cancelledAsOf.current = s;
    setStaleRefusal(null);
  }, []);
  // PO-7: Cancel order. C2's stale kinds become the dialog's own refusal (Close only) in UX D-2's words, built from the
  // re-read (`alreadyCancelled` / `alreadyReceived`) or, when it failed, `changedSinceLoaded`; the order number never changes.
  // The status the page held before the request is taken here, at the press (`afterPartly`).
  const cancelRun = useCallback(
    async (reason: string): Promise<CancelDialogResult> => {
      cancelFrom.current = committedStatus.current;
      const r = await orderCancel({ caller, id, stamp, readLines: canReadLines, setOrder: commit, onRereadFailed: failed, onCancelled })(reason);
      if (r.kind === 'already-cancelled') return { kind: 'refused', rule: '', message: CANCEL_ORDER.alreadyCancelled(r.order.order_number) };
      if (r.kind === 'already-received') return { kind: 'refused', rule: '', message: CANCEL_ORDER.alreadyReceived(r.order.order_number) };
      if (r.kind === 'changed-since-loaded') return { kind: 'refused', rule: '', message: PURCHASE_ORDER_PAGE.changedSinceLoaded(initial.order_number) };
      if (r.kind === 'cancelled') return { kind: 'cancelled' };
      return r;
    },
    [caller, id, stamp, canReadLines, commit, failed, onCancelled, initial.order_number],
  );
  // PO-7 (po-7-progress.md, the cancel floor (a)): Cancel order joins the one line write at a time — a line write started on
  // the draft may still be answering after the page turned ordered; while it is in flight the press opens nothing.
  const openCancel = () => {
    if (lineWriting.current) return;
    setCancelling(true);
  };
  // PO-8: the approve floor, raised before the approval's re-read is sent (the cancel floor's shape): a reading requested
  // earlier (a page Retry) is dropped. Its Save-silencing half cannot be reached by the person's own Save, which `openApprove`
  // refuses to overlap (po-8-red.md H-1); it is kept as the shared predicate's third arm. A stale "no longer a draft" banner
  // describes an edit the person can no longer make: it goes.
  const onApproved = useCallback((s: number) => {
    // Not `Math.max`: this route dir is in the quantity scan (QR-2 backstop), which reads the token, not its meaning.
    if (s > committedAsOf.current) committedAsOf.current = s;
    approvedAsOf.current = s;
    setStaleRefusal(null);
  }, []);
  // PO-8: Approve. A2's stale kinds become the dialog's own refusal (Go back only) in UX D-2's words, built from the re-read
  // (`alreadyApproved`; `noLongerDraft` for a draft cancelled elsewhere) or, when it failed, `changedSinceLoaded`; the order
  // number never changes.
  const approveRun = useCallback(async (): Promise<ApproveDialogResult> => {
    const r = await orderApprove({ caller, id, stamp, readLines: canReadLines, readDeleted, setOrder: commit, onRereadFailed: failed, onApproved })();
    if (r.kind === 'already-approved') return { kind: 'refused', rule: '', message: APPROVE_ORDER.alreadyApproved(r.order.order_number) };
    if (r.kind === 'no-longer-draft') return { kind: 'refused', rule: '', message: PURCHASE_ORDER_PAGE.noLongerDraft(r.order.order_number, r.order.status) };
    if (r.kind === 'changed-since-loaded') return { kind: 'refused', rule: '', message: PURCHASE_ORDER_PAGE.changedSinceLoaded(initial.order_number) };
    if (r.kind === 'approved') return { kind: 'approved', order: r.order };
    return r;
  }, [caller, id, stamp, canReadLines, readDeleted, commit, failed, onApproved, initial.order_number]);
  // PO-8 (po-8-progress.md D-7/D-8; po-8-red.md H-1): one write at a time — while an Add, a line Delete or a header Save is
  // in flight the press opens nothing and sends nothing. So no Save of the person's own can still be answering when an
  // approval answers: whether a change went into the approved order is always settled, and said, before the dialog opens.
  const openApprove = () => {
    if (lineWriting.current || headerSaving.current) return;
    setApproving(true);
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
  // PO-4b: the line rows are editable (the lines' own rule; a viewer may hold it without the header editor's ListWarehouses).
  const lineEditShown = canEditLine && actionable;

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
    // po-9-red.md M-1 (b): a live draft whose Deleted lines are shown reads them with its lines (a failed re-read after a line
    // write may have left both out of date, M-2).
    const withDeleted = r.kind === 'ok' && readDeleted && r.order.archived_at === null && r.order.status === 'draft';
    // Live with no lines shown (a restore whose lines were not committed): read them now, with the order.
    const lines = r.kind === 'ok' && r.order.archived_at === null && canReadLines && (committedLines.current === null || withDeleted) ? await loadLines(readOf(caller), id, d.signal) : null;
    const deleted = withDeleted ? await loadDeletedLines(readOf(caller), id, d.signal) : undefined;
    d.cancel();
    setRetrying(false);
    if (committedAsOf.current > mine) return;
    if (r.kind === 'ok') {
      await commit(r.order, lines, mine, undefined, deleted);
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
    // po-9-red.md R3-L-1 (closed at PO-4b): a live draft's reading takes its deleted lines when they are shown.
    const deletedRead = r.kind === 'ok' && readDeleted && lines !== null && r.order.status === 'draft' ? await loadDeletedLines(readOf(caller), id, d.signal) : undefined;
    d.cancel();
    if (committedAsOf.current > mine) return;
    if (r.kind !== 'ok') {
      failed(r.kind, mine);
      return;
    }
    await commit(r.order, lines, mine, undefined, deletedRead);
    // The warehouse page's RED H-1, widened to every reading that is not a live draft (deleted, or approved / cancelled
    // elsewhere): it unmounts the Delete purchase order button the dialog returns focus to, so the h1 takes it. A live
    // draft keeps the button, and the dialog's own return puts focus back on it (a check of `document.activeElement` here
    // is not used: under StrictMode the dialog's return has not happened yet, so it would read a lost focus that is not).
    if (r.order.archived_at !== null || r.order.status !== 'draft') h1.current?.focus();
    if (r.order.archived_at !== null) router.refresh();
  };

  // PO-7 (plan §4 PO-7; FE-D1, as `closeDelete`). After a 200 the cancel's re-read (or its 200 body) is committed: commit
  // the close, focus the h1 (Cancel order is gone with the ordered state), speak B-7's `after` — `afterPartly` when the page
  // held a partly received order before the request — then refresh the router cache once. Any other close after an answer
  // re-reads the order and its lines, so what is under the dialog is the server's (an interrupted press may have committed).
  // A reading that can no longer be cancelled removed the button focus goes back to, so the h1 takes focus; a reading that
  // left the open orders refreshes the router cache.
  const closeCancel = async ({ cancelled, reread }: { cancelled: boolean; reread: boolean }) => {
    flushSync(() => setCancelling(false));
    if (cancelled) {
      h1.current?.focus();
      announce(cancelFrom.current === 'partially_received' ? CANCEL_ORDER.afterPartly(initial.order_number) : CANCEL_ORDER.after(initial.order_number));
      router.refresh();
      return;
    }
    if (!reread) return;
    const mine = ++stamps.current;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadPurchaseOrder(readOf(caller), id, d.signal);
    const lines = r.kind === 'ok' && r.order.archived_at === null && canReadLines ? await loadLines(readOf(caller), id, d.signal) : null;
    // po-9-red.md R3-L-1 (closed at PO-4b): a live draft's reading takes its deleted lines when they are shown.
    const deletedRead = r.kind === 'ok' && readDeleted && lines !== null && r.order.status === 'draft' ? await loadDeletedLines(readOf(caller), id, d.signal) : undefined;
    d.cancel();
    if (committedAsOf.current > mine) return;
    if (r.kind !== 'ok') {
      failed(r.kind, mine);
      return;
    }
    await commit(r.order, lines, mine, undefined, deletedRead);
    if (!cancellable(r.order)) {
      h1.current?.focus();
      router.refresh();
    }
  };

  // PO-8 (plan §4 PO-8; FE-D1, as `closeCancel`). After a 200 the approval's re-read (or its 200 body) is committed: commit
  // the close, focus the h1 (Approve is gone with the draft state), speak B-6's `after` with its supplier reminder, then
  // refresh the router cache once. Any other close after an answer re-reads the order and its lines, so what is under the
  // dialog is the server's (an interrupted press may have committed). A reading that is no longer an actionable draft removed
  // the button focus goes back to, so the h1 takes focus and the router cache is refreshed.
  const closeApprove = async ({ approved, reread, outcome }: { approved: boolean; reread: boolean; outcome?: ApprovedOutcome }) => {
    flushSync(() => setApproving(false));
    if (approved) {
      h1.current?.focus();
      // M-2 (po-8-m1-m2-recommendation.md Revision 1 — FINAL): the RAW served strings the dialog opened with against the 200
      // body only; a difference is said between `after` and `afterSupplier`, in one region message.
      const parts = [APPROVE_ORDER.after(initial.order_number)];
      if (outcome !== undefined && outcome.body.total_amount !== outcome.openedTotal) parts.push(APPROVE_ORDER.totalChanged(renderMoney(outcome.body.total_amount, outcome.body.currency_code)));
      if (outcome !== undefined && outcome.body.warehouse.id !== outcome.openedWarehouseId) parts.push(APPROVE_ORDER.warehouseChanged(outcome.body.warehouse.name));
      parts.push(APPROVE_ORDER.afterSupplier(initial.supplier.name));
      announce(parts.join(' '));
      router.refresh();
      return;
    }
    if (!reread) return;
    const mine = ++stamps.current;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadPurchaseOrder(readOf(caller), id, d.signal);
    const lines = r.kind === 'ok' && r.order.archived_at === null && canReadLines ? await loadLines(readOf(caller), id, d.signal) : null;
    // po-9-red.md R3-L-1 (closed at PO-4b): a live draft's reading takes its deleted lines when they are shown.
    const deletedRead = r.kind === 'ok' && readDeleted && lines !== null && r.order.status === 'draft' ? await loadDeletedLines(readOf(caller), id, d.signal) : undefined;
    d.cancel();
    if (committedAsOf.current > mine) return;
    if (r.kind !== 'ok') {
      failed(r.kind, mine);
      return;
    }
    await commit(r.order, lines, mine, undefined, deletedRead);
    if (r.order.archived_at !== null || r.order.status !== 'draft') {
      h1.current?.focus();
      router.refresh();
    }
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
        {canApprove && actionable ? (
          <Button variant="primary" unavailableReason={headerDirty && editorShown ? APPROVE_ORDER.unsavedHeader : (dirtyLines.size > 0 || linesHiddenDirty) && lineEditShown ? APPROVE_ORDER.unsavedLines : undefined} onClick={openApprove}>
            {APPROVE_ORDER.action}
          </Button>
        ) : null}
        {canCancel && cancellable(order) ? (
          <Button variant="destructive" onClick={openCancel}>
            {CANCEL_ORDER.action}
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
          onDirtyChange={setHeaderDirty}
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
          edit={lineEditShown ? { save: saveLine, editCode: gateCode('UpdatePurchaseOrderLine'), onRefusedStale, ownOutcomeAfter: beforeOwnOutcome, onDirtyChange: onLineDirty, onHiddenDirtyChange: setLinesHiddenDirty } : null}
          deleted={
            canRestoreLine && actionable && shown.deleted !== null
              ? { lines: shown.deleted, restore: restoreLine, restoreCode: gateCode('RestorePurchaseOrderLine'), ownOutcomeAfter: beforeOwnOutcome, onRefusedStale: onRestoreLineRefusedStale }
              : null
          }
        />
      ) : null}
      {receipts !== null && s !== 'draft' && !archived && !deletedHere ? <ReceiptsSection orderId={order.id} orderNumber={order.order_number} initial={receipts} canOpenReceipt={canOpenReceipt} cancelled={s === 'cancelled'} /> : null}
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
      {approving ? <PlaceOrderDialog order={order} run={approveRun} approveCode={gateCode('ApprovePurchaseOrder')} onApproveStarted={clear} onClose={(o) => void closeApprove(o)} fallbackFocusRef={h1} /> : null}
      {cancelling ? <CancelOrderDialog order={order} run={cancelRun} cancelCode={gateCode('CancelPurchaseOrder')} onCancelStarted={clear} onClose={(o) => void closeCancel(o)} fallbackFocusRef={h1} /> : null}
    </section>
  );
}
