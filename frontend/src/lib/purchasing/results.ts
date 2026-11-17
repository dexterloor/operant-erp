// Typed results of the purchase-order page's injected actions (Revision 7 A-19 item 5, Revision 8 R8-2), on the shared
// kinds in `lib/results.ts`. Framework-free (architecture.test.ts). A thrown action is treated as `unreachable`.

import type { CreatePurchaseOrderInput, CreatePurchaseOrderLineInput, PurchaseOrder, PurchaseOrderLine, UpdatePurchaseOrderInput } from '../api/purchasing';
import type { Contention, FieldErrors, Forbidden, NotFound, Refused, RestoreResult, ServerError, SessionEnded, Unreachable } from '../results';

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
 * as sent (P1 says "try again"; the person's choice is kept). `saved`, `refused-archived` and `no-longer-draft` carry
 * `sentAsOf`, the stamp taken when the PATCH was sent: the page drops the sentence or the refusal when a 204 of the person's
 * own Delete arrived after it (PO-6; the items UX ruling M-3 / T-17).
 */
export type SaveOrderResult =
  | { kind: 'saved'; order: PurchaseOrder; sentAsOf: number }
  | { kind: 'invalid'; fieldErrors: FieldErrors<OrderField>; formErrors: string[] }
  | { kind: 'refused-archived'; message: string; sentAsOf: number }
  | { kind: 'no-longer-draft'; order: PurchaseOrder; sentAsOf: number }
  | { kind: 'changed-since-loaded' }
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

export type SaveOrderAction = (changes: OrderChange) => Promise<SaveOrderResult>;

/**
 * PO-5: the new line row's fields, in screen order (the first field with a sentence takes focus). A name the mirrored input
 * lacks is a type error.
 */
export const ADD_LINE_FIELDS = ['inventory_item_id', 'quantity', 'unit_cost'] as const satisfies readonly (keyof CreatePurchaseOrderLineInput & string)[];
export type AddLineField = (typeof ADD_LINE_FIELDS)[number];
/** What the row hands over: the chosen entry's item id and the two fields as typed. */
export type AddLineValues = CreatePurchaseOrderLineInput;

/** The refusals a line write shares with Save (P8 / P9 found by the re-read, plan §2.2 "Stale page"; L10). */
type StaleLineWrite = { kind: 'refused-archived'; message: string } | { kind: 'no-longer-draft'; order: PurchaseOrder } | { kind: 'changed-since-loaded' };

/**
 * `POST /purchase-orders/{id}/lines` (PO-5; a fresh Idempotency-Key per submit). `added` carries the 201 body and resolves
 * after the stamped re-read (the order and its lines, one commit); `listStale` = that re-read did not answer both. `invalid`
 * a 422 mapped onto the row; `itemUnavailable` = it held H-2's violation (shown with the client sentence, the catalogue read
 * again). `not-listed` = L1 (the supplier no longer lists the item; the server's sentence, the catalogue read again). The
 * stale kinds as Save's. `refused` any other 409 as sent (P1, L6), the row kept.
 */
export type AddLineResult =
  | { kind: 'added'; line: PurchaseOrderLine; listStale: boolean }
  | { kind: 'invalid'; fieldErrors: FieldErrors<AddLineField>; formErrors: string[]; itemUnavailable: boolean }
  | { kind: 'not-listed'; message: string }
  | StaleLineWrite
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

export type AddLineAction = (values: AddLineValues) => Promise<AddLineResult>;

/**
 * `DELETE /purchase-orders/{id}/lines/{lineId}` (PO-5; no body, no key: a repeat is refused W23). `deleted` resolves after
 * the stamped re-read is committed, or after the page was told it failed. The stale kinds as Save's (the page shows them
 * under its stale banner, *Not deleted*). `contention` is P1 (its sentence says "try again"); `refused` any other 409 as
 * sent (W23: deleted elsewhere), after the re-read was committed so the row the server no longer has goes. `not-found`
 * likewise follows a committed re-read.
 */
export type DeleteLineResult = { kind: 'deleted' } | StaleLineWrite | Contention | Refused | Forbidden | NotFound | SessionEnded | Unreachable | ServerError;

export type DeleteLineAction = (line: PurchaseOrderLine) => Promise<DeleteLineResult>;

/**
 * `DELETE /purchase-orders/{id}` (PO-6; no body, no key: a repeat is refused P10). `deleted` resolves after the stamped
 * re-read is committed, or after the page was told it failed (the page then keeps the 204 as a fact: read-only). The stale
 * kinds are P9's rule (plan Response L-1: P11 is P9) followed by the re-read: `no-longer-draft` (committed; UX D-2's sentence
 * built from it) or `changed-since-loaded` (the re-read failed); the page shows either in the dialog, Close only.
 * `contention` is P1 (Delete kept); `refused` any other 409 as sent (P10: deleted elsewhere), Close only.
 */
export type DeleteOrderResult = { kind: 'deleted' } | { kind: 'no-longer-draft'; order: PurchaseOrder } | { kind: 'changed-since-loaded' } | Contention | Refused | Forbidden | NotFound | SessionEnded | Unreachable | ServerError;

export type DeleteOrderAction = () => Promise<DeleteOrderResult>;

/**
 * `POST /purchase-orders/{id}/cancellation` (PO-7; body `{}` or `{ reason }`, no key: a repeat is refused C2). `cancelled`
 * carries the 200 body and resolves after the stamped re-read (the order and its lines) is committed — or, when it failed,
 * after the 200 body was committed and the page told. The stale kinds follow C2's rule and its re-read (committed whatever
 * it read): `already-cancelled` / `already-received` (UX D-2's sentences, built from it) or `changed-since-loaded` (the
 * re-read failed). `contention` is P1 (Cancel order kept); `refused` any other 409 as sent (C1 DSoD-1, C3 archived), final.
 */
export type CancelOrderResult =
  | { kind: 'cancelled'; order: PurchaseOrder }
  | { kind: 'already-cancelled'; order: PurchaseOrder }
  | { kind: 'already-received'; order: PurchaseOrder }
  | { kind: 'changed-since-loaded' }
  | Contention
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

/** Cancel order with the Reason box's text as typed (`''` = none: the body is then `{}`). */
export type CancelOrderAction = (reason: string) => Promise<CancelOrderResult>;

/**
 * What `components/purchasing/CancelOrderDialog` branches on (kinds only, never a code): the page maps the stale kinds onto
 * `refused` with UX D-2's sentence. `refused` and `not-found` leave Close only; `contention`, `forbidden` and an interrupted
 * request keep Cancel order.
 */
export type CancelDialogResult = { kind: 'cancelled' } | Contention | Refused | Forbidden | NotFound | SessionEnded | Unreachable | ServerError;
