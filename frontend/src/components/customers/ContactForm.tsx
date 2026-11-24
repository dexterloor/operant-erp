'use client';

import { useId, useLayoutEffect, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Dialog } from '@/components/ui/Dialog';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, MIXED_FIELDS_TOO, NOT_SAVED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { CONTACTS_SECTION, CUSTOMER_FORBIDDEN, EDIT_CONTACT } from '@/copy/customers';
import type { CustomerContact } from '@/lib/api/customers';
import { CONTACT_FIELDS, type ContactChange, type ContactField, type SaveContactAction, type SaveContactResult } from '@/lib/customers/results';
import { marksAnyField } from '@/lib/forms/fieldErrors';
import type { FieldErrors } from '@/lib/results';
import styles from './Customers.module.css';

export interface ContactFormProps {
  /** The row being edited (there is no single-contact read: the section's row is the served state). */
  contact: CustomerContact;
  save: SaveContactAction;
  /** `gateCode('UpdateCustomerContact')`, for the lost-permission sentence's label (B-10). */
  editCode: string | null;
  /**
   * Closes the dialog. `saved` is the 200 body and `sentPrimary` the `is_primary` the body carried (`undefined` when it
   * carried none); `reread` is true when what the section shows may be out of date (a save, an interruption, a refusal, a
   * 404); `parentStale` is true when the page must re-read the customer (copy R1a-1): after any answer whose customer
   * re-read was archived, and after any `customer.is_archived` answer whatever the re-read said. The flag is raised when
   * that answer arrives and survives until the dialog goes — a later press that succeeds does not lower it.
   */
  onClose: (outcome: { saved: CustomerContact | null; sentPrimary: boolean | undefined; reread: boolean; parentStale: boolean }) => void;
  /** Where focus goes on close when the row's Edit button is gone (the section's heading). */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /**
   * Called once per Save press that will send a request: after every guard, just before the request (FE-R9). The page
   * empties its status region here, through its counted writer.
   */
  onSaveStarted?: () => void;
}

type Text = Exclude<ContactField, 'is_primary'>;
interface Values {
  name: string;
  email: string;
  phone: string;
  is_primary: boolean;
}
interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

/** The fields as the row shows them: a null email or phone is an empty field. */
const valuesOf = (c: CustomerContact): Values => ({ name: c.name, email: c.email ?? '', phone: c.phone ?? '', is_primary: c.is_primary });

/**
 * The changed members only (a changed-only merge patch, F-10): Name as typed; an Email or Phone emptied by the person is
 * `null` (it clears the served value), any other change as typed; Primary contact only when the box differs from the row.
 */
function changesOf(initial: Values, values: Values): ContactChange {
  const out: ContactChange = {};
  if (values.name !== initial.name) out.name = values.name;
  for (const f of ['email', 'phone'] as const) if (values[f] !== initial[f]) out[f] = values[f] === '' ? null : values[f];
  if (values.is_primary !== initial.is_primary) out.is_primary = values.is_primary;
  return out;
}

/**
 * A contact row's Edit dialog (Customers C-4a: customers-plan-c4c5.md Revision 1 R1-2 I-11a over Revision 0 I-11, F-3,
 * F-6, F-10; `customers-c4c5-ux-copy.md` Revision 1a > Revision 1 §2.4; the `EntryForm` dialog shape), props-only. Name ·
 * Email (optional) · Phone (optional) · Primary contact, each starting at the row's value, the field words read from
 * `CONTACTS_SECTION` (copy R1-1). Under the box: `primaryClearHint` when the row is the primary contact, else
 * `primaryHint`. The body is the changed members only; Save is `aria-disabled` with *Nothing to save.* while nothing
 * differs. An emptied Name — and only an empty one — shows `nameRequired` beside it, sends nothing and takes focus. A 422's
 * field sentences show beside their fields, the first takes focus; everything else shows in the dialog's banner under
 * **Not saved** (ST-3):
 * - any refusal whose customer re-read is archived → the interim `archived`, Close only (copy D-15);
 * - refused `customer.is_archived`, re-read live → `archivedThenRestored`; failed → `archivedAtSave`; the edits and Save
 *   stay;
 * - any other refusal → the server's sentence as sent (or `REFUSED_WITHOUT_MESSAGE`); Save stays;
 * - a 404 (the contact or the customer is gone) → `PAGE_STATES.notFoundInline`, Close only; closing re-reads the section;
 * - a 403 → `CUSTOMER_FORBIDDEN` around the permission's label; Save stays;
 * - an interruption → `interrupted(name shown before the press)` under `COULD_NOT_REACH` / `WENT_WRONG`; Save stays.
 * A 401 renders nothing (the caller navigates).
 */
