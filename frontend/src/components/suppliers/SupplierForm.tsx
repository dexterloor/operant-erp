'use client';

import { useLayoutEffect, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, MIXED_FIELDS_TOO, NOT_SAVED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { SUPPLIER_FORBIDDEN, SUPPLIER_PAGE } from '@/copy/suppliers';
import type { Supplier, UpdateSupplierInput } from '@/lib/api/suppliers';
import { changedFields, marksAnyField } from '@/lib/forms/fieldErrors';
import type { FieldErrors } from '@/lib/results';
import { SUPPLIER_FIELDS, type SaveSupplierAction, type SaveSupplierResult, type SupplierField } from '@/lib/suppliers/results';
import styles from './Suppliers.module.css';

export interface SupplierFormProps {
  /** The served supplier (`GetSupplier`); a new one after a save's re-read resets the fields to it. */
  supplier: Supplier;
  save: SaveSupplierAction;
  /** `gateCode('UpdateSupplier')`, for the lost-permission sentence's label (B-10). */
  editCode: string | null;
  /**
   * Writes the page's status region (B-3 "After save"). `sentAsOf` is the Save's send stamp (S-7): the page drops the
   * sentence when a 204 of the person's own Delete supplier arrived after it (the items UX ruling M-3).
   */
  announce: (text: string, sentAsOf: number) => void;
  /** The page's `<h1>`: focus goes there after a save. */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /** Called once per Save answered `saved`, after the message is written. */
  onSaved?: () => void;
  /**
   * Called once per Save press that will send a request: after every guard, just before the request (FE-R6 commit A).
   * The page empties its status region here, in its own render before the answer, so a second identical "… was saved."
   * is a change to the region and is given again (an equal string written over itself is no DOM change).
   */
  onSaveStarted?: () => void;
  /**
   * A refused Save whose re-read found the supplier archived (`refused-archived`): the page is already archived and
   * read-only (this form is gone), so the page shows the server's sentence under **Not saved** — unless a 204 of the
   * person's own Delete supplier arrived after this Save was sent (`sentAsOf`, the T-17 ruling).
   */
  onRefusedArchived: (message: string, sentAsOf: number) => void;
}

/** Every field as the person types it: Payment terms is text here and becomes a number only in `supplierSave` (R9-2). */
type Values = Record<SupplierField, string>;
interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

/** The fields as the person first sees them: the served terms as its digits, so an untouched field equals its initial value. */
const valuesOf = (s: Supplier): Values => ({ code: s.code, name: s.name, country_code: s.country_code, currency_code: s.currency_code, payment_terms_days: String(s.payment_terms_days) });

/**
 * The supplier page's editor (suppliers-ux-copy.md Revision 1 — FINAL S-1 over Revision 0 B-3; Revision 8 R8-2),
 * props-only. Code, Name, Country, Currency and Payment terms are editable (Currency from S-6, suppliers-plan.md §R1-10;
 * before, plain text). Country and Currency are upper-cased as typed (the create form's shape). Currency carries ONE
 * unconditional hint, `currencyHint` (D-4): it states the rule and claims nothing about this supplier's entries, and the
 * field is never disabled — a change while the catalogue has entries is refused by the server after Save (R8), shown in
 * the banner as sent (ST-4f row 1 as amended). Payment terms starts as the served number's digits and is handed
 * to Save exactly as typed (the number conversion is `supplierSave`'s, R9-2); an emptied terms field is caught here and
 * no request is sent, on every press (`termsRequired`). Save is `aria-disabled` with *Nothing to save.* while nothing
 * differs; the body holds only the changed fields, compared exactly (`changedFields`). A 422's field sentences show
 * beside their fields and its others in the banner; a 409 and every other failure show in the banner, the server's
 * sentence as sent (ST-3). After `saved` the page's region says *"{code} was saved."* and focus goes to the `h1`; after
 * a refusal to the banner heading; after `invalid`, to the first field with an error, once that error is on screen.
 * A 401 renders nothing (the caller navigates to sign-in).
 */
export function SupplierForm({ supplier, save, editCode, announce, fallbackFocusRef, onSaved, onSaveStarted, onRefusedArchived }: SupplierFormProps) {
  const [loadedKey, setLoadedKey] = useState(supplier);
  const [values, setValues] = useState<Values>(() => valuesOf(supplier));
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<SupplierField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: 'banner' | 'h1' | SupplierField } | null>(null);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const codeRef = useRef<HTMLInputElement>(null);
  const nameRef = useRef<HTMLInputElement>(null);
  const countryRef = useRef<HTMLInputElement>(null);
  const currencyRef = useRef<HTMLInputElement>(null);
  const termsRef = useRef<HTMLInputElement>(null);
  const gen = useRef(0);

  // A new served supplier (the re-read after a save) is what the fields now edit.
  if (loadedKey !== supplier) {
    setLoadedKey(supplier);
    setValues(valuesOf(supplier));
  }

  // After the commit that renders the error or banner (focus never runs ahead of what it lands on).
  useLayoutEffect(() => {
    if (focusTarget === null) return;
    if (focusTarget.to === 'banner') bannerRef.current?.focus();
    else if (focusTarget.to === 'h1') fallbackFocusRef.current?.focus();
    else ({ code: codeRef, name: nameRef, country_code: countryRef, currency_code: currencyRef, payment_terms_days: termsRef })[focusTarget.to].current?.focus();
    // Once per target generation.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [focusTarget]);

  const initial = valuesOf(supplier);
  const changes = changedFields<UpdateSupplierInput, SupplierField>(SUPPLIER_FIELDS, initial, values);
  const nothing = Object.keys(changes).length === 0;

  const focusField = (f: SupplierField) => {
    gen.current += 1;
    setFocusTarget({ gen: gen.current, to: f });
  };
  // Every banner clears the field sentences first, so an earlier 422's sentence never stays beside its field under a
  // banner about something else. `invalid` with form errors sets its field errors after calling this.
  const show = (heading: string, body: ReactNode) => {
    setFieldErrors({});
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
    setFocusTarget({ gen: gen.current, to: 'banner' });
  };

  const settle = (r: SaveSupplierResult, code: string) => {
    switch (r.kind) {
      case 'saved':
        setFieldErrors({});
        setNotice(null);
        announce(SUPPLIER_PAGE.saved(r.supplier.code), r.sentAsOf);
        gen.current += 1;
        setFocusTarget({ gen: gen.current, to: 'h1' });
        onSaved?.();
        return;
      case 'invalid': {
        const first = SUPPLIER_FIELDS.find((f) => r.fieldErrors[f] !== undefined);
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
        onRefusedArchived(r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE, r.sentAsOf);
        return;
      case 'refused':
        show(NOT_SAVED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        const label = (editCode !== null ? PERMISSION_LABELS[editCode]?.label : undefined) ?? '';
        show(
          NOT_SAVED,
          <p>
            {SUPPLIER_FORBIDDEN.before}
            <em>{label}</em>
            {SUPPLIER_FORBIDDEN.after}
          </p>,
        );
        return;
      }
      case 'not-found':
        show(NOT_SAVED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, SUPPLIER_PAGE.interrupted(code));
        return;
      case 'server-error':
        show(WENT_WRONG, SUPPLIER_PAGE.interrupted(code));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy || nothing) return;
    // An emptied terms field is the person's own unfinished input, caught on every press; nothing is sent. Only the
    // empty field: the server's range rule is never copied here (R9-2, ST-4f).
    if (values.payment_terms_days === '') {
      setNotice(null);
      setFieldErrors({ payment_terms_days: SUPPLIER_PAGE.termsRequired });
      focusField('payment_terms_days');
      return;
    }
    onSaveStarted?.();
    setBusy(true);
    let r: SaveSupplierResult;
    try {
      r = await save(changes);
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    settle(r, supplier.code);
  };

  const set = (f: SupplierField, v: string) => setValues((cur) => ({ ...cur, [f]: v }));

  return (
    <form method="post" noValidate className={styles.form} onSubmit={(e) => void onSubmit(e)}>
      {notice ? (
        <Banner key={notice.gen} tone="danger" heading={notice.heading} ref={bannerRef}>
          {typeof notice.body === 'string' ? <p>{notice.body}</p> : notice.body}
        </Banner>
      ) : null}
      <TextField ref={codeRef} id="supplier-code" name="code" label={SUPPLIER_PAGE.code} hint={SUPPLIER_PAGE.codeHint} error={fieldErrors.code} value={values.code} onChange={(e) => set('code', e.target.value)} autoComplete="off" spellCheck={false} />
      <TextField ref={nameRef} id="supplier-name" name="name" label={SUPPLIER_PAGE.name} hint={SUPPLIER_PAGE.nameHint} error={fieldErrors.name} value={values.name} onChange={(e) => set('name', e.target.value)} autoComplete="off" />
      <TextField
        ref={countryRef}
        id="supplier-country"
        name="country_code"
        label={SUPPLIER_PAGE.country}
        hint={SUPPLIER_PAGE.countryHint}
        error={fieldErrors.country_code}
        value={values.country_code}
        // Upper-cased as typed, in the form's own onChange (TextField takes no transform), as on the warehouse page.
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
        hint={SUPPLIER_PAGE.currencyHint}
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
        <Button type="submit" variant="primary" loading={busy} unavailableReason={nothing ? SUPPLIER_PAGE.saveNothing : undefined}>
          {SUPPLIER_PAGE.save}
        </Button>
      </div>
    </form>
  );
}
