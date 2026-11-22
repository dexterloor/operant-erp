import { CUSTOMER_SAVE_REFUSAL, type Customer } from '../api/customers';
import type { ApiCaller } from '../bff/apiCall';
import { ACTION_DEADLINES_MS, startDeadline } from '../bff/deadline';
import { readOf, type ReadFailure } from '../bff/reads';
import { fieldErrorsFrom } from '../forms/fieldErrors';
import { wholeNumberOrText } from '../forms/wholeNumberOrText';
import { isCustomer, loadCustomer } from './load';
import { CUSTOMER_FIELDS, type CustomerChange, type SaveCustomerAction } from './results';

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
//   cannot be taken (H-1, customers-plan.md Revision 1c R1c-5), so no platform rule is compared in this file.
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
