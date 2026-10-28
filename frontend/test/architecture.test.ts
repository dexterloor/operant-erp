import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import ts from 'typescript';
import { describe, expect, it } from 'vitest';
import { bulkMaxIds, bulkResources } from '@/lib/bff/surface';

// Mechanical guards for docs/design/b4-architecture.md (Revision 5 > Revision 4 > Revision 3 > Revision 2 FINAL > Revision 1 >
// Response to RED > RED H-5). Revision 5 A-8's G-2 and G-3 are at the end of this file; G-1 and G-4…G-7 arrive with B-3b C2/C3.
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
  // Revision 5 A-8 G-7 (B-3b C2): the pages and route handlers, and the BFF's own code.
  'src/app',
  'src/lib/bff',
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
const BFF_SERVER_DIR = 'src/lib/bff/server';
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
  'src/lib': 55,
  'src/components': 49,
  'src/lib/api': 5,
  'src/lib/roles': 11,
  'src/lib/permissions': 5,
  'src/lib/bulk': 3,
  'src/lib/people': 4,
  'src/components/roles': 4,
  'src/components/permissions': 2,
  'src/components/bulk': 6,
  'src/components/people': 4,
  'src/components/ui': 11,
  [FIXTURE_DIR]: 4,
  // B-3b C1 added: src/generated/api-surface.{server,client}.ts; lib/bff/surface.ts, lib/bff/server/surface.ts.
  'src/generated': 2,
  // B-3b C2 added: lib/bff/{loopback,transport,authCalls}.ts, lib/bff/server/{session,origin,apiClient,handlers}.ts,
  // lib/routes.ts; app/bff/session/{route,sign-out/route}.ts, app/(auth)/login/{page,LoginRoute}.tsx, app/(app)/page.tsx
  // (replacing app/page.tsx); components/shell/{StartPage,DocumentArrival}.tsx.
  // B-3b C3 added: lib/bff/{paths,problem,deadline,apiCall}.ts, lib/bff/server/apiProxy.ts; app/bff/api/[...path]/route.ts,
  // app/(app)/{layout,BffProvider,AppShell}.tsx, app/(app)/currentViewer.ts.
  // B-3b C8 added: lib/auth/wired.ts; app/bff/password-resets/{route,redemption/route}.ts.
  // B-3b C5 added: lib/bff/reads.ts, lib/bff/server/reads.ts, lib/people/{load,wired}.ts; app/(app)/useCommittedState.ts,
  // app/(app)/admin/users/{page,UsersList,PeopleStates}.tsx, app/(app)/admin/users/[id]/{page,PersonPage,not-found}.tsx.
  // B-3b C6 added: lib/roles/{load,wired}.ts.
  // B-3b C7 added: lib/permissions/{load,wired}.ts; app/(app)/admin/ArchivableList.tsx (the list both pages share),
  // app/(app)/admin/roles/{page,RolesList,RoleStates}.tsx, app/(app)/admin/roles/[id]/{page,RolePage,not-found}.tsx.
  'src/lib/bff': 16,
  'src/lib/bff/server': 7,
  'src/app': 35,
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

// ---------------------------------------------------------------------------------------------------------------------
// B-3b (b4-architecture.md Revision 5 A-8). G-2: the generated modules import nothing, and each is read by exactly one
// module — the server map by lib/bff/server/surface.ts, the client projection by lib/bff/surface.ts. Tests are held to
// the same rule (they go through the readers), so nothing else learns the generated shape. G-3: no TS/TSX string or
// numeric literal equal to a bulk resource key or to the bulk cap under the bulk-facing dirs; the keys and the cap are
// read from the generated surface, never typed here. Closes b4-architecture.md §5's "no bare 50/10000 in bulk code".
// ---------------------------------------------------------------------------------------------------------------------

