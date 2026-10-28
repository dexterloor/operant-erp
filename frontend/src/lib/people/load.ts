import type { User } from '../api/users';
import type { Read, ReadFailure } from '../bff/reads';
import { loadCatalogue, loadRoleAccess, type Catalogue, type RoleAccess } from '../roles/load';

// The people loaders (b3b-design.md §10): pure, run over the server transport on first render and over the browser one
// for every re-read. Each issues only its own read; the page decides from the viewer's codes whether to call it (M-4).

export type UsersLoad = { readonly kind: 'ok'; readonly users: User[] } | { readonly kind: ReadFailure };
export type PersonLoad = { readonly kind: 'ok'; readonly person: User } | { readonly kind: ReadFailure };

const isUser = (x: unknown): x is User => !!x && typeof x === 'object' && typeof (x as User).id === 'string' && Array.isArray((x as User).roles);

/** `ListUsers`: the live view, or the Archived one (`archived=true`). Bounded (IA §5.6): the whole view in one answer. */
export async function loadUsers(read: Read, archived: boolean, signal?: AbortSignal): Promise<UsersLoad> {
  const query = new URLSearchParams();
  if (archived) query.set('archived', 'true');
  const a = await read('ListUsers', { query, signal });
  if (a.kind !== 'ok') return a;
  const items = (a.body as { items?: unknown } | null)?.items;
  return Array.isArray(items) && items.every(isUser) ? { kind: 'ok', users: items } : { kind: 'server-error' };
}

/** `GetUser`: a deleted person answers 200 with `archived_at` set (F-28), never 404. */
export async function loadPerson(read: Read, id: string, signal?: AbortSignal): Promise<PersonLoad> {
  const a = await read('GetUser', { params: { id }, signal });
  if (a.kind !== 'ok') return a;
  return isUser(a.body) ? { kind: 'ok', person: a.body } : { kind: 'server-error' };
}

/** Which secondary reads the viewer's codes allow (M-4): the page never issues one it may not make. */
export interface ViewGates {
  /** `role.assign`: the offered roles and the pending walk. */
  readonly assign: boolean;
  /** `role.view`: the roles and the permission catalogue. */
  readonly view: boolean;
}

export interface PersonView {
  readonly person: User;
  readonly access: RoleAccess | null;
  readonly catalogue: Catalogue | null;
}

/**
 * The person page's whole read (§10's per-read table). GetUser failing fails the read with its own kind (the page then
 * swaps for 403/404). A 403 on a role read does NOT fail it: that read's part is absent and `lost` says which (the page
 * shows R1-5 for `assign`, drops the panel for `view`). A 404 on any read fails it as `not-found` (ST-5). Any other
 * failure fails it, so the caller keeps the old props.
 */
export type PersonViewLoad = { readonly kind: 'ok'; readonly view: PersonView; readonly lost: { readonly assign: boolean; readonly view: boolean } } | { readonly kind: ReadFailure };

export async function loadPersonView(read: Read, id: string, gates: ViewGates, signal?: AbortSignal): Promise<PersonViewLoad> {
  const p = await loadPerson(read, id, signal);
  if (p.kind !== 'ok') return p;
  let access: RoleAccess | null = null;
  let catalogue: Catalogue | null = null;
  const lost = { assign: false, view: false };
  if (gates.assign) {
    const a = await loadRoleAccess(read, id, signal);
    if (a.kind === 'ok') access = a.value;
    else if (a.kind === 'forbidden') lost.assign = true;
    else return a;
  }
  if (gates.view) {
    const c = await loadCatalogue(read, signal);
    if (c.kind === 'ok') catalogue = c.value;
    else if (c.kind === 'forbidden') lost.view = true;
    else return c;
  }
  return { kind: 'ok', view: { person: p.person, access, catalogue }, lost };
}
