import type { Collection } from '../api/envelope';
import type { Permission } from '../api/permissions';
import type { RoleGrantProposal } from '../api/proposals';
import type { OfferedRole, Role } from '../api/roles';
import type { Read, ReadFailure } from '../bff/reads';

// The role reads of a person page (b3b-design.md §9.1–§9.3, §10): pure, run over the server transport on first render
// and the browser one on every re-read. The page issues each only when the viewer holds its codes (M-4).

/** The pending walk stops here; past it the read fails rather than show a partial set (§10, A-1). */
export const PENDING_WALK_MAX_PAGES = 50;

export type Load<T> = { readonly kind: 'ok'; readonly value: T } | { readonly kind: ReadFailure };

/** What the picker and the Waiting group read (`role.assign`). */
export interface RoleAccess {
  readonly offered: OfferedRole[];
  /** Every pending proposal for the person: the WHOLE walk (A-1), for `pendingRoleIds`. */
  readonly pending: RoleGrantProposal[];
  /** The page the Waiting group shows (the walk's first page until Older / Newer moves it). */
  readonly page: Collection<RoleGrantProposal>;
}

/** What the effective-permissions panel reads (`role.view`). */
export interface Catalogue {
  readonly roles: Role[];
  readonly permissions: Permission[];
}

const isCollection = (x: unknown): x is Collection<unknown> => !!x && typeof x === 'object' && Array.isArray((x as Collection<unknown>).items);

async function list<T>(read: Read, operationId: string, request: Parameters<Read>[1]): Promise<Load<Collection<T>>> {
  const a = await read(operationId, request);
  if (a.kind !== 'ok') return a;
  return isCollection(a.body) ? { kind: 'ok', value: a.body as Collection<T> } : { kind: 'server-error' };
}

/** One page of the person's pending proposals (`after` = Older, `before` = Newer). */
export function loadProposalPage(read: Read, userId: string, cursor: { direction: 'after' | 'before'; value: string } | null, signal?: AbortSignal): Promise<Load<Collection<RoleGrantProposal>>> {
  const query = new URLSearchParams({ user: userId, status: 'pending' });
  if (cursor) query.set(cursor.direction, cursor.value);
  return list<RoleGrantProposal>(read, 'ListRoleGrantProposals', { query, signal });
}

/** `ListOfferedRoles` + the whole pending walk. Any failed page fails the whole read (never a partial set). */
export async function loadRoleAccess(read: Read, userId: string, signal?: AbortSignal): Promise<Load<RoleAccess>> {
  const offered = await list<OfferedRole>(read, 'ListOfferedRoles', { params: { id: userId }, signal });
  if (offered.kind !== 'ok') return offered;
  const first = await loadProposalPage(read, userId, null, signal);
  if (first.kind !== 'ok') return first;
  const pending = [...first.value.items];
  let next = first.value.next_cursor;
  for (let pages = 1; next !== null; pages += 1) {
    if (pages >= PENDING_WALK_MAX_PAGES) return { kind: 'server-error' };
    const p = await loadProposalPage(read, userId, { direction: 'after', value: next }, signal);
    if (p.kind !== 'ok') return p;
    pending.push(...p.value.items);
    next = p.value.next_cursor;
  }
  return { kind: 'ok', value: { offered: offered.value.items, pending, page: first.value } };
}

/** `ListRoles` (live) + `ListPermissions` (the whole catalogue). */
export async function loadCatalogue(read: Read, signal?: AbortSignal): Promise<Load<Catalogue>> {
  const roles = await list<Role>(read, 'ListRoles', { signal });
  if (roles.kind !== 'ok') return roles;
  const permissions = await list<Permission>(read, 'ListPermissions', { signal });
  if (permissions.kind !== 'ok') return permissions;
  return { kind: 'ok', value: { roles: roles.value.items, permissions: permissions.value.items } };
}
