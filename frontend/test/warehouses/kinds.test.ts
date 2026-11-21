// @vitest-environment node
import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { KIND_LABELS, kindLabel } from '@/copy/warehouses';
import { wireEnum } from '@/lib/bff/surface';

// Revision 7 A-21 (as narrowed by Revision 3 R3-9): the location kinds and the rule that the client never decides
// anything from a kind value.
//
// §2 step 4.5 (BK-19's consuming commit): the parity check reads the SERVED enum through the one contract reader
// (`wireEnum`, G-2), so the temporary list and the direct `contracts/openapi.json` read of step 4.4 (its X-1) are deleted.
// `KIND_LABELS`' keys are now the only frontend copy of the kinds, and this test holds them equal to the server's.
const SERVED_KINDS = wireEnum('StorageLocation', 'kind');
const CREATE_INPUT = 'StorageLocation.CreateStorageLocationInput';
const UPDATE_INPUT = 'StorageLocation.UpdateStorageLocationInput.jsonMergePatch';

const FRONTEND = path.resolve(__dirname, '../..');

/** The parity rule, over any label map (so the planted control runs the same code). */
const keysMatch = (labels: Readonly<Record<string, unknown>>, kinds: readonly string[]) => JSON.stringify(Object.keys(labels)) === JSON.stringify([...kinds]);

describe('A-21 — KIND_LABELS against the served kinds (wireEnum, from step 4.5)', () => {
  it('KIND_LABELS keys equal wireEnum("StorageLocation", "kind"), in served order', () => {
    expect(SERVED_KINDS.length).toBeGreaterThan(0);
    expect(Object.keys(KIND_LABELS)).toEqual(SERVED_KINDS);
  });

  it('planted control: an extra key, a missing key or a reordering is red', () => {
    expect(keysMatch(KIND_LABELS, SERVED_KINDS)).toBe(true);
    expect(keysMatch({ ...KIND_LABELS, pallet: { label: 'Pallet' } }, SERVED_KINDS)).toBe(false);
    const missing = Object.fromEntries(Object.entries(KIND_LABELS).filter(([k]) => k !== SERVED_KINDS[0]));
    expect(keysMatch(missing, SERVED_KINDS)).toBe(false);
    const reordered = Object.fromEntries([...Object.entries(KIND_LABELS)].reverse());
    expect(keysMatch(reordered, SERVED_KINDS)).toBe(false);
  });

  it('the two inputs the dialogs offer serve the same kinds, in the same order, as the read schema', () => {
    expect(wireEnum(CREATE_INPUT, 'kind')).toEqual(SERVED_KINDS);
    expect(wireEnum(UPDATE_INPUT, 'kind')).toEqual(SERVED_KINDS);
  });

  it('a property with no served enum throws (wireEnum reads only what is served)', () => {
    expect(() => wireEnum('Warehouse', 'code')).toThrow(/no served enum/);
  });

  it('a served value with no entry renders as sent (B-5); a known one renders its label', () => {
    expect(kindLabel(SERVED_KINDS[SERVED_KINDS.length - 1]!)).toBe(KIND_LABELS[SERVED_KINDS[SERVED_KINDS.length - 1]!]!.label);
    expect(kindLabel('pallet')).toBe('pallet');
  });
});

// THE KIND-LITERAL SCAN (A-21): no kind value appears as a string literal under src/app, src/components or
// src/lib/warehouses, so no screen can branch on one (pickability and the owner-only release are the server's, G-44).
// src/copy is outside the scanned dirs (it holds KIND_LABELS); src/components/preview/fixtures is excluded, as G-3 does.
// Stock ST-2 (b4-architecture.md Revision 12 R12-5 (e)): src/lib/stock is scanned too. Its one exemption is the G-47 row
// marker, whose PICKABLE_KINDS and 'quarantine' literals are owner-ruled under G-47's option (2) and held to the served enum
// by the kind pin (test/stock/marker.test.ts). The exemption is stale (red) once marker.ts is gone or holds no kind literal.
const SCANNED = ['src/app', 'src/components', 'src/lib/warehouses', 'src/lib/stock'];
const EXCLUDED = 'src/components/preview/fixtures/';
const EXEMPT: readonly string[] = ['src/lib/stock/marker.ts'];

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

