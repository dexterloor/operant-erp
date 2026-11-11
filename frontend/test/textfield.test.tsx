import { readFileSync } from 'node:fs';
import path from 'node:path';
import { createRef } from 'react';
import { isInaccessible, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { TextField } from '@/components/ui/TextField';
import { AuthField } from '@/components/auth/AuthCard';

// Revision 7 A-19 item 1: the in-app form field, props-only, with AuthField's contract (ST-3, WCAG 3.3.1).

describe('TextField', () => {
  it('is labelled by its label (htmlFor) and carries neither aria-invalid nor aria-describedby when it has no hint or error', () => {
    render(<TextField id="wh-code" label="Code" />);
    const input = screen.getByRole('textbox', { name: 'Code' });
    expect(input.id).toBe('wh-code');
    expect(input.hasAttribute('aria-invalid')).toBe(false);
    expect(input.hasAttribute('aria-describedby')).toBe(false);
  });

  it('a hint alone is described, and the field is not invalid', () => {
    render(<TextField id="wh-code" label="Code" hint="Up to you." />);
    const input = screen.getByRole('textbox', { name: 'Code' });
    expect(input.getAttribute('aria-describedby')).toBe('wh-code-hint');
    expect(input.hasAttribute('aria-invalid')).toBe(false);
    expect(screen.getByText('Up to you.').id).toBe('wh-code-hint');
  });

  it('an error sets aria-invalid and is described after the hint, with its icon', () => {
    render(<TextField id="wh-code" label="Code" hint="Up to you." error="That code is taken." />);
    const input = screen.getByRole('textbox', { name: 'Code' });
    expect(input.getAttribute('aria-invalid')).toBe('true');
    expect(input.getAttribute('aria-describedby')).toBe('wh-code-hint wh-code-error');
    const line = document.getElementById('wh-code-error')!;
    expect(line.textContent).toBe('That code is taken.');
    expect(line.querySelector('svg[aria-hidden="true"]')).not.toBeNull();
  });

  // Items I-1 (UX R1-2 / F-4; items-i1-red.md L-6 d), as amended by S-3: the suffix comes AFTER the number, outside the
  // input, and is read with the field first; without a suffix the field renders exactly as before (no wrapper, the same
  // describedby). Moved deliberately: the id is on the spoken span, the visible code is aria-hidden.
  it('a suffix follows the input, outside it, and is described first; without one there is no wrapper', () => {
    const { unmount } = render(<TextField id="item-point" label="Reorder point" hint="When it is due." error="Bad." suffix={{ shown: 'KG', spoken: 'kilograms.' }} />);
    const input = screen.getByRole('textbox', { name: 'Reorder point' });
    const spoken = document.getElementById('item-point-suffix')!;
    const shown = spoken.parentElement!.querySelector('[aria-hidden="true"]')!;
    expect(shown.textContent).toBe('KG');
    expect(input.contains(spoken)).toBe(false);
    expect(input.compareDocumentPosition(spoken) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(input.getAttribute('aria-describedby')).toBe('item-point-suffix item-point-hint item-point-error');
    // s3-fer8-final-red.md L-2: by DOM ancestry, not by a class name.
    expect(input.parentElement).toBe(spoken.parentElement!.parentElement);
    unmount();
    render(<TextField id="item-point" label="Reorder point" hint="When it is due." />);
    const plain = screen.getByRole('textbox', { name: 'Reorder point' });
    expect(plain.getAttribute('aria-describedby')).toBe('item-point-hint');
    expect(document.getElementById('item-point-suffix')).toBeNull();
    // The input sits directly in the field, as it did before the suffix existed.
    expect(plain.parentElement).toBe(screen.getByText('Reorder point').parentElement);
  });

  // S-3 T-S3-1 (items-s3-design.md Revision 1 — FINAL item 7(a), with s3-fer8-final-red.md F-1).
  it('T-S3-1: the description says the spoken suffix verbatim; the code is shown, hidden from assistive technology; the spoken span is never inside an aria-hidden subtree', () => {
    render(<TextField id="item-point" label="Reorder point" hint="When it is due." error="Bad." suffix={{ shown: 'KG', spoken: 'kilograms.' }} />);
    const input = screen.getByRole('textbox', { name: 'Reorder point' });
    expect(input).toHaveAccessibleDescription('kilograms. When it is due. Bad.');
    const spoken = document.getElementById('item-point-suffix')!;
    expect(spoken.textContent).toBe('kilograms.');
    expect(spoken).toHaveClass('visually-hidden');
    expect(spoken.closest('[aria-hidden="true"]')).toBeNull();
    // items-s3-commit-red.md L-2: nor hidden any other way (the `hidden` attribute, display none, visibility hidden).
    expect(isInaccessible(spoken)).toBe(false);
    const shown = spoken.parentElement!.querySelector('[aria-hidden="true"]')!;
    expect(shown.textContent).toBe('KG');
    expect(shown.id).toBe('');
    expect(input.getAttribute('aria-describedby')).toBe('item-point-suffix item-point-hint item-point-error');
    expect(input.contains(spoken)).toBe(false);
    expect(input.compareDocumentPosition(shown) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('T-S3-5: a suffix without its spoken text is a type error', () => {
    // @ts-expect-error — `spoken` is required wherever a suffix is shown (S-3).
    render(<TextField id="t5" label="T5" suffix="KG" />);
    // @ts-expect-error — the same for an object without `spoken`.
    render(<TextField id="t6" label="T6" suffix={{ shown: 'KG' }} />);
  });

  it('an empty-string error is no error', () => {
    render(<TextField id="f" label="Name" error="" />);
    const input = screen.getByRole('textbox', { name: 'Name' });
    expect(input.hasAttribute('aria-invalid')).toBe(false);
    expect(input.hasAttribute('aria-describedby')).toBe(false);
    expect(document.getElementById('f-error')).toBeNull();
  });

  it('passes input attributes through and forwards its ref; it takes no transform (the form owns onChange)', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    const ref = createRef<HTMLInputElement>();
    render(<TextField ref={ref} id="cc" label="Country" maxLength={2} autoComplete="off" spellCheck={false} value="" onChange={onChange} />);
    const input = screen.getByRole('textbox', { name: 'Country' });
    expect(ref.current).toBe(input);
    expect(input.getAttribute('maxlength')).toBe('2');
    expect(input.getAttribute('autocomplete')).toBe('off');
    expect(input.getAttribute('spellcheck')).toBe('false');
    await user.type(input, 'g');
    expect(onChange).toHaveBeenCalledTimes(1);
  });

  it('matches AuthField\'s accessibility contract attribute for attribute', () => {
    const cases = [{}, { hint: 'H' }, { error: 'E' }, { hint: 'H', error: 'E' }];
    for (const extra of cases) {
      const a = render(<TextField id="x" label="L" {...extra} />);
      const ti = a.getByRole('textbox', { name: 'L' });
      const t = { invalid: ti.getAttribute('aria-invalid'), describedBy: ti.getAttribute('aria-describedby'), error: document.getElementById('x-error')?.textContent ?? null };
      a.unmount();
      const b = render(<AuthField id="x" label="L" {...extra} />);
      const ai = b.getByRole('textbox', { name: 'L' });
      const au = { invalid: ai.getAttribute('aria-invalid'), describedBy: ai.getAttribute('aria-describedby'), error: document.getElementById('x-error')?.textContent ?? null };
      b.unmount();
      expect(t, JSON.stringify(extra)).toEqual(au);
    }
  });
});

// RED L-2 (D-7 amended): TextField.module.css repeats AuthCard.module.css's field rules on purpose (components/ui must
// not import components/auth). This holds the two equal, so the in-app and credential fields cannot drift apart.
const ROOT = path.resolve(__dirname, '..');
const AUTH_CSS = readFileSync(path.join(ROOT, 'src/components/auth/AuthCard.module.css'), 'utf8');
const FIELD_CSS = readFileSync(path.join(ROOT, 'src/components/ui/TextField.module.css'), 'utf8');

/** Innermost rules (`selector { body }`), comments stripped — the dialog-css.test.ts parser. */
function rules(source: string): Map<string, string> {
  const css = source.replace(/\/\*[\s\S]*?\*\//g, '');
  const out = new Map<string, string>();
  for (const m of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    out.set(m[1]!.replace(/^[\s\S]*;/, '').trim().replace(/\s+/g, ' '), m[2]!.replace(/\s+/g, ' ').trim());
  }
  return out;
}

/** AuthCard selector → its TextField twin. */
const PAIRS: [string, string][] = [
  ['.field', '.field'],
  ['.field label', '.field label'],
  ['.field input', '.field input'],
  ['.field input:hover', '.field input:hover'],
  [".field input[aria-invalid='true']", ".field input[aria-invalid='true']"],
  ['.hint', '.hint'],
  ['.fieldError', '.error'],
  ['.fieldError svg', '.error svg'],
];

function cssParityDiffs(auth: string, field: string): string[] {
  const a = rules(auth);
  const f = rules(field);
  const out: string[] = [];
  for (const [as, fs] of PAIRS) {
    const ab = a.get(as);
    const fb = f.get(fs);
    if (ab === undefined) out.push(`AuthCard.module.css has no "${as}"`);
    else if (fb === undefined) out.push(`TextField.module.css has no "${fs}"`);
    else if (ab !== fb) out.push(`"${fs}" differs from AuthCard's "${as}"`);
  }
  return out;
}

describe('TextField.module.css stays equal to the credential field rules (RED L-2)', () => {
  it('each of the eight field selectors has the same declarations as AuthCard.module.css', () => {
    expect(cssParityDiffs(AUTH_CSS, FIELD_CSS)).toEqual([]);
  });

  it('planted control: one changed declaration is reported', () => {
    expect(FIELD_CSS).toContain('var(--space-3)');
    const planted = FIELD_CSS.replace('var(--space-3)', 'var(--space-4)');
    expect(cssParityDiffs(AUTH_CSS, planted)).toEqual(['".field" differs from AuthCard\'s ".field"']);
  });
});