const GENERATED_DIR = 'src/generated';
const GENERATED_READERS: Record<string, string> = {
  'src/generated/api-surface.server': 'src/lib/bff/server/surface.ts',
  'src/generated/api-surface.client': 'src/lib/bff/surface.ts',
};
const withoutExtension = (t: string) => t.replace(/\.(ts|tsx|js|mjs)$/, '');

function generatedImportViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    for (const spec of specifiersOf(sf)) {
      if (under(sf.file, GENERATED_DIR)) {
        out.push({ file: sf.file, detail: `generated modules import nothing: imports '${spec}'` });
        continue;
      }
      const t = withoutExtension(targetOf(sf.file, spec));
      if (!under(t, GENERATED_DIR)) continue;
      if (GENERATED_READERS[t] !== sf.file) out.push({ file: sf.file, detail: `only its one reader imports ${t}: imports '${spec}'` });
    }
  }
  return out;
}

describe('architecture — G-2: the generated surface is read through its two readers only (A-8)', () => {
  it('the real tree (src and test) has no breach, and both generated modules exist', () => {
    expect(walk(GENERATED_DIR).map((f) => f.file)).toEqual(['src/generated/api-surface.client.ts', 'src/generated/api-surface.server.ts']);
    expect(generatedImportViolations([...walk('src'), ...walk('test')])).toEqual([]);
  });

  it('planted controls: a generated module importing, a third reader, a test reading directly, a bad path form all go red', () => {
    const planted: SourceFile[] = [
      { file: 'src/generated/api-surface.client.ts', source: "import type { X } from '../lib/api/users';" },
      { file: 'src/components/bulk/BulkBar.tsx', source: "import { BULK } from '@/generated/api-surface.client';" },
      { file: 'src/lib/bff/surface.ts', source: "import { OPERATIONS } from '@/generated/api-surface.server';" },
      { file: 'src/lib/bff/server/apiClient.ts', source: "import { OPERATIONS } from './../../../generated/api-surface.server.ts';" },
      { file: 'test/contract/x.test.ts', source: "import { BULK } from '../../src/generated/api-surface.client';" },
      { file: 'src/lib/bff/server/surface.ts', source: "import { OPERATIONS } from '@/generated/api-surface.server';\nimport { BULK } from '@/generated/api-surface.client';" },
      { file: 'src/lib/bff/surface.ts', source: "import { BULK } from '@/generated/api-surface.client';" },
    ];
    expect(generatedImportViolations(planted).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      "src/generated/api-surface.client.ts — generated modules import nothing: imports '../lib/api/users'",
      "src/components/bulk/BulkBar.tsx — only its one reader imports src/generated/api-surface.client: imports '@/generated/api-surface.client'",
      "src/lib/bff/surface.ts — only its one reader imports src/generated/api-surface.server: imports '@/generated/api-surface.server'",
      "src/lib/bff/server/apiClient.ts — only its one reader imports src/generated/api-surface.server: imports './../../../generated/api-surface.server.ts'",
      "test/contract/x.test.ts — only its one reader imports src/generated/api-surface.client: imports '../../src/generated/api-surface.client'",
      "src/lib/bff/server/surface.ts — only its one reader imports src/generated/api-surface.client: imports '@/generated/api-surface.client'",
    ]);
  });
});

const BULK_LITERAL_DIRS = ['src/components', 'src/app', 'src/lib/bulk', 'src/lib/bff'] as const;
const BULK_LITERAL_EXCLUDED = [FIXTURE_DIR] as const;

