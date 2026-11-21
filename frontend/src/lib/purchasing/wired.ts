import { ITEM_UNAVAILABLE, PURCHASE_ORDER_REFUSAL, type ApprovePurchaseOrderInput, type CancelPurchaseOrderInput, type CreatePurchaseOrderInput, type CreateGoodsReceiptInput, type CreatePurchaseOrderLineInput, type PurchaseOrder } from '../api/purchasing';
import { PLATFORM_REFUSAL } from '../api/problems';
import type { ApiCaller } from '../bff/apiCall';
import { ACTION_DEADLINES_MS, startDeadline } from '../bff/deadline';
import { heldKey } from '../bff/heldKey';
import { readOf, type ReadFailure } from '../bff/reads';
import { fieldErrorsFrom } from '../forms/fieldErrors';
import type { Refused } from '../results';
import { isGoodsReceipt, isPurchaseOrder, isPurchaseOrderLine, loadDeletedLines, loadLines, loadPurchaseOrder, type LinesLoad } from './load';
import { ADD_LINE_FIELDS, CREATE_ORDER_FIELDS, EDIT_LINE_FIELDS, isReceivable, ORDER_FIELDS, type ReceiveAction, type ReceiveResult, type ReceiveRowField, type AddLineAction, type ApproveOrderAction, type ApproveOrderResult, type AddLineResult, type CancelOrderAction, type CancelOrderResult, type CreateOrderAction, type DeleteLineAction, type DeleteLineResult, type DeleteOrderAction, type DeleteOrderResult, type LineChange, type OrderChange, type RestoreLineAction, type RestoreLineResult, type RestoreOrderAction, type SaveLineAction, type SaveLineResult, type SaveOrderAction } from './results';

// The purchasing family's actions over the BFF (Revision 8 R8-2; the lib/suppliers/wired.ts shape). PO-2 shipped
// Restore purchase order; PO-3 Create purchase order; PO-4a Save (the draft's header); PO-5 adds Add line and Delete line;
// PO-6 Delete purchase order; PO-7 Cancel order; PO-8 Approve; PO-9 Restore line; PO-4b Save line (all six at the end of
// this file). po-9-red.md R3-L-1 (closed at PO-4b): every reading of a live draft's live lines committed by this file takes
// its deleted lines with it (for a page that shows them), so a line hidden from Deleted lines because it was live never
// comes back there with an older reading's numbers once lines can be edited.
// Restore takes no Idempotency-Key: it is guarded by `deleted_at` (a repeat is refused P12).
// One deadline covers the request, its GetPurchaseOrder re-read and, for a ListPurchaseOrderLines holder, the order's live
// lines. Restore compares no refusal code: a refusal is followed by a re-read, and what the re-read shows decides (live →
// `already-live`; still deleted → the refusal as sent, Restore kept — P1 included, whose sentence says "try again").
// Save is the family's first action that must tell refusals apart (po-2-progress.md D-2), so from PO-4a this file is the
// purchasing family's one `lib/api/problems` importer (R8-4): P1 (`PLATFORM_REFUSAL.contention`) is never shown as the
// page's stale refusal, and P9's rule (`PURCHASE_ORDER_REFUSAL.notDraft`, UX D-2 "matched by rule code") is the one whose
// failed re-read says UX's changed-since sentence instead of the server's.
//
// Lines (po-2-progress.md D-1, po-1-red.md L-5): a deleted draft's page shows no lines (`?archived=true` would serve every
// deleted line, also those deleted on their own, which a restore does not bring back). Whenever this action commits a LIVE
// order, it reads that order's LIVE lines first and commits them with it in one commit, so the restored page shows what
// came back and `restored` resolves after both are on screen. A failed lines read is committed as the section's own state.
//
// Stamps (items-i3-design.md Revision 1 — FINAL §0a, the page's rule): the stamp is taken from `stamp()` immediately
// before the re-read is sent, after the write's answer has arrived, and passed to every commit or failure that follows,
// the fallback answer body included. The page drops anything older than what it shows.

export interface OrderWiring {
  readonly caller: ApiCaller;
  readonly id: string;
  /** The page's next stamp: taken when a re-read is sent. */
  readonly stamp: () => number;
  /** Holds ListPurchaseOrderLines' codes: only then is a live order's lines read with it. */
  readonly readLines: boolean;
  /**
   * PO-9: the page shows a live draft's Deleted lines (a RestorePurchaseOrderLine holder whose lines are read): only then are
   * a live draft's deleted lines (`?archived=true`) read with it — by Delete line, Restore line and Restore purchase order,
   * the writes that change which lines are deleted, and by Save, which may run beside a line write (po-9-red.md M-1 (b)).
   * From PO-4b by EVERY commit here that reads a live draft's live lines (Add line, a line Save's refusal, Delete purchase
   * order's and Approve's re-reads too: po-9-red.md R3-L-1); a line Save's header-only re-read reads neither (M-9).
   */
  readonly readDeleted?: boolean;
  /**
   * Sets the re-read order (and, for a live one read with its lines, those lines; `null` = no lines read) under the stamp
   * of the re-read, and resolves after the commit that shows them. `sentAsOf`, given only with a write's own answer body,
   * is the stamp taken when that write was sent: the page drops the body when a 204 arrived after it (PO-6, the 204 floor).
   * `deleted` (PO-9), given only when the deleted lines were read with the order, replaces the Deleted lines shown in the same
   * commit; absent, what is shown is kept.
   */
  readonly setOrder: (order: PurchaseOrder, lines: LinesLoad | null, asOf: number, sentAsOf?: number, deleted?: LinesLoad) => Promise<void>;
  /**
   * A re-read after a write did not answer an order: the page shows its region error, or swaps (ST-4b / ST-5). `sentAsOf`,
   * given only by Save (both sites), is the stamp taken when its PATCH was sent: the page drops the region error — never the
   * swap — when a 204 of the person's own Delete arrived after it (PO-6, po-6-red.md "Spar on the :384 residual").
   */
  readonly onRereadFailed: (failure: ReadFailure, asOf: number, sentAsOf?: number) => void;
  /** PO-6: after a 204, before the delete's re-read is sent: the page raises what it shows to that re-read's stamp (the 204 floor). */
  readonly onDeleted?: (asOf: number) => void;
  /**
   * PO-7: after a 200 of Cancel order, before its re-read is sent: the page raises what it shows to that re-read's stamp, and
   * a Save sent before it is silenced as before a 204 (the cancel floor, po-7-progress.md).
   */
  readonly onCancelled?: (asOf: number) => void;
  /**
   * PO-8: after a 200 of Approve, before its re-read is sent: the page raises what it shows to that re-read's stamp (the
   * approve floor, po-8-progress.md; the page never lets a Save of its own overlap an approval, po-8-red.md H-1).
   */
  readonly onApproved?: (asOf: number) => void;
}

