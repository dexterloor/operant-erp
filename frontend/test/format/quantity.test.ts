import { describe, expect, it } from 'vitest';
import { renderQuantity, renderSignedQuantity } from '@/lib/format/quantity';

// STATES.md QR-2 (trailing zeros trimmed, a whole number carries no point), QR-4 (zero is `0`, never blank) and QR-1's
// second implementation (items-ux-copy.md R1-18): `renderQuantity` must agree with `QuantityRendering::render()` on QR-2's
// table, verbatim — the rows below are that table's "Stored → Rendered" columns, in order.

describe('renderQuantity — QR-2 table, verbatim', () => {
  it.each([
    ['10.000000', '10'],
    ['1.500000', '1.5'],
    ['0.250000', '0.25'],
    ['8.250000', '8.25'],
    ['0.000000', '0'],
    ['1.000001', '1.000001'],
  ])('%s → %s', (stored, rendered) => {
    expect(renderQuantity(stored)).toBe(rendered);
  });
});

describe('renderQuantity — beyond the table', () => {
  it('keeps every digit of a 13 + 6 digit value (a double would alter it: string arithmetic only)', () => {
    expect(renderQuantity('1234567890123.123456')).toBe('1234567890123.123456');
    expect(renderQuantity('9999999999999.999999')).toBe('9999999999999.999999');
    // The control: the same value through a number does NOT survive, so this test can tell the two apart.
    expect(String(Number('1234567890123.123456'))).not.toBe('1234567890123.123456');
  });

  it('a negative (accepted by the writer today) keeps its sign and trims as any other value', () => {
    expect(renderQuantity('-0.500000')).toBe('-0.5');
    expect(renderQuantity('-3.000000')).toBe('-3');
  });

  it('never pads, rounds or groups: a value with no point is returned unchanged, trailing integer zeros kept', () => {
    expect(renderQuantity('10')).toBe('10');
    expect(renderQuantity('1000000.000000')).toBe('1000000');
    expect(renderQuantity('100.100000')).toBe('100.1');
  });

  // items-i1-red.md L-4: the backend's domain (1-13 integer digits, 1-6 fractional) and its signed-zero rule.
  it('agrees with QuantityRendering::render() outside the table: no signed zero, and the same domain', () => {
    expect(renderQuantity('-0.000000')).toBe('0');
    expect(renderQuantity('-0')).toBe('0');
    expect(renderQuantity('-00.000000')).toBe('00');
    expect(renderQuantity('1.0000000')).toBe('1.0000000');
    expect(renderQuantity('12345678901234')).toBe('12345678901234');
    expect(renderQuantity('12345678901234.000000')).toBe('12345678901234.000000');
  });

  it('renders, never validates: a string that is not a plain decimal comes back as served', () => {
    for (const odd of ['', 'abc', '1e3', '1,5', ' 2', '1.2.3', '.5']) expect(renderQuantity(odd)).toBe(odd);
  });
});

// Stock ST-1 (stock-plan.md §2.5, §6.3 as amended by stock-st1-red.md L-9; UX B-5, proposed QR-2a; Revision 11 R11-5): a
// movement's signed change. renderQuantity's digits with `+` for stock that arrived and U+2212 MINUS SIGN (never a
// hyphen-minus) for stock that left; it differs from renderQuantity only by the sign glyph. String work only.
describe('renderSignedQuantity — a movement’s change', () => {
  it.each([
    ['12.000000', '+12'],
    ['-2.500000', '\u22122.5'],
    ['-0.000001', '\u22120.000001'],
    ['1234567890123.123456', '+1234567890123.123456'],
    ['-1234567890123.123456', '\u22121234567890123.123456'],
    ['10.000000', '+10'],
    ['-0.500000', '\u22120.5'],
  ])('%s → %s', (served, rendered) => {
    expect(renderSignedQuantity(served)).toBe(rendered);
  });

  it('the minus is U+2212, never a hyphen-minus', () => {
    expect(renderSignedQuantity('-3.000000').codePointAt(0)).toBe(0x2212);
    expect(renderSignedQuantity('-3.000000')).not.toContain('-');
  });

  it('RED L-9: zero (which cannot be served) is 0 with no sign, as QR-4; a value outside the pattern comes back as served', () => {
    expect(renderSignedQuantity('0.000000')).toBe('0');
    expect(renderSignedQuantity('-0.000000')).toBe('0');
    expect(renderSignedQuantity('+5')).toBe('+5');
    expect(renderSignedQuantity('abc')).toBe('abc');
    expect(renderSignedQuantity('')).toBe('');
  });

  it('differs from renderQuantity only by the sign glyph', () => {
    for (const served of ['12.000000', '-2.500000', '0.250000', '-1234567890123.123456']) {
      const unsigned = renderQuantity(served);
      const signed = renderSignedQuantity(served);
      expect(signed.slice(1)).toBe(unsigned.replace(/^-/, ''));
    }
  });
});
