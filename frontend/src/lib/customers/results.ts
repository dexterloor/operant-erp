// Typed results of the customer page's injected actions (Revision 8 R8-2; the `lib/suppliers/results.ts` shape), on the
// shared kinds in `lib/results.ts`. Framework-free (architecture.test.ts). A thrown action is treated as `unreachable`.

import type { Customer, UpdateCustomerInput } from '../api/customers';
import type { FieldErrors, Forbidden, NotFound, Refused, ServerError, SessionEnded, Unreachable } from '../results';

/**
 * The customer page's editable fields in C-2a, in screen order (the first field with a sentence takes focus): Code,
 * Country, Currency, Payment terms. **Not `name`**: Name is read-only text until C-2b ships the shared-name dialog
 * (customers-plan.md Revision 1 — FINAL R1-5), so `changedFields` can never put it in a body. A name the mirrored input
 * lacks is a type error.
 */
export const CUSTOMER_FIELDS = ['code', 'country_code', 'currency_code', 'payment_terms_days'] as const satisfies readonly (keyof UpdateCustomerInput & string)[];
export type CustomerField = (typeof CUSTOMER_FIELDS)[number];
/**
 * The body Save sends: the changed members only (never empty: Save is unavailable while nothing changed). The form gives
 * Payment terms as typed text; `customerSave` turns it into a JSON number through `wholeNumberOrText` (R9-2).
 */
export type CustomerChange = Partial<Pick<UpdateCustomerInput, CustomerField>>;

/**
 * `PATCH /customers/{id}`. `saved` carries the 200 body. `invalid` is a 422 mapped onto the form. A refusal without field
 * violations re-reads the customer (customers-plan.md Revision 1c R1c-3 test 5), and the re-read decides:
 * - `refused-archived`: the re-read found the customer archived, after ANY refusal (`customer.is_archived`, R2, P1, …).
 *   The re-read customer is already committed (the page is archived and read-only) and the page shows **Not saved** +
 *   `archivedOnSave` (K-15) above the ST-9 banner — never the served sentence (copy Revision 1a D-6).
 * - `archived-then-restored`: refused `customer.is_archived`, and the re-read found the customer live: **Not saved** +
 *   `archivedThenRestored` (K-16). Not committed, so the person's edits stay (the supplier shape).
 * - `archived-at-save`: refused `customer.is_archived`, and the re-read failed: **Not saved** + `archivedAtSave` (K-17);
 *   the page shows its region error with Retry; the edits stay.
 * - `refused`: any other refusal whose re-read is live or failed, shown as sent (or `REFUSED_WITHOUT_MESSAGE`).
 */
export type SaveCustomerResult =
  | { kind: 'saved'; customer: Customer }
  | { kind: 'invalid'; fieldErrors: FieldErrors<CustomerField>; formErrors: string[] }
  | { kind: 'refused-archived' }
  | { kind: 'archived-then-restored' }
  | { kind: 'archived-at-save' }
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

/** The form's Save: only the changed fields are sent (the form builds the body with `changedFields`). */
export type SaveCustomerAction = (changes: CustomerChange) => Promise<SaveCustomerResult>;
