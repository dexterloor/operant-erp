'use client';

import { useLayoutEffect, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, NOT_SAVED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { EDIT_LOCATION, KIND_LABELS, WAREHOUSE_FORBIDDEN } from '@/copy/warehouses';
import type { StorageLocation, UpdateStorageLocationInput } from '@/lib/api/warehouses';
import { changedFields } from '@/lib/forms/fieldErrors';
import type { FieldErrors } from '@/lib/results';
import { LOCATION_FIELDS, type LocationField, type SaveLocationAction, type SaveLocationResult } from '@/lib/warehouses/results';
import { KindField } from './KindField';
import styles from './Warehouses.module.css';

export interface LocationFormProps {
  /** The row being edited (there is no `GetStorageLocation`: the section's row is the served state, B-5). */
  location: StorageLocation;
  /** The served kinds, in served order (`wireEnum('StorageLocation.UpdateStorageLocationInput.jsonMergePatch', 'kind')`). */
  kinds: readonly string[];
  save: SaveLocationAction;
  /** `gateCode('UpdateStorageLocation')`, for the lost-permission sentence's label (B-8, L-9). */
  editCode: string | null;
  /** Closes the dialog. `reread` is true when what the section shows may be out of date (a save, an interruption, or a refusal). */
  onClose: (outcome: { saved: StorageLocation | null; reread: boolean }) => void;
  /** Where focus goes on close when the row's Edit button is gone (the section's heading). */
  fallbackFocusRef: RefObject<HTMLElement | null>;
}

interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

/**
 * Edit location (warehouses-ux-copy.md B-5, B-6; Revision 7 §2 steps 4.4, 4.5), props-only. Code and Kind (a select over
 * the served kinds, A-21). The body is the changed members only (`changedFields`, compared exactly), so a code-only change
 * sends no `kind` and cannot reach the quarantine release; a kind change is decided by the server alone (L13 shows as
 * sent under **Not saved**, B-6 — nothing here compares a kind). The Kind hint is the served CURRENT kind's `editHint`,
 * then the chosen kind's `optionHelp`, both through `KIND_LABELS`. Save is `aria-disabled` with *Nothing to save.* while
 * nothing changed. A 422's field sentence shows beside Code, everything else in the
 * dialog's refusal banner as sent (ST-3); the dialog stays open and Save stays available (B-7: P1 asks for another press).
 * An interruption says so; after it, or after any refusal, closing re-reads the section (L-8, RED M-1). A 401 renders nothing (the caller navigates).
 */
export function LocationForm({ location, kinds, save, editCode, onClose, fallbackFocusRef }: LocationFormProps) {
  const [code, setCode] = useState(location.code);
  const [kind, setKind] = useState(location.kind);
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
  const changes = changedFields<UpdateStorageLocationInput, LocationField>(LOCATION_FIELDS, { code: location.code, kind: location.kind }, { code, kind });
  const nothing = Object.keys(changes).length === 0;
  const hints = [KIND_LABELS[location.kind]?.editHint, KIND_LABELS[kind]?.optionHelp].filter((h): h is string => h !== undefined);

  const show = (heading: string, body: ReactNode) => {
    setFieldErrors({});
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
  };

  const settle = (r: SaveLocationResult) => {
    switch (r.kind) {
      case 'saved':
        onClose({ saved: r.location, reread: true });
        return;
      case 'invalid':
        if (r.formErrors.length > 0) show(NOT_SAVED, r.formErrors.map((m) => <p key={m}>{m}</p>));
        else setNotice(null);
        setFieldErrors(r.fieldErrors);
        if (r.formErrors.length === 0) focusField(r.fieldErrors.code !== undefined ? 'code' : 'kind');
        return;
      case 'refused':
        // L4/L5 are "stale page" refusals (the location or its warehouse was archived meanwhile): closing re-reads (RED M-1).
        setUnsure(true);
        show(NOT_SAVED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        setUnsure(true);
        const label = (editCode !== null ? PERMISSION_LABELS[editCode]?.label : undefined) ?? '';
        show(
          NOT_SAVED,
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
        show(NOT_SAVED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        setUnsure(true);
        show(COULD_NOT_REACH, EDIT_LOCATION.interrupted(location.code));
        return;
      case 'server-error':
        setUnsure(true);
        show(WENT_WRONG, EDIT_LOCATION.interrupted(location.code));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy || nothing) return;
    setBusy(true);
    let r: SaveLocationResult;
    try {
      r = await save(location.id, changes);
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    settle(r);
  };

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) onClose({ saved: null, reread: unsure });
      }}
      title={EDIT_LOCATION.title(location.code)}
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
        <TextField ref={codeRef} id="location-code" name="code" label={EDIT_LOCATION.code} hint={EDIT_LOCATION.codeHint} error={fieldErrors.code} value={code} onChange={(e) => setCode(e.target.value)} autoComplete="off" spellCheck={false} />
        <KindField ref={kindRef} id="location-kind" label={EDIT_LOCATION.kind} kinds={kinds} value={kind} onChange={setKind} hints={hints} error={fieldErrors.kind} />
        <div className={styles.actions}>
          {busy ? null : <Button onClick={() => onClose({ saved: null, reread: unsure })}>{EDIT_LOCATION.cancel}</Button>}
          <Button type="submit" variant="primary" loading={busy} unavailableReason={nothing ? EDIT_LOCATION.saveNothing : undefined}>
            {EDIT_LOCATION.save}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
