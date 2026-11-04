// @vitest-environment node
import { existsSync, lstatSync, readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import ts from 'typescript';
import { describe, expect, it } from 'vitest';
import { bulkMaxIds, bulkResources } from '@/lib/bff/surface';

// Mechanical guards for docs/design/b4-architecture.md (Revision 6 > Revision 5 > Revision 4 > Revision 3 > Revision 2 FINAL > Revision 1 >
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

// How the files reach the guards (it changes nothing about what any guard checks). `src` and `test` are read from disk
// ONCE, eagerly, while this module loads, into a frozen snapshot; every `walk(dir)` returns a fresh array filtered from it,
// in the same depth-first, name-sorted order the disk walk produces. Measured 2026-11-02: under four concurrent full
// suites a file open here becomes ~40× slower, and re-reading the tree in every guard (~10× the files needed) pushed the
// first tests past vitest's 5 s limit. The reads now happen during collection, which vitest does not time: that is a
// relocation of the cost, not a weakening, and a refused read now fails the whole file loudly instead of one test.
// `walk` throws for any directory outside `src`/`test` or not in normal form (`src/../x`, `src/x/`), so a walk can never
// silently see nothing because the snapshot does not hold it. A directory under `src`/`test` that does not exist yet still
// counts as 0. This file needs no DOM, so it runs in the node environment.
//
// The same walk also records, for `src` only, EVERY entry it meets (any extension, directories and symlinks included)
// into ALL_SRC_ENTRIES, with the contents of every regular file — a file reached through a symlink included, as the
// backend reads it. A FIFO, socket or device is recorded but never read, so it cannot hang or fail the walk for every guard
// here (twin RED L-1). Only the two backend twins at the end of this file (FE-R2 pick route, G-47 stock rows) read
// ALL_SRC_ENTRIES: Next.js serves `page.js`/`page.jsx` too, and the backend checks they twin search every file under
// frontend/src for their tokens. SNAPSHOT is unchanged by it: same files, same order, same contents.
interface DiskEntry {
  /** Path relative to the frontend root, POSIX separators. */
  file: string;
  /** Contents of a regular file (through a symlink too); null for a directory or a non-regular file. */
  source: string | null;
  link: boolean;
  dir: boolean;
}

function walkDisk(dirRel: string, everything?: DiskEntry[]): SourceFile[] {
  const abs = path.join(ROOT, dirRel);
  if (!existsSync(abs)) return [];
  const out: SourceFile[] = [];
  for (const name of readdirSync(abs).sort()) {
    const childRel = `${dirRel}/${name}`;
    const childAbs = path.join(abs, name);
    const stat = statSync(childAbs);
    const isDir = stat.isDirectory();
    const tsSource = !isDir && /\.(ts|tsx)$/.test(name) && !name.endsWith('.d.ts');
    let source: string | null = null;
    if (everything) {
      const link = lstatSync(childAbs).isSymbolicLink();
      if (stat.isFile()) source = readFileSync(childAbs, 'utf8');
      everything.push({ file: childRel, source, link, dir: isDir });
    }
    if (isDir) out.push(...walkDisk(childRel, everything));
    else if (tsSource) out.push({ file: childRel, source: source ?? readFileSync(childAbs, 'utf8') });
  }
  return out;
}

const SRC_ENTRIES: DiskEntry[] = [];
const SNAPSHOT: readonly SourceFile[] = Object.freeze([...walkDisk('src', SRC_ENTRIES), ...walkDisk('test')].map((f) => Object.freeze(f)));
const ALL_SRC_ENTRIES: readonly DiskEntry[] = Object.freeze(SRC_ENTRIES.map((e) => Object.freeze(e)));

function walk(dirRel: string): SourceFile[] {
  const inSnapshot = dirRel === 'src' || dirRel === 'test' || dirRel.startsWith('src/') || dirRel.startsWith('test/');
  const normal = path.posix.normalize(dirRel) === dirRel && !dirRel.endsWith('/') && !dirRel.split('/').includes('..');
  if (!inSnapshot || !normal) throw new Error(`walk('${dirRel}'): only normalised directories under src/ or test/ are in the snapshot`);
  return SNAPSHOT.filter((f) => f.file.startsWith(`${dirRel}/`));
}

// Parsed once per file object: the snapshot's objects are frozen, and a planted control is always a different object, so a
// real file's tree can never answer for a planted source (or the other way round).
const PARSED = new WeakMap<SourceFile, ts.SourceFile>();
const parse = (sf: SourceFile): ts.SourceFile => {
  let tree = PARSED.get(sf);
  if (!tree) {
    tree = ts.createSourceFile(sf.file, sf.source, ts.ScriptTarget.Latest, true, sf.file.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
    PARSED.set(sf, tree);
  }
  return tree;
};

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
  // c10 commit B removed: lib/auth/unwired.ts (change password is wired).
  'src/lib': 54,
  // b3b-followup C-2 added: components/shell/PageError.tsx. C-9 added: components/shell/RootNotFound.tsx.
  // Restore role added: components/permissions/RoleRestoreActions.tsx.
  'src/components': 52,
  'src/lib/api': 5,
  'src/lib/roles': 11,
  'src/lib/permissions': 5,
  'src/lib/bulk': 3,
  'src/lib/people': 4,
  'src/components/roles': 4,
  // Restore role added: components/permissions/RoleRestoreActions.tsx.
  'src/components/permissions': 3,
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
  // b3b-followup C-2 added: app/error.tsx, app/(app)/error.tsx, app/not-found.tsx.
  // c10 commit B added: app/bff/me/password/route.ts; moved (count unchanged) app/account/password/{page,
  // ChangePasswordRoute}.tsx → app/(app)/account/password/ (Revision 6 A-13).
  // Session-expiry return path added: app/(app)/SessionEndedReturn.tsx.
  'src/app': 40,
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

// ---------------------------------------------------------------------------------------------------------------------
// B-3b follow-up C-1 (G-F2, design b3b-followup §1 + RED L-2): a form submitted before hydration, or with JavaScript off,
// goes out natively. As a GET it puts every named field — a password included — in the address bar, history and request
// logs. So every <form> under src states method="post" literally and has no action (the default, the current URL, keeps
// the query string and stays off (app) paths); nothing may override the method or target per button (formMethod,
// formAction) or the encoding (encType); and next/form, which submits as GET, is not imported. Attribute names are
// compared case-insensitively. Limits: a form built with createElement, or a method given through an expression, is not
// read as post — the first evades the guard, the second is refused.
// ---------------------------------------------------------------------------------------------------------------------

const FORM_ONLY_FORBIDDEN = ['action', 'enctype'];
const ANY_ELEMENT_FORBIDDEN = ['formmethod', 'formaction'];

function formMethodViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    for (const spec of specifiersOf(sf)) if (spec === 'next/form') out.push({ file: sf.file, detail: "imports 'next/form'" });
    if (!sf.file.endsWith('.tsx')) continue;
    const tree = parse(sf);
    const visit = (node: ts.Node) => {
      if (ts.isJsxOpeningElement(node) || ts.isJsxSelfClosingElement(node)) {
        const tag = node.tagName.getText(tree);
        const isForm = tag === 'form';
        let method: string | null = null;
        for (const attr of node.attributes.properties) {
          if (ts.isJsxSpreadAttribute(attr)) {
            if (isForm) out.push({ file: sf.file, detail: 'spreads attributes onto <form>' });
            continue;
          }
          const name = attr.name.getText(tree);
          const lower = name.toLowerCase();
          if (ANY_ELEMENT_FORBIDDEN.includes(lower)) out.push({ file: sf.file, detail: `${name} on <${tag}>` });
          if (!isForm) continue;
          if (FORM_ONLY_FORBIDDEN.includes(lower)) out.push({ file: sf.file, detail: `${name} on <form>` });
          if (lower === 'method') method = attr.initializer && ts.isStringLiteral(attr.initializer) ? attr.initializer.text : '(not a literal)';
        }
        if (isForm && method !== 'post') out.push({ file: sf.file, detail: method === null ? '<form> without method="post"' : `<form> with method ${method}` });
      }
      ts.forEachChild(node, visit);
    };
    visit(tree);
  }
  return out;
}