function bulkLiteralViolations(files: readonly SourceFile[], keys: readonly string[], cap: number): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    if (!BULK_LITERAL_DIRS.some((d) => under(sf.file, d)) || BULK_LITERAL_EXCLUDED.some((d) => under(sf.file, d))) continue;
    const tree = parse(sf);
    const visit = (node: ts.Node) => {
      const isSpecifier = node.parent && (ts.isImportDeclaration(node.parent) || ts.isExportDeclaration(node.parent) || ts.isExternalModuleReference(node.parent));
      let text: string | null = null;
      if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) text = node.text;
      else if (ts.isTemplateHead(node) || ts.isTemplateMiddle(node) || ts.isTemplateTail(node)) text = node.text;
      if (text !== null && !isSpecifier && (keys.includes(text) || text === String(cap))) out.push({ file: sf.file, detail: `literal '${text}'` });
      if (ts.isNumericLiteral(node) && Number(node.text) === cap) out.push({ file: sf.file, detail: `number ${node.getText(tree)}` });
      ts.forEachChild(node, visit);
    };
    visit(tree);
  }
  return out;
}

describe('architecture — G-3: no typed bulk resource key or bulk cap (A-8)', () => {
  const keys = bulkResources();
  const cap = bulkMaxIds();

  it('reads a non-empty key set and a cap from the generated surface', () => {
    expect(keys.length).toBeGreaterThan(0);
    expect(cap).toBeGreaterThan(0);
  });

  it('the real bulk-facing dirs hold no such literal (preview fixtures excluded: dev-only, notFound() in production)', () => {
    const files = BULK_LITERAL_DIRS.flatMap((d) => walk(d));
    expect(files.length).toBeGreaterThan(50);
    expect(bulkLiteralViolations(files, keys, cap)).toEqual([]);
  });

  it('planted controls: keys and the cap in every scanned dir go red; the fixtures folder, other dirs and near misses do not', () => {
    const k = keys[0]!;
    const planted: SourceFile[] = [
      { file: 'src/components/preview/x.ts', source: `export const r = '${k}';` },
      { file: `${FIXTURE_DIR}/bulk.ts`, source: `export const r = '${k}';` },
      { file: 'src/app/x.tsx', source: `export const m = ${cap};` },
      { file: 'src/lib/bff/x.ts', source: `export const m = 0x${cap.toString(16)};\nexport const s = '${cap}';` },
      { file: 'src/lib/bulk/x.ts', source: `export const r = \`${k}\`;\nexport const t = (x: string) => \`\${x}${k}\${x}\`;` },
      { file: 'src/components/bulk/y.tsx', source: `export const m = ${cap}.0;` },
      { file: 'src/lib/roles/x.ts', source: `export const r = '${k}';` },
      { file: 'src/components/ui/z.tsx', source: `export const a = '${k}s';\nexport const b = ${cap + 0.5};\nimport y from '${k}';` },
    ];
    expect(bulkLiteralViolations(planted, keys, cap).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      `src/components/preview/x.ts — literal '${k}'`,
      `src/app/x.tsx — number ${cap}`,
      `src/lib/bff/x.ts — number 0x${cap.toString(16)}`,
      `src/lib/bff/x.ts — literal '${cap}'`,
      `src/lib/bulk/x.ts — literal '${k}'`,
      `src/lib/bulk/x.ts — literal '${k}'`,
      `src/components/bulk/y.tsx — number ${cap}.0`,
    ]);
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// B-3b C2 (b4-architecture.md Revision 5 A-8; b3b-design.md §14). G-4: every lib/bff/server module opens with
// `import 'server-only'`, and nothing reached by import from a 'use client' file reaches lib/bff/server. G-5: the BFF's
// environment variables are named only in lib/bff/server, and ERP_API_BASE_URL is never read there at module scope
// (`next build` loads every route module). G-6 / T-TK (b): no client-reachable module imports the Session type or tokenOf.
// T-TK (c): a server component under src/app passes session-derived props only as clientSessionView(session). G-7: the
// literal backstop also reads src/app and src/lib/bff. T-AL: the server-only test alias lives in the vitest config only.
// ---------------------------------------------------------------------------------------------------------------------


/** `import 'server-only'` is the module's first statement. */
function serverOnlyViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    if (!under(sf.file, BFF_SERVER_DIR)) continue;
    const first = parse(sf).statements[0];
    const ok = first && ts.isImportDeclaration(first) && !first.importClause && ts.isStringLiteral(first.moduleSpecifier) && first.moduleSpecifier.text === 'server-only';
    if (!ok) out.push({ file: sf.file, detail: "does not open with import 'server-only'" });
  }
  return out;
}

const isUseClient = (sf: SourceFile) => {
  const first = parse(sf).statements[0];
  return !!first && ts.isExpressionStatement(first) && ts.isStringLiteral(first.expression) && first.expression.text === 'use client';
};

/** Resolve an import target (`src/x/y`, no extension) to a file in `files`, as the bundler would. */
function resolveIn(byFile: Map<string, SourceFile>, target: string): string | null {
  for (const cand of [target, `${target}.ts`, `${target}.tsx`, `${target}/index.ts`, `${target}/index.tsx`]) if (byFile.has(cand)) return cand;
  return null;
}

/** For each 'use client' file, every file it reaches by import, with the first chain that reached it. */
function clientChains(files: readonly SourceFile[]): string[][] {
  const byFile = new Map(files.map((f) => [f.file, f]));
  const chains: string[][] = [];
  for (const start of files.filter(isUseClient)) {
    const queue: string[][] = [[start.file]];
    const seen = new Set([start.file]);
    while (queue.length) {
      const chain = queue.shift()!;
      const sf = byFile.get(chain[chain.length - 1]!)!;
      for (const spec of specifiersOf(sf)) {
        const t = targetOf(sf.file, spec);
        if (t.startsWith('pkg:')) continue;
        const hit = resolveIn(byFile, withoutExtension(t));
        if (!hit || seen.has(hit)) continue;
        seen.add(hit);
        chains.push([...chain, hit]);
        queue.push([...chain, hit]);
      }
    }
  }
  return chains;
}

/** Every file reachable from some 'use client' file. */
const clientReachable = (files: readonly SourceFile[]) => new Set(clientChains(files).map((c) => c[c.length - 1]!));

function clientReachesServerViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const chain of clientChains(files)) {
    const file = chain[chain.length - 1]!;
    if (under(file, BFF_SERVER_DIR)) out.push({ file: chain[0]!, detail: `reaches ${file} via ${chain.join(' → ')}` });
  }
  return out;
}

