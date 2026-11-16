// Typed results of the supplier page's injected actions (Revision 7 A-19 item 5, Revision 8 R8-2), on the shared kinds
// in `lib/results.ts`. Framework-free (architecture.test.ts). A thrown action is treated as `unreachable`.

import type { CreateSupplierInput, CreateSupplierProductInput, Supplier, SupplierProduct, UpdateSupplierInput, UpdateSupplierProductInput } from '../api/suppliers';
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

/**
 * Suppliers S-4 (suppliers-plan.md Revision 1 — FINAL §R1-4 S-4): a catalogue entry's editable fields, in the Edit
 * dialog's screen order (the first field with a 422 sentence takes focus). Not the item and not its unit: neither is a
 * member of the update input (a name the mirrored input lacks is a type error), so `changedFields` can never send them.
 */
export const ENTRY_FIELDS = ['supplier_sku', 'unit_cost', 'lead_time_days', 'minimum_order_quantity'] as const satisfies readonly (keyof UpdateSupplierProductInput & string)[];
export type EntryField = (typeof ENTRY_FIELDS)[number];
/**
 * The body Edit's Save sends: the changed members only (never empty: Save is unavailable while nothing changed). The
 * dialog gives Lead time as typed text; `entrySave` turns it into a JSON number through `wholeNumberOrText` (R9-2).
 */
export type EntryChange = Partial<Pick<UpdateSupplierProductInput, EntryField>>;

/**
 * `PATCH /suppliers/{id}/products/{productId}`. `saved` carries the 200 body (validated as an entry, its unit included).
 * `invalid` is a 422 mapped onto the dialog. `refused` is a 409 shown as sent with Save kept (P1, or a refusal whose
 * re-read found the supplier live). `refused-archived`: a 409 after which the re-read found the supplier archived (C1 on a
 * stale page, final RED M-3): the dialog shows the server's sentence with Close only, and closing has the page re-read
 * the supplier.
 */
export type SaveEntryResult =
  | { kind: 'saved'; entry: SupplierProduct }
  | { kind: 'invalid'; fieldErrors: FieldErrors<EntryField>; formErrors: string[] }
  | { kind: 'refused-archived'; message: string }
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

/** The Edit dialog's Save for one entry: only the changed fields are sent (the dialog builds them with `changedFields`). */
export type SaveEntryAction = (entryId: string, changes: EntryChange) => Promise<SaveEntryResult>;

/**
 * Suppliers S-5 (suppliers-plan.md Revision 1 — FINAL §R1-4 S-5): Add to catalogue's members, in the dialog's screen order
 * (the chosen item first, then the four fields; the first with a 422 sentence takes focus). A name the mirrored create
 * input lacks is a type error. Not the unit: an entry's unit is its item's.
 */
export const CREATE_ENTRY_FIELDS = ['inventory_item_id', ...ENTRY_FIELDS] as const satisfies readonly (keyof CreateSupplierProductInput & string)[];
export type CreateEntryField = (typeof CREATE_ENTRY_FIELDS)[number];
/** What the Add dialog hands over: the chosen item's id and the four fields as typed (Lead time as text; R9-2). */
export type CreateEntryValues = Record<CreateEntryField, string>;

/**
 * `POST /suppliers/{id}/products` (a fresh Idempotency-Key per submit; the create does not replay on it — a repeat is
 * refused C2). `created` carries the 201 body (validated as an entry, its unit included). `invalid` is a 422 mapped onto
 * the dialog (C3 on the item, C4–C6 on the fields). `refused` is a 409 shown as sent with Add kept (C2, P1, or a refusal
 * whose re-read found the supplier live). `refused-archived`: a 409 after which the re-read found the supplier archived
 * (C1 on a stale page): Close only, and closing has the page re-read the supplier.
 */
export type CreateEntryResult =
  | { kind: 'created'; entry: SupplierProduct }
  | { kind: 'invalid'; fieldErrors: FieldErrors<CreateEntryField>; formErrors: string[] }
  | { kind: 'refused-archived'; message: string }
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

export type CreateEntryAction = (values: CreateEntryValues) => Promise<CreateEntryResult>;
