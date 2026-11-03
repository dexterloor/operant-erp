// Typed result of one bulk run (b4-architecture.md tree: `lib/bulk/results.ts`, on the shared kinds in `lib/results.ts`;
// RED M-2). The bar renders what this says; B-3b maps the BFF response onto it. Framework-free (architecture.test.ts).
//
// Served by `CreateBulkArchival` / `CreateBulkRestoration` (`backend/src/Platform/Http/Bulk/ApiResource/
// BulkArchivalRequest.php`): 200 `{requested, applied}` and nothing else — never which rows (E-14).

import type { Forbidden, NotFound, Refused, ServerError, SessionEnded, Unreachable } from '../results';

/** 200: how many were asked for and how many changed. A replay of the same key and body answers the stored envelope. */
export interface BulkDone {
  kind: 'done';
  requested: number;
  applied: number;
}

/**
 * 422 `key_expired` on pointer `#Idempotency-Key` (`IdempotencyEnvelopeStore.php`): only possible after a run that
 * committed more than 24 h ago (b4-owed-copy.md R1 F17). Its own kind, so no component branches on the served code
 * (Revision 1 C-2); B-3b maps it here (R1-7 step 1). Renders R1-8 **Already sent**.
 */
export interface BulkKeyExpired {
  kind: 'key-expired';
}

/**
 * Everything else. A `refused` carries the server's sentence verbatim (e.g. `bulk.contention`, `BulkArchival.php:73`);
 * B-3b maps a 409 `idempotency-key-conflict` (detail only) to `refused` with an empty `message`, which renders R1-7
 * step 5's generic body.
 */
export type BulkResult = BulkDone | BulkKeyExpired | Refused | NotFound | Forbidden | SessionEnded | Unreachable | ServerError;

/**
 * The injected action (Revision 1 C-1 / RED H-3): `idempotencyKey` is minted by the bulk surface when a selection is
 * first submitted and passed again, unchanged, on every re-run of the unchanged selection — so a retry after a lost
 * response replays the stored result instead of running twice. A thrown action is treated as `unreachable`.
 */
export type BulkRunAction = (ids: readonly string[], idempotencyKey: string) => Promise<BulkResult>;
