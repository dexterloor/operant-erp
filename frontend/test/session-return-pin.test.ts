// @vitest-environment node
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import ts from 'typescript';
import { describe, expect, it } from 'vitest';

// The rules the session-ended return path rests on (fe-session-return-progress.md D-1, D-2; fe-session-return-red.md
// M-3, L-1, L-2, L-3). For an ended session the (app) layout renders no frame and lets the page answer, so:
//
// (1) Every (app) page — any `page.{tsx,ts,jsx,js,mdx}` — must, as the first thing its default export does after awaiting
//     only its own `params` / `searchParams`, await `currentViewer(sessionEndedPath(<its own address>[, query]))` as a
//     top-level statement (never inside a `try`, which could swallow the redirect). The address is derived from the
//     folder: route groups dropped, `[id]` → `${encodeURIComponent(id)}`, and the `searchParams` value passed as the query
//     whenever the page takes `searchParams`. Checked on the TypeScript AST, so a comment or a string cannot satisfy it.
// (2) Nothing under (app) may put a Suspense boundary above a page: no `loading.*`, no `template.*`, and no `Suspense` in
//     any (app) `layout.*`. Each would let the shell flush before the page's redirect, turning the server 307 into a 200
//     with a streamed redirect (measured as option A: a blank page without JavaScript).
//
// Known blind shapes (not checked): a `Suspense` inside a client component a layout renders; a page that re-exports its
// default from another file; an `.mdx` page's body (it is reported, and fails, as having no checkable default export).

const APP = 'src/app/(app)';
const ROOT = path.resolve(__dirname, '..');

interface SourceFile {
  readonly file: string; // relative to src/app/(app), with '/' separators
  readonly source: string;
}

function walk(dir: string): SourceFile[] {
  const abs = path.join(ROOT, dir);
  return (readdirSync(abs, { recursive: true }) as string[])
    .filter((f) => /\.(tsx|ts|jsx|js|mdx)$/.test(f))
    .map((f) => ({ file: f.split(path.sep).join('/'), source: readFileSync(path.join(abs, f), 'utf8') }));
}

const PAGE = /(^|\/)page\.(tsx|ts|jsx|js|mdx)$/;

/** The address a page at this file names: groups dropped, a dynamic segment encoded. */
export function expectedAddress(file: string): string {
  const segs = file.split('/').slice(0, -1).filter((s) => !/^\(.*\)$/.test(s));
  const parts = segs.map((s) => {
    const m = /^\[([A-Za-z_$][\w$]*)\]$/.exec(s);
    return m ? '${encodeURIComponent(' + m[1] + ')}' : s;
  });
  return '/' + parts.join('/');
}

function kindOf(file: string): ts.ScriptKind {
  return file.endsWith('.tsx') ? ts.ScriptKind.TSX : file.endsWith('.jsx') ? ts.ScriptKind.JSX : file.endsWith('.js') ? ts.ScriptKind.JS : ts.ScriptKind.TS;
}

const isAwaitOf = (e: ts.Expression | undefined, name: string) =>
  !!e && ts.isAwaitExpression(e) && ts.isIdentifier(e.expression) && e.expression.text === name;

