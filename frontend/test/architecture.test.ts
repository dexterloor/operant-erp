import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import ts from 'typescript';
import { describe, expect, it } from 'vitest';

// Mechanical guards for docs/design/b4-architecture.md (Revision 4 > Revision 3 > Revision 2 FINAL > Revision 1 > Response
// to RED > RED H-5).
// Never weaken a guard to get green (FRONTEND-HANDOFF.md). Every guard is a pure function over {file, source} pairs,
// run once over the real tree (must find nothing) and once over a planted in-memory source (must go red), so a guard
// that silently matches nothing cannot pass. Scanned-file counts are pinned: a B-4 step that adds files updates
// EXPECTED_COUNTS deliberately, and a directory that does not exist yet is counted as 0, not skipped.
//
// Here: import direction (H-5 item 5 as amended by Revision 2 C-2, plus Revision 4 A-6's lib ↛ copy), the AST literal
// backstop (H-5 item 4 + C-2) and Revision 4 A-6's no-network rule for the component dirs.
// DOM fitness lives in test/dom-fitness.test.tsx (the binding tree's file). Step 5 adds no-clock expiry and no-identity
// Withdraw (RED H-5 items 6–7): a static backstop here (no clock read, no `proposed_by` comparison in the picker dirs) plus
// the behavioural checks, which render, are in test/roles/RoleProposals.test.tsx ("no clock" and "Withdraw for every viewer"). The `__FIXTURE_ONLY__` post-build check (L-2) is
// `scripts/check-fixtures-absent.mjs`, run by `postbuild` (it reads `.next/static`, which a unit test cannot).

const ROOT = path.resolve(__dirname, '..');

interface SourceFile {
  /** Path relative to the frontend root, POSIX separators, e.g. `src/lib/results.ts`. */
  file: string;
  source: string;
}

function walk(dirRel: string): SourceFile[] {
  const abs = path.join(ROOT, dirRel);
  if (!existsSync(abs)) return [];
  const out: SourceFile[] = [];
  for (const name of readdirSync(abs).sort()) {
    const childRel = `${dirRel}/${name}`;
    const childAbs = path.join(abs, name);
    if (statSync(childAbs).isDirectory()) out.push(...walk(childRel));
    else if (/\.(ts|tsx)$/.test(name) && !name.endsWith('.d.ts')) out.push({ file: childRel, source: readFileSync(childAbs, 'utf8') });
  }
  return out;
}

const parse = ({ file, source }: SourceFile) =>
  ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true, file.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS);

// ---------------------------------------------------------------------------------------------------------------------
// Import direction
// ---------------------------------------------------------------------------------------------------------------------

/** Every module specifier in a file: static imports, re-exports, `import()` and `require()`. */
function specifiersOf(sf: SourceFile): string[] {
  const tree = parse(sf);
  const found: string[] = [];
  const visit = (node: ts.Node) => {
    if ((ts.isImportDeclaration(node) || ts.isExportDeclaration(node)) && node.moduleSpecifier && ts.isStringLiteral(node.moduleSpecifier)) {
      found.push(node.moduleSpecifier.text);
    } else if (ts.isCallExpression(node) && node.arguments.length > 0) {
      const arg = node.arguments[0]!;
      const isImport = node.expression.kind === ts.SyntaxKind.ImportKeyword;
      const isRequire = ts.isIdentifier(node.expression) && node.expression.text === 'require';
      if ((isImport || isRequire) && ts.isStringLiteralLike(arg)) found.push(arg.text);
    }
    ts.forEachChild(node, visit);
  };
  visit(tree);
  return found;
}

/**
 * `@/x` → `src/x`; `./x` → resolved against the file; anything else is a package name, returned as `pkg:<name>`. Both
 * path forms are normalised, so `@/../test/x` is `test/x` and cannot slip past a rule (b4-final-red L-3).
 */
function targetOf(file: string, spec: string): string {
  if (spec.startsWith('@/')) return path.posix.normalize(`src/${spec.slice(2)}`);
  if (spec.startsWith('.')) return path.posix.normalize(path.posix.join(path.posix.dirname(file), spec));
  return `pkg:${spec}`;
}

