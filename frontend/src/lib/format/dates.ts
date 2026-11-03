// Dates as shown to a person — docs/ux/STATES.md DT-1…DT-4. Framework-free; the time zone is always a PARAMETER so the
// output is deterministic in tests. DT-2: the viewer's browser zone is the INTERIM rule (ROADMAP F-11, PO + DATA); it is
// the zone the browser reported at sign-in, sealed in the session as `tz` (or UTC), and the caller passes it — never
// this module guessing, and never read during render (rule M-4).
//
// Two locale traps, measured on Node 20 (RED L-3) and fixed here deterministically rather than by trusting the locale:
//  - `en-GB` short month prints "Sept" — DT-1 rules the three-letter month from a FIXED twelve-entry list ("Sep");
//  - `timeZoneName: 'longOffset'` prints "GMT+08:00" (and bare "GMT" at zero) — DT-3 rules "(UTC+08:00)".
// Only the numbers come from Intl (`formatToParts` in the given zone); the words and the offset label are ours.

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as const;

interface Parts {
  day: number;
  month: number; // 1–12
  year: string;
  hour: string; // 00–23
  minute: string;
  offset: string; // "+08:00", "-02:30", "+00:00"
}

const formatters = new Map<string, Intl.DateTimeFormat>();

function formatterFor(timeZone: string): Intl.DateTimeFormat {
  let f = formatters.get(timeZone);
  if (!f) {
    f = new Intl.DateTimeFormat('en-GB', {
      timeZone,
      year: 'numeric',
      month: 'numeric',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
      timeZoneName: 'longOffset',
    });
    formatters.set(timeZone, f);
  }
  return f;
}

function partsOf(instant: string, timeZone: string): Parts {
  const date = new Date(instant);
  if (Number.isNaN(date.getTime())) throw new RangeError(`Not an RFC 3339 instant: ${JSON.stringify(instant)}`);
  const got: Record<string, string> = {};
  for (const p of formatterFor(timeZone).formatToParts(date)) got[p.type] = p.value;
  const zone = got.timeZoneName ?? '';
  // "GMT" alone is offset zero; otherwise "GMT±hh:mm" (longOffset always pads to hh:mm).
  const offset = zone === 'GMT' ? '+00:00' : zone.replace(/^GMT/, '');
  if (!/^[+-]\d{2}:\d{2}$/.test(offset)) throw new RangeError(`Unexpected offset form: ${JSON.stringify(zone)}`);
  return {
    day: Number(got.day),
    month: Number(got.month),
    year: got.year ?? '',
    hour: got.hour ?? '',
    minute: got.minute ?? '',
    offset,
  };
}

function datePart(p: Parts): string {
  return `${p.day} ${MONTHS[p.month - 1]} ${p.year}`;
}

/** DT-1 date alone: `22 Oct 2026` (no leading zero on the day). */
export function formatDate(instant: string, timeZone: string): string {
  return datePart(partsOf(instant, timeZone));
}

/** DT-1/DT-3 date with its time: `22 Oct 2026, 14:05` (24-hour, no seconds). */
export function formatDateTime(instant: string, timeZone: string): string {
  const p = partsOf(instant, timeZone);
  return `${datePart(p)}, ${p.hour}:${p.minute}`;
}

/** DT-3 full instant with its offset, for the 1.4.13 tooltip: `22 Oct 2026, 14:05 (UTC+08:00)`. */
export function formatInstant(instant: string, timeZone: string): string {
  const p = partsOf(instant, timeZone);
  return `${datePart(p)}, ${p.hour}:${p.minute} (UTC${p.offset})`;
}
