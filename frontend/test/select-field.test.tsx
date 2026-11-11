import { createRef } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { SelectField } from '@/components/ui/SelectField';

// Revision 8 R8-5 (items I-2; items-i2-design.md Revision 1 — FINAL A-2, T-2): the props-only select over a served enum.
// The Location Kind select is now a thin caller of it (its 4.5 behaviour tests run unedited, T-3); here the generic
// contract is pinned on its own: the prompt is an enabled first option, an unknown served value is kept first, the hint
// then the error describe the select, `aria-invalid` only with an error, never the native `disabled`, and a ref reaches
// the `<select>` (the dialogs and the create form focus it after a refusal).

const OPTIONS = ['A', 'B', 'C'] as const;
const labelOf = (v: string) => ({ A: 'Apple (A)', B: 'Banana (B)', C: 'Cherry (C)' })[v] ?? v;

function show(over: Partial<Parameters<typeof SelectField>[0]> = {}) {
  const onChange = vi.fn();
  const ref = createRef<HTMLSelectElement>();
  const utils = render(<SelectField ref={ref} id="fruit" name="fruit" label="Fruit" options={OPTIONS} labelOf={labelOf} value="" onChange={onChange} hints={[]} {...over} />);
  return { ...utils, onChange, ref, select: screen.getByRole('combobox', { name: 'Fruit' }) as HTMLSelectElement };
}
const optionTexts = (s: HTMLElement) => within(s).getAllByRole('option').map((o) => [(o as HTMLOptionElement).value, o.textContent]);

describe('SelectField (R8-5)', () => {
  it('the label names the select; options are the given values in the given order, each by its label', () => {
    const { select } = show({ value: 'B' });
    expect(select).toHaveAttribute('name', 'fruit');
    expect(optionTexts(select)).toEqual([
      ['A', 'Apple (A)'],
      ['B', 'Banana (B)'],
      ['C', 'Cherry (C)'],
    ]);
    expect(select.value).toBe('B');
  });

  it('the prompt is the first option, value "", with NO disabled attribute, and is selected while the value is ""', () => {
    const { select } = show({ prompt: 'Choose a fruit' });
    const first = within(select).getAllByRole('option')[0] as HTMLOptionElement;
    expect([first.value, first.textContent]).toEqual(['', 'Choose a fruit']);
    expect(first).not.toHaveAttribute('disabled');
    expect(first.selected).toBe(true);
    expect(optionTexts(select)).toHaveLength(4);
  });

  it('a value the options lack is kept as the first option (never shows a value the record does not have)', () => {
    const { select } = show({ value: 'Z' });
    expect(optionTexts(select)).toEqual([
      ['Z', 'Z'],
      ['A', 'Apple (A)'],
      ['B', 'Banana (B)'],
      ['C', 'Cherry (C)'],
    ]);
    expect(select.value).toBe('Z');
  });

  it('with a prompt, an unknown value is still kept (only "" is the prompt)', () => {
    const { select } = show({ value: 'Z', prompt: 'Choose a fruit' });
    expect(optionTexts(select).map(([v]) => v)).toEqual(['', 'Z', 'A', 'B', 'C']);
  });

  it('aria-describedby is the hint id, then the error id; aria-invalid only while there is an error', () => {
    const { select, rerender, ref } = show({ hints: ['Pick one.'] });
    expect(select).toHaveAttribute('aria-describedby', 'fruit-hint');
    expect(select).not.toHaveAttribute('aria-invalid');
    rerender(<SelectField ref={ref} id="fruit" name="fruit" label="Fruit" options={OPTIONS} labelOf={labelOf} value="" onChange={() => {}} hints={['Pick one.']} error="Choose a fruit for this." />);
    expect(select).toHaveAttribute('aria-describedby', 'fruit-hint fruit-error');
    expect(select).toHaveAttribute('aria-invalid', 'true');
    expect(select).toHaveAccessibleDescription('Pick one. Choose a fruit for this.');
    rerender(<SelectField ref={ref} id="fruit" name="fruit" label="Fruit" options={OPTIONS} labelOf={labelOf} value="" onChange={() => {}} hints={[]} error="Choose a fruit for this." />);
    expect(select).toHaveAttribute('aria-describedby', 'fruit-error');
  });

  it('no hints and no error: no aria-describedby', () => {
    const { select } = show();
    expect(select).not.toHaveAttribute('aria-describedby');
  });

  it('never the native disabled attribute, on the select or any option', () => {
    const { container } = show({ prompt: 'Choose a fruit', value: 'Z', error: 'x', hints: ['h'] });
    expect(container.querySelectorAll('[disabled]')).toHaveLength(0);
  });

  it('onChange receives the chosen value', async () => {
    const { select, onChange } = show({ prompt: 'Choose a fruit' });
    await userEvent.setup().selectOptions(select, 'C');
    expect(onChange).toHaveBeenCalledWith('C');
  });

  it('a ref passed to SelectField is the <select>', () => {
    const { select, ref } = show();
    expect(ref.current).toBe(select);
    ref.current!.focus();
    expect(select).toHaveFocus();
  });
});
