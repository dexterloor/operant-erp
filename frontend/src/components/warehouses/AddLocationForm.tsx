'use client';

import { useLayoutEffect, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, NOT_ADDED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { ADD_LOCATION, KIND_LABELS, WAREHOUSE_FORBIDDEN } from '@/copy/warehouses';
import type { StorageLocation } from '@/lib/api/warehouses';
import type { FieldErrors } from '@/lib/results';
import type { CreateLocationAction, CreateLocationResult, LocationField } from '@/lib/warehouses/results';
import { KindField } from './KindField';
import styles from './Warehouses.module.css';

export interface AddLocationFormProps {
  /** The warehouse's code, for the title (B-5 *Add a location to {warehouse code}*). */
  warehouseCode: string;
  /** The served kinds, in served order (`wireEnum('StorageLocation.CreateStorageLocationInput', 'kind')`). */
  kinds: readonly string[];
  create: CreateLocationAction;
  /** `gateCode('CreateStorageLocation')`, for the lost-permission sentence's label (B-8, L-9). */
  createCode: string | null;
  /** Closes the dialog. `reread` is true when what the section shows may be out of date (a create, an interruption, a refusal). */
  onClose: (outcome: { created: StorageLocation | null; reread: boolean }) => void;
  /** Where focus goes on close when the opener is gone (the section's heading). */
  fallbackFocusRef: RefObject<HTMLElement | null>;
}

interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

/**
 * Add location (warehouses-ux-copy.md B-5; Revision 7 §2 step 4.5, A-19, A-21), props-only. Code and Kind, both sent as
 * chosen. Kind starts unselected and is required (A-21 "Preselection" + S-7; UX `kind-preselection-ux.md`: do not
 * preselect): an empty first option *Choose a kind* (no `disabled`; the form is `noValidate`), and Add location pressed
 * with nothing chosen sends NO request and shows *Choose a kind for this location.* beside Kind through the same
 * `fieldErrors` path as a 422, with focus on Kind. Each submit is a new request with a new Idempotency-Key (the caller's
 * action mints it; the create does not replay). A 422's field sentences show beside Code and Kind; a 409 and every other
 * failure show in the dialog's banner under **Not added**, the server's sentence as sent (L1, L3, P1); the dialog stays
 * open and Add location stays available (B-7: P1 asks for another press). A lost answer says B-5's interrupted sentence,
 * never "try again". After a refusal or an interruption, closing re-reads the section. A 401 renders nothing.
 */
export function AddLocationForm({ warehouseCode, kinds, create, createCode, onClose, fallbackFocusRef }: AddLocationFormProps) {
  const [code, setCode] = useState('');
  const [kind, setKind] = useState('');
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<LocationField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [unsure, setUnsure] = useState(false);
  const codeRef = useRef<HTMLInputElement>(null);
  const kindRef = useRef<HTMLSelectElement>(null);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const gen = useRef(0);
  // Field focus moves only after the commit that renders the field's error and links it (aria-invalid,
  // aria-describedby), so a screen reader hears the sentence when focus lands (step 4.5 RED-2 M-1; the step-3
  // WarehouseCreateForm `focusTarget` pattern).
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: LocationField } | null>(null);
  useLayoutEffect(() => {
    if (focusTarget === null) return;
    (focusTarget.to === 'code' ? codeRef : kindRef).current?.focus();
  }, [focusTarget]);
  const focusField = (to: LocationField) => {
    gen.current += 1;
    setFocusTarget({ gen: gen.current, to });
  };
  const hints = [KIND_LABELS[kind]?.optionHelp].filter((h): h is string => h !== undefined);

  const show = (heading: string, body: ReactNode) => {
    setFieldErrors({});
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
  };

  const settle = (r: CreateLocationResult, sent: string) => {
    switch (r.kind) {
      case 'created':
        onClose({ created: r.location, reread: true });
        return;
      case 'invalid':
        if (r.formErrors.length > 0) show(NOT_ADDED, r.formErrors.map((m) => <p key={m}>{m}</p>));
        else setNotice(null);
        setFieldErrors(r.fieldErrors);
        if (r.formErrors.length === 0) focusField(r.fieldErrors.code !== undefined ? 'code' : 'kind');
        return;
      case 'refused':
        // L3 is a "stale page" refusal (the warehouse was archived meanwhile): closing re-reads.
        setUnsure(true);
        show(NOT_ADDED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        setUnsure(true);
        const label = (createCode !== null ? PERMISSION_LABELS[createCode]?.label : undefined) ?? '';
        show(
          NOT_ADDED,
          <p>
            {WAREHOUSE_FORBIDDEN.before}
            <em>{label}</em>
            {WAREHOUSE_FORBIDDEN.after}
          </p>,
        );
        return;
      }
      case 'not-found':
        setUnsure(true);
        show(NOT_ADDED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        setUnsure(true);
        show(COULD_NOT_REACH, ADD_LOCATION.interrupted(sent));
        return;
      case 'server-error':
        setUnsure(true);
        show(WENT_WRONG, ADD_LOCATION.interrupted(sent));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy) return;
    if (kind === '') {
      // The client refusal (A-21, S-7): beside Kind, as a 422's field sentence is, on every press with nothing chosen
      // (no request, ever). Any earlier banner is cleared; so is an earlier 422's Code sentence (the server says it again).
      setNotice(null);
      setFieldErrors({ kind: ADD_LOCATION.kindRequired });
      focusField('kind');
      return;
    }
    setBusy(true);
    const sent = code;
    let r: CreateLocationResult;
    try {
      r = await create({ code, kind });
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    settle(r, sent);
  };

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) onClose({ created: null, reread: unsure });
      }}
      title={ADD_LOCATION.title(warehouseCode)}
      description={null}
      initialFocusRef={codeRef}
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
        <TextField ref={codeRef} id="new-location-code" name="code" label={ADD_LOCATION.code} hint={ADD_LOCATION.codeHint} error={fieldErrors.code} value={code} onChange={(e) => setCode(e.target.value)} autoComplete="off" spellCheck={false} />
        <KindField ref={kindRef} id="new-location-kind" label={ADD_LOCATION.kind} kinds={kinds} value={kind} onChange={setKind} hints={hints} error={fieldErrors.kind} prompt={ADD_LOCATION.kindPrompt} />
        <div className={styles.actions}>
          {busy ? null : <Button onClick={() => onClose({ created: null, reread: unsure })}>{ADD_LOCATION.cancel}</Button>}
          <Button type="submit" variant="primary" loading={busy}>
            {ADD_LOCATION.submit}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