const isFramework = (t: string) => /^pkg:(react|react-dom|next)(\/|$)/.test(t);
const under = (t: string, dir: string) => t === dir || t.startsWith(`${dir}/`);

interface Violation {
  file: string;
  detail: string;
}

/** The import rules. `files` is any set of {file, source}; returns every breach. */
function importViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    for (const spec of specifiersOf(sf)) {
      const t = targetOf(sf.file, spec);
      const breach = (rule: string) => out.push({ file: sf.file, detail: `${rule}: imports '${spec}'` });
      // CSS Modules are a component's own styles, never a layer edge.
      if (/\.css$/.test(spec)) continue;
      if (under(sf.file, 'src/lib/api')) breach('lib/api imports nothing');
      else if (under(sf.file, 'src/copy') && !under(t, 'src/lib/api')) breach('copy/* may import only from lib/api/*');
      if (under(sf.file, 'src/lib') && (isFramework(t) || under(t, 'src/components'))) breach('lib/** never imports react, next or components');
      if (under(sf.file, 'src/lib') && under(t, 'src/copy')) breach('lib/** never imports copy/** (Revision 4 A-6)');
      if (under(sf.file, 'src') && under(t, 'test')) breach('src never imports test/**');
      if (under(t, 'src/components/preview/fixtures') && !under(sf.file, 'src/components/preview')) {
        breach('only components/preview (and tests) import the preview fixtures (RED L-1)');
      }
    }
  }
  return out;
}

// ---------------------------------------------------------------------------------------------------------------------
// AST literal backstop
// ---------------------------------------------------------------------------------------------------------------------

/** H-5 item 4 + C-2: the B-4 logic and component dirs, plus `lib/api` (type positions exempt there). */
const LITERAL_SCAN_DIRS = [
  'src/lib/api',
  'src/lib/roles',
  'src/lib/permissions',
  'src/lib/bulk',
  'src/lib/people',
  'src/components/roles',
  'src/components/permissions',
  'src/components/bulk',
  'src/components/people',
] as const;

/** A served code shape: `user_role.separation_of_duty`, `invoice.view`. */
const CODE_SHAPE = /^[a-z_]+\.[a-z_]+$/;

/**
 * Built-in role names, read from the ONE fixture copy's header (RED H-5 item 4, L-1), never typed here (a typed list
 * would be another copy of the backend's catalogue). The header line is `BUILT_IN_ROLE_NAMES: owner, admin, …`. The
 * fixture folder arrives with step 2; until then the list is empty and the test below pins that the folder is absent,
 * so the role-name half cannot go quietly vacuous once fixtures exist.
 */
const FIXTURE_DIR = 'src/components/preview/fixtures';
function builtInRoleNames(): string[] {
  const names = new Set<string>();
  for (const sf of walk(FIXTURE_DIR)) {
    const m = /BUILT_IN_ROLE_NAMES:\s*([a-z_, ]+)/.exec(sf.source);
    if (m) for (const n of m[1]!.split(',')) if (n.trim()) names.add(n.trim());
  }
  return [...names];
}

function inTypePosition(node: ts.Node): boolean {
  for (let p: ts.Node | undefined = node.parent; p; p = p.parent) if (ts.isTypeNode(p)) return true;
  return false;
}

function inTopLevelDeclaration(node: ts.Node): boolean {
  for (let p: ts.Node | undefined = node.parent; p; p = p.parent) {
    if (ts.isVariableStatement(p) && ts.isSourceFile(p.parent)) return true;
    if (ts.isFunctionLike(p)) return false;
  }
  return false;
}

