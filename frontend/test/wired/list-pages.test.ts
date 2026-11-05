import { isValidElement, type ReactElement } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { DECLARED_ROUTES } from '@/lib/routes';
import { safeReturnPath } from '@/lib/session';

// b3b-followup C-4 (O-1): the two list pages read `?archived=true` from the address and load that view on the server.
// Next 16 hands `searchParams` to a page as a Promise (final-red L-2), so the page awaits it. The viewer, the server
// transport and the loaders are stubbed: only the page's own choice is under test.

const load = vi.hoisted(() => ({ users: vi.fn(), roles: vi.fn(), warehouses: vi.fn() }));
// The real `sessionEndedPath` (the return address is under test); only the viewer read is stubbed.
vi.mock('@/app/(app)/currentViewer', async (actual) => ({
  ...(await actual<typeof import('@/app/(app)/currentViewer')>()),
  currentViewer: async () => ({ session: { sid: 's' }, viewer: { id: 'v', permissionCodes: ['app_user.view', 'role.view', 'warehouse.view'] } }),
}));
class Redirected extends Error {
  constructor(readonly to: string) {
    super(`redirect ${to}`);
  }
}
vi.mock('next/navigation', () => ({
  redirect: (to: string) => {
    throw new Redirected(to);
  },
}));
vi.mock('@/lib/bff/server/reads', () => ({ serverRead: () => 'read' }));
vi.mock('@/lib/people/load', () => ({ loadUsers: load.users }));
vi.mock('@/lib/permissions/load', () => ({ loadRoles: load.roles }));
vi.mock('@/lib/warehouses/load', () => ({ loadWarehouses: load.warehouses }));

const { default: UsersPage } = await import('@/app/(app)/admin/users/page');
const { default: RolesPage } = await import('@/app/(app)/admin/roles/page');
const { default: WarehousesPage } = await import('@/app/(app)/warehouses/page');

type Page = (p: { searchParams: Promise<Record<string, string | string[] | undefined>> }) => Promise<ReactElement>;
const cases: [string, Page, typeof load.users, object][] = [
  ['/admin/users', UsersPage as Page, load.users, { kind: 'ok', users: [] }],
  ['/admin/roles', RolesPage as Page, load.roles, { kind: 'ok', roles: [] }],
  ['/warehouses', WarehousesPage as Page, load.warehouses, { kind: 'ok', warehouses: [], tenantHasRows: false }],
];

afterEach(() => {
  load.users.mockReset();
  load.roles.mockReset();
  load.warehouses.mockReset();
});

describe('list pages read the Archived view from the address (b3b-followup C-4)', () => {
  for (const [name, Page, loader, ok] of cases) {
    it(`${name}?archived=true loads and passes the Archived view; any other value is the live view`, async () => {
      loader.mockResolvedValue(ok);
      const el = await Page({ searchParams: Promise.resolve({ archived: 'true' }) });
      expect(isValidElement(el)).toBe(true);
      expect(loader).toHaveBeenLastCalledWith('read', true);
      expect((el.props as { initialArchived?: boolean }).initialArchived).toBe(true);
      for (const other of [{}, { archived: 'TRUE' }, { archived: '1' }, { archived: ['true', 'true'] }]) {
        const live = await Page({ searchParams: Promise.resolve(other) });
        expect(loader).toHaveBeenLastCalledWith('read', false);
        expect((live.props as { initialArchived?: boolean }).initialArchived).toBe(false);
      }
    });
  }
});

// A list read that answers 401 sends the viewer to sign in, returning to the SAME view: the address the sign-in
// handler will check (SD-1, `safeReturnPath` over the declared routes, as `handleSignIn` does) keeps the query.
describe('a list page that finds the session ended returns to the same view after sign-in', () => {
  async function returnAddress(Page: Page, loader: typeof load.users, query: Record<string, string | string[] | undefined>): Promise<string> {
    loader.mockResolvedValue({ kind: 'session-ended' });
    const err = await Page({ searchParams: Promise.resolve(query) }).then(
      () => null,
      (e: unknown) => e,
    );
    expect(err).toBeInstanceOf(Redirected);
    const to = new URL((err as Redirected).to, 'https://x.invalid');
    expect(to.pathname).toBe('/login');
    expect(to.searchParams.get('reason')).toBe('session_ended');
    return safeReturnPath(to.searchParams.get('return_to'), { declaredRoutes: DECLARED_ROUTES, fallback: '/' });
  }

  for (const [name, Page, loader] of cases) {
    it(`${name}?archived=true comes back to ${name}?archived=true; the live view comes back to ${name}`, async () => {
      expect(await returnAddress(Page, loader, { archived: 'true' })).toBe(`${name}?archived=true`);
      expect(await returnAddress(Page, loader, {})).toBe(name);
    });

    it(`${name} keeps any other query too, each value once, encoded`, async () => {
      expect(await returnAddress(Page, loader, { archived: 'true', q: 'a&b c', x: ['1', '2'] })).toBe(`${name}?archived=true&q=a%26b+c&x=1&x=2`);
    });
  }
});
