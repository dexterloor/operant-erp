// Typed results of the purchase-order page's injected actions (Revision 7 A-19 item 5, Revision 8 R8-2), on the shared
// kinds in `lib/results.ts`. Framework-free (architecture.test.ts). A thrown action is treated as `unreachable`.

import type { CreatePurchaseOrderInput, PurchaseOrder } from '../api/purchasing';
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
