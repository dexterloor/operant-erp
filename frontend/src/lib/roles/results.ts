// Typed results of the role picker's and the Waiting group's injected actions (b4-architecture.md tree:
// `lib/roles/results.ts`, on the shared kinds in `lib/results.ts`; RED M-2). B-3b maps BFF responses onto them.
// Framework-free (architecture.test.ts). A thrown action is treated as `unreachable`.
//
// No idempotency key parameter (Revision 2 C-1): `AssignRole` and `CreateRoleGrantProposal` demand the header but do not
// store its value — the natural pair is the idempotency, so a retry is refused `already_held` / `already_pending` whatever
// key it carries; the B-3b action mints a header per call.

import type { RoleGrantProposal } from '../api/proposals';
import type { Role } from '../api/roles';
import type { Forbidden, NotFound, Refused, ServerError, SessionEnded, Unreachable } from '../results';

export type RoleActionFailure = Refused | NotFound | Forbidden | SessionEnded | Unreachable | ServerError;

/** `POST /users/{id}/roles` → 201 + the granted `RoleResource` (`AssignRoleProcessor`). */
export type AssignResult = { kind: 'given'; role: Role } | RoleActionFailure;
/** `POST /users/{id}/role-grant-proposals` → 201 + the proposal. */
export type ProposeResult = { kind: 'proposed'; proposal: RoleGrantProposal } | RoleActionFailure;
/** `DELETE /users/{id}/roles/{roleId}` → 204. A grant that is already gone is a 404 with no text (R1-6). */
export type RevokeResult = { kind: 'taken-away' } | RoleActionFailure;
/** `POST /role-grant-proposals/{id}/confirmation` → 200 + the proposal now `confirmed`. */
export type ConfirmResult = { kind: 'confirmed'; proposal: RoleGrantProposal } | RoleActionFailure;
/** `…/rejection` → 200 + the proposal now `rejected`. */
export type RejectResult = { kind: 'rejected'; proposal: RoleGrantProposal } | RoleActionFailure;
/** `…/cancellation` ("Withdraw") → 200 + the proposal now `cancelled`. */
export type WithdrawResult = { kind: 'withdrawn'; proposal: RoleGrantProposal } | RoleActionFailure;

/**
 * What a re-read answers (RED M-4). `failed` covers every page of the pending-proposals walk (A-1: never a partial set).
 * `failure` says how, for the R1-1 heading (lost connection vs 5xx); a 401 navigates (R1-4), so it maps to either.
 */
export type RereadResult = { kind: 'ok' } | { kind: 'failed'; failure: 'unreachable' | 'server-error' };

/**
 * Re-read the person (`GetUser`), `ListOfferedRoles`, `ListRoles`/`ListPermissions` when shown, and the WHOLE
 * `ListRoleGrantProposals?user={id}&status=pending` walk (followed through `next_cursor` to the end; A-1), then pass the
 * new props. **Caller contract:** resolve only after the new props are set; never unmount the picker, swap in
 * `RegionLoading`, or change its `key` during a re-read (Revision 1 "State"). If any page of the walk fails, resolve
 * `failed` and keep the old props — never a partial `pendingRoleIds`.
 */
export type RereadAction = () => Promise<RereadResult>;

export interface RolePickerActions {
  assign: (roleId: string) => Promise<AssignResult>;
  propose: (roleId: string) => Promise<ProposeResult>;
  revoke: (roleId: string) => Promise<RevokeResult>;
  reread: RereadAction;
}

export interface RoleProposalActions {
  confirm: (proposalId: string) => Promise<ConfirmResult>;
  /** RED L-7: the optional reason is typed; no field is rendered until `UX` rules one (R1-11 item 4). */
  reject: (proposalId: string, reason?: string) => Promise<RejectResult>;
  withdraw: (proposalId: string) => Promise<WithdrawResult>;
  reread: RereadAction;
  /** RED H-4: `after` = Older (next_cursor), `before` = Newer (previous_cursor). */
  onPage: (direction: 'after' | 'before', cursor: string) => void;
}
