// Typed results of the item page's injected actions (Revision 7 A-19 item 5, Revision 8 R8-2), on the shared kinds in
// `lib/results.ts`. Framework-free (architecture.test.ts). A thrown action is treated as `unreachable`.

import type { CreateItemInput, Item, UpdateItemInput } from '../api/items';
import type { FieldErrors, Forbidden, NotFound, Refused, RestoreResult, ServerError, SessionEnded, Unreachable } from '../results';

/**
 * The item page's editable fields, in screen order. NOT `unit_of_measure_code` (R8-2: the Unit is read-only on the item
 * page until I-4) and NOT `supplier_id` (S-B′: shown, never edited, in this build), so `changedFields` can never send
 * either. A name the mirrored input lacks is a type error.
 */
export const ITEM_FIELDS = ['sku', 'name', 'reorder_point', 'reorder_quantity', 'safety_stock'] as const satisfies readonly (keyof UpdateItemInput & string)[];
export type ItemField = (typeof ITEM_FIELDS)[number];
/** The three numbers, rendered by QR-2 into their inputs and checked for an emptied field before Save (UX R1-9). */
export const QUANTITY_FIELDS = ['reorder_point', 'reorder_quantity', 'safety_stock'] as const satisfies readonly ItemField[];
export type QuantityField = (typeof QUANTITY_FIELDS)[number];
/** The body Save sends: the changed members only (never empty: Save is unavailable while nothing changed). */
export type ItemChange = Partial<Pick<UpdateItemInput, ItemField>>;

/**
 * `PATCH /items/{id}`. `saved` carries the 200 body. `invalid` is a 422 mapped onto the form. `refused` is a 409 (UX-I1
 * SKU taken, P1 contention), shown at form level as sent. `refused-archived`: a 409 after which the re-read found the item
 * archived (UX-I2 on a stale live page, 4.4 RED M-1): the re-read item is already committed (the page is now archived and
 * read-only), and the page shows the server's sentence under **Not saved**.
 */
export type SaveItemResult =
  | { kind: 'saved'; item: Item }
  | { kind: 'invalid'; fieldErrors: FieldErrors<ItemField>; formErrors: string[] }
  | { kind: 'refused-archived'; message: string }
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

/** The form's Save: only the changed fields are sent (the form builds the body with `changedFields`). */
export type SaveItemAction = (changes: ItemChange) => Promise<SaveItemResult>;

/**
 * `POST /items/{id}/restoration` (no body, no key). `restored` carries the 200 body. `refused` is a 409 (UX-I4 not
 * archived, UX-I1 SKU taken, P1), shown under the archived banner as sent. `already-live`: a refusal after which the
 * re-read found the item live (an interrupted press that committed, another tab).
 */
export type RestoreItemResult = RestoreResult<{ item: Item }>;

/** The archived page's Restore item. Caller contract: `restored` resolves after the re-read item is committed. */
export type RestoreItemAction = () => Promise<RestoreItemResult>;

/**
 * `/items/new`'s fields, in screen order (items-i2-design.md Revision 1 — FINAL A-5). NOT `supplier_id` (S-B′: never
 * sent in this build). Every one is always sent, exactly as typed, so the body is these six keys and nothing else. A name
 * the mirrored input lacks is a type error.
 */
export const CREATE_ITEM_FIELDS = ['sku', 'name', 'unit_of_measure_code', 'reorder_point', 'reorder_quantity', 'safety_stock'] as const satisfies readonly (keyof CreateItemInput & string)[];
export type CreateItemField = (typeof CREATE_ITEM_FIELDS)[number];
/** The body Create item sends: all six members, never `supplier_id`, never `null`. */
export type CreateItemBody = Required<Pick<CreateItemInput, CreateItemField>>;

/**
 * `POST /items`. `created` carries the 201 body. `invalid` is a 422 mapped onto the form (a pointer outside the six
 * fields lands in `formErrors`, shown in the banner). `refused` is a 409 (UX-I1 SKU taken, P1 contention), shown in the
 * banner as sent.
 */
export type CreateItemResult =
  | { kind: 'created'; item: Item }
  | { kind: 'invalid'; fieldErrors: FieldErrors<CreateItemField>; formErrors: string[] }
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

/** The create form's Create item. A fresh Idempotency-Key per call (the create does not replay on it). */
export type CreateItemAction = (body: CreateItemBody) => Promise<CreateItemResult>;
