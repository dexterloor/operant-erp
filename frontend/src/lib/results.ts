// Shared result kinds for every injected server action (b4-architecture.md Revision 1 + RED M-2). A page or component
// takes its actions as async callbacks resolving to a typed union and renders what the union says; B-3b maps BFF
// responses onto these kinds. Framework-free: nothing here imports react, next or a component (architecture.test.ts).
//
// One copy: `lib/auth/results.ts` re-exports the three kinds B-3a already used (Revision 2, C-3), so no B-3a import moved.

/** A failure that never reached a usable response (fetch rejected, offline). A thrown callback is treated as this. */
export interface Unreachable {
  kind: 'unreachable';
}
/** 5xx or an unexpected status. Never carries the server's `detail` (ST-3: 5xx detail is for the log). */
export interface ServerError {
  kind: 'server-error';
}
/** 429. No count, no window (ST-8a). */
export interface RateLimited {
  kind: 'rate-limited';
}
/** 401: the session has ended; the caller routes to sign-in (BFF note §8). */
export interface SessionEnded {
  kind: 'session-ended';
}
/**
 * 403: the permission was lost between load and click (ST-4's state, e.g. `AssignRole`'s `LockTimeDenial`). Rendered
 * as R1-5's banner from client copy; never server text (b4-final-red L-6).
 */
export interface Forbidden {
  kind: 'forbidden';
}
/** 404: the record is gone, or was never this caller's (one state for both). */
export interface NotFound {
  kind: 'not-found';
}
/**
 * 409/422 business-rule refusal, as served by `ApiProblem::businessRuleViolated($rule, $message)`
 * (`backend/src/Platform/Http/Problem/ApiProblem.php`: `getRule()` → `rule`, `getRuleMessage()` serialised as
 * `message`). `rule` is the machine code the client may branch on (only through a named constant, Revision 1 C-2);
 * `message` is the server's sentence, rendered verbatim and never stored in `copy/`.
 */
export interface Refused {
  kind: 'refused';
  rule: string;
  message: string;
  /**
   * Set only on a 422 (Revision 7 A-19 item 3): every entry of the problem's `errors[]`, in order, keeping only entries
   * whose `pointer`, `code` and `message` are all strings, each projected to exactly those three (a served extra member
   * is never carried). `rule` and `message` above stay the first served entry's, as before. A 409 carries none.
   */
  violations?: readonly Violation[];
}

/** One entry of a 422 problem's `errors[]`, projected (A-19 item 3). `message` is the server's sentence, verbatim. */
export interface Violation {
  readonly pointer: string;
  readonly code: string;
  readonly message: string;
}

/** Field name → the server's sentence for it (moved here from `lib/auth/results.ts` by Revision 7 A-19 item 4). */
export type FieldErrors<F extends string> = Partial<Record<F, string>>;

/**
 * A refused write whose `rule` is `transaction.contention` (P1, A-18 S-1): its sentence tells the reader to try again, so
 * a Delete dialog shows it and keeps its Delete button (warehouses-ux-copy.md B-7 "Exception"). Mapped only in a family's
 * `wired.ts` (the one `lib/api/problems` importer per family). Moved here from `lib/warehouses/results.ts` by Revision 8
 * R8-3 (items plan I-0a), so the shared record dialogs in `components/records/` name no family's results.
 */
export interface Contention {
  kind: 'contention';
  message: string;
}

/**
 * A record-level `DELETE` (204, no body, no key), any family (R8-3). `deleted` resolves after the caller's re-read is
 * committed, or after the page was told the re-read failed. `refused` is any other 409, shown in the dialog as sent, which
 * then offers Close only (ST-9b). `contention` keeps Delete.
 */
export type DeleteResult = { kind: 'deleted' } | Contention | Refused | Forbidden | NotFound | SessionEnded | Unreachable | ServerError;

/**
 * A record-level restore (`POST …/restoration`, no body, no key), any family (R8-3). `S` is the family's payload, e.g.
 * `{ warehouse: Warehouse }`. `already-live`: a refusal after which the re-read found the record live (an interrupted press
 * that committed, another tab); `message` is the server's sentence, spoken as sent.
 */
export type RestoreResult<S> = ({ kind: 'restored' } & S) | ({ kind: 'already-live'; message: string } & S) | RestoreFailure;

/** Every answer of a record-level restore that leaves the record archived. */
export type RestoreFailure = Refused | Forbidden | NotFound | SessionEnded | Unreachable | ServerError;
