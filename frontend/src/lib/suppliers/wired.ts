import type { Supplier } from '../api/suppliers';
import { PLATFORM_REFUSAL } from '../api/problems';
import type { ApiCaller } from '../bff/apiCall';
import { ACTION_DEADLINES_MS, startDeadline } from '../bff/deadline';
import { readOf, type ReadFailure } from '../bff/reads';
import { fieldErrorsFrom } from '../forms/fieldErrors';
import { wholeNumberOrText } from '../forms/wholeNumberOrText';
import { isSupplier, isSupplierProduct, loadSupplier } from './load';
import { CREATE_ENTRY_FIELDS, CREATE_SUPPLIER_FIELDS, ENTRY_FIELDS, SUPPLIER_FIELDS, type CreateEntryAction, type CreateSupplierAction, type DeleteEntryAction, type EntryChange, type RestoreSupplierAction, type SaveEntryAction, type SaveSupplierAction, type SupplierChange } from './results';

// The supplier page's Save and Restore, and the create page's Create (S-2, at the end), over the BFF (Revision 8 R8-2;
// the lib/items/wired.ts shape). Neither Save nor Restore takes an
// Idempotency-Key: the update is a merge patch (a repeat finds nothing to change) and the restore is guarded by
// `deleted_at` (a repeat is refused S8). One deadline covers each request AND its GetSupplier re-read. No refusal code is
// compared on the page: a refusal is followed by a re-read, and what the re-read shows decides (archived after a refused
// Save; live after a refused Restore). The ONE rule compared here is P1 (`PLATFORM_REFUSAL.contention`, R8-4: this file
// is the suppliers family's one `lib/api/problems` importer): a contention refusal is always returned as `refused`,
// never `refused-archived`, because its sentence says "try again".
//
// Payment terms (Revision 9 R9-2): the form hands the field as typed; it becomes a JSON number here, through
// `wholeNumberOrText` only, when it is 1–15 digits, and is otherwise sent as typed so the server's own sentence shows
// beside the field (suppliers-red.md M-1). No other supplier file converts a typed number.
//
// Blind spot, stated (the items precedent): C-2's body-key check reads object-literal bodies only; the Save body is built
// by `changedFields` over `SUPPLIER_FIELDS` and copied by name below, so only a changed field is sent (Currency is one of
// them from S-6; an untouched Currency is never sent).
//
// Stamps (items-i3-design.md Revision 1 — FINAL §0a, the page's rule): every action here takes its stamp from `stamp()`
// immediately before it sends its re-read, after the write's answer has arrived, and passes it to every commit or
// failure that follows, the fallback answer body included. The page drops anything older than what it shows.

export interface SupplierWiring {
  readonly caller: ApiCaller;
  readonly id: string;
  /** The page's next stamp: taken when a re-read is sent. */
  readonly stamp: () => number;
  /** Sets the re-read supplier (under the stamp of the re-read) and resolves after the commit that shows it. */
  readonly setSupplier: (supplier: Supplier, asOf: number) => Promise<void>;
  /** A re-read after a write did not answer a supplier: the page shows its region error, or swaps (ST-4b / ST-5). */
  readonly onRereadFailed: (failure: ReadFailure, asOf: number) => void;
}

export function supplierSave(w: SupplierWiring): SaveSupplierAction {
  return async (changes) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.supplierSave);
    try {
      // The changed members only, copied by name from the editable fields, never from a spread: nothing else is sent.
      const body: SupplierChange = {};
      const copy = <K extends (typeof SUPPLIER_FIELDS)[number]>(k: K) => {
        if (changes[k] !== undefined) body[k] = changes[k];
      };
      for (const f of SUPPLIER_FIELDS) copy(f);
      if (typeof body.payment_terms_days === 'string') body.payment_terms_days = wholeNumberOrText(body.payment_terms_days);
      const r = await w.caller.call('UpdateSupplier', { id: w.id }, body, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused': {
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, SUPPLIER_FIELDS) };
            // 4.4 RED M-1: a refusal may prove the page stale (S5: archived elsewhere). Re-read under the same deadline;
            // only a supplier that now reads archived is committed (the page turns read-only and shows the sentence). A
            // live re-read is NOT committed, so the person's edits stay for another press (P1, S2, S4).
            const s = w.stamp();
            const again = await loadSupplier(readOf(w.caller), w.id, deadline.signal);
            if (again.kind === 'ok' && again.supplier.archived_at !== null) {
              await w.setSupplier(again.supplier, s);
              // P1 says "try again": it is never shown as the page's archived-elsewhere refusal.
              return r.rule === PLATFORM_REFUSAL.contention ? r : { kind: 'refused-archived', message: r.message };
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
      if (!isSupplier(r.body)) return { kind: 'server-error' };
      const s = w.stamp();
      const again = await loadSupplier(readOf(w.caller), w.id, deadline.signal);
      if (again.kind === 'ok') await w.setSupplier(again.supplier, s);
      else {
        // The 200 body is the server's new state: commit it, so the page shows what was saved (Restore's rule).
        await w.setSupplier(r.body, s);
        w.onRereadFailed(again.kind, s);
      }
      return { kind: 'saved', supplier: r.body };
    } finally {
      deadline.cancel();
    }
  };
}

