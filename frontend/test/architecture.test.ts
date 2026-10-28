import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import ts from 'typescript';
import { describe, expect, it } from 'vitest';

// Mechanical guards for docs/design/b4-architecture.md (Revision 2 FINAL > Revision 1 > Response to RED > RED H-5).
// Never weaken a guard to get green (FRONTEND-HANDOFF.md). Every guard is a pure function over {file, source} pairs,
// run once over the real tree (must find nothing) and once over a planted in-memory source (must go red), so a guard
// that silently matches nothing cannot pass. Scanned-file counts are pinned: a B-4 step that adds files updates
// EXPECTED_COUNTS deliberately, and a directory that does not exist yet is counted as 0, not skipped.
//
// Here: import direction (H-5 item 5 as amended by Revision 2 C-2) and the AST literal backstop (H-5 item 4 + C-2).
// DOM fitness lives in test/dom-fitness.test.tsx (the binding tree's file). Not yet here, because their subjects are
// later steps: no-clock expiry and no-identity Withdraw (step 5). The `__FIXTURE_ONLY__` post-build check (L-2) is
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

/** `@/x` → `src/x`; `./x` → resolved against the file; anything else is a package name, returned as `pkg:<name>`. */
function targetOf(file: string, spec: string): string {
  if (spec.startsWith('@/')) return `src/${spec.slice(2)}`;
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
  'src/copy': 4,
  'src/lib': 12,
  'src/components': 32,
  'src/lib/api': 1,
  'src/lib/roles': 0,
  'src/lib/permissions': 0,
  'src/lib/bulk': 0,
  'src/lib/people': 2,
  'src/components/roles': 0,
  'src/components/permissions': 0,
  'src/components/bulk': 0,
  'src/components/people': 4,
  [FIXTURE_DIR]: 1,
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