/** T-TK (b) / G-6: a client-reachable module (or a 'use client' file) imports `Session` or `tokenOf` from the session module. */
function sessionImportViolations(files: readonly SourceFile[]): Violation[] {
  const reach = clientReachable(files);
  const out: Violation[] = [];
  for (const sf of files) {
    if (!isUseClient(sf) && !reach.has(sf.file)) continue;
    for (const st of parse(sf).statements) {
      if (!(ts.isImportDeclaration(st) || ts.isExportDeclaration(st)) || !st.moduleSpecifier || !ts.isStringLiteral(st.moduleSpecifier)) continue;
      if (withoutExtension(targetOf(sf.file, st.moduleSpecifier.text)) !== `${BFF_SERVER_DIR}/session`) continue;
      const names: string[] = [];
      if (ts.isImportDeclaration(st)) {
        const b = st.importClause?.namedBindings;
        if (b && ts.isNamedImports(b)) for (const e of b.elements) names.push((e.propertyName ?? e.name).text);
        if (b && ts.isNamespaceImport(b)) names.push('*');
      } else if (st.exportClause && ts.isNamedExports(st.exportClause)) for (const e of st.exportClause.elements) names.push((e.propertyName ?? e.name).text);
      else names.push('*');
      for (const n of names) if (n === 'Session' || n === 'tokenOf' || n === '*') out.push({ file: sf.file, detail: `client-reachable import of ${n}` });
    }
  }
  return out;
}

const BFF_ENV = ['ERP_API_BASE_URL', 'BFF_SESSION_KEY', 'APP_PUBLIC_ORIGIN', 'BFF_CLIENT_IP_SOURCE', 'BFF_API_IP_KEY', 'BFF_API_IP_KEYS'];

