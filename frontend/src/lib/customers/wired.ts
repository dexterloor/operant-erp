import { CONTACT_ADD_REFUSAL, CONTACT_REMOVE_REFUSAL, CONTACT_SAVE_REFUSAL, CUSTOMER_SAVE_REFUSAL, type CreateCustomerContactInput, type Customer } from '../api/customers';
import { PLATFORM_REFUSAL } from '../api/problems';
import type { ApiCaller } from '../bff/apiCall';
import { ACTION_DEADLINES_MS, startDeadline } from '../bff/deadline';
import { readOf, type ReadFailure } from '../bff/reads';
import { fieldErrorsFrom } from '../forms/fieldErrors';
import { wholeNumberOrText } from '../forms/wholeNumberOrText';
import { isCustomer, isCustomerContact, loadCustomer } from './load';
import { CONTACT_FIELDS, CUSTOMER_FIELDS, type ContactChange, type CreateContactAction, type CustomerChange, type RemoveContactAction, type SaveContactAction, type SaveCustomerAction } from './results';

// The customer page's Save over the BFF (customers-plan.md Revision 1 — FINAL R1-5 C-2a + Revision 1c; the
// `lib/suppliers/wired.ts` `supplierSave` shape, with one deliberate departure below). No Idempotency-Key: the update is a
// merge patch (a repeat finds nothing to change). One deadline covers the request AND its GetCustomer re-read.
//
// The body: the changed members only, copied by name from `CUSTOMER_FIELDS` (never a spread), so `name` — not a member of
// that list — is never sent (C-2a: Name is read-only text). Payment terms becomes a JSON number through
// `wholeNumberOrText` only, when it is 1–15 digits, and is otherwise sent as typed so the server's own sentence shows beside
// the field (R9-2). No other customer file converts a typed number.
//
// A refusal with field violations goes beside its fields with no re-read. Any other refusal re-reads the customer, and
// what the re-read shows decides (copy Revision 1a R1a-2, D-6):
// - archived (after ANY refusal, P1 included) → the re-read is committed (the page turns archived and read-only) and the
//   result is `refused-archived`: the page shows K-15, never the served sentence. **The departure from the supplier
//   shape:** `supplierSave` returns P1 ("try again") as sent here; on a read-only page "try again" names an action that
//   cannot be taken (H-1, customers-plan.md Revision 1c R1c-5), so no platform rule is compared in `customerSave`,
//   `contactCreate` or `contactSave`; the Remove actions compare P1 after the customer re-read (K-1 (a′), pinned by
//   `CUSTOMER_P1_ACTIONS`).
// - live → not committed, so the person's edits stay: `archived-then-restored` (K-16) when the rule was the archived one,
//   else the refusal as sent (R2, P1, …).
// - failed → the page is told (its region error) and the edits stay: `archived-at-save` (K-17) when the rule was the
//   archived one, else the refusal as sent.
// The ONE rule compared is `CUSTOMER_SAVE_REFUSAL.archived`, and only here.
//
// Stamps (the supplier page's rule, without its delete floor: C-2a has no Delete): the action takes its stamp from
// `stamp()` immediately before it sends its re-read, after the write's answer has arrived, and passes it to every commit or
// failure that follows, the 200-body fallback included. The page drops anything older than what it shows.

export interface CustomerWiring {
  readonly caller: ApiCaller;
  readonly id: string;
  /** The page's next stamp: taken when a re-read is sent. */
  readonly stamp: () => number;
  /** Sets the re-read customer (under the stamp of the re-read) and resolves after the commit that shows it. */
  readonly setCustomer: (customer: Customer, asOf: number) => Promise<void>;
  /** A re-read after a write did not answer a customer: the page shows its region error, or swaps (ST-4b / ST-5). */
  readonly onRereadFailed: (failure: ReadFailure, asOf: number) => void;
}

