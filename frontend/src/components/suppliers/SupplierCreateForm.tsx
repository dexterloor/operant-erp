'use client';

import { useLayoutEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import Link from 'next/link';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, MIXED_FIELDS_TOO, NOT_CREATED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { NEW_SUPPLIER, SUPPLIER_FORBIDDEN, SUPPLIER_PAGE } from '@/copy/suppliers';
import type { Supplier } from '@/lib/api/suppliers';
import { marksAnyField } from '@/lib/forms/fieldErrors';
import type { FieldErrors } from '@/lib/results';
import { CREATE_SUPPLIER_FIELDS, type CreateSupplierAction, type CreateSupplierField, type CreateSupplierResult, type CreateSupplierValues } from '@/lib/suppliers/results';
import styles from './Suppliers.module.css';

export interface SupplierCreateFormProps {
  create: CreateSupplierAction;
  /** `gateCode('CreateSupplier')`, for the lost-permission sentence's label (SUPPLIER_FORBIDDEN). */
  createCode: string | null;
  /** Called once per submit answered `created` (the page leaves the arrival message and navigates). */
  onCreated: (supplier: Supplier) => void;
}

interface Notice {
  gen: number;
  body: ReactNode;
  heading: string;
}

/**
 * `/suppliers/new`'s form (suppliers-ux-copy.md Revision 1 — FINAL S-2; suppliers-plan.md §R1-4 S-2), props-only: the
 * `WarehouseCreateForm` shape plus Currency and Payment terms. Code, Name, Country and Currency are sent as typed (Country
 * and Currency upper-cased as typed). Payment terms starts EMPTY (H-2 (a): a field is prefilled only with its documented
 * "none" value, and 30 days is a business choice, `suppliers-po.md` §5); an empty field is refused here with
 * `termsRequired` on every press and nothing is sent. The day count is handed over as typed; `supplierCreate` turns it
 * into a number (R9-2). A 422's field sentences show beside their fields and its others in the banner; a 409 and every
 * other failure show in the banner under **Not created**, the server's sentence as sent. A lost answer says the
 * interrupted sentence (search the list), never "try again": the create does not replay. After `invalid`, focus goes to
 * the first field with an error in screen order; after a banner, to its heading. A 401 renders nothing.
 */
export function SupplierCreateForm({ create, createCode, onCreated }: SupplierCreateFormProps) {
  const [values, setValues] = useState<CreateSupplierValues>({ code: '', name: '', country_code: '', currency_code: '', payment_terms_days: '' });
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<CreateSupplierField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: 'banner' | CreateSupplierField } | null>(null);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const codeRef = useRef<HTMLInputElement>(null);
  const nameRef = useRef<HTMLInputElement>(null);
  const countryRef = useRef<HTMLInputElement>(null);
  const currencyRef = useRef<HTMLInputElement>(null);
  const termsRef = useRef<HTMLInputElement>(null);
  const gen = useRef(0);

  useLayoutEffect(() => {
    if (focusTarget === null) return;
    if (focusTarget.to === 'banner') bannerRef.current?.focus();
    else ({ code: codeRef, name: nameRef, country_code: countryRef, currency_code: currencyRef, payment_terms_days: termsRef })[focusTarget.to].current?.focus();
  }, [focusTarget]);

  const focusField = (f: CreateSupplierField) => {
    gen.current += 1;
    setFocusTarget({ gen: gen.current, to: f });
  };

  // Every banner clears the field sentences first (step 4.1 RED L-3).
  const show = (heading: string, body: ReactNode) => {
    setFieldErrors({});
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
    setFocusTarget({ gen: gen.current, to: 'banner' });
  };

  // The interrupted sentence names the code as sent; one that is empty after trimming names none (UX `interruptedNoCode`).
  const interrupted = (code: string) => (code.trim() !== '' ? NEW_SUPPLIER.interrupted(code) : NEW_SUPPLIER.interruptedNoCode);

  const settle = (r: CreateSupplierResult, code: string) => {
    switch (r.kind) {
      case 'created':
        setFieldErrors({});
        setNotice(null);
        onCreated(r.supplier);
        return;
      case 'invalid': {
        const first = CREATE_SUPPLIER_FIELDS.find((f) => r.fieldErrors[f] !== undefined);
        if (r.formErrors.length > 0) {
          show(
            NOT_CREATED,
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
      case 'refused':
        show(NOT_CREATED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        const label = (createCode !== null ? PERMISSION_LABELS[createCode]?.label : undefined) ?? '';
        show(
          NOT_CREATED,
          <p>
            {SUPPLIER_FORBIDDEN.before}
            <em>{label}</em>
            {SUPPLIER_FORBIDDEN.after}
          </p>,
        );
        return;
      }
      case 'not-found':
        show(NOT_CREATED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, interrupted(code));
        return;
      case 'server-error':
        show(WENT_WRONG, interrupted(code));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy) return;
    // An empty terms field is the person's own unfinished input, caught on every press; nothing is sent. Only the empty
    // field: the server's range rule is never copied here (R9-2, ST-4f).
    if (values.payment_terms_days === '') {
      setNotice(null);
      setFieldErrors({ payment_terms_days: SUPPLIER_PAGE.termsRequired });
      focusField('payment_terms_days');
      return;
    }
    setBusy(true);
    const sent = { ...values };
    let r: CreateSupplierResult;
    try {
      r = await create(sent);
    } catch {
      r = { kind: 'unreachable' };
    }
    // A 201 keeps the form busy: the page is already navigating to the new record, and a press before it lands would send
    // a new key and be refused as a duplicate under "Not created" (suppliers-s2-red.md L-1). Every other answer gives it back.
    if (r.kind !== 'created') setBusy(false);
    settle(r, sent.code);
  };

  const set = (f: CreateSupplierField, v: string) => setValues((cur) => ({ ...cur, [f]: v }));

  return (
    <form method="post" noValidate className={styles.form} onSubmit={(e) => void onSubmit(e)}>
      {notice ? (
        <Banner key={notice.gen} tone="danger" heading={notice.heading} ref={bannerRef}>
          {typeof notice.body === 'string' ? <p>{notice.body}</p> : notice.body}
        </Banner>
      ) : null}
      <TextField ref={codeRef} id="supplier-code" name="code" label={SUPPLIER_PAGE.code} hint={SUPPLIER_PAGE.codeHint} error={fieldErrors.code} value={values.code} onChange={(e) => set('code', e.target.value)} autoComplete="off" spellCheck={false} />
      {/* No name hint on create: nothing names the supplier yet (UX S-2). */}
      <TextField ref={nameRef} id="supplier-name" name="name" label={SUPPLIER_PAGE.name} error={fieldErrors.name} value={values.name} onChange={(e) => set('name', e.target.value)} autoComplete="off" />
      <TextField
        ref={countryRef}
        id="supplier-country"
        name="country_code"
        label={SUPPLIER_PAGE.country}
        hint={SUPPLIER_PAGE.countryHint}
        error={fieldErrors.country_code}
        value={values.country_code}
        onChange={(e) => set('country_code', e.target.value.toUpperCase())}
        maxLength={2}
        autoComplete="off"
        spellCheck={false}
      />
      <TextField
        ref={currencyRef}
        id="supplier-currency"
        name="currency_code"
        label={SUPPLIER_PAGE.currency}
        hint={NEW_SUPPLIER.currencyHintNew}
        error={fieldErrors.currency_code}
        value={values.currency_code}
        onChange={(e) => set('currency_code', e.target.value.toUpperCase())}
        maxLength={3}
        autoComplete="off"
        spellCheck={false}
      />
      <TextField
        ref={termsRef}
        id="supplier-payment-terms"
        name="payment_terms_days"
        label={SUPPLIER_PAGE.paymentTerms}
        hint={SUPPLIER_PAGE.termsHint}
        error={fieldErrors.payment_terms_days}
        value={values.payment_terms_days}
        onChange={(e) => set('payment_terms_days', e.target.value)}
        // Never type="number" (locale-mangled, spinner, `e` notation): text, with a digit keypad on touch.
        inputMode="numeric"
        autoComplete="off"
        spellCheck={false}
      />
      <div className={styles.actions}>
        <Button type="submit" variant="primary" loading={busy}>
          {NEW_SUPPLIER.submit}
        </Button>
        <Link href="/suppliers">{NEW_SUPPLIER.cancel}</Link>
      </div>
    </form>
  );
}