// Restore supplier (the itemRestore precedent): after any refusal the supplier is re-read; one that reads live was
// restored already and the page turns live (`already-live`); otherwise the refusal is returned as sent and Restore stays.
export function supplierRestore(w: SupplierWiring): RestoreSupplierAction {
  return async () => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.supplierRestore);
    try {
      const r = await w.caller.call('RestoreSupplier', { id: w.id }, undefined, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused': {
            const s = w.stamp();
            const again = await loadSupplier(readOf(w.caller), w.id, deadline.signal);
            if (again.kind === 'ok' && again.supplier.archived_at === null) {
              await w.setSupplier(again.supplier, s);
              return { kind: 'already-live', supplier: again.supplier, message: r.message };
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
      if (!isSupplier(r.body)) return { kind: 'server-error' };
      const s = w.stamp();
      const again = await loadSupplier(readOf(w.caller), w.id, deadline.signal);
      if (again.kind === 'ok') await w.setSupplier(again.supplier, s);
      else {
        // The 200 body is the server's new state: commit it, so the page never says "restored" over the archived banner.
        await w.setSupplier(r.body, s);
        w.onRereadFailed(again.kind, s);
      }
      return { kind: 'restored', supplier: r.body };
    } finally {
      deadline.cancel();
    }
  };
}

// Create supplier over the BFF (suppliers-plan.md §R1-4 S-2; the `warehouseCreate` / `itemCreate` shape). `CreateSupplier`
// requires an Idempotency-Key but does not replay on it (a repeat is refused by the live code unique, UX S-2
// `interrupted`), so a fresh key is minted for EVERY submit (`newKey`) and never stored. The body is the five fields copied
// by name (never a spread); Payment terms becomes a JSON number through `wholeNumberOrText` only (R9-2). No re-read: the
// page navigates to the new supplier, which reads it. No refusal code is compared.
export function supplierCreate(caller: ApiCaller, newKey: () => string = () => crypto.randomUUID()): CreateSupplierAction {
  return async (values) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.supplierCreate);
    try {
      const body = {
        code: values.code,
        name: values.name,
        country_code: values.country_code,
        currency_code: values.currency_code,
        payment_terms_days: wholeNumberOrText(values.payment_terms_days),
      };
      const r = await caller.call('CreateSupplier', {}, body, { signal: deadline.signal, idempotencyKey: newKey() });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused':
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, CREATE_SUPPLIER_FIELDS) };
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
      return isSupplier(r.body) ? { kind: 'created', supplier: r.body } : { kind: 'server-error' };
    } finally {
      deadline.cancel();
    }
  };
}

// Edit a catalogue entry over the BFF (suppliers-plan.md Revision 1 — FINAL §R1-4 S-4; the `supplierSave` shape for the
// refusal, the `locationSave` shape for the rest). No Idempotency-Key: the update is a merge patch. The body is the changed
// members only, copied by name from `ENTRY_FIELDS` (never a spread), so neither the item nor its unit can be sent; Lead time
// becomes a JSON number through `wholeNumberOrText` only (R9-2); the cost and the minimum order go as typed strings (QR-1).
// The 200 body must be a whole entry, its unit included (FINAL (e) item 2). No re-read of the catalogue here: the section
// re-reads after the dialog closes. After a 409 (other than a 422) the SUPPLIER is re-read under the same deadline, and what
// it shows decides, as for Save on the page (no refusal code is compared but P1): archived → `refused-archived` (C1 on a
// stale page; the page re-reads the supplier itself when the dialog closes, so nothing is committed here); otherwise the
// refusal as sent. P1 is never `refused-archived`: its sentence says "try again".
export function entrySave(caller: ApiCaller, supplierId: string): SaveEntryAction {
  return async (entryId, changes) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.entrySave);
    try {
      const body: EntryChange = {};
      const copy = <K extends (typeof ENTRY_FIELDS)[number]>(k: K) => {
        if (changes[k] !== undefined) body[k] = changes[k];
      };
      for (const f of ENTRY_FIELDS) copy(f);
      if (typeof body.lead_time_days === 'string') body.lead_time_days = wholeNumberOrText(body.lead_time_days);
      const r = await caller.call('UpdateSupplierProduct', { id: supplierId, productId: entryId }, body, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused': {
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, ENTRY_FIELDS) };
            if (r.rule === PLATFORM_REFUSAL.contention) return r;
            const again = await loadSupplier(readOf(caller), supplierId, deadline.signal);
            return again.kind === 'ok' && again.supplier.archived_at !== null ? { kind: 'refused-archived', message: r.message } : r;
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
      return isSupplierProduct(r.body) ? { kind: 'saved', entry: r.body } : { kind: 'server-error' };
    } finally {
      deadline.cancel();
    }
  };
}