/** Every string literal / template text in the scanned files that looks like a served code or a built-in role name. */
function literalViolations(files: readonly SourceFile[], roleNames: readonly string[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    const inApi = under(sf.file, 'src/lib/api');
    const isRefusalHome = sf.file === 'src/lib/api/roles.ts';
    const visit = (node: ts.Node) => {
      let text: string | null = null;
      if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) text = node.text;
      else if (ts.isTemplateHead(node) || ts.isTemplateMiddle(node) || ts.isTemplateTail(node)) text = node.text;
      const isSpecifier = node.parent && (ts.isImportDeclaration(node.parent) || ts.isExportDeclaration(node.parent) || ts.isExternalModuleReference(node.parent));
      if (text !== null && !isSpecifier) {
        const exempt = (inApi && inTypePosition(node)) || (isRefusalHome && inTopLevelDeclaration(node));
        const hit = CODE_SHAPE.test(text) || roleNames.includes(text);
        if (hit && !exempt) out.push({ file: sf.file, detail: `literal '${text}'` });
      }
      ts.forEachChild(node, visit);
    };
    visit(parse(sf));
  }
  return out;
}

// ---------------------------------------------------------------------------------------------------------------------

/**
 * Files each guard reads today. Update deliberately when a step adds files; an unexpected count means a guard is
 * reading more or less than its author thought. B-4 dirs that do not exist yet are 0 here, not missing.
 */
const EXPECTED_COUNTS: Record<string, number> = {
  // Step 2 added: copy/{people,headings}.ts; lib/api/users.ts, lib/people/{results,unwired}.ts; components/people/
  // {PersonLifecycleActions,DeletePersonDialog,RestorePersonDialog,ArchivedPersonBanner}.tsx; preview/fixtures/people.ts.
  // Step 3 added: copy/bulk.ts; lib/bulk/{results,selection,unwired}.ts; components/bulk/{useBulkSelection.ts,
  // BulkHeaderCheckbox,BulkRowCheckbox,BulkBar,BulkArchiveDialog,BulkResultMessage}.tsx; preview/fixtures/bulk.ts.
  // Step 4 added: copy/{permissionLabels,sod}.ts; lib/api/{permissions,roles}.ts, lib/permissions/{checklist,results,
  // unwired}.ts; components/permissions/{PermissionChecklist,PermissionGroup}.tsx; preview/fixtures/permissions.ts.
  // Step 5 added: copy/roles.ts; lib/api/{envelope,proposals}.ts, lib/roles/{availability,conflictLines,effectivePreview,
  // intents,margin,results,save,statusLine,unwired}.ts; components/roles/{RolePicker,RoleOption,EffectivePermissions,
  // RoleProposals}.tsx; preview/fixtures/roles.ts.
  'src/copy': 8,
  'src/lib': 31,
  'src/components': 47,
  'src/lib/api': 5,
  'src/lib/roles': 9,
  'src/lib/permissions': 3,
  'src/lib/bulk': 3,
  'src/lib/people': 2,
  'src/components/roles': 4,
  'src/components/permissions': 2,
  'src/components/bulk': 6,
  'src/components/people': 4,
  'src/components/ui': 11,
  [FIXTURE_DIR]: 4,
};

describe('architecture — scanned file counts (no vacuous guard)', () => {
  it('each guard reads exactly the files expected at this step', () => {
    const got = Object.fromEntries(Object.keys(EXPECTED_COUNTS).map((d) => [d, walk(d).length]));
    expect(got).toEqual(EXPECTED_COUNTS);
    expect(walk('src').length).toBeGreaterThan(40);
  });
});

