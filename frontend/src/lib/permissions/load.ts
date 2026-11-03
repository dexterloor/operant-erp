import type { Permission } from '../api/permissions';
import type { Role } from '../api/roles';
import type { Read, ReadFailure } from '../bff/reads';

// The roles pages' loaders (b3b-design.md §9.4, §10): pure, run over the server transport on first render and the
// browser one for every re-read.

export type RolesLoad = { readonly kind: 'ok'; readonly roles: Role[] } | { readonly kind: ReadFailure };
export type RolePageLoad = { readonly kind: 'ok'; readonly role: Role; readonly permissions: Permission[] } | { readonly kind: ReadFailure };
export type RoleLoad = { readonly kind: 'ok'; readonly role: Role } | { readonly kind: ReadFailure };

const isRole = (x: unknown): x is Role => !!x && typeof x === 'object' && typeof (x as Role).id === 'string' && Array.isArray((x as Role).permission_codes);
const itemsOf = (body: unknown): unknown[] | null => {
  const items = (body as { items?: unknown } | null)?.items;
  return Array.isArray(items) ? items : null;
};

/** `ListRoles`: the live view, or the Archived one (`archived=true`). Bounded: the whole view in one answer. */
export async function loadRoles(read: Read, archived: boolean, signal?: AbortSignal): Promise<RolesLoad> {
  const query = new URLSearchParams();
  if (archived) query.set('archived', 'true');
  const a = await read('ListRoles', { query, signal });
  if (a.kind !== 'ok') return a;
  const items = itemsOf(a.body);
  return items && items.every(isRole) ? { kind: 'ok', roles: items } : { kind: 'server-error' };
}

/** `GetRole` alone: the checklist's re-read after a save (no remount, §9.4). */
export async function loadRole(read: Read, id: string, signal?: AbortSignal): Promise<RoleLoad> {
  const a = await read('GetRole', { params: { id }, signal });
  if (a.kind !== 'ok') return a;
  return isRole(a.body) ? { kind: 'ok', role: a.body } : { kind: 'server-error' };
}

/** `GetRole` + `ListPermissions` (both `role.view`): a role page's first read. */
export async function loadRolePage(read: Read, id: string, signal?: AbortSignal): Promise<RolePageLoad> {
  const r = await loadRole(read, id, signal);
  if (r.kind !== 'ok') return r;
  const p = await read('ListPermissions', { signal });
  if (p.kind !== 'ok') return p;
  const items = itemsOf(p.body);
  return items ? { kind: 'ok', role: r.role, permissions: items as Permission[] } : { kind: 'server-error' };
}