export function ContactForm({ contact, save, editCode, onClose, fallbackFocusRef, onSaveStarted }: ContactFormProps) {
  const initial = valuesOf(contact);
  const [values, setValues] = useState<Values>(initial);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<ContactField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [unsure, setUnsure] = useState(false);
  // A refusal that leaves nothing to press: Save goes and Cancel becomes Close (the DeleteDialog `final` shape).
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
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: ContactField } | null>(null);
  useLayoutEffect(() => {
    if (focusTarget === null) return;
    ({ name: nameRef, email: emailRef, phone: phoneRef, is_primary: primaryRef })[focusTarget.to].current?.focus();
  }, [focusTarget]);
  const focusField = (to: ContactField) => {
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

  const settle = (r: SaveContactResult, sentPrimary: boolean | undefined) => {
    switch (r.kind) {
      case 'saved':
        onClose({ saved: r.contact, sentPrimary, reread: true, parentStale: parentStale.current });
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
        const first = CONTACT_FIELDS.find((f) => r.fieldErrors[f] !== undefined);
        if (r.formErrors.length === 0 && first !== undefined) focusField(first);
        return;
      }
      case 'refused-archived':
        setUnsure(true);
        parentStale.current = true;
        setFinal('parent-archived');
        show(NOT_SAVED, EDIT_CONTACT.archived);
        return;
      case 'archived-then-restored':
        setUnsure(true);
        parentStale.current = true;
        show(NOT_SAVED, EDIT_CONTACT.archivedThenRestored);
        return;
      case 'archived-at-save':
        setUnsure(true);
        parentStale.current = true;
        show(NOT_SAVED, EDIT_CONTACT.archivedAtSave);
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
        // Removed elsewhere (or the customer is gone): closing re-reads the section (the `EntryForm` precedent, D-7).
        setUnsure(true);
        setFinal('not-found');
        show(NOT_SAVED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        setUnsure(true);
        show(COULD_NOT_REACH, EDIT_CONTACT.interrupted(contact.name));
        return;
      case 'server-error':
        setUnsure(true);
        show(WENT_WRONG, EDIT_CONTACT.interrupted(contact.name));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy || nothing || final !== null) return;
    // An emptied Name is the person's own unfinished input, caught on every press (T-C6): nothing is sent and the page's
    // region is not emptied (the press will not send). Only the empty field: spaces are sent as typed.
    if (values.name === '') {
      setNotice(null);
      setFieldErrors({ name: CONTACTS_SECTION.nameRequired });
      focusField('name');
      return;
    }
    const sent = changes;
    onSaveStarted?.();
    setBusy(true);
    let r: SaveContactResult;
    try {
      r = await save(contact.id, sent);
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    settle(r, sent.is_primary);
  };

  const close = () => onClose({ saved: null, sentPrimary: undefined, reread: unsure, parentStale: parentStale.current });
  const set = (f: Text, v: string) => setValues((cur) => ({ ...cur, [f]: v }));

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) close();
      }}
      title={EDIT_CONTACT.title(contact.name)}
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
        <TextField ref={nameRef} id="contact-edit-name" name="name" label={CONTACTS_SECTION.fieldName} error={fieldErrors.name} value={values.name} onChange={(e) => set('name', e.target.value)} autoComplete="off" />
        <TextField ref={emailRef} id="contact-edit-email" name="email" label={CONTACTS_SECTION.fieldEmail} error={fieldErrors.email} value={values.email} onChange={(e) => set('email', e.target.value)} autoComplete="off" spellCheck={false} />
        <TextField ref={phoneRef} id="contact-edit-phone" name="phone" label={CONTACTS_SECTION.fieldPhone} error={fieldErrors.phone} value={values.phone} onChange={(e) => set('phone', e.target.value)} autoComplete="off" spellCheck={false} />
        <div>
          <Checkbox
            ref={primaryRef}
            name="is_primary"
            label={CONTACTS_SECTION.fieldPrimary}
            checked={values.is_primary}
            onCheckedChange={(next) => setValues((cur) => ({ ...cur, is_primary: next }))}
            describedBy={[hintId, fieldErrors.is_primary !== undefined ? primaryErrorId : ''].join(' ').trim()}
          />
          {/* By the SERVED row (copy rows 13–14), not by the box as ticked now. */}
          <p id={hintId}>{contact.is_primary ? CONTACTS_SECTION.primaryClearHint : CONTACTS_SECTION.primaryHint}</p>
          {fieldErrors.is_primary !== undefined ? <p id={primaryErrorId}>{fieldErrors.is_primary}</p> : null}
        </div>
        <div className={styles.actions}>
          {busy ? null : <Button onClick={close}>{final !== null ? EDIT_CONTACT.close : EDIT_CONTACT.cancel}</Button>}
          {final !== null ? null : (
            <Button type="submit" variant="primary" loading={busy} unavailableReason={nothing ? EDIT_CONTACT.saveNothing : undefined}>
              {EDIT_CONTACT.save}
            </Button>
          )}
        </div>
      </form>
    </Dialog>
  );
}
