// "a, b and c" — the conjunction list used in sentences (ux-copy-frontend.md R4-1; RED L-4: Intl.ListFormat, not a hand
// join, so `format/` stays i18n-ready). en-GB has no serial comma. Framework-free.

const CONJUNCTION = new Intl.ListFormat('en-GB', { style: 'long', type: 'conjunction' });

/** Joins items as a sentence list: `[]` → `''`, `['a']` → `'a'`, `['a','b']` → `'a and b'`, three → `'a, b and c'`. */
export function formatList(items: readonly string[]): string {
  return CONJUNCTION.format(items);
}