const insideFunction = (node: ts.Node) => {
  for (let p: ts.Node | undefined = node.parent; p; p = p.parent) if (ts.isFunctionLike(p)) return true;
  return false;
};

/** G-5: the BFF's variables are named only in lib/bff/server; there, ERP_API_BASE_URL only inside a function body. */
function envViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    const inServer = under(sf.file, BFF_SERVER_DIR);
    const visit = (node: ts.Node) => {
      const text = ts.isIdentifier(node) || ts.isStringLiteralLike(node) ? node.text : null;
      if (text !== null && BFF_ENV.includes(text)) {
        if (!inServer) out.push({ file: sf.file, detail: `names ${text} outside ${BFF_SERVER_DIR}` });
        else if (text === 'ERP_API_BASE_URL' && !insideFunction(node)) out.push({ file: sf.file, detail: 'reads ERP_API_BASE_URL at module scope' });
      }
      ts.forEachChild(node, visit);
    };
    visit(parse(sf));
  }
  return out;
}

/**
 * T-TK (c): in a server file under src/app, an identifier bound to the session — `readSession(…)` / `unsealSession(…)`,
 * or the `session` member destructured from `currentViewer()` — may reach a JSX prop only as `clientSessionView(<it>)`,
 * or as `<it>.workspaceTyped`: the one named exception, the workspace name the person typed, which the top bar shows
 * (bff-auth-note.md §9, D14; deviation D-9, TO CONFIRM — SEC). `tokenOf(…)` never reaches JSX.
 */
function sessionPropViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    if (!under(sf.file, 'src/app') || isUseClient(sf)) continue;
    const tree = parse(sf);
    const bound = new Set<string>();
    const collect = (node: ts.Node) => {
      if (ts.isVariableDeclaration(node) && node.initializer && /\b(readSession|unsealSession|currentViewer)\s*\(/.test(node.initializer.getText(tree))) {
        if (ts.isIdentifier(node.name)) bound.add(node.name.text);
        else if (ts.isObjectBindingPattern(node.name)) {
          for (const el of node.name.elements) {
            const key = el.propertyName && ts.isIdentifier(el.propertyName) ? el.propertyName.text : ts.isIdentifier(el.name) ? el.name.text : '';
            if (key === 'session' && ts.isIdentifier(el.name)) bound.add(el.name.text);
          }
        }
      }
      ts.forEachChild(node, collect);
    };
    collect(tree);
    const mentions = (e: ts.Node): boolean => {
      let hit = false;
      const v = (n: ts.Node) => {
        if (ts.isIdentifier(n) && (bound.has(n.text) || n.text === 'tokenOf')) hit = true;
        ts.forEachChild(n, v);
      };
      v(e);
      return hit;
    };
    const isBound = (e: ts.Node) => ts.isIdentifier(e) && bound.has(e.text);
    const allowed = (e: ts.Expression) =>
      (ts.isCallExpression(e) && ts.isIdentifier(e.expression) && e.expression.text === 'clientSessionView' && e.arguments.length === 1 && isBound(e.arguments[0]!)) ||
      (ts.isPropertyAccessExpression(e) && isBound(e.expression) && e.name.text === 'workspaceTyped');
    const visit = (node: ts.Node) => {
      if (ts.isJsxAttribute(node) && node.initializer && ts.isJsxExpression(node.initializer) && node.initializer.expression) {
        const e = node.initializer.expression;
        if (mentions(e) && !allowed(e)) out.push({ file: sf.file, detail: `prop ${node.name.getText(tree)}={${e.getText(tree)}}` });
      }
      if (ts.isJsxSpreadAttribute(node) && mentions(node.expression) && !allowed(node.expression)) out.push({ file: sf.file, detail: `spread {...${node.expression.getText(tree)}}` });
      ts.forEachChild(node, visit);
    };
    visit(tree);
  }
  return out;
}

