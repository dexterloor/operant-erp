import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
// @ts-expect-error — plain ESM script without type declarations
import { loadTokens, generateCss, generateTs, CSS_PATH, TS_PATH } from '../scripts/tokens-lib.mjs';
// @ts-expect-error — plain ESM script without type declarations
import { report, ratio } from '../scripts/contrast-lib.mjs';

type Row = { theme: string; kind: string; fg: string; bg: string; value: number; min: number; pass: boolean };

describe('token pipeline (review M-9)', () => {
  const t = loadTokens();

  it('tokens.css is exactly what tokens.json generates (no hand edits, no drift)', () => {
    expect(readFileSync(CSS_PATH, 'utf8')).toBe(generateCss(t));
  });

  it('tokens.ts is exactly what tokens.json generates', () => {
    expect(readFileSync(TS_PATH, 'utf8')).toBe(generateTs(t));
  });

  it('a changed source value would be caught: the generator is not a constant', () => {
    const mutated = structuredClone(t);
    mutated.color.light['bg.canvas'] = '#000000';
    expect(generateCss(mutated)).not.toBe(readFileSync(CSS_PATH, 'utf8'));
    expect(generateTs(mutated)).not.toBe(readFileSync(TS_PATH, 'utf8'));
  });

  it('carries the M-2 border fix and the tokens the review found missing', () => {
    expect(t.color.light['border.strong']).toBe('#76829A');
    expect(t.color.dark['border.strong']).toBe('#68768F');
    const css = readFileSync(CSS_PATH, 'utf8');
    expect(css).toContain('--font-weight-bold: 700;');
    expect(css).toContain('--type-overline-transform: uppercase;');
  });

  it('light, dark and the system block hold the same colour names; system equals dark', () => {
    expect(Object.keys(t.color.dark)).toEqual(Object.keys(t.color.light));
    const css = readFileSync(CSS_PATH, 'utf8');
    const block = (sel: string) => css.slice(css.indexOf(sel)).split('}')[0] ?? '';
    const darkVals = block('[data-theme="dark"] {').split('\n').slice(2).map((l) => l.trim()).filter(Boolean);
    const sysVals = block('  [data-theme="system"] {').split('\n').slice(2).map((l) => l.trim()).filter(Boolean);
    expect(sysVals).toEqual(darkVals);
  });

  it('reduced motion keeps the 1.2 s colour-only highlight and zeroes movement', () => {
    expect(t.motionReduced.special.highlight).toBe(1200);
    expect(t.motionReduced.distance).toEqual({ settle: 0, toast: 0 });
    expect(Math.max(...Object.values(t.motionReduced.duration as Record<string, number>))).toBeLessThanOrEqual(100);
  });
});

describe('contrast (WCAG 2.x, computed — never the printed ratios)', () => {
  const rows: Row[] = report(loadTokens());

  it('the luminance maths matches known reference values', () => {
    expect(ratio('#000000', '#FFFFFF')).toBeCloseTo(21, 5);
    expect(ratio('#777777', '#FFFFFF')).toBeCloseTo(4.48, 2);
  });

  it('every text pair is ≥ 4.5:1 and every boundary / focus ring ≥ 3:1, both themes', () => {
    const failures = rows.filter((r) => !r.pass).map((r) => `${r.theme} ${r.fg} on ${r.bg} = ${r.value.toFixed(2)}`);
    expect(failures).toEqual([]);
    expect(rows.filter((r) => r.theme === 'dark').length).toBe(rows.filter((r) => r.theme === 'light').length);
  });

  it('covers the surfaces the review named for border.strong (M-2)', () => {
    for (const theme of ['light', 'dark'])
      for (const bg of ['bg.surface', 'bg.surface-raised', 'bg.hover', 'bg.pressed', 'accent.subtle-bg'])
        expect(rows.some((r) => r.theme === theme && r.fg === 'border.strong' && r.bg === bg)).toBe(true);
  });

  it('the pre-fix border values would have failed (the check can go red)', () => {
    expect(ratio('#5E6C86', '#16233B')).toBeLessThan(3);
    expect(ratio('#7D899F', '#E1E8F2')).toBeLessThan(3);
  });
});
