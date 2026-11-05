// @vitest-environment node
import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { KIND_LABELS, kindLabel } from '@/copy/warehouses';

// Revision 7 A-21 (as narrowed by Revision 3 R3-9), from §2 step 4.4: the location kinds' two copies and the rule that
// the client never decides anything from a kind value.
//
// THE TEMPORARY LIST. Until the frontend reads the served enum (`wireEnum('StorageLocation', 'kind')`, step 4.5), the five
// values live twice: `KIND_LABELS`' keys in `src/copy/warehouses.ts`, and this list. The test holds them equal, in order.
// BK-19 has already landed (the contract serves `StorageLocation.kind` as an enum), so this test ALSO holds the temporary
// list equal to the served enum read straight from `contracts/openapi.json` — a test-side read only: `src/` still has one
// reader of the contract (G-2), and the `WIRE_ENUMS` emitter arrives at step 4.5 with the switch that deletes this list.
const TEMPORARY_KINDS = ['bin', 'shelf', 'zone', 'staging', 'quarantine'] as const;

const FRONTEND = path.resolve(__dirname, '../..');
const OPENAPI = path.resolve(FRONTEND, '../contracts/openapi.json');

/** The parity rule, over any label map (so the planted control runs the same code). */
const keysMatch = (labels: Readonly<Record<string, unknown>>, kinds: readonly string[]) => JSON.stringify(Object.keys(labels)) === JSON.stringify([...kinds]);

describe('A-21 — KIND_LABELS against the kinds list (from step 4.4)', () => {
  it('KIND_LABELS keys equal the temporary list, in order', () => {
    expect(Object.keys(KIND_LABELS)).toEqual([...TEMPORARY_KINDS]);
  });

  it('planted control: an extra key, a missing key or a reordering is red', () => {
    expect(keysMatch(KIND_LABELS, TEMPORARY_KINDS)).toBe(true);
    expect(keysMatch({ ...KIND_LABELS, pallet: { label: 'Pallet' } }, TEMPORARY_KINDS)).toBe(false);
    const missing = Object.fromEntries(Object.entries(KIND_LABELS).filter(([k]) => k !== 'bin'));
    expect(keysMatch(missing, TEMPORARY_KINDS)).toBe(false);
    expect(keysMatch({ shelf: {}, bin: {}, zone: {}, staging: {}, quarantine: {} }, TEMPORARY_KINDS)).toBe(false);
  });

  it('the temporary list equals the served StorageLocation.kind enum, in served order (BK-19 has landed)', () => {
    const spec = JSON.parse(readFileSync(OPENAPI, 'utf8')) as { components: { schemas: Record<string, { properties?: Record<string, { enum?: string[] }> }> } };
    const served = spec.components.schemas.StorageLocation?.properties?.kind?.enum;
    expect(served).toEqual([...TEMPORARY_KINDS]);
  });

  it('a served value with no entry renders as sent (B-5); a known one renders its label', () => {
    expect(kindLabel('quarantine')).toBe('Quarantine');
    expect(kindLabel('pallet')).toBe('pallet');
  });
});

// THE KIND-LITERAL SCAN (A-21): no kind value appears as a string literal under src/app, src/components or
// src/lib/warehouses, so no screen can branch on one (pickability and the owner-only release are the server's, G-44).
// src/copy is outside the scanned dirs (it holds KIND_LABELS); src/components/preview/fixtures is excluded, as G-3 does.
const SCANNED = ['src/app', 'src/components', 'src/lib/warehouses'];
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

const literal = new RegExp(`(['"\`])(${TEMPORARY_KINDS.join('|')})\\1`);
/** RED L-4: a kind named through the label map (`KIND_LABELS.quarantine`, `KIND_LABELS['bin']`) decides from a kind with no quoted literal. A dynamic `KIND_LABELS[l.kind]` stays allowed. */
const viaLabels = new RegExp(`\\bKIND_LABELS\\s*(?:\\.\\s*|\\[\\s*['"\`])(${TEMPORARY_KINDS.join('|')})\\b`);
/** Files in the scanned dirs (fixtures excluded) holding a kind value as a quoted literal. */
const kindLiteralViolations = (files: readonly SourceFile[]) =>
  files.filter((f) => SCANNED.some((d) => f.file.startsWith(`${d}/`)) && !f.file.startsWith(EXCLUDED) && (literal.test(f.source) || viaLabels.test(f.source))).map((f) => f.file);

describe('A-21 — the kind-literal scan (holds from step 4.4)', () => {
  it('the real tree holds no kind literal in the scanned dirs, and the scan reads files', () => {
    const files = SCANNED.flatMap((d) => walk(d));
    expect(files.length).toBeGreaterThan(100);
    expect(files.some((f) => f.file === 'src/app/(app)/warehouses/[id]/LocationsSection.tsx')).toBe(true);
    expect(files.some((f) => f.file === 'src/components/warehouses/LocationForm.tsx')).toBe(true);
    expect(kindLiteralViolations(files)).toEqual([]);
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
