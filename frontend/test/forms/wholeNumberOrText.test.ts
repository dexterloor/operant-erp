import { describe, expect, it } from 'vitest';
import { wholeNumberOrText } from '@/lib/forms/wholeNumberOrText';

// b4-architecture.md Revision 9 R9-2 (suppliers S-1; suppliers-red.md M-1, suppliers-final-red.md R-2): the one place a
// typed day count becomes a JSON number. A text of 1 to 15 ASCII digits is sent as that number; anything else is sent
// exactly as typed, so the server's own 422 sentence shows beside the field (no client range or format rule).

describe('wholeNumberOrText', () => {
  it('1 to 15 ASCII digits become that number', () => {
    expect(wholeNumberOrText('30')).toBe(30);
    expect(wholeNumberOrText('0')).toBe(0);
    expect(wholeNumberOrText('3650')).toBe(3650);
    expect(wholeNumberOrText('030')).toBe(30);
    expect(wholeNumberOrText('999999999999999')).toBe(999999999999999);
  });

  it('anything else is returned exactly as typed', () => {
    for (const typed of ['30.5', '-1', ' 30', '30 ', 'abc', '1234567890123456', '', '1e3', '+3', '٣٠', '3,0', '0x1f']) {
      expect(wholeNumberOrText(typed), JSON.stringify(typed)).toBe(typed);
    }
  });
});
