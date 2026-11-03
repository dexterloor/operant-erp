// Hand-mirrored from `backend/src/Modules/UserManagement/ApiResource/PermissionResource.php` and its list envelope
// `PermissionCollection.php` at `e2180dd` (main checkout, 2026-10-27; RED H-4). When BK-5 lands, this becomes
// `export type Permission = paths['/api/v1/permissions']['get']['responses']['200']['content']['application/json']
// ['items'][number]` — OfferedRole and Permission are inline schemas, not components (`PermissionCollection.php`: "mints
// no `Permission` component").
//
// Wire field names stay snake_case, as served, so codegen is a drop-in. Types only: this file imports nothing
// (architecture.test.ts) and holds no value.

/** One SoD row naming the code, and which side of it the code is on (V2-C-5). There is no served SoD-row list. */
export interface SodRowRef {
  id: string;
  half: 'preparing' | 'controlled';
}

/**
 * `ListPermissions` item (`role.view`, bounded TB-1c: the whole catalogue, cursors always null). No `id`: the code is
 * the key. `module` ships as stored (`Modules\Purchasing`); its heading is a client mapping (`copy/permissionLabels.ts`).
 * `description` is the server's mechanical `<resource>: <verb>` text — the label fallback for a code with no label.
 */
export interface Permission {
  code: string;
  module: string;
  description: string;
  /** `[]` for a code on no split. */
  sod_rows: SodRowRef[];
}
