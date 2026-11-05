// Typed results of the warehouse page's injected actions (Revision 7 A-19 item 5), on the shared kinds in
// `lib/results.ts`. Framework-free (architecture.test.ts). A thrown action is treated as `unreachable`.

import type { UpdateWarehouseInput, Warehouse } from '../api/warehouses';
import type { FieldErrors, Forbidden, NotFound, Refused, ServerError, SessionEnded, Unreachable } from '../results';

/**
 * The warehouse form's fields, in screen order (step-3 RED L-3): a name the mirrored input lacks is a type error, and
 * C-1 keeps the input itself honest against the served schema.
 */
export const WAREHOUSE_FIELDS = ['code', 'name', 'country_code'] as const satisfies readonly (keyof UpdateWarehouseInput & string)[];
export type WarehouseField = (typeof WAREHOUSE_FIELDS)[number];

/**
 * `PATCH /warehouses/{id}`. `saved` carries the 200 body. `invalid` is a 422 mapped onto the form (`fieldErrorsFrom`):
 * field sentences beside their fields, the rest at form level, all verbatim. `refused` is a 409 (code taken, archived,
 * contention), shown at form level as sent.
 */
export type SaveWarehouseResult =
  | { kind: 'saved'; warehouse: Warehouse }
  | { kind: 'invalid'; fieldErrors: FieldErrors<WarehouseField>; formErrors: string[] }
  | Refused
  | Forbidden
  | NotFound
  | SessionEnded
  | Unreachable
  | ServerError;

/** The form's Save: only the changed fields are sent (the caller builds the body with `changedFields`). */
export type SaveWarehouseAction = (changes: UpdateWarehouseInput) => Promise<SaveWarehouseResult>;

/**
 * `POST /warehouses/{id}/restoration` (no body, no key). `restored` carries the 200 body. `refused` is a 409 (W4 not
 * archived, W5 code taken, P1 contention), shown under the archived banner as sent. `already-live`: a refusal after
 * which the re-read found the warehouse live (an interrupted press that committed, another tab); `message` is the
 * server's sentence, spoken as sent.
 */
export type RestoreWarehouseResult =
  | { kind: 'restored'; warehouse: Warehouse }
  | { kind: 'already-live'; warehouse: Warehouse; message: string }
  | Refused | Forbidden | NotFound | SessionEnded | Unreachable | ServerError;

/** The archived page's Restore warehouse. Caller contract: `restored` resolves after the re-read warehouse is committed. */
export type RestoreWarehouseAction = () => Promise<RestoreWarehouseResult>;
