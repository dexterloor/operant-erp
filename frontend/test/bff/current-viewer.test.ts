import { afterEach, describe, expect, it, vi } from 'vitest';

// The request's viewer read (b3b-design.md §10 "Viewer", D-7) and where it sends a person whose session has ended. No
// cookie is not a session that ended: plain /login. A cookie that does not open, or GetCurrentUser 401, ends the session:
// the caller names the address to go to, built from its own page, so sign-in brings the person back there.

const h = vi.hoisted(() => ({ cookie: undefined as string | undefined, session: null as object | null, status: 200 }));

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
vi.mock('next/headers', () => ({
  cookies: async () => ({ get: () => (h.cookie === undefined ? undefined : { value: h.cookie }) }),
}));
vi.mock('@/lib/bff/server/session', () => ({
  SESSION_COOKIE: 'c',
  readSession: async () => h.session,
  tokenOf: () => 't',
}));
vi.mock('@/lib/bff/server/apiClient', () => ({
  apiFetch: async () =>
    new Response(JSON.stringify({ id: 'v', display_name: 'V', email: 'v@x', permission_codes: ['role.view'] }), { status: h.status }),
}));

const { currentViewer, sessionHasEnded, sessionEndedPath } = await import('@/app/(app)/currentViewer');

afterEach(() => {
  h.cookie = undefined;
  h.session = null;
  h.status = 200;
});

const redirectOf = (p: Promise<unknown>) =>
  p.then(
    () => null,
    (e: unknown) => (e instanceof Redirected ? e.to : e),
  );

const HERE = sessionEndedPath('/admin/roles', { archived: 'true' });

describe('currentViewer sends an ended session to the address its page names', () => {
  it('no cookie → plain /login (nothing ended), whatever the page named', async () => {
    expect(await redirectOf(currentViewer(HERE))).toBe('/login');
    expect(await sessionHasEnded()).toBe(false);
  });

  it('a cookie that does not open → the page’s own session-ended address', async () => {
    h.cookie = 'x';
    expect(await redirectOf(currentViewer(HERE))).toBe('/login?reason=session_ended&return_to=%2Fadmin%2Froles%3Farchived%3Dtrue');
    expect(await sessionHasEnded()).toBe(true);
  });

  it('GetCurrentUser 401 → the page’s own session-ended address', async () => {
    h.cookie = 'x';
    h.session = { sid: 's' };
    h.status = 401;
    expect(await redirectOf(currentViewer(HERE))).toBe('/login?reason=session_ended&return_to=%2Fadmin%2Froles%3Farchived%3Dtrue');
    expect(await sessionHasEnded()).toBe(true);
  });

  it('a live session → the viewer, no redirect', async () => {
    h.cookie = 'x';
    h.session = { sid: 's' };
    const r = await currentViewer(HERE);
    expect(r.viewer).toEqual({ id: 'v', displayName: 'V', email: 'v@x', permissionCodes: ['role.view'] });
    expect(await sessionHasEnded()).toBe(false);
  });

  it('any other GetCurrentUser answer throws to the error page, never a redirect', async () => {
    h.cookie = 'x';
    h.session = { sid: 's' };
    h.status = 500;
    const e = await redirectOf(currentViewer(HERE));
    expect(e).toBeInstanceOf(Error);
    expect(e).not.toBeInstanceOf(Redirected);
  });
});
