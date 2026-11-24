'use client';

import { useLayoutEffect, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { SelectField } from '@/components/ui/SelectField';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, MIXED_FIELDS_TOO, NOT_ADDED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { ADD_ADDRESS, ADDRESSES_SECTION, CUSTOMER_FORBIDDEN, addressKindLabel } from '@/copy/customers';
import type { CustomerAddress } from '@/lib/api/customers';
import { ADDRESS_FIELDS, type AddressField, type AddressValues, type CreateAddressAction, type CreateAddressResult } from '@/lib/customers/results';
import { marksAnyField } from '@/lib/forms/fieldErrors';
import type { FieldErrors } from '@/lib/results';
import styles from './Customers.module.css';

export interface AddAddressFormProps {
  /** The page's CURRENT customer's served code (the dialog's title). */
  customerCode: string;
  /** The served kinds, in served order (`wireEnum('CustomerAddress.CreateCustomerAddressInput', 'kind')`). */
  kinds: readonly string[];
  create: CreateAddressAction;
  /** `gateCode('CreateCustomerAddress')`, for the lost-permission sentence's label (B-10). */
  createCode: string | null;
  /**
   * Closes the dialog. `created` is the 201 body; `reread` is true when what the section shows may be out of date (an add,
   * an interruption, a refusal); `parentStale` is true when the page must re-read the customer (copy R1a-1): after any
   * answer whose customer re-read was archived, after any `customer.is_archived` answer whatever the re-read said, and
   * after a 404. The flag is raised when that answer arrives and survives until the dialog goes.
   */
  onClose: (outcome: { created: CustomerAddress | null; reread: boolean; parentStale: boolean }) => void;
  /** Where focus goes on close when the button that opened the dialog is gone (the section's heading). */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /** Called once per Add press that will send a request: after every guard, just before the request (FE-R9). */
  onAddStarted?: () => void;
}

type Values = { kind: string; line: string; city: string; postal_code: string; country_code: string };
interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

/** Each field's required sentence (copy rows 62, 68–71), in field order. */
const REQUIRED: Record<AddressField, string> = {
  kind: ADDRESSES_SECTION.kindRequired,
  line: ADDRESSES_SECTION.lineRequired,
  city: ADDRESSES_SECTION.cityRequired,
  postal_code: ADDRESSES_SECTION.postalCodeRequired,
  country_code: ADDRESSES_SECTION.countryRequired,
};

/**
 * Add address (Customers C-5a: customers-plan-c4c5.md Revision 1 R1-4 over Revision 0 J-8, F-7; `customers-c4c5-ux-copy.md`
 * Revision 1a > Revision 1 §3.2; the `AddContactForm` dialog shape), props-only. Kind · Address line · City · Postal code ·
 * Country, the field words read from `ADDRESSES_SECTION` (copy R1-1). Kind is a select over the served enum, in served
 * order, labelled `addressKindLabel`, its first option `kindPrompt`, nothing preselected. Country is upper-cased as typed,
 * with `countryHint` beside it. A press with any field empty — empty, never spaces — shows every empty field's sentence at
 * once, sends nothing and moves focus to the first in field order. A 422's field sentences show beside their fields, the
 * first takes focus; everything else shows in the dialog's banner under **Not added** (ST-3), as `AddContactForm`.
 * A 401 renders nothing (the caller navigates).
 */
export function AddAddressForm({ customerCode, kinds, create, createCode, onClose, fallbackFocusRef, onAddStarted }: AddAddressFormProps) {
  const [values, setValues] = useState<Values>({ kind: '', line: '', city: '', postal_code: '', country_code: '' });
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<AddressField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [unsure, setUnsure] = useState(false);
  // A refusal that leaves nothing to press: Add goes and Cancel becomes Close (the DeleteDialog `final` shape).
  const [final, setFinal] = useState<'not-found' | 'parent-archived' | null>(null);
  // Copy R1a-1: raised when an answer means the page's customer reading may be stale; survives until the dialog goes.
  const parentStale = useRef(false);
  const kindRef = useRef<HTMLSelectElement>(null);
  const lineRef = useRef<HTMLInputElement>(null);
  const cityRef = useRef<HTMLInputElement>(null);
  const postalRef = useRef<HTMLInputElement>(null);
  const countryRef = useRef<HTMLInputElement>(null);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const gen = useRef(0);
  // Field focus moves only after the commit that renders the field's sentence and links it (the `LocationForm` rule).
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: AddressField } | null>(null);
  useLayoutEffect(() => {
    if (focusTarget === null) return;
    ({ kind: kindRef, line: lineRef, city: cityRef, postal_code: postalRef, country_code: countryRef })[focusTarget.to].current?.focus();
  }, [focusTarget]);
  const focusField = (to: AddressField) => {
    gen.current += 1;
    setFocusTarget({ gen: gen.current, to });
  };

  const show = (heading: string, body: ReactNode) => {
    setFieldErrors({});
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
  };

  const settle = (r: CreateAddressResult, sent: { kind: string; line: string }) => {
    switch (r.kind) {
      case 'created':
        onClose({ created: r.address, reread: true, parentStale: parentStale.current });
        return;
      case 'invalid': {
        if (r.formErrors.length > 0)
          show(
            NOT_ADDED,
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
        show(NOT_ADDED, ADD_ADDRESS.archived);
        return;
      case 'archived-then-restored':
        setUnsure(true);
        parentStale.current = true;
        show(NOT_ADDED, ADD_ADDRESS.archivedThenRestored);
        return;
      case 'archived-at-add':
        setUnsure(true);
        parentStale.current = true;
        show(NOT_ADDED, ADD_ADDRESS.archivedAtAdd);
        return;
      case 'refused':
        setUnsure(true);
        show(NOT_ADDED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        setUnsure(true);
        const label = (createCode !== null ? PERMISSION_LABELS[createCode]?.label : undefined) ?? '';
        show(
          NOT_ADDED,
          <p>
            {CUSTOMER_FORBIDDEN.before}
            <em>{label}</em>
            {CUSTOMER_FORBIDDEN.after}
          </p>,
        );
        return;
      }
      case 'not-found':
        // The customer is gone (copy R1-3): the page re-reads it when the dialog goes (ST-5).
        setUnsure(true);
        parentStale.current = true;
        setFinal('not-found');
        show(NOT_ADDED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        setUnsure(true);
        show(COULD_NOT_REACH, ADD_ADDRESS.interrupted(sent.kind, sent.line));
        return;
      case 'server-error':
        setUnsure(true);
        show(WENT_WRONG, ADD_ADDRESS.interrupted(sent.kind, sent.line));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy || final !== null) return;
    // Every empty field is the person's own unfinished input, caught on every press: each shows its sentence at once,
    // nothing is sent, the page's region is not emptied, and focus goes to the first in field order. Only empty: spaces
    // are sent as typed.
    const empty = ADDRESS_FIELDS.filter((f) => values[f] === '');
    if (empty.length > 0) {
      setNotice(null);
      setFieldErrors(Object.fromEntries(empty.map((f) => [f, REQUIRED[f]])) as FieldErrors<AddressField>);
      focusField(empty[0]!);
      return;
    }
    const sent = { kind: values.kind, line: values.line };
    onAddStarted?.();
    setBusy(true);
    let r: CreateAddressResult;
    try {
      r = await create(values as AddressValues);
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    settle(r, sent);
  };

  const close = () => onClose({ created: null, reread: unsure, parentStale: parentStale.current });
  const set = (f: Exclude<AddressField, 'kind'>, v: string) => setValues((cur) => ({ ...cur, [f]: v }));

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) close();
      }}
      title={ADD_ADDRESS.title(customerCode)}
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
        <SelectField ref={kindRef} id="address-add-kind" name="kind" label={ADDRESSES_SECTION.fieldKind} options={kinds} labelOf={addressKindLabel} value={values.kind} onChange={(v) => setValues((cur) => ({ ...cur, kind: v }))} hints={[]} error={fieldErrors.kind} prompt={ADDRESSES_SECTION.kindPrompt} />
        <TextField ref={lineRef} id="address-add-line" name="line" label={ADDRESSES_SECTION.fieldLine} error={fieldErrors.line} value={values.line} onChange={(e) => set('line', e.target.value)} autoComplete="off" />
        <TextField ref={cityRef} id="address-add-city" name="city" label={ADDRESSES_SECTION.fieldCity} error={fieldErrors.city} value={values.city} onChange={(e) => set('city', e.target.value)} autoComplete="off" />
        <TextField ref={postalRef} id="address-add-postal-code" name="postal_code" label={ADDRESSES_SECTION.fieldPostalCode} error={fieldErrors.postal_code} value={values.postal_code} onChange={(e) => set('postal_code', e.target.value)} autoComplete="off" spellCheck={false} />
        <TextField ref={countryRef} id="address-add-country" name="country_code" label={ADDRESSES_SECTION.fieldCountry} hint={ADDRESSES_SECTION.countryHint} error={fieldErrors.country_code} value={values.country_code} onChange={(e) => set('country_code', e.target.value.toUpperCase())} autoComplete="off" spellCheck={false} />
        <div className={styles.actions}>
          {busy ? null : <Button onClick={close}>{final !== null ? ADD_ADDRESS.close : ADD_ADDRESS.cancel}</Button>}
          {final !== null ? null : (
            <Button type="submit" variant="primary" loading={busy}>
              {ADD_ADDRESS.submit}
            </Button>
          )}
        </div>
      </form>
    </Dialog>
  );
}