describe('architecture — import direction (RED H-5 item 5, Revision 2 C-2)', () => {
  it('the real tree has no breach', () => {
    expect(importViolations(walk('src'))).toEqual([]);
  });

  it('positive controls: each rule goes red on a planted import', () => {
    const planted: SourceFile[] = [
      { file: 'src/copy/roles.ts', source: "import { formatList } from '@/lib/format/lists';" },
      { file: 'src/copy/bulk.ts', source: "import { REFUSAL } from '@/lib/api/roles';\nimport type { X } from '../lib/api/users';" },
      { file: 'src/lib/api/users.ts', source: "import type { Refused } from '../results';" },
      { file: 'src/lib/roles/save.ts', source: "import { useState } from 'react';" },
      { file: 'src/lib/bulk/selection.ts', source: "export { x } from 'next/navigation';" },
      { file: 'src/lib/people/x.ts', source: "const m = await import('@/components/ui/Chip');" },
      { file: 'src/components/ui/Chip.tsx', source: "import { built } from '../../../test/fixtures/x';" },
      { file: 'src/components/roles/RolePicker.tsx', source: "import { ROLES } from '@/components/preview/fixtures/roles';" },
      { file: 'src/components/preview/ComponentPreview.tsx', source: "import { ROLES } from './fixtures/roles';\nimport styles from './x.module.css';" },
      { file: 'src/lib/roles/margin.ts', source: "import { PICKER } from '@/copy/roles';\nimport { PICKER_BANNERS } from '../../copy/roles';" },
      { file: 'src/components/ui/Banner.tsx', source: "import { x } from '@/../test/fixtures/x';" },
    ];
    const got = importViolations(planted).map((v) => `${v.file} — ${v.detail}`);
    expect(got).toEqual([
      "src/copy/roles.ts — copy/* may import only from lib/api/*: imports '@/lib/format/lists'",
      "src/lib/api/users.ts — lib/api imports nothing: imports '../results'",
      "src/lib/roles/save.ts — lib/** never imports react, next or components: imports 'react'",
      "src/lib/bulk/selection.ts — lib/** never imports react, next or components: imports 'next/navigation'",
      "src/lib/people/x.ts — lib/** never imports react, next or components: imports '@/components/ui/Chip'",
      "src/components/ui/Chip.tsx — src never imports test/**: imports '../../../test/fixtures/x'",
      "src/components/roles/RolePicker.tsx — only components/preview (and tests) import the preview fixtures (RED L-1): imports '@/components/preview/fixtures/roles'",
      "src/lib/roles/margin.ts — lib/** never imports copy/** (Revision 4 A-6): imports '@/copy/roles'",
      "src/lib/roles/margin.ts — lib/** never imports copy/** (Revision 4 A-6): imports '../../copy/roles'",
      "src/components/ui/Banner.tsx — src never imports test/**: imports '@/../test/fixtures/x'",
    ]);
  });
});

