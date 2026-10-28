import { describe, expect, it } from 'vitest';
import { formatList } from '@/lib/format/lists';

describe('formatList (R4-1, RED L-4)', () => {
  it('joins as an en-GB sentence list, no serial comma', () => {
    expect(formatList([])).toBe('');
    expect(formatList(['Finance'])).toBe('Finance');
    expect(formatList(['Finance', 'Sales'])).toBe('Finance and Sales');
    expect(formatList(['Finance', 'Sales', 'Viewer'])).toBe('Finance, Sales and Viewer');
  });
});