/**
 * PO-9: a live draft's deleted lines, read under the action's deadline; `undefined` (not read: the page keeps what it shows)
 * when the page shows no Deleted lines or the order is not a live draft (the page shows them on a live draft only).
 */
async function deletedFor(w: OrderWiring, order: PurchaseOrder, signal: AbortSignal): Promise<LinesLoad | undefined> {
  if (w.readDeleted !== true || order.archived_at !== null || order.status !== 'draft') return undefined;
  return loadDeletedLines(readOf(w.caller), order.id, signal);
}

/** A live order's lines, read under the action's deadline; `null` for a deleted order or a viewer without the read. */
async function linesFor(w: OrderWiring, order: PurchaseOrder, signal: AbortSignal): Promise<LinesLoad | null> {
  if (order.archived_at !== null || !w.readLines) return null;
  return loadLines(readOf(w.caller), order.id, signal);
}

// Restore purchase order (the supplierRestore precedent): after any refusal the order is re-read; one that reads live was
// restored already and the page turns live (`already-live`); otherwise the refusal is returned as sent and Restore stays.
export function orderRestore(w: OrderWiring): RestoreOrderAction {
  return async () => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.orderRestore);
    try {
      const r = await w.caller.call('RestorePurchaseOrder', { id: w.id }, undefined, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused': {
            const s = w.stamp();
            const again = await loadPurchaseOrder(readOf(w.caller), w.id, deadline.signal);
            if (again.kind === 'ok' && again.order.archived_at === null) {
              const lines = await linesFor(w, again.order, deadline.signal);
              await w.setOrder(again.order, lines, s, undefined, await deletedFor(w, again.order, deadline.signal));
              return { kind: 'already-live', order: again.order, message: r.message };
            }
            if (again.kind !== 'ok') w.onRereadFailed(again.kind, s);
            return r;
          }
          case 'not-found':
          case 'forbidden':
          case 'session-ended':
          case 'unreachable':
          case 'server-error':
            return r;
          default:
            return { kind: 'server-error' };
        }
      }
      if (!isPurchaseOrder(r.body)) return { kind: 'server-error' };
      const s = w.stamp();
      const again = await loadPurchaseOrder(readOf(w.caller), w.id, deadline.signal);
      if (again.kind === 'ok') {
        const lines = await linesFor(w, again.order, deadline.signal);
        await w.setOrder(again.order, lines, s, undefined, await deletedFor(w, again.order, deadline.signal));
      } else {
        // The 200 body is the server's new state: commit it (with its lines), so the page never says "restored" over the
        // deleted banner.
        const lines = await linesFor(w, r.body, deadline.signal);
        await w.setOrder(r.body, lines, s, undefined, await deletedFor(w, r.body, deadline.signal));
        w.onRereadFailed(again.kind, s);
      }
      return { kind: 'restored', order: r.body };
    } finally {
      deadline.cancel();
    }
  };
}

// Create purchase order (PO-3; the `supplierCreate` shape). `CreatePurchaseOrder` requires an Idempotency-Key but the page
// never replays one (a lost answer says "check the Draft list", never "press again"), so a fresh key is minted for EVERY
// submit (`newKey`) and never stored. The body is the three members copied by name (never a spread): the chosen supplier,
// the chosen warehouse and that supplier's served currency (PO D-5 (a)). Never `lines` (the draft is created header-first;
// `lines: []` would be refused by the server's minimum of one) and never `expected_at` (D-4 unruled). No re-read: the page
// navigates to the new order, which reads it. No refusal code is compared.
export function orderCreate(caller: ApiCaller, newKey: () => string = () => crypto.randomUUID()): CreateOrderAction {
  return async (values) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.orderCreate);
    try {
      const body: CreatePurchaseOrderInput = { supplier_id: values.supplier_id, warehouse_id: values.warehouse_id, currency_code: values.currency_code };
      const r = await caller.call('CreatePurchaseOrder', {}, body, { signal: deadline.signal, idempotencyKey: newKey() });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused':
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, CREATE_ORDER_FIELDS) };
            return r;
          case 'not-found':
          case 'forbidden':
          case 'session-ended':
          case 'unreachable':
          case 'server-error':
            return r;
          default:
            return { kind: 'server-error' };
        }
      }
      return isPurchaseOrder(r.body) ? { kind: 'created', order: r.body } : { kind: 'server-error' };
    } finally {
      deadline.cancel();
    }
  };
}

