// Typed results of the customer page's injected actions (Revision 8 R8-2; the `lib/suppliers/results.ts` shape), on the
// shared kinds in `lib/results.ts`. Framework-free (architecture.test.ts). A thrown action is treated as `unreachable`.

import type { CreateCustomerContactInput, Customer, CustomerContact, UpdateCustomerContactInput, UpdateCustomerInput } from '../api/customers';
import type { Contention, FieldErrors, Forbidden, NotFound, Refused, ServerError, SessionEnded, Unreachable } from '../results';

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

/**
 * A contact's fields in screen order (the first field with a sentence takes focus, and `fieldErrorsFrom` maps a 422's
 * pointers onto them): Name, Email, Phone, Primary contact (customers-plan-c4c5.md Revision 0 I-9).
 */
export const CONTACT_FIELDS = ['name', 'email', 'phone', 'is_primary'] as const satisfies readonly (keyof CreateCustomerContactInput & keyof UpdateCustomerContactInput & string)[];
export type ContactField = (typeof CONTACT_FIELDS)[number];

/** What Add contact hands its action: the fields as typed and the box's state. The action builds the body. */
export interface ContactValues {
  name: string;
  email: string;
  phone: string;
  is_primary: boolean;
}

/**
 * `POST /customers/{id}/contacts`. `created` carries the 201 body. `invalid` is a 422 mapped onto the form. A refusal
 * without field violations re-reads the customer inside the action, and the re-read decides (copy D-15, R1a-1):
 * - `refused-archived`: the re-read found the customer archived, after ANY refusal. **Not added** + the interim
 *   `ADD_CONTACT.archived`, Close only; nothing is committed here (the page re-reads when the dialog goes).
 * - `archived-then-restored`: refused `customer.is_archived`, re-read live: **Not added** + `archivedThenRestored`.
 * - `archived-at-add`: refused `customer.is_archived`, re-read failed: **Not added** + `archivedAtAdd`.
 * - `refused`: any other refusal whose re-read is live or failed, shown as sent (or `REFUSED_WITHOUT_MESSAGE`).
 */
export type CreateContactResult =
  | { kind: 'created'; contact: CustomerContact }
  | { kind: 'invalid'; fieldErrors: FieldErrors<ContactField>; formErrors: string[] }
  | { kind: 'refused-archived' }
  | { kind: 'archived-then-restored' }
  | { kind: 'archived-at-add' }
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

/** Add contact: a fresh Idempotency-Key per call (the create does not replay on it). */
export type CreateContactAction = (values: ContactValues) => Promise<CreateContactResult>;

/** The changed members only (Edit's changed-only merge patch); a cleared Email or Phone is `null`. */
export type ContactChange = Partial<Pick<UpdateCustomerContactInput, ContactField>>;

/** `PATCH /customers/{id}/contacts/{contactId}`: the Add result's shape with `saved` and `archived-at-save`. */
export type SaveContactResult =
  | { kind: 'saved'; contact: CustomerContact }
  | { kind: 'invalid'; fieldErrors: FieldErrors<ContactField>; formErrors: string[] }
  | { kind: 'refused-archived' }
  | { kind: 'archived-then-restored' }
  | { kind: 'archived-at-save' }
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

/** Edit contact: no Idempotency-Key (a merge patch). */
export type SaveContactAction = (contactId: string, changes: ContactChange) => Promise<SaveContactResult>;

/**
 * `DELETE /customers/{id}/contacts/{contactId}` (customers-plan-c4c5.md Revision 1 R1-3 I-9b; no body, no key). `deleted` is
 * the 204. Any refusal re-reads the customer inside the action FIRST, and the re-read decides (copy D-15, R1a-1):
 * - `refused-archived`: the re-read found the customer archived, after ANY refusal (P1 included): **Not removed** + the
 *   interim `REMOVE_CONTACT.archived`, Close only.
 * - `archived-then-restored`: refused `customer.is_archived`, re-read live: **Not removed** + `archivedThenRestored`, Close
 *   only (D-2: the shared Delete dialog's `refused` leaves Close).
 * - `archived-at-remove`: refused `customer.is_archived`, re-read failed: **Not removed** + `archivedAtRemove`, Close only.
 * - `contention`: P1, decided only after a live or failed re-read (K-1 (a′)): shown as sent, Remove stays.
 * - `refused`: any other refusal whose re-read is live or failed, shown as sent (or `REFUSED_WITHOUT_MESSAGE`), Close only.
 * **A deviation from F-9's union (final RED L-2):** the three archived kinds carry the served `rule`, so the section can hand
 * the shared dialog a whole `Refused` without importing a refusal constant (P-1); the dialog never reads it.
 */
export type RemoveContactResult =
  | { kind: 'deleted' }
  | Contention
  | { kind: 'refused-archived'; rule: string }
  | { kind: 'archived-then-restored'; rule: string }
  | { kind: 'archived-at-remove'; rule: string }
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

/** Remove contact: no body, no Idempotency-Key (a repeat after a removal answers 404). */
export type RemoveContactAction = (contactId: string) => Promise<RemoveContactResult>;
