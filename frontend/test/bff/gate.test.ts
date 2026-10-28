import { describe, expect, it } from 'vitest';
import { gateAllows, gateCode, operation, viewerMay, type SurfacePermission } from '@/lib/bff/surface';

// The gate helper (b3b-design.md §9 "The gate helper", final RED L-2): read from the contract, never typed.

describe('viewerMay / gateCode', () => {
  const code = (op: string) => (operation(op).permission as { codes: readonly string[] }).codes[0]!;

  it('all_of: every code held → true; one missing → false; a Set or an array', () => {
    expect(viewerMay('GetUser', [code('GetUser')])).toBe(true);
    expect(viewerMay('GetUser', new Set([code('GetUser')]))).toBe(true);
    expect(viewerMay('GetUser', [])).toBe(false);
    expect(viewerMay('DeleteUser', [code('GetUser')])).toBe(false);
  });

  it('derived and unknown kinds are refused; none is open; all_of with two codes needs both', () => {
    expect(gateAllows({ kind: 'none' }, [])).toBe(true);
    expect(gateAllows({ kind: 'derived' }, ['a.b'])).toBe(false);
    expect(gateAllows({ kind: 'other' } as unknown as SurfacePermission, ['a.b'])).toBe(false);
    expect(gateAllows({ kind: 'all_of', codes: ['a.b', 'c.d'] }, ['a.b'])).toBe(false);
    expect(gateAllows({ kind: 'all_of', codes: ['a.b', 'c.d'] }, ['c.d', 'a.b'])).toBe(true);
    expect(gateCode('GetUser')).toBe(code('GetUser'));
  });

  it('an operation that is not wired throws (a programming error), never answers false', () => {
    expect(() => viewerMay('NoSuchOperation', [])).toThrow(/not wired/);
  });
});