// Save (PO-4a; the `supplierSave` shape). No Idempotency-Key: a merge patch, whose repeat finds nothing to change. The body
// is the changed members only, copied by name from `ORDER_FIELDS` (Deliver to), never from a spread: never `supplier_id`
// (PO D-6), never `expected_at` (D-4 unruled). One deadline covers the request and its GetPurchaseOrder re-read, which is
// also run after a refusal to see whether the page was stale (plan §2.2 "Stale page": GetPurchaseOrder + lines; UX D-2).
// As Restore does: whenever this action commits a LIVE order, it reads that order's LIVE lines first (for a
// ListPurchaseOrderLines holder) and commits them with it in one commit, so the Total shown is never a newer server total
// over lines loaded earlier (po-4a-red.md M-2). A deleted reading carries no lines (`linesFor`).
export function orderSave(w: OrderWiring): SaveOrderAction {
  return async (changes) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.orderSave);
    try {
      const body: OrderChange = {};
      for (const f of ORDER_FIELDS) if (changes[f] !== undefined) body[f] = changes[f];
      // PO-6: the stamp of the moment the PATCH is sent. Its own answer body is committed only if no 204 arrived after it
      // (a Save that answers after a delete was processed before it; the warehouse / items rule, i3-0a0b RED H-1). The same
      // stamp goes back with `saved`, `refused-archived` and `no-longer-draft`, so the page can drop what such a Save would
      // say (the items UX ruling M-3 / T-17; po-6-red.md M-1).
      const sent = w.stamp();
      const r = await w.caller.call('UpdatePurchaseOrder', { id: w.id }, body, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused': {
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, ORDER_FIELDS) };
            // A refusal may prove the page stale (P8 deleted elsewhere, P9 approved or cancelled elsewhere). Re-read under
            // the same deadline. Only a reading that is no longer an editable draft is committed (the editor goes, the
            // page shows the record it read); a live draft is NOT committed, so the person's choice stays for another
            // press (P1).
            const s = w.stamp();
            const again = await loadPurchaseOrder(readOf(w.caller), w.id, deadline.signal);
            if (again.kind === 'ok' && (again.order.archived_at !== null || again.order.status !== 'draft')) {
              // Not a live draft: `deletedFor` reads none (Deleted lines is shown on a live draft only).
              await w.setOrder(again.order, await linesFor(w, again.order, deadline.signal), s);
              // A live order that is no longer a draft: UX D-2's sentence, built from what was read, whatever the refusal
              // (it is true of the page either way). A deleted one: the server's sentence (P8, true: Restore exists) —
              // except P1, which says "try again" and is never shown as the page's stale refusal (the suppliers rule).
              if (again.order.archived_at === null) return { kind: 'no-longer-draft', order: again.order, sentAsOf: sent };
              return r.rule === PLATFORM_REFUSAL.contention ? r : { kind: 'refused-archived', message: r.message, sentAsOf: sent };
            }
            if (again.kind !== 'ok') {
              w.onRereadFailed(again.kind, s, sent);
              if (r.rule === PURCHASE_ORDER_REFUSAL.notDraft) return { kind: 'changed-since-loaded' };
            }
            return r;
          }
          case 'not-found':
          case 'forbidden':
          case 'session-ended':
          case 'unreachable':
          case 'server-error':
            return r;
          default:
            return { kind: 'server-error' };
        }
      }
      if (!isPurchaseOrder(r.body)) return { kind: 'server-error' };
      const s = w.stamp();
      const again = await loadPurchaseOrder(readOf(w.caller), w.id, deadline.signal);
      // po-9-red.md M-1 (b): a Save's reading carries the deleted lines too (a line write may have run beside it, D-11), so the
      // reading that lands last never shows a line live and deleted at once, or neither.
      if (again.kind === 'ok') {
        const lines = await linesFor(w, again.order, deadline.signal);
        await w.setOrder(again.order, lines, s, undefined, await deletedFor(w, again.order, deadline.signal));
      } else {
        // The 200 body is the server's new state: commit it, so the page shows what was saved (Restore's rule), unless a
        // 204 arrived after this Save was sent (the body is then the order before the delete; PO-6).
        const lines = await linesFor(w, r.body, deadline.signal);
        await w.setOrder(r.body, lines, s, sent, await deletedFor(w, r.body, deadline.signal));
        w.onRereadFailed(again.kind, s, sent);
      }
      return { kind: 'saved', order: r.body, sentAsOf: sent };
    } finally {
      deadline.cancel();
    }
  };
}

/**
 * A line write (or, from PO-6, Delete purchase order on P9's rule) refused with a 409 that may prove the page stale (P8 / P9,
 * plan §2.2 "Stale page"; L10): the order is read
 * again under the write's deadline, stamped when sent. A reading that is no longer an editable draft is committed (with its
 * live lines) and named; with `commitLive` a live draft's reading is committed too (Delete: there is no typed choice to
 * keep, and a line deleted elsewhere must go). A failed re-read is the page's region error, and P9's rule then says UX D-2's
 * changed-since sentence; otherwise the refusal as sent.
 */
async function afterLineRefusal(w: OrderWiring, r: Refused, signal: AbortSignal, commitLive: boolean): Promise<Refused | { kind: 'refused-archived'; message: string } | { kind: 'no-longer-draft'; order: PurchaseOrder } | { kind: 'changed-since-loaded' }> {
  const s = w.stamp();
  const again = await loadPurchaseOrder(readOf(w.caller), w.id, signal);
  if (again.kind !== 'ok') {
    w.onRereadFailed(again.kind, s);
    return r.rule === PURCHASE_ORDER_REFUSAL.notDraft ? { kind: 'changed-since-loaded' } : r;
  }
  const editable = again.order.archived_at === null && again.order.status === 'draft';
  if (!editable || commitLive) {
    const lines = await linesFor(w, again.order, signal);
    // PO-9 / R3-L-1: the deleted lines are read with any reading committed here (none for a reading that is not a live draft).
    await w.setOrder(again.order, lines, s, undefined, await deletedFor(w, again.order, signal));
  }
  if (editable) return r;
  return again.order.archived_at === null ? { kind: 'no-longer-draft', order: again.order } : { kind: 'refused-archived', message: r.message };
}

