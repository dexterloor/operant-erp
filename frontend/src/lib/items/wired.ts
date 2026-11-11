import type { Item } from '../api/items';
import { PLATFORM_REFUSAL } from '../api/problems';
import type { ApiCaller } from '../bff/apiCall';
import { ACTION_DEADLINES_MS, startDeadline } from '../bff/deadline';
import { readOf, type ReadFailure } from '../bff/reads';
import { fieldErrorsFrom } from '../forms/fieldErrors';
import { isItem, loadItem } from './load';
import { CREATE_ITEM_FIELDS, ITEM_FIELDS, type CreateItemAction, type DeleteItemAction, type ItemChange, type RestoreItemAction, type SaveItemAction } from './results';

// The item page's Save, Restore and Delete over the BFF (Revision 8 R8-2; the lib/warehouses/wired.ts shape). Neither takes an
// Idempotency-Key: the update is a merge patch (a repeat finds nothing to change) and the restore is guarded by
// `deleted_at` (a repeat is refused UX-I4). One deadline covers each request AND its GetItem re-read. No refusal code is
// compared on the page: a refusal is followed by a re-read, and what the re-read shows decides (archived after a refused
// Save; live after a refused Restore). The ONE rule compared here is P1 (`PLATFORM_REFUSAL.contention`, R8-4: this file
// is the items family's one `lib/api/problems` importer): a contention refusal is always returned as `refused`, never
// `refused-archived`, because its sentence says "try again" (items-i1-red.md L-3, second option). A re-read that fails
// after a refusal is told to the page (`onRereadFailed`), so a stale page is never left silent (M-1).
//
// Blind spot, stated (the warehouses X-2 precedent): C-2's body-key check reads object-literal bodies only; the Save body
// is built by `changedFields` over `ITEM_FIELDS` and copied by name below, which is what keeps `supplier_id` out of it
// and the unit in it only when the person changed it. The unit's own refusal (in use) is not compared here either: it
// takes the shared `refused` path and is shown as sent (items-i4-design.md Revision 1 — FINAL §3.2, option A).
//
// Blind spot, stated: C-2's body-key check reads object-literal bodies only; the create body is built by name over
// `CREATE_ITEM_FIELDS`, which, with the item-create body test, keeps it to the six keys.

//
// Stamps (items-i3-design.md Revision 1 — FINAL §0a): every action here takes its stamp from `stamp()` immediately
// before it sends its re-read, after the write's answer has arrived, and passes it to every commit or failure that
// follows, the fallback answer body included. The page drops anything older than what it shows.
// A Save also takes a stamp when it sends its PATCH; its own answer body is committed only if no 204 arrived after that
// (a Save that answers after a delete was processed before it; i3-0a0b RED H-1, the warehouse rule). The same stamp goes
// back with `saved` and `refused-archived` (`sentAsOf`), so the page can drop what such a Save would say (I-4 T-17, M-3).

export interface ItemWiring {
  readonly caller: ApiCaller;
  readonly id: string;
  /** The page's next stamp: taken when a re-read is sent. */
  readonly stamp: () => number;
  /**
   * Sets the re-read item (under the stamp of the re-read) and resolves after the commit that shows it. `sentAsOf`, given
   * only with a write's own answer body, is the stamp taken when that write was sent.
   */
  readonly setItem: (item: Item, asOf: number, sentAsOf?: number) => Promise<void>;
  /** A re-read after a write did not answer an item: the page shows its region error, or swaps (ST-4b / ST-5). */
  readonly onRereadFailed: (failure: ReadFailure, asOf: number) => void;
  /** After a 204, before the delete's re-read: the page raises what it shows to that re-read's stamp (the 204 floor). */
  readonly onDeleted?: (asOf: number) => void;
}

