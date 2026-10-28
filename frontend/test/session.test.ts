import { describe, expect, it } from 'vitest';
import { initials, safeReturnPath, workspaceLabel } from '@/lib/session';

describe('D14 workspace label', () => {
  it('tenant_settings.view holders see the display name', () => {
    expect(workspaceLabel({ codes: ['tenant_settings.view'], displayName: 'Display Name', signInName: 'sign-in' })).toBe('Display Name');
  });
  it('everyone else sees the sign-in name exactly as typed', () => {
    expect(workspaceLabel({ codes: ['invoice.view'], displayName: 'Display Name', signInName: 'Sign-In As Typed' })).toBe('Sign-In As Typed');
  });
  it('falls back to the sign-in name when no display name was given', () => {
    expect(workspaceLabel({ codes: new Set(['tenant_settings.view']), displayName: null, signInName: 'sign-in' })).toBe('sign-in');
  });
});

describe('L-7 / SD-1 return path after session ended', () => {
  const opts = { declaredRoutes: ['purchase-orders', 'admin'] as const, fallback: '/fallback' };
  const uuid = '0b7c1f9e-3f4a-4c8e-9d2b-5a6e7f8091a2';

  it.each([
    [`/purchase-orders/${uuid}?status=draft`, `/purchase-orders/${uuid}?status=draft`],
    [`/admin/users/${uuid}#frag`, `/admin/users/${uuid}`],
    ['/purchase-orders/42?status=open#x', '/purchase-orders/42?status=open'],
  ])('keeps the declared same-origin path %s as %s', (raw, want) => expect(safeReturnPath(raw, opts)).toBe(want));

  it.each([
    '//evil.example',
    '/\\evil.example',
    'https://evil.example',
    '/%2F%2Fevil.example',
    '%2F%2Fevil.example',
    '/\\t/evil.example',
    '/\t/evil.example',
    'javascript:alert(1)',
    '/login?return_to=//evil',
    '/login',
    '/bff/session/sign-out',
    '/' + 'a'.repeat(2999),
    '/.//evil.example',
    '/a/..//evil.example',
    '/%5Cevil.example',
    '/%09/evil.example',
    '\uFF0F\uFF0Fevil.example',
    '/ ',
    '/',
    '/reset-password',
    '/forgot-password',
    '/_next/x',
    '/api/v1/users',
    '/nowhere',
    '/Admin',
    '',
    '/admin/../_next/x',
    '/admin/../login',
    '/admin/%2E./login',
    '/a/%2e%2e//evil.example',
    '/%5fnext/x',
    '/%E0%A4%A',
    '/admin%2f..%2f..%2f/evil',
    '/a\u0000b',
    'overview',
  ])('refuses %j', (raw) => expect(safeReturnPath(raw, opts)).toBe('/fallback'));

  it.each([null, undefined])('refuses %s', (raw) => expect(safeReturnPath(raw, opts)).toBe('/fallback'));

  it('refuses a refused page even when it is declared', () => {
    const listed = { declaredRoutes: ['login', '_next', 'api', 'bff', 'reset-password', 'forgot-password'], fallback: '/f' };
    for (const raw of ['/login', '/_next/x', '/api/x', '/bff/x', '/reset-password', '/forgot-password']) {
      expect(safeReturnPath(raw, listed)).toBe('/f');
    }
  });

  it("keeps / (and / normalised) only when '' is declared", () => {
    const withRoot = { declaredRoutes: [''], fallback: '/f' };
    expect(safeReturnPath('/', withRoot)).toBe('/');
    expect(safeReturnPath('/?x=1', withRoot)).toBe('/?x=1');
    expect(safeReturnPath('/', { declaredRoutes: ['admin'], fallback: '/f' })).toBe('/f');
    expect(safeReturnPath('/ ', { declaredRoutes: ['admin'], fallback: '/f' })).toBe('/f');
  });

  it.each(['/.//evil.example', '/a/..//evil.example', '/a/%2e%2e//evil.example', '//evil.example'])(
    "refuses %s even when '' (the start page) is declared, as the sign-in handler will",
    (raw) => expect(safeReturnPath(raw, { declaredRoutes: ['', 'admin', 'a'], fallback: '/' })).toBe('/'),
  );

  it('the output never starts with two slashes and never keeps a fragment', () => {
    const cases = ['/admin//evil.example', '/admin/x/..//..//evil', '/admin#//evil', '/admin?a=b#c'];
    for (const raw of cases) {
      const out = safeReturnPath(raw, opts);
      expect(out.startsWith('/')).toBe(true);
      expect(out.startsWith('//')).toBe(false);
      expect(out.includes('#')).toBe(false);
    }
  });
});

describe('initials', () => {
  it('takes the first letters of the first two words', () => {
    expect(initials('sample person name')).toBe('SP');
    expect(initials('  Solo ')).toBe('S');
  });
});