describe('architecture — G-4: lib/bff/server is server-only and unreachable from client code (A-8)', () => {
  it('the real tree: every lib/bff/server module opens with the import, and no client file reaches the folder', () => {
    expect(walk(BFF_SERVER_DIR).length).toBeGreaterThan(0);
    expect(serverOnlyViolations(walk('src'))).toEqual([]);
    expect(clientReachesServerViolations(walk('src'))).toEqual([]);
  });

  it('planted controls: a server module without the import, and a client file reaching the folder directly or through a hop, go red', () => {
    const planted: SourceFile[] = [
      { file: 'src/lib/bff/server/x.ts', source: "import { a } from './y';\nexport const b = a;" },
      { file: 'src/lib/bff/server/y.ts', source: "import 'server-only';\nexport const a = 1;" },
      { file: 'src/lib/bff/server/z.ts', source: "import type { S } from './y';\nimport 'server-only';" },
      { file: 'src/app/A.tsx', source: "'use client';\nimport { a } from '@/lib/bff/server/y';" },
      { file: 'src/app/B.tsx', source: "'use client';\nimport { h } from '../lib/hop';" },
      { file: 'src/lib/hop.ts', source: "export { a as h } from './bff/server/y';" },
      { file: 'src/app/C.tsx', source: "import { a } from '@/lib/bff/server/y';\nexport const c = a;" },
    ];
    expect(serverOnlyViolations(planted).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      "src/lib/bff/server/x.ts — does not open with import 'server-only'",
      "src/lib/bff/server/z.ts — does not open with import 'server-only'",
    ]);
    expect(clientReachesServerViolations(planted).map((v) => v.detail).sort()).toEqual([
      'reaches src/lib/bff/server/y.ts via src/app/A.tsx → src/lib/bff/server/y.ts',
      'reaches src/lib/bff/server/y.ts via src/app/B.tsx → src/lib/hop.ts → src/lib/bff/server/y.ts',
    ]);
  });
});

describe('architecture — G-5: the BFF environment is read only in lib/bff/server, the API base never at module scope (A-8)', () => {
  it('the real tree has no breach', () => {
    expect(envViolations(walk('src'))).toEqual([]);
  });

  it('planted controls: a module-scope base read in lib/bff/server and any mention outside it go red', () => {
    const planted: SourceFile[] = [
      { file: 'src/lib/bff/server/x.ts', source: 'export const base = process.env.ERP_API_BASE_URL;' },
      { file: 'src/lib/bff/server/y.ts', source: "const { ERP_API_BASE_URL } = process.env;\nexport const k = () => process.env['BFF_SESSION_KEY'];" },
      { file: 'src/lib/bff/server/ok.ts', source: 'export const base = () => process.env.ERP_API_BASE_URL;\nexport const o = process.env.APP_PUBLIC_ORIGIN;' },
      { file: 'src/lib/bff/transport.ts', source: "export const k = process.env['BFF_SESSION_KEY'];" },
      { file: 'src/app/page.tsx', source: 'export const f = () => process.env.BFF_CLIENT_IP_SOURCE;' },
    ];
    expect(envViolations(planted).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      'src/lib/bff/server/x.ts — reads ERP_API_BASE_URL at module scope',
      'src/lib/bff/server/y.ts — reads ERP_API_BASE_URL at module scope',
      'src/lib/bff/transport.ts — names BFF_SESSION_KEY outside src/lib/bff/server',
      'src/app/page.tsx — names BFF_CLIENT_IP_SOURCE outside src/lib/bff/server',
    ]);
  });
});

