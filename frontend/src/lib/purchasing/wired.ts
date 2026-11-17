import { PURCHASE_ORDER_REFUSAL, type CreatePurchaseOrderInput, type PurchaseOrder } from '../api/purchasing';
import { PLATFORM_REFUSAL } from '../api/problems';
import type { ApiCaller } from '../bff/apiCall';
import { ACTION_DEADLINES_MS, startDeadline } from '../bff/deadline';
import { readOf, type ReadFailure } from '../bff/reads';
import { fieldErrorsFrom } from '../forms/fieldErrors';
import { isPurchaseOrder, loadLines, loadPurchaseOrder, type LinesLoad } from './load';
import { CREATE_ORDER_FIELDS, ORDER_FIELDS, type CreateOrderAction, type OrderChange, type RestoreOrderAction, type SaveOrderAction } from './results';

// The purchasing family's actions over the BFF (Revision 8 R8-2; the lib/suppliers/wired.ts shape). PO-2 shipped
// Restore purchase order; PO-3 Create purchase order; PO-4a adds Save (the draft's header, at the end of this file).
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
   * Sets the re-read order (and, for a live one read with its lines, those lines; `null` = no lines read) under the stamp
   * of the re-read, and resolves after the commit that shows them.
   */
  readonly setOrder: (order: PurchaseOrder, lines: LinesLoad | null, asOf: number) => Promise<void>;
  /** A re-read after a write did not answer an order: the page shows its region error, or swaps (ST-4b / ST-5). */
  readonly onRereadFailed: (failure: ReadFailure, asOf: number) => void;
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
              await w.setOrder(again.order, await linesFor(w, again.order, deadline.signal), s);
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
      if (again.kind === 'ok') await w.setOrder(again.order, await linesFor(w, again.order, deadline.signal), s);
      else {
        // The 200 body is the server's new state: commit it (with its lines), so the page never says "restored" over the
        // deleted banner.
        await w.setOrder(r.body, await linesFor(w, r.body, deadline.signal), s);
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
              await w.setOrder(again.order, await linesFor(w, again.order, deadline.signal), s);
              // A live order that is no longer a draft: UX D-2's sentence, built from what was read, whatever the refusal
              // (it is true of the page either way). A deleted one: the server's sentence (P8, true: Restore exists) —
              // except P1, which says "try again" and is never shown as the page's stale refusal (the suppliers rule).
              if (again.order.archived_at === null) return { kind: 'no-longer-draft', order: again.order };
              return r.rule === PLATFORM_REFUSAL.contention ? r : { kind: 'refused-archived', message: r.message };
            }
            if (again.kind !== 'ok') {
              w.onRereadFailed(again.kind, s);
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
      if (again.kind === 'ok') await w.setOrder(again.order, await linesFor(w, again.order, deadline.signal), s);
      else {
        // The 200 body is the server's new state: commit it, so the page shows what was saved (Restore's rule).
        await w.setOrder(r.body, await linesFor(w, r.body, deadline.signal), s);
        w.onRereadFailed(again.kind, s);
      }
      return { kind: 'saved', order: r.body };
    } finally {
      deadline.cancel();
    }
  };
}