/**
 * After a line write the server accepted: the order and its live lines are read again, stamped when sent, and committed in
 * one commit (the server recomputes `total_amount` on every line write, so the Total and the rows always come from one
 * reading — po-4a-red.md M-2's rule). Nothing is committed from the write's answer itself. Resolves `true` when the page now
 * shows both as read; `false` when either read failed (an order read failure is the page's region error; a lines read
 * failure is committed as the section's own state).
 */
async function rereadAfterLineWrite(w: OrderWiring, signal: AbortSignal, sentAsOf?: number): Promise<boolean> {
  const s = w.stamp();
  const again = await loadPurchaseOrder(readOf(w.caller), w.id, signal);
  if (again.kind !== 'ok') {
    w.onRereadFailed(again.kind, s, sentAsOf);
    return false;
  }
  const lines = await linesFor(w, again.order, signal);
  // PO-9: Delete line and Restore line change which lines are deleted, so the Deleted lines come in the same commit; from
  // PO-4b every line write's reading takes them (R3-L-1: Add line too — a line live here may have been edited and deleted
  // elsewhere, and only a fresh deleted reading shows its numbers).
  await w.setOrder(again.order, lines, s, sentAsOf, await deletedFor(w, again.order, signal));
  return lines === null || lines.kind === 'ok';
}

// Add line (PO-5; the `orderCreate` shape for the key, the `orderSave` shape for the refusals). `CreatePurchaseOrderLine`
// requires an Idempotency-Key but the row never replays one (a lost answer says "check this order's lines", never "press
// again"), so a fresh key is minted for EVERY submit (`newKey`) and never stored. The body is the three members copied by
// name (never a spread), the quantity and the cost as typed. One deadline covers the request and its re-read. A 422 is mapped
// onto the row; H-2's violation (`ITEM_UNAVAILABLE`, both members) is flagged so the row says UX's client sentence for it.
// L1 (`notListed`) is returned as such (the row reads the catalogue again). P1 is returned as sent ("try again"). Any other
// 409 goes through the stale re-read, which commits only a reading that is no longer an editable draft (the row's typed text
// stays for another press).
export function lineCreate(w: OrderWiring, newKey: () => string = () => crypto.randomUUID()): AddLineAction {
  return async (values): Promise<AddLineResult> => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.lineCreate);
    try {
      const body: CreatePurchaseOrderLineInput = { inventory_item_id: values.inventory_item_id, quantity: values.quantity, unit_cost: values.unit_cost };
      const r = await w.caller.call('CreatePurchaseOrderLine', { id: w.id }, body, { signal: deadline.signal, idempotencyKey: newKey() });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused': {
            if (r.violations !== undefined && r.violations.length > 0) {
              const itemUnavailable = r.violations.some((v) => v.pointer === ITEM_UNAVAILABLE.pointer && v.code === ITEM_UNAVAILABLE.code);
              return { kind: 'invalid', ...fieldErrorsFrom(r.violations, ADD_LINE_FIELDS), itemUnavailable };
            }
            if (r.rule === PURCHASE_ORDER_REFUSAL.notListed) return { kind: 'not-listed', message: r.message };
            if (r.rule === PLATFORM_REFUSAL.contention) return r;
            return afterLineRefusal(w, r, deadline.signal, false);
          }
          case 'not-found':
          case 'forbidden':
          case 'session-ended':
          case 'unreachable':
          case 'server-error':
            return r;
          default:
            return { kind: 'server-error' };
        }
      }
      if (!isPurchaseOrderLine(r.body)) return { kind: 'server-error' };
      const shown = await rereadAfterLineWrite(w, deadline.signal);
      return { kind: 'added', line: r.body, listStale: !shown };
    } finally {
      deadline.cancel();
    }
  };
}

// Delete line (PO-5; X-4: a direct row action, no dialog; the `entryDelete` shape for the request). No body, no
// Idempotency-Key: a repeat is refused W23 ("already archived"). A 204 is followed by the stamped re-read (order + lines, one
// commit). P1 is `contention`. Any other 409, and a 404, are followed by the re-read too, and ANY good reading is committed
// (a Delete keeps no typed choice, and a line already deleted elsewhere must leave the table); a reading that is no longer an
// editable draft is named as Save's.
export function lineDelete(w: OrderWiring): DeleteLineAction {
  return async (line): Promise<DeleteLineResult> => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.lineDelete);
    try {
      const r = await w.caller.call('DeletePurchaseOrderLine', { id: w.id, lineId: line.id }, undefined, { signal: deadline.signal });
      if (r.kind === 'ok') {
        await rereadAfterLineWrite(w, deadline.signal);
        return { kind: 'deleted' };
      }
      switch (r.kind) {
        case 'refused':
          if (r.rule === PLATFORM_REFUSAL.contention) return { kind: 'contention', message: r.message };
          return afterLineRefusal(w, r, deadline.signal, true);
        case 'not-found':
          await rereadAfterLineWrite(w, deadline.signal);
          return r;
        case 'forbidden':
        case 'session-ended':
        case 'unreachable':
        case 'server-error':
          return r;
        default:
          return { kind: 'server-error' };
      }
    } finally {
      deadline.cancel();
    }
  };
}

