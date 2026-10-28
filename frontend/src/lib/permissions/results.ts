// Typed result of saving a role's permission set (b4-architecture.md tree: `lib/permissions/results.ts`, on the shared
// kinds in `lib/results.ts`). The checklist renders what this says; B-3b maps the BFF response onto it.
// Framework-free (architecture.test.ts).
//
// Served by `UpdateRole` (`PATCH /api/v1/roles/{id}`, `role.edit`; `RoleResource.php`): 200 with the role in its new
// state. Its `Idem` cell is `no` and sound because the edit is a SET: a repeat of the same set computes an empty delta
// and writes nothing (b4-owed-copy.md R1-3), so no idempotency key is carried here.

import type { Role } from '../api/roles';
import type { Forbidden, NotFound, Refused, ServerError, SessionEnded, Unreachable } from '../results';

/**
 * 200: the role as saved. Its served `margin_exposure` is the ONLY source of the checklist's margin banner (R4-4 L-10);
 * the client computes no exposure from codes.
 */
export interface RoleSaved {
  kind: 'saved';
  role: Role;
}

/** A refusal's `message` is the server's sentence (e.g. `role.missing_companion_view`), rendered verbatim, never stored. */
export type SaveRoleResult = RoleSaved | Refused | NotFound | Forbidden | SessionEnded | Unreachable | ServerError;

/**
 * The injected action: the whole permission set to save, sorted. Caller contract (B-3b): after `saved`, re-read the
 * role and pass it as the new `role` prop; never unmount the checklist or change its `key` while doing so. A thrown
 * action is treated as `unreachable`.
 */
export type SaveRoleAction = (permissionCodes: readonly string[]) => Promise<SaveRoleResult>;
