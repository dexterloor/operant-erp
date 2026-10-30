import { render } from '@testing-library/react';
import { Children, isValidElement, type ReactElement, type ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { DECLARED_ROUTES } from '@/lib/routes';
import { safeReturnPath } from '@/lib/session';

// The carry "session-expiry returns to / not the page": when the session has ended on a page inside the app, the person
// signs in again and lands back on THAT page (path and query; b3b-design.md §6.3, §10). Each page names its own
// address to currentViewer; the frame, which cannot know the page it wraps (Next layouts get no pathname), hands the
// browser's own address to the sign-in notice instead of a path-less redirect. Where the browser finally goes is still
// decided only by the sign-in handler's `safeReturnPath` over the declared routes, which this file applies to every
// address it reads, exactly as `handleSignIn` does.

const ID = '0b7c1f9e-3f4a-4c8e-9d2b-5a6e7f8091a2';
const v = vi.hoisted(() => ({ ended: false, named: [] as string[] }));

class Redirected extends Error {
  constructor(readonly to: string) {
    super(`redirect ${to}`);
  }
}
vi.mock('next/navigation', () => ({
  redirect: (to: string) => {
    throw new Redirected(to);
  },
  notFound: () => {
    throw new Error('not found');
  },
}));
// The real `sessionEndedPath`; currentViewer stands for a session that has ended: it redirects to the address it is given.
vi.mock('@/app/(app)/currentViewer', async (actual) => ({
  ...(await actual<typeof import('@/app/(app)/currentViewer')>()),
  currentViewer: async (ended: string) => {
    v.named.push(ended);
    throw new Redirected(ended);
  },
  sessionHasEnded: async () => v.ended,
}));
vi.mock('@/lib/bff/server/reads', () => ({ serverRead: () => 'read' }));
vi.mock('@/lib/bff/server/session', () => ({ clientSessionView: () => ({ sid: 's', tz: 'UTC' }) }));

afterEach(() => {
  v.ended = false;
  v.named = [];
  window.history.replaceState(null, '', '/');
});

/** Where the browser lands after signing in again from `to` (the sign-in handler's check, verbatim). */
function landsOn(to: string): string {
  const u = new URL(to, 'https://x.invalid');
  expect(u.origin).toBe('https://x.invalid');
  expect(u.pathname).toBe('/login');
  expect(u.searchParams.get('reason')).toBe('session_ended');
  return safeReturnPath(u.searchParams.get('return_to'), { declaredRoutes: DECLARED_ROUTES, fallback: '/' });
}

async function redirectOf(p: Promise<unknown>): Promise<string> {
  const e = await p.then(
    () => null,
    (x: unknown) => x,
  );
  expect(e).toBeInstanceOf(Redirected);
  return (e as Redirected).to;
}

const { default: StartPage } = await import('@/app/(app)/page');
const { default: UsersPage } = await import('@/app/(app)/admin/users/page');
const { default: RolesPage } = await import('@/app/(app)/admin/roles/page');
const person = await import('@/app/(app)/admin/users/[id]/page');
const role = await import('@/app/(app)/admin/roles/[id]/page');
const { default: AccountPage } = await import('@/app/(app)/account/password/page');
const { default: AppLayout } = await import('@/app/(app)/layout');
const { SessionEndedReturn } = await import('@/app/(app)/SessionEndedReturn');

type ListPage = (p: { searchParams: Promise<Record<string, string | string[] | undefined>> }) => Promise<ReactElement>;
type DetailPage = (p: { params: Promise<{ id: string }> }) => Promise<unknown>;

describe('a page whose session has ended returns to itself after sign-in', () => {
  it('/ → /', async () => {
    expect(landsOn(await redirectOf(StartPage()))).toBe('/');
  });

  for (const [name, Page] of [
    ['/admin/users', UsersPage as ListPage],
    ['/admin/roles', RolesPage as ListPage],
  ] as const) {
    it(`${name}?archived=true → ${name}?archived=true; the live view → ${name}`, async () => {
      expect(landsOn(await redirectOf(Page({ searchParams: Promise.resolve({ archived: 'true' }) })))).toBe(`${name}?archived=true`);
      expect(landsOn(await redirectOf(Page({ searchParams: Promise.resolve({}) })))).toBe(name);
    });
  }

  for (const [name, mod] of [
    ['/admin/users', person],
    ['/admin/roles', role],
  ] as const) {
    it(`${name}/<id> and its title → ${name}/<id>`, async () => {
      const params = () => Promise.resolve({ id: ID });
      expect(landsOn(await redirectOf((mod.default as DetailPage)({ params: params() })))).toBe(`${name}/${ID}`);
      expect(landsOn(await redirectOf(mod.generateMetadata({ params: params() })))).toBe(`${name}/${ID}`);
    });

    it(`${name}/<a malformed id> comes back to that same address, encoded (L-4)`, async () => {
      const to = await redirectOf((mod.default as DetailPage)({ params: Promise.resolve({ id: 'abc?x#y%' }) }));
      expect(new URL(to, 'https://x.invalid').searchParams.get('return_to')).toBe(`${name}/abc%3Fx%23y%25`);
      expect(landsOn(to)).toBe(`${name}/abc%3Fx%23y%25`);
    });
  }

  it('/account/password checks the session too, and lands on / (an account page is never a return target, A-13)', async () => {
    expect(landsOn(await redirectOf(AccountPage()))).toBe('/');
  });
});

describe('the frame hands an ended session to the browser’s own address', () => {
  it('an ended session renders no frame: only the page (which redirects with its own address) and the hand-over', async () => {
    v.ended = true;
    const child = <p>page</p>;
    const el = await AppLayout({ children: child });
    expect(isValidElement(el)).toBe(true);
    const kids = Children.toArray((el as ReactElement<{ children: ReactNode }>).props.children) as ReactElement[];
    expect(kids.map((k) => k.type)).toEqual(['p', SessionEndedReturn]);
    expect(v.named).toEqual([]);
  });

  it('a live session never renders the hand-over: the layout goes on to read the viewer', async () => {
    expect(await redirectOf(AppLayout({ children: <p>page</p> }))).toBe(v.named[0]);
    expect(v.named).toHaveLength(1);
  });

  it('navigates once to the notice with the current path and query', () => {
    window.history.replaceState(null, '', `/admin/users/${ID}?x=1#frag`);
    const navigate = vi.fn();
    const { container } = render(<SessionEndedReturn navigate={navigate} />);
    expect(container.innerHTML).toBe('');
    expect(navigate).toHaveBeenCalledTimes(1);
    const to = navigate.mock.calls[0]![0] as string;
    expect(new URL(to, 'https://x.invalid').searchParams.get('return_to')).toBe(`/admin/users/${ID}?x=1`);
    expect(landsOn(to)).toBe(`/admin/users/${ID}?x=1`);
  });

  it('navigates once only, however often it re-renders (L-5)', () => {
    const navigate = vi.fn();
    const { rerender } = render(<SessionEndedReturn navigate={navigate} />);
    rerender(<SessionEndedReturn navigate={navigate} />);
    rerender(<SessionEndedReturn navigate={navigate} here={() => '/admin/roles'} />);
    expect(navigate).toHaveBeenCalledTimes(1);
  });

  it.each(['//evil.example', '//evil.example/admin/users', '/%2F%2Fevil.example', '/.//evil.example', '/%5Cevil.example', '/account/password'])(
    'a hostile or undeclared address %j still lands on /',
    (path) => {
      const navigate = vi.fn();
      render(<SessionEndedReturn navigate={navigate} here={() => path} />);
      expect(landsOn(navigate.mock.calls[0]![0] as string)).toBe('/');
    },
  );
});
