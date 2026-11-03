// WCAG 2.x contrast over the token values (review M-2: never trust printed ratios, compute them).
// Text pairs need 4.5:1 (1.4.3); UI boundaries and focus rings need 3:1 (1.4.11).

export function luminance(hex) {
  const h = hex.replace('#', '');
  if (!/^[0-9a-fA-F]{6}$/.test(h)) throw new Error(`Not a 6-digit hex colour: ${hex}`);
  const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function ratio(a, b) {
  const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (l1 + 0.05) / (l2 + 0.05);
}

// Content surfaces text and boundaries sit on. accent.subtle-bg = selected rows; bg.pressed = pressed controls (M-2).
const SURFACES = ['bg.canvas', 'bg.surface', 'bg.surface-raised', 'bg.sunken', 'bg.hover', 'bg.pressed', 'accent.subtle-bg'];
const SHELL = ['shell.solid', 'shell.edge', 'shell.hover', 'shell.active-bg'];
const TONES = ['info', 'success', 'warning', 'danger', 'neutral', 'violet'];

/** Every pair the shell and its generic components render, per theme. */
export function pairsFor(c) {
  const P = [];
  const add = (kind, fg, bg, min) => P.push({ kind, fg, bg, min, value: ratio(c[fg], c[bg]) });
  for (const bg of SURFACES) {
    for (const fg of ['fg.default', 'fg.muted', 'fg.subtle', 'fg.link']) add('text', fg, bg, 4.5);
    add('boundary', 'border.strong', bg, 3);
    add('focus ring', 'border.focus', bg, 3);
  }
  add('text', 'fg.disabled', 'bg.disabled', 4.5);
  add('boundary', 'border.strong', 'bg.disabled', 3);
  for (const bg of ['accent.solid', 'accent.hover', 'accent.pressed', 'danger.solid', 'danger.hover', 'danger.pressed', ...TONES.map((t) => `${t}.solid`)]) {
    add('text', 'fg.on-accent', bg, 4.5);
  }
  add('text', 'accent.subtle-fg', 'accent.subtle-bg', 4.5);
  for (const t of TONES) add('text', `${t}.subtle-fg`, `${t}.subtle-bg`, 4.5);
  for (const t of TONES) for (const bg of ['bg.surface', 'bg.canvas']) add('text', `${t}.subtle-fg`, bg, 4.5);
  for (const fg of ['qty.positive', 'qty.negative']) for (const bg of ['bg.surface', 'bg.canvas', 'bg.hover', 'accent.subtle-bg']) add('text', fg, bg, 4.5);
  for (const bg of SHELL) {
    add('text', 'shell.fg', bg, 4.5);
    add('text', 'shell.muted', bg, 4.5);
    add('focus ring', 'shell.focus', bg, 3);
  }
  for (const bg of ['shell.solid', 'shell.edge']) add('text', 'shell.group', bg, 4.5);
  add('text', 'shell.active-fg', 'shell.active-bg', 4.5);
  add('boundary', 'shell.indicator', 'shell.active-bg', 3);
  return P;
}

export function report(tokens) {
  const rows = [];
  for (const theme of ['light', 'dark']) for (const p of pairsFor(tokens.color[theme])) rows.push({ theme, ...p, pass: p.value >= p.min });
  return rows;
}
