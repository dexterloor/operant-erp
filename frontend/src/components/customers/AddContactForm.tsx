'use client';

import { useId, useLayoutEffect, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Dialog } from '@/components/ui/Dialog';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, MIXED_FIELDS_TOO, NOT_ADDED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { ADD_CONTACT, CONTACTS_SECTION, CUSTOMER_FORBIDDEN } from '@/copy/customers';
import type { CustomerContact } from '@/lib/api/customers';
import { CONTACT_FIELDS, type ContactField, type ContactValues, type CreateContactAction, type CreateContactResult } from '@/lib/customers/results';
import { marksAnyField } from '@/lib/forms/fieldErrors';
import type { FieldErrors } from '@/lib/results';
import styles from './Customers.module.css';

export interface AddContactFormProps {
  /** The page's CURRENT customer's served code (the dialog's title). */
  customerCode: string;
  create: CreateContactAction;
  /** `gateCode('CreateCustomerContact')`, for the lost-permission sentence's label (B-10). */
  createCode: string | null;
  /**
   * Closes the dialog. `created` is the 201 body and `sentPrimary` whether the box was ticked when it was sent; `reread`
   * is true when what the section shows may be out of date (an add, an interruption, a refusal); `parentStale` is true
   * when the page must re-read the customer (copy R1a-1): after any answer whose customer re-read was archived, after any
   * `customer.is_archived` answer whatever the re-read said, and after a 404. The flag is raised when that answer arrives
   * and survives until the dialog goes — a later press that succeeds does not lower it.
   */
  onClose: (outcome: { created: CustomerContact | null; sentPrimary: boolean; reread: boolean; parentStale: boolean }) => void;
  /** Where focus goes on close when the button that opened the dialog is gone (the section's heading). */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /**
   * Called once per Add press that will send a request: after every guard, just before the request (FE-R9). The page
   * empties its status region here, through its counted writer.
   */
  onAddStarted?: () => void;
}

type Text = Exclude<ContactField, 'is_primary'>;
interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

/**
 * Add contact (Customers C-4a: customers-plan-c4c5.md Revision 1 R1-2 I-11a over Revision 0 I-11, F-3, F-6, F-10;
 * `customers-c4c5-ux-copy.md` Revision 1a > Revision 1 §2.3; the `AddEntryForm` dialog shape), props-only. Name · Email
 * (optional) · Phone (optional) · Primary contact, the field words read from `CONTACTS_SECTION` (copy R1-1), the box under
 * `primaryHint` always. An empty Name — and only an empty one, never spaces — shows `nameRequired` beside it, sends
 * nothing and takes focus (T-C6). The action builds the body (an empty Email or Phone omitted, `is_primary` a boolean). A
 * 422's field sentences show beside their fields, the first takes focus; everything else shows in the dialog's banner
 * under **Not added** (ST-3):
 * - any refusal whose customer re-read is archived → the interim `archived`, Close only (copy D-15);
 * - refused `customer.is_archived`, re-read live → `archivedThenRestored`; failed → `archivedAtAdd`; the typed values and
 *   Add stay;
 * - any other refusal → the server's sentence as sent (or `REFUSED_WITHOUT_MESSAGE`); Add stays;
 * - a 404 (the customer is gone) → `PAGE_STATES.notFoundInline`, Close only;
 * - a 403 → `CUSTOMER_FORBIDDEN` around the permission's label; Add stays;
 * - an interruption → `interrupted(typed name)` under `COULD_NOT_REACH` / `WENT_WRONG`; Add stays (a fresh key per submit).
 * A 401 renders nothing (the caller navigates).
 */