describe('architecture — G-6 / T-TK (b): no client-reachable import of Session or tokenOf (A-8)', () => {
  it('the real tree has no breach', () => {
    expect(sessionImportViolations(walk('src'))).toEqual([]);
  });

  it('planted controls: a client file importing tokenOf, a type-only Session import, and a reached helper re-exporting it go red', () => {
    const planted: SourceFile[] = [
      { file: 'src/app/A.tsx', source: "'use client';\nimport { tokenOf } from '@/lib/bff/server/session';" },
      { file: 'src/app/B.tsx', source: "'use client';\nimport type { Session } from '../lib/bff/server/session';\nimport { h } from '@/lib/hop';" },
      { file: 'src/lib/hop.ts', source: "export { tokenOf as h } from './bff/server/session';" },
      { file: 'src/lib/bff/server/session.ts', source: "import 'server-only';\nexport const tokenOf = 1;" },
      { file: 'src/app/page.tsx', source: "import { tokenOf } from '@/lib/bff/server/session';" },
    ];
    expect(sessionImportViolations(planted).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      'src/app/A.tsx — client-reachable import of tokenOf',
      'src/app/B.tsx — client-reachable import of Session',
      'src/lib/hop.ts — client-reachable import of tokenOf',
    ]);
  });
});

describe('architecture — T-TK (c): a server component passes the session only as clientSessionView(session)', () => {
  it('the real src/app server files have no breach', () => {
    expect(sessionPropViolations(walk('src/app'))).toEqual([]);
  });

  it('planted controls: the session, a session field, a spread and tokenOf go red; clientSessionView(session) does not', () => {
    const planted: SourceFile[] = [
      {
        file: 'src/app/(app)/layout.tsx',
        source: [
          'export default async function L() {',
          '  const session = await readSession(v);',
          '  return (<>',
          '    <P a={session} />',
          '    <P b={session.tz} />',
          '    <P {...session} />',
          '    <P c={tokenOf(other)} />',
          '    <P ok={clientSessionView(session)} />',
          '    <P {...clientSessionView(session)} />',
          '  </>);',
          '}',
        ].join('\n'),
      },
    ];
    expect(sessionPropViolations(planted).map((v) => v.detail)).toEqual(['prop a={session}', 'prop b={session.tz}', 'spread {...session}', 'prop c={tokenOf(other)}']);
    const destructured: SourceFile[] = [
      {
        file: 'src/app/(app)/layout.tsx',
        source: [
          'export default async function L() {',
          '  const { session: s, viewer } = await currentViewer();',
          '  return (<>',
          '    <P a={s} />',
          '    <P b={{ x: s.sid }} />',
          '    <P w={s.workspaceTyped} />',
          '    <P v={viewer} />',
          '    <P ok={clientSessionView(s)} />',
          '  </>);',
          '}',
        ].join('\n'),
      },
    ];
    expect(sessionPropViolations(destructured).map((v) => v.detail)).toEqual(['prop a={s}', 'prop b={{ x: s.sid }}']);
  });
});

describe('architecture — G-7: the literal backstop reads src/app and src/lib/bff (A-8)', () => {
  it('both are in LITERAL_SCAN_DIRS, and their real files hold no served-code or role-name literal', () => {
    expect(LITERAL_SCAN_DIRS).toEqual(expect.arrayContaining(['src/app', 'src/lib/bff']));
    expect(literalViolations([...walk('src/app'), ...walk('src/lib/bff')], builtInRoleNames())).toEqual([]);
  });

  it("planted controls: 'user_role.x' in src/app and 'owner' in src/lib/bff go red", () => {
    const planted: SourceFile[] = [
      { file: 'src/app/x.tsx', source: "export const c = 'user_role.x';" },
      { file: 'src/lib/bff/x.ts', source: "export const r = 'owner';" },
    ];
    expect(literalViolations(planted, builtInRoleNames()).map((v) => `${v.file} — ${v.detail}`)).toEqual(["src/app/x.tsx — literal 'user_role.x'", "src/lib/bff/x.ts — literal 'owner'"]);
  });
});

