// Typed results of the purchase-order page's injected actions (Revision 7 A-19 item 5, Revision 8 R8-2), on the shared
// kinds in `lib/results.ts`. Framework-free (architecture.test.ts). A thrown action is treated as `unreachable`.

import type { PurchaseOrder } from '../api/purchasing';
import type { RestoreResult } from '../results';

/**
 * `POST /purchase-orders/{id}/restoration` (PO-2; no body, no key). `restored` carries the 200 body. `refused` is a 409
 * (P12 not archived, P13 warehouse archived, P14 supplier archived, P16 line numbers, P17 total, P1 contention), shown
 * under the deleted banner as sent. `already-live`: a refusal after which the re-read found the order live (an
 * interrupted press that committed, another tab).
 */
export type RestoreOrderResult = RestoreResult<{ order: PurchaseOrder }>;

/** The deleted draft's Restore purchase order. Caller contract: `restored` resolves after the re-read order (and its lines) is committed. */
export type RestoreOrderAction = () => Promise<RestoreOrderResult>;
