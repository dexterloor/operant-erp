import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { MINOR_UNITS } from '@/lib/format/money';

// The money table's anchor (purchase-orders-trim-red.md C-2; purchase-orders-ruling.md Q-3, 2026-11-17). `renderMoney`
// holds a four-row table of minor units because the backend serves none (BK-39, keyed to G-37's closing work). This
// test holds that table equal to the one place the backend writes currencies: the `INSERT INTO currency` of the first
// migration. It reads backend source, never a database (the G-51 parity test's precedent, which reads ../backend/ for a
// message). It is red, never skipped, when ../backend/migrations is not there.
//
// What it does: walk every migration; find every statement that writes the `currency` table under any write keyword
// (INSERT INTO, UPDATE, DELETE FROM, COPY, TRUNCATE, ALTER TABLE, MERGE INTO), allowing `public.` and a quoted name;
// require exactly one, the known insert; compare its (code, minor unit) triples with the table both ways.
//
// Limit stated (trim RED C-2, "cannot see a hosted database"): reference data is owner-maintained, and an owner's SQL in
// a hosted database is invisible to a source test. A new code there still fails closed (shown as stored); a changed minor
// unit for EUR, GBP, USD or JPY would not, but ISO 4217 has not changed these. That is BK-39's reason to exist.
// Also not seen (po-1-red.md L-4): a write whose table name is built at run time (a constant or `sprintf(…)`, a shape
// these migrations use for DDL, e.g. `Version20260907000200.php`), and a write through a DBAL helper
// (`$this->connection->insert('currency', …)`) rather than SQL text. A migration that writes `currency` either way passes
// this test; the review of that migration is what catches it.

/** Relative to `frontend/` (vitest's working directory), as the G-51 parity test reads `../backend/tests/…`. */
const MIGRATIONS_DIR = '../backend/migrations';