// Add a catalogue entry over the BFF (suppliers-plan.md Revision 1 — FINAL §R1-4 S-5; the `entrySave` shape for the
// refusal, the `supplierCreate` shape for the key). `CreateSupplierProduct` requires an Idempotency-Key but does not replay
// on it (a repeat is refused C2, "already lists this item"), so a fresh key is minted for EVERY submit (`newKey`) and never
// stored. The body is the five members copied by name (never a spread): the chosen item's id and the four fields as typed;
// Lead time becomes a JSON number through `wholeNumberOrText` only (R9-2); the cost and the minimum order go as typed
// strings (QR-1). The unit is never sent (no input carries it). The 201 body must be a whole entry, its unit included. No
// catalogue re-read here: the section re-reads after the dialog closes. After a 409 (other than a 422 and P1) the SUPPLIER
// is re-read under the same deadline: archived → `refused-archived` (C1 on a stale page); otherwise the refusal as sent
// (C2). No refusal code is compared but P1.
export function entryCreate(caller: ApiCaller, supplierId: string, newKey: () => string = () => crypto.randomUUID()): CreateEntryAction {
  return async (values) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.entryCreate);
    try {
      const body = {
        inventory_item_id: values.inventory_item_id,
        supplier_sku: values.supplier_sku,
        unit_cost: values.unit_cost,
        lead_time_days: wholeNumberOrText(values.lead_time_days),
        minimum_order_quantity: values.minimum_order_quantity,
      };
      const r = await caller.call('CreateSupplierProduct', { id: supplierId }, body, { signal: deadline.signal, idempotencyKey: newKey() });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused': {
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, CREATE_ENTRY_FIELDS) };
            if (r.rule === PLATFORM_REFUSAL.contention) return r;
            const again = await loadSupplier(readOf(caller), supplierId, deadline.signal);
            return again.kind === 'ok' && again.supplier.archived_at !== null ? { kind: 'refused-archived', message: r.message } : r;
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
      return isSupplierProduct(r.body) ? { kind: 'created', entry: r.body } : { kind: 'server-error' };
    } finally {
      deadline.cancel();
    }
  };
}

// Remove a catalogue entry over the BFF (suppliers-plan.md Revision 1 — FINAL §R1-4 S-6; the `locationDelete` shape for
// the request, the `entrySave` shape for the refusal). No body, no Idempotency-Key: a repeat after a removal answers 404.
// P1 is `contention` (the shared Delete dialog keeps Remove). After any other 409 the SUPPLIER is re-read under the same
// deadline: archived → `refused-archived` (C1 on a stale page; the page re-reads the supplier itself when the dialog
// closes, so nothing is committed here); otherwise the refusal as sent. No refusal code is compared but P1. No catalogue
// re-read here: the section re-reads after the dialog closes.
export function entryDelete(caller: ApiCaller, supplierId: string): DeleteEntryAction {
  return async (entryId) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.entryDelete);
    try {
      const r = await caller.call('DeleteSupplierProduct', { id: supplierId, productId: entryId }, undefined, { signal: deadline.signal });
      if (r.kind === 'ok') return { kind: 'deleted' };
      switch (r.kind) {
        case 'refused': {
          if (r.rule === PLATFORM_REFUSAL.contention) return { kind: 'contention', message: r.message };
          const again = await loadSupplier(readOf(caller), supplierId, deadline.signal);
          return again.kind === 'ok' && again.supplier.archived_at !== null ? { kind: 'refused-archived', rule: r.rule, message: r.message } : r;
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