// Delete purchase order (PO-6; the `warehouseDelete` shape over this family's stale rule). No body, no Idempotency-Key: a
// repeat is refused P10 ("already archived"). One deadline covers the request and its re-read. After a 204 the page stays:
// the draft is archived, not gone. Before the re-read is sent, `onDeleted` raises what the page shows to the re-read's stamp
// (the 204 floor), so a reading requested before the delete (a Save's re-read still in flight) cannot undo it, even when this
// re-read fails. The re-read is committed: archived (the banner with Restore), or live with its lines (restored in between,
// S-2). A failed re-read still resolves `deleted` (the delete happened); the page is told through `onRereadFailed`.
// Refusals: P1 is `contention`; P9's rule (`refuseUnlessDraft()`, which Delete answers for a draft approved or cancelled
// elsewhere — plan Response L-1) is followed by a stamped re-read under the same deadline, as Save's: a reading that is no
// longer an editable draft is committed and named (UX D-2's sentence, never the server's developer words); a failed re-read
// is the page's region error and `changed-since-loaded`; an editable reading leaves the refusal as sent. Any other refusal
// (P10) is returned as sent: the page re-reads once the dialog closes (FE-D1).
export function orderDelete(w: OrderWiring): DeleteOrderAction {
  return async (): Promise<DeleteOrderResult> => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.orderDelete);
    try {
      const r = await w.caller.call('DeletePurchaseOrder', { id: w.id }, undefined, { signal: deadline.signal });
      if (r.kind === 'ok') {
        const s = w.stamp();
        w.onDeleted?.(s);
        const again = await loadPurchaseOrder(readOf(w.caller), w.id, deadline.signal);
        // R3-L-1: restored in between (a live draft), its deleted lines come with it.
        if (again.kind === 'ok') await w.setOrder(again.order, await linesFor(w, again.order, deadline.signal), s, undefined, await deletedFor(w, again.order, deadline.signal));
        else w.onRereadFailed(again.kind, s);
        return { kind: 'deleted' };
      }
      switch (r.kind) {
        case 'refused': {
          if (r.rule === PLATFORM_REFUSAL.contention) return { kind: 'contention', message: r.message };
          if (r.rule !== PURCHASE_ORDER_REFUSAL.notDraft) return r;
          const stale = await afterLineRefusal(w, r, deadline.signal, false);
          // `refused-archived` cannot follow P9's rule unless the order was deleted in between: the server's sentence then.
          return stale.kind === 'refused-archived' ? { kind: 'refused', rule: r.rule, message: stale.message } : stale;
        }
        case 'not-found':
        case 'forbidden':
        case 'session-ended':
        case 'unreachable':
        case 'server-error':
          return r;
        default:
          return { kind: 'server-error' };
      }
    } finally {
      deadline.cancel();
    }
  };
}

// Cancel order (PO-7; the `orderDelete` shape over this family's stale rule). Body `{}` when the Reason box is empty,
// `{ reason }` exactly as typed otherwise (X-5; the server trims, and a blank reason is none there); never `null`. No
// Idempotency-Key: `guard:purchase_order.status` (a repeat is refused C2). One deadline covers the request and its re-read.
// After a 200, `onCancelled` raises what the page shows to the re-read's stamp before that re-read is sent (the cancel
// floor), then the order and its live lines are read and committed in one commit; if that re-read fails, the 200 body (the
// server's new state) is committed with its lines under the same stamp, so the page never says "cancelled" over an ordered
// page, and the page is told. Refusals: P1 is `contention`; C2's rule (`PurchaseOrder::cancel()`'s status guard: cancelled
// or received in full elsewhere) is followed by a stamped re-read under the same deadline whose reading is committed
// whatever it is (there is no typed choice to keep: the dialog is final after C2), and named — cancelled →
// `already-cancelled`, received → `already-received` (UX D-2's sentences), anything else the refusal as sent; a failed
// re-read is the page's region error and `changed-since-loaded`. Any other refusal (C1 DSoD-1, C3 archived) is returned as
// sent: the page re-reads once the dialog closes (FE-D1).
export function orderCancel(w: OrderWiring): CancelOrderAction {
  return async (reason): Promise<CancelOrderResult> => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.orderCancel);
    try {
      const body: CancelPurchaseOrderInput = reason === '' ? {} : { reason };
      const r = await w.caller.call('CancelPurchaseOrder', { id: w.id }, body, { signal: deadline.signal });
      if (r.kind === 'ok') {
        if (!isPurchaseOrder(r.body)) return { kind: 'server-error' };
        const s = w.stamp();
        w.onCancelled?.(s);
        const again = await loadPurchaseOrder(readOf(w.caller), w.id, deadline.signal);
        if (again.kind === 'ok') await w.setOrder(again.order, await linesFor(w, again.order, deadline.signal), s);
        else {
          await w.setOrder(r.body, await linesFor(w, r.body, deadline.signal), s);
          w.onRereadFailed(again.kind, s);
        }
        return { kind: 'cancelled', order: r.body };
      }
      switch (r.kind) {
        case 'refused': {
          if (r.rule === PLATFORM_REFUSAL.contention) return { kind: 'contention', message: r.message };
          if (r.rule !== PURCHASE_ORDER_REFUSAL.illegalTransition) return r;
          const s = w.stamp();
          const again = await loadPurchaseOrder(readOf(w.caller), w.id, deadline.signal);
          if (again.kind !== 'ok') {
            w.onRereadFailed(again.kind, s);
            return { kind: 'changed-since-loaded' };
          }
          await w.setOrder(again.order, await linesFor(w, again.order, deadline.signal), s);
          if (again.order.status === 'cancelled') return { kind: 'already-cancelled', order: again.order };
          if (again.order.status === 'received') return { kind: 'already-received', order: again.order };
          return r;
        }
        case 'not-found':
        case 'forbidden':
        case 'session-ended':
        case 'unreachable':
        case 'server-error':
          return r;
        default:
          return { kind: 'server-error' };
      }
    } finally {
      deadline.cancel();
    }
  };
}