describe('architecture — T-AL: the server-only alias is a test-only setting', () => {
  it('vitest aliases server-only to its empty module; next.config.ts and src do not', () => {
    const vitest = readFileSync(path.join(ROOT, 'vitest.config.mts'), 'utf8');
    expect(vitest).toMatch(/'server-only':\s*'next\/dist\/compiled\/server-only\/empty\.js'/);
    expect(readFileSync(path.join(ROOT, 'next.config.ts'), 'utf8')).not.toMatch(/server-only/);
    for (const sf of walk('src')) expect(sf.source, sf.file).not.toMatch(/compiled\/server-only/);
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// B-3b C3 (A-8 G-1): network access only in the two fetch modules — the browser's in lib/bff/transport.ts, the server's in
// lib/bff/server/apiClient.ts — over the whole of src, and no Node HTTP client imported anywhere else.
// ---------------------------------------------------------------------------------------------------------------------

const FETCH_HOMES = ['src/lib/bff/transport.ts', 'src/lib/bff/server/apiClient.ts'];
const HTTP_PACKAGES = ['undici', 'http', 'https', 'node:http', 'node:https'];

function fetchHomeViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    if (FETCH_HOMES.includes(sf.file)) continue;
    for (const spec of specifiersOf(sf)) if (HTTP_PACKAGES.some((p) => spec === p || spec.startsWith(`${p}/`))) out.push({ file: sf.file, detail: `imports '${spec}'` });
    const tree = parse(sf);
    const local = boundNames(tree);
    const visit = (node: ts.Node) => {
      if (ts.isIdentifier(node) && NETWORK_GLOBALS.includes(node.text) && !local.has(node.text) && isValueReference(node)) out.push({ file: sf.file, detail: `references ${node.text}` });
      const member = memberName(node);
      if (member !== null && (ts.isPropertyAccessExpression(node) || ts.isElementAccessExpression(node)) && NETWORK_GLOBALS.includes(member) && isGlobalObject(node.expression)) {
        out.push({ file: sf.file, detail: `references ${node.getText(tree)}` });
      }
      ts.forEachChild(node, visit);
    };
    visit(tree);
  }
  return out;
}

describe('architecture — G-1: network access only in the two fetch modules (A-8)', () => {
  it('the real tree: both homes exist and use fetch; nothing else does', () => {
    for (const f of FETCH_HOMES) expect(readFileSync(path.join(ROOT, f), 'utf8')).toMatch(/\bfetch\(/);
    expect(fetchHomeViolations(walk('src'))).toEqual([]);
  });

  it('planted controls: fetch in a wired module, an RSC, a client route; XMLHttpRequest; node:http; undici — all red; the homes are not', () => {
    const planted: SourceFile[] = [
      { file: 'src/lib/people/wired.ts', source: "export const a = () => fetch('/bff/api/users');" },
      { file: 'src/app/(app)/page.tsx', source: "export default async function P() { await globalThis.fetch('http://x'); }" },
      { file: 'src/app/(auth)/login/LoginRoute.tsx', source: "'use client';\nexport const x = new XMLHttpRequest();" },
      { file: 'src/lib/bff/apiCall.ts', source: "import { request } from 'node:http';\nimport { Pool } from 'undici';" },
      { file: 'src/lib/bff/transport.ts', source: "export const t = () => fetch('/bff/x');" },
      { file: 'src/lib/bff/server/apiClient.ts', source: "export const t = () => fetch('http://127.0.0.1');" },
    ];
    expect(fetchHomeViolations(planted).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      'src/lib/people/wired.ts — references fetch',
      'src/app/(app)/page.tsx — references globalThis.fetch',
      'src/app/(auth)/login/LoginRoute.tsx — references XMLHttpRequest',
      "src/lib/bff/apiCall.ts — imports 'node:http'",
      "src/lib/bff/apiCall.ts — imports 'undici'",
    ]);
  });
});
