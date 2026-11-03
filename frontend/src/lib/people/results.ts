// Typed results of the two person lifecycle actions (b4-architecture.md tree: `lib/people/results.ts`, on the shared
// kinds in `lib/results.ts`). The component renders what these say; B-3b maps BFF responses onto them.
// Framework-free: no react, next or component import (architecture.test.ts).

import type { User } from '../api/users';
import type { Forbidden, NotFound, Refused, ServerError, SessionEnded, Unreachable } from '../results';

/** What either action can answer besides success. A refusal's `message` is the server's sentence (ST-3), verbatim. */
export type PersonActionFailure = Refused | NotFound | Forbidden | SessionEnded | Unreachable | ServerError;

/** Why a delete's re-read did not answer a person, when the page takes over (a 401 navigates instead: R1-4). */
export type DeleteTakeover = 'forbidden' | 'not-found' | 'unreachable' | 'server-error';

/**
 * `DELETE /api/v1/users/{id}` → 204 (no body). `pageTookOver`: the delete happened but the re-read did not answer a
 * person, so the page shows what follows (its region error, ST-4b or ST-5) once the dialog has closed (FE-R1: R-14b,
 * FE-D1b) — the component then neither focuses the page `<h1>` nor speaks before the page has. The failure travels in
 * the result, so nothing has to be recorded on the side and handed over later (fe-r1-red.md L-2).
 */
export type DeletePersonResult = { kind: 'deleted'; pageTookOver?: DeleteTakeover } | PersonActionFailure;

/** `POST /api/v1/users/{id}/restoration` → 200 + the person (`RestoreUser`, API-21 branch 5). */
export type RestorePersonResult = { kind: 'restored'; person: User } | PersonActionFailure;

/**
 * The injected actions. Caller contract (B-3b): resolve `deleted` / `restored` only after the `person` prop has been
 * re-read (or replaced), so the page's branch on `archived_at` has already swapped when the dialog closes — the opener
 * is then gone by design and focus goes to the fallback (4b-4). A thrown action is treated as `unreachable`.
 */
export type DeletePersonAction = () => Promise<DeletePersonResult>;
export type RestorePersonAction = () => Promise<RestorePersonResult>;