export function customerSave(w: CustomerWiring): SaveCustomerAction {
  return async (changes) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.customerSave);
    try {
      // The changed members only, copied by name from the editable fields, never from a spread: nothing else is sent.
      const body: CustomerChange = {};
      const copy = <K extends (typeof CUSTOMER_FIELDS)[number]>(k: K) => {
        if (changes[k] !== undefined) body[k] = changes[k];
      };
      for (const f of CUSTOMER_FIELDS) copy(f);
      if (typeof body.payment_terms_days === 'string') body.payment_terms_days = wholeNumberOrText(body.payment_terms_days);
      const r = await w.caller.call('UpdateCustomer', { id: w.id }, body, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused': {
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, CUSTOMER_FIELDS) };
            const archivedRule = r.rule === CUSTOMER_SAVE_REFUSAL.archived;
            const s = w.stamp();
            const again = await loadCustomer(readOf(w.caller), w.id, deadline.signal);
            if (again.kind === 'ok' && again.customer.archived_at !== null) {
              await w.setCustomer(again.customer, s);
              return { kind: 'refused-archived' };
            }
            if (again.kind === 'ok') return archivedRule ? { kind: 'archived-then-restored' } : r;
            w.onRereadFailed(again.kind, s);
            return archivedRule ? { kind: 'archived-at-save' } : r;
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
      if (!isCustomer(r.body)) return { kind: 'server-error' };
      const s = w.stamp();
      const again = await loadCustomer(readOf(w.caller), w.id, deadline.signal);
      if (again.kind === 'ok') await w.setCustomer(again.customer, s);
      else {
        // The 200 body is the server's new state: commit it, so the page shows what was saved.
        await w.setCustomer(r.body, s);
        w.onRereadFailed(again.kind, s);
      }
      return { kind: 'saved', customer: r.body };
    } finally {
      deadline.cancel();
    }
  };
}

// The contact actions (customers-plan-c4c5.md Revision 1 R1-2 I-10a over Revision 0 F-1, F-9, F-10; the
// `entryCreate` / `entrySave` shape, with one departure). No P1 short-circuit in any action. Remove alone compares P1, and
// only after a live or failed customer re-read (K-1 (a′)). A refusal with field violations goes beside its fields with no
// re-read. **The departure:** after ANY other refusal (P1 included) the CUSTOMER is re-read under the action's own
// deadline, and what it shows decides (copy D-15, R1a-1): archived (any rule) → `refused-archived` (the interim client
// sentence, Close only); live → `archived-then-restored` when the rule is the action's own constant, else the refusal as
// sent; failed → `archived-at-add` / `archived-at-save` when the rule is the action's own constant, else the refusal as
// sent. Nothing is committed here and no re-read failure is reported to the page (the supplier entry shape): the page
// re-reads the customer itself when the dialog goes (`onParentStale`). `not-found`, `forbidden`, `unreachable`,
// `server-error` and `session-ended` never re-read. A 201/200 body that is not a whole contact is `server-error`. The
// section re-reads its contacts after the dialog closes, under `reread`. Each action compares only its own constant
// (`CONTACT_ADD_REFUSAL` / `CONTACT_SAVE_REFUSAL` / `CONTACT_REMOVE_REFUSAL`), inline, and nowhere else (P-1).

/**
 * Add contact over the BFF. `CreateCustomerContact` requires an Idempotency-Key but does not replay on it in any way the
 * page relies on, so a fresh key is minted for EVERY submit (`newKey`) and never stored. The body is copied by name (never
 * a spread): `name` as typed (spaces too: the server's S-6 shows beside the field), an empty Email or Phone OMITTED
 * (`minLength: 1`), `is_primary` always, as a JSON boolean (F-10).
 */