const WRITE = /\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM|COPY|TRUNCATE(?:\s+TABLE)?|ALTER\s+TABLE(?:\s+IF\s+EXISTS)?|MERGE\s+INTO)\s+(?:ONLY\s+)?(?:"?public"?\s*\.\s*)?"?currency"?(?![\w"])/gi;

export interface CurrencyWrite {
  readonly file: string;
  readonly keyword: string;
  /** The statement's text from its keyword to the end of the heredoc or the next semicolon. */
  readonly statement: string;
}

/** Every write to the `currency` table in the given migration sources. */
export function currencyWrites(files: readonly { file: string; source: string }[]): CurrencyWrite[] {
  const out: CurrencyWrite[] = [];
  for (const { file, source } of files) {
    for (const m of source.matchAll(WRITE)) {
      const rest = source.slice(m.index);
      const end = rest.search(/;|\n\s*SQL\b/);
      out.push({ file, keyword: m[1]!.toUpperCase().replace(/\s+/g, ' '), statement: end < 0 ? rest : rest.slice(0, end) });
    }
  }
  return out;
}

/** The (code, minor unit) pairs of an `INSERT INTO currency (code, name, minor_unit) VALUES (…), …` statement. */
export function seededMinorUnits(statement: string): Record<string, number> {
  if (!/^INSERT\s+INTO\s+(?:"?public"?\s*\.\s*)?"?currency"?\s*\(\s*code\s*,\s*name\s*,\s*minor_unit\s*\)\s*VALUES\b/i.test(statement)) {
    throw new Error(`the write to currency is not the known (code, name, minor_unit) VALUES insert: ${statement.slice(0, 120)}`);
  }
  const out: Record<string, number> = {};
  const values = statement.slice(statement.search(/\bVALUES\b/i) + 'VALUES'.length);
  const tuples = [...values.matchAll(/\(\s*'([A-Z]{3})'\s*,\s*'(?:[^']|'')*'\s*,\s*(\d+)\s*\)/g)];
  for (const t of tuples) out[t[1]!] = Number(t[2]);
  // Every parenthesised group must have been read as a triple: a row of another shape is not silently skipped.
  if (tuples.length !== (values.match(/\(/g) ?? []).length) throw new Error(`a row of the currency insert is not a (code, name, minor_unit) triple: ${values.trim().slice(0, 160)}`);
  return out;
}

/** Every migration's source. No existence check and no skip: a missing directory throws here, so the test is red, never skipped. */
function migrations(dir: string = MIGRATIONS_DIR): { file: string; source: string }[] {
  return readdirSync(dir)
    .filter((f) => f.endsWith('.php'))
    .sort()
    .map((f) => ({ file: f, source: readFileSync(join(dir, f), 'utf8') }));
}

describe('the money table equals the backend\'s one currency write (trim RED C-2)', () => {
  it('positive control: the walk reads every migration (more than 100) and finds the known insert', () => {
    const all = migrations();
    expect(all.length).toBeGreaterThan(100);
    expect(currencyWrites(all).some((w) => w.file === 'Version20260906000100.php' && w.keyword === 'INSERT INTO')).toBe(true);
  });

  it('exactly one statement writes the currency table, and it is the known insert', () => {
    const writes = currencyWrites(migrations());
    expect(
      writes.length,
      `a second write to currency exists: this is G-37's seed, BK-39's trigger; re-derive the table in lib/format/money.ts or serve the minor unit. Writes: ${writes.map((w) => `${w.file} ${w.keyword}`).join('; ')}`,
    ).toBe(1);
    expect(writes[0]!.keyword).toBe('INSERT INTO');
  });

  it('its codes and minor units equal the table, both ways', () => {
    const [only] = currencyWrites(migrations());
    expect(seededMinorUnits(only!.statement)).toEqual(MINOR_UNITS);
  });

  it('a missing migrations directory throws (red, never skipped)', () => {
    // The reader itself, pointed at a directory that is not there (po-1-red.md L-4: not Node's readdirSync on its own).
    expect(() => migrations('../backend/no-such-migrations-dir')).toThrow();
  });

  it('planted controls: every write keyword, public., quotes and INSERT … SELECT are found; currency_code is not', () => {
    const planted = [
      "UPDATE currency SET minor_unit = 3 WHERE code = 'EUR'",
      "DELETE FROM currency WHERE code = 'JPY'",
      'COPY currency (code, name, minor_unit) FROM stdin',
      'TRUNCATE TABLE currency',
      'ALTER TABLE currency ADD COLUMN symbol text',
      'MERGE INTO currency c USING x ON true',
      "INSERT INTO public.currency (code, name, minor_unit) VALUES ('BHD', 'Dinar', 3)",
      'INSERT INTO "currency" SELECT * FROM staging',
      'update "public"."currency" set name = name',
    ];
    for (const sql of planted) expect(currencyWrites([{ file: 'p.php', source: sql }]), sql).toHaveLength(1);
    const notWrites = ['ALTER TABLE supplier ADD COLUMN currency_code text', 'UPDATE purchase_order SET currency_code = 1', 'SELECT * FROM currency', 'INSERT INTO currency_rate VALUES (1)'];
    for (const sql of notWrites) expect(currencyWrites([{ file: 'p.php', source: sql }]), sql).toEqual([]);
  });

  it('planted controls: a changed minor unit or an extra seeded code is unequal to the table', () => {
    const changed = "INSERT INTO currency (code, name, minor_unit) VALUES ('EUR', 'Euro', 3), ('GBP', 'Pound Sterling', 2), ('USD', 'US Dollar', 2), ('JPY', 'Yen', 0)";
    expect(seededMinorUnits(changed)).not.toEqual(MINOR_UNITS);
    const extra = "INSERT INTO currency (code, name, minor_unit) VALUES ('EUR', 'Euro', 2), ('GBP', 'Pound Sterling', 2), ('USD', 'US Dollar', 2), ('JPY', 'Yen', 0), ('CHF', 'Swiss Franc', 2)";
    expect(seededMinorUnits(extra)).not.toEqual(MINOR_UNITS);
    expect(() => seededMinorUnits("INSERT INTO currency (code, name, minor_unit) VALUES ('EUR', 'Euro', 2), (SELECT 1)")).toThrow();
    expect(() => seededMinorUnits('INSERT INTO currency SELECT * FROM staging')).toThrow();
  });
});