// Approve (PO-8; the `orderCancel` shape over this family's stale rule). Body always `{}`: the dialog has no expected date
// while D-4 is unruled, so the order's date is kept (absent = keep, `ApprovePurchaseOrderInput.php`). No Idempotency-Key:
// `guard:purchase_order.status` (a repeat is refused A2). One deadline covers the request and its re-read. After a 200,
// `onApproved` raises what the page shows to the re-read's stamp before that re-read is sent (the approve floor), then the
// order and its live lines are read and committed in one commit; if that re-read fails, the 200 body (the server's new state)
// is committed with its lines under the same stamp, so the page never says "approved" over a draft, and the page is told.
// Refusals: P1 is `contention`; A2's rule (`PurchaseOrder::place()`'s status guard: approved, or cancelled, elsewhere) is
// followed by a stamped re-read under the same deadline whose reading is committed whatever it is (the dialog is final after
// A2: nothing typed to keep), and named — ordered / partly received / received → `already-approved`, cancelled →
// `no-longer-draft` (UX D-2's sentences), anything else the refusal as sent; a failed re-read is the page's region error and
// `changed-since-loaded`. A1's rule (DSoD-1, which the server checks before the status guard) gets the same re-read and the
// same two names; a draft reading, or a failed re-read, leaves A1 as sent (po-8-red.md L-1). Any other refusal (A3, A4, A5,
// A6) is returned as sent: the page re-reads once the dialog closes (FE-D1).
export function orderApprove(w: OrderWiring): ApproveOrderAction {
  return async (): Promise<ApproveOrderResult> => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.orderApprove);
    try {
      const body: ApprovePurchaseOrderInput = {};
      const r = await w.caller.call('ApprovePurchaseOrder', { id: w.id }, body, { signal: deadline.signal });
      if (r.kind === 'ok') {
        if (!isPurchaseOrder(r.body)) return { kind: 'server-error' };
        const s = w.stamp();
        w.onApproved?.(s);
        const again = await loadPurchaseOrder(readOf(w.caller), w.id, deadline.signal);
        if (again.kind === 'ok') await w.setOrder(again.order, await linesFor(w, again.order, deadline.signal), s);
        else {
          await w.setOrder(r.body, await linesFor(w, r.body, deadline.signal), s);
          w.onRereadFailed(again.kind, s);
        }
        return { kind: 'approved', order: r.body };
      }
      switch (r.kind) {
        case 'refused': {
          if (r.rule === PLATFORM_REFUSAL.contention) return { kind: 'contention', message: r.message };
          const stale = r.rule === PURCHASE_ORDER_REFUSAL.illegalTransition;
          // A1 (DSoD-1) is checked before the status guard, so it is also what a creator on a stale page is told about an
          // order approved elsewhere (po-8-red.md L-1): it gets the same re-read. Its own sentence stays true when the reading
          // is still a draft, or when the re-read fails.
          if (!stale && r.rule !== PURCHASE_ORDER_REFUSAL.approverIsCreator) return r;
          const s = w.stamp();
          const again = await loadPurchaseOrder(readOf(w.caller), w.id, deadline.signal);
          if (again.kind !== 'ok') {
            w.onRereadFailed(again.kind, s);
            return stale ? { kind: 'changed-since-loaded' } : r;
          }
          // R3-L-1: A1 on a draft commits a live draft's reading; its deleted lines come with it.
          await w.setOrder(again.order, await linesFor(w, again.order, deadline.signal), s, undefined, await deletedFor(w, again.order, deadline.signal));
          const st = again.order.status;
          if (again.order.archived_at === null && (st === 'ordered' || st === 'partially_received' || st === 'received')) return { kind: 'already-approved', order: again.order };
          if (again.order.archived_at === null && st === 'cancelled') return { kind: 'no-longer-draft', order: again.order };
          return r;
        }
        case 'not-found':
        case 'forbidden':
        case 'session-ended':
        case 'unreachable':
        case 'server-error':
          return r;
        default:
          return { kind: 'server-error' };
      }
    } finally {
      deadline.cancel();
    }
  };
}

// Restore line (PO-9; the `lineDelete` shape; no dialog, the restore precedent). No body, no Idempotency-Key: guarded by
// `deleted_at` (a repeat is refused W24). The stamp of the moment the request is sent goes back with every answer, and with
// each re-read's commit, so the page drops what an answer to a request sent before the person's own Delete purchase order,
// Cancel order or Approve would commit, say or show (the page's own-outcome floors; behind the page's one-write-at-a-time
// guard those cannot overlap a Restore, so this is defence). A 200 is followed by the stamped re-read (order, live lines and
// deleted lines, one commit). P1 is `contention`. Any other 409, and a 404, are followed by that re-read too, and ANY good
// reading is committed (the row must leave Deleted lines if the line came back elsewhere); a reading that is no longer an
// editable draft is named as Delete line's; one that shows the line live is `already-restored` (W24, R1-17); otherwise the
// refusal as sent (L8), its row and Restore kept. A failed re-read is the page's region error; P9's rule then says UX D-2's
// changed-since sentence.
export function lineRestore(w: OrderWiring): RestoreLineAction {
  return async (line) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.lineRestore);
    const sent = w.stamp();
    const done = (result: RestoreLineResult) => ({ result, sentAsOf: sent });
    try {
      const r = await w.caller.call('RestorePurchaseOrderLine', { id: w.id, lineId: line.id }, undefined, { signal: deadline.signal });
      if (r.kind === 'ok') {
        if (!isPurchaseOrderLine(r.body)) return done({ kind: 'server-error' });
        const shown = await rereadAfterLineWrite(w, deadline.signal, sent);
        return done({ kind: 'restored', listStale: !shown });
      }
      switch (r.kind) {
        case 'refused': {
          if (r.rule === PLATFORM_REFUSAL.contention) return done({ kind: 'contention', message: r.message });
          const s = w.stamp();
          const again = await loadPurchaseOrder(readOf(w.caller), w.id, deadline.signal);
          if (again.kind !== 'ok') {
            w.onRereadFailed(again.kind, s, sent);
            // po-9-red.md N-3: W24 says the line is live whatever the re-read returned (R1-17: its words are never shown).
            return done(r.rule === PURCHASE_ORDER_REFUSAL.notDraft ? { kind: 'changed-since-loaded' } : r.rule === PURCHASE_ORDER_REFUSAL.lineNotArchived ? { kind: 'already-restored' } : r);
          }
          const lines = await linesFor(w, again.order, deadline.signal);
          await w.setOrder(again.order, lines, s, sent, await deletedFor(w, again.order, deadline.signal));
          if (again.order.archived_at !== null) return done({ kind: 'refused-archived', message: r.message });
          if (again.order.status !== 'draft') return done({ kind: 'no-longer-draft', order: again.order });
          // po-9-red.md L-1: W24 says the line is live whatever the lines read returned (R1-17: its words are never shown).
          if (r.rule === PURCHASE_ORDER_REFUSAL.lineNotArchived) return done({ kind: 'already-restored' });
          if (lines !== null && lines.kind === 'ok' && lines.lines.some((l) => l.id === line.id)) return done({ kind: 'already-restored' });
          return done(r);
        }
        case 'not-found':
          await rereadAfterLineWrite(w, deadline.signal, sent);
          return done(r);
        case 'forbidden':
        case 'session-ended':
        case 'unreachable':
        case 'server-error':
          return done(r);
        default:
          return done({ kind: 'server-error' });
      }
    } finally {
      deadline.cancel();
    }
  };
}

