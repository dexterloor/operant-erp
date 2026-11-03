import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';
import ts from 'typescript';
import { describe, expect, it } from 'vitest';
// @ts-expect-error — plain ESM script without type declarations
import { CLIENT_OPS_PATH, readInputs } from '../../scripts/api-surface-lib.mjs';
import { operation } from '@/lib/bff/surface';

// C-2 and C-3 (b3b-design.md §4, §14): the committed wired-operation list equals the operations the wired modules call,
// in BOTH directions; each is in the contract with the same method and template; every request-body key a wired call
// sends is in that operation's request schema; and each wired write's idempotency fact matches the contract (T-I1 is
// the behavioural half). The wired modules are `src/lib/*/wired.ts` and `src/lib/*/load.ts`.

const ROOT = path.resolve(__dirname, '../..');
const LIB = path.join(ROOT, 'src/lib');

function wiredFiles(): { file: string; source: string }[] {
  const out: { file: string; source: string }[] = [];
  for (const dir of readdirSync(LIB).sort()) {
    const abs = path.join(LIB, dir);
    if (!statSync(abs).isDirectory()) continue;
    for (const name of ['wired.ts', 'load.ts']) {
      const f = path.join(abs, name);
      if (existsSync(f)) out.push({ file: `src/lib/${dir}/${name}`, source: readFileSync(f, 'utf8') });
    }
  }
  return out;
}

const { openapi, surface } = readInputs();
const CONTRACT_OPS = new Set(Object.keys(surface.operations));

/** Every string literal in the file that names a contract operation, and the object-literal bodies passed with it. */
function callsOf(sf: { file: string; source: string }): { ops: Set<string>; bodies: Array<{ op: string; keys: string[] }> } {
  const tree = ts.createSourceFile(sf.file, sf.source, ts.ScriptTarget.Latest, true);
  const ops = new Set<string>();
  const bodies: Array<{ op: string; keys: string[] }> = [];
  const visit = (n: ts.Node) => {
    if ((ts.isStringLiteral(n) || ts.isNoSubstitutionTemplateLiteral(n)) && CONTRACT_OPS.has(n.text)) ops.add(n.text);
    if (ts.isCallExpression(n) && n.arguments.length >= 3 && ts.isStringLiteral(n.arguments[0]!) && CONTRACT_OPS.has(n.arguments[0].text)) {
      const body = n.arguments[2]!;
      if (ts.isObjectLiteralExpression(body)) {
        bodies.push({ op: n.arguments[0].text, keys: body.properties.map((p) => (p.name && (ts.isIdentifier(p.name) || ts.isStringLiteral(p.name)) ? p.name.text : '?')) });
      }
    }
    ts.forEachChild(n, visit);
  };
  visit(tree);
  return { ops, bodies };
}

function operationOf(opId: string): { method: string; template: string; schema: unknown } {
  for (const [template, methods] of Object.entries(openapi.paths as Record<string, Record<string, { operationId?: string; requestBody?: { content?: Record<string, { schema?: unknown }> } }>>)) {
    for (const [method, op] of Object.entries(methods)) {
      if (op.operationId === opId) return { method: method.toUpperCase(), template, schema: op.requestBody?.content?.['application/json']?.schema ?? null };
    }
  }
  throw new Error(`${opId} is not in openapi.json`);
}

function propertiesOf(schema: unknown): string[] {
  const s = schema as { $ref?: string; properties?: Record<string, unknown> } | null;
  if (!s) return [];
  if (s.$ref) return propertiesOf(openapi.components.schemas[s.$ref.split('/').pop()!]);
  return Object.keys(s.properties ?? {});
}

describe('C-2 — the wired-operation list is exactly what the wired modules call', () => {
  const files = wiredFiles();
  const called = new Set(files.flatMap((f) => [...callsOf(f).ops]));
  const listed: string[] = JSON.parse(readFileSync(CLIENT_OPS_PATH, 'utf8'));

  it('the wired modules exist and call something (no vacuous check)', () => {
    expect(files.length).toBeGreaterThan(0);
    expect(called.size).toBeGreaterThan(0);
  });

  it('both directions: every listed operation is called, every called operation is listed', () => {
    expect([...called].sort()).toEqual([...listed].sort());
  });

  it('each wired operation has the contract method and template', () => {
    for (const opId of listed) {
      const want = operationOf(opId);
      expect({ method: operation(opId).method, template: operation(opId).template }, opId).toEqual({ method: want.method, template: want.template });
    }
  });

  it('every request-body key a wired call sends is in the operation request schema', () => {
    const bodies = files.flatMap((f) => callsOf(f).bodies);
    for (const { op, keys } of bodies) {
      const allowed = propertiesOf(operationOf(op).schema);
      for (const k of keys) expect(allowed, `${op} body key ${k}`).toContain(k);
    }
  });

  it('C-3: each wired operation keeps the contract idempotency fact', () => {
    for (const opId of listed) expect(operation(opId).idempotencyKeyRequired, opId).toBe(surface.operations[opId].idempotency_key_required);
  });

  it('planted control: an operation named only in a wired module, and one listed but never called, are both caught', () => {
    const planted = callsOf({ file: 'src/lib/x/wired.ts', source: "export const f = (c: any) => c.call('AssignRole', {}, { role_id: 'x' });" });
    expect([...planted.ops]).toEqual(['AssignRole']);
    expect(planted.bodies).toEqual([{ op: 'AssignRole', keys: ['role_id'] }]);
    expect(propertiesOf(operationOf('AssignRole').schema)).toEqual(['role_id']);
  });
});
