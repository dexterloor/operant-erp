// Token pipeline (review M-9): tokens.json is the single source; tokens.css and tokens.ts are generated here.
// Pure functions only, so the drift test can call them without touching the disk.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

export const STYLES_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../src/styles');
export const JSON_PATH = path.join(STYLES_DIR, 'tokens.json');
export const CSS_PATH = path.join(STYLES_DIR, 'tokens.css');
export const TS_PATH = path.join(STYLES_DIR, 'tokens.ts');

export function loadTokens(file = JSON_PATH) {
  return JSON.parse(readFileSync(file, 'utf8'));
}

const BANNER = 'GENERATED from tokens.json by scripts/build-tokens.mjs. Do not edit by hand: change tokens.json and run `npm run tokens`.';

const cssName = (key) => `--${key.replace(/\./g, '-')}`;
const px = (n) => (n === 0 ? '0px' : `${n}px`);
const ms = (n) => `${n}ms`;

function noteFor(t, key) {
  return t.notes && t.notes[key] ? ` /* ${t.notes[key]} */` : '';
}

function colourBlock(t, theme, indent) {
  const lines = [];
  for (const [k, v] of Object.entries(t.color[theme])) lines.push(`${indent}${cssName(k)}: ${v};${noteFor(t, k)}`);
  for (const [k, v] of Object.entries(t.elevation[theme])) lines.push(`${indent}--elevation-${k}: ${v};`);
  return lines;
}

function motionLines(m, t, indent) {
  const L = [];
  if (m.duration) for (const [k, v] of Object.entries(m.duration)) L.push(`${indent}--motion-${k}: ${ms(v)};`);
  if (m.exit) for (const [k, v] of Object.entries(m.exit)) L.push(`${indent}--motion-exit-${k}: ${ms(v)};`);
  if (m.special) for (const [k, v] of Object.entries(m.special)) L.push(`${indent}--motion-${k}: ${ms(v)};${noteFor(t, `motion.${k}`)}`);
  if (m.ease) for (const [k, v] of Object.entries(m.ease)) L.push(`${indent}--ease-${k}: ${v};${noteFor(t, `ease.${k}`)}`);
  if (m.scale && 'lift' in m.scale) L.push(`${indent}--motion-lift: ${px(m.scale.lift)};${noteFor(t, 'motion.lift')}`);
  if (m.distance) for (const [k, v] of Object.entries(m.distance)) L.push(`${indent}--motion-distance-${k}: ${px(v)};`);
  if (m.scale) for (const [k, v] of Object.entries(m.scale)) if (k !== 'lift') L.push(`${indent}--motion-scale-${k}: ${v};`);
  return L;
}

export function generateCss(t) {
  const out = [];
  out.push(`/* ${t.meta.name}. ${BANNER}`);
  out.push(` * ${t.meta.origin}`);
  out.push(' * Themes (D4): light is the default; <html data-theme="dark"> for dark; data-theme="system" follows prefers-color-scheme. */');
  out.push(':root,', '[data-theme="light"] {', '  color-scheme: light;', ...colourBlock(t, 'light', '  '), '}', '');
  out.push('[data-theme="dark"] {', '  color-scheme: dark;', ...colourBlock(t, 'dark', '  '), '}', '');
  out.push('@media (prefers-color-scheme: dark) {', '  [data-theme="system"] {', '    color-scheme: dark;', ...colourBlock(t, 'dark', '    '), '  }', '}', '');

  const R = [];
  // next/font self-hosts each face under a hashed family name and exposes it as --font-face-<role>; the named
  // family stays as the fallback so the stack still reads as tokens.json says.
  for (const [k, v] of Object.entries(t.font)) R.push(`  --font-${k}: ${v.replace(/^('[^']+')/, `var(--font-face-${k}, $1)`)};`);
  R.push('  --font-numeric: tabular-nums;');
  for (const [k, v] of Object.entries(t.weight)) R.push(`  --font-weight-${k}: ${v};`);
  for (const [name, s] of Object.entries(t.type)) {
    R.push(`  --type-${name}-size: ${px(s.size)};`);
    R.push(`  --type-${name}-line: ${px(s.line)};`);
    R.push(`  --type-${name}-weight: ${s.weight};`);
    R.push(`  --type-${name}-tracking: ${s.tracking};`);
    if (s.family) R.push(`  --type-${name}-family: var(--font-${s.family});`);
    if (s.transform) R.push(`  --type-${name}-transform: ${s.transform};`);
  }
  t.space.forEach((v, i) => R.push(`  --space-${i}: ${px(v)};`));
  for (const [k, v] of Object.entries(t.radius)) R.push(`  --radius-${k}: ${px(v)};`);
  for (const [k, v] of Object.entries(t.z)) R.push(`  --z-${k}: ${v};`);
  for (const [k, v] of Object.entries(t.size)) R.push(`  --size-${k}: ${k === 'icon-stroke' ? v : px(v)};`);
  R.push(...motionLines(t.motion, t, '  '));
  R.push(`  --motion-reduced: ${ms(t.motionReduced.policy.crossFade)};`);
  for (const [k, v] of Object.entries(t.interaction)) R.push(`  --interaction-${k}: ${ms(v)};`);
  out.push(':root {', ...R, '}', '');

  out.push('/* Reduced motion (§6): movement becomes a 100 ms cross-fade or nothing; the 1.2 s colour-only highlight is kept. */');
  out.push('@media (prefers-reduced-motion: reduce) {', '  :root {', ...motionLines(t.motionReduced, t, '    '), '  }', '}', '');
  return out.join('\n');
}

