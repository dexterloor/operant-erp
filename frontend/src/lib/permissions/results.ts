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

/**
 * `RestoreRole` (`POST /api/v1/roles/{id}/restoration`, 200 + the role, no key): `restored` with the live role. `already`
 * is true when the server answered `role.is_not_archived` and the re-read found the role live (restore-role-design.md
 * R1-D4): the restore has happened, by this press or another. `page-took-over`: that re-read failed and the page shows
 * its own region error or swaps (ST-4b / ST-5), so the component shows nothing.
 */
export type RestoreRoleResult =
  | { kind: 'restored'; role: Role; already: boolean }
  | { kind: 'page-took-over' }
  | Refused
  | NotFound
  | Forbidden
  | SessionEnded
  | Unreachable
  | ServerError;

/**
 * The injected restore. Caller contract: resolve `restored` (and a refusal after a re-read) only after `setRole` has
 * committed the role it carries, so the page's branch on `archived_at` has swapped when the component reads the result.
 * A thrown action is treated as `unreachable`, and the component clears its busy state either way.
 */
export type RestoreRoleAction = () => Promise<RestoreRoleResult>;