/** Violations of rule (1) for one page file. */
export function pageViolations(sf: SourceFile): string[] {
  if (sf.file.endsWith('.mdx')) return [`${sf.file}: an .mdx page cannot be checked`];
  const tree = ts.createSourceFile(sf.file, sf.source, ts.ScriptTarget.Latest, true, kindOf(sf.file));
  const fn = tree.statements.find(
    (s): s is ts.FunctionDeclaration =>
      ts.isFunctionDeclaration(s) && !!s.modifiers?.some((m) => m.kind === ts.SyntaxKind.ExportKeyword) && !!s.modifiers?.some((m) => m.kind === ts.SyntaxKind.DefaultKeyword),
  );
  if (!fn || !fn.body) return [`${sf.file}: no default-exported function declaration`];
  if (!fn.modifiers?.some((m) => m.kind === ts.SyntaxKind.AsyncKeyword)) return [`${sf.file}: the page is not async`];

  // What the page's props bind: `searchParams` / `params` destructured from the first parameter.
  const takes = new Set<string>();
  const p0 = fn.parameters[0];
  if (p0 && ts.isObjectBindingPattern(p0.name)) for (const el of p0.name.elements) if (ts.isIdentifier(el.name)) takes.add((el.propertyName && ts.isIdentifier(el.propertyName) ? el.propertyName : el.name).text);

  let queryName: string | null = null;
  for (const st of fn.body.statements) {
    if (ts.isVariableStatement(st) && st.declarationList.declarations.length === 1) {
      const d = st.declarationList.declarations[0]!;
      if (isAwaitOf(d.initializer, 'params')) continue;
      if (isAwaitOf(d.initializer, 'searchParams')) {
        if (ts.isIdentifier(d.name)) queryName = d.name.text;
        continue;
      }
    }
    // The first other statement must be the viewer check.
    const call = ts.isExpressionStatement(st)
      ? st.expression
      : ts.isVariableStatement(st) && st.declarationList.declarations.length === 1
        ? st.declarationList.declarations[0]!.initializer
        : undefined;
    if (!call || !ts.isAwaitExpression(call) || !ts.isCallExpression(call.expression) || !ts.isIdentifier(call.expression.expression) || call.expression.expression.text !== 'currentViewer') {
      return [`${sf.file}: the first statement after params/searchParams is not \`await currentViewer(…)\`: ${st.getText(tree).slice(0, 80)}`];
    }
    const arg = call.expression.arguments[0];
    if (call.expression.arguments.length !== 1 || !arg || !ts.isCallExpression(arg) || !ts.isIdentifier(arg.expression) || arg.expression.text !== 'sessionEndedPath') {
      return [`${sf.file}: currentViewer is not given sessionEndedPath(…)`];
    }
    const out: string[] = [];
    const want = expectedAddress(sf.file);
    const a0 = arg.arguments[0];
    const named = a0 && ts.isStringLiteral(a0) ? a0.text : a0 && ts.isNoSubstitutionTemplateLiteral(a0) ? a0.text : a0 && ts.isTemplateExpression(a0) ? a0.getText(tree).slice(1, -1) : null;
    if (named !== want) out.push(`${sf.file}: names ${named === null ? a0?.getText(tree) : named}, not ${want}`);
    if (takes.has('searchParams')) {
      const a1 = arg.arguments[1];
      if (arg.arguments.length !== 2 || !a1 || !ts.isIdentifier(a1) || a1.text !== queryName) out.push(`${sf.file}: takes searchParams but does not pass its query`);
    } else if (arg.arguments.length !== 1) out.push(`${sf.file}: passes a query it does not take`);
    return out;
  }
  return [`${sf.file}: never awaits currentViewer`];
}

/** Violations of rule (2) over the (app) tree. */
export function suspenseViolations(files: readonly SourceFile[]): string[] {
  const out: string[] = [];
  for (const sf of files) {
    const base = sf.file.split('/').pop()!;
    if (/^(loading|template)\./.test(base)) out.push(`${sf.file}: a ${base.split('.')[0]} file puts a Suspense boundary above the page`);
    if (/^layout\./.test(base)) {
      const tree = ts.createSourceFile(sf.file, sf.source, ts.ScriptTarget.Latest, true, kindOf(sf.file));
      let hit = false;
      const v = (n: ts.Node) => {
        if (ts.isIdentifier(n) && n.text === 'Suspense') hit = true;
        ts.forEachChild(n, v);
      };
      v(tree);
      if (hit) out.push(`${sf.file}: a layout uses Suspense`);
    }
  }
  return out;
}

const pagesOf = (files: readonly SourceFile[]) => files.filter((f) => PAGE.test(f.file));