export function AddContactForm({ customerCode, create, createCode, onClose, fallbackFocusRef, onAddStarted }: AddContactFormProps) {
  const [values, setValues] = useState<ContactValues>({ name: '', email: '', phone: '', is_primary: false });
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<ContactField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [unsure, setUnsure] = useState(false);
  // A refusal that leaves nothing to press: Add goes and Cancel becomes Close (the DeleteDialog `final` shape).
  const [final, setFinal] = useState<'not-found' | 'parent-archived' | null>(null);
  // Copy R1a-1: raised when an answer means the page's customer reading may be stale; survives until the dialog goes.
  const parentStale = useRef(false);
  const hintId = useId();
  const primaryErrorId = useId();
  const nameRef = useRef<HTMLInputElement>(null);
  const emailRef = useRef<HTMLInputElement>(null);
  const phoneRef = useRef<HTMLInputElement>(null);
  const primaryRef = useRef<HTMLInputElement>(null);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const gen = useRef(0);
  // Field focus moves only after the commit that renders the field's sentence and links it (the `LocationForm` rule).
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: ContactField } | null>(null);
  useLayoutEffect(() => {
    if (focusTarget === null) return;
    ({ name: nameRef, email: emailRef, phone: phoneRef, is_primary: primaryRef })[focusTarget.to].current?.focus();
  }, [focusTarget]);
  const focusField = (to: ContactField) => {
    gen.current += 1;
    setFocusTarget({ gen: gen.current, to });
  };

  const show = (heading: string, body: ReactNode) => {
    setFieldErrors({});
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
  };

  const settle = (r: CreateContactResult, sent: { name: string; primary: boolean }) => {
    switch (r.kind) {
      case 'created':
        onClose({ created: r.contact, sentPrimary: sent.primary, reread: true, parentStale: parentStale.current });
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
        const first = CONTACT_FIELDS.find((f) => r.fieldErrors[f] !== undefined);
        if (r.formErrors.length === 0 && first !== undefined) focusField(first);
        return;
      }
      case 'refused-archived':
        setUnsure(true);
        parentStale.current = true;
        setFinal('parent-archived');
        show(NOT_ADDED, ADD_CONTACT.archived);
        return;
      case 'archived-then-restored':
        setUnsure(true);
        parentStale.current = true;
        show(NOT_ADDED, ADD_CONTACT.archivedThenRestored);
        return;
      case 'archived-at-add':
        setUnsure(true);
        parentStale.current = true;
        show(NOT_ADDED, ADD_CONTACT.archivedAtAdd);
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
        show(COULD_NOT_REACH, ADD_CONTACT.interrupted(sent.name));
        return;
      case 'server-error':
        setUnsure(true);
        show(WENT_WRONG, ADD_CONTACT.interrupted(sent.name));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy || final !== null) return;
    // An empty Name is the person's own unfinished input, caught on every press (T-C6): nothing is sent and the page's
    // region is not emptied (the press will not send). Only the empty field: spaces are sent as typed.
    if (values.name === '') {
      setNotice(null);
      setFieldErrors({ name: CONTACTS_SECTION.nameRequired });
      focusField('name');
      return;
    }
    const sent = { name: values.name, primary: values.is_primary };
    onAddStarted?.();
    setBusy(true);
    let r: CreateContactResult;
    try {
      r = await create(values);
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    settle(r, sent);
  };

  const close = () => onClose({ created: null, sentPrimary: false, reread: unsure, parentStale: parentStale.current });
  const set = (f: Text, v: string) => setValues((cur) => ({ ...cur, [f]: v }));

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) close();
      }}
      title={ADD_CONTACT.title(customerCode)}
      description={null}
      initialFocusRef={nameRef}
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
        <TextField ref={nameRef} id="contact-add-name" name="name" label={CONTACTS_SECTION.fieldName} error={fieldErrors.name} value={values.name} onChange={(e) => set('name', e.target.value)} autoComplete="off" />
        <TextField ref={emailRef} id="contact-add-email" name="email" label={CONTACTS_SECTION.fieldEmail} error={fieldErrors.email} value={values.email} onChange={(e) => set('email', e.target.value)} autoComplete="off" spellCheck={false} />
        <TextField ref={phoneRef} id="contact-add-phone" name="phone" label={CONTACTS_SECTION.fieldPhone} error={fieldErrors.phone} value={values.phone} onChange={(e) => set('phone', e.target.value)} autoComplete="off" spellCheck={false} />
        <div>
          <Checkbox
            ref={primaryRef}
            name="is_primary"
            label={CONTACTS_SECTION.fieldPrimary}
            checked={values.is_primary}
            onCheckedChange={(next) => setValues((cur) => ({ ...cur, is_primary: next }))}
            describedBy={[hintId, fieldErrors.is_primary !== undefined ? primaryErrorId : ''].join(' ').trim()}
          />
          <p id={hintId}>{CONTACTS_SECTION.primaryHint}</p>
          {fieldErrors.is_primary !== undefined ? <p id={primaryErrorId}>{fieldErrors.is_primary}</p> : null}
        </div>
        <div className={styles.actions}>
          {busy ? null : <Button onClick={close}>{final !== null ? ADD_CONTACT.close : ADD_CONTACT.cancel}</Button>}
          {final !== null ? null : (
            <Button type="submit" variant="primary" loading={busy}>
              {ADD_CONTACT.submit}
            </Button>
          )}
        </div>
      </form>
    </Dialog>
  );
}
