import type { User } from '../api/users';
import type { Read, ReadFailure } from '../bff/reads';

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
