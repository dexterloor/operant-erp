import type { CreatePurchaseOrderInput, PurchaseOrder } from '../api/purchasing';
import type { ApiCaller } from '../bff/apiCall';
import { ACTION_DEADLINES_MS, startDeadline } from '../bff/deadline';
import { readOf, type ReadFailure } from '../bff/reads';
import { fieldErrorsFrom } from '../forms/fieldErrors';
import { isPurchaseOrder, loadLines, loadPurchaseOrder, type LinesLoad } from './load';
import { CREATE_ORDER_FIELDS, type CreateOrderAction, type RestoreOrderAction } from './results';

// The purchasing family's actions over the BFF (Revision 8 R8-2; the lib/suppliers/wired.ts shape). PO-2 shipped
// Restore purchase order; PO-3 adds Create purchase order (at the end of this file). Restore takes no Idempotency-Key:
// it is guarded by `deleted_at` (a repeat is refused P12).
// One deadline covers the request, its GetPurchaseOrder re-read and, for a ListPurchaseOrderLines holder, the order's live
// lines. No refusal code is compared on the page or here: a refusal is followed by a re-read, and what the re-read shows
// decides (live → `already-live`; still deleted → the refusal as sent, Restore kept — P1 included, whose sentence says
// "try again"). So this file does not import `lib/api/problems` (PROBLEMS_IMPORTERS unchanged; po-2-progress.md D-2).
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