describe('the session-ended return path: every (app) page answers with its own address (D-1)', () => {
  const files = walk(APP);

  it('derives every page, whatever its extension, and each one is clean', () => {
    const pages = pagesOf(files);
    expect(pages.map((p) => p.file).sort()).toEqual(['account/password/page.tsx', 'admin/roles/[id]/page.tsx', 'admin/roles/page.tsx', 'admin/users/[id]/page.tsx', 'admin/users/page.tsx', 'page.tsx', 'warehouses/[id]/page.tsx', 'warehouses/new/page.tsx', 'warehouses/page.tsx']);
    expect(pages.flatMap(pageViolations)).toEqual([]);
  });

  it('derives the expected address from the folder', () => {
    expect(expectedAddress('page.tsx')).toBe('/');
    expect(expectedAddress('admin/users/page.tsx')).toBe('/admin/users');
    expect(expectedAddress('admin/users/[id]/page.tsx')).toBe('/admin/users/${encodeURIComponent(id)}');
    expect(expectedAddress('(group)/x/page.js')).toBe('/x');
  });

  it('planted controls go red: other extensions, wrong address, dropped query, try, a statement first, a comment', () => {
    const bad: SourceFile[] = [
      { file: 'x/page.ts', source: 'export default async function Page() { return null; }' },
      { file: 'x/page.js', source: "export default async function Page() { await currentViewer(sessionEndedPath('/y')); }" },
      { file: 'admin/roles/page.tsx', source: "export default async function Page({ searchParams }) { const query = await searchParams; await currentViewer(sessionEndedPath('/admin/users', query)); }" },
      { file: 'admin/roles/page.tsx', source: "export default async function Page({ searchParams }) { const query = await searchParams; await currentViewer(sessionEndedPath('/admin/roles')); }" },
      { file: 'admin/roles/[id]/page.tsx', source: 'export default async function Page({ params }) { const { id } = await params; await currentViewer(sessionEndedPath(`/admin/roles/${id}`)); }' },
      { file: 'x/page.tsx', source: "export default async function Page() { try { await currentViewer(sessionEndedPath('/x')); } catch {} }" },
      { file: 'x/page.tsx', source: "export default async function Page() { const p = load(); await currentViewer(sessionEndedPath('/x')); }" },
      { file: 'x/page.tsx', source: "export default async function Page() {\n  // await currentViewer(sessionEndedPath('/x'))\n  return null;\n}" },
      { file: 'x/page.tsx', source: "export default function Page() { return null; }" },
    ];
    for (const sf of bad) expect(pageViolations(sf), sf.source).not.toEqual([]);
    expect(pagesOf(bad.slice(0, 2)).length).toBe(2);
    const good: SourceFile = { file: 'admin/roles/[id]/page.tsx', source: 'export default async function Page({ params }) { const { id } = await params; const { viewer } = await currentViewer(sessionEndedPath(`/admin/roles/${encodeURIComponent(id)}`)); return viewer; }' };
    expect(pageViolations(good)).toEqual([]);
  });
});

describe('the session-ended return path: nothing puts a Suspense boundary above an (app) page (M-3, D-2)', () => {
  it('no loading.*, no template.*, no Suspense in an (app) layout', () => {
    expect(suspenseViolations(walk(APP))).toEqual([]);
  });

  it('planted controls go red', () => {
    const planted: SourceFile[] = [
      { file: 'loading.tsx', source: 'export default function L() { return null; }' },
      { file: 'admin/template.js', source: 'export default function T({ children }) { return children; }' },
      { file: 'admin/layout.tsx', source: "import { Suspense } from 'react';\nexport default function L({ children }) { return <Suspense>{children}</Suspense>; }" },
      { file: 'layout.tsx', source: 'export default function L({ children }) { return children; }' },
    ];
    expect(suspenseViolations(planted)).toEqual([
      'loading.tsx: a loading file puts a Suspense boundary above the page',
      'admin/template.js: a template file puts a Suspense boundary above the page',
      'admin/layout.tsx: a layout uses Suspense',
    ]);
  });
});
