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

describe('L-7 return path after session ended', () => {
  it.each([
    ['/purchase-orders/42?status=open#x', '/purchase-orders/42?status=open#x'],
    ['/overview', '/overview'],
  ])('keeps same-origin relative path %s', (raw, want) => expect(safeReturnPath(raw)).toBe(want));

  it.each(['https://evil.example/', '//evil.example/x', '/\\evil.example', 'javascript:alert(1)', '', 'overview', '/login', '/login?next=/x', '/a\u0000b', null, undefined])(
    'refuses %s',
    (raw) => expect(safeReturnPath(raw as string)).toBe('/overview'),
  );
});

describe('initials', () => {
  it('takes the first letters of the first two words', () => {
    expect(initials('sample person name')).toBe('SP');
    expect(initials('  Solo ')).toBe('S');
  });
});
