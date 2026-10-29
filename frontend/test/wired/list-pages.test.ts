import { isValidElement, type ReactElement } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

// b3b-followup C-4 (O-1): the two list pages read `?archived=true` from the address and load that view on the server.
// Next 16 hands `searchParams` to a page as a Promise (final-red L-2), so the page awaits it. The viewer, the server
// transport and the loaders are stubbed: only the page's own choice is under test.

const load = vi.hoisted(() => ({ users: vi.fn(), roles: vi.fn() }));
vi.mock('@/app/(app)/currentViewer', () => ({
  currentViewer: async () => ({ session: { sid: 's' }, viewer: { id: 'v', permissionCodes: ['app_user.view', 'role.view'] } }),
  sessionEndedPath: (p: string) => `/login?return_to=${p}`,
}));
vi.mock('@/lib/bff/server/reads', () => ({ serverRead: () => 'read' }));
vi.mock('@/lib/people/load', () => ({ loadUsers: load.users }));
vi.mock('@/lib/permissions/load', () => ({ loadRoles: load.roles }));

const { default: UsersPage } = await import('@/app/(app)/admin/users/page');
const { default: RolesPage } = await import('@/app/(app)/admin/roles/page');

type Page = (p: { searchParams: Promise<Record<string, string | string[] | undefined>> }) => Promise<ReactElement>;
const cases: [string, Page, typeof load.users, object][] = [
  ['/admin/users', UsersPage as Page, load.users, { kind: 'ok', users: [] }],
  ['/admin/roles', RolesPage as Page, load.roles, { kind: 'ok', roles: [] }],
];

afterEach(() => {
  load.users.mockReset();
  load.roles.mockReset();
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
