'use client';

import { useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, NOT_SAVED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { EDIT_LOCATION, kindLabel, WAREHOUSE_FORBIDDEN } from '@/copy/warehouses';
import type { StorageLocation } from '@/lib/api/warehouses';
import type { FieldErrors } from '@/lib/results';
import type { LocationField, SaveLocationAction, SaveLocationResult } from '@/lib/warehouses/results';
import styles from './Warehouses.module.css';

export interface LocationFormProps {
  /** The row being edited (there is no `GetStorageLocation`: the section's row is the served state, B-5). */
  location: StorageLocation;
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
 * Edit location (warehouses-ux-copy.md B-5; Revision 7 §2 step 4.4, R3-7), props-only. **The code only**: Kind is shown
 * as a label through `KIND_LABELS` (or the value as sent), with no quarantine hint and no Kind control until step 4.5, so
 * the body is exactly `{ code }` and cannot reach the quarantine release. Save is `aria-disabled` with *Nothing to save.*
 * while the code is unchanged (compared exactly). A 422's field sentence shows beside Code, everything else in the
 * dialog's refusal banner as sent (ST-3); the dialog stays open and Save stays available (B-7: P1 asks for another press).
 * An interruption says so; after it, or after any refusal, closing re-reads the section (L-8, RED M-1). A 401 renders nothing (the caller navigates).
 */
export function LocationForm({ location, save, editCode, onClose, fallbackFocusRef }: LocationFormProps) {
  const [code, setCode] = useState(location.code);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<LocationField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [unsure, setUnsure] = useState(false);
  const codeRef = useRef<HTMLInputElement>(null);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const gen = useRef(0);
  const nothing = code === location.code;

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
        if (r.formErrors.length === 0) codeRef.current?.focus();
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
      r = await save(location.id, { code });
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
      description={
        <dl className={styles.fields}>
          <dt>{EDIT_LOCATION.kind}</dt>
          <dd>{kindLabel(location.kind)}</dd>
        </dl>
      }
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
