'use client';

import { useLayoutEffect, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, MIXED_FIELDS_TOO, NOT_SAVED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { CUSTOMER_FORBIDDEN, CUSTOMER_PAGE } from '@/copy/customers';
import type { Customer, UpdateCustomerInput } from '@/lib/api/customers';
import { CUSTOMER_FIELDS, type CustomerField, type SaveCustomerAction, type SaveCustomerResult } from '@/lib/customers/results';
import { changedFields, marksAnyField } from '@/lib/forms/fieldErrors';
import type { FieldErrors } from '@/lib/results';
import styles from './Customers.module.css';

export interface CustomerFormProps {
  /** The served customer (`GetCustomer`); a new one after a save's re-read resets the fields to it. */
  customer: Customer;
  save: SaveCustomerAction;
  /** `gateCode('UpdateCustomer')`, for the lost-permission sentence's label (B-10). */
  editCode: string | null;
  /** Writes the page's status region (B-3 "After save"). */
  announce: (text: string) => void;
  /** The page's `<h1>`: focus goes there after a save. */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /** Called once per Save answered `saved`, after the message is written. */
  onSaved?: () => void;
  /**
   * Called once per Save press that will send a request: after every guard, just before the request (FE-R6 commit A).
   * The page empties its status region here, so a second identical "… was saved." is a change to the region.
   */
  onSaveStarted?: () => void;
  /**
   * A refused Save whose re-read found the customer archived (`refused-archived`): the page is already archived and
   * read-only (this form is gone), so the page shows **Not saved** + `archivedOnSave` (K-15) above the ST-9 banner.
   */
  onRefusedArchived: () => void;
}

/** Every editable field as the person types it: Payment terms is text here and becomes a number only in `customerSave` (R9-2). */
type Values = Record<CustomerField, string>;
interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

/** The fields as the person first sees them: the served terms as its digits, so an untouched field equals its initial value. */
const valuesOf = (c: Customer): Values => ({ code: c.code, country_code: c.country_code, currency_code: c.currency_code, payment_terms_days: String(c.payment_terms_days) });

/** Each editable field's emptied sentence (copy Revision 1 — FINAL R1-1 K-6…K-9). */
const REQUIRED: Record<CustomerField, string> = {
  code: CUSTOMER_PAGE.codeRequired,
  country_code: CUSTOMER_PAGE.countryRequired,
  currency_code: CUSTOMER_PAGE.currencyRequired,
  payment_terms_days: CUSTOMER_PAGE.termsRequired,
};

/**
 * The customer page's editor (Customers C-2a: customers-plan.md Revision 1 — FINAL R1-5 + Revision 1c;
 * `customers-c2a-ux-copy.md` Revision 1a > Revision 1 — FINAL; the `SupplierForm` shape), props-only. Code, Country,
 * Currency and Payment terms are editable, each with its hint. **Name is plain text under its label**, not a disabled
 * input, with no hint (copy D-1): a rename ships at C-2b, with the shared-name dialog, so the body never carries `name`.
 * Country and Currency are upper-cased as typed (D-8). Payment terms starts as the served number's digits and is handed to
 * Save exactly as typed (the number conversion is `customerSave`'s, R9-2). An emptied field — and only an emptied one,
 * never spaces, a range or a shape (D-7) — is caught here on every press and nothing is sent: every emptied field shows its
 * sentence at once, and focus goes to the first in field order. Save is `aria-disabled` with *Nothing to save.* while
 * nothing differs; the body holds only the changed fields, compared exactly (`changedFields`). A 422's field sentences show
 * beside their fields and its others in the banner; a 409 (R2 code taken, P1, …) and every other failure show in the
 * banner, the server's sentence as sent (ST-3, copy N-a). A Save refused `customer.is_archived` whose re-read is live shows
 * `archivedThenRestored` (K-16), and one whose re-read failed `archivedAtSave` (K-17) — never the server's R3 (D-6). After
 * `saved` the page's region says *"{code} was saved."* with the served code and focus goes to the `h1`; after a refusal to
 * the banner heading; after a field sentence, to the first field with one, once it is on screen. A 401 renders nothing
 * (the caller navigates to sign-in).
 */
export function CustomerForm({ customer, save, editCode, announce, fallbackFocusRef, onSaved, onSaveStarted, onRefusedArchived }: CustomerFormProps) {
  const [loadedKey, setLoadedKey] = useState(customer);
  const [values, setValues] = useState<Values>(() => valuesOf(customer));
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<CustomerField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: 'banner' | 'h1' | CustomerField } | null>(null);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const codeRef = useRef<HTMLInputElement>(null);
  const countryRef = useRef<HTMLInputElement>(null);
  const currencyRef = useRef<HTMLInputElement>(null);
  const termsRef = useRef<HTMLInputElement>(null);
  const gen = useRef(0);

  // A new served customer (the re-read after a save) is what the fields now edit.
  if (loadedKey !== customer) {
    setLoadedKey(customer);
    setValues(valuesOf(customer));
  }

  // After the commit that renders the error or banner (focus never runs ahead of what it lands on).
  useLayoutEffect(() => {
    if (focusTarget === null) return;
    if (focusTarget.to === 'banner') bannerRef.current?.focus();
    else if (focusTarget.to === 'h1') fallbackFocusRef.current?.focus();
    else ({ code: codeRef, country_code: countryRef, currency_code: currencyRef, payment_terms_days: termsRef })[focusTarget.to].current?.focus();
    // Once per target generation.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [focusTarget]);

  const initial = valuesOf(customer);
  const changes = changedFields<UpdateCustomerInput, CustomerField>(CUSTOMER_FIELDS, initial, values);
  const nothing = Object.keys(changes).length === 0;

  const focusField = (f: CustomerField) => {
    gen.current += 1;
    setFocusTarget({ gen: gen.current, to: f });
  };
  // Every banner clears the field sentences first, so an earlier sentence never stays beside its field under a banner
  // about something else. `invalid` with form errors sets its field errors after calling this.
  const show = (heading: string, body: ReactNode) => {
    setFieldErrors({});
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
    setFocusTarget({ gen: gen.current, to: 'banner' });
  };

  const settle = (r: SaveCustomerResult, code: string) => {
    switch (r.kind) {
      case 'saved':
        setFieldErrors({});
        setNotice(null);
        announce(CUSTOMER_PAGE.saved(r.customer.code));
        gen.current += 1;
        setFocusTarget({ gen: gen.current, to: 'h1' });
        onSaved?.();
        return;
      case 'invalid': {
        const first = CUSTOMER_FIELDS.find((f) => r.fieldErrors[f] !== undefined);
        if (r.formErrors.length > 0) {
          show(
            NOT_SAVED,
            <>
              {r.formErrors.map((m, i) => <p key={i}>{m}</p>)}
              {marksAnyField(r.fieldErrors) ? <p key="mixed">{MIXED_FIELDS_TOO}</p> : null}
            </>,
          );
          setFieldErrors(r.fieldErrors);
          return;
        }
        setFieldErrors(r.fieldErrors);
        setNotice(null);
        if (first) focusField(first);
        return;
      }
      case 'refused-archived':
        onRefusedArchived();
        return;
      case 'archived-then-restored':
        show(NOT_SAVED, CUSTOMER_PAGE.archivedThenRestored);
        return;
      case 'archived-at-save':
        show(NOT_SAVED, CUSTOMER_PAGE.archivedAtSave);
        return;
      case 'refused':
        show(NOT_SAVED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
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
        show(NOT_SAVED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, CUSTOMER_PAGE.interrupted(code));
        return;
      case 'server-error':
        show(WENT_WRONG, CUSTOMER_PAGE.interrupted(code));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy || nothing) return;
    // An emptied field is the person's own unfinished input, caught on every press; nothing is sent. Only the empty field:
    // the server's other rules are never copied here (R9-2, ST-4f, copy D-7). Every emptied field at once; focus to the first.
    const emptied = CUSTOMER_FIELDS.filter((f) => values[f] === '');
    if (emptied.length > 0) {
      setNotice(null);
      setFieldErrors(Object.fromEntries(emptied.map((f) => [f, REQUIRED[f]])));
      focusField(emptied[0]!);
      return;
    }
    onSaveStarted?.();
    setBusy(true);
    let r: SaveCustomerResult;
    try {
      r = await save(changes);
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    settle(r, customer.code);
  };

  const set = (f: CustomerField, v: string) => setValues((cur) => ({ ...cur, [f]: v }));

  return (
    <form method="post" noValidate className={styles.form} onSubmit={(e) => void onSubmit(e)}>
      {notice ? (
        <Banner key={notice.gen} tone="danger" heading={notice.heading} ref={bannerRef}>
          {typeof notice.body === 'string' ? <p>{notice.body}</p> : notice.body}
        </Banner>
      ) : null}
      <TextField ref={codeRef} id="customer-code" name="code" label={CUSTOMER_PAGE.code} hint={CUSTOMER_PAGE.codeHint} error={fieldErrors.code} value={values.code} onChange={(e) => set('code', e.target.value)} autoComplete="off" spellCheck={false} />
      {/* Name: plain text under its label in the field order, never a disabled input, no hint (copy D-1, §1.1). */}
      <dl className={styles.fields}>
        <dt>{CUSTOMER_PAGE.name}</dt>
        <dd>{customer.name}</dd>
      </dl>
      <TextField
        ref={countryRef}
        id="customer-country"
        name="country_code"
        label={CUSTOMER_PAGE.country}
        hint={CUSTOMER_PAGE.countryHint}
        error={fieldErrors.country_code}
        value={values.country_code}
        // Upper-cased as typed, in the form's own onChange (TextField takes no transform), as on the supplier page (D-8).
        onChange={(e) => set('country_code', e.target.value.toUpperCase())}
        maxLength={2}
        autoComplete="off"
        spellCheck={false}
      />
      <TextField
        ref={currencyRef}
        id="customer-currency"
        name="currency_code"
        label={CUSTOMER_PAGE.currency}
        hint={CUSTOMER_PAGE.currencyHint}
        error={fieldErrors.currency_code}
        value={values.currency_code}
        onChange={(e) => set('currency_code', e.target.value.toUpperCase())}
        maxLength={3}
        autoComplete="off"
        spellCheck={false}
      />
      <TextField
        ref={termsRef}
        id="customer-payment-terms"
        name="payment_terms_days"
        label={CUSTOMER_PAGE.paymentTerms}
        hint={CUSTOMER_PAGE.termsHint}
        error={fieldErrors.payment_terms_days}
        value={values.payment_terms_days}
        onChange={(e) => set('payment_terms_days', e.target.value)}
        // Never type="number" (locale-mangled, spinner, `e` notation): text, with a digit keypad on touch.
        inputMode="numeric"
        autoComplete="off"
        spellCheck={false}
      />
      <div className={styles.actions}>
        <Button type="submit" variant="primary" loading={busy} unavailableReason={nothing ? CUSTOMER_PAGE.saveNothing : undefined}>
          {CUSTOMER_PAGE.save}
        </Button>
      </div>
    </form>
  );
}