describe('architecture — G-F2: every form posts, to its own page (b3b-followup C-1)', () => {
  it('the real tree has no breach, and the guard reads the four credential forms', () => {
    const files = walk('src');
    expect(formMethodViolations(files)).toEqual([]);
    const withForm = files.filter((sf) => /<form\b/.test(sf.source)).map((sf) => sf.file);
    expect(withForm).toEqual([
      'src/components/auth/ChangePasswordForm.tsx',
      'src/components/auth/ForgotPasswordForm.tsx',
      'src/components/auth/ResetPasswordForm.tsx',
      'src/components/auth/SignInCard.tsx',
    ]);
  });

  it('planted controls: no method, an action, an encType, a button formMethod, next/form — each red on its own; a post form is not', () => {
    const planted: SourceFile[] = [
      { file: 'src/components/a/NoMethod.tsx', source: 'export const A = () => <form onSubmit={f}><input name="p" /></form>;' },
      { file: 'src/components/a/Action.tsx', source: 'export const B = () => <form method="post" action="/elsewhere"></form>;' },
      { file: 'src/components/a/EncType.tsx', source: 'export const C = () => <form method="post" encType="multipart/form-data"></form>;' },
      { file: 'src/components/a/Button.tsx', source: 'export const D = () => <form method="post"><button formMethod="get">Go</button></form>;' },
      { file: 'src/app/x/page.tsx', source: "import Form from 'next/form';\nexport const E = () => null;" },
      { file: 'src/components/a/Ok.tsx', source: 'export const F = () => <form method="post" onSubmit={f} noValidate><button type="submit">Go</button></form>;' },
    ];
    expect(formMethodViolations(planted).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      'src/components/a/NoMethod.tsx — <form> without method="post"',
      'src/components/a/Action.tsx — action on <form>',
      'src/components/a/EncType.tsx — encType on <form>',
      'src/components/a/Button.tsx — formMethod on <button>',
      "src/app/x/page.tsx — imports 'next/form'",
    ]);
  });

  it('planted controls: a GET or computed method, formAction, a spread on the form, and lowercase spellings go red', () => {
    const planted: SourceFile[] = [
      { file: 'src/components/b/Get.tsx', source: 'export const A = () => <form method="get"></form>;' },
      { file: 'src/components/b/Computed.tsx', source: 'export const B = () => <form method={m}></form>;' },
      { file: 'src/components/b/FormAction.tsx', source: 'export const C = () => <form method="post"><input type="submit" formAction="/x" /></form>;' },
      { file: 'src/components/b/Spread.tsx', source: 'export const D = (p: P) => <form method="post" {...p}></form>;' },
      { file: 'src/components/b/Lower.tsx', source: 'export const E = () => <form method="post" enctype="text/plain"><button formmethod="get" /></form>;' },
    ];
    expect(formMethodViolations(planted).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      'src/components/b/Get.tsx — <form> with method get',
      'src/components/b/Computed.tsx — <form> with method (not a literal)',
      'src/components/b/FormAction.tsx — formAction on <input>',
      'src/components/b/Spread.tsx — spreads attributes onto <form>',
      'src/components/b/Lower.tsx — enctype on <form>',
      'src/components/b/Lower.tsx — formmethod on <button>',
    ]);
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// B-3b follow-up C-5b (RED M-1, narrow): components never join copy (ux-confirm-b4.md §4). The ST-5 heading and body as one
// sentence pair is `PAGE_STATES.notFoundInline`; no template literal under src may rebuild it from the two keys. Scope is
// src only (final-red I-2): test/roles/RolePicker.test.tsx joins them on purpose, to pin notFoundInline's equality.
// ---------------------------------------------------------------------------------------------------------------------

const NOT_FOUND_PARTS = ['PAGE_STATES.notFoundHeading', 'PAGE_STATES.notFoundBody'];

function notFoundJoinViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    const tree = parse(sf);
    const visit = (node: ts.Node) => {
      if (ts.isTemplateExpression(node)) {
        const refs = node.templateSpans.map((span) => span.expression.getText(tree).replace(/\s+/g, ''));
        if (NOT_FOUND_PARTS.every((p) => refs.includes(p))) out.push({ file: sf.file, detail: `joins the ST-5 heading and body at line ${tree.getLineAndCharacterOfPosition(node.getStart(tree)).line + 1}` });
      }
      ts.forEachChild(node, visit);
    };
    visit(tree);
  }
  return out;
}

describe('architecture — C-5b: the ST-5 sentence pair comes from notFoundInline, never a join (b3b-followup)', () => {
  it('the real src tree has no join', () => {
    expect(notFoundJoinViolations(walk('src'))).toEqual([]);
  });

  it('planted controls: the join goes red; one key alone, or notFoundInline, does not', () => {
    const planted: SourceFile[] = [
      { file: 'src/components/x/A.tsx', source: 'export const a = `${PAGE_STATES.notFoundHeading} ${PAGE_STATES.notFoundBody}`;' },
      { file: 'src/components/x/B.tsx', source: 'export const b = `${PAGE_STATES.notFoundHeading} more`;\nexport const c = PAGE_STATES.notFoundInline;' },
    ];
    expect(notFoundJoinViolations(planted).map((v) => `${v.file} — ${v.detail}`)).toEqual(['src/components/x/A.tsx — joins the ST-5 heading and body at line 1']);
  });
});

// Revision 6 A-14, G-8 (c10-final-red M-2): the frame's session id reaches only the account-page route. Every other
// authenticated browser call goes through useApiCaller(), which handles a 401 and a 409; a hand-rolled bffFetch with the
// sid would skip that. The identifier `useSessionId` may appear only in BffProvider.tsx and under src/app/(app)/account/.

const SESSION_ID_HOME = 'src/app/(app)/BffProvider.tsx';
const SESSION_ID_CONSUMERS = 'src/app/(app)/account/';

function sessionIdViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    if (sf.file === SESSION_ID_HOME || sf.file.startsWith(SESSION_ID_CONSUMERS)) continue;
    const tree = parse(sf);
    const visit = (node: ts.Node) => {
      if ((ts.isIdentifier(node) && node.text === 'useSessionId') || (ts.isStringLiteralLike(node) && node.text === 'useSessionId')) {
        out.push({ file: sf.file, detail: `names useSessionId at line ${tree.getLineAndCharacterOfPosition(node.getStart(tree)).line + 1}` });
      }
      ts.forEachChild(node, visit);
    };
    visit(tree);
  }
  return out;
}

describe('architecture — G-8: the session id reaches only the account-page route (Revision 6 A-14)', () => {
  it('the real src tree has no breach, and the accessor and its one consumer exist', () => {
    const files = walk('src');
    expect(sessionIdViolations(files)).toEqual([]);
    const named = files.filter((f) => /\buseSessionId\b/.test(f.source)).map((f) => f.file);
    expect(named).toContain(SESSION_ID_HOME);
    expect(named.some((f) => f.startsWith(SESSION_ID_CONSUMERS))).toBe(true);
  });

  it('planted controls: a use in an (app) page, the shell, a component or lib goes red; the home and an account route do not', () => {
    const planted: SourceFile[] = [
      { file: 'src/app/(app)/x.tsx', source: "import { useSessionId } from './BffProvider';\nexport const X = () => useSessionId();" },
      { file: 'src/app/(app)/AppShell.tsx', source: "import { useSessionId as sid } from './BffProvider';" },
      { file: 'src/components/people/Y.tsx', source: 'export const y = (m: { useSessionId: () => string }) => m.useSessionId();' },
      { file: 'src/lib/bff/z.ts', source: "export const k = 'useSessionId';" },
      { file: 'src/app/(app)/BffProvider.tsx', source: 'export function useSessionId() { return "s"; }' },
      { file: 'src/app/(app)/account/password/R.tsx', source: "import { useSessionId } from '../../BffProvider';\nexport const R = () => useSessionId();" },
    ];
    expect(sessionIdViolations(planted).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      'src/app/(app)/x.tsx — names useSessionId at line 1',
      'src/app/(app)/x.tsx — names useSessionId at line 2',
      'src/app/(app)/AppShell.tsx — names useSessionId at line 1',
      'src/components/people/Y.tsx — names useSessionId at line 1',
      'src/components/people/Y.tsx — names useSessionId at line 1',
      'src/lib/bff/z.ts — names useSessionId at line 1',
    ]);
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// FE-R2 (a): the pick screen's trigger — twin of the backend's OwedAmendmentTripwireTest::noFrontendShipsWithoutThePickRouteCarry
// ---------------------------------------------------------------------------------------------------------------------
// V2-S-1b clause 5's FRONTEND half and B1N-12's screen (owner ruling R8, 2026-09-22; trigger re-anchored by the owner on
// 2026-11-03, docs/design/fe-merge-r8-ruling.md, to-do 2; docs/design/frontend-residuals.md FE-R2). The backend check runs
// only when this app reaches main; this twin runs on every frontend gate, so the trigger fires on the commit that crosses
// it. Same rule, ported line for line (keep the two in step — a change to one is a change to both):
//   every page.{tsx,ts,jsx,js} under src/app gets the URL Next.js serves a static-segment page at (pageUrl). Antecedent (a): some page's URL
//   is /sales-orders or below. Antecedent (b): CreateFulfilment or GetPickList is named in a file under src outside
//   src/generated/. Either requires BOTH a page at exactly /sales-orders/[<one param>]/fulfil AND a use of GetPickList
//   outside src/generated/. Keyed on the URL (fixed by IA N-8a and lib/navigation.ts), never on a guessed folder.
// Not checkable here, and said so: R1 (on-hand per bin, never an available figure) and B1N-12 (show the order's resulting
// status after a CreateFulfilment 201). A stub page that names GetPickList satisfies the implication, and nothing here
// stops that. Nor can the URL model see a page that serves /sales-orders through a dynamic first segment (`[x]`, `[...x]`,
// `[[...x]]` at the app root), a middleware/proxy rewrite, a `rewrites()`/`redirects()` in next.config, or a config value
// imported from another module; the real-tree controls refuse the first three while none exists (twin RED M-2), and a
// person must teach the walk before adding one. The walk reads file symlinks and refuses any symlink under src; the
// backend reads file links but does not descend into directory links. The last control ("the trigger is still false") is what forces a person to look: the day the trigger turns
// true it goes red, and the commit that builds the screen checks R1 and B1N-12 by hand, says so, and removes that control
// here and in the backend test together.

const PAGE_FILES = ['page.tsx', 'page.ts', 'page.jsx', 'page.js'];
/** The identifiers that make the pick screen due (antecedent (b)); `GetPickList` alone is the consequent's use. */
const PICK_OPERATION_TOKEN = /\b(?:CreateFulfilment|GetPickList)\b/;
/** Exactly one single-segment dynamic parameter, then `fulfil`: no catch-all, no optional catch-all. */
const PICK_ROUTE_URL = /^\/sales-orders\/\[[A-Za-z][A-Za-z0-9]*\]\/fulfil$/;
const ROUTE_GROUP = /^\((?!\.)[^()]+\)$/;

/**
 * The URL Next.js serves a file at, or null when the file is not a page or is not routable. `page` is relative to
 * `src/app`. Only page.{tsx,ts,jsx,js} is a page. A `_` segment is a private folder. A route group `(name)` and a parallel
 * slot `@name` add nothing to the URL, at any depth. An intercepting segment (`(.)x`, `(..)x`) is kept verbatim: it can
 * make the screen due but never satisfy it (fail-closed).
 */
function pageUrl(page: string): string | null {
  const segments = page.replace(/\\/g, '/').split('/');
  if (!PAGE_FILES.includes(segments.pop()!)) return null;
  const url: string[] = [];
  for (const segment of segments) {
    if (segment.startsWith('_')) return null;
    if (ROUTE_GROUP.test(segment) || segment.startsWith('@')) continue;
    url.push(segment);
  }
  return `/${url.join('/')}`;
}

/** `pages`: files relative to src/app. `sources`: file contents keyed by path relative to src. */
function pickRouteIsDue(pages: readonly string[], sources: Readonly<Record<string, string>>): boolean {
  for (const page of pages) {
    const url = pageUrl(page);
    if (url !== null && (url === '/sales-orders' || url.startsWith('/sales-orders/'))) return true;
  }
  return Object.entries(sources).some(([file, text]) => !file.startsWith('generated/') && PICK_OPERATION_TOKEN.test(text));
}

function pickRouteDefects(pages: readonly string[], sources: Readonly<Record<string, string>>): string[] {
  if (!pickRouteIsDue(pages, sources)) return [];
  const defects: string[] = [];
  if (!pages.some((page) => PICK_ROUTE_URL.test(pageUrl(page) ?? ''))) {
    defects.push('the frontend has a /sales-orders page or names a pick operation, but no page is served at exactly /sales-orders/[param]/fulfil');
  }
  if (!Object.entries(sources).some(([file, text]) => !file.startsWith('generated/') && /\bGetPickList\b/.test(text))) {
    defects.push('no frontend source outside src/generated/ uses GetPickList (the read the pick screen consumes)');
  }
  return defects;
}

const PICK_ROUTE_MESSAGE =
  "V2-S-1b clause 5's FRONTEND half is owed (owner ruling R8, 2026-09-22; trigger re-anchored by the owner on\n" +
  '2026-11-03, docs/design/fe-merge-r8-ruling.md: a page at /sales-orders or below, or frontend source outside\n' +
  'src/generated/ naming CreateFulfilment or GetPickList; owner FE + UX).\n\n' +
  '  - The pick screen lives on `/sales-orders/[id]/fulfil` -- the action on the parent order (IA N-3/N-8,\n' +
  '    N-8a), not a standalone `/new` route -- and consumes `GET /api/v1/sales-orders/{id}/pick-list`.\n' +
  "  - B1N-12 (UX): after a CreateFulfilment 201 the screen shows the order's current status (re-read the\n" +
  '    pick list: `status` is on it), or FLOWS F-5 step 6 is rewritten.\n' +
  "  - docs/ux/FLOWS.md F-5 (UX, 2026-11-03) now holds the corrected gate and step 6's recommendation: after\n" +
  '    the 201, re-read the pick list and say "The order is now partly shipped / fully shipped / cancelled";\n' +
  "    if the re-read fails, keep the shipment's success message and say the status could not be loaded.\n" +
  '    TO CONFIRM FE-LEAD.\n' +
  '  - The pick read shows on-hand per candidate bin, never an available figure (owner ruling R1).\n' +
  'Do this in the commit that builds the screen, then move this test deliberately. Until then V2-S-1b is\n' +
  'shipped IN PART and must not be called discharged.';

/** Pages under src/app allowed a dynamic first URL segment. Empty: none exists, and each one added needs a reason. */
const DYNAMIC_ROOT_PAGES_ALLOWED: readonly string[] = [];

/** Pages whose first URL segment is dynamic (`[x]`, `[...x]`, `[[...x]]`), so they may serve /sales-orders unseen. */
function dynamicRootPages(pages: readonly string[], allowed: readonly string[]): string[] {
  return pages.filter((page) => {
    const first = pageUrl(page)?.split('/')[1];
    return first !== undefined && first.startsWith('[') && !allowed.includes(page);
  });
}

const APP_PREFIX = 'src/app/';
const realAppEntries = () => ALL_SRC_ENTRIES.filter((e) => e.file.startsWith(APP_PREFIX));
const realPages = () => realAppEntries().filter((e) => !e.link && !e.dir).map((e) => e.file.slice(APP_PREFIX.length));
const realSources = (): Record<string, string> =>
  Object.fromEntries(ALL_SRC_ENTRIES.filter((e) => e.source !== null).map((e) => [e.file.slice('src/'.length), e.source!]));

/**
 * The controls that make the walk of the real tree trustworthy, shared by both frontend tripwire twins in this file
 * (FE-R2 and G-47), as the backend's OwedAmendmentTripwireTest::frontendTree() is shared by its two methods. Pure walk: no
 * method-specific token control lives here (each block keeps its own generated-boundary control).
 */
function assertFrontendTreeIsWalkable(): void {
  expect(existsSync(path.join(ROOT, 'src/app')), 'Positive control: src/app is not where the walk looks, so it would find no page and pass on everything.').toBe(true);
  for (const router of ['app', 'pages', 'src/pages']) {
    expect(existsSync(path.join(ROOT, router)), `frontend/${router} exists: Next.js routes from it, and this check reads only src/app. Teach the walk that router before trusting it.`).toBe(false);
  }
  const configs = readdirSync(ROOT).filter((name) => name.startsWith('next.config.'));
  expect(configs.length, 'Positive control: no next.config.* found, so the pageExtensions check below reads nothing.').toBeGreaterThan(0);
  for (const config of configs) {
    const text = readFileSync(path.join(ROOT, config), 'utf8');
    expect(text, `${config} sets pageExtensions, so a page may now end in something the walk does not read. Make the walk read the configured extensions.`).not.toContain('pageExtensions');
    for (const key of ['rewrites', 'redirects']) {
      expect(text, `${config} sets ${key}, so a URL may now be served by a page this walk derives differently. Teach the walk before trusting it.`).not.toContain(key);
    }
  }
  expect(
    ALL_SRC_ENTRIES.filter((e) => e.link).map((e) => e.file),
    'A symlink under src: this walk reads file links and descends into directory links, while the backend check reads file links but never descends into a directory link, so the two would disagree about what is behind it. Remove the link, or teach both walks before trusting them.',
  ).toEqual([]);
  for (const dir of ['', 'src/']) {
    const routers = readdirSync(path.join(ROOT, dir)).filter((name) => /^(middleware|proxy)\./.test(name));
    expect(routers, `frontend/${dir}${routers[0] ?? ''} exists: a middleware or proxy can rewrite a URL to a page this walk derives differently. Teach the walk before trusting it.`).toEqual([]);
  }

  const pages = realPages();
  expect(
    dynamicRootPages(pages, DYNAMIC_ROOT_PAGES_ALLOWED),
    'A page whose first URL segment is dynamic can serve any first segment, and this walk would derive its URL as /[x]. Teach the walk before trusting it (or add the page to DYNAMIC_ROOT_PAGES_ALLOWED with a reason).',
  ).toEqual([]);
  const urls = pages.map(pageUrl).filter((u): u is string => u !== null);
  expect(urls.length, 'The walk found no page under src/app, so the implication would hold over nothing.').toBeGreaterThan(0);
  const underAGroup = pages.filter((p) => pageUrl(p) !== null && /(^|\/)\((?!\.)[^()/]+\)\//.test(p));
  expect(underAGroup.length, 'No real page sits under a route group, so the walk never exercised the group drop on the real tree.').toBeGreaterThan(0);
}

describe('architecture — FE-R2: no /sales-orders screen or pick operation without the pick screen (twin of the backend R8 check)', () => {
  it('planted controls: the predicate trips and passes on synthetic trees exactly as the backend check does', () => {
    const uses = { 'lib/api/pick.ts': "viewerMay('GetPickList')" };
    const planted: [string, string[], Record<string, string>, boolean][] = [
      ['a sales-orders list alone', ['(app)/sales-orders/page.tsx'], uses, false],
      ['the list and the fulfil page', ['(app)/sales-orders/page.tsx', '(app)/sales-orders/[id]/fulfil/page.tsx'], uses, true],
      ['the fulfil page under a nested group', ['(app)/sales-orders/[id]/(actions)/fulfil/page.tsx'], uses, true],
      ['the fulfil page under a parallel slot', ['(app)/sales-orders/[id]/@panel/fulfil/page.tsx'], uses, true],
      ['a component preview of the fulfil page', ['(dev)/component-preview/sales-orders/[id]/fulfil/page.tsx', '(app)/sales-orders/page.tsx'], uses, false],
      ['a catch-all under sales-orders', ['(app)/sales-orders/[...rest]/page.tsx'], uses, false],
      ['an optional catch-all as the param', ['(app)/sales-orders/page.tsx', '(app)/sales-orders/[[...id]]/fulfil/page.tsx'], uses, false],
      ['a route handler, not a page', ['(app)/sales-orders/page.tsx', '(app)/sales-orders/[id]/fulfil/route.ts'], uses, false],
      ['a layout, not a page', ['(app)/sales-orders/page.tsx', '(app)/sales-orders/[id]/fulfil/layout.tsx'], uses, false],
      ['a fulfil page in a private folder', ['_wip/sales-orders/[id]/fulfil/page.tsx', '(app)/sales-orders/page.tsx'], uses, false],
      ['an intercepting segment', ['(app)/sales-orders/page.tsx', '(app)/sales-orders/[id]/(.)fulfil/page.tsx'], uses, false],
      ['a fulfil page one level too deep', ['(app)/sales-orders/x/[id]/fulfil/page.tsx'], uses, false],
      ['the fulfil page without GetPickList', ['(app)/sales-orders/page.tsx', '(app)/sales-orders/[id]/fulfil/page.tsx'], {}, false],
      ['GetPickList only in generated code', ['(app)/admin/page.tsx'], { 'generated/api-surface.server.ts': 'GetPickList' }, true],
      ['CreateFulfilment used with no fulfil page', ['(app)/admin/page.tsx'], { 'components/Ship.tsx': 'CreateFulfilment' }, false],
      ['a frontend with no sales screens', ['(app)/admin/users/page.tsx', '(auth)/login/page.tsx'], {}, true],
      // Each tree below isolates ONE rule (empty or generated-only sources, so antecedent (b) cannot carry it; twin RED H-1).
      // Mirror them in the backend's planted set.
      ['a page.jsx counts as a page', ['(app)/sales-orders/page.jsx'], {}, false],
      ['a page.js counts as a page', ['(app)/sales-orders/page.js'], {}, false],
      ['a page below /sales-orders makes the screen due', ['(app)/sales-orders/[id]/page.tsx'], {}, false],
      ['GetPickList named only in generated code does not satisfy the screen', ['(app)/sales-orders/page.tsx', '(app)/sales-orders/[id]/fulfil/page.tsx'], { 'generated/api-surface.server.ts': 'GetPickList' }, false],
      ['a sales-orders page in a private folder is not routable', ['(app)/_wip/sales-orders/page.tsx'], {}, true],
      ['a private folder below sales-orders is not routable', ['(app)/sales-orders/_wip/page.tsx'], {}, true],
    ];
    const verdicts = planted.map(([name, pages, sources]) => [name, pickRouteDefects(pages, sources).length === 0]);
    expect(verdicts).toEqual(planted.map(([name, , , passes]) => [name, passes]));
    expect(pickRouteDefects(['(app)/sales-orders/page.tsx'], {})).toEqual([
      'the frontend has a /sales-orders page or names a pick operation, but no page is served at exactly /sales-orders/[param]/fulfil',
      'no frontend source outside src/generated/ uses GetPickList (the read the pick screen consumes)',
    ]);
  });

  it('planted controls: a page with a dynamic first segment is caught unless allowed; a deeper one is not', () => {
    const pages = ['(app)/[module]/page.tsx', '[...slug]/page.tsx', '(app)/[[...all]]/page.tsx', '(app)/admin/roles/[id]/page.tsx', '(app)/page.tsx', '_x/[y]/page.tsx', 'bff/[...p]/route.ts'];
    expect(dynamicRootPages(pages, [])).toEqual(['(app)/[module]/page.tsx', '[...slug]/page.tsx', '(app)/[[...all]]/page.tsx']);
    expect(dynamicRootPages(pages, ['[...slug]/page.tsx'])).toEqual(['(app)/[module]/page.tsx', '(app)/[[...all]]/page.tsx']);
  });

  it('real-tree controls: the walk sees what the routes it knows can serve, and the generated boundary is real', () => {
    assertFrontendTreeIsWalkable();

    const generatedNamesIt = Object.entries(realSources()).filter(([file, text]) => file.startsWith('generated/') && PICK_OPERATION_TOKEN.test(text));
    expect(generatedNamesIt.length, 'Positive control: src/generated/ no longer names GetPickList or CreateFulfilment, so excluding it proves nothing about where the boundary is. Re-derive the token or the folder.').toBeGreaterThan(0);
  });

  it('the real tree: a /sales-orders page or a pick operation brings the pick screen with it', () => {
    const defects = pickRouteDefects(realPages(), realSources());
    expect(defects, `  ${defects.join('\n  ')}\n\n${PICK_ROUTE_MESSAGE}`).toEqual([]);
  });

  it("the trigger is still false on the real tree (remove only in the pick screen's commit, after the hand check)", () => {
    expect(
      pickRouteIsDue(realPages(), realSources()),
      "The pick screen's trigger is now TRUE on the real tree and the implication above holds -- the screen is being\n" +
        'built. R1 and B1N-12 cannot be checked mechanically: check both by hand against the screen, say so in the\n' +
        'commit, and only then remove this control in that commit.\n' +
        "The backend's OwedAmendmentTripwireTest carries the same control; remove it in the merge that brings this commit to main, citing this commit's hand check.\n\n" +
        PICK_ROUTE_MESSAGE,
    ).toBe(false);
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// G-47: no stock rows on a screen while G-47 is open — twin of the backend's
// OwedAmendmentTripwireTest::noFrontendShowsStockRowsWhileG47IsOpen
// ---------------------------------------------------------------------------------------------------------------------
// G-47 (docs/RESIDUAL_GAPS.md, Low; owner UX + SEC): a ListStockLevels row does not say whether its bin can be picked --
// quarantine bins, archived bins and bins of an archived warehouse look like pickable ones. G-47 is NOT closed and nothing
// here may be read as closing it. Trigger re-put by the owner's delegation on 2026-11-03 (docs/design/g47-ruling.md;
// g47-owner-questions-recommendation.md FINAL R-1…R-3). The backend check runs only when this app reaches main; this twin
// runs on every frontend gate. Same rule, ported line for line from the backend method (keep the two in step — a change
// to one is a change to both):
//   The trigger T (outside src/generated/): (a) a page served at /stock or below (pageUrl, shared with FE-R2); (b) source
//   naming ListStockLevels, minus the one navigation-map `operation:` entry in lib/navigation.ts (path AND shape, never
//   path alone); (c) source naming the stock path /api/v1/stock or /bff/api/stock (not /stock-movements); (d)
//   scripts/api-surface.client-ops.json wiring ListStockLevels for the browser.
//   T ⇒ C1 (FINAL R-3, option 2): every file making T true through (a), (b) or (c), together with the modules it imports
//   one level down (relative or `@/` path, resolved by text, no AST), names ListStorageLocations, 'storage_location.view'
//   and 'warehouse.view'. When T holds through (d) alone no file can be checked, so the pin fails closed. C2 (the badge
//   copy key) joins in the screen's commit, once UX fixes the key.
// Weaker than option (1), and said so: a stub naming the three tokens satisfies C1; the safety rests on the seven hand
// checks in the failure message. Known limits (as the backend states them): a path or operation name built by
// concatenation or a template literal evades (b) and (c); a comment naming a token fires them (loud and safe); a
// nav-driven generic page (`[module]`) is held on its route half by the shared dynamic-segment refusal and on its client
// half by (d), and not on its server half; an intercepting `(..)stock` page that names nothing evades (a); a link to
// /stock from a caller of deriveNavigation() without the built filter is not watched; this walk refuses every symlink
// under src (the backend reads file links and does not descend into directory links). Movements (/stock-movements,
// GetStockMovement) are out of G-47's scope (FINAL R-4), but a new member on the shared location schema reaches them too.
// Row-shape control (G-47 RED A-M5): read here from the committed contract ../contracts/openapi.json (the backend reads
// the spec it regenerates; `npm run surface -- --check` ties src/generated/ to the same file).

/** G-47 trigger (b): the stock read's operationId. */
const STOCK_OPERATION_TOKEN = /\bListStockLevels\b/;
/** G-47 trigger (c): the stock path the server (`/api/v1/stock`) or the browser (`/bff/api/stock`) uses. */
const STOCK_PATH_TOKEN = /(?:\/api\/v1|\/bff\/api)\/stock(?![-A-Za-z0-9_])/;
/** The one navigation-map entry excluded from (b), in STOCK_NAV_FILE only. Global: removed and counted at every occurrence. */
const STOCK_NAV_ENTRY = /operation:\s*'ListStockLevels'/g;
const STOCK_NAV_FILE = 'lib/navigation.ts';
/** G-47 trigger (d), relative to `frontend/`. */
const CLIENT_OPS_FILE = 'scripts/api-surface.client-ops.json';
/** C1: what a file making the trigger true, or a module it imports one level down, must name. */
const STOCK_MARKER_INPUTS: readonly (readonly [string, RegExp])[] = [
  ['ListStorageLocations', /\bListStorageLocations\b/],
  ["'storage_location.view'", /'storage_location\.view'/],
  ["'warehouse.view'", /'warehouse\.view'/],
];

/** Byte-order sort, as the backend's `sort(…, SORT_STRING)` / `ksort(…, SORT_STRING)` (code-unit order; ASCII paths). */
const byteOrder = (a: string, b: string) => (a < b ? -1 : a > b ? 1 : 0);
const own = (sources: Readonly<Record<string, string>>, file: string): string => (Object.hasOwn(sources, file) ? (sources[file] ?? '') : '');

function stockPages(pages: readonly string[]): string[] {
  return pages.filter((page) => {
    const url = pageUrl(page);
    return url !== null && (url === '/stock' || url.startsWith('/stock/'));
  });
}

function stockSourceReasons(file: string, text: string): string[] {
  if (file.startsWith('generated/')) return [];
  const reasons: string[] = [];
  const searched = file === STOCK_NAV_FILE ? text.replace(STOCK_NAV_ENTRY, '') : text;
  if (STOCK_OPERATION_TOKEN.test(searched)) reasons.push('names ListStockLevels');
  if (STOCK_PATH_TOKEN.test(text)) reasons.push('names the stock path (/api/v1/stock or /bff/api/stock)');
  return reasons;
}

/** One line per reason G-47's frontend trigger is true; empty when it is not. Pages first, then sources (byte order), then the client wiring list. */
function stockRowTriggers(pages: readonly string[], sources: Readonly<Record<string, string>>, clientOps: readonly unknown[]): string[] {
  const lines: string[] = [];
  for (const page of [...stockPages(pages)].sort(byteOrder)) lines.push(`the frontend serves a page at ${pageUrl(page)} (src/app/${page})`);
  for (const [file, text] of Object.entries(sources).sort(([a], [b]) => byteOrder(a, b))) {
    for (const reason of stockSourceReasons(file, text)) lines.push(`src/${file} ${reason} outside src/generated/`);
  }
  if (clientOps.includes('ListStockLevels')) lines.push(`${CLIENT_OPS_FILE} wires ListStockLevels for the browser`);
  return lines;
}

/**
 * Modules a file imports by a relative or `@/` specifier, resolved by text to paths relative to `src` (`.ts`, `.tsx`,
 * `.js`, `.jsx`, or an `index.*` in a folder). Unresolved specifiers are dropped by the caller (a missing key reads '').
 */
function directImports(file: string, text: string): string[] {
  const found = new Set<string>();
  for (const match of text.matchAll(/(?:\bfrom|\bimport)\s*\(?\s*['"]((?:\.\/|\.\.\/|@\/)[^'"]+)['"]/g)) {
    const specifier = match[1] ?? ''; // the group is not optional, so this is always the captured text
    const parts = specifier.startsWith('@/') ? specifier.slice(2).split('/') : [...file.split('/').slice(0, -1), ...specifier.split('/')];
    const resolved: string[] = [];
    for (const part of parts) {
      if (part === '..') resolved.pop();
      else if (part !== '.' && part !== '') resolved.push(part);
    }
    const base = resolved.join('/');
    for (const suffix of ['', '.ts', '.tsx', '.js', '.jsx', '/index.ts', '/index.tsx', '/index.js', '/index.jsx']) found.add(base + suffix);
  }
  return [...found];
}

/** C1 over every file that makes the trigger true. Fails closed when the trigger holds only through the client wiring list. */
function stockRowDefects(pages: readonly string[], sources: Readonly<Record<string, string>>, clientOps: readonly unknown[]): string[] {
  if (stockRowTriggers(pages, sources, clientOps).length === 0) return [];
  const files = stockPages(pages).map((page) => `app/${page}`);
  for (const [file, text] of Object.entries(sources)) {
    if (stockSourceReasons(file, text).length > 0) files.push(file);
  }
  const unique = [...new Set(files)].sort(byteOrder);
  if (unique.length === 0) {
    return [
      'the client wiring list wires ListStockLevels, but no frontend source outside src/generated/ names it, ' +
        'so no file can be checked for the marker inputs (fail closed: name the stock read where it is used)',
    ];
  }
  const defects: string[] = [];
  for (const file of unique) {
    let text = own(sources, file);
    for (const imported of directImports(file, text)) text += `\n${own(sources, imported)}`;
    const missing = STOCK_MARKER_INPUTS.filter(([, pattern]) => !pattern.test(text)).map(([name]) => name);
    if (missing.length > 0) defects.push(`src/${file} makes the stock trigger true, but neither it nor a module it imports names ${missing.join(', ')}`);
  }
  return defects;
}

type Json = Record<string, unknown>;
const asObject = (value: unknown): Json => (value !== null && typeof value === 'object' && !Array.isArray(value) ? (value as Json) : {});

function resolveRef(spec: Json, schema: Json): Json {
  let current = schema;
  while (typeof current.$ref === 'string') {
    let target: unknown = spec;
    for (const segment of current.$ref.split('/').slice(1)) target = asObject(target)[segment] ?? {};
    current = asObject(target);
  }
  return current;
}

/** The item schema of ListStockLevels' 200 response, found by operationId, `$ref`s resolved; null when the spec has none. */
function stockRowSchema(spec: Json): Json | null {
  for (const item of Object.values(asObject(spec.paths))) {
    for (const [method, operation] of Object.entries(asObject(item))) {
      if (['parameters', 'summary', 'description', 'servers', '$ref'].includes(method)) continue;
      if (asObject(operation).operationId !== 'ListStockLevels') continue;
      for (const content of Object.values(asObject(asObject(asObject(asObject(operation).responses)['200']).content))) {
        const collection = resolveRef(spec, asObject(asObject(content).schema));
        const items = resolveRef(spec, asObject(asObject(collection.properties).items));
        return resolveRef(spec, asObject(items.items));
      }
    }
  }
  return null;
}

const schemaShape = (schema: Json) => Object.keys(asObject(schema.properties)).map(String).sort(byteOrder);

const G47_MESSAGE =
  'G-47 is open (docs/RESIDUAL_GAPS.md G-47, Low; owner UX + SEC): a stock row (ListStockLevels) does not say\n' +
  'whether its bin can be picked -- quarantine bins, archived bins and bins of an archived warehouse look like\n' +
  'pickable ones. Its frontend trigger was re-put on 2026-11-03 (docs/design/g47-ruling.md, adopted under the\n' +
  "owner's delegation; the owner may strike it): stock rows may render in the web app ONLY to a viewer holding\n" +
  'BOTH storage_location.view and warehouse.view, with a text badge (Quarantine / Archived) drawn client-side\n' +
  'from ListStorageLocations (live and ?archived=true: kind, archived_at) and the warehouse\'s archived state\n' +
  '(GetWarehouse / ListWarehouses?archived). Everyone else sees no stock rows until G-47 closes.\n\n' +
  'FAIL CLOSED: a row whose marker inputs did not all load is never shown as pickable (a text state such as\n' +
  "'Status unknown' -- UX wording -- or the rows withheld behind a retry).\n\n" +
  'This check proves only that the marker inputs are NAMED. A stub naming them passes, so it is weaker than\n' +
  'option (1), the full block, which the owner was offered as the fallback. The commit that builds the screen\n' +
  'checks these seven by hand and says so:\n' +
  '  1. rows render only when the viewer holds both codes; otherwise the H-3a hint and an empty state, no rows;\n' +
  "  2. the badge uses all three inputs: kind = quarantine, the bin's archived_at, the warehouse's archived_at;\n" +
  '  3. fail closed, as above;\n' +
  '  4. the badge is text, never colour only;\n' +
  "  5. IA.md H-3a's hint stays under qty_on_hand;\n" +
  '  6. an item-scoped view covers every warehouse its rows name;\n' +
  '  7. the server-side first render applies the same gate as the browser re-read.\n' +
  'In that commit also add C2 (the badge copy key UX fixes) to this check and to its frontend twin, which is\n' +
  'owed next on the frontend track (frontend/test/architecture.test.ts, FE-LEAD). G-47 itself stays open:\n' +
  "closing it needs SEC's ruling on a row field, the owner's word, and UX's badge (RESIDUAL_GAPS.md G-47).";

const G47_ROW_SHAPE_MESSAGE =
  "The stock row (ListStockLevels' items) changed shape. If this is G-47's marker field (or a member on the\n" +
  'shared location reference, which StockMovement reuses), the closing design starts in THIS backend commit:\n' +
  'move this pin to the implication docs/design/g47-ruling.md describes, and close or re-put G-47 in\n' +
  'docs/RESIDUAL_GAPS.md. Otherwise update the expected shape with a stated reason.';

const realClientOps = (): unknown => JSON.parse(readFileSync(path.join(ROOT, CLIENT_OPS_FILE), 'utf8'));

describe('architecture — G-47: no stock rows without the marker inputs while G-47 is open (twin of the backend G-47 check)', () => {
  it('planted trigger trees: each isolates one rule and is due or not exactly as the backend check says', () => {
    const admin = ['(app)/admin/page.tsx'];
    const nav = { 'lib/navigation.ts': "{ label: 'Stock on hand', href: '/stock', operation: 'ListStockLevels', permission: 'stock_level.view' }" };
    const triggers: [string, string[], Record<string, string>, string[], boolean][] = [
      ['a /stock page', ['(app)/stock/page.tsx'], {}, [], true],
      ['a /stock page outside any group', ['stock/page.tsx'], {}, [], true],
      ['a /stock page.jsx', ['(app)/stock/page.jsx'], {}, [], true],
      ['a /stock page.js', ['(app)/stock/page.js'], {}, [], true],
      ['a page below /stock', ['(app)/stock/[id]/page.tsx'], {}, [], true],
      ['a /stock page under a nested group', ['(app)/(inventory)/stock/page.tsx'], {}, [], true],
      ['a /stock page under a parallel slot', ['(app)/stock/@panel/page.tsx'], {}, [], true],
      ['a /stock page in a private folder', ['(app)/_wip/stock/page.tsx'], {}, [], false],
      ['a private folder below /stock', ['(app)/stock/_wip/page.tsx'], {}, [], false],
      ['a /stock-movements page', ['(app)/stock-movements/page.tsx'], {}, [], false],
      ['a /stock layout or route handler, not a page', ['(app)/stock/layout.tsx', '(app)/stock/route.ts'], {}, [], false],
      ['a component preview of a stock screen', ['(dev)/component-preview/stock/page.tsx'], {}, [], false],
      ['an intercepting (..)stock segment (a stated limit)', ['(app)/items/(..)stock/page.tsx'], {}, [], false],
      ['the stock read named in a library file', admin, { 'lib/api/stock.ts': "read('ListStockLevels')" }, [], true],
      ['the stock read only in generated code, and the nav entry', admin, { 'generated/api-surface.server.ts': 'ListStockLevels', ...nav }, [], false],
      ['the stock read called from the navigation file', admin, { 'lib/navigation.ts': "read('ListStockLevels')" }, [], true],
      ['the nav entry shape in another file', admin, { 'components/X.tsx': "operation: 'ListStockLevels'" }, [], true],
      ['a raw browser fetch of /bff/api/stock', admin, { 'lib/x.ts': "bffFetch('/bff/api/stock')" }, [], true],
      ['a raw browser fetch of /bff/api/stock-movements', admin, { 'lib/x.ts': "bffFetch('/bff/api/stock-movements')" }, [], false],
      ['a raw server path /api/v1/stock with a query', admin, { 'lib/x.ts': "'/api/v1/stock?item=1'" }, [], true],
      ['a raw server path /api/v1/stock-movements', admin, { 'lib/x.ts': "'/api/v1/stock-movements'" }, [], false],
      ['the stock path only in generated code', admin, { 'generated/api-surface.server.ts': "template: '/api/v1/stock'," }, [], false],
      ['the stock read wired for the browser', admin, {}, ['ListUsers', 'ListStockLevels'], true],
      ['only the movements read wired for the browser', admin, {}, ['ListStockMovements'], false],
      ['a frontend with no stock screens', ['(app)/admin/users/page.tsx', '(auth)/login/page.tsx'], {}, ['ListUsers'], false],
    ];
    const verdicts = triggers.map(([name, pages, sources, clientOps]) => [name, stockRowTriggers(pages, sources, clientOps).length > 0]);
    expect(verdicts).toEqual(triggers.map(([name, , , , due]) => [name, due]));
  });

  it('planted implication trees: C1 and its one-level import resolution trip and pass exactly as the backend check says', () => {
    const admin = ['(app)/admin/page.tsx'];
    const marker = "listLocations('ListStorageLocations'); need('storage_location.view'); need('warehouse.view');";
    const page = ['(app)/stock/page.tsx'];
    const implications: [string, string[], Record<string, string>, string[], boolean][] = [
      ['the screen names all three marker inputs', page, { 'app/(app)/stock/page.tsx': marker }, [], true],
      ['the screen without ListStorageLocations', page, { 'app/(app)/stock/page.tsx': "need('storage_location.view'); need('warehouse.view');" }, [], false],
      ['the screen without storage_location.view', page, { 'app/(app)/stock/page.tsx': "x('ListStorageLocations'); need('warehouse.view');" }, [], false],
      ['the screen without warehouse.view', page, { 'app/(app)/stock/page.tsx': "x('ListStorageLocations'); need('storage_location.view');" }, [], false],
      ['the screen with no source at all', page, {}, [], false],
      ['the marker one level down by a relative import', page, { 'app/(app)/stock/page.tsx': "import { m } from './marker';", 'app/(app)/stock/marker.ts': marker }, [], true],
      ['the marker one level down by an @/ import', page, { 'app/(app)/stock/page.tsx': "import { m } from '@/lib/stock/marker';", 'lib/stock/marker.ts': marker }, [], true],
      ['the marker two levels down', page, { 'app/(app)/stock/page.tsx': "import { a } from './a';", 'app/(app)/stock/a.ts': "import { m } from './marker';", 'app/(app)/stock/marker.ts': marker }, [], false],
      ['a stock panel naming the read and the marker', admin, { 'components/StockPanel.tsx': `read('ListStockLevels'); ${marker}` }, [], true],
      ['a stock panel naming the read alone', admin, { 'components/StockPanel.tsx': "read('ListStockLevels')" }, [], false],
      ['the stock read wired for the browser, no file to check', admin, {}, ['ListStockLevels'], false],
      ['no stock screen at all', admin, {}, [], true],
    ];
    const verdicts = implications.map(([name, pages, sources, clientOps]) => [name, stockRowDefects(pages, sources, clientOps).length === 0]);
    expect(verdicts).toEqual(implications.map(([name, , , , passes]) => [name, passes]));
  });

  it('planted control: the trigger lines for a four-reason tree are the exact lines, in order, that a red run prints', () => {
    expect(stockRowTriggers(['(app)/stock/page.tsx'], { 'lib/x.ts': "'/bff/api/stock'", 'components/A.tsx': 'ListStockLevels' }, ['ListStockLevels'])).toEqual([
      'the frontend serves a page at /stock (src/app/(app)/stock/page.tsx)',
      'src/components/A.tsx names ListStockLevels outside src/generated/',
      'src/lib/x.ts names the stock path (/api/v1/stock or /bff/api/stock) outside src/generated/',
      'scripts/api-surface.client-ops.json wires ListStockLevels for the browser',
    ]);
  });

  it('real-tree controls: the walk, the generated boundary, the nav exclusion, the client wiring list and the row shape', () => {
    assertFrontendTreeIsWalkable();
    const sources = realSources();
    const generated = Object.entries(sources).filter(([file, text]) => file.startsWith('generated/') && STOCK_OPERATION_TOKEN.test(text) && STOCK_PATH_TOKEN.test(text));
    expect(generated.length, 'Positive control: src/generated/ no longer names ListStockLevels and its path, so excluding it proves nothing about where the boundary is. Re-derive the tokens or the folder.').toBeGreaterThan(0);
    expect(
      (own(sources, STOCK_NAV_FILE).match(STOCK_NAV_ENTRY) ?? []).length,
      `src/${STOCK_NAV_FILE} does not hold the navigation entry \`operation: 'ListStockLevels'\` exactly once. The exclusion below removes that shape from that file only, on the premise that the nav map renders nothing but built links; it has moved or changed shape, so re-derive the exclusion before trusting it.`,
    ).toBe(1);
    expect(existsSync(path.join(ROOT, CLIENT_OPS_FILE)), 'Positive control: the client wiring list moved, so trigger (d) reads nothing.').toBe(true);
    const clientOps = realClientOps();
    expect(Array.isArray(clientOps), 'Positive control: the client wiring list is not a JSON list, so trigger (d) reads nothing.').toBe(true);
    expect(clientOps as unknown[], 'Positive control: the client wiring list no longer holds ListUsers, so it is not the list trigger (d) means.').toContain('ListUsers');

    // Row-shape control (A-M5): a marker field on the stock row is G-47's closing work arriving -- design it there.
    const contract = path.join(ROOT, '../contracts/openapi.json');
    expect(existsSync(contract), 'Positive control: ../contracts/openapi.json is not where the row-shape control looks, so it reads nothing.').toBe(true);
    const spec = asObject(JSON.parse(readFileSync(contract, 'utf8')));
    const row = stockRowSchema(spec);
    expect(row, 'ListStockLevels has no 200 response schema in the committed contract, so the row-shape control reads nothing.').not.toBeNull();
    const rowProperties = asObject(row!.properties);
    expect(schemaShape(row!), G47_ROW_SHAPE_MESSAGE).toEqual(['id', 'inventory_item', 'qty_on_hand', 'storage_location', 'version', 'warehouse']);
    expect(schemaShape(resolveRef(spec, asObject(rowProperties.storage_location))), G47_ROW_SHAPE_MESSAGE).toEqual(['code', 'id']);
    expect(schemaShape(resolveRef(spec, asObject(rowProperties.warehouse))), G47_ROW_SHAPE_MESSAGE).toEqual(['id', 'name']);
  });

  it('the real tree: anything that makes the stock trigger true names the marker inputs', () => {
    const defects = stockRowDefects(realPages(), realSources(), realClientOps() as unknown[]);
    expect(defects, `  ${defects.join('\n  ')}\n\n${G47_MESSAGE}`).toEqual([]);
  });

  it("the trigger is still false on the real tree (remove only in the stock screen's commit, after the hand check)", () => {
    const lines = stockRowTriggers(realPages(), realSources(), realClientOps() as unknown[]);
    expect(
      lines,
      `  ${lines.join('\n  ')}\n\n` +
        "G-47's frontend trigger is now TRUE on the real tree and the implication above holds -- a stock screen is\n" +
        'being built. Check the seven items below by hand against the screen, say so in the commit, and only then\n' +
        'remove this control in that commit.\n' +
        "The backend's OwedAmendmentTripwireTest carries the same control; remove it in the merge that brings this commit to main, citing this commit's hand check.\n\n" +
        G47_MESSAGE,
    ).toEqual([]);
  });
});
