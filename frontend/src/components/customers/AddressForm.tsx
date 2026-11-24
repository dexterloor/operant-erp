'use client';

import { useLayoutEffect, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { SelectField } from '@/components/ui/SelectField';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, MIXED_FIELDS_TOO, NOT_SAVED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { ADDRESSES_SECTION, CUSTOMER_FORBIDDEN, EDIT_ADDRESS, addressKindLabel } from '@/copy/customers';
import type { CustomerAddress } from '@/lib/api/customers';
import { ADDRESS_FIELDS, type AddressChange, type AddressField, type SaveAddressAction, type SaveAddressResult } from '@/lib/customers/results';
import { marksAnyField } from '@/lib/forms/fieldErrors';
import type { FieldErrors } from '@/lib/results';
import styles from './Customers.module.css';

export interface AddressFormProps {
  /** The row being edited (there is no single-address read: the section's row is the served state). */
  address: CustomerAddress;
  /** The served kinds, in served order (`wireEnum('CustomerAddress.UpdateCustomerAddressInput.jsonMergePatch', 'kind')`). */
  kinds: readonly string[];
  save: SaveAddressAction;
  /** `gateCode('UpdateCustomerAddress')`, for the lost-permission sentence's label (B-10). */
  editCode: string | null;
  /**
   * Closes the dialog. `saved` is the 200 body; `reread` is true when what the section shows may be out of date (a save,
   * an interruption, a refusal, a 404); `parentStale` is true when the page must re-read the customer (copy R1a-1): after
   * any answer whose customer re-read was archived, and after any `customer.is_archived` answer whatever the re-read said.
   * The flag is raised when that answer arrives and survives until the dialog goes.
   */
  onClose: (outcome: { saved: CustomerAddress | null; reread: boolean; parentStale: boolean }) => void;
  /** Where focus goes on close when the row's Edit button is gone (the section's heading). */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /** Called once per Save press that will send a request: after every guard, just before the request (FE-R9). */
  onSaveStarted?: () => void;
}

type Values = { kind: string; line: string; city: string; postal_code: string; country_code: string };
type Text = Exclude<AddressField, 'kind'>;
interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

/** Each text field's required sentence (copy rows 68–71). */
const REQUIRED: Record<Text, string> = {
  line: ADDRESSES_SECTION.lineRequired,
  city: ADDRESSES_SECTION.cityRequired,
  postal_code: ADDRESSES_SECTION.postalCodeRequired,
  country_code: ADDRESSES_SECTION.countryRequired,
};
const TEXTS: readonly Text[] = ['line', 'city', 'postal_code', 'country_code'];

/**
 * The fields as the row shows them. A served kind that is not one of the served enum's values (unreachable under the
 * CHECK) is shown as `kindPrompt`, nothing preselected (copy R1a-6 item 4, plan F-4): the select starts at `''`.
 */
const valuesOf = (a: CustomerAddress, kinds: readonly string[]): Values => ({ kind: kinds.includes(a.kind) ? a.kind : '', line: a.line, city: a.city, postal_code: a.postal_code, country_code: a.country_code });

/**
 * The changed members only (a changed-only merge patch): each compared with the select or field as it STARTED, never with
 * the served value, so a prompt shown for an unknown served kind is never read as a change and never sent.
 */
function changesOf(initial: Values, values: Values): AddressChange {
  const out: AddressChange = {};
  if (values.kind !== initial.kind && values.kind !== '') out.kind = values.kind as 'billing' | 'shipping';
  for (const f of TEXTS) if (values[f] !== initial[f]) out[f] = values[f];
  return out;
}

/**
 * An address row's Edit dialog (Customers C-5a: customers-plan-c4c5.md Revision 1 R1-4 over Revision 0 J-8, F-4, F-7;
 * `customers-c4c5-ux-copy.md` Revision 1a > Revision 1 §3.3; the `ContactForm` dialog shape), props-only. Kind · Address
 * line · City · Postal code · Country, each starting at the row's value. Kind is a select over the served enum with
 * `kindSwitchHint` under it; a served kind outside the enum starts at `kindPrompt` and is not sent unless chosen. Country
 * is upper-cased as typed, with `countryHint`. `editHint` stands beside Save. The body is the changed members only; Save
 * is `aria-disabled` with *Nothing to save.* while nothing differs. An emptied field — empty, never spaces — shows its
 * sentence, sends nothing and takes focus (the first in field order). Everything else as `ContactForm`, under
 * **Not saved**; `interrupted` names the served row as it was before the press.
 */
export function AddressForm({ address, kinds, save, editCode, onClose, fallbackFocusRef, onSaveStarted }: AddressFormProps) {
  const initial = valuesOf(address, kinds);
  const known = kinds.includes(address.kind);
  const [values, setValues] = useState<Values>(initial);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<AddressField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [unsure, setUnsure] = useState(false);
  const [final, setFinal] = useState<'not-found' | 'parent-archived' | null>(null);
  const parentStale = useRef(false);
  const kindRef = useRef<HTMLSelectElement>(null);
  const lineRef = useRef<HTMLInputElement>(null);
  const cityRef = useRef<HTMLInputElement>(null);
  const postalRef = useRef<HTMLInputElement>(null);
  const countryRef = useRef<HTMLInputElement>(null);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const gen = useRef(0);
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: AddressField } | null>(null);
  useLayoutEffect(() => {
    if (focusTarget === null) return;
    ({ kind: kindRef, line: lineRef, city: cityRef, postal_code: postalRef, country_code: countryRef })[focusTarget.to].current?.focus();
  }, [focusTarget]);
  const focusField = (to: AddressField) => {
    gen.current += 1;
    setFocusTarget({ gen: gen.current, to });
  };
  const changes = changesOf(initial, values);
  const nothing = Object.keys(changes).length === 0;

  const show = (heading: string, body: ReactNode) => {
    setFieldErrors({});
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
  };

  const settle = (r: SaveAddressResult) => {
    switch (r.kind) {
      case 'saved':
        onClose({ saved: r.address, reread: true, parentStale: parentStale.current });
        return;
      case 'invalid': {
        if (r.formErrors.length > 0)
          show(
            NOT_SAVED,
            <>
              {r.formErrors.map((m, i) => <p key={i}>{m}</p>)}
              {marksAnyField(r.fieldErrors) ? <p key="mixed">{MIXED_FIELDS_TOO}</p> : null}
            </>,
          );
        else setNotice(null);
        setFieldErrors(r.fieldErrors);
        const first = ADDRESS_FIELDS.find((f) => r.fieldErrors[f] !== undefined);
        if (r.formErrors.length === 0 && first !== undefined) focusField(first);
        return;
      }
      case 'refused-archived':
        setUnsure(true);
        parentStale.current = true;
        setFinal('parent-archived');
        show(NOT_SAVED, EDIT_ADDRESS.archived);
        return;
      case 'archived-then-restored':
        setUnsure(true);
        parentStale.current = true;
        show(NOT_SAVED, EDIT_ADDRESS.archivedThenRestored);
        return;
      case 'archived-at-save':
        setUnsure(true);
        parentStale.current = true;
        show(NOT_SAVED, EDIT_ADDRESS.archivedAtSave);
        return;
      case 'refused':
        setUnsure(true);
        show(NOT_SAVED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        setUnsure(true);
        const label = (editCode !== null ? PERMISSION_LABELS[editCode]?.label : undefined) ?? '';
        show(
          NOT_SAVED,
          <p>
            {CUSTOMER_FORBIDDEN.before}
            <em>{label}</em>
            {CUSTOMER_FORBIDDEN.after}
          </p>,
        );
        return;
      }
      case 'not-found':
        // Removed elsewhere (or the customer is gone): closing re-reads the section (the `EntryForm` precedent).
        setUnsure(true);
        setFinal('not-found');
        show(NOT_SAVED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        setUnsure(true);
        show(COULD_NOT_REACH, EDIT_ADDRESS.interrupted(address.kind, address.line));
        return;
      case 'server-error':
        setUnsure(true);
        show(WENT_WRONG, EDIT_ADDRESS.interrupted(address.kind, address.line));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy || nothing || final !== null) return;
    // An emptied field is the person's own unfinished input, caught on every press: each shows its sentence, nothing is
    // sent, the page's region is not emptied, focus goes to the first in field order. Only empty: spaces are sent as typed.
    const empty = TEXTS.filter((f) => values[f] === '');
    if (empty.length > 0) {
      setNotice(null);
      setFieldErrors(Object.fromEntries(empty.map((f) => [f, REQUIRED[f]])) as FieldErrors<AddressField>);
      focusField(empty[0]!);
      return;
    }
    const sent = changes;
    onSaveStarted?.();
    setBusy(true);
    let r: SaveAddressResult;
    try {
      r = await save(address.id, sent);
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    settle(r);
  };

  const close = () => onClose({ saved: null, reread: unsure, parentStale: parentStale.current });
  const set = (f: Text, v: string) => setValues((cur) => ({ ...cur, [f]: v }));

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) close();
      }}
      title={EDIT_ADDRESS.title(address.kind, address.line)}
      description={null}
      initialFocusRef={kindRef}
      fallbackFocusRef={fallbackFocusRef}
      busy={busy}
      refusal={
        notice
          ? {
              id: String(notice.gen),
              headingRef: bannerRef,
              content: (
                <Banner tone="danger" live="none" heading={notice.heading} ref={bannerRef}>
                  {typeof notice.body === 'string' ? <p>{notice.body}</p> : notice.body}
                </Banner>
              ),
            }
          : null
      }
    >
      <form method="post" noValidate className={styles.form} onSubmit={(e) => void onSubmit(e)}>
        <SelectField
          ref={kindRef}
          id="address-edit-kind"
          name="kind"
          label={ADDRESSES_SECTION.fieldKind}
          options={kinds}
          labelOf={addressKindLabel}
          value={values.kind}
          onChange={(v) => setValues((cur) => ({ ...cur, kind: v }))}
          hints={[ADDRESSES_SECTION.kindSwitchHint]}
          error={fieldErrors.kind}
          prompt={known ? undefined : ADDRESSES_SECTION.kindPrompt}
        />
        <TextField ref={lineRef} id="address-edit-line" name="line" label={ADDRESSES_SECTION.fieldLine} error={fieldErrors.line} value={values.line} onChange={(e) => set('line', e.target.value)} autoComplete="off" />
        <TextField ref={cityRef} id="address-edit-city" name="city" label={ADDRESSES_SECTION.fieldCity} error={fieldErrors.city} value={values.city} onChange={(e) => set('city', e.target.value)} autoComplete="off" />
        <TextField ref={postalRef} id="address-edit-postal-code" name="postal_code" label={ADDRESSES_SECTION.fieldPostalCode} error={fieldErrors.postal_code} value={values.postal_code} onChange={(e) => set('postal_code', e.target.value)} autoComplete="off" spellCheck={false} />
        <TextField ref={countryRef} id="address-edit-country" name="country_code" label={ADDRESSES_SECTION.fieldCountry} hint={ADDRESSES_SECTION.countryHint} error={fieldErrors.country_code} value={values.country_code} onChange={(e) => set('country_code', e.target.value.toUpperCase())} autoComplete="off" spellCheck={false} />
        <p>{ADDRESSES_SECTION.editHint}</p>
        <div className={styles.actions}>
          {busy ? null : <Button onClick={close}>{final !== null ? EDIT_ADDRESS.close : EDIT_ADDRESS.cancel}</Button>}
          {final !== null ? null : (
            <Button type="submit" variant="primary" loading={busy} unavailableReason={nothing ? EDIT_ADDRESS.saveNothing : undefined}>
              {EDIT_ADDRESS.save}
            </Button>
          )}
        </div>
      </form>
    </Dialog>
  );
}
