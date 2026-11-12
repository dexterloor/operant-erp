// Typed results of the supplier page's injected actions (Revision 7 A-19 item 5, Revision 8 R8-2), on the shared kinds
// in `lib/results.ts`. Framework-free (architecture.test.ts). A thrown action is treated as `unreachable`.

import type { CreateSupplierInput, Supplier, UpdateSupplierInput } from '../api/suppliers';
import type { FieldErrors, Forbidden, NotFound, Refused, RestoreResult, ServerError, SessionEnded, Unreachable } from '../results';

/**
 * The supplier page's editable fields, in screen order (the first field with a 422 sentence takes focus). NOT
 * `currency_code`: read-only text in S-1…S-5 (suppliers-plan.md §R1-10), so `changedFields` can never send it. A name the
 * mirrored input lacks is a type error.
 */
export const SUPPLIER_FIELDS = ['code', 'name', 'country_code', 'payment_terms_days'] as const satisfies readonly (keyof UpdateSupplierInput & string)[];
export type SupplierField = (typeof SUPPLIER_FIELDS)[number];
/**
 * The body Save sends: the changed members only (never empty: Save is unavailable while nothing changed). The form gives
 * Payment terms as typed text; `supplierSave` turns it into a JSON number through `wholeNumberOrText` (R9-2).
 */
export type SupplierChange = Partial<Pick<UpdateSupplierInput, SupplierField>>;

/**
 * `PATCH /suppliers/{id}`. `saved` carries the 200 body. `invalid` is a 422 mapped onto the form. `refused` is a 409 (S2
 * code taken, S4 name taken, P1 contention), shown at form level as sent. `refused-archived`: a 409 after which the
 * re-read found the supplier archived (S5 on a stale live page, 4.4 RED M-1): the re-read supplier is already committed
 * (the page is now archived and read-only), and the page shows the server's sentence under **Not saved**.
 */
export type SaveSupplierResult =
  | { kind: 'saved'; supplier: Supplier }
  | { kind: 'invalid'; fieldErrors: FieldErrors<SupplierField>; formErrors: string[] }
  | { kind: 'refused-archived'; message: string }
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

/** The form's Save: only the changed fields are sent (the form builds the body with `changedFields`). */
export type SaveSupplierAction = (changes: SupplierChange) => Promise<SaveSupplierResult>;

/**
 * `POST /suppliers/{id}/restoration` (no body, no key). `restored` carries the 200 body. `refused` is a 409 (S8 not
 * archived, S9 / S10 code or name taken, P1), shown under the archived banner as sent. `already-live`: a refusal after
 * which the re-read found the supplier live (an interrupted press that committed, another tab).
 */
export type RestoreSupplierResult = RestoreResult<{ supplier: Supplier }>;

/** The archived page's Restore supplier. Caller contract: `restored` resolves after the re-read supplier is committed. */
export type RestoreSupplierAction = () => Promise<RestoreSupplierResult>;

/**
 * `/suppliers/new`'s fields, in screen order (the first field with a 422 sentence takes focus). A name the mirrored create
 * input lacks is a type error.
 */
export const CREATE_SUPPLIER_FIELDS = ['code', 'name', 'country_code', 'currency_code', 'payment_terms_days'] as const satisfies readonly (keyof CreateSupplierInput & string)[];
export type CreateSupplierField = (typeof CREATE_SUPPLIER_FIELDS)[number];
/** What the create form hands over: every field as typed (Payment terms as text; `supplierCreate` converts it, R9-2). */
export type CreateSupplierValues = Record<CreateSupplierField, string>;

/**
 * `POST /suppliers` (fresh Idempotency-Key per submit; the create does not replay on it). `created` carries the 201 body;
 * `invalid` a 422 mapped onto the form; `refused` a 409 (S2 code taken, S4 name taken, P1), shown as sent.
 */
export type CreateSupplierResult =
  | { kind: 'created'; supplier: Supplier }
  | { kind: 'invalid'; fieldErrors: FieldErrors<CreateSupplierField>; formErrors: string[] }
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

export type CreateSupplierAction = (values: CreateSupplierValues) => Promise<CreateSupplierResult>;