export function contactCreate(caller: ApiCaller, customerId: string, newKey: () => string = () => crypto.randomUUID()): CreateContactAction {
  return async (values) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.contactCreate);
    try {
      const body: CreateCustomerContactInput = { name: values.name };
      if (values.email !== '') body.email = values.email;
      if (values.phone !== '') body.phone = values.phone;
      body.is_primary = values.is_primary;
      const r = await caller.call('CreateCustomerContact', { id: customerId }, body, { signal: deadline.signal, idempotencyKey: newKey() });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused': {
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, CONTACT_FIELDS) };
            const archivedRule = r.rule === CONTACT_ADD_REFUSAL.archived;
            const again = await loadCustomer(readOf(caller), customerId, deadline.signal);
            if (again.kind === 'ok' && again.customer.archived_at !== null) return { kind: 'refused-archived' };
            if (again.kind === 'ok') return archivedRule ? { kind: 'archived-then-restored' } : r;
            return archivedRule ? { kind: 'archived-at-add' } : r;
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
      return isCustomerContact(r.body) ? { kind: 'created', contact: r.body } : { kind: 'server-error' };
    } finally {
      deadline.cancel();
    }
  };
}

/**
 * Edit contact over the BFF. No Idempotency-Key: the update is a merge patch. The body is the changed members only, copied
 * by name from `CONTACT_FIELDS` (never a spread); a cleared Email or Phone arrives as `null` and is sent as `null`.
 */
export function contactSave(caller: ApiCaller, customerId: string): SaveContactAction {
  return async (contactId, changes) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.contactSave);
    try {
      const body: ContactChange = {};
      const copy = <K extends (typeof CONTACT_FIELDS)[number]>(k: K) => {
        if (changes[k] !== undefined) body[k] = changes[k];
      };
      for (const f of CONTACT_FIELDS) copy(f);
      const r = await caller.call('UpdateCustomerContact', { id: customerId, contactId }, body, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused': {
            if (r.violations !== undefined && r.violations.length > 0) return { kind: 'invalid', ...fieldErrorsFrom(r.violations, CONTACT_FIELDS) };
            const archivedRule = r.rule === CONTACT_SAVE_REFUSAL.archived;
            const again = await loadCustomer(readOf(caller), customerId, deadline.signal);
            if (again.kind === 'ok' && again.customer.archived_at !== null) return { kind: 'refused-archived' };
            if (again.kind === 'ok') return archivedRule ? { kind: 'archived-then-restored' } : r;
            return archivedRule ? { kind: 'archived-at-save' } : r;
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
      return isCustomerContact(r.body) ? { kind: 'saved', contact: r.body } : { kind: 'server-error' };
    } finally {
      deadline.cancel();
    }
  };
}

/**
 * Remove contact over the BFF (customers-plan-c4c5.md Revision 1 R1-3 I-10b; the `entryDelete` shape, with its order
 * reversed). No body and no Idempotency-Key. After ANY refusal the customer is re-read FIRST, under the action's own
 * deadline: archived (any rule, P1 included) → `refused-archived`; the rule is `CONTACT_REMOVE_REFUSAL.archived` → live
 * `archived-then-restored`, failed `archived-at-remove`; otherwise, **only now**, P1 → `contention` (Remove stays), else the
 * refusal as sent. Each archived kind carries the served rule (final RED L-2). `not-found`, `forbidden`, `unreachable`,
 * `server-error` and `session-ended` never re-read. The one platform rule compared in this file is compared here, and only
 * here (P-2, `CUSTOMER_P1_ACTIONS`).
 */
export function contactDelete(caller: ApiCaller, customerId: string): RemoveContactAction {
  return async (contactId) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.contactDelete);
    try {
      const r = await caller.call('DeleteCustomerContact', { id: customerId, contactId }, undefined, { signal: deadline.signal });
      if (r.kind === 'ok') return { kind: 'deleted' };
      switch (r.kind) {
        case 'refused': {
          const archivedRule = r.rule === CONTACT_REMOVE_REFUSAL.archived;
          const again = await loadCustomer(readOf(caller), customerId, deadline.signal);
          if (again.kind === 'ok' && again.customer.archived_at !== null) return { kind: 'refused-archived', rule: r.rule };
          if (archivedRule) return again.kind === 'ok' ? { kind: 'archived-then-restored', rule: r.rule } : { kind: 'archived-at-remove', rule: r.rule };
          if (r.rule === PLATFORM_REFUSAL.contention) return { kind: 'contention', message: r.message };
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
