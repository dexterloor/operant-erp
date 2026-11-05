// Typed results of the warehouse page's injected actions (Revision 7 A-19 item 5), on the shared kinds in
// `lib/results.ts`. Framework-free (architecture.test.ts). A thrown action is treated as `unreachable`.

import type { CreateStorageLocationInput, CreateWarehouseInput, StorageLocation, UpdateStorageLocationInput, UpdateWarehouseInput, Warehouse } from '../api/warehouses';
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

/** The create form's fields, in screen order; a name the mirrored create input lacks is a type error (step-3 L-3). */
export const CREATE_WAREHOUSE_FIELDS = ['code', 'name', 'country_code'] as const satisfies readonly (keyof CreateWarehouseInput & string)[];

/**
 * `POST /warehouses` (Idempotency-Key required, a fresh one per submit; A-19 item 6). `created` carries the 201 body.
 * `invalid` is a 422 mapped onto the form; `refused` a 409 (W1 code taken, P1 contention), shown at form level as sent.
 * A create whose answer was lost and is submitted again gets W1 for the warehouse it did create (the key is not stored,
 * F-6); it is not suppressed or re-worded.
 */
export type CreateWarehouseResult =
  | { kind: 'created'; warehouse: Warehouse }
  | { kind: 'invalid'; fieldErrors: FieldErrors<WarehouseField>; formErrors: string[] }
  | Refused | Forbidden | NotFound | SessionEnded | Unreachable | ServerError;

/** The create page's submit: the body holds all three fields as typed. */
export type CreateWarehouseAction = (input: Required<CreateWarehouseInput>) => Promise<CreateWarehouseResult>;

/**
 * The location dialogs' fields, in screen order (§2 step 4.5): Code and Kind. A name the mirrored update input lacks is a
 * type error. Edit sends only the members that changed (`changedFields`), so a code-only change sends no `kind` and
 * cannot reach the quarantine release (`storage_location.release_needs_owner`).
 */
export const LOCATION_FIELDS = ['code', 'kind'] as const satisfies readonly (keyof UpdateStorageLocationInput & string)[];
export type LocationField = (typeof LOCATION_FIELDS)[number];
/** The body Edit location sends: the changed members only (never empty: Save is unavailable while nothing changed). */
export type LocationChange = Partial<Pick<UpdateStorageLocationInput, LocationField>>;

/** Add location's fields, in screen order; a name the mirrored create input lacks is a type error (step-3 L-3). */
export const CREATE_LOCATION_FIELDS = ['code', 'kind'] as const satisfies readonly (keyof CreateStorageLocationInput & string)[];

/**
 * `POST /warehouses/{id}/locations` (Idempotency-Key required, a fresh one per submit; A-19 item 6 — the create does not
 * replay on its key, `CreateStorageLocationProcessor.php:15-16`). `created` carries the 201 body; `invalid` a 422 mapped
 * beside Code and Kind; `refused` a 409 (L1 code taken, L3 warehouse archived, P1 contention), shown in the dialog as
 * sent. A create whose answer was lost and is submitted again gets L1 for the location it did create; it is not
 * suppressed or re-worded.
 */
export type CreateLocationResult =
  | { kind: 'created'; location: StorageLocation }
  | { kind: 'invalid'; fieldErrors: FieldErrors<LocationField>; formErrors: string[] }
  | Refused | Forbidden | NotFound | SessionEnded | Unreachable | ServerError;

/** Add location's submit: the body holds both fields as chosen. */
export type CreateLocationAction = (input: Required<CreateStorageLocationInput>) => Promise<CreateLocationResult>;

/**
 * `PATCH /warehouses/{id}/locations/{locationId}`. `saved` carries the 200 body; `invalid` a 422 mapped beside Code;
 * `refused` a 409 (L2 code taken, L4 warehouse archived, L5 location archived, L13 release needs an owner, P1 contention),
 * shown in the dialog as sent.
 */
export type SaveLocationResult =
  | { kind: 'saved'; location: StorageLocation }
  | { kind: 'invalid'; fieldErrors: FieldErrors<LocationField>; formErrors: string[] }
  | Refused | Forbidden | NotFound | SessionEnded | Unreachable | ServerError;

/** Edit location's Save for one row. */
export type SaveLocationAction = (locationId: string, change: LocationChange) => Promise<SaveLocationResult>;

/**
 * `POST /warehouses/{id}/locations/{locationId}/restoration` (no body, no key). `restored` carries the 200 body;
 * `refused` a 409 (L10 warehouse archived, L11 not archived, L12 code taken, P1), shown above the rows as sent.
 */
export type RestoreLocationResult = { kind: 'restored'; location: StorageLocation } | Refused | Forbidden | NotFound | SessionEnded | Unreachable | ServerError;

/** Restore location for one archived row. */
export type RestoreLocationAction = (locationId: string) => Promise<RestoreLocationResult>;

/**
 * A refused Delete whose `rule` is `transaction.contention` (P1, A-18 S-1): its sentence tells the reader to try again, so
 * the dialog shows it and keeps its Delete button (warehouses-ux-copy.md B-7 "Exception"). Mapped in `wired.ts` only.
 */
export interface Contention {
  kind: 'contention';
  message: string;
}

/**
 * `DELETE /warehouses/{id}` (204, no body, no key). `deleted` resolves after the re-read warehouse (now archived) is
 * committed, or, when that re-read failed, after the page was told (`onRereadFailed`). `refused` is any other 409
 * (W6–W10), shown in the dialog as sent, which then offers Close only (ST-9b).
 */
export type DeleteWarehouseResult = { kind: 'deleted' } | Contention | Refused | Forbidden | NotFound | SessionEnded | Unreachable | ServerError;

/** The live warehouse page's Delete warehouse. */
export type DeleteWarehouseAction = () => Promise<DeleteWarehouseResult>;

/**
 * `DELETE /warehouses/{id}/locations/{locationId}` (204, no body, no key). `refused` is any other 409 (L6–L9), shown in
 * the dialog as sent, which then offers Close only (ST-9b). The section re-reads its view after the dialog closes.
 */
export type DeleteLocationResult = { kind: 'deleted' } | Contention | Refused | Forbidden | NotFound | SessionEnded | Unreachable | ServerError;

/** Delete for one live row. */
export type DeleteLocationAction = (locationId: string) => Promise<DeleteLocationResult>;
