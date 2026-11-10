// @vitest-environment node
import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { UNIT_LABELS, unitLabel } from '@/copy/items';
import { wireEnum } from '@/lib/bff/surface';

// Items I-2 (items-i2-design.md Revision 1 — FINAL T-1; b4-architecture.md Revision 8 R8-5; BK-22): the units of measure
// are the SERVED enum, read through the one contract reader (`wireEnum`). `UNIT_LABELS`' keys are the only frontend copy
// of the codes, held equal to the server's here, and no screen names a unit code as a literal (so none can branch on
// one or offer a hand-written list).
const SERVED_UNITS = wireEnum('Item.CreateItemInput', 'unit_of_measure_code');

const FRONTEND = path.resolve(__dirname, '../..');

/** The parity rule, over any label map (so the planted control runs the same code). */
const keysMatch = (labels: Readonly<Record<string, unknown>>, units: readonly string[]) => JSON.stringify(Object.keys(labels)) === JSON.stringify([...units]);

describe('T-1 — UNIT_LABELS against the served units', () => {
  it('(a) UNIT_LABELS keys equal wireEnum("Item.CreateItemInput", "unit_of_measure_code"), in served order', () => {
    expect(SERVED_UNITS.length).toBeGreaterThan(0);
    expect(Object.keys(UNIT_LABELS)).toEqual(SERVED_UNITS);
  });

  it('(a) planted control: an extra key, a missing key or a reordering is red', () => {
    expect(keysMatch(UNIT_LABELS, SERVED_UNITS)).toBe(true);
    expect(keysMatch({ ...UNIT_LABELS, PAIR: { label: 'Pair (PAIR)' } }, SERVED_UNITS)).toBe(false);
    const missing = Object.fromEntries(Object.entries(UNIT_LABELS).filter(([k]) => k !== SERVED_UNITS[0]));
    expect(keysMatch(missing, SERVED_UNITS)).toBe(false);
    const reordered = Object.fromEntries([...Object.entries(UNIT_LABELS)].reverse());
    expect(keysMatch(reordered, SERVED_UNITS)).toBe(false);
  });

  it('(b) the read schema and the update input serve the same units, in the same order, as the create input', () => {
    expect(wireEnum('Item', 'unit_of_measure_code')).toEqual(SERVED_UNITS);
    expect(wireEnum('Item.UpdateItemInput.jsonMergePatch', 'unit_of_measure_code')).toEqual(SERVED_UNITS);
  });

  it('(c) a served code with no entry renders as sent; a known one renders its label', () => {
    expect(unitLabel('XX')).toBe('XX');
    const last = SERVED_UNITS[SERVED_UNITS.length - 1]!;
    expect(unitLabel(last)).toBe(UNIT_LABELS[last]!.label);
  });
});

// (d) THE UNIT-LITERAL SCAN: no unit code appears as a quoted literal, or as a named member of UNIT_LABELS, under the
// scanned dirs. src/copy is not scanned (it holds UNIT_LABELS); src/lib/format and src/lib/forms are out, as for the kind
// scan; src/components/preview/fixtures is excluded, as G-3 does. Comments are scanned too (comment-blind, as the kind
// scan is), so no unit code is written in a comment there either.
//
// Limits, stated (Final RED FR-L5; the kind scan shares them): neither arm sees
//  - optional chaining on the map (`UNIT_LABELS?.KG`);
//  - destructuring (`const { KG } = UNIT_LABELS`);
//  - an aliased import (`UNIT_LABELS as U` → `U.KG`);
//  - a positional pick from the served list (`units[2]`).
const SCANNED = ['src/app', 'src/components', 'src/lib/items'];
const EXCLUDED = 'src/components/preview/fixtures/';

interface SourceFile {
  file: string;
  source: string;
}

function walk(dir: string): SourceFile[] {
  const abs = path.join(FRONTEND, dir);
  let names: string[];
  try {
    names = readdirSync(abs).sort();
  } catch {
    return [];
  }
  return names.flatMap((n) => {
    const rel = `${dir}/${n}`;
    if (statSync(path.join(FRONTEND, rel)).isDirectory()) return walk(rel);
    return /\.(ts|tsx|mjs|js)$/.test(n) ? [{ file: rel, source: readFileSync(path.join(FRONTEND, rel), 'utf8') }] : [];
  });
}