function nest(flat) {
  const o = {};
  for (const [k, v] of Object.entries(flat)) {
    const dot = k.indexOf('.');
    const g = k.slice(0, dot);
    const n = k.slice(dot + 1);
    (o[g] ||= {})[n] = v;
  }
  return o;
}

const j = (v) => JSON.stringify(v, null, 2);

export function generateTs(t) {
  const varsFlat = Object.fromEntries(Object.keys(t.color.light).map((k) => [k, `var(${cssName(k)})`]));
  const statusChip = {};
  for (const doc of t.statusMap) {
    statusChip[doc.key] = {};
    for (const r of doc.rows) {
      const chip = { label: r.label, tone: r.tone, variant: r.variant };
      if (r.icon) chip.icon = r.icon;
      if (r.spin) chip.spin = true;
      statusChip[doc.key][r.key ?? r.wire] = chip;
    }
  }
  const icon = Object.fromEntries(t.icons.map((i) => [i.concept, i.icon]));
  const p = t.motionReduced.policy;
  const reducedMotion = { crossFade: p.crossFade, maxDuration: p.maxDuration, distance: 0, scale: 1, smoothScroll: p.smoothScroll, shimmer: p.shimmer, spinner: p.spinner };
  const font = { ...t.font, numeric: 'tabular-nums', weight: t.weight };
  const out = [];
  out.push(`/**\n * ${t.meta.name}. ${BANNER}\n * ${t.meta.origin}\n */`);
  out.push("export type ThemeName = 'light' | 'dark';");
  out.push('/** D4: Light | Dark | Match system, stored under THEME_STORAGE_KEY, default light. */');
  out.push("export type ThemePreference = 'light' | 'dark' | 'system';");
  out.push("export const THEME_STORAGE_KEY = 'erp.theme';");
  out.push("export const THEME_DEFAULT: ThemePreference = 'light';");
  out.push(`export type Tone = ${t.tones.map((x) => `'${x}'`).join(' | ')};`);
  out.push("export type ChipVariant = 'subtle' | 'solid' | 'outline';");
  out.push('');
  out.push(`export const color = {\n  light: ${j(nest(t.color.light)).replace(/\n/g, '\n  ')},\n  dark: ${j(nest(t.color.dark)).replace(/\n/g, '\n  ')}\n} as const;`);
  out.push(`/** Theme-aware CSS variable references, e.g. vars.bg.canvas === 'var(--bg-canvas)'. */\nexport const vars = ${j(nest(varsFlat))} as const;`);
  out.push(`export const font = ${j(font)} as const;`);
  out.push(`export const type = ${j(t.type)} as const;`);
  out.push(`export const space = ${j(t.space)} as const;`);
  out.push(`export const radius = ${j(t.radius)} as const;`);
  out.push(`export const elevation = ${j({ ...t.elevation, spec: t.elevationSpec })} as const;`);
  out.push(`export const zIndex = ${j(t.z)} as const;`);
  out.push(`export const size = ${j(t.size)} as const;`);
  out.push(`export const motion = ${j(t.motion)} as const;`);
  out.push(`/** Values used under prefers-reduced-motion: reduce. */\nexport const reducedMotion = ${j(reducedMotion)} as const;`);
  out.push(`export const interaction = ${j(t.interaction)} as const;`);
  out.push('export type StatusChip = { label: string; tone: Tone; variant: ChipVariant; icon?: string; spin?: boolean };');
  out.push(`/** §4.2 status-to-colour map, keyed by wire value. */\nexport const statusChip = ${j(statusChip)} as const satisfies Record<string, Record<string, StatusChip>>;`);
  out.push(`/** §4.6 Lucide icon map (kebab-case Lucide names). */\nexport const icon = ${j(icon)} as const;`);
  out.push('export const tokens = { color, vars, font, type, space, radius, elevation, zIndex, size, motion, reducedMotion, interaction, statusChip, icon } as const;');
  out.push('export default tokens;');
  return out.join('\n\n') + '\n';
}
