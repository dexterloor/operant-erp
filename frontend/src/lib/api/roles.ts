// Hand-mirrored from `backend/src/Modules/UserManagement/ApiResource/RoleResource.php` at `e2180dd` (main checkout,
// 2026-10-27; RED H-4). When BK-5 lands, this becomes `export type Role = components['schemas']['Role']` — `Role` is a
// named component (`shortName: 'Role'`). `RoleReference` lives in `users.ts`; this file imports nothing, so the picker's
// references below repeat its `{id, name}` shape structurally. `OfferedRole` (B-4 step 5) is mirrored from
// `OfferedRoleResource.php` at `732b60a`: an INLINE schema (`OfferedRoleCollection` publishes it, no component), so under
// BK-5 it becomes `paths['/api/v1/users/{id}/offered-roles']['get']…['items'][number]`.
//
// `REFUSAL` (Revision 2 C-2): every served refusal code the client branches on lives once, here, as a named constant;
// logic and copy import it, and the AST scan (architecture.test.ts) exempts only this file's top-level declarations.
// Wire field names stay snake_case, as served.

/**
 * `GetRole` / `ListRoles` item / `UpdateRole`'s 200 body (API-23's derivation for `Role`). Deliberately carries no
 * `pending_*` member (PC-16 condition 1): `viewer` holds `role.view`.
 */
export interface Role {
  id: string;
  name: string;
  description: string;
  /** Codes, not references; sorted ascending by the server. */
  permission_codes: string[];
  /** A built-in role: it can't be changed or deleted (`RoleUpdate::IS_SYSTEM`). */
  is_system: boolean;
  holder_count: number;
  /** PC-12 clause 3's warning at definition (`MarginExposure::of(permission_codes)`): a warning, never a refusal. */
  margin_exposure: boolean;
  archived_at: string | null;
}

/**
 * The served `refusal` codes of `ListOfferedRoles` (`GrantRules::firstVerdict()`, phases 1–4, `user_role.<name>`). The
 * copy table in `copy/roles.ts` is declared `satisfies Record<KnownRefusal, …>`, so a new constant fails typecheck until
 * it has copy. `user_role.role_is_archived` is deliberately absent: archived roles are not offered (round-6 §2a), so it
 * takes the unknown-code fallback (R1-7) if it is ever served here.
 */
export const REFUSAL = {
  /** Phase 3. NOT a refusal on screen: held-ness comes from the person's `roles[]` (round-6 §2a S-1). */
  alreadyHeld: 'user_role.already_held',
  /** Phase 4 — the only code the client may override (principle 2 (a), `lib/roles/availability.ts`). */
  separationOfDuty: 'user_role.separation_of_duty',
  markerNeedsOwner: 'user_role.marker_needs_owner',
  /** Phase 2, custom roles only (`!is_system`). */
  exceedsActorPermissions: 'user_role.exceeds_actor_permissions',
  actorIsSubject: 'user_role.actor_is_subject',
  subjectIsInactive: 'user_role.subject_is_inactive',
  subjectIsDeleted: 'user_role.subject_is_deleted',
} as const;
export type KnownRefusal = (typeof REFUSAL)[keyof typeof REFUSAL];

/** One `conflicts_with` entry: another live role this one conflicts with, one entry per SoD row (`RoleConflictReference`). */
export interface RoleConflict {
  role: { id: string; name: string };
  /** The served SoD id (`SoD-1`…); its clause is client copy (`copy/sod.ts`). */
  sod_row: string;
}

/** `ListOfferedRoles` item (`GET /users/{id}/offered-roles`, `role.assign`): every verdict is the server's. */
export interface OfferedRole {
  id: string;
  name: string;
  /** PC-13 pairwise for the screen's selection (`OfferedRoleEvaluator::conflictsWith`). */
  conflicts_with: RoleConflict[];
  /** The roles that, together with this one, show both cost and sale prices (PC-12 cl. 3, a warning). */
  margin_exposure_with: Array<{ id: string; name: string }>;
  /** A `user_role.<name>` code, or null. Never text. */
  refusal: string | null;
  /** Ticking it stages a proposal. Always false when `refusal` is set. */
  requires_owner_confirmation: boolean;
}