// Save line (PO-4b; the `orderSave` shape for the body, the `lineRestore` shape for the stamps). No Idempotency-Key: a merge
// patch, whose repeat finds nothing to change. The body is the row's changed members only, copied by name from
// `EDIT_LINE_FIELDS`, as typed. The stamp of the moment the PATCH is sent goes back with every answer and every commit, so
// the page drops what an answer to a Save sent before the person's own Delete purchase order, Cancel order or Approve would
// commit, say or show (behind the page's one-write-at-a-time guard those cannot overlap a Save: defence). A 200 is followed
// by the header-only re-read (plan R1-5 PO-4b, M-9): GetPurchaseOrder alone, committed with the lines kept as shown, so
// other rows' typed text and the lines list stand and the Total is the server's new one; the lines are read only when that
// reading is no longer a live draft (no row is editable then, and its lines must match it). Its `asOf` (the stamp of that
// re-read) goes back with `saved`, so the row shows the 200 body until a lines reading requested after it is on screen. A
// 422 is mapped onto the row. P1 is `contention` (the row's text kept, nothing re-read). Any other 409 is followed by a
// re-read of the order, its live lines and (shown) its deleted lines, committed whatever it is (other rows keep their typed
// text, M-6): not a live draft → the stale kinds; the line no longer live in it, or the lines not read → `gone` (the row has
// left; the server's sentence as sent, W23's precedent); otherwise the refusal as sent, the row kept. A 404 is followed by
// that re-read too → `gone`. A failed re-read is the page's region error; P9's rule then says UX D-2's changed-since sentence.
export function lineSave(w: OrderWiring): SaveLineAction {
  return async (line, changes) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.lineSave);
    const sent = w.stamp();
    const done = (result: SaveLineResult) => ({ result, sentAsOf: sent });
    try {
      const body: LineChange = {};
      for (const f of EDIT_LINE_FIELDS) if (changes[f] !== undefined) body[f] = changes[f];
      const r = await w.caller.call('UpdatePurchaseOrderLine', { id: w.id, lineId: line.id }, body, { signal: deadline.signal });
      if (r.kind === 'ok') {
        if (!isPurchaseOrderLine(r.body)) return done({ kind: 'server-error' });
        const s = w.stamp();
        const again = await loadPurchaseOrder(readOf(w.caller), w.id, deadline.signal);
        if (again.kind !== 'ok') {
          w.onRereadFailed(again.kind, s, sent);
          return done({ kind: 'saved', line: r.body, asOf: s, orderStale: true });
        }
        const editable = again.order.archived_at === null && again.order.status === 'draft';
        await w.setOrder(again.order, editable ? null : await linesFor(w, again.order, deadline.signal), s, sent);
        return done({ kind: 'saved', line: r.body, asOf: s, orderStale: false });
      }
      switch (r.kind) {
        case 'refused': {
          if (r.violations !== undefined && r.violations.length > 0) return done({ kind: 'invalid', ...fieldErrorsFrom(r.violations, EDIT_LINE_FIELDS) });
          if (r.rule === PLATFORM_REFUSAL.contention) return done({ kind: 'contention', message: r.message });
          const s = w.stamp();
          const again = await loadPurchaseOrder(readOf(w.caller), w.id, deadline.signal);
          if (again.kind !== 'ok') {
            w.onRereadFailed(again.kind, s, sent);
            return done(r.rule === PURCHASE_ORDER_REFUSAL.notDraft ? { kind: 'changed-since-loaded' } : r);
          }
          const lines = await linesFor(w, again.order, deadline.signal);
          await w.setOrder(again.order, lines, s, sent, await deletedFor(w, again.order, deadline.signal));
          if (again.order.archived_at !== null) return done({ kind: 'refused-archived', message: r.message });
          if (again.order.status !== 'draft') return done({ kind: 'no-longer-draft', order: again.order });
          if (lines === null || lines.kind !== 'ok' || !lines.lines.some((l) => l.id === line.id)) return done({ kind: 'gone', message: r.message });
          // po-4b-red.md L-4: deleted when the PATCH arrived, live again in the re-read (restored in between): the server's
          // "archived" is no longer true of the row, so UX D-2's changed-since sentence.
          if (r.rule === PURCHASE_ORDER_REFUSAL.lineArchived) return done({ kind: 'changed-since-loaded' });
          return done(r);
        }
        case 'not-found':
          await rereadAfterLineWrite(w, deadline.signal, sent);
          return done({ kind: 'gone', message: '' });
        case 'forbidden':
        case 'session-ended':
        case 'unreachable':
        case 'server-error':
          return done(r);
        default:
          return done({ kind: 'server-error' });
      }
    } finally {
      deadline.cancel();
    }
  };
}