const literal = new RegExp(`(['"\`])(${SERVED_KINDS.join('|')})\\1`);
/** RED L-4: a kind named through the label map (`KIND_LABELS.quarantine`, `KIND_LABELS['bin']`) decides from a kind with no quoted literal. A dynamic `KIND_LABELS[l.kind]` stays allowed. */
const viaLabels = new RegExp(`\\bKIND_LABELS\\s*(?:\\.\\s*|\\[\\s*['"\`])(${SERVED_KINDS.join('|')})\\b`);
/** Files in the scanned dirs (fixtures excluded) holding a kind value as a quoted literal. */
const kindLiteralViolations = (files: readonly SourceFile[]) =>
  files.filter((f) => SCANNED.some((d) => f.file.startsWith(`${d}/`)) && !f.file.startsWith(EXCLUDED) && !EXEMPT.includes(f.file) && (literal.test(f.source) || viaLabels.test(f.source))).map((f) => f.file);

describe('A-21 — the kind-literal scan (holds from step 4.4)', () => {
  it('the real tree holds no kind literal in the scanned dirs, and the scan reads files', () => {
    const files = SCANNED.flatMap((d) => walk(d));
    expect(files.length).toBeGreaterThan(100);
    expect(files.some((f) => f.file === 'src/app/(app)/warehouses/[id]/LocationsSection.tsx')).toBe(true);
    expect(files.some((f) => f.file === 'src/components/warehouses/LocationForm.tsx')).toBe(true);
    expect(files.some((f) => f.file === 'src/components/warehouses/AddLocationForm.tsx')).toBe(true);
    expect(files.some((f) => f.file === 'src/components/warehouses/KindField.tsx')).toBe(true);
    // Revision 8 R8-5 (items I-2): the select itself now lives in components/ui; the scan must read it too.
    expect(files.some((f) => f.file === 'src/components/ui/SelectField.tsx')).toBe(true);
    // R12-5 (e): the scan reads lib/stock (positive control), and the marker's exemption is not stale.
    expect(files.some((f) => f.file === 'src/lib/stock/levels.ts')).toBe(true);
    const marker = files.find((f) => f.file === 'src/lib/stock/marker.ts');
    expect(marker, 'src/lib/stock/marker.ts is gone: drop its exemption (R12-5 (e))').toBeDefined();
    expect(literal.test(marker!.source), 'src/lib/stock/marker.ts holds no kind literal: its exemption is stale (R12-5 (e))').toBe(true);
    expect(kindLiteralViolations(files)).toEqual([]);
  });

  it("R12-5 (e) planted controls: a kind literal in lib/stock/levels.ts is red; the exempt marker.ts is not", () => {
    expect(kindLiteralViolations([{ file: 'src/lib/stock/levels.ts', source: "const k = 'bin';" }])).toEqual(['src/lib/stock/levels.ts']);
    expect(kindLiteralViolations([{ file: 'src/lib/stock/marker.ts', source: "export const PICKABLE_KINDS = ['bin'];" }])).toEqual([]);
  });

  it('planted controls: just outside the fixtures folder is red; inside it is not; src/lib/warehouses is red; src/copy is not scanned', () => {
    const plant = (file: string, source = "if (l.kind === 'quarantine') show();"): SourceFile => ({ file, source });
    expect(kindLiteralViolations([plant('src/components/preview/fixturesX.ts')])).toEqual(['src/components/preview/fixturesX.ts']);
    expect(kindLiteralViolations([plant('src/components/preview/Preview.tsx', 'const k = "bin";')])).toEqual(['src/components/preview/Preview.tsx']);
    expect(kindLiteralViolations([plant('src/components/preview/fixtures/warehouses.ts')])).toEqual([]);
    expect(kindLiteralViolations([plant('src/lib/warehouses/x.ts', 'const k = `staging`;')])).toEqual(['src/lib/warehouses/x.ts']);
    expect(kindLiteralViolations([plant('src/app/(app)/warehouses/[id]/X.tsx', "kind !== 'zone'")])).toEqual(['src/app/(app)/warehouses/[id]/X.tsx']);
    expect(kindLiteralViolations([plant('src/copy/warehouses.ts')])).toEqual([]);
    // RED L-4: a kind named through the label map is red; a dynamic lookup by the served value is not.
    expect(kindLiteralViolations([plant('src/app/x.tsx', 'if (KIND_LABELS[k] === KIND_LABELS.quarantine) x();')])).toEqual(['src/app/x.tsx']);
    expect(kindLiteralViolations([plant('src/app/y.tsx', "const q = KIND_LABELS['bin'];")])).toEqual(['src/app/y.tsx']);
    expect(kindLiteralViolations([plant('src/app/z.tsx', 'const label = KIND_LABELS[l.kind]?.label;')])).toEqual([]);
    // A word inside a sentence is not a kind literal.
    expect(kindLiteralViolations([plant('src/components/x.tsx', "const s = 'Put it in the bin';")])).toEqual([]);
  });
});