describe('architecture — AST literal backstop (RED H-5 item 4, Revision 2 C-2)', () => {
  const scanned = () => LITERAL_SCAN_DIRS.flatMap((d) => walk(d));

  it('the real B-4 dirs hold no served-code or role-name literal', () => {
    expect(literalViolations(scanned(), builtInRoleNames())).toEqual([]);
  });

  it('role names come from the fixture header, which now exists (step 2)', () => {
    expect(existsSync(path.join(ROOT, FIXTURE_DIR))).toBe(true);
    expect(builtInRoleNames().length).toBeGreaterThan(0);
  });

  it('every fixture file opens with the __FIXTURE_ONLY__ marker and the BUILT_IN_ROLE_NAMES header on line 1 (RED L-1, L-2)', () => {
    const files = walk(FIXTURE_DIR);
    expect(files.length).toBeGreaterThan(0);
    for (const sf of files) {
      const first = sf.source.split('\n')[0] ?? '';
      expect(first, sf.file).toContain("'__FIXTURE_ONLY__'");
      expect(first, sf.file).toMatch(/BUILT_IN_ROLE_NAMES:\s*[a-z_]+/);
    }
  });

  it('positive controls: code-shaped and role-name literals go red; only lib/api declarations and types are exempt', () => {
    const planted: SourceFile[] = [
      { file: 'src/lib/roles/availability.ts', source: "export const bad = (r: { rule: string }) => r.rule === 'user_role.separation_of_duty';" },
      { file: 'src/components/roles/RoleOption.tsx', source: 'export const t = `invoice.view`;\nexport const u = (x: string) => `${x}finance.view${x}`;' },
      { file: 'src/lib/bulk/selection.ts', source: "export const isOwner = (n: string) => n === 'owner';" },
      { file: 'src/lib/api/roles.ts', source: "export const REFUSAL = { separationOfDuty: 'user_role.separation_of_duty' } as const;\nexport type K = 'user_role.already_held';\nexport function f() { return 'user_role.leak'; }" },
      { file: 'src/lib/api/users.ts', source: "export type Code = 'invoice.view' | 'invoice.issue';\nexport const leak = 'invoice.pay';" },
      { file: 'src/lib/people/x.ts', source: "import y from 'a.b';\nexport const ok = 'Delete person';" },
    ];
    const got = literalViolations(planted, ['owner']).map((v) => `${v.file} — ${v.detail}`);
    expect(got).toEqual([
      "src/lib/roles/availability.ts — literal 'user_role.separation_of_duty'",
      "src/components/roles/RoleOption.tsx — literal 'invoice.view'",
      "src/components/roles/RoleOption.tsx — literal 'finance.view'",
      "src/lib/bulk/selection.ts — literal 'owner'",
      "src/lib/api/roles.ts — literal 'user_role.leak'",
      "src/lib/api/users.ts — literal 'invoice.pay'",
    ]);
  });

  it('documents its limit: a code built by concatenation evades it (caught only by the invariance test, C-2)', () => {
    const evasive: SourceFile[] = [{ file: 'src/lib/roles/x.ts', source: "export const c = 'user_role' + '.' + 'separation_of_duty';" }];
    expect(literalViolations(evasive, [])).toEqual([]);
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// RED H-5 items 6–7 (step 5): no clock in expiry, no identity comparison for Withdraw. Static backstop over the picker
// dirs; the behavioural checks render below.
// ---------------------------------------------------------------------------------------------------------------------

const PICKER_DIRS = ['src/lib/roles', 'src/components/roles'] as const;

/** A clock read (`Date.now()`, `new Date(…)`, `performance.now()`) or a comparison against `proposed_by` / `expires_at`. */
function clockAndIdentityViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    const visit = (node: ts.Node) => {
      if (ts.isNewExpression(node) && ts.isIdentifier(node.expression) && node.expression.text === 'Date') out.push({ file: sf.file, detail: 'new Date(…)' });
      if (ts.isPropertyAccessExpression(node) && ts.isIdentifier(node.expression) && ['Date', 'performance'].includes(node.expression.text) && node.name.text === 'now') {
        out.push({ file: sf.file, detail: `${node.expression.text}.now()` });
      }
      if (ts.isBinaryExpression(node) && [ts.SyntaxKind.EqualsEqualsEqualsToken, ts.SyntaxKind.ExclamationEqualsEqualsToken, ts.SyntaxKind.LessThanToken, ts.SyntaxKind.GreaterThanToken].includes(node.operatorToken.kind)) {
        const text = node.getText(parse(sf));
        if (/proposed_by|expires_at/.test(text)) out.push({ file: sf.file, detail: `compares ${text}` });
      }
      ts.forEachChild(node, visit);
    };
    visit(parse(sf));
  }
  return out;
}

describe('architecture — no clock in expiry, no identity for Withdraw (RED H-5 items 6–7, step 5)', () => {
  it('the picker dirs read no clock and compare no proposer / expiry', () => {
    expect(clockAndIdentityViolations(PICKER_DIRS.flatMap((d) => walk(d)))).toEqual([]);
  });

  it('positive controls: each shape goes red', () => {
    const planted: SourceFile[] = [
      { file: 'src/components/roles/RoleProposals.tsx', source: 'export const e = (p: { expires_at: string }) => new Date(p.expires_at) < new Date();' },
      { file: 'src/lib/roles/x.ts', source: 'export const t = Date.now();' },
      { file: 'src/components/roles/Y.tsx', source: 'export const mine = (p: { proposed_by: { id: string } }, v: string) => p.proposed_by.id === v;' },
    ];
    expect(clockAndIdentityViolations(planted).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      'src/components/roles/RoleProposals.tsx — compares new Date(p.expires_at) < new Date()',
      'src/components/roles/RoleProposals.tsx — new Date(…)',
      'src/components/roles/RoleProposals.tsx — new Date(…)',
      'src/lib/roles/x.ts — Date.now()',
      'src/components/roles/Y.tsx — compares p.proposed_by.id === v',
    ]);
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// Revision 4 A-6: no network or request-scoped server access in the component dirs (props in, callbacks out). Matched on
// the AST — identifier references, property and element access, import specifiers — never by substring, so a comment or
// a sentence that mentions fetch does not trip it. Hardened by b4-final-red L-D2: ANY reference to an unshadowed global
// `fetch`/`XMLHttpRequest`/`EventSource`/`WebSocket` counts (so `(fetch)()` and `const f = fetch` do), `self` joins
// `window`/`globalThis` (dot or string-literal bracket access), and `document['cookie']` counts with `document.cookie`.
// Limit, stated as the other guards state theirs: a computed key (`window[k]`) or a global reached through a value this
// file did not name (`Reflect.get`, an injected object) is not seen. `src/lib/**` stays open: B-3b chooses where its
// wired actions live.
// ---------------------------------------------------------------------------------------------------------------------

const NETWORK_SCAN_DIRS = ['src/components/ui', 'src/components/roles', 'src/components/permissions', 'src/components/bulk', 'src/components/people'] as const;
const GLOBAL_OBJECTS = ['window', 'globalThis', 'self'];
const NETWORK_GLOBALS = ['fetch', 'XMLHttpRequest', 'EventSource', 'WebSocket'];

/** The member name of `x.name` or `x['name']` (string literal only), else null. */
function memberName(node: ts.Node): string | null {
  if (ts.isPropertyAccessExpression(node)) return node.name.text;
  if (ts.isElementAccessExpression(node) && ts.isStringLiteralLike(node.argumentExpression)) return node.argumentExpression.text;
  return null;
}

/** `(x)` → `x`, any depth. */
function unwrap(expr: ts.Expression): ts.Expression {
  let e = expr;
  while (ts.isParenthesizedExpression(e)) e = e.expression;
  return e;
}

/** `window` / `globalThis` / `self`, parentheses unwrapped. */
function isGlobalObject(expr: ts.Expression): boolean {
  const e = unwrap(expr);
  return ts.isIdentifier(e) && GLOBAL_OBJECTS.includes(e.text);
}

/** `document`, or `window.document` / `self['document']` etc., parentheses unwrapped. */
function isDocument(expr: ts.Expression): boolean {
  const e = unwrap(expr);
  if (ts.isIdentifier(e)) return e.text === 'document';
  return (ts.isPropertyAccessExpression(e) || ts.isElementAccessExpression(e)) && memberName(e) === 'document' && isGlobalObject(e.expression);
}

/** Every name this file binds (variables, parameters, functions, classes, imports): such a name is not the global. */
function boundNames(tree: ts.SourceFile): Set<string> {
  const names = new Set<string>();
  const addBinding = (n: ts.BindingName) => {
    if (ts.isIdentifier(n)) names.add(n.text);
    else for (const el of n.elements) if (!ts.isOmittedExpression(el)) addBinding(el.name);
  };
  const visit = (node: ts.Node) => {
    if (ts.isVariableDeclaration(node) || ts.isParameter(node)) addBinding(node.name);
    else if ((ts.isFunctionDeclaration(node) || ts.isClassDeclaration(node) || ts.isFunctionExpression(node)) && node.name) names.add(node.name.text);
    else if (ts.isImportClause(node) && node.name) names.add(node.name.text);
    else if (ts.isImportSpecifier(node) || ts.isNamespaceImport(node)) names.add(node.name.text);
    ts.forEachChild(node, visit);
  };
  visit(tree);
  return names;
}

/** True when this identifier is a value reference (not a member name, a key, a declaration name or a type). */
function isValueReference(id: ts.Identifier): boolean {
  const p = id.parent;
  if (ts.isPropertyAccessExpression(p) && p.name === id) return false;
  if ((ts.isPropertyAssignment(p) || ts.isPropertySignature(p) || ts.isPropertyDeclaration(p) || ts.isMethodDeclaration(p) || ts.isMethodSignature(p)) && p.name === id) return false;
  if (ts.isJsxAttribute(p) || ts.isQualifiedName(p)) return false;
  return !inTypePosition(id);
}

function networkViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    for (const spec of specifiersOf(sf)) if (spec === 'next/headers' || spec.startsWith('next/headers/')) out.push({ file: sf.file, detail: `imports '${spec}'` });
    const tree = parse(sf);
    const local = boundNames(tree);
    const visit = (node: ts.Node) => {
      if (ts.isIdentifier(node) && NETWORK_GLOBALS.includes(node.text) && !local.has(node.text) && isValueReference(node)) {
        out.push({ file: sf.file, detail: `references ${node.text}` });
      }
      const member = memberName(node);
      if (member !== null && (ts.isPropertyAccessExpression(node) || ts.isElementAccessExpression(node))) {
        if (NETWORK_GLOBALS.includes(member) && isGlobalObject(node.expression)) out.push({ file: sf.file, detail: `references ${node.getText(tree)}` });
        if (member === 'cookie' && isDocument(node.expression)) out.push({ file: sf.file, detail: `references ${node.getText(tree)}` });
      }
      ts.forEachChild(node, visit);
    };
    visit(tree);
  }
  return out;
}

describe('architecture — no network in the component dirs (Revision 4 A-6)', () => {
  it('the real component dirs make no request and read no cookie or request header', () => {
    const files = NETWORK_SCAN_DIRS.flatMap((d) => walk(d));
    expect(files.length).toBe(NETWORK_SCAN_DIRS.reduce((n, d) => n + EXPECTED_COUNTS[d]!, 0));
    expect(networkViolations(files)).toEqual([]);
  });

  it('positive controls: each shape goes red; a comment, a sentence, another object\'s fetch or a local binding does not', () => {
    const planted: SourceFile[] = [
      { file: 'src/components/roles/RolePicker.tsx', source: "export const a = () => fetch('/api/x');" },
      { file: 'src/components/bulk/BulkBar.tsx', source: "export const b = () => window.fetch('/api/x');\nexport const c = () => globalThis.fetch('/api/x');" },
      { file: 'src/components/people/X.tsx', source: "export const d = new XMLHttpRequest();\nexport const e = new EventSource('/s');\nexport const f = new window.WebSocket('ws://x');" },
      { file: 'src/components/permissions/Y.tsx', source: 'export const g = document.cookie;' },
      { file: 'src/components/ui/Z.tsx', source: "import { cookies } from 'next/headers';\nexport const h = () => import('next/headers');" },
      // b4-final-red L-D2: the four escapes, plus self / bracket / parenthesised forms.
      { file: 'src/components/roles/RoleOption.tsx', source: "export const i = () => (fetch)('/x');\nconst f = fetch;\nexport const j = () => f('/x');" },
      { file: 'src/components/roles/Effective.tsx', source: "export const k = () => self.fetch('/x');\nexport const l = () => self['fetch']('/x');\nexport const m = () => (globalThis)['WebSocket'];" },
      { file: 'src/components/bulk/C.tsx', source: "export const n = document['cookie'];\nexport const o = window.document.cookie;" },
      { file: 'src/components/people/W.tsx', source: 'export const p = [EventSource];' },
      {
        file: 'src/components/ui/Ok.tsx',
        source:
          "// the fetch wrapper navigates; new WebSocket is not used\nexport const s = 'fetch(the list) document.cookie';\nexport const t = (api: { fetch: () => void }) => api.fetch();\nexport const u = (fetch: () => void) => fetch();\nexport type V = typeof WebSocket;\nexport const w = { fetch: 1 };",
      },
    ];
    expect(networkViolations(planted).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      'src/components/roles/RolePicker.tsx — references fetch',
      'src/components/bulk/BulkBar.tsx — references window.fetch',
      'src/components/bulk/BulkBar.tsx — references globalThis.fetch',
      'src/components/people/X.tsx — references XMLHttpRequest',
      'src/components/people/X.tsx — references EventSource',
      'src/components/people/X.tsx — references window.WebSocket',
      'src/components/permissions/Y.tsx — references document.cookie',
      "src/components/ui/Z.tsx — imports 'next/headers'",
      "src/components/ui/Z.tsx — imports 'next/headers'",
      'src/components/roles/RoleOption.tsx — references fetch',
      'src/components/roles/RoleOption.tsx — references fetch',
      'src/components/roles/Effective.tsx — references self.fetch',
      "src/components/roles/Effective.tsx — references self['fetch']",
      "src/components/roles/Effective.tsx — references (globalThis)['WebSocket']",
      "src/components/bulk/C.tsx — references document['cookie']",
      'src/components/bulk/C.tsx — references window.document.cookie',
      'src/components/people/W.tsx — references EventSource',
    ]);
  });
});