const escape = (v: string) => v.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
/** Both arms, built from a value list (so a planted list runs the same matcher), each value regex-escaped. */
function matchersFor(values: readonly string[]) {
  const alt = values.map(escape).join('|');
  return {
    literal: new RegExp(`(['"\`])(${alt})\\1`),
    viaLabels: new RegExp(`\\bUNIT_LABELS\\s*(?:\\.\\s*|\\[\\s*['"\`])(${alt})\\b`),
  };
}
function unitLiteralViolations(files: readonly SourceFile[], values: readonly string[] = SERVED_UNITS) {
  const { literal, viaLabels } = matchersFor(values);
  return files.filter((f) => SCANNED.some((d) => f.file.startsWith(`${d}/`)) && !f.file.startsWith(EXCLUDED) && (literal.test(f.source) || viaLabels.test(f.source))).map((f) => f.file);
}

describe('T-1 (d) — the unit-literal scan', () => {
  it('the real tree holds no unit literal in the scanned dirs, and the scan reads the create form, its page and SelectField', () => {
    const files = SCANNED.flatMap((d) => walk(d));
    expect(files.length).toBeGreaterThan(100);
    for (const f of ['src/components/items/ItemCreateForm.tsx', 'src/app/(app)/items/new/NewItemPage.tsx', 'src/components/ui/SelectField.tsx', 'src/lib/items/wired.ts']) {
      expect(files.some((sf) => sf.file === f), f).toBe(true);
    }
    expect(unitLiteralViolations(files)).toEqual([]);
  });

  it('planted controls, red: a quoted code and a code named through the label map, in each scanned dir', () => {
    const plant = (file: string, source: string): SourceFile => ({ file, source });
    expect(unitLiteralViolations([plant('src/app/(app)/items/new/X.tsx', 'const u = "M";')])).toEqual(['src/app/(app)/items/new/X.tsx']);
    expect(unitLiteralViolations([plant('src/components/items/X.tsx', 'const u = `L`;')])).toEqual(['src/components/items/X.tsx']);
    expect(unitLiteralViolations([plant('src/lib/items/x.ts', "const units = ['EA', 'BOX'];")])).toEqual(['src/lib/items/x.ts']);
    expect(unitLiteralViolations([plant('src/app/x.tsx', 'const l = UNIT_LABELS.KG;')])).toEqual(['src/app/x.tsx']);
    expect(unitLiteralViolations([plant('src/app/y.tsx', "const l = UNIT_LABELS['EA'];")])).toEqual(['src/app/y.tsx']);
    expect(unitLiteralViolations([plant('src/components/preview/fixturesX.ts', 'const u = "KG";')])).toEqual(['src/components/preview/fixturesX.ts']);
  });

  it('planted controls, not red: a longer quoted word, a dynamic lookup, a longer member, a word holding a code, fixtures, src/copy', () => {
    const plant = (file: string, source: string): SourceFile => ({ file, source });
    expect(unitLiteralViolations([plant('src/components/a.tsx', '<Button size="ML" />')])).toEqual([]);
    expect(unitLiteralViolations([plant('src/components/b.tsx', 'const l = UNIT_LABELS[item.unit_of_measure_code]?.label;')])).toEqual([]);
    expect(unitLiteralViolations([plant('src/components/c.tsx', 'const l = UNIT_LABELS.Label;')])).toEqual([]);
    expect(unitLiteralViolations([plant('src/components/d.tsx', "const s = 'Measure it in Metres';")])).toEqual([]);
    expect(unitLiteralViolations([plant('src/components/preview/fixtures/items.ts', 'const u = "KG";')])).toEqual([]);
    expect(unitLiteralViolations([plant('src/copy/items.ts', 'const u = "KG";')])).toEqual([]);
  });

  it('the matcher escapes each value: with a value holding a regex metacharacter ("M."), "M." is red and "MX" is not', () => {
    const plant = (source: string): SourceFile => ({ file: 'src/app/z.tsx', source });
    expect(unitLiteralViolations([plant('const u = "M.";')], ['M.'])).toEqual(['src/app/z.tsx']);
    expect(unitLiteralViolations([plant('const u = "MX";')], ['M.'])).toEqual([]);
    expect(unitLiteralViolations([plant('const l = UNIT_LABELS.MX;')], ['M.'])).toEqual([]);
  });
});
