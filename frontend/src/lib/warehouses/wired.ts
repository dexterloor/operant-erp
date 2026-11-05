import type { Warehouse } from '../api/warehouses';
import type { ApiCaller } from '../bff/apiCall';
import { ACTION_DEADLINES_MS, startDeadline } from '../bff/deadline';
import { readOf, type ReadFailure } from '../bff/reads';
import { fieldErrorsFrom } from '../forms/fieldErrors';
import { isWarehouse, loadWarehouse } from './load';
import { WAREHOUSE_FIELDS, type RestoreWarehouseAction, type SaveWarehouseAction } from './results';

// The warehouse page's Save over the BFF (Revision 7 A-18, A-19). `UpdateWarehouse` is a merge patch with no
// Idempotency-Key: a repeat of a committed save finds nothing to change, so it is safe to press again. One deadline
// covers the request AND the GetWarehouse re-read. Caller contract: after `saved` the re-read warehouse is passed to
// `setWarehouse` (the form is never unmounted); a failed re-read is the page's to show.
//
// Blind spot, stated (step-3 X-2): C-2's body-key check (`test/contract/clientOps.test.ts`) reads object-literal request
// bodies only, so it does not see the body sent here, which the form builds with `changedFields`. What keeps the sent
// keys honest is that typing against `UpdateWarehouseInput`, which C-1 checks against the served schema.

export interface WarehouseSaveWiring {
  readonly caller: ApiCaller;
  readonly id: string;
  /** Sets the re-read warehouse and resolves after the commit that shows it. */
  readonly setWarehouse: (warehouse: Warehouse) => Promise<void>;
  /** The re-read after a save did not answer a warehouse: the page shows its region error, or swaps (ST-4b / ST-5). */
  readonly onRereadFailed: (failure: ReadFailure) => void;
}

export function warehouseSave(w: WarehouseSaveWiring): SaveWarehouseAction {
  return async (changes) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.warehouseSave);
    try {
      const r = await w.caller.call('UpdateWarehouse', { id: w.id }, changes, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused':
            // A 422 carries `violations` (problem.ts); a 409 does not, and is shown at form level as sent, as is a 422 whose
            // `errors[]` held no well-formed entry (its first sentence is then the refusal's `message`).
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, WAREHOUSE_FIELDS) };
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
      if (!isWarehouse(r.body)) return { kind: 'server-error' };
      const again = await loadWarehouse(readOf(w.caller), w.id, deadline.signal);
      if (again.kind === 'ok') await w.setWarehouse(again.warehouse);
      else w.onRereadFailed(again.kind);
      return { kind: 'saved', warehouse: r.body };
    } finally {
      deadline.cancel();
    }
  };
}

// Restore warehouse over the BFF (Revision 7 §2 step 4.2). `RestoreWarehouse` takes no body and no key; its 200 is the
// restored warehouse. As Save, one deadline covers the request AND the GetWarehouse re-read, and the re-read warehouse is
// passed to `setWarehouse`, so the page turns live without a remount. A refusal (W4, W5, P1) is returned as sent; no
// refusal code is compared here or on the page.
export function warehouseRestore(w: WarehouseSaveWiring): RestoreWarehouseAction {
  return async () => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.warehouseRestore);
    try {
      const r = await w.caller.call('RestoreWarehouse', { id: w.id }, undefined, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused': {
            // Any refusal: re-read under the same deadline. A warehouse that reads live was restored already (an
            // interrupted press that committed, another tab); the page turns live. No refusal code is compared.
            const again = await loadWarehouse(readOf(w.caller), w.id, deadline.signal);
            if (again.kind === 'ok' && again.warehouse.archived_at === null) {
              await w.setWarehouse(again.warehouse);
              return { kind: 'already-live', warehouse: again.warehouse, message: r.message };
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
      if (!isWarehouse(r.body)) return { kind: 'server-error' };
      const again = await loadWarehouse(readOf(w.caller), w.id, deadline.signal);
      if (again.kind === 'ok') await w.setWarehouse(again.warehouse);
      else {
        // The 200 body is the server's new state: commit it, so the page never says "restored" over the archived banner.
        await w.setWarehouse(r.body);
        w.onRereadFailed(again.kind);
      }
      return { kind: 'restored', warehouse: r.body };
    } finally {
      deadline.cancel();
    }
  };
}
