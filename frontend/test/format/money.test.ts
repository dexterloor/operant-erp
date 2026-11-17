import { describe, expect, it } from 'vitest';
import { MINOR_UNITS, renderMoney } from '@/lib/format/money';

// The money rule (owner D1 of 2026-10-23, `1250.00 EUR` / `250 JPY`, as answered for Q-3 on 2026-11-17:
// purchase-orders-ruling.md; purchase-orders-trim-red.md C-1, "unit test owed"): a lossless, all-or-nothing trim. Each
// currency has exactly two forms — D1's (the currency's own decimals) when every digit past its minor unit is 0, else
// the stored value exactly as served. Nothing is ever rounded.

describe('renderMoney — the all-or-nothing trim (trim RED C-1)', () => {
  it.each([
    ['12.5000', 'GBP', '12.50 GBP'],
    ['1250.0000', 'GBP', '1250.00 GBP'],
    ['13.0000', 'GBP', '13.00 GBP'],
    ['250.0000', 'JPY', '250 JPY'],
    ['4.5000', 'EUR', '4.50 EUR'],
    ['9.9000', 'USD', '9.90 USD'],
    ['0.0000', 'GBP', '0.00 GBP'],
    ['0.0000', 'JPY', '0 JPY'],
  ])('%s %s → %s (D1 form: every digit past the minor unit is 0)', (amount, code, shown) => {
    expect(renderMoney(amount, code)).toBe(shown);
  });

  it.each([
    ['0.1250', 'GBP'],
    ['0.3333', 'GBP'],
    ['12.5010', 'EUR'],
    ['250.5000', 'JPY'],
    ['250.0001', 'JPY'],
    ['123456789012345.1234', 'EUR'],
  ])('%s %s stays exactly as stored: a non-zero digit past the minor unit, never rounded, never trimmed part way', (amount, code) => {
    expect(renderMoney(amount, code)).toBe(`${amount} ${code}`);
  });

  it('the anti-vacuity row: a trimmable value really changes (a renderer that returns everything as served is red here)', () => {
    expect(renderMoney('12.5000', 'GBP')).not.toBe('12.5000 GBP');
  });

  it('keeps every digit of a 15 + 4 digit value (a double would alter it: string work only)', () => {
    expect(renderMoney('123456789012345.0000', 'EUR')).toBe('123456789012345.00 EUR');
    expect(renderMoney('999999999999999.9999', 'GBP')).toBe('999999999999999.9999 GBP');
    expect(String(Number('123456789012345.1234'))).not.toBe('123456789012345.1234');
  });

  it('a value with fewer decimals than the minor unit is padded with zeros (adds zeros, never changes the value)', () => {
    expect(renderMoney('1250', 'GBP')).toBe('1250.00 GBP');
    expect(renderMoney('12.5', 'GBP')).toBe('12.50 GBP');
    expect(renderMoney('1250', 'JPY')).toBe('1250 JPY');
  });

  it('a sign passes through; zero is never signed (renderQuantity\'s rule)', () => {
    expect(renderMoney('-12.5000', 'GBP')).toBe('-12.50 GBP');
    expect(renderMoney('-0.0000', 'GBP')).toBe('0.00 GBP');
    expect(renderMoney('-0.0000', 'JPY')).toBe('0 JPY');
    expect(renderMoney('-0.1250', 'GBP')).toBe('-0.1250 GBP');
  });

  it('fails closed: a code not in the table, or a string outside the pattern, is returned as served', () => {
    expect(renderMoney('12.5000', 'CHF')).toBe('12.5000 CHF');
    expect(renderMoney('12.5000', 'gbp')).toBe('12.5000 gbp');
    for (const odd of ['1e5', '+12.50', '12.', '.50', '12.50000', '1234567890123456.00', 'abc', '', ' 12.5000']) {
      expect(renderMoney(odd, 'GBP')).toBe(`${odd} GBP`);
    }
  });

  it('the table is exactly the four seeded currencies, EUR/GBP/USD 2 and JPY 0 (held to backend/migrations by the parity test)', () => {
    expect(MINOR_UNITS).toEqual({ EUR: 2, GBP: 2, USD: 2, JPY: 0 });
  });
});
