import { describe, expect, it } from 'vitest';
import { formatDate, formatDateTime, formatInstant } from '@/lib/format/dates';

// STATES.md DT-1/DT-3. The zone is a parameter, so every assertion is deterministic whatever the machine's zone.
const MANILA = 'Asia/Manila'; // +08:00, no DST
describe('dates (DT-1, DT-3; RED L-3)', () => {
  it('DT-1 date: day without a leading zero, three-letter month, four-digit year', () => {
    expect(formatDate('2026-10-22T06:05:00Z', MANILA)).toBe('22 Oct 2026');
    expect(formatDate('2026-10-02T06:05:00Z', MANILA)).toBe('2 Oct 2026');
  });

  it('pins September as "Sep", never the en-GB locale\'s "Sept"', () => {
    expect(formatDate('2026-09-22T00:00:00Z', 'UTC')).toBe('22 Sep 2026');
    expect(formatDate('2026-09-22T00:00:00Z', 'UTC')).not.toContain('Sept');
  });

  it('every month comes from the fixed list', () => {
    const got = Array.from({ length: 12 }, (_, i) => formatDate(`2026-${String(i + 1).padStart(2, '0')}-15T12:00:00Z`, 'UTC').split(' ')[1]);
    expect(got).toEqual(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']);
  });

  it('DT-3 date with time: 24-hour, no seconds, in the given zone', () => {
    expect(formatDateTime('2026-10-22T06:05:59Z', MANILA)).toBe('22 Oct 2026, 14:05');
    expect(formatDateTime('2026-10-22T23:30:00Z', MANILA)).toBe('23 Oct 2026, 07:30');
    expect(formatDateTime('2026-10-22T00:00:00Z', 'UTC')).toBe('22 Oct 2026, 00:00');
  });

  it('full instant: "(UTC+08:00)", never "GMT"', () => {
    expect(formatInstant('2026-10-22T06:05:00Z', MANILA)).toBe('22 Oct 2026, 14:05 (UTC+08:00)');
    expect(formatInstant('2026-10-22T06:05:00Z', MANILA)).not.toContain('GMT');
  });

  it('offset zero reads UTC+00:00; negative and half-hour offsets keep their sign and minutes', () => {
    expect(formatInstant('2026-01-10T12:00:00Z', 'UTC')).toBe('10 Jan 2026, 12:00 (UTC+00:00)');
    expect(formatInstant('2026-01-10T12:00:00Z', 'Europe/London')).toBe('10 Jan 2026, 12:00 (UTC+00:00)');
    expect(formatInstant('2026-07-10T12:00:00Z', 'Europe/London')).toBe('10 Jul 2026, 13:00 (UTC+01:00)');
    expect(formatInstant('2026-01-10T12:00:00Z', 'America/St_Johns')).toBe('10 Jan 2026, 08:30 (UTC-03:30)');
    expect(formatInstant('2026-01-10T12:00:00Z', 'Asia/Kolkata')).toBe('10 Jan 2026, 17:30 (UTC+05:30)');
  });

  it('accepts a served offset form and refuses a non-instant', () => {
    expect(formatDateTime('2026-10-22T14:05:00+08:00', MANILA)).toBe('22 Oct 2026, 14:05');
    expect(() => formatDate('not a date', MANILA)).toThrow(RangeError);
  });
});