/** GR-2: a 422 pointer naming one row's field, `/lines/{request index}/{field}`. */
const RECEIVE_POINTER = /^\/lines\/(\d+)\/(quantity|storage_location_id)$/;
/** GR-2 (final RED M-1): the order line a served `stock_level.on_hand_fits` sentence names (a 409 carries no pointer). */
const ORDER_LINE_NAMED = /^Order line (\d+)\b/;

// Record receipt (GR-2; plan §2.6, Revision 1 R1-5 GR-2). GR-2 H-1 (gr-2-h1-spar.md converged text): ONE Idempotency-Key per
// action (= per receive page, which creates the action once), held in a `heldKey(newKey)` holder and taken BEFORE the send
// (write-ahead: a throwing caller leaves it held). It is rotated (`consumed()`) only once known used up: after a 201 whose
// body passes `isGoodsReceipt` (never on the raw ok), and after a 409 `idempotency-key-conflict` (the explicit `problem`
// discriminator). Every other answer keeps it — an unknown outcome may have committed, and a refusal commits nothing — so a
// retry from this page replays, conflicts, or runs once, never records the delivery twice. The body is never compared; a
// changed body goes out under the held key and the server decides. A conflict is checked FIRST in the refused arm, then the
// order (and, when receivable, its lines) is re-read → `earlier-recorded` in three variants; the server's detail is never
// carried. The form never sends two at once. The body is one
// `{purchase_order_line_id, quantity, storage_location_id}` per row the form hands over, copied by name in its order, the
// quantity as typed; never `received_at`. One deadline covers the request and every re-read after it. After a 201 the order
// is re-read for the arrival sentence's status (a failed re-read gives `order: null`). A 422 is keyed by REQUEST index
// (the form maps it back, M-6). R3 (`onHandFits`) is named by its order line and shown in the client's words (final RED
// M-1). P1 is returned as sent ("try again"). Any other 409 is followed by a re-read of the order and its lines, and what
// that re-read shows decides (a refusal's re-read is checked before anything is said): an order nothing can be received on
// → `order-changed`; R1 (`overReceived`) over a receivable order → `over-received` with the new lines (X-15); otherwise the
// refusal as sent.
export function receiptCreate(caller: ApiCaller, orderId: string, newKey: () => string = () => crypto.randomUUID()): ReceiveAction {
  const held = heldKey(newKey);
  return async (lines): Promise<ReceiveResult> => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.receiptCreate);
    try {
      const body: CreateGoodsReceiptInput = { lines: lines.map((l) => ({ purchase_order_line_id: l.purchase_order_line_id, quantity: l.quantity, storage_location_id: l.storage_location_id })) };
      const r = await caller.call('CreateGoodsReceipt', { id: orderId }, body, { signal: deadline.signal, idempotencyKey: held.key() });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused': {
            if (r.problem === 'idempotency-key-conflict') {
              held.consumed();
              // gr-2-round2-red.md L-1: the key is already consumed, so a re-read that throws is a failed re-read, never a rejection.
              const again = await loadPurchaseOrder(readOf(caller), orderId, deadline.signal).catch(() => ({ kind: 'server-error' as const }));
              if (again.kind !== 'ok') return { kind: 'earlier-recorded', order: null, lines: null };
              if (!isReceivable(again.order)) return { kind: 'earlier-recorded', order: again.order, lines: null };
              const fresh = await loadLines(readOf(caller), orderId, deadline.signal).catch(() => ({ kind: 'server-error' as const }));
              // L-5: only the whole set counts (round-1 L-2's rule for the first read).
              return fresh.kind === 'ok' && fresh.nextCursor === null ? { kind: 'earlier-recorded', order: again.order, lines: fresh.lines } : { kind: 'earlier-recorded', order: null, lines: null };
            }
            if (r.violations !== undefined && r.violations.length > 0) {
              const rows = new Map<string, Partial<Record<ReceiveRowField, string>>>();
              const formErrors: string[] = [];
              for (const v of r.violations) {
                const m = RECEIVE_POINTER.exec(v.pointer);
                if (m === null) {
                  formErrors.push(v.message);
                  continue;
                }
                const index = m[1]!;
                const field = m[2] as ReceiveRowField;
                const row = rows.get(index) ?? {};
                if (row[field] === undefined || row[field] === '') row[field] = v.message;
                rows.set(index, row);
              }
              return { kind: 'invalid', rows, formErrors };
            }
            if (r.rule === PLATFORM_REFUSAL.contention) return r;
            if (r.rule === PURCHASE_ORDER_REFUSAL.onHandFits) {
              const m = ORDER_LINE_NAMED.exec(r.message);
              return { kind: 'on-hand-too-large', lineNumber: m === null ? null : m[1]! };
            }
            const order = await loadPurchaseOrder(readOf(caller), orderId, deadline.signal);
            if (order.kind !== 'ok') return r;
            if (!isReceivable(order.order)) return { kind: 'order-changed', order: order.order };
            if (r.rule !== PURCHASE_ORDER_REFUSAL.overReceived) return r;
            const fresh = await loadLines(readOf(caller), orderId, deadline.signal);
            return fresh.kind === 'ok' && fresh.nextCursor === null ? { kind: 'over-received', message: r.message, order: order.order, lines: fresh.lines } : r;
          }
          case 'not-found':
          case 'forbidden':
          case 'session-ended':
          case 'unreachable':
          case 'server-error':
            return r;
          default:
            return { kind: 'server-error' };
        }
      }
      if (!isGoodsReceipt(r.body)) return { kind: 'server-error' };
      held.consumed();
      const order = await loadPurchaseOrder(readOf(caller), orderId, deadline.signal).catch(() => ({ kind: 'server-error' as const }));
      return { kind: 'recorded', receipt: r.body, order: order.kind === 'ok' ? order.order : null };
    } finally {
      deadline.cancel();
    }
  };
}
