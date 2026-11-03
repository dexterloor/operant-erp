// @vitest-environment node
import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

// FE-D1 (fe-d1-red.md M-3 / spar P-3): the dialog must have NO exit animation. PersonLifecycleActions and BulkBar commit
// a successful close with flushSync and then move focus to the page's <h1>. Radix's Presence keeps the dialog content
// mounted after close only when the ANIMATION NAME changes between open and closed; while it is mounted the page stays
// aria-hidden and the trap stays on, so focus would land under aria-hidden. jsdom has no animations, so this source test
// is the guard. A `transition` never holds the content mounted and is not forbidden.
//
// Rules, over comment-stripped CSS:
//  1. Dialog.module.css (every class in it lands on the overlay or the content): no selector mentions `data-state`; every
//     `animation`/`animation-name` uses the keyframes `fade` or `none`; no animation inside a rule whose selector carries
//     an attribute or pseudo-class qualifier.
//  2. Every src/**/*.css: no selector combines `data-state` with `closed`, and none targets `[role=dialog]`.
// Anti-vacuity: the `.content` rule must be found with `animation: fade …`, and the planted sources below must go red.

const ROOT = path.resolve(__dirname, '..');
const DIALOG_CSS = 'src/components/ui/Dialog.module.css';

interface CssFile {
  file: string;
  source: string;
}
interface Rule {
  selector: string;
  body: string;
}

function cssFiles(dirRel: string): CssFile[] {
  const out: CssFile[] = [];
  for (const name of readdirSync(path.join(ROOT, dirRel)).sort()) {
    const rel = `${dirRel}/${name}`;
    if (statSync(path.join(ROOT, rel)).isDirectory()) out.push(...cssFiles(rel));
    else if (name.endsWith('.css')) out.push({ file: rel, source: readFileSync(path.join(ROOT, rel), 'utf8') });
  }
  return out;
}

/** Innermost rules (`selector { body }` with no braces inside the body); @media/@supports wrappers fall away. */
function rules(source: string): Rule[] {
  const css = source.replace(/\/\*[\s\S]*?\*\//g, '');
  return [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)].map((m) => ({ selector: m[1]!.replace(/^[\s\S]*;/, '').trim(), body: m[2]! }));
}

/** The keyframes names used by a rule's `animation` / `animation-name` declarations. */
function animationNames(body: string): string[][] {
  return [...body.matchAll(/(?:^|;)\s*animation(-name)?\s*:\s*([^;]+)/g)].map((m) =>
    m[1] ? m[2]!.split(',').map((s) => s.trim()) : m[2]!.split(',').map((one) => one.trim().split(/\s+/)[0] ?? ''),
  );
}

function violations(files: CssFile[]): string[] {
  const out: string[] = [];
  for (const { file, source } of files) {
    for (const { selector, body } of rules(source)) {
      if (/data-state/.test(selector) && /closed/.test(selector)) out.push(`${file}: closed-state selector "${selector}"`);
      if (/\[\s*role\s*=\s*['"]?dialog/.test(selector)) out.push(`${file}: dialog-role selector "${selector}"`);
      if (file !== DIALOG_CSS) continue;
      if (/data-state/.test(selector)) out.push(`${file}: data-state selector "${selector}"`);
      const names = animationNames(body);
      if (names.length > 0 && /[[:]/.test(selector) && !/^(from|to|\d+%)$/.test(selector)) out.push(`${file}: animation on a qualified selector "${selector}"`);
      for (const decl of names) for (const n of decl) if (n !== 'fade' && n !== 'none') out.push(`${file}: animation "${n}" on "${selector}"`);
    }
  }
  return out;
}

const TREE = cssFiles('src');

describe('FE-D1: the dialog has no exit animation', () => {
  it('finds the dialog stylesheet, its .content rule and its `animation: fade` (anti-vacuity)', () => {
    const dialog = TREE.find((f) => f.file === DIALOG_CSS);
    expect(dialog).toBeDefined();
    const content = rules(dialog!.source).find((r) => r.selector === '.content');
    expect(content).toBeDefined();
    expect(animationNames(content!.body)).toEqual([['fade']]);
    expect(TREE.length).toBeGreaterThan(5);
  });

  it('the real tree has no closed-state dialog rule and no other animation on the dialog', () => {
    expect(violations(TREE)).toEqual([]);
  });

  it('goes red on each planted shape, and stays green on a transition', () => {
    const base = TREE.find((f) => f.file === DIALOG_CSS)!;
    const plant = (extra: string, file = DIALOG_CSS) => violations([...TREE.filter((f) => f.file !== file), { file, source: (file === DIALOG_CSS ? base.source : '') + extra }]);
    expect(plant(".content[data-state='closed'] { animation: fade-out 120ms; }")).not.toEqual([]);
    expect(plant('.danger[data-state=closed] { animation: x 1s; }')).not.toEqual([]);
    expect(plant('.content:not(.x) { animation: fade 1s; }')).not.toEqual([]);
    expect(plant('.overlay { animation-name: slide; }')).not.toEqual([]);
    expect(plant("[data-state=closed][role=dialog] { animation: out 1s; }", 'src/app/globals-planted.css')).not.toEqual([]);
    expect(plant('.content { transition: opacity 120ms; }')).toEqual([]);
  });
});
