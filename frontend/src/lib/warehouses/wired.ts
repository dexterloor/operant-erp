import type { Warehouse } from '../api/warehouses';
import type { ApiCaller } from '../bff/apiCall';
import { ACTION_DEADLINES_MS, startDeadline } from '../bff/deadline';
import { readOf, type ReadFailure } from '../bff/reads';
import { fieldErrorsFrom } from '../forms/fieldErrors';
import { isStorageLocation, isWarehouse, loadWarehouse } from './load';
import { CREATE_LOCATION_FIELDS, CREATE_WAREHOUSE_FIELDS, LOCATION_FIELDS, WAREHOUSE_FIELDS, type CreateLocationAction, type CreateWarehouseAction, type LocationChange, type RestoreLocationAction, type RestoreWarehouseAction, type SaveLocationAction, type SaveWarehouseAction } from './results';

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

// Create warehouse over the BFF (Revision 7 §2 step 4.3; A-19 items 1, 6). `CreateWarehouse` requires an Idempotency-Key
// but does not replay on it (`WarehouseWriter.php:42-43`), so a fresh key is minted for EVERY submit (`newKey`, the B-3b
// action precedent) and never stored (F-6). A 201 is the new warehouse; the page navigates to it, which reads it.
export function warehouseCreate(caller: ApiCaller, newKey: () => string = () => crypto.randomUUID()): CreateWarehouseAction {
  return async (input) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.warehouseCreate);
    try {
      const r = await caller.call('CreateWarehouse', {}, input, { signal: deadline.signal, idempotencyKey: newKey() });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused':
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, CREATE_WAREHOUSE_FIELDS) };
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
      return { kind: 'created', warehouse: r.body };
    } finally {
      deadline.cancel();
    }
  };
}

// Add location over the BFF (Revision 7 §2 step 4.5; A-19 item 6). `CreateStorageLocation` requires an Idempotency-Key
// but does not replay on it (`CreateStorageLocationProcessor.php:15-16`: the live (warehouse, code) unique refuses a
// repeat), so a fresh key is minted for EVERY submit (`newKey`, the `warehouseCreate` precedent) and never stored. No
// re-read here: the section re-reads its view after the dialog closes (FE-D1). No refusal code is compared.
export function locationCreate(caller: ApiCaller, warehouseId: string, newKey: () => string = () => crypto.randomUUID()): CreateLocationAction {
  return async (input) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.locationCreate);
    try {
      const r = await caller.call('CreateStorageLocation', { id: warehouseId }, input, { signal: deadline.signal, idempotencyKey: newKey() });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused':
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, CREATE_LOCATION_FIELDS) };
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
      return isStorageLocation(r.body) ? { kind: 'created', location: r.body } : { kind: 'server-error' };
    } finally {
      deadline.cancel();
    }
  };
}

// The Locations section's row actions over the BFF (Revision 7 §2 steps 4.4, 4.5). Neither takes an Idempotency-Key: the
// update is a merge patch (a repeat finds nothing to change) and the restore is guarded by `deleted_at` (a repeat is
// refused L11), so pressing again is safe. Neither re-reads here: the section re-reads its own view through its list
// controller after the dialog has closed or the row's message is spoken (FE-D1: close → focus → announce → refresh).
// No refusal code is compared.
export function locationSave(caller: ApiCaller, warehouseId: string): SaveLocationAction {
  return async (locationId, change) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.locationSave);
    try {
      // The body is the changed members only (§2 step 4.5), copied by name from the two fields, never from a spread, so
      // nothing else is sent and an unchanged kind is never sent.
      const body: LocationChange = {};
      if (change.code !== undefined) body.code = change.code;
      if (change.kind !== undefined) body.kind = change.kind;
      const r = await caller.call('UpdateStorageLocation', { id: warehouseId, locationId }, body, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused':
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, LOCATION_FIELDS) };
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
      return isStorageLocation(r.body) ? { kind: 'saved', location: r.body } : { kind: 'server-error' };
    } finally {
      deadline.cancel();
    }
  };
}

export function locationRestore(caller: ApiCaller, warehouseId: string): RestoreLocationAction {
  return async (locationId) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.locationRestore);
    try {
      const r = await caller.call('RestoreStorageLocation', { id: warehouseId, locationId }, undefined, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused':
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
      return isStorageLocation(r.body) ? { kind: 'restored', location: r.body } : { kind: 'server-error' };
    } finally {
      deadline.cancel();
    }
  };
}
