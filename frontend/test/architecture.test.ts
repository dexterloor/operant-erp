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
// here (twin RED L-1). The three backend twins at the end of this file (FE-R2 pick route, G-47 stock rows, G-51 stock
// counts and transfers) read
// ALL_SRC_ENTRIES: Next.js serves `page.js`/`page.jsx` too, and the backend checks they twin search every file under
// frontend/src for their tokens. The guard-enrolment check (items I-0a, M-2) reads only its directory entries. SNAPSHOT is unchanged by it: same files, same order, same contents.
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
    } else if (ts.isImportTypeNode(node) && ts.isLiteralTypeNode(node.argument) && ts.isStringLiteral(node.argument.literal)) {
      // `type W = import('x').T` (items I-0a RED M-2): an inline type import names a module too.
      found.push(node.argument.literal.text);
    } else if (ts.isImportEqualsDeclaration(node) && ts.isExternalModuleReference(node.moduleReference) && ts.isStringLiteral(node.moduleReference.expression)) {
      // `import x = require('x')` (items I-0a RED M-2 / L-5; tsc also refuses it under module esnext, TS1202).
      found.push(node.moduleReference.expression.text);
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

/** The only importers of `src/lib/api/problems.ts` (A-18 S-1): a new one is a deliberate edit here. */
// Revision 8 R8-4: one importer per family, each pinned. Items I-1 (items-i1-red.md L-3, second option) added lib/items/wired.ts.
// Suppliers S-1 (suppliers-plan.md Revision 0 §3, PROBLEMS_IMPORTERS row) added lib/suppliers/wired.ts (P1 on Save/Restore).
// Purchase orders PO-4a (plan Revision 0 §3 PROBLEMS_IMPORTERS row, moved from PO-2 by po-2-progress.md D-2: the first
// action that must tell P1 apart) added lib/purchasing/wired.ts (P1 on the draft header's Save).
const PROBLEMS_IMPORTERS: readonly string[] = ['src/lib/items/wired.ts', 'src/lib/purchasing/wired.ts', 'src/lib/suppliers/wired.ts', 'src/lib/warehouses/wired.ts'];

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
      // A-18 S-1 (RED M-2 of step 4.6): the platform refusal rule is compared in one place, so only that file imports it.
      // A comparison through the constant holds no literal, which REFUSAL_HOMES cannot see; this pin can.
      if (t.replace(/\.ts$/, '') === 'src/lib/api/problems' && !PROBLEMS_IMPORTERS.includes(sf.file)) breach('only the pinned family wired.ts files import lib/api/problems (A-18 S-1, R8-4)');
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
  // Revision 7 step 3 (§5 ledger, LITERAL_SCAN_DIRS row): the forms helpers.
  'src/lib/forms',
  // Revision 7 step 4.1 (A-18 guards): the warehouse logic and components.
  'src/lib/warehouses',
  // Revision 8 R8-3 (items I-1): the item logic and components.
  'src/lib/items',
  'src/components/items',
  // Suppliers S-1 (Revision 8 R8-2, R8-3 completeness): the supplier logic and components.
  'src/lib/suppliers',
  'src/components/suppliers',
  // Purchase orders PO-1 (Revision 8 R8-2, R8-3 completeness; Revision 10 R10-2): the purchasing family's logic.
  'src/lib/purchasing',
  // Purchase orders PO-3 (R8-3 completeness): the family's components (NewOrderForm, the create page's form).
  'src/components/purchasing',
  'src/components/roles',
  'src/components/permissions',
  'src/components/bulk',
  'src/components/people',
  'src/components/warehouses',
  // Revision 8 R8-3 (items I-0a): the shared record dialogs, moved out of src/components/warehouses.
  'src/components/records',
  // items I-0a RED M-3: folders that already passed this scan, enrolled so a literal typed there later goes red.
  'src/components/auth',
  'src/components/shell',
  'src/components/states',
  'src/components/theme',
  'src/components/ui',
  'src/lib/auth',
  'src/lib/format',
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

/**
 * The refusal homes (Revision 7 A-18 S-2, R3-4): the only files whose top-level declarations may hold code-shaped
 * literals, each pinned to exactly the literals it holds (`exemptedLiterals` must equal its list). A new constant in a
 * home is a deliberate edit here. `problems.ts` joined at §2 step 4.6 (its first consumer, Delete warehouse/location).
 */
const REFUSAL_HOMES: Record<string, readonly string[]> = {
  'src/lib/api/roles.ts': [
    'user_role.already_held',
    'user_role.separation_of_duty',
    'user_role.marker_needs_owner',
    'user_role.exceeds_actor_permissions',
    'user_role.actor_is_subject',
    'user_role.subject_is_inactive',
    'user_role.subject_is_deleted',
    'role.is_not_archived',
    'tenant_settings.edit',
  ],
  'src/lib/api/problems.ts': ['transaction.contention'],
  // Purchase orders PO-4a (UX D-2: a status refusal is matched by rule code): P9's rule, compared in lib/purchasing/wired.ts.
  // Purchase orders PO-5 (plan R1-5 PO-5 "L1 → … catalogue re-read"): L1's rule, compared in lib/purchasing/wired.ts.
  // Purchase orders PO-7 (plan §2.2 "Stale page", C2 → re-read): the status guard's rule, compared in lib/purchasing/wired.ts.
  // Purchase orders PO-8 (po-8-red.md L-1, A1 → re-read): DSoD-1's Approve rule, compared in lib/purchasing/wired.ts.
  // Purchase orders PO-9 (po-9-red.md L-1, W24 → already restored): the line restore's not-archived rule, compared in lib/purchasing/wired.ts.
  // Purchase orders PO-4b (po-4b-red.md L-4): the line update's archived-line rule, compared in lib/purchasing/wired.ts.
  // Purchase orders GR-2 (plan §2.6 R1 → lines re-read, X-15; final RED M-1, R3 → the client's sentence): the over-receipt
  // and the on-hand-fits rules, compared in lib/purchasing/wired.ts.
  'src/lib/api/purchasing.ts': ['purchase_order.is_editable_only_while_draft', 'purchase_order_line.supplier_lists_the_item', 'purchase_order.status_transition_is_legal', 'purchase_order.approver_is_not_the_creator', 'purchase_order_line.is_not_archived', 'purchase_order_line.is_archived', 'purchase_order_line.is_not_over_received', 'stock_level.on_hand_fits'],
};

/** The code-shaped literals a home's top-level declarations hold (the ones `literalViolations` exempts there). */
function exemptedLiterals(sf: SourceFile): string[] {
  const out: string[] = [];
  const visit = (node: ts.Node) => {
    if ((ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) && CODE_SHAPE.test(node.text) && inTopLevelDeclaration(node) && !inTypePosition(node)) out.push(node.text);
    ts.forEachChild(node, visit);
  };
  visit(parse(sf));
  return out;
}

/** Every string literal / template text in the scanned files that looks like a served code or a built-in role name. */
function literalViolations(files: readonly SourceFile[], roleNames: readonly string[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    const inApi = under(sf.file, 'src/lib/api');
    const isRefusalHome = Object.hasOwn(REFUSAL_HOMES, sf.file);
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
  // Revision 7 step 4.1 added: copy/warehouses.ts.
  // Items I-1 (Revision 8 R8-2) added: copy/items.ts.
  // Suppliers S-1 (Revision 8 R8-2) added: copy/suppliers.ts.
  // Purchase orders PO-1 (Revision 8 R8-2; Revision 10 R10-2) added: copy/purchasing.ts.
  'src/copy': 12,
  // c10 commit B removed: lib/auth/unwired.ts (change password is wired).
  // Revision 7 step 3 (A-19 item 4) added: lib/forms/fieldErrors.ts.
  // Revision 7 step 4.1 added: lib/api/warehouses.ts, lib/warehouses/{load,results,wired}.ts.
  // Revision 7 step 4.6 added: lib/api/problems.ts (PLATFORM_REFUSAL, its first consumer is Delete; A-18 S-1).
  // Items I-1 (Revision 8 R8-2) added: lib/api/items.ts, lib/format/quantity.ts, lib/items/{load,results,wired}.ts.
  // Suppliers S-1 added: lib/api/suppliers.ts, lib/suppliers/{load,results,wired}.ts (R8-2) and
  // lib/forms/wholeNumberOrText.ts (Revision 9 R9-2).
  // Suppliers S-3 added: lib/format/money.ts (renderMoney, Revision 9 R9-2).
  // Purchase orders PO-1 added: lib/api/purchasing.ts, lib/purchasing/load.ts (R8-2; no results or wired before PO-2's
  // first action). PO-2 added: lib/purchasing/results.ts, lib/purchasing/wired.ts (Restore purchase order). PO-3 added
  // nothing here (Create purchase order joins the three files).
  // Purchase orders GR-2 H-1 added: lib/bff/heldKey.ts (the held Idempotency-Key of a stock- or money-moving create).
  'src/lib': 76,
  // b3b-followup C-2 added: components/shell/PageError.tsx. C-9 added: components/shell/RootNotFound.tsx.
  // Restore role added: components/permissions/RoleRestoreActions.tsx.
  // Revision 7 step 3 (A-19 item 1) added: components/ui/TextField.tsx.
  // Revision 7 step 4.1 added: components/warehouses/WarehouseForm.tsx.
  // Revision 7 step 4.2 added: components/warehouses/WarehouseRestoreActions.tsx.
  // Revision 7 step 4.3 added: components/warehouses/WarehouseCreateForm.tsx.
  // Revision 7 step 4.4 added: components/warehouses/LocationForm.tsx.
  // Revision 7 step 4.5 added: components/warehouses/AddLocationForm.tsx (the Add location dialog) and KindField.tsx
  // (the Kind select both location dialogs use).
  // Revision 7 step 4.6 added: components/warehouses/DeleteDialog.tsx (Delete warehouse and Delete location, B-7).
  // Revision 8 R8-3 (items I-0a) MOVED, count unchanged: components/warehouses/{DeleteDialog,WarehouseRestoreActions}.tsx
  // → components/records/{DeleteDialog,RestoreActions}.tsx.
  // Items I-1 added: components/items/ItemForm.tsx.
  // Items I-2 (Revision 8 R8-5) added: components/ui/SelectField.tsx (KindField.tsx stays, a thin caller) and
  // components/items/ItemCreateForm.tsx.
  // Suppliers S-1 added: components/suppliers/SupplierForm.tsx. Suppliers S-2 added: components/suppliers/SupplierCreateForm.tsx.
  // Suppliers S-4 added: components/suppliers/EntryForm.tsx (the catalogue entry's Edit dialog, suppliers-plan.md §3).
  // Suppliers S-5 added: components/suppliers/AddEntryForm.tsx (Add to catalogue's dialog, suppliers-plan.md §R1-3).
  // Purchase orders PO-3 added: components/purchasing/NewOrderForm.tsx (the create page's form, plan §3).
  // Purchase orders PO-4a added: components/purchasing/OrderHeaderForm.tsx (the draft header's editor, plan §3).
  // Purchase orders PO-5 added: components/purchasing/AddLineRow.tsx (the lines table's new line row, plan §3).
  // Purchase orders PO-7 added: components/purchasing/CancelOrderDialog.tsx (Cancel order's reason dialog, plan §3).
  // Purchase orders PO-8 added: components/purchasing/PlaceOrderDialog.tsx (Approve's confirm dialog, plan §3).
  // Purchase orders PO-4b added: components/purchasing/EditLineRow.tsx (a live line's edit row, R10-6).
  // Purchase orders GR-2 added: components/purchasing/ReceiveForm.tsx (the receive page's form, plan R1-4: the M-6 map lives here).
  'src/components': 74,
  // Revision 7 step 4.6 added: lib/api/problems.ts. Items I-1 added: lib/api/items.ts. Suppliers S-1 added: lib/api/suppliers.ts.
  // Purchase orders PO-1 added: lib/api/purchasing.ts.
  'src/lib/api': 10,
  'src/lib/roles': 11,
  'src/lib/permissions': 5,
  'src/lib/bulk': 3,
  'src/lib/people': 4,
  // Revision 7 step 3 (A-19 item 4): the forms helpers. Revision 9 R9-2 (suppliers S-1) added: wholeNumberOrText.ts.
  'src/lib/forms': 2,
  // Revision 7 step 4.1 added: the warehouse loaders, results and Save.
  'src/lib/warehouses': 3,
  // Items I-1 (Revision 8 R8-2, R8-3) added: the item loaders, results, Save and Restore; the item page's editor.
  'src/lib/items': 3,
  // Items I-2 added: ItemCreateForm.tsx (the create page's form).
  'src/components/items': 2,
  // Suppliers S-1 (Revision 8 R8-2) added: the supplier loaders, results, Save and Restore; the supplier page's editor.
  'src/lib/suppliers': 3,
  // Purchase orders PO-1 (Revision 8 R8-2; Revision 10 R10-2) added: the purchasing loaders. PO-2 added: results and
  // wired (Restore purchase order, the family's first action).
  'src/lib/purchasing': 3,
  // Suppliers S-2 added: SupplierCreateForm.tsx (the create page's form). Suppliers S-4 added: EntryForm.tsx (Edit entry).
  // Suppliers S-5 added: AddEntryForm.tsx (Add to catalogue).
  'src/components/suppliers': 4,
  // Purchase orders PO-3 added: NewOrderForm.tsx (the create page's form, with the supplier choice dialog).
  // Purchase orders PO-4a added: OrderHeaderForm.tsx (the draft header's editor).
  // Purchase orders PO-5 added: AddLineRow.tsx (the lines table's new line row).
  // Purchase orders PO-7 added: CancelOrderDialog.tsx (Cancel order's reason dialog).
  // Purchase orders PO-8 added: PlaceOrderDialog.tsx (Approve's confirm dialog).
  // Purchase orders PO-4b added: EditLineRow.tsx (a live line's edit row, R10-6).
  // Purchase orders GR-2 added: ReceiveForm.tsx (the receive page's form and its confirm dialog).
  'src/components/purchasing': 7,
  'src/components/roles': 4,
  // Restore role added: components/permissions/RoleRestoreActions.tsx.
  'src/components/permissions': 3,
  'src/components/bulk': 6,
  'src/components/people': 4,
  // Revision 7 step 4.1 added: WarehouseForm.tsx. Step 4.2 added: WarehouseRestoreActions.tsx. Step 4.3 added:
  // WarehouseCreateForm.tsx. Step 4.4 added: LocationForm.tsx (the code-only Edit location dialog). Step 4.5 added:
  // AddLocationForm.tsx and KindField.tsx. Revision 8 R8-3 (items I-0a) moved out: DeleteDialog.tsx and
  // WarehouseRestoreActions.tsx (now components/records/).
  'src/components/warehouses': 5,
  // Revision 8 R8-3 (items I-0a) added: DeleteDialog.tsx, RestoreActions.tsx (moved from components/warehouses/).
  'src/components/records': 2,
  // items I-0a RED M-3: per-folder counts for the folders enrolled in the scans above (contents unchanged).
  'src/components/auth': 5,
  'src/components/shell': 11,
  'src/components/states': 1,
  'src/components/theme': 2,
  'src/components/preview': 5,
  'src/lib/auth': 2,
  // Items I-1 (Revision 8 R8-2) added: lib/format/quantity.ts (renderQuantity, the one screen-side QR-2).
  // Suppliers S-3 added: lib/format/money.ts (renderMoney, the QR-6 interim's one home; Revision 9 R9-2).
  'src/lib/format': 5,
  // Revision 7 step 3 (A-19 item 1) added: TextField.tsx. Items I-2 (Revision 8 R8-5) added: SelectField.tsx.
  'src/components/ui': 13,
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
  // B-3b C7 added: lib/permissions/{load,wired}.ts; app/(app)/ArchivableList.tsx (the list both pages share; moved from admin/ by Revision 7 A-16, count unchanged),
  // app/(app)/admin/roles/{page,RolesList,RoleStates}.tsx, app/(app)/admin/roles/[id]/{page,RolePage,not-found}.tsx.
  // Purchase orders GR-2 H-1 added: lib/bff/heldKey.ts (gr-2-h1-spar.md converged text; its guard is at the end of this file).
  'src/lib/bff': 17,
  'src/lib/bff/server': 7,
  // b3b-followup C-2 added: app/error.tsx, app/(app)/error.tsx, app/not-found.tsx.
  // c10 commit B added: app/bff/me/password/route.ts; moved (count unchanged) app/account/password/{page,
  // ChangePasswordRoute}.tsx → app/(app)/account/password/ (Revision 6 A-13).
  // Session-expiry return path added: app/(app)/SessionEndedReturn.tsx.
  // Revision 7 step 2 (A-17 item 1) added: app/(app)/useArchivedAddress.ts (the list's only address writer).
  // Revision 7 step 4.1 added: app/(app)/warehouses/{page,WarehousesList,WarehouseStates}.tsx, app/(app)/warehouses/[id]/{page,WarehousePage,
  // not-found}.tsx.
  // Revision 7 step 4.3 added: app/(app)/arrivalMessage.ts, app/(app)/warehouses/new/{page,NewWarehousePage}.tsx.
  // Revision 7 step 4.4 added: app/(app)/warehouses/[id]/LocationsSection.tsx (a section of the page, no route).
  // Items I-1 added: app/(app)/items/{page,ItemsList,ItemStates}.tsx, app/(app)/items/[id]/{page,ItemPage,not-found}.tsx.
  // Items I-2 added: app/(app)/items/new/{page,NewItemPage}.tsx.
  // Suppliers S-1 added: app/(app)/suppliers/{page,SuppliersList,SupplierStates}.tsx,
  // app/(app)/suppliers/[id]/{page,SupplierPage,not-found}.tsx.
  // Suppliers S-2 added: app/(app)/suppliers/new/{page,NewSupplierPage}.tsx.
  // Suppliers S-3 added: app/(app)/suppliers/[id]/CatalogueSection.tsx (a section of the page, no route; ARCH (a)).
  // Purchase orders PO-1 added: app/(app)/useSegmentAddress.ts (Revision 10 R10-1, a segmented list's address writer),
  // app/(app)/purchase-orders/{page,PurchaseOrdersList,PurchaseOrderStates}.tsx,
  // app/(app)/purchase-orders/[id]/{page,PurchaseOrderPage,LinesSection,not-found}.tsx.
  // Purchase orders PO-3 added: app/(app)/purchase-orders/new/{page,NewPurchaseOrderPage}.tsx.
  // Goods receipts GR-1 (Revision 10 R10-2: the purchasing family's second route folder) added:
  // app/(app)/goods-receipts/{page,GoodsReceiptsList,GoodsReceiptStates}.tsx,
  // app/(app)/goods-receipts/[id]/{page,GoodsReceiptPage,not-found}.tsx, and app/(app)/purchase-orders/[id]/ReceiptsSection.tsx
  // (the order page's Receipts section, no route).
  // Purchase orders GR-2 added: app/(app)/purchase-orders/[id]/receive/{page,ReceivePage}.tsx.
  'src/app': 87,
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

  it('an inline type import is an import too (items I-0a RED M-2)', () => {
    const planted: SourceFile[] = [{ file: 'src/lib/roles/margin.ts', source: "export type R = import('@/copy/roles').RoleCopy;" }];
    expect(importViolations(planted).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      "src/lib/roles/margin.ts — lib/** never imports copy/** (Revision 4 A-6): imports '@/copy/roles'",
    ]);
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// Revision 7 A-17 item 1 (RED M-1): the list core holds its view in state and never touches the address. The one address
// writer is `useArchivedAddress.ts` beside it; a list that keeps no view in the address (a section) simply does not call it.
// Checked on module specifiers (static imports, re-exports, `import()`, `require()`), never by substring.
// ---------------------------------------------------------------------------------------------------------------------

const LIST_CORE = 'src/app/(app)/ArchivableList.tsx';

function listCoreNavigationViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    if (sf.file !== LIST_CORE) continue;
    for (const spec of specifiersOf(sf)) if (spec === 'next/navigation' || spec.startsWith('next/navigation/')) out.push({ file: sf.file, detail: `imports '${spec}'` });
  }
  return out;
}

describe('architecture — the list core never imports next/navigation (Revision 7 A-17 item 1)', () => {
  it('the real core imports no next/navigation, and the real core is the file the guard reads (not vacuous)', () => {
    const files = walk('src');
    expect(files.filter((f) => f.file === LIST_CORE)).toHaveLength(1);
    expect(files.some((f) => f.file === 'src/app/(app)/useArchivedAddress.ts' && specifiersOf(f).includes('next/navigation'))).toBe(true);
    expect(listCoreNavigationViolations(files)).toEqual([]);
  });

  it('planted: the core importing next/navigation (static or dynamic) goes red; useArchivedAddress.ts importing it does not', () => {
    const planted: SourceFile[] = [
      { file: LIST_CORE, source: "import { useRouter } from 'next/navigation';" },
      { file: LIST_CORE, source: "const nav = await import('next/navigation');" },
      { file: 'src/app/(app)/useArchivedAddress.ts', source: "import { useRouter, usePathname } from 'next/navigation';" },
    ];
    expect(listCoreNavigationViolations(planted).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      "src/app/(app)/ArchivableList.tsx — imports 'next/navigation'",
      "src/app/(app)/ArchivableList.tsx — imports 'next/navigation'",
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

  it('REFUSAL_HOMES: each home holds exactly its pinned literals (A-18 S-2), and every home exists', () => {
    for (const [file, pinned] of Object.entries(REFUSAL_HOMES)) {
      const sf = walk('src/lib/api').find((f) => f.file === file);
      expect(sf, file).toBeDefined();
      expect(exemptedLiterals(sf!), file).toEqual([...pinned]);
    }
  });

  it('problems.ts import pin (RED M-2): wired.ts may import it; a component importing it is red', () => {
    const planted: SourceFile[] = [
      { file: 'src/lib/warehouses/wired.ts', source: "import { PLATFORM_REFUSAL } from '../api/problems';" },
      { file: 'src/components/warehouses/x.tsx', source: "import { PLATFORM_REFUSAL } from '@/lib/api/problems';" },
      { file: 'src/lib/warehouses/y.ts', source: "import { PLATFORM_REFUSAL } from '../api/problems.ts';" },
      // Items I-1 (R8-4, L-3): the items family's wired.ts may import it; its other lib files and its components may not.
      { file: 'src/lib/items/wired.ts', source: "import { PLATFORM_REFUSAL } from '../api/problems';" },
      { file: 'src/lib/items/load.ts', source: "import { PLATFORM_REFUSAL } from '../api/problems';" },
      { file: 'src/components/items/ItemForm.tsx', source: "import { PLATFORM_REFUSAL } from '@/lib/api/problems';" },
    ];
    expect(importViolations(planted).map((v) => v.file)).toEqual(['src/components/warehouses/x.tsx', 'src/lib/warehouses/y.ts', 'src/lib/items/load.ts', 'src/components/items/ItemForm.tsx']);
  });

  it('REFUSAL_HOMES planted controls (§2 step 4.6): only a home\'s top-level declarations are exempt', () => {
    const planted: SourceFile[] = [
      { file: 'src/lib/api/problems.ts', source: "export const PLATFORM_REFUSAL = { contention: 'transaction.contention' } as const;\nexport function f() { return 'transaction.contention'; }" },
      { file: 'src/components/warehouses/x.tsx', source: "export const c = 'transaction.contention';" },
      { file: 'src/lib/api/warehouses.ts', source: "export const R = { held: 'warehouse.holds_stock' } as const;" },
    ];
    expect(literalViolations(planted, []).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      "src/lib/api/problems.ts — literal 'transaction.contention'",
      "src/components/warehouses/x.tsx — literal 'transaction.contention'",
      "src/lib/api/warehouses.ts — literal 'warehouse.holds_stock'",
    ]);
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

// Revision 7 step 4.1 (A-18): + src/components/warehouses. Revision 8 R8-3 (items I-0a): + src/components/records.
// items I-0a RED M-3: + auth, shell, states, theme and preview, which already passed it.
const NETWORK_SCAN_DIRS = [
  'src/components/ui',
  'src/components/roles',
  'src/components/permissions',
  'src/components/bulk',
  'src/components/people',
  'src/components/warehouses',
  'src/components/records',
  // Items I-1 (Revision 8 R8-3): the item page's editor.
  'src/components/items',
  // Suppliers S-1 (Revision 8 R8-3 completeness): the supplier page's editor.
  'src/components/suppliers',
  // Purchase orders PO-3 (Revision 8 R8-3 completeness): the create page's form, props-only (its reads are the page's).
  'src/components/purchasing',
  'src/components/auth',
  'src/components/shell',
  'src/components/states',
  'src/components/theme',
  'src/components/preview',
] as const;
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

// Revision 7 step 4.1 (A-18): + src/lib/warehouses (bulk for this family comes later).
// items I-0a RED M-3: + src/lib/{api,auth,format,forms,people,permissions}, which already passed it (src/lib/roles: see
// GUARD_EXEMPT.bulk).
const BULK_LITERAL_DIRS = [
  'src/components',
  'src/app',
  'src/lib/bulk',
  'src/lib/bff',
  'src/lib/warehouses',
  // Items I-1 (Revision 8 R8-3): the item family's logic (`inventory_item` is a bulk key, G-3).
  'src/lib/items',
  // Suppliers S-1 (suppliers-plan.md Revision 0 §1.5, G-3): `supplier` is a bulk key.
  'src/lib/suppliers',
  // Purchase orders PO-1 (purchase-orders-plan.md §1.5, G-3): `purchase_order` is a bulk key.
  'src/lib/purchasing',
  'src/lib/api',
  'src/lib/auth',
  'src/lib/format',
  'src/lib/forms',
  'src/lib/people',
  'src/lib/permissions',
] as const;
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

  // Suppliers S-1 test 13 (suppliers-plan.md Revision 0 §1.5): `supplier` is a bulk key, so lib/suppliers is scanned and
  // a typed 'supplier' there goes red; the real lib/suppliers holds none.
  it("suppliers S-1: lib/suppliers is scanned, holds no 'supplier' literal, and a planted one goes red", () => {
    expect(keys).toContain('supplier');
    const real = walk('src/lib/suppliers');
    expect(real.map((f) => f.file)).toEqual(['src/lib/suppliers/load.ts', 'src/lib/suppliers/results.ts', 'src/lib/suppliers/wired.ts']);
    expect(bulkLiteralViolations(real, keys, cap)).toEqual([]);
    const planted: SourceFile[] = [{ file: 'src/lib/suppliers/load.ts', source: "export const r = 'supplier';" }];
    expect(bulkLiteralViolations(planted, keys, cap).map((v) => `${v.file} — ${v.detail}`)).toEqual(["src/lib/suppliers/load.ts — literal 'supplier'"]);
  });

  // Purchase orders PO-1 test 13 (purchase-orders-plan.md §4 PO-1, §1.5): `purchase_order` is a bulk key, so
  // lib/purchasing is scanned and a typed 'purchase_order' there goes red; the real lib/purchasing holds none. PO-2 added
  // results.ts and wired.ts (Restore purchase order); the planted control now also covers wired.ts.
  it("purchase orders PO-1: lib/purchasing is scanned, holds no 'purchase_order' literal, and a planted one goes red", () => {
    expect(keys).toContain('purchase_order');
    const real = walk('src/lib/purchasing');
    expect(real.map((f) => f.file)).toEqual(['src/lib/purchasing/load.ts', 'src/lib/purchasing/results.ts', 'src/lib/purchasing/wired.ts']);
    expect(bulkLiteralViolations(real, keys, cap)).toEqual([]);
    const planted: SourceFile[] = [
      { file: 'src/lib/purchasing/load.ts', source: "export const r = 'purchase_order';" },
      { file: 'src/lib/purchasing/wired.ts', source: "export const r = 'purchase_order';" },
    ];
    expect(bulkLiteralViolations(planted, keys, cap).map((v) => `${v.file} — ${v.detail}`)).toEqual(["src/lib/purchasing/load.ts — literal 'purchase_order'", "src/lib/purchasing/wired.ts — literal 'purchase_order'"]);
  });

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
      // Items I-1 (G-F2 row): the item page's editor.
      // Items I-2 (G-F2 row): the create page's form.
      'src/components/items/ItemCreateForm.tsx',
      'src/components/items/ItemForm.tsx',
      // Purchase orders PO-5 (G-F2 row; plan M-9's form-attribute mechanism): the new line row's form, in its last cell;
      // the row's fields join it with the `form` attribute, which this guard does not forbid.
      'src/components/purchasing/AddLineRow.tsx',
      // Purchase orders PO-4b (G-F2 row; R10-6): each live line's edit row's form, in its last cell, as Add line's.
      'src/components/purchasing/EditLineRow.tsx',
      // Purchase orders PO-3 (G-F2 row): the create page's form (its supplier dialog is a sibling after it, never in it).
      'src/components/purchasing/NewOrderForm.tsx',
      // Purchase orders PO-4a (G-F2 row): the draft header's editor.
      'src/components/purchasing/OrderHeaderForm.tsx',
      // Purchase orders GR-2 (G-F2 row): the receive page's form (its confirm dialog is a sibling after it, never in it).
      'src/components/purchasing/ReceiveForm.tsx',
      // Suppliers S-5 (G-F2 row): Add to catalogue's dialog is a <form> (its item list sits before it, never in it).
      'src/components/suppliers/AddEntryForm.tsx',
      // Suppliers S-4 (G-F2 row): the catalogue entry's Edit dialog is a <form>, so it joins here.
      'src/components/suppliers/EntryForm.tsx',
      // Suppliers S-2 (G-F2 row): the create page's form.
      'src/components/suppliers/SupplierCreateForm.tsx',
      // Suppliers S-1 (G-F2 row): the supplier page's editor.
      'src/components/suppliers/SupplierForm.tsx',
      // Revision 7 step 4.1 (A-19 G-F2 row): the warehouse page's editor.
      // Revision 7 step 4.3: the create page's form.
      // Revision 7 step 4.4 (R3-7 "Silent", G-F2 row): the code-only Edit location dialog is a <form>, so it joins here.
      // Revision 7 step 4.5 (G-F2 row): the Add location dialog is a <form>, so it joins here (KindField.tsx is a field, no form).
      'src/components/warehouses/AddLocationForm.tsx',
      'src/components/warehouses/LocationForm.tsx',
      'src/components/warehouses/WarehouseCreateForm.tsx',
      'src/components/warehouses/WarehouseForm.tsx',
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
      // PO-5 (plan M-9): a field outside its form, joined by the `form` attribute, to a post form with no action — not a breach.
      { file: 'src/components/a/FormAttribute.tsx', source: 'export const G = () => <tr><td><input name="q" form="f1" /></td><td><form id="f1" method="post" onSubmit={f}><button type="submit">Go</button></form></td></tr>;' },
      // PO-5 (po-5-arch.md (a)): a per-control override is red wherever the control sits — here outside its form, joined by `form`.
      { file: 'src/components/a/FormAttributeOverride.tsx', source: 'export const H = () => <tr><td><input name="q" form="f1" /><button type="submit" form="f1" formMethod="get">Go</button></td><td><form id="f1" method="post" onSubmit={f}></form></td></tr>;' },
    ];
    expect(formMethodViolations(planted).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      'src/components/a/NoMethod.tsx — <form> without method="post"',
      'src/components/a/Action.tsx — action on <form>',
      'src/components/a/EncType.tsx — encType on <form>',
      'src/components/a/Button.tsx — formMethod on <button>',
      "src/app/x/page.tsx — imports 'next/form'",
      'src/components/a/FormAttributeOverride.tsx — formMethod on <button>',
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
// it. Same antecedents and the same four consequents, ported line for line (keep the two in step — a change to one is a
// change to both):
//   every page.{tsx,ts,jsx,js} under src/app gets the URL Next.js serves a static-segment page at (pageUrl). Antecedent (a): some page's URL
//   is /sales-orders or below. Antecedent (b): CreateFulfilment or GetPickList is named in a file under src outside
//   src/generated/. Either requires ALL of: a page at exactly /sales-orders/[<one param>]/fulfil; a use of GetPickList
//   outside src/generated/; and the operator's way in (BK-21; owner D-5 of 2026-11-03): (i) the spec carries
//   ListPickQueueEntries, keyed on the operationId, never on a path (`1076ba3`), and (ii) a page served at exactly
//   /pick-queue whose OWN page file (source key `app/<page>`) names that operation. Keyed on the URL (fixed by IA N-8a,
//   lib/navigation.ts and, for the queue, final review M-3), never on a guessed folder. If UX moves the queue URL, one
//   frontend commit plus its merge moves the constant here, the backend's, the IA.md §5.6 rows and both planted tables.
//   One stated difference: the backend reads the spec it regenerates; this twin reads the committed contract
//   ../contracts/openapi.json (as the G-47 twin does; `npm run surface -- --check` ties src/generated/ to that file).
//   Both BK-21 consequents are vacuous on the real tree until the screen's commit, as in the backend: the queue and the
//   screen come due together. A stub queue page that merely names the operation satisfies (ii) — the same residual the
//   GetPickList consequent carries; the screen commit's hand check covers it. PICK_ROUTE_MESSAGE below is the backend's
//   message byte for byte (synced to 19f824e; sha-checked again when the BK-21 consequents were ported).
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
/** BK-21: the queue's operationId (consequent (i)) and the token its page names (consequent (ii)). */
const QUEUE_OPERATION_ID = 'ListPickQueueEntries';
/** BK-21: the queue page's one exact URL (final review M-3: binding unless UX writes another before the commit). */
const QUEUE_PAGE_URL = '/pick-queue';
const QUEUE_OPERATION_TOKEN = new RegExp(`\\b${QUEUE_OPERATION_ID}\\b`);
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

/** As pickRouteIsDue; `specOperationIds`: the contract's operationIds (BK-21 consequent (i)). */
function pickRouteDefects(pages: readonly string[], sources: Readonly<Record<string, string>>, specOperationIds: readonly string[]): string[] {
  if (!pickRouteIsDue(pages, sources)) return [];
  const defects: string[] = [];
  if (!pages.some((page) => PICK_ROUTE_URL.test(pageUrl(page) ?? ''))) {
    defects.push('the frontend has a /sales-orders page or names a pick operation, but no page is served at exactly /sales-orders/[param]/fulfil');
  }
  if (!Object.entries(sources).some(([file, text]) => !file.startsWith('generated/') && /\bGetPickList\b/.test(text))) {
    defects.push('no frontend source outside src/generated/ uses GetPickList (the read the pick screen consumes)');
  }
  // BK-21 consequent (i): the operator's way in exists in the spec.
  if (!specOperationIds.includes(QUEUE_OPERATION_ID)) {
    defects.push(`the generated spec does not contain ${QUEUE_OPERATION_ID}`);
  }
  // BK-21 consequent (ii): a page at exactly the queue URL whose own page file names the operation.
  if (!pages.some((page) => pageUrl(page) === QUEUE_PAGE_URL && QUEUE_OPERATION_TOKEN.test(sources[`app/${page}`] ?? ''))) {
    defects.push(`no page served at exactly ${QUEUE_PAGE_URL} names ${QUEUE_OPERATION_ID}`);
  }
  return defects;
}

/** Every operationId in an OpenAPI document (the backend's builtOperationIds(), over the committed contract). */
function specOperationIdsOf(spec: Json): string[] {
  const ids: string[] = [];
  for (const item of Object.values(asObject(spec.paths))) {
    for (const [method, operation] of Object.entries(asObject(item))) {
      if (['parameters', 'summary', 'description', 'servers', '$ref'].includes(method)) continue;
      const id = asObject(operation).operationId;
      if (typeof id === 'string' && id !== '') ids.push(id);
    }
  }
  return ids;
}

/** Relative to `frontend/`: the committed contract the BK-21 consequent (i) reads. */
const CONTRACT_FILE = '../contracts/openapi.json';
const realSpecOperationIds = (): string[] => specOperationIdsOf(asObject(JSON.parse(readFileSync(path.join(ROOT, CONTRACT_FILE), 'utf8'))));

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
  "  - The operator's way in (BK-21; D-5, N-7): a page at exactly /pick-queue (its own Sales nav item, gated\n" +
  '    fulfilment.create) lists ListPickQueueEntries; a row opens the fulfil page only for a viewer who may open\n' +
  '    it (GetPickList); the fulfil page gates on GetPickList; its back link goes first to where the person came\n' +
  '    from (the queue, the order or the fulfilments list) if that page is built and they may still open it,\n' +
  '    else three-way -- GetSalesOrder -> the order, else the queue operation -> the queue, else /fulfilments if\n' +
  '    permitted, else none (D-5 as refined 2026-11-06, docs/session13/bk21-roles/f2-f7-recommendation.md).\n' +
  'Do this in the commit that builds the screen, then move this test deliberately. Until then V2-S-1b is\n' +
  'shipped IN PART and must not be called discharged.';

/** Pages under src/app allowed a dynamic first URL segment. Empty: none exists, and each one added needs a reason. */
const DYNAMIC_ROOT_PAGES_ALLOWED: readonly string[] = [];

/** Pages whose first URL segment is dynamic (`[x]`, `[...x]`, `[[...x]]`), so they may serve any first segment unseen by a check keyed on the derived URL. */
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
 * The controls that make the walk of the real tree trustworthy, shared by the three frontend tripwire twins in this file
 * (FE-R2, G-47 and G-51), as the backend's OwedAmendmentTripwireTest::frontendTree() is shared by its methods. Pure walk: no
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

// ---------------------------------------------------------------------------------------------------------------------
// Revision 8 R8-3 (items plan I-0a): the shared record dialogs, and guard enrolment (items-red.md M-2, L-6)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * What `src/components/records/` may import (R8-3): packages, the design-system primitives, the shared (non-family) copy
 * and the shared result kinds. A family's `copy/<family>.ts`, `lib/<family>/*`, `lib/api/<family>.ts` or
 * `components/<family>/*` comes in as props instead, so one family's sentence or type cannot leak into another's dialog.
 */
const RECORDS_DIR = 'src/components/records';
const RECORDS_MAY_IMPORT: readonly string[] = ['src/components/ui', 'src/copy/headings', 'src/copy/permissionLabels', 'src/copy/shell', 'src/lib/results'];

/**
 * The same rule one step down (items I-0a RED L-4): what records may import must itself name no family, or a family
 * sentence could reach a records dialog through a `ui` primitive. Every file in this closure may import only packages and
 * the closure itself (today `ui/DateTime` reads `lib/format`, and `ui/StatusMessages` reads `styles/tokens`).
 */
const SHARED_CLOSURE: readonly string[] = [...RECORDS_MAY_IMPORT, 'src/lib/format', 'src/styles'];

function sharedClosureViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    if (!SHARED_CLOSURE.some((d) => under(sf.file.replace(/\.tsx?$/, ''), d))) continue;
    for (const spec of specifiersOf(sf)) {
      if (/\.css$/.test(spec)) continue;
      const t = targetOf(sf.file, spec).replace(/\.tsx?$/, '');
      if (t.startsWith('pkg:') || SHARED_CLOSURE.some((d) => under(t, d))) continue;
      out.push({ file: sf.file, detail: `what components/records may import names no family either (R8-3, RED L-4): imports '${spec}'` });
    }
  }
  return out;
}

function recordsImportViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    if (!under(sf.file, RECORDS_DIR)) continue;
    for (const spec of specifiersOf(sf)) {
      if (/\.css$/.test(spec)) continue;
      const t = targetOf(sf.file, spec).replace(/\.tsx?$/, '');
      if (t.startsWith('pkg:') || under(t, RECORDS_DIR) || RECORDS_MAY_IMPORT.some((d) => under(t, d))) continue;
      out.push({ file: sf.file, detail: `components/records imports no family copy, lib or component (R8-3): imports '${spec}'` });
    }
  }
  return out;
}

describe('architecture — R8-3: components/records is props-only and names no family (items I-0a)', () => {
  it('the real folder holds the two moved components and imports nothing family-specific', () => {
    const files = walk(RECORDS_DIR);
    expect(files.map((f) => f.file)).toEqual(['src/components/records/DeleteDialog.tsx', 'src/components/records/RestoreActions.tsx']);
    expect(recordsImportViolations(files)).toEqual([]);
  });

  it('planted controls: a family copy, lib, api or component import goes red in every literal import spelling (a computed import() is out of reach); the shared ones do not', () => {
    const planted: SourceFile[] = [
      { file: 'src/components/records/A.tsx', source: "import { WAREHOUSE_FORBIDDEN } from '@/copy/warehouses';" },
      { file: 'src/components/records/B.tsx', source: "import type { DeleteWarehouseResult } from '@/lib/warehouses/results';" },
      { file: 'src/components/records/C.tsx', source: "import type { DeleteWarehouseResult } from '../../lib/warehouses/results.ts';" },
      { file: 'src/components/records/D.tsx', source: "import type { Warehouse } from '@/lib/api/warehouses';" },
      { file: 'src/components/records/E.tsx', source: "import { KindField } from '@/components/warehouses/KindField';" },
      { file: 'src/components/records/F.tsx', source: "export { ITEMS } from '@/copy/items';" },
      // RED M-2: an inline type import and an import-equals require.
      { file: 'src/components/records/T1.tsx', source: "type W = import('@/lib/api/warehouses').Warehouse;\nexport const w = (x: W) => x;" },
      { file: 'src/components/records/T2.tsx', source: "import W = require('@/copy/warehouses');\nexport const w = W;" },
      {
        file: 'src/components/records/G.tsx',
        source:
          "import { useState } from 'react';\nimport { Banner } from '@/components/ui/Banner';\nimport { NOT_DELETED } from '@/copy/headings';\nimport { PAGE_STATES } from '../../copy/shell';\nimport type { DeleteResult } from '@/lib/results';\nimport { RestoreActions } from './RestoreActions';",
      },
      { file: 'src/components/warehouses/H.tsx', source: "import { WAREHOUSE_FORBIDDEN } from '@/copy/warehouses';" },
    ];
    expect(recordsImportViolations(planted).map((v) => v.file)).toEqual([
      'src/components/records/A.tsx',
      'src/components/records/B.tsx',
      'src/components/records/C.tsx',
      'src/components/records/D.tsx',
      'src/components/records/E.tsx',
      'src/components/records/F.tsx',
      'src/components/records/T1.tsx',
      'src/components/records/T2.tsx',
    ]);
  });

  it('what records may import (ui, the shared copy, lib/results, and what they read) names no family either (RED L-4)', () => {
    const files = SNAPSHOT.filter((f) => f.file.startsWith('src/') && SHARED_CLOSURE.some((d) => under(f.file.replace(/\.tsx?$/, ''), d)));
    expect(files.map((f) => f.file)).toEqual(expect.arrayContaining(['src/components/ui/DateTime.tsx', 'src/copy/shell.ts', 'src/lib/results.ts', 'src/lib/format/dates.ts']));
    expect(sharedClosureViolations(files)).toEqual([]);
  });

  it('planted controls for the closure: a ui primitive or a shared copy file naming a family goes red', () => {
    const planted: SourceFile[] = [
      { file: 'src/components/ui/X.tsx', source: "import { WAREHOUSE_FORBIDDEN } from '@/copy/warehouses';" },
      { file: 'src/components/ui/Y.tsx', source: "type W = import('../../lib/api/warehouses').Warehouse;\nexport const y = (w: W) => w;" },
      { file: 'src/copy/shell.ts', source: "export { ROLE_PAGE } from './roles';" },
      { file: 'src/copy/headings.ts', source: "import type { Role } from '@/lib/api/roles';" },
      { file: 'src/lib/format/z.ts', source: "import { thing } from '@/lib/warehouses/load';" },
      { file: 'src/components/ui/Ok.tsx', source: "import { Spinner } from './Spinner';\nimport { PAGE_STATES } from '@/copy/shell';\nimport { formatDate } from '@/lib/format/dates';\nimport { tokens } from '@/styles/tokens';" },
      { file: 'src/components/warehouses/NotInClosure.tsx', source: "import { WAREHOUSE_FORBIDDEN } from '@/copy/warehouses';" },
    ];
    expect(sharedClosureViolations(planted).map((v) => v.file)).toEqual([
      'src/components/ui/X.tsx',
      'src/components/ui/Y.tsx',
      'src/copy/shell.ts',
      'src/copy/headings.ts',
      'src/lib/format/z.ts',
    ]);
  });
});

/**
 * Items I-1 (Revision 8 R8-2; items-plan.md R1-5 / M-5): a quantity is a decimal string rendered only by
 * `lib/format/quantity.ts` (QR-2), never through a number (13 + 6 digits exceed a double) and never by a fixed-scale or
 * locale formatter. The behaviour is pinned per display site in `test/wired/item-page.test.tsx`; this scan is the
 * BACKSTOP over the item dirs and the renderer. Tokens: `Number(` and `new Number(`, `BigInt(`, `Number.parse…`,
 * `parseFloat`, `parseInt`, a call whose callee is a member named `Number`, `parseFloat` or `parseInt` on anything
 * (`globalThis.Number(…)`, `window.parseFloat(…)`), `.toFixed`, `.toLocaleString`, `Intl.NumberFormat`, any `Math.<name>`
 * call (`Math.round`, `Math.trunc`, …), and a unary `+` or `-` on an identifier or member named like a quantity
 * (items-i1-red.md L-5 widened it). Limit, stated: an alias (`const N = Number`), a computed member (`x['toFixed']`),
 * binary arithmetic (`x * 1`, `x - 0`) or a quantity under another name is not seen; the per-site DOM tests are what
 * catch those.
 */
// Suppliers S-3 (suppliers-plan.md §R1-3 L-8, Revision 9 R9-2): the supplier dirs (the catalogue's minimum order is a
// served quantity, its unit cost a served decimal) and `lib/format/money.ts` join the scan.
// Purchase orders PO-1 (purchase-orders-plan.md §3 QUANTITY_SCAN_DIRS row): the purchasing dirs (order totals, line
// quantities and unit costs are served decimals); the receipt route dir joins at GR-1. PO-3: components/purchasing joins
// with its first file (plan §3 lists the three purchasing dirs; the folder exists from PO-3, so it is scanned from PO-3).
// Goods receipts GR-1: the receipt route dir joins (a receipt line's quantity is a served decimal).
const QUANTITY_SCAN_DIRS = ['src/lib/items', 'src/components/items', 'src/app/(app)/items', 'src/lib/suppliers', 'src/components/suppliers', 'src/app/(app)/suppliers', 'src/lib/purchasing', 'src/components/purchasing', 'src/app/(app)/purchase-orders', 'src/app/(app)/goods-receipts'] as const;
const QUANTITY_SCAN_FILES = ['src/lib/format/quantity.ts', 'src/lib/format/money.ts'] as const;
// Suppliers S-3 RED L-7: widened to the money names (`amount`, `unit_cost`, `unitCost`) now that `money.ts` is scanned.
const QUANTITY_NAME = /quantity|reorder_point|safety_stock|reorderPoint|safetyStock|served|amount|unit_cost|unitCost/i;

function quantityNumberViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    const tree = parse(sf);
    const visit = (node: ts.Node) => {
      const hit = (detail: string) => out.push({ file: sf.file, detail });
      if ((ts.isCallExpression(node) || ts.isNewExpression(node)) && ts.isIdentifier(node.expression)) {
        const name = node.expression.text;
        if (name === 'Number' || name === 'parseFloat' || name === 'parseInt' || name === 'BigInt') hit(`${name}(`);
      }
      if ((ts.isCallExpression(node) || ts.isNewExpression(node)) && ts.isPropertyAccessExpression(node.expression)) {
        const callee = node.expression;
        const target = ts.isIdentifier(callee.expression) ? callee.expression.text : null;
        const member = callee.name.text;
        if (member === 'Number' || member === 'parseFloat' || member === 'parseInt') hit(`.${member}(`);
        if (target === 'Math' && member !== 'round') hit(`Math.${member}(`);
      }
      if (ts.isPropertyAccessExpression(node)) {
        const target = ts.isIdentifier(node.expression) ? node.expression.text : null;
        const member = node.name.text;
        if (target === 'Number' && member.startsWith('parse')) hit('Number.parse');
        if (target === 'Intl' && member === 'NumberFormat') hit('Intl.NumberFormat');
        if (target === 'Math' && member === 'round') hit('Math.round');
        if (member === 'toFixed' || member === 'toLocaleString') hit(member);
      }
      if (ts.isPrefixUnaryExpression(node) && (node.operator === ts.SyntaxKind.PlusToken || node.operator === ts.SyntaxKind.MinusToken)) {
        const operand = node.operand;
        const named = ts.isIdentifier(operand) ? operand.text : ts.isPropertyAccessExpression(operand) ? operand.name.text : null;
        if (named !== null && QUANTITY_NAME.test(named)) hit(`unary ${node.operator === ts.SyntaxKind.PlusToken ? '+' : '-'} on ${named}`);
      }
      ts.forEachChild(node, visit);
    };
    visit(tree);
  }
  return out;
}

describe('architecture — items I-1: no quantity goes through a number (QR-2 backstop, R8-2)', () => {
  const scanned = () => [...QUANTITY_SCAN_DIRS.flatMap((d) => walk(d)), ...walk('src/lib/format').filter((f) => (QUANTITY_SCAN_FILES as readonly string[]).includes(f.file))];

  it('the item dirs and the renderer hold no such token', () => {
    const files = scanned();
    // Positive control: the scan reads the item family and the renderer, not an empty set.
    expect(files.map((f) => f.file)).toEqual(expect.arrayContaining(['src/lib/format/quantity.ts', 'src/lib/items/load.ts', 'src/components/items/ItemForm.tsx', 'src/app/(app)/items/[id]/ItemPage.tsx', 'src/components/items/ItemCreateForm.tsx', 'src/app/(app)/items/new/NewItemPage.tsx', 'src/lib/format/money.ts', 'src/lib/suppliers/load.ts', 'src/app/(app)/suppliers/[id]/CatalogueSection.tsx', 'src/components/suppliers/EntryForm.tsx', 'src/components/suppliers/AddEntryForm.tsx', 'src/lib/purchasing/load.ts', 'src/app/(app)/purchase-orders/PurchaseOrdersList.tsx', 'src/app/(app)/purchase-orders/[id]/PurchaseOrderPage.tsx', 'src/app/(app)/purchase-orders/[id]/LinesSection.tsx', 'src/components/purchasing/NewOrderForm.tsx', 'src/app/(app)/purchase-orders/new/NewPurchaseOrderPage.tsx', 'src/components/purchasing/OrderHeaderForm.tsx', 'src/components/purchasing/AddLineRow.tsx', 'src/app/(app)/goods-receipts/GoodsReceiptsList.tsx', 'src/app/(app)/goods-receipts/[id]/GoodsReceiptPage.tsx', 'src/app/(app)/purchase-orders/[id]/ReceiptsSection.tsx', 'src/components/purchasing/ReceiveForm.tsx', 'src/app/(app)/purchase-orders/[id]/receive/ReceivePage.tsx', 'src/lib/purchasing/results.ts']));
    // src/app/(app)/items: 6 at I-1; Items I-2 added new/{page,NewItemPage}.tsx → 8. Suppliers S-3 added the supplier
    // dirs (src/app/(app)/suppliers: 9 files at S-3) and lib/format/money.ts (+1). Suppliers S-4: EntryForm.tsx (the Edit
    // dialog, which holds a cost and a minimum order) is in the positive control; it is counted by EXPECTED_COUNTS.
    // Suppliers S-5: AddEntryForm.tsx (the Add dialog, the same four fields) likewise. Purchase orders PO-1: lib/purchasing
    // and the order route dir (src/app/(app)/purchase-orders: 7 files at PO-1). PO-2: lib/purchasing gains results.ts and
    // wired.ts, counted by EXPECTED_COUNTS. PO-3: components/purchasing (counted by EXPECTED_COUNTS) and the order route
    // dir's new/{page,NewPurchaseOrderPage}.tsx → 9. PO-4a: components/purchasing/OrderHeaderForm.tsx (counted by
    // EXPECTED_COUNTS) is in the positive control. PO-5: components/purchasing/AddLineRow.tsx (the new line row, which holds a
    // quantity and a cost) likewise. Goods receipts GR-1: the order route dir gains [id]/ReceiptsSection.tsx → 10, and the
    // receipt route dir joins with its 6 files. GR-2: the order route dir gains [id]/receive/{page,ReceivePage}.tsx → 12; the
    // receive form (a quantity typed and sent as text) and the zero rule (`isNotReceived`, results.ts) are in the control.
    expect(files.length).toBe(EXPECTED_COUNTS['src/lib/items']! + EXPECTED_COUNTS['src/components/items']! + 8 + 1 + EXPECTED_COUNTS['src/lib/suppliers']! + EXPECTED_COUNTS['src/components/suppliers']! + 9 + 1 + EXPECTED_COUNTS['src/lib/purchasing']! + EXPECTED_COUNTS['src/components/purchasing']! + 12 + 6);
    expect(quantityNumberViolations(files)).toEqual([]);
  });

  it('planted controls: each token goes red on its own; the renderer and plain string work do not', () => {
    const planted: SourceFile[] = [
      { file: 'src/components/items/A.tsx', source: 'export const a = (q: string) => Number(q);' },
      { file: 'src/components/items/B.tsx', source: 'export const b = (q: string) => new Number(q);' },
      { file: 'src/components/items/C.tsx', source: 'export const c = (q: string) => Number.parseFloat(q);' },
      { file: 'src/components/items/D.tsx', source: 'export const d = (q: string) => parseFloat(q);' },
      { file: 'src/components/items/E.tsx', source: 'export const e = (q: string) => parseInt(q, 10);' },
      { file: 'src/components/items/F.tsx', source: 'export const f = (q: number) => q.toFixed(6);' },
      { file: 'src/components/items/G.tsx', source: 'export const g = (q: number) => q.toLocaleString();' },
      { file: 'src/components/items/H.tsx', source: "export const h = (q: number) => new Intl.NumberFormat('en-GB').format(q);" },
      { file: 'src/components/items/I.tsx', source: 'export const i = (q: number) => Math.round(q);' },
      { file: 'src/components/items/J.tsx', source: 'export const j = (item: { reorder_point: string }) => +item.reorder_point;' },
      { file: 'src/components/items/K.tsx', source: 'export const k = (safetyStock: string) => -safetyStock;' },
      // items-i1-red.md L-5: the widened tokens.
      { file: 'src/lib/format/quantity.ts', source: 'export const t = (served: string) => String(Math.trunc(served as never));' },
      { file: 'src/components/items/L.tsx', source: 'export const l = (q: string) => globalThis.Number(q);' },
      { file: 'src/components/items/M.tsx', source: 'export const m = (q: string) => window.parseFloat(q);' },
      { file: 'src/components/items/N.tsx', source: 'export const n = (q: string) => self.parseInt(q, 10);' },
      { file: 'src/components/items/O.tsx', source: 'export const o = (q: string) => BigInt(q);' },
      { file: 'src/components/items/P.tsx', source: 'export const p = (q: number) => Math.floor(q);' },
      { file: 'src/lib/format/quantity.ts', source: 'export const renderQuantity = (served: string) => String(Number(served));' },
      // Suppliers S-3 RED L-7: a unary `+` on a money value is seen (the name pattern covers amount / unit_cost / unitCost).
      { file: 'src/lib/format/money.ts', source: 'export const r = (amount: string) => String(+amount);' },
      { file: 'src/components/items/Ok.tsx', source: "export const ok = (served: string, n: number) => served.slice(0, served.indexOf('.')) + String(n - 1);" },
    ];
    expect(quantityNumberViolations(planted).map((v) => `${v.file} — ${v.detail}`)).toEqual([
      'src/components/items/A.tsx — Number(',
      'src/components/items/B.tsx — Number(',
      // `Number.parseFloat(…)` is now seen twice: as `Number.parse…` and as a call to a member named parseFloat (L-5).
      'src/components/items/C.tsx — .parseFloat(',
      'src/components/items/C.tsx — Number.parse',
      'src/components/items/D.tsx — parseFloat(',
      'src/components/items/E.tsx — parseInt(',
      'src/components/items/F.tsx — toFixed',
      'src/components/items/G.tsx — toLocaleString',
      'src/components/items/H.tsx — Intl.NumberFormat',
      'src/components/items/I.tsx — Math.round',
      'src/components/items/J.tsx — unary + on reorder_point',
      'src/components/items/K.tsx — unary - on safetyStock',
      'src/lib/format/quantity.ts — Math.trunc(',
      'src/components/items/L.tsx — .Number(',
      'src/components/items/M.tsx — .parseFloat(',
      'src/components/items/N.tsx — .parseInt(',
      'src/components/items/O.tsx — BigInt(',
      'src/components/items/P.tsx — Math.floor(',
      'src/lib/format/quantity.ts — Number(',
      'src/lib/format/money.ts — unary + on amount',
    ]);
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// Goods receipts GR-1 — Revision 10 R10-2: the receipt pages import no other family's components or lib
// ---------------------------------------------------------------------------------------------------------------------

/** The receipt route folder (Revision 10 R10-2: the purchasing family's second folder). */
const RECEIPTS_ROUTE_DIR = 'src/app/(app)/goods-receipts';
/**
 * What a goods-receipts page may import under `src/components/` and `src/lib/`: its own family's (`purchasing`), and the
 * shared, family-free folders and files the other families' pages share. Anything else under those two roots belongs to
 * another family (items, suppliers, warehouses, people, roles, permissions, bulk, …) or to a family not built yet — a new
 * shared folder is a deliberate edit here, never a silent pass.
 */
const RECEIPTS_MAY_IMPORT = {
  components: ['purchasing', 'ui', 'states', 'records', 'shell'],
  lib: ['purchasing', 'bff', 'format', 'forms', 'results', 'routes', 'navigation', 'session'],
  libApi: ['purchasing'],
} as const;

/** R10-2's check: a goods-receipts file whose import reaches another family's `components/`, `lib/` or `lib/api/`. */
function receiptsFamilyViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    if (!under(sf.file, RECEIPTS_ROUTE_DIR)) continue;
    for (const spec of specifiersOf(sf)) {
      const t = targetOf(sf.file, spec).replace(/\.(ts|tsx|css)$/, '');
      const parts = t.split('/');
      let ok = true;
      if (parts[0] === 'src' && parts[1] === 'components') ok = (RECEIPTS_MAY_IMPORT.components as readonly string[]).includes(parts[2] ?? '');
      else if (parts[0] === 'src' && parts[1] === 'lib' && parts[2] === 'api') ok = (RECEIPTS_MAY_IMPORT.libApi as readonly string[]).includes(parts[3] ?? '');
      else if (parts[0] === 'src' && parts[1] === 'lib') ok = (RECEIPTS_MAY_IMPORT.lib as readonly string[]).includes(parts[2] ?? '');
      if (!ok) out.push({ file: sf.file, detail: `a goods-receipts page imports another family's components or lib (R10-2): '${spec}'` });
    }
  }
  return out;
}

describe('architecture — goods receipts GR-1: R10-2, the receipt pages stay inside the purchasing family', () => {
  it('the real receipt folder (6 files) imports only purchasing and shared components/lib', () => {
    const files = walk(RECEIPTS_ROUTE_DIR);
    expect(files.map((f) => f.file)).toEqual([
      'src/app/(app)/goods-receipts/GoodsReceiptStates.tsx',
      'src/app/(app)/goods-receipts/GoodsReceiptsList.tsx',
      'src/app/(app)/goods-receipts/[id]/GoodsReceiptPage.tsx',
      'src/app/(app)/goods-receipts/[id]/not-found.tsx',
      'src/app/(app)/goods-receipts/[id]/page.tsx',
      'src/app/(app)/goods-receipts/page.tsx',
    ]);
    // Positive control: the check sees the folder's real family and shared imports and accepts them (not an empty domain).
    const targets = files.flatMap((f) => specifiersOf(f).map((s) => targetOf(f.file, s)));
    expect(targets).toEqual(expect.arrayContaining(['src/lib/purchasing/load', 'src/lib/api/purchasing', 'src/components/ui/DateTime', 'src/lib/bff/surface', 'src/lib/format/quantity']));
    expect(receiptsFamilyViolations(files)).toEqual([]);
  });

  it('planted controls: another family’s lib, components or api goes red in each spelling; its own and the shared ones do not', () => {
    const planted: SourceFile[] = [
      { file: 'src/app/(app)/goods-receipts/A.tsx', source: "import { loadItem } from '@/lib/items/load';" },
      { file: 'src/app/(app)/goods-receipts/B.tsx', source: "import { EntryForm } from '@/components/suppliers/EntryForm';" },
      { file: 'src/app/(app)/goods-receipts/C.tsx', source: "import type { Warehouse } from '@/lib/api/warehouses';" },
      { file: 'src/app/(app)/goods-receipts/[id]/D.tsx', source: "import { loadSuppliers } from '../../../../lib/suppliers/load';" },
      { file: 'src/app/(app)/goods-receipts/E.tsx', source: "export type P = import('@/lib/people/load').PersonLoad;" },
      { file: 'src/app/(app)/goods-receipts/Ok.tsx', source: "import { loadReceipts } from '@/lib/purchasing/load';\nimport { Button } from '@/components/ui/Button';\nimport { renderQuantity } from '@/lib/format/quantity';\nimport type { GoodsReceipt } from '@/lib/api/purchasing';\nimport { ArchivableList } from '../ArchivableList';" },
      // Outside the folder the rule does not apply (the order page's section is in the purchase-orders folder).
      { file: 'src/app/(app)/items/Z.tsx', source: "import { loadItem } from '@/lib/items/load';" },
    ];
    expect(receiptsFamilyViolations(planted).map((v) => v.file)).toEqual([
      'src/app/(app)/goods-receipts/A.tsx',
      'src/app/(app)/goods-receipts/B.tsx',
      'src/app/(app)/goods-receipts/C.tsx',
      'src/app/(app)/goods-receipts/[id]/D.tsx',
      'src/app/(app)/goods-receipts/E.tsx',
    ]);
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// Revision 10 R10-2 (amended, gr-1-followups-arch.md item 2 / A-3, as corrected by gr-1-followups-red.md L-4, L-5): route
// folders import only their own family's route folders and the shared (app)-root modules; nothing under src/app outside
// (app)/ is imported from (app)/ at all. CSS modules included (one of the two purchasing edges is a stylesheet), so unlike
// importViolations this check does not skip `.css` specifiers.
// ---------------------------------------------------------------------------------------------------------------------

const APP_ROOT = 'src/app/(app)';
/** Families that own more than one route folder (R10-2). A new entry is a deliberate edit and needs an architecture line. */
const ROUTE_FAMILIES: readonly (readonly string[])[] = [['purchase-orders', 'goods-receipts']];
/** The route folder a path is in: the first folder under (app)/ (`admin/<folder>` for the admin pages); null for an (app)-root module. */
function routeFolderOf(p: string): string | null {
  if (!under(p, APP_ROOT)) return null;
  const parts = p.slice(APP_ROOT.length + 1).split('/');
  if (parts.length === 1) return null;
  return parts[0] === 'admin' ? `admin/${parts[1]}` : parts[0]!;
}
const sameFamily = (a: string, b: string) => a === b || ROUTE_FAMILIES.some((f) => f.includes(a) && f.includes(b));
/** A specifier naming a directory under (app)/ means that directory's index file (a barrel; gr-1b-red M-1). */
const isDirIn = (files: readonly SourceFile[], t: string) => files.some((f) => f.file.startsWith(`${t}/`));
const folderOfTarget = (files: readonly SourceFile[], t: string) => (isDirIn(files, t) ? routeFolderOf(`${t}/index.ts`) : routeFolderOf(t));

function routeFolderViolations(files: readonly SourceFile[]): Violation[] {
  const out: Violation[] = [];
  for (const sf of files) {
    if (!under(sf.file, APP_ROOT)) continue;
    const from = routeFolderOf(sf.file);
    for (const spec of specifiersOf(sf)) {
      const t = targetOf(sf.file, spec);
      if (!under(t, 'src/app')) continue;
      const to = folderOfTarget(files, t);
      let ok: boolean;
      if (!under(t, APP_ROOT)) ok = false; // src/app outside (app)/: (auth), (dev), bff, fonts, the root layout — from any (app) file
      else if (from === null) ok = to === null; // an (app)-root module imports no route folder
      else ok = to === null || sameFamily(from, to);
      if (!ok) out.push({ file: sf.file, detail: `a route folder imports another family's route folder, or an (app) file imports a route folder it may not or src/app outside (app)/ (R10-2): '${spec}'` });
    }
  }
  return out;
}

describe('architecture — R10-2: route folders import only their own family’s route folders and the (app)-root modules', () => {
  it('the real (app) tree has no violation, and the two purchasing edges are really seen (not an empty domain)', () => {
    const files = walk('src/app');
    // Distinct pairs (RED L-5): a second import statement between the same two files is not a new edge.
    const edges = [
      ...new Set(
        files
          .flatMap((f) => specifiersOf(f).map((s) => `${f.file} -> ${targetOf(f.file, s)}`))
          .filter((e) => {
            const [a, b] = e.split(' -> ') as [string, string];
            return routeFolderOf(a) !== null && folderOfTarget(files, b) !== null && routeFolderOf(a) !== folderOfTarget(files, b);
          }),
      ),
    ].sort();
    // An exact pin: a third cross-folder edge, even inside the family, turns this red on purpose, so whoever adds it records it.
    expect(edges).toEqual([
      'src/app/(app)/goods-receipts/[id]/GoodsReceiptPage.tsx -> src/app/(app)/purchase-orders/PurchaseOrders.module.css',
      'src/app/(app)/purchase-orders/[id]/ReceiptsSection.tsx -> src/app/(app)/goods-receipts/GoodsReceiptsList',
    ]);
    expect(routeFolderViolations(files)).toEqual([]);
  });

  it('planted controls: each clause goes red; its own family both ways, its own folder and the (app)-root modules do not', () => {
    const planted: SourceFile[] = [
      { file: 'src/app/(app)/goods-receipts/F.tsx', source: "import '../items/ItemsList';" }, // gr-1-red P-1 / L-9
      { file: 'src/app/(app)/goods-receipts/[id]/G.tsx', source: "import styles from '@/app/(app)/suppliers/Suppliers.module.css';" }, // CSS counts
      { file: 'src/app/(app)/purchase-orders/H.tsx', source: "export type W = import('../warehouses/WarehousesList').X;" },
      { file: 'src/app/(app)/admin/users/I.tsx', source: "import { RolesList } from '../roles/RolesList';" }, // admin/<x> are separate folders
      { file: 'src/app/(app)/items/J.tsx', source: "import { LoginRoute } from '@/app/(auth)/login/LoginRoute';" }, // outside (app)/
      { file: 'src/app/(app)/K.tsx', source: "import { ItemsList } from './items/ItemsList';" }, // (app)-root → route folder
      { file: 'src/app/(app)/L.tsx', source: "import { x } from '../fonts';" }, // (app)-root → src/app outside (app)/ (RED L-4)
      { file: 'src/app/(app)/items/index.ts', source: "export * from './ItemsList';" }, // allowed: its own folder
      { file: 'src/app/(app)/goods-receipts/B.tsx', source: "import { ItemsList } from '../items';" }, // a barrel import of another family (gr-1b-red M-1)
      // Allowed: own family both ways, own folder, (app)-root modules, other roots.
      { file: 'src/app/(app)/goods-receipts/Ok.tsx', source: "import s from '../purchase-orders/PurchaseOrders.module.css';\nimport { ArchivableList } from '../ArchivableList';\nimport { GoodsReceiptsDenied } from './GoodsReceiptStates';\nimport { loadReceipts } from '@/lib/purchasing/load';" },
      { file: 'src/app/(app)/purchase-orders/[id]/Ok2.tsx', source: "import { receiptColumns } from '../../goods-receipts/GoodsReceiptsList';" },
    ];
    expect(routeFolderViolations(planted).map((v) => v.file)).toEqual([
      'src/app/(app)/goods-receipts/F.tsx',
      'src/app/(app)/goods-receipts/[id]/G.tsx',
      'src/app/(app)/purchase-orders/H.tsx',
      'src/app/(app)/admin/users/I.tsx',
      'src/app/(app)/items/J.tsx',
      'src/app/(app)/K.tsx',
      'src/app/(app)/L.tsx',
      'src/app/(app)/goods-receipts/B.tsx',
    ]);
  });
});

// Goods receipts GR-1 (purchase-orders-plan.md §4 GR-1: "no money rendered on any receipt surface"; UX D-6), Revision 10
// A-5 (gr-1-followups-arch.md item 6, as corrected by gr-1-followups-red.md M-1): no receipt surface — the receipt folder and
// the order page's Receipts section, and the receive page (`purchase-orders/[id]/receive/`, `components/purchasing/ReceiveForm.tsx`;
// GR-2, UX D-6; delete-swap-arch-red.md) — reaches `lib/format/money.ts` through ANY chain of imports. This holds the money
// helper only; the receipt wire types carrying no cost is held by `wireParity.test.ts`.
const RECEIPT_SURFACES = (files: readonly SourceFile[]) => files.filter((f) => under(f.file, RECEIPTS_ROUTE_DIR) || f.file === 'src/app/(app)/purchase-orders/[id]/ReceiptsSection.tsx' || under(f.file, 'src/app/(app)/purchase-orders/[id]/receive') || f.file === 'src/components/purchasing/ReceiveForm.tsx');
const MONEY = 'src/lib/format/money.ts';
/**
 * Every src file reachable from `from` through any import kind specifiersOf sees (type imports included: conservative).
 * An import that resolves to no scanned file throws: a silent unresolved import would be a hole in the closure.
 */
function reachableFrom(files: readonly SourceFile[], from: SourceFile): Set<string> {
  const byFile = new Map(files.map((f) => [f.file, f] as const)); // later entries (planted) override real ones
  const seen = new Set<string>();
  const stack = [from];
  while (stack.length > 0) {
    const sf = stack.pop()!;
    for (const spec of specifiersOf(sf)) {
      const t = targetOf(sf.file, spec);
      if (t.startsWith('pkg:') || /\.css$/.test(t)) continue;
      const hit = [t, `${t}.ts`, `${t}.tsx`, `${t}/index.ts`, `${t}/index.tsx`].map((c) => byFile.get(c)).find((x) => x !== undefined);
      if (hit === undefined) throw new Error(`reachableFrom: ${sf.file} imports '${spec}', which resolves to no scanned file`);
      if (!seen.has(hit.file)) {
        seen.add(hit.file);
        stack.push(hit);
      }
    }
  }
  return seen;
}
const moneyOnReceipts = (files: readonly SourceFile[]) => RECEIPT_SURFACES(files).filter((f) => reachableFrom(files, f).has(MONEY)).map((f) => f.file);

describe('architecture — goods receipts GR-1: no money reachable from any receipt surface', () => {
  it('no receipt surface reaches lib/format/money through any chain of imports (10 surfaces)', () => {
    const files = walk('src'); // the closure leaves src/app
    expect(RECEIPT_SURFACES(files).length).toBe(10);
    expect(moneyOnReceipts(files)).toEqual([]);
    // Positive controls on real files: the closure is not empty, and it really follows chains.
    const section = files.find((f) => f.file === 'src/app/(app)/purchase-orders/[id]/ReceiptsSection.tsx')!;
    expect([...reachableFrom(files, section)]).toEqual(expect.arrayContaining(['src/app/(app)/goods-receipts/GoodsReceiptsList.tsx', 'src/lib/purchasing/load.ts', 'src/app/(app)/ArchivableList.tsx']));
    const orderPage = files.find((f) => f.file === 'src/app/(app)/purchase-orders/[id]/page.tsx')!;
    expect(specifiersOf(orderPage).map((s) => targetOf(orderPage.file, s))).not.toContain('src/lib/format/money'); // not a direct import…
    expect(reachableFrom(files, orderPage).has(MONEY)).toBe(true); // …yet reached (page → PurchaseOrderPage → money)
    expect([...reachableFrom(files, files.find((f) => f.file === 'src/app/(app)/purchase-orders/[id]/receive/page.tsx')!)]).toContain('src/components/purchasing/ReceiveForm.tsx');
  });

  /**
   * Plants never reuse a real file's path: a planted file replaces the real one for every importer, so a real surface
   * importing it would reach money through the plant.
   */
  it('planted controls: a direct import, a components hop, a route-folder hop and a two-hop chain go red; family logic does not', () => {
    const real = walk('src');
    const planted: SourceFile[] = [
      { file: 'src/app/(app)/goods-receipts/[id]/Direct.tsx', source: "import { renderMoney } from '@/lib/format/money';" },
      { file: 'src/components/purchasing/CostCell.tsx', source: "import { renderMoney } from '@/lib/format/money';" },
      { file: 'src/app/(app)/goods-receipts/X.tsx', source: "import { CostCell } from '@/components/purchasing/CostCell';" },
      { file: 'src/app/(app)/goods-receipts/Y.tsx', source: "import { LinesSection } from '../purchase-orders/[id]/LinesSection';" }, // real file, real money import
      { file: 'src/lib/purchasing/costText.ts', source: "import { renderMoney } from '@/lib/format/money';" },
      { file: 'src/components/purchasing/Two.tsx', source: "import { costText } from '@/lib/purchasing/costText';" },
      { file: 'src/app/(app)/goods-receipts/W.tsx', source: "import { Two } from '@/components/purchasing/Two';" },
      { file: 'src/app/(app)/goods-receipts/Z.tsx', source: "import { loadReceipts } from '@/lib/purchasing/load';\nimport { ArchivableList } from '../ArchivableList';" },
      { file: 'src/app/(app)/purchase-orders/[id]/receive/Direct2.tsx', source: "import { renderMoney } from '@/lib/format/money';" },
    ];
    expect(moneyOnReceipts([...real, ...planted]).sort()).toEqual(['src/app/(app)/goods-receipts/W.tsx', 'src/app/(app)/goods-receipts/X.tsx', 'src/app/(app)/goods-receipts/Y.tsx', 'src/app/(app)/goods-receipts/[id]/Direct.tsx', 'src/app/(app)/purchase-orders/[id]/receive/Direct2.tsx']);
  });
});

/**
 * Guard enrolment (items-red.md M-2): LITERAL_SCAN_DIRS, NETWORK_SCAN_DIRS (component dirs only), BULK_LITERAL_DIRS and
 * EXPECTED_COUNTS are hand lists, so a new folder under `src/components` or `src/lib` used to be unscanned unless someone
 * remembered it. Every such folder must now be enrolled in each applicable list, or named below with the fact that keeps
 * it out (the scan would be red for a reason that is not a defect). A reason shorter than ten characters is a gap, and a
 * stale exemption is red too. Limit, stated: the domain is the direct folders of `src/components` and `src/lib`; loose
 * files there (`src/lib/results.ts`, `src/lib/routes.ts`, …) and other top-level `src/*` folders are outside it.
 */
type GuardList = 'literal' | 'network' | 'bulk' | 'counts';
const GUARD_EXEMPT: Readonly<Record<GuardList, Readonly<Record<string, string>>>> = {
  literal: {
    'src/components/preview': 'red if scanned: its fixtures carry the built-in role names by design (the BUILT_IN_ROLE_NAMES header this backstop reads)',
  },
  network: {},
  bulk: {
    'src/lib/roles': 'red if scanned: lib/roles/load.ts PENDING_WALK_MAX_PAGES = 50 equals the bulk cap, and the guard cannot tell a page cap from the bulk cap',
  },
  counts: {},
};

/** The folders each list applies to: every direct sub-folder of `src/components` and `src/lib` (network: components only). */
function guardedFolders(entries: readonly DiskEntry[]): string[] {
  return entries.filter((e) => e.dir && /^src\/(components|lib)\/[^/]+$/.test(e.file)).map((e) => e.file);
}

interface GuardLists {
  literal: readonly string[];
  network: readonly string[];
  bulk: readonly string[];
  counts: readonly string[];
}

/** Every folder missing from an applicable list without an exemption, and every exemption that is stale. */
function enrolmentGaps(folders: readonly string[], lists: GuardLists, exempt: typeof GUARD_EXEMPT): string[] {
  const out: string[] = [];
  const covered: Record<GuardList, (d: string) => boolean> = {
    literal: (d) => lists.literal.includes(d),
    network: (d) => lists.network.includes(d),
    bulk: (d) => lists.bulk.some((b) => under(d, b)),
    counts: (d) => lists.counts.includes(d),
  };
  for (const list of ['literal', 'network', 'bulk', 'counts'] as const) {
    const applies = folders.filter((d) => list !== 'network' || under(d, 'src/components'));
    for (const d of applies) {
      const reason = exempt[list][d];
      if (!covered[list](d)) {
        if (reason === undefined) out.push(`${list}: ${d} is in no list and has no exemption`);
        else if (reason.trim().length < 10) out.push(`${list}: ${d}'s exemption has no reason`);
      }
      if (covered[list](d) && reason !== undefined) out.push(`${list}: ${d} is enrolled, so its exemption is stale`);
    }
    for (const d of Object.keys(exempt[list])) {
      if (!applies.includes(d)) out.push(`${list}: exemption for ${d}, which is not a folder this list applies to`);
    }
  }
  return out;
}

const REAL_GUARD_LISTS: GuardLists = {
  literal: LITERAL_SCAN_DIRS,
  network: NETWORK_SCAN_DIRS,
  bulk: BULK_LITERAL_DIRS,
  counts: Object.keys(EXPECTED_COUNTS),
};

describe('architecture — guard enrolment is complete (items-red.md M-2, items I-0a)', () => {
  it('every folder under src/components and src/lib is in each applicable guard list, or exempt with a reason', () => {
    const folders = guardedFolders(ALL_SRC_ENTRIES);
    expect(folders).toEqual(expect.arrayContaining(['src/components/records', 'src/components/warehouses', 'src/lib/warehouses', 'src/components/items', 'src/lib/items', 'src/components/suppliers', 'src/lib/suppliers']));
    expect(folders.length).toBeGreaterThan(15);
    expect(enrolmentGaps(folders, REAL_GUARD_LISTS, GUARD_EXEMPT)).toEqual([]);
  });

  it('planted controls: an unlisted new folder, a dropped enrolment and a stale exemption all go red', () => {
    const folders = guardedFolders(ALL_SRC_ENTRIES);
    // A new folder nobody enrolled. It was the items family's folders until I-1 enrolled them, then the suppliers family's
    // until S-1 enrolled those; now a family that does not exist yet (customers), so the control still names folders
    // absent from every list.
    expect(enrolmentGaps([...folders, 'src/components/customers', 'src/lib/customers'], REAL_GUARD_LISTS, GUARD_EXEMPT)).toEqual([
      'literal: src/components/customers is in no list and has no exemption',
      'literal: src/lib/customers is in no list and has no exemption',
      'network: src/components/customers is in no list and has no exemption',
      'bulk: src/lib/customers is in no list and has no exemption',
      'counts: src/components/customers is in no list and has no exemption',
      'counts: src/lib/customers is in no list and has no exemption',
    ]);
    // records/ dropped from one list (the move silently unscanning the dialogs, M-2's last bullet).
    const dropped: GuardLists = { ...REAL_GUARD_LISTS, network: NETWORK_SCAN_DIRS.filter((d) => d !== RECORDS_DIR) };
    expect(enrolmentGaps(folders, dropped, GUARD_EXEMPT)).toEqual(['network: src/components/records is in no list and has no exemption']);
    // An exemption with no reason (an empty or near-empty string) is a gap, not an exemption (RED L-1).
    const unreasoned = { ...GUARD_EXEMPT, literal: { ...GUARD_EXEMPT.literal, 'src/components/customers': '' }, bulk: { ...GUARD_EXEMPT.bulk, 'src/lib/customers': '  tbd  ' } };
    expect(enrolmentGaps([...folders, 'src/components/customers', 'src/lib/customers'], REAL_GUARD_LISTS, unreasoned)).toEqual([
      "literal: src/components/customers's exemption has no reason",
      'literal: src/lib/customers is in no list and has no exemption',
      'network: src/components/customers is in no list and has no exemption',
      "bulk: src/lib/customers's exemption has no reason",
      'counts: src/components/customers is in no list and has no exemption',
      'counts: src/lib/customers is in no list and has no exemption',
    ]);
    // An exemption left behind after enrolment, and one naming a folder that does not exist.
    const stale = { ...GUARD_EXEMPT, literal: { ...GUARD_EXEMPT.literal, 'src/components/records': 'x', 'src/lib/nowhere': 'y' } };
    expect(enrolmentGaps(folders, REAL_GUARD_LISTS, stale)).toEqual([
      'literal: src/components/records is enrolled, so its exemption is stale',
      'literal: exemption for src/lib/nowhere, which is not a folder this list applies to',
    ]);
  });
});

describe('architecture — FE-R2: no /sales-orders screen or pick operation without the pick screen (twin of the backend R8 check)', () => {
  it('planted controls: the predicate trips and passes on synthetic trees exactly as the backend check does', () => {
    const uses = { 'lib/api/pick.ts': "viewerMay('GetPickList')" };
    // BK-21: every tree states its spec set (default: with the queue); a due tree that passes also carries the queue
    // page, its source keyed `app/<page>` as realSources() keys it.
    const withQueue = [QUEUE_OPERATION_ID, 'GetPickList', 'CreateFulfilment'];
    const withoutQueue = ['GetPickList', 'CreateFulfilment'];
    const queuePage = '(app)/pick-queue/page.tsx';
    const queueUses = { ...uses, [`app/${queuePage}`]: `viewerMay('${QUEUE_OPERATION_ID}')` };
    const due = ['(app)/sales-orders/page.tsx', '(app)/sales-orders/[id]/fulfil/page.tsx'];
    const planted: [string, string[], Record<string, string>, boolean, string[]?][] = [
      ['a sales-orders list alone', ['(app)/sales-orders/page.tsx'], uses, false],
      ['the list and the fulfil page', ['(app)/sales-orders/page.tsx', '(app)/sales-orders/[id]/fulfil/page.tsx', queuePage], queueUses, true],
      ['the fulfil page under a nested group', ['(app)/sales-orders/[id]/(actions)/fulfil/page.tsx', queuePage], queueUses, true],
      ['the fulfil page under a parallel slot', ['(app)/sales-orders/[id]/@panel/fulfil/page.tsx', queuePage], queueUses, true],
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
      // BK-21's two consequents (design R4; final review M-3), mirrored from the backend's planted set, same names and
      // verdicts. Each row below is due unless it says otherwise.
      ['the queue page names the queue operation', [...due, queuePage], queueUses, true, withQueue],
      ['the queue operation named only on the fulfilments page', [...due, '(app)/fulfilments/page.tsx'], { ...uses, 'app/(app)/fulfilments/page.tsx': QUEUE_OPERATION_ID }, false, withQueue],
      ['the queue page at the wrong URL', [...due, '(app)/admin/pick-queue/page.tsx'], { ...uses, 'app/(app)/admin/pick-queue/page.tsx': QUEUE_OPERATION_ID }, false, withQueue],
      ['the queue page source keyed without app/', [...due, queuePage], { ...uses, [queuePage]: QUEUE_OPERATION_ID }, false, withQueue],
      ['the spec without the queue operation', [...due, queuePage], queueUses, false, withoutQueue],
      ['no queue page', due, uses, false, withQueue],
      ['the queue operation only in generated code', [...due, queuePage], { ...uses, 'generated/api-surface.server.ts': QUEUE_OPERATION_ID }, false, withQueue],
      ['the queue operation only in a component', [...due, queuePage], { ...uses, 'components/PickQueue.tsx': QUEUE_OPERATION_ID }, false, withQueue],
      ['the queue page in a private folder', [...due, '(app)/_wip/pick-queue/page.tsx'], { ...uses, 'app/(app)/_wip/pick-queue/page.tsx': QUEUE_OPERATION_ID }, false, withQueue],
      ['the queue page in a component preview', [...due, '(dev)/component-preview/pick-queue/page.tsx'], { ...uses, 'app/(dev)/component-preview/pick-queue/page.tsx': QUEUE_OPERATION_ID }, false, withQueue],
      ['the queue page under a route group', [...due, '(app)/(ops)/pick-queue/page.tsx'], { ...uses, 'app/(app)/(ops)/pick-queue/page.tsx': QUEUE_OPERATION_ID }, true, withQueue],
      ['not due, no queue page, spec without the queue operation', ['(app)/admin/users/page.tsx'], {}, true, withoutQueue],
      // Twin-only so far (owed to the backend's planted set, filed via owed-to-backend.md BK-21): "exactly /pick-queue"
      // refuses a page below it or at another case; the queue operation is matched as a whole word in the page and as
      // an exact operationId in the spec.
      ['the queue page one level too deep', [...due, '(app)/pick-queue/x/page.tsx'], { ...uses, 'app/(app)/pick-queue/x/page.tsx': QUEUE_OPERATION_ID }, false, withQueue],
      ['the queue page names only a longer identifier', [...due, queuePage], { ...uses, [`app/${queuePage}`]: `${QUEUE_OPERATION_ID}Summary` }, false, withQueue],
      ['the queue page at another case', [...due, '(app)/Pick-Queue/page.tsx'], { ...uses, 'app/(app)/Pick-Queue/page.tsx': QUEUE_OPERATION_ID }, false, withQueue],
      ['the spec holds only a longer operationId', [...due, queuePage], queueUses, false, [`${QUEUE_OPERATION_ID}V2`, 'GetPickList', 'CreateFulfilment']],
    ];
    const verdicts = planted.map(([name, pages, sources, , spec]) => [name, pickRouteDefects(pages, sources, spec ?? withQueue).length === 0]);
    expect(verdicts).toEqual(planted.map(([name, , , passes]) => [name, passes]));
    expect(pickRouteDefects(['(app)/sales-orders/page.tsx'], {}, withQueue)).toEqual([
      'the frontend has a /sales-orders page or names a pick operation, but no page is served at exactly /sales-orders/[param]/fulfil',
      'no frontend source outside src/generated/ uses GetPickList (the read the pick screen consumes)',
      'no page served at exactly /pick-queue names ListPickQueueEntries',
    ]);
    // The backend's defect texts, all four in its order.
    expect(pickRouteDefects(['(app)/sales-orders/page.tsx'], {}, withoutQueue)).toEqual([
      'the frontend has a /sales-orders page or names a pick operation, but no page is served at exactly /sales-orders/[param]/fulfil',
      'no frontend source outside src/generated/ uses GetPickList (the read the pick screen consumes)',
      'the generated spec does not contain ListPickQueueEntries',
      'no page served at exactly /pick-queue names ListPickQueueEntries',
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

    // BK-21 real-tree controls (design R4, L-5): the source map keys a page file as `app/<page>`, so consequent (ii)'s
    // lookup has a real subject; and the contract read for consequent (i) really yields the spec's operationIds.
    expect(realPages(), 'Real-tree control: the known page (app)/page.tsx is gone; pick another.').toContain('(app)/page.tsx');
    expect(Object.keys(realSources()), 'Real-tree control: realSources() no longer keys page sources as app/<page>, so consequent (ii) reads nothing.').toContain('app/(app)/page.tsx');
    expect(existsSync(path.join(ROOT, CONTRACT_FILE)), `Positive control: ${CONTRACT_FILE} is not where consequent (i) looks, so it reads nothing.`).toBe(true);
    expect(realSpecOperationIds(), `Positive control: the operationIds read from ${CONTRACT_FILE} no longer include GetPickList, so consequent (i) reads the wrong list or none.`).toContain('GetPickList');
  });

  it('the real tree: a /sales-orders page or a pick operation brings the pick screen with it', () => {
    const defects = pickRouteDefects(realPages(), realSources(), realSpecOperationIds());
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
      // Items I-1 (plan §2(g), §4 I-1): an item page that reads stock is a stock screen while G-47 is open.
      ['an item page naming the stock read', ['(app)/items/[id]/page.tsx'], { 'app/(app)/items/[id]/ItemPage.tsx': "read('ListStockLevels')" }, [], true],
      ['an item page linking to the stock path', ['(app)/items/[id]/page.tsx'], { 'app/(app)/items/[id]/ItemPage.tsx': "fetch('/bff/api/stock?item=1')" }, [], true],
      ['the item pages as built (no stock read, no stock path)', ['(app)/items/page.tsx', '(app)/items/[id]/page.tsx'], { 'app/(app)/items/[id]/ItemPage.tsx': "loadItem(read, id); renderQuantity(item.reorder_point)" }, ['ListItems', 'GetItem'], false],
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

// ---------------------------------------------------------------------------------------------------------------------
// G-51: no stock count or transfer in the web app while G-51 is open — twin of the backend's
// OwedAmendmentTripwireTest::noFrontendCountsOrMovesStockWhileG51IsOpen (items I-3; items-i3-design.md Revision 1 —
// FINAL §1.2 and Revision 0 §6, as corrected by items-i3-red.md "Final RED — on Revision 1" M-2; items-i3-po-sec.md I3-1).
// ---------------------------------------------------------------------------------------------------------------------
// G-51 (docs/RESIDUAL_GAPS.md, Low; owner PO): an item can be archived while it holds stock, and that stock then cannot
// be counted or moved between locations until the item is restored. G-51 is NOT closed and nothing here may be read as
// closing it. Its acceptance for the web app rests on the web app being unable to count or move stock for ANY item.
// The trigger T (outside src/generated/): (a) source naming CreateStockAdjustment or CreateStockTransfer (word
// boundaries); (b) the client wiring list wiring either; (c) a page with a URL segment stock-counts, stock-adjustments or
// stock-transfers, or a segment count or transfer under /stock (IA.md:205-206 puts counts and transfers on /stock as row
// actions, not routes: arm (c) can only catch a deviation from the IA; (a), (b) and (d) are the arms that catch the
// planned screen); (d) source naming /stock-movements/adjustments or /stock-movements/transfers under any prefix, never
// /stock-movements alone (the movements read). T must stay false while G-51 is open. Known limits, as the backend's: a
// name or path built by concatenation or a template literal evades (a) and (d); a comment naming a token fires them (loud
// and safe); a route word other than the five evades (c); a source behind a symlinked directory is refused by the walk.
// The message is held equal to the backend's by the parity test below (it reads ../backend/, the ../contracts/ precedent),
// so the two cannot drift apart unseen. Ported from origin/main 119adb5, which carries E-6 ("removes no stock ability",
// re-wrapped; items-i3-po-sec.md I3-8): backend message sha256
// 73ef349573ef76765d08fde88c033402ca560cce6321463077ae1b1d54e74c04. A later backend change to the message turns the parity test red until it is re-ported here.

/** G-51 trigger (a): the count's and the transfer's operationIds. Collected with a fresh /g in matchAll, never .test() on a shared /g. */
const STOCK_COUNT_OR_MOVE_OPERATION_TOKEN = '\\b(CreateStockAdjustment|CreateStockTransfer)\\b';
/** G-51 trigger (d): the two operations' paths, under any prefix; never `/stock-movements` alone. */
const STOCK_COUNT_OR_MOVE_PATH_TOKEN = /\/stock-movements\/(?:adjustments|transfers)(?![-A-Za-z0-9_])/;
/** G-51 trigger (c): a URL segment anywhere that names a count or transfer screen. */
const STOCK_COUNT_OR_MOVE_SEGMENTS: readonly string[] = ['stock-counts', 'stock-adjustments', 'stock-transfers'];
/** G-51 trigger (c): a URL segment under `/stock` that names a count or transfer screen. */
const STOCK_COUNT_OR_MOVE_SEGMENTS_UNDER_STOCK: readonly string[] = ['count', 'transfer'];
const STOCK_COUNT_OR_MOVE_OPERATIONS = ['CreateStockAdjustment', 'CreateStockTransfer'] as const;

/** One line per reason G-51's frontend trigger is true; empty when it is not. Pages first, then sources (byte order), then the client wiring list. */
function stockCountOrMoveTriggers(pages: readonly string[], sources: Readonly<Record<string, string>>, clientOps: readonly unknown[]): string[] {
  const lines: string[] = [];
  const screens = pages.filter((page) => {
    const url = pageUrl(page);
    if (url === null) return false;
    const segments = url.split('/').slice(1);
    if (segments.some((s) => STOCK_COUNT_OR_MOVE_SEGMENTS.includes(s))) return true;
    return segments[0] === 'stock' && segments.slice(1).some((s) => STOCK_COUNT_OR_MOVE_SEGMENTS_UNDER_STOCK.includes(s));
  });
  for (const page of [...screens].sort(byteOrder)) lines.push(`the frontend serves a page at ${pageUrl(page)} (src/app/${page})`);
  for (const [file, text] of Object.entries(sources).sort(([a], [b]) => byteOrder(a, b))) {
    if (file.startsWith('generated/')) continue;
    for (const operation of new Set([...text.matchAll(new RegExp(STOCK_COUNT_OR_MOVE_OPERATION_TOKEN, 'g'))].map((m) => m[1]!))) {
      lines.push(`src/${file} names ${operation} outside src/generated/`);
    }
    if (STOCK_COUNT_OR_MOVE_PATH_TOKEN.test(text)) {
      lines.push(`src/${file} names a stock count or transfer path (/stock-movements/adjustments or /stock-movements/transfers) outside src/generated/`);
    }
  }
  for (const operation of STOCK_COUNT_OR_MOVE_OPERATIONS) if (clientOps.includes(operation)) lines.push(`${CLIENT_OPS_FILE} wires ${operation} for the browser`);
  return lines;
}

/** Relative to `frontend/`: the backend check this block twins, read only for its message (the parity test). */
const G51_BACKEND_FILE = '../backend/tests/Api/OwedAmendmentTripwireTest.php';
const G51_BACKEND_METHOD = 'public function noFrontendCountsOrMovesStockWhileG51IsOpen(): void';
/** The exact source text that opens the real-tree assertion; its message's literals follow it. */
const G51_BACKEND_ANCHOR = 'self::assertSame([], $lines, "  ".implode("\\n  ", $lines)."\\n\\n"';

/**
 * A PHP method's inline failure message, read from source (final RED M-2): the method's body ends at the first
 * `\n    }\n` after its signature; from the end of the LAST occurrence of `anchor` in it, literals are read one at a time
 * (`."…"`, sticky), and what follows the last one must be `);`. Never cut at the first `);`: a literal holds
 * `ItemWriter::archive();`. Each literal is unescaped (`\n` → newline; `\"`, `\\`, `\$` → the character) and joined.
 * Fails closed, by name, when the method, the anchor or the closing `);` is not where it should be.
 */
function phpInlineMessage(source: string, method: string, anchor: string): string {
  const at = source.indexOf(method);
  if (at < 0) throw new Error(`G-51 parity: ${method} is not in the backend file, so there is no message to compare.`);
  const end = source.indexOf('\n    }\n', at);
  const body = source.slice(at, end < 0 ? undefined : end);
  const a = body.lastIndexOf(anchor);
  if (a < 0) throw new Error('G-51 parity: the real-tree assertion\'s opening text is not in the method, so its message cannot be found.');
  const literal = /\s*\.\s*"((?:[^"\\]|\\.)*)"/y;
  literal.lastIndex = a + anchor.length;
  const parts: string[] = [];
  let last = literal.lastIndex;
  for (let m = literal.exec(body); m !== null; m = literal.exec(body)) {
    for (const e of m[1]!.matchAll(/\\(.)|\$/gs)) if (e[0] === '$' || !'n"\\$'.includes(e[1]!)) throw new Error('G-51 parity: a backend literal holds a PHP escape or interpolation this reader does not decode, so the comparison would not be with the printed message.');
    parts.push(m[1]!);
    last = literal.lastIndex;
  }
  if (!/^\s*\);/.test(body.slice(last))) throw new Error('G-51 parity: the message is not followed by `);`, so its last literal is not where this reads it.');
  return parts.map((p) => p.replace(/\\([n"\\$])/g, (_, c: string) => (c === 'n' ? '\n' : c))).join('');
}

const G51_MESSAGE =
  'G-51 is open (docs/RESIDUAL_GAPS.md G-51, Low; owner PO): an item can be archived (DeleteItem) while it holds\n' +
  'stock, and that stock then cannot be counted or moved between locations until the item is restored. Its\n' +
  'acceptance for the web app rests on the web app being unable to count or move stock for ANY item, so that\n' +
  'archiving removes no stock ability its user has. The frontend is now wiring a stock count or transfer, so\n' +
  'that ground is about to become false.\n' +
  '\n' +
  "Before this screen ships, PO + UX recommend and the owner decides (a shipped-behaviour change, as G-51's\n" +
  'Closing work says) between:\n' +
  "  1. DeleteItem refuses an item with stock in any location (qty_on_hand <> 0): fold archiveUnlessStocked()'s\n" +
  '     check into ItemWriter::archive(); BulkArchivalTest::aStockedItemIsSkippedWhileTheSingleDeleteStillArchivesIt\n' +
  "     and StorageLocationDeleteTest's G-51 test then go red by design and move in that commit; or\n" +
  "  2. G-51 is re-accepted on a ground that is true with a count or transfer screen (the delete dialog's\n" +
  '     holds_stock warning alone is not that ground: it says the stock exists, not that the new screen\n' +
  '     will refuse it).\n' +
  'Then record the decision in docs/RESIDUAL_GAPS.md G-51, and move this check and its frontend twin\n' +
  '(frontend/test/architecture.test.ts, FE-LEAD) in the same commit, saying so.\n' +
  '\n' +
  "The same screen is G-97's hard trigger (docs/RESIDUAL_GAPS.md G-97, Low; owner PO; items batch C3): a used\n" +
  "item's unit cannot be changed (UpdateItem 409 inventory_item.unit_in_use), and the API's way round -- count\n" +
  'to zero, close or cancel the open lines, archive, create the new item, count up -- strands stock and\n' +
  'triggers re-ordering if done out of order. With a count or transfer screen the web app can do part of that\n' +
  'way round: PO re-rates G-97 in the same commit.';

describe('architecture — G-51: no stock count or transfer while G-51 is open (twin of the backend G-51 check)', () => {
  it('planted trigger trees: each isolates one rule and is due or not exactly as the backend check says', () => {
    const admin = ['(app)/admin/page.tsx'];
    const triggers: [string, string[], Record<string, string>, string[], boolean][] = [
      ['arm (a): a library file naming CreateStockAdjustment', admin, { 'lib/api/stock.ts': "write('CreateStockAdjustment', body)" }, [], true],
      ['arm (a): a component naming CreateStockTransfer', admin, { 'components/Move.tsx': 'CreateStockTransfer' }, [], true],
      ['arm (a) miss: a longer identifier (word boundary after)', admin, { 'lib/x.ts': 'CreateStockAdjustmentDraft' }, [], false],
      ['arm (a) miss: a longer identifier (word boundary before)', admin, { 'lib/x.ts': 'XCreateStockTransfer' }, [], false],
      ['arm (a) miss: only the movements read named', admin, { 'lib/x.ts': "read('ListStockMovements')" }, [], false],
      ['arm (a) miss: both operations only in generated code', admin, { 'generated/api-surface.server.ts': 'CreateStockAdjustment CreateStockTransfer' }, [], false],
      ['arm (b): the adjustment wired for the browser', admin, {}, ['ListUsers', 'CreateStockAdjustment'], true],
      ['arm (b): the transfer wired for the browser', admin, {}, ['CreateStockTransfer'], true],
      ['arm (b) miss: only the movements read wired', admin, {}, ['ListStockMovements'], false],
      ['arm (c): a /stock-counts page', ['(app)/stock-counts/page.tsx'], {}, [], true],
      ['arm (c): a page below /stock-adjustments', ['(app)/stock-adjustments/new/page.tsx'], {}, [], true],
      ['arm (c): a stock-transfers segment deeper down', ['(app)/inventory/stock-transfers/page.tsx'], {}, [], true],
      ['arm (c): a count page under /stock', ['(app)/stock/count/page.tsx'], {}, [], true],
      ['arm (c): a transfer page under a /stock item', ['(app)/stock/[id]/transfer/page.tsx'], {}, [], true],
      ['arm (c): a /stock-counts page under a parallel slot', ['(app)/stock-counts/@panel/page.tsx'], {}, [], true],
      ["arm (c) miss: the /stock page itself (G-47's trigger, not this one)", ['(app)/stock/page.tsx'], {}, [], false],
      ['arm (c) miss: a count page outside /stock', ['(app)/items/count/page.tsx'], {}, [], false],
      ['arm (c) miss: a /stock-movements page', ['(app)/stock-movements/page.tsx'], {}, [], false],
      ['arm (c) miss: a private folder', ['(app)/_wip/stock-counts/page.tsx'], {}, [], false],
      ['arm (c) miss: a layout, not a page', ['(app)/stock-counts/layout.tsx'], {}, [], false],
      ['arm (d): the adjustment path on the server', admin, { 'lib/x.ts': "'/api/v1/stock-movements/adjustments'" }, [], true],
      ['arm (d): the transfer path through the BFF', admin, { 'lib/x.ts': "bffFetch('/bff/api/stock-movements/transfers', { method: 'POST' })" }, [], true],
      ['arm (d) miss: the movements read path alone', admin, { 'lib/x.ts': "'/bff/api/stock-movements?item=1'" }, [], false],
      ['arm (d) miss: a longer path segment', admin, { 'lib/x.ts': "'/api/v1/stock-movements/adjustments-draft'" }, [], false],
      ['arm (d) miss: both paths only in generated code', admin, { 'generated/api-surface.server.ts': "template: '/api/v1/stock-movements/adjustments', template: '/api/v1/stock-movements/transfers'," }, [], false],
      ['a frontend with no stock screens', ['(app)/admin/users/page.tsx', '(auth)/login/page.tsx'], {}, ['ListUsers'], false],
    ];
    const verdicts = triggers.map(([name, pages, sources, clientOps]) => [name, stockCountOrMoveTriggers(pages, sources, clientOps).length > 0]);
    expect(verdicts).toEqual(triggers.map(([name, , , , due]) => [name, due]));
  });

  it('planted control: the trigger lines for a four-reason tree are the exact lines, in order, that a red run prints', () => {
    expect(stockCountOrMoveTriggers(['(app)/stock/count/page.tsx'], { 'lib/x.ts': "'/bff/api/stock-movements/transfers'", 'components/A.tsx': 'CreateStockTransfer' }, ['CreateStockAdjustment'])).toEqual([
      'the frontend serves a page at /stock/count (src/app/(app)/stock/count/page.tsx)',
      'src/components/A.tsx names CreateStockTransfer outside src/generated/',
      'src/lib/x.ts names a stock count or transfer path (/stock-movements/adjustments or /stock-movements/transfers) outside src/generated/',
      'scripts/api-surface.client-ops.json wires CreateStockAdjustment for the browser',
    ]);
  });

  it('real-tree controls: the walk, the generated boundary and the client wiring list', () => {
    assertFrontendTreeIsWalkable();
    const generated = Object.entries(realSources()).filter(
      ([file, text]) => file.startsWith('generated/') && /\bCreateStockAdjustment\b/.test(text) && /\bCreateStockTransfer\b/.test(text) && STOCK_COUNT_OR_MOVE_PATH_TOKEN.test(text),
    );
    expect(generated.length, 'Positive control: src/generated/ no longer names CreateStockAdjustment, CreateStockTransfer and their paths, so excluding it proves nothing about where the boundary is. Re-derive the tokens or the folder.').toBeGreaterThan(0);
    expect(existsSync(path.join(ROOT, CLIENT_OPS_FILE)), 'Positive control: the client wiring list moved, so arm (b) reads nothing.').toBe(true);
    const clientOps = realClientOps();
    expect(Array.isArray(clientOps), 'Positive control: the client wiring list is not a JSON list, so arm (b) reads nothing.').toBe(true);
    expect(clientOps as unknown[], 'Positive control: the client wiring list no longer holds ListUsers, so it is not the list arm (b) means.').toContain('ListUsers');
  });

  it('the real tree: the web app neither counts nor moves stock', () => {
    const lines = stockCountOrMoveTriggers(realPages(), realSources(), realClientOps() as unknown[]);
    expect(lines, `  ${lines.join('\n  ')}\n\n${G51_MESSAGE}`).toEqual([]);
  });

  it('planted parity controls: the extractor reads every literal, past a `);` inside one, and fails closed', () => {
    const method = (message: string) => `class T {\n    public function noFrontendCountsOrMovesStockWhileG51IsOpen(): void\n    {\n        ${G51_BACKEND_ANCHOR}\n            ."first x();\\n"\n            ."second \\"q\\" \\$v\\\\"${message}\n    }\n}\n`;
    expect(phpInlineMessage(method(');'), G51_BACKEND_METHOD, G51_BACKEND_ANCHOR)).toBe('first x();\nsecond "q" $v\\');
    expect(() => phpInlineMessage('class T {}\n', G51_BACKEND_METHOD, G51_BACKEND_ANCHOR)).toThrow(/is not in the backend file/);
    expect(() => phpInlineMessage(method(", 'x');"), G51_BACKEND_METHOD, G51_BACKEND_ANCHOR)).toThrow(/not followed by/);
    // i3-c1 RED L-4: an escape the reader does not decode (here `\t`) fails closed instead of being compared raw.
    const tabbed = `class T {\n    public function noFrontendCountsOrMovesStockWhileG51IsOpen(): void\n    {\n        ${G51_BACKEND_ANCHOR}\n            ."a\\tb");\n    }\n}\n`;
    expect(() => phpInlineMessage(tabbed, G51_BACKEND_METHOD, G51_BACKEND_ANCHOR)).toThrow(/does not decode/);
    // i3-c1 fix-RED L-2: a bare `$` (interpolation) and an escaped backslash before `$` fail closed too.
    const interpolated = `class T {\n    public function noFrontendCountsOrMovesStockWhileG51IsOpen(): void\n    {\n        ${G51_BACKEND_ANCHOR}\n            ."a $v b");\n    }\n}\n`;
    expect(() => phpInlineMessage(interpolated, G51_BACKEND_METHOD, G51_BACKEND_ANCHOR)).toThrow(/does not decode/);
    const backslashThenVariable = `class T {\n    public function noFrontendCountsOrMovesStockWhileG51IsOpen(): void\n    {\n        ${G51_BACKEND_ANCHOR}\n            ."a \\\\$v b");\n    }\n}\n`;
    expect(() => phpInlineMessage(backslashThenVariable, G51_BACKEND_METHOD, G51_BACKEND_ANCHOR)).toThrow(/does not decode/);
  });

  it("parity: G51_MESSAGE equals the backend check's inline failure message, character for character", () => {
    const file = path.join(ROOT, G51_BACKEND_FILE);
    expect(existsSync(file), `G-51 parity: ${G51_BACKEND_FILE} is not where this reads the backend message, so the two could drift apart unseen.`).toBe(true);
    expect(phpInlineMessage(readFileSync(file, 'utf8'), G51_BACKEND_METHOD, G51_BACKEND_ANCHOR), 'G51_MESSAGE is no longer the backend text: sync it (the backend owns the canonical message).').toBe(G51_MESSAGE);
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// GR-2 H-1 (gr-2-h1-spar.md converged text; first RED gr-2-h1-red.md M-1; round-2 RED gr-2-round2-red.md M-1, M-2, L-2, L-3;
// b4-architecture.md R10-7): a create that moves stock or money holds its Idempotency-Key across an unknown outcome. Any
// non-generated src/ file that calls one of the operations below by name (`<x>.call('<Operation>', …)`) must pass
// `idempotencyKey: <h>.key()` where `<h>` is bound in the same file to `heldKey(…)` imported from `src/lib/bff/heldKey`, and
// not declared in the very function that sends (a holder minted per press) — a callback nested inside that function counts
// as a different function, so a per-press holder used from a `map`/`forEach` callback is not seen. A bare `newKey()`, `crypto.randomUUID()` or a
// missing key is red. This is the trigger of frontend-residuals.md FE-R12 (the other creates' fresh key per press). Limits,
// stated: a call through `caller.operation(op, …)` is not seen (no `src` file uses it today); a non-literal id in
// `caller.call` is red outside `DYNAMIC_CALL_ALLOWED`; a holder created in a React component body (per render) with the
// call in a nested handler is not seen — the behaviour tests (test/wired/receipt-held-key.test.ts) and the `useState`
// review check hold that for CreateGoodsReceipt.
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Closed list (a new entry is a deliberate edit): the §4.2 creates that move stock or money AND carry an Idempotency-Key
 * (their Idem cell is `unique:…`; the generated surface says `idempotencyKeyRequired: true`). Status-guarded money writes
 * (PayInvoice, ReverseInvoicePayment, VoidInvoice) send no key and are out by construction.
 */
const STOCK_OR_MONEY_OPERATIONS: readonly string[] = ['CreateGoodsReceipt', 'CreateStockAdjustment', 'CreateStockTransfer', 'CreateFulfilment', 'IssueInvoice', 'IssueCreditNote', 'IssueAdjustmentCreditNote', 'CreateSalesReturn'];
const HELD_KEY_MODULE = 'src/lib/bff/heldKey';
/** Files allowed to call `caller.call` with a non-literal operation id. reads.ts: the read transport; it sends no key. */
const DYNAMIC_CALL_ALLOWED: readonly string[] = ['src/lib/bff/reads.ts'];

function heldKeyViolations(files: readonly SourceFile[]): { violations: Violation[]; inspected: string[] } {
  const out: Violation[] = [];
  const inspected: string[] = [];
  for (const sf of files) {
    if (!under(sf.file, 'src') || under(sf.file, 'src/generated')) continue;
    const tree = parse(sf);
    // Local names bound to the imported heldKey, then the names bound to a heldKey(...) call (with the function each is declared in).
    const heldKeyNames = new Set<string>();
    for (const st of tree.statements) {
      if (!ts.isImportDeclaration(st) || !ts.isStringLiteral(st.moduleSpecifier)) continue;
      const t = targetOf(sf.file, st.moduleSpecifier.text).replace(/\.tsx?$/, '');
      if (t !== HELD_KEY_MODULE) continue;
      const named = st.importClause?.namedBindings;
      if (named && ts.isNamedImports(named)) for (const el of named.elements) if ((el.propertyName ?? el.name).text === 'heldKey') heldKeyNames.add(el.name.text);
    }
    const holders = new Map<string, (ts.Node | undefined)[]>();
    const calls: { call: ts.CallExpression; fn: ts.Node | undefined }[] = [];
    const fns: ts.Node[] = [];
    const visit = (node: ts.Node) => {
      const fn = fns[fns.length - 1];
      if (ts.isVariableDeclaration(node) && ts.isIdentifier(node.name) && node.initializer && ts.isCallExpression(node.initializer) && ts.isIdentifier(node.initializer.expression) && heldKeyNames.has(node.initializer.expression.text)) {
        holders.set(node.name.text, [...(holders.get(node.name.text) ?? []), fn]);
      }
      if (ts.isCallExpression(node) && ts.isPropertyAccessExpression(node.expression) && node.expression.name.text === 'call') {
        const first = node.arguments[0];
        if (first !== undefined && ts.isStringLiteralLike(first) && STOCK_OR_MONEY_OPERATIONS.includes(first.text)) calls.push({ call: node, fn });
      }
      if (ts.isCallExpression(node) && ts.isPropertyAccessExpression(node.expression) && node.expression.name.text === 'call') {
        const first = node.arguments[0];
        if (first !== undefined && !ts.isStringLiteralLike(first) && !DYNAMIC_CALL_ALLOWED.includes(sf.file) && ts.isIdentifier(node.expression.expression) && node.expression.expression.text === 'caller')
          out.push({ file: sf.file, detail: 'caller.call with a non-literal operation id: the stock-or-money check cannot see it (GR-2 H-1, FE-R12) — name the operation literally, or add the file to DYNAMIC_CALL_ALLOWED with a reason' });
      }
      const isFn = ts.isFunctionLike(node);
      if (isFn) fns.push(node);
      ts.forEachChild(node, visit);
      if (isFn) fns.pop();
    };
    visit(tree);
    for (const { call, fn } of calls) {
      const op = (call.arguments[0] as ts.StringLiteralLike).text;
      inspected.push(`${sf.file}:${op}`);
      const options = call.arguments[3];
      let value: ts.Expression | undefined;
      if (options !== undefined && ts.isObjectLiteralExpression(options)) {
        for (const p of options.properties) if (ts.isPropertyAssignment(p) && p.name.getText(tree) === 'idempotencyKey') value = p.initializer;
      }
      const holderFns =
        value !== undefined && ts.isCallExpression(value) && value.arguments.length === 0 && ts.isPropertyAccessExpression(value.expression) && value.expression.name.text === 'key' && ts.isIdentifier(value.expression.expression)
          ? holders.get(value.expression.expression.text)
          : undefined;
      // Held: a holder of that name exists, and none of them is declared in the function that sends (round-2 RED L-2).
      const held = holderFns !== undefined && !holderFns.some((h) => h !== undefined && h === fn);
      if (!held) out.push({ file: sf.file, detail: `${op} moves stock or money: its Idempotency-Key must come from a heldKey(…) holder's key(), not minted per press (GR-2 H-1, FE-R12)` });
    }
  }
  return { violations: out, inspected };
}

describe('architecture — GR-2 H-1: a stock- or money-moving create holds its Idempotency-Key (heldKey; FE-R12 trigger)', () => {
  it('the real tree: every call of a listed operation takes its key from a heldKey holder (the checker inspected CreateGoodsReceipt)', () => {
    const surface = readFileSync('src/generated/api-surface.server.ts', 'utf8');
    for (const op of STOCK_OR_MONEY_OPERATIONS) {
      const entry = new RegExp(`\\n  ${op}: \\{[\\s\\S]*?\\n  \\}`).exec(surface)?.[0] ?? '';
      expect(entry, `${op} is not in the generated surface`).not.toBe('');
      expect(entry, `${op} must require an Idempotency-Key to be on this list`).toContain('idempotencyKeyRequired: true');
    }
    // Membership pinned (gr-2-round3-red.md L-1): a removal or an addition is a deliberate edit in two places, never a silent one.
    expect([...STOCK_OR_MONEY_OPERATIONS].sort()).toEqual(['CreateFulfilment', 'CreateGoodsReceipt', 'CreateSalesReturn', 'CreateStockAdjustment', 'CreateStockTransfer', 'IssueAdjustmentCreditNote', 'IssueCreditNote', 'IssueInvoice']);
    const { violations, inspected } = heldKeyViolations(walk('src'));
    expect(inspected).toEqual(['src/lib/purchasing/wired.ts:CreateGoodsReceipt']);
    expect(violations).toEqual([]);
  });

  it('planted controls: a fresh key per press, a missing key, a holder not from heldKey, an import from elsewhere, a non-literal id, a per-press holder all go red; the held shape does not', () => {
    const imp = "import { heldKey } from '@/lib/bff/heldKey';\n";
    const planted: SourceFile[] = [
      { file: 'src/lib/stock/A.ts', source: `${imp}export const a = (caller, newKey) => caller.call('CreateStockAdjustment', {}, {}, { idempotencyKey: newKey() });` },
      { file: 'src/lib/stock/B.ts', source: `${imp}export const b = (caller) => caller.call('CreateStockTransfer', {}, {}, { signal: undefined });` },
      { file: 'src/lib/stock/C.ts', source: `${imp}const pending = { key: () => crypto.randomUUID() };\nexport const c = (caller) => caller.call('CreateFulfilment', {}, {}, { idempotencyKey: pending.key() });` },
      { file: 'src/lib/stock/D.ts', source: "import { heldKey } from '@/lib/bulk/results';\nconst k = heldKey(() => 'x');\nexport const d = (caller) => caller.call('IssueAdjustmentCreditNote', {}, {}, { idempotencyKey: k.key() });" },
      { file: 'src/lib/stock/Dyn.ts', source: "export const w = (caller, op, newKey) => caller.call(op, {}, {}, { idempotencyKey: newKey() });" },
      { file: 'src/lib/stock/E.ts', source: `${imp}export const e = (caller) => caller.call('IssueInvoice', {}, {});` },
      { file: 'src/lib/stock/Ok.ts', source: `${imp}const held = heldKey(() => crypto.randomUUID());\nexport const ok = (caller) => caller.call('IssueCreditNote', {}, {}, { signal: undefined, idempotencyKey: held.key() });` },
      { file: 'src/lib/stock/Alias.ts', source: "import { heldKey as hold } from '../bff/heldKey';\nconst h = hold(() => 'x');\nexport const ok2 = (caller) => caller.call('CreateSalesReturn', {}, {}, { idempotencyKey: h.key() });" },
      { file: 'src/lib/stock/PerPress.ts', source: `${imp}export const p = (caller) => async () => { const h = heldKey(() => 'x'); return caller.call('CreateFulfilment', {}, {}, { idempotencyKey: h.key() }); };` },
      // Not listed: the rule does not apply to a draft create; generated modules are never scanned; Function.prototype.call is not a caller.
      { file: 'src/lib/stock/Other.ts', source: "export const o = (caller, newKey) => caller.call('CreatePurchaseOrder', {}, {}, { idempotencyKey: newKey() });" },
      { file: 'src/lib/stock/Fn.ts', source: 'export const f = (g, self, a) => g.call(self, a);' },
      { file: 'src/generated/x.ts', source: "export const g = (caller) => caller.call('CreateGoodsReceipt', {}, {}, {});" },
    ];
    expect(heldKeyViolations(planted).violations.map((v) => v.file)).toEqual(['src/lib/stock/A.ts', 'src/lib/stock/B.ts', 'src/lib/stock/C.ts', 'src/lib/stock/D.ts', 'src/lib/stock/Dyn.ts', 'src/lib/stock/E.ts', 'src/lib/stock/PerPress.ts']);
  });
});
