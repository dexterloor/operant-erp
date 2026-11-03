import { describe, expect, it } from 'vitest';
import { plural } from '@/lib/format/plural';

const changes = { one: (n: number) => `${n} change`, other: (n: number) => `${n} changes` };

describe('plural (Intl.PluralRules)', () => {
  it('1 change / {n} changes', () => {
    expect(plural(1, changes)).toBe('1 change');
    expect(plural(0, changes)).toBe('0 changes');
    expect(plural(2, changes)).toBe('2 changes');
    expect(plural(21, changes)).toBe('21 changes');
  });
});
