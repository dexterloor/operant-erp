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
}