export function itemSave(w: ItemWiring): SaveItemAction {
  return async (changes) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.itemSave);
    try {
      // The changed members only, copied by name from the editable fields, never from a spread: nothing else is sent.
      const body: ItemChange = {};
      for (const f of ITEM_FIELDS) if (changes[f] !== undefined) body[f] = changes[f];
      const sent = w.stamp();
      const r = await w.caller.call('UpdateItem', { id: w.id }, body, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused': {
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, ITEM_FIELDS) };
            // 4.4 RED M-1: a refusal may prove the page stale (UX-I2: archived elsewhere). Re-read under the same
            // deadline; only an item that now reads archived is committed (the page turns read-only and shows the
            // sentence). A live re-read is NOT committed, so the person's edits stay for another press (P1, UX-I1).
            const s = w.stamp();
            const again = await loadItem(readOf(w.caller), w.id, deadline.signal);
            if (again.kind === 'ok' && again.item.archived_at !== null) {
              await w.setItem(again.item, s);
              // P1 says "try again": it is never shown as the page's archived-elsewhere refusal (L-3).
              return r.rule === PLATFORM_REFUSAL.contention ? r : { kind: 'refused-archived', message: r.message, sentAsOf: sent };
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
      if (!isItem(r.body)) return { kind: 'server-error' };
      const s = w.stamp();
      const again = await loadItem(readOf(w.caller), w.id, deadline.signal);
      if (again.kind === 'ok') await w.setItem(again.item, s);
      else {
        // The 200 body is the server's new state: commit it, so the page shows what was saved (L-1, Restore's rule),
        // unless a 204 arrived after this Save was sent (the body is then the item before the delete; RED H-1).
        await w.setItem(r.body, s, sent);
        w.onRereadFailed(again.kind, s);
      }
      return { kind: 'saved', item: r.body, sentAsOf: sent };
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
            const s = w.stamp();
            const again = await loadItem(readOf(w.caller), w.id, deadline.signal);
            if (again.kind === 'ok' && again.item.archived_at === null) {
              await w.setItem(again.item, s);
              return { kind: 'already-live', item: again.item, message: r.message };
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
      if (!isItem(r.body)) return { kind: 'server-error' };
      const s = w.stamp();
      const again = await loadItem(readOf(w.caller), w.id, deadline.signal);
      if (again.kind === 'ok') await w.setItem(again.item, s);
      else {
        // The 200 body is the server's new state: commit it, so the page never says "restored" over the archived banner.
        await w.setItem(r.body, s);
        w.onRereadFailed(again.kind, s);
      }
      return { kind: 'restored', item: r.body };
    } finally {
      deadline.cancel();
    }
  };
}

/**
 * Delete item (items I-3; the `warehouseDelete` shape). No body, no key (the surface requires none). One deadline covers
 * the request AND the GetItem re-read. After the 204 the page stays where it is: the item is archived, not gone, so the
 * re-read item is committed and the page turns archived (ST-9 banner, Restore item for a holder). A failed re-read still
 * resolves `deleted` (the delete happened); the page is told through `onRereadFailed`. Before the re-read is sent,
 * `onDeleted` raises what the page shows to the re-read's stamp (§0a, the 204 floor), so a reading requested before the
 * delete (a Save's re-read still in flight) cannot undo it, even when this re-read fails. P1 is the one rule compared
 * (`contention`: Delete stays); every other refusal is returned as sent and is final in the dialog.
 */
export function itemDelete(w: ItemWiring): DeleteItemAction {
  return async () => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.itemDelete);
    try {
      const r = await w.caller.call('DeleteItem', { id: w.id }, undefined, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused':
            return r.rule === PLATFORM_REFUSAL.contention ? { kind: 'contention', message: r.message } : r;
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
      const s = w.stamp();
      w.onDeleted?.(s);
      const again = await loadItem(readOf(w.caller), w.id, deadline.signal);
      if (again.kind === 'ok') await w.setItem(again.item, s);
      else w.onRereadFailed(again.kind, s);
      return { kind: 'deleted' };
    } finally {
      deadline.cancel();
    }
  };
}

// Create item over the BFF (items-i2-design.md Revision 1 — FINAL A-9; the `warehouseCreate` precedent). `CreateItem`
// requires an Idempotency-Key but does not replay on it (the live SKU unique refuses a repeat, UX-I1), so a fresh key is
// minted for EVERY call (`newKey`) and never stored, never in the body. No re-read here: the page navigates to the new
// item, which reads it. No refusal code is compared (`PROBLEMS_IMPORTERS` is unchanged by this action).
export function itemCreate(caller: ApiCaller, newKey: () => string = () => crypto.randomUUID()): CreateItemAction {
  return async (body) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.itemCreate);
    try {
      // The body is the form's, built by name over CREATE_ITEM_FIELDS (A-6): sent as given.
      const r = await caller.call('CreateItem', {}, body, { signal: deadline.signal, idempotencyKey: newKey() });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused':
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, CREATE_ITEM_FIELDS) };
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
      if (!isItem(r.body)) return { kind: 'server-error' };
      return { kind: 'created', item: r.body };
    } finally {
      deadline.cancel();
    }
  };
}
