import type { Item } from '../api/items';
import { PLATFORM_REFUSAL } from '../api/problems';
import type { ApiCaller } from '../bff/apiCall';
import { ACTION_DEADLINES_MS, startDeadline } from '../bff/deadline';
import { readOf, type ReadFailure } from '../bff/reads';
import { fieldErrorsFrom } from '../forms/fieldErrors';
import { isItem, loadItem } from './load';
import { ITEM_FIELDS, type ItemChange, type RestoreItemAction, type SaveItemAction } from './results';

// The item page's Save and Restore over the BFF (Revision 8 R8-2; the lib/warehouses/wired.ts shape). Neither takes an
// Idempotency-Key: the update is a merge patch (a repeat finds nothing to change) and the restore is guarded by
// `deleted_at` (a repeat is refused UX-I4). One deadline covers each request AND its GetItem re-read. No refusal code is
// compared on the page: a refusal is followed by a re-read, and what the re-read shows decides (archived after a refused
// Save; live after a refused Restore). The ONE rule compared here is P1 (`PLATFORM_REFUSAL.contention`, R8-4: this file
// is the items family's one `lib/api/problems` importer): a contention refusal is always returned as `refused`, never
// `refused-archived`, because its sentence says "try again" (items-i1-red.md L-3, second option). A re-read that fails
// after a refusal is told to the page (`onRereadFailed`), so a stale page is never left silent (M-1).
//
// Blind spot, stated (the warehouses X-2 precedent): C-2's body-key check reads object-literal bodies only; the Save body
// is built by `changedFields` over `ITEM_FIELDS` and copied by name below, which is what keeps `supplier_id` and
// `unit_of_measure_code` out of it.

export interface ItemWiring {
  readonly caller: ApiCaller;
  readonly id: string;
  /** Sets the re-read item and resolves after the commit that shows it. */
  readonly setItem: (item: Item) => Promise<void>;
  /** A re-read after a write did not answer an item: the page shows its region error, or swaps (ST-4b / ST-5). */
  readonly onRereadFailed: (failure: ReadFailure) => void;
}

export function itemSave(w: ItemWiring): SaveItemAction {
  return async (changes) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.itemSave);
    try {
      // The changed members only, copied by name from the editable fields, never from a spread: nothing else is sent.
      const body: ItemChange = {};
      for (const f of ITEM_FIELDS) if (changes[f] !== undefined) body[f] = changes[f];
      const r = await w.caller.call('UpdateItem', { id: w.id }, body, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused': {
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, ITEM_FIELDS) };
            // 4.4 RED M-1: a refusal may prove the page stale (UX-I2: archived elsewhere). Re-read under the same
            // deadline; only an item that now reads archived is committed (the page turns read-only and shows the
            // sentence). A live re-read is NOT committed, so the person's edits stay for another press (P1, UX-I1).
            const again = await loadItem(readOf(w.caller), w.id, deadline.signal);
            if (again.kind === 'ok' && again.item.archived_at !== null) {
              await w.setItem(again.item);
              // P1 says "try again": it is never shown as the page's archived-elsewhere refusal (L-3).
              return r.rule === PLATFORM_REFUSAL.contention ? r : { kind: 'refused-archived', message: r.message };
            }
            if (again.kind !== 'ok') w.onRereadFailed(again.kind);
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
      if (!isItem(r.body)) return { kind: 'server-error' };
      const again = await loadItem(readOf(w.caller), w.id, deadline.signal);
      if (again.kind === 'ok') await w.setItem(again.item);
      else {
        // The 200 body is the server's new state: commit it, so the page shows what was saved (L-1, Restore's rule).
        await w.setItem(r.body);
        w.onRereadFailed(again.kind);
      }
      return { kind: 'saved', item: r.body };
    } finally {
      deadline.cancel();
    }
  };
}

// Restore item (the warehouseRestore precedent, 4.2 H-1): after any refusal the item is re-read; one that reads live was
// restored already and the page turns live (`already-live`); otherwise the refusal is returned as sent and Restore stays.
export function itemRestore(w: ItemWiring): RestoreItemAction {
  return async () => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.itemRestore);
    try {
      const r = await w.caller.call('RestoreItem', { id: w.id }, undefined, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused': {
            const again = await loadItem(readOf(w.caller), w.id, deadline.signal);
            if (again.kind === 'ok' && again.item.archived_at === null) {
              await w.setItem(again.item);
              return { kind: 'already-live', item: again.item, message: r.message };
            }
            if (again.kind !== 'ok') w.onRereadFailed(again.kind);
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
      if (!isItem(r.body)) return { kind: 'server-error' };
      const again = await loadItem(readOf(w.caller), w.id, deadline.signal);
      if (again.kind === 'ok') await w.setItem(again.item);
      else {
        // The 200 body is the server's new state: commit it, so the page never says "restored" over the archived banner.
        await w.setItem(r.body);
        w.onRereadFailed(again.kind);
      }
      return { kind: 'restored', item: r.body };
    } finally {
      deadline.cancel();
    }
  };
}
