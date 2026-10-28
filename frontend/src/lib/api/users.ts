// Hand-mirrored from `backend/src/Modules/UserManagement/ApiResource/UserResource.php` and `RoleReference.php` at
// `e2180dd` (main checkout, 2026-10-27; `UserResource.php` last changed in `71214cc`). When BK-5 lands, each becomes
// `export type X = components['schemas']['X']` from the generated artefact — `User` is a named component (`shortName:
// 'User'`); `RoleReference` is its expanded `roles[]` item (API-23b: `{id, name}` only). OfferedRole and Permission are
// inline schemas, not components (RED H-4) — they are not mirrored here.
//
// Wire field names stay snake_case, as served, so codegen is a drop-in. Types only: this file imports nothing
// (architecture.test.ts) and holds no value.

/** API-23b: a role reference is its id and display field only — never its permission list. */
export interface RoleReference {
  id: string;
  name: string;
}

/** `GetUser` / `RestoreUser`'s 200 body (§4.4.3 `User`, K-1). The same for every caller. */
export interface User {
  id: string;
  email: string;
  display_name: string;
  /** "Switched off" when false. Delete leaves it as it was; restore brings it back as it was (§2.1 E8). */
  is_active: boolean;
  /** RFC 3339 instant the person was deleted, or null while they are live (§2.1 E10). */
  archived_at: string | null;
  /** Live roles only. */
  roles: RoleReference[];
  /** PC-12 clause 3's union warning over `roles[]`: a warning, never a refusal. */
  margin_exposure: boolean;
}
