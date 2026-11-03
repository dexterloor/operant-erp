// Plural selection (RED L-4: Intl.PluralRules). This file chooses a FORM; the words live in `copy/` as template
// functions (principle 4), e.g. `plural(n, { one: (n) => `${n} change`, other: (n) => `${n} changes` })`.

const RULES = new Intl.PluralRules('en-GB');

/** The forms a count needs in English. `other` is the fallback for every category the language has. */
export interface PluralForms {
  one: (n: number) => string;
  other: (n: number) => string;
}

/** `plural(1, f)` → `f.one(1)`; `plural(0, f)` and `plural(3, f)` → `f.other(…)`. */
export function plural(n: number, forms: PluralForms): string {
  return RULES.select(n) === 'one' ? forms.one(n) : forms.other(n);
}
