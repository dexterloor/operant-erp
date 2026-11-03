// Hand-mirrored from `backend/src/Modules/UserManagement/ApiResource/RoleGrantProposalResource.php` at `732b60a` (RED
// H-4). When BK-5 lands this becomes `export type RoleGrantProposal = components['schemas']['RoleGrantProposal']` — a named
// component (`RoleGrantProposalCollection` `$ref`s it). Every member is served only under `role.assign` (PC-16).
// Wire field names stay snake_case, as served. Types only: this file imports nothing (architecture.test.ts).

/** PC-16's closed `app_user` reference: id and display name only. */
export interface UserDisplayReference {
  id: string;
  display_name: string;
}

/** E-15e's pending grant, or its later state. */
export interface RoleGrantProposal {
  id: string;
  user: UserDisplayReference;
  role: { id: string; name: string };
  /** The proposal-time snapshot, sorted. Never added to the effective permissions (a pending grant confers nothing). */
  permission_codes: string[];
  /** Stored status: `pending`, `confirmed`, `rejected`, `cancelled`, `lapsed`. An expired proposal is still `pending`. */
  status: string;
  /** Derived server-side, display only (R-13): pending and past `expires_at`. The client never compares clocks (4c S-2). */
  is_expired: boolean;
  proposed_by: UserDisplayReference;
  proposed_at: string;
  expires_at: string;
  decided_by: UserDisplayReference | null;
  decided_at: string | null;
  /** Only on `rejected`. */
  reason: string | null;
  lapse_reason: string | null;
}
