import { describe, expect, expectTypeOf, it } from 'vitest';
import { changedFields, fieldErrorsFrom } from '@/lib/forms/fieldErrors';
import type { FieldErrors, Violation } from '@/lib/results';

// Revision 7 A-19 item 4: a 422's violations onto a form's fields, and a PATCH body of only what changed.

const v = (pointer: string, message: string, code = 'c'): Violation => ({ pointer, code, message });
const FIELDS = ['code', 'name', 'country_code'] as const;

describe('fieldErrorsFrom', () => {
  it('a pointer /<name> naming a field lands on that field', () => {
    expect(fieldErrorsFrom([v('/code', 'That code is taken.'), v('/country_code', 'Use a two-letter code.')], FIELDS)).toEqual({
      fieldErrors: { code: 'That code is taken.', country_code: 'Use a two-letter code.' },
      formErrors: [],
    });
  });

  it('the first message for a field wins; a later one for the same field is not shown', () => {
    expect(fieldErrorsFrom([v('/name', 'Enter a name.'), v('/name', 'Second sentence.')], FIELDS)).toEqual({
      fieldErrors: { name: 'Enter a name.' },
      formErrors: [],
    });
  });

  it('anything else (empty pointer, a query parameter, an unknown or nested field) goes to formErrors verbatim, in order', () => {
    const got = fieldErrorsFrom(
      [v('', 'Whole form.'), v('/code', 'Code.'), v('?archived', 'Query.'), v('/address', 'Unknown field.'), v('/name/0', 'Nested.'), v('name', 'No slash.'), v('/', 'Bare slash.')],
      FIELDS,
    );
    expect(got.fieldErrors).toEqual({ code: 'Code.' });
    expect(got.formErrors).toEqual(['Whole form.', 'Query.', 'Unknown field.', 'Nested.', 'No slash.', 'Bare slash.']);
  });

  it('a 422 that maps nothing still shows its sentences', () => {
    expect(fieldErrorsFrom([v('/other', 'Only this.')], FIELDS)).toEqual({ fieldErrors: {}, formErrors: ['Only this.'] });
  });

  it('no violations → nothing to show', () => {
    expect(fieldErrorsFrom([], FIELDS)).toEqual({ fieldErrors: {}, formErrors: [] });
  });

  it('the field map is typed by the form\'s own field names', () => {
    expectTypeOf(fieldErrorsFrom([], FIELDS).fieldErrors).toEqualTypeOf<FieldErrors<'code' | 'name' | 'country_code'>>();
  });
});

describe('changedFields', () => {
  interface Input {
    code?: string;
    name?: string;
    country_code?: string;
  }

  it('returns only the fields whose string differs, compared exactly (no trim, no case folding)', () => {
    const initial = { code: 'WH1', name: 'Main', country_code: 'GB' };
    expect(changedFields<Input, 'code' | 'name' | 'country_code'>(['code', 'name', 'country_code'], initial, { code: 'WH1', name: 'Main ', country_code: 'gb' })).toEqual({ name: 'Main ', country_code: 'gb' });
  });

  it('nothing changed → an empty body', () => {
    const initial = { code: 'WH1', name: 'Main', country_code: 'GB' };
    expect(changedFields<Input, 'code' | 'name' | 'country_code'>(['code', 'name', 'country_code'], initial, { ...initial })).toEqual({});
  });

  it('a field changed and changed back is not sent', () => {
    expect(changedFields<Input, 'name'>(['name'], { name: 'Main' }, { name: 'Main' })).toEqual({});
    expect(Object.keys(changedFields<Input, 'name'>(['name'], { name: 'Main' }, { name: 'North' }))).toEqual(['name']);
  });

  it('is typed against the operation input: a key the input does not declare is a type error', () => {
    expectTypeOf(changedFields<Input, 'code' | 'name'>).returns.toEqualTypeOf<Partial<Pick<Input, 'code' | 'name'>>>();
    // @ts-expect-error — `notes` is not a member of Input.
    changedFields<Input, 'notes'>(['notes'], { notes: 'x' }, { notes: 'y' });
    // @ts-expect-error — no operation input given
    changedFields(['code'], { code: 'A' }, { code: 'B' });
  });

  it('only the listed keys are read: a wider form-state object cannot leak a key into the body (RED M-1)', () => {
    expect(changedFields<Input, 'code' | 'name'>(['code', 'name'], { code: 'A', name: 'N' }, { code: 'B', name: 'N', kind: 'bin' } as { code: string; name: string })).toEqual({ code: 'B' });
  });
});
