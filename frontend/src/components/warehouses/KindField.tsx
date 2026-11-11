'use client';

import { forwardRef } from 'react';
import { SelectField } from '@/components/ui/SelectField';
import { kindLabel } from '@/copy/warehouses';

export interface KindFieldProps {
  id: string;
  label: string;
  /** The served kinds, in served order (`wireEnum`, read by the caller; Revision 7 A-21). */
  kinds: readonly string[];
  value: string;
  onChange: (kind: string) => void;
  /** Sentences under the field (the chosen kind's option help; in Edit, the served current kind's hint). */
  hints: readonly string[];
  /** The server's sentence for `kind`, verbatim; empty or absent = no error. */
  error?: string | undefined;
  /**
   * The unselected state (A-21 "Preselection", S-7; Add location only): a first `<option value="">` with this prompt and
   * NO `disabled` attribute. Absent (Edit), the select has no empty option.
   */
  prompt?: string;
}

/**
 * The Kind select (warehouses-ux-copy.md B-5; Revision 7 A-21, §2 step 4.5), now a thin caller of the shared
 * `SelectField` (Revision 8 R8-5): the label names the select; `aria-invalid` only while there is an error;
 * `aria-describedby` = the hint id then the error id. Options are the served kinds in served order, each shown by its label
 * (or the value as sent). A value the served list lacks is kept as the first option. Never the native `disabled` attribute
 * (ST-4e). Nothing is decided from a kind value. Its props are unchanged, so both location dialogs are untouched.
 */
export const KindField = forwardRef<HTMLSelectElement, KindFieldProps>(function KindField({ id, label, kinds, value, onChange, hints, error, prompt }, ref) {
  return <SelectField ref={ref} id={id} name="kind" label={label} options={kinds} labelOf={kindLabel} value={value} onChange={onChange} hints={hints} error={error} prompt={prompt} />;
});
