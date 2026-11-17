// Typed results of the purchase-order page's injected actions (Revision 7 A-19 item 5, Revision 8 R8-2), on the shared
// kinds in `lib/results.ts`. Framework-free (architecture.test.ts). A thrown action is treated as `unreachable`.

import type { CreatePurchaseOrderInput, PurchaseOrder, UpdatePurchaseOrderInput } from '../api/purchasing';
import type { FieldErrors, Forbidden, NotFound, Refused, RestoreResult, ServerError, SessionEnded, Unreachable } from '../results';

/**
 * `POST /purchase-orders/{id}/restoration` (PO-2; no body, no key). `restored` carries the 200 body. `refused` is a 409
 * (P12 not archived, P13 warehouse archived, P14 supplier archived, P16 line numbers, P17 total, P1 contention), shown
 * under the deleted banner as sent. `already-live`: a refusal after which the re-read found the order live (an
 * interrupted press that committed, another tab).
 */
export type RestoreOrderResult = RestoreResult<{ order: PurchaseOrder }>;

/** The deleted draft's Restore purchase order. Caller contract: `restored` resolves after the re-read order (and its lines) is committed. */
export type RestoreOrderAction = () => Promise<RestoreOrderResult>;

/**
 * PO-3: the create page's fields, in screen order (the first field with a sentence takes focus). A name the mirrored input
 * lacks is a type error. `currency_code` is not a field: it is the chosen supplier's, sent by the page (PO D-5 (a)), so a
 * sentence about it shows in the banner.
 */
export const CREATE_ORDER_FIELDS = ['supplier_id', 'warehouse_id'] as const satisfies readonly (keyof CreatePurchaseOrderInput & string)[];
export type CreateOrderField = (typeof CREATE_ORDER_FIELDS)[number];
/** What the create form hands over: the two chosen ids and the chosen supplier's served currency. Never lines, never a date. */
export interface CreateOrderValues {
  supplier_id: string;
  warehouse_id: string;
  currency_code: string;
}

/**
 * `POST /purchase-orders` (PO-3; a fresh Idempotency-Key per submit; the create does not replay on it). `created` carries
 * the 201 body; `invalid` a 422 mapped onto the form (P4 `unknown_reference` beside the supplier or the warehouse);
 * `refused` a 409 (P2 the number race, P3 the series used up, P1), shown as sent.
 */
export type CreateOrderResult =
  | { kind: 'created'; order: PurchaseOrder }
  | { kind: 'invalid'; fieldErrors: FieldErrors<CreateOrderField>; formErrors: string[] }
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

export type CreateOrderAction = (values: CreateOrderValues) => Promise<CreateOrderResult>;

/**
 * PO-4a: the draft header editor's fields, in screen order. Only Deliver to: Supplier is never changed in the app (PO D-6)
 * and the expected date is absent while D-4 is unruled. A name the mirrored input lacks is a type error.
 */
export const ORDER_FIELDS = ['warehouse_id'] as const satisfies readonly (keyof UpdatePurchaseOrderInput & string)[];
export type OrderField = (typeof ORDER_FIELDS)[number];
/** What the editor hands to Save: only the changed members (`changedFields`), never one it did not change. */
export type OrderChange = Partial<Pick<UpdatePurchaseOrderInput, OrderField>>;

/**
 * `PATCH /purchase-orders/{id}` (PO-4a; no Idempotency-Key: a merge patch, a repeat finds nothing to change). `saved`
 * carries the 200 body. `invalid` a 422 mapped onto the form (P4 `unknown_reference` beside Deliver to). A 409 is
 * followed by a re-read: `refused-archived` (the re-read is deleted: P8 elsewhere; the page shows the server's sentence),
 * `no-longer-draft` (the re-read is live and not a draft: UX D-2's sentence built from it; both committed, so the
 * editor is gone), `changed-since-loaded` (P9's rule, and the re-read failed: UX D-2's other sentence), else `refused`
 * as sent (P1 says "try again"; the person's choice is kept).
 */
export type SaveOrderResult =
  | { kind: 'saved'; order: PurchaseOrder }
  | { kind: 'invalid'; fieldErrors: FieldErrors<OrderField>; formErrors: string[] }
  | { kind: 'refused-archived'; message: string }
  | { kind: 'no-longer-draft'; order: PurchaseOrder }
  | { kind: 'changed-since-loaded' }
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

export type SaveOrderAction = (changes: OrderChange) => Promise<SaveOrderResult>;
