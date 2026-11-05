'use client';

import { useLayoutEffect, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, NOT_SAVED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { WAREHOUSE_FORBIDDEN, WAREHOUSE_PAGE } from '@/copy/warehouses';
import type { UpdateWarehouseInput, Warehouse } from '@/lib/api/warehouses';
import { changedFields } from '@/lib/forms/fieldErrors';
import type { FieldErrors } from '@/lib/results';
import { WAREHOUSE_FIELDS, type SaveWarehouseAction, type SaveWarehouseResult, type WarehouseField } from '@/lib/warehouses/results';
import styles from './Warehouses.module.css';

export interface WarehouseFormProps {
  /** The served warehouse (`GetWarehouse`); a new one after a save's re-read resets the fields to it. */
  warehouse: Warehouse;
  save: SaveWarehouseAction;
  /** `gateCode('UpdateWarehouse')`, for the lost-permission sentence's label (B-8, L-9). */
  editCode: string | null;
  /** Writes the page's status region (B-3 "After save"). */
  announce: (text: string) => void;
  /** The page's `<h1>`: focus goes there after a save. */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /** Called once per Save answered `saved`, after the message is written. */
  onSaved?: () => void;
}

type Values = Required<Pick<UpdateWarehouseInput, WarehouseField>>;
interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

const valuesOf = (w: Warehouse): Values => ({ code: w.code, name: w.name, country_code: w.country_code });

/**
 * The warehouse page's editor (warehouses-ux-copy.md B-3; Revision 7 A-19), props-only. Code, Name and Country — and
 * nothing else (G-36, L-5). Save is `aria-disabled` with *Nothing to save.* while nothing differs from the served
 * warehouse; the body holds only the changed fields, compared exactly (`changedFields`). A 422's field sentences show
 * beside their fields and its others in the banner; a 409 and every other failure show in the banner, the server's
 * sentence as sent (ST-3). After `saved` the page's region says *"{code} was saved."* and focus goes to the `h1`; after
 * a refusal focus goes to the banner heading; after `invalid`, to the first field with an error (D-4, UX TO CONFIRM).
 * A 401 renders nothing (the caller navigates to sign-in).
 */
export function WarehouseForm({ warehouse, save, editCode, announce, fallbackFocusRef, onSaved }: WarehouseFormProps) {
  const [loadedKey, setLoadedKey] = useState(warehouse);
  const [values, setValues] = useState<Values>(() => valuesOf(warehouse));
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<WarehouseField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: 'banner' | 'h1' | WarehouseField } | null>(null);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const codeRef = useRef<HTMLInputElement>(null);
  const nameRef = useRef<HTMLInputElement>(null);
  const countryRef = useRef<HTMLInputElement>(null);
  const gen = useRef(0);

  // A new served warehouse (the re-read after a save) is what the fields now edit.
  if (loadedKey !== warehouse) {
    setLoadedKey(warehouse);
    setValues(valuesOf(warehouse));
  }

  useLayoutEffect(() => {
    if (focusTarget === null) return;
    if (focusTarget.to === 'banner') bannerRef.current?.focus();
    else if (focusTarget.to === 'h1') fallbackFocusRef.current?.focus();
    else ({ code: codeRef, name: nameRef, country_code: countryRef })[focusTarget.to].current?.focus();
    // Once per target generation.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [focusTarget]);

  const initial = valuesOf(warehouse);
  const changes = changedFields<UpdateWarehouseInput, WarehouseField>(WAREHOUSE_FIELDS, initial, values);
  const nothing = Object.keys(changes).length === 0;

  // Every banner clears the field sentences first, so an earlier 422's sentence never stays beside its field under a
  // banner about something else (RED L-3). `invalid` with form errors sets its field errors after calling this.
  const show = (heading: string, body: ReactNode) => {
    setFieldErrors({});
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
    setFocusTarget({ gen: gen.current, to: 'banner' });
  };

  const settle = (r: SaveWarehouseResult, code: string) => {
    switch (r.kind) {
      case 'saved':
        setFieldErrors({});
        setNotice(null);
        announce(WAREHOUSE_PAGE.saved(r.warehouse.code));
        gen.current += 1;
        setFocusTarget({ gen: gen.current, to: 'h1' });
        onSaved?.();
        return;
      case 'invalid': {
        const first = WAREHOUSE_FIELDS.find((f) => r.fieldErrors[f] !== undefined);
        if (r.formErrors.length > 0) {
          show(NOT_SAVED, r.formErrors.map((m) => <p key={m}>{m}</p>));
          setFieldErrors(r.fieldErrors);
          return;
        }
        setFieldErrors(r.fieldErrors);
        setNotice(null);
        gen.current += 1;
        if (first) setFocusTarget({ gen: gen.current, to: first });
        return;
      }
      case 'refused':
        show(NOT_SAVED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
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
        show(NOT_SAVED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, WAREHOUSE_PAGE.interrupted(code));
        return;
      case 'server-error':
        show(WENT_WRONG, WAREHOUSE_PAGE.interrupted(code));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy || nothing) return;
    setBusy(true);
    let r: SaveWarehouseResult;
    try {
      r = await save(changes);
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    settle(r, warehouse.code);
  };

  const set = (f: WarehouseField, v: string) => setValues((cur) => ({ ...cur, [f]: v }));

  return (
    <form method="post" noValidate className={styles.form} onSubmit={(e) => void onSubmit(e)}>
      {notice ? (
        <Banner key={notice.gen} tone="danger" heading={notice.heading} ref={bannerRef}>
          {typeof notice.body === 'string' ? <p>{notice.body}</p> : notice.body}
        </Banner>
      ) : null}
      <TextField ref={codeRef} id="warehouse-code" name="code" label={WAREHOUSE_PAGE.code} hint={WAREHOUSE_PAGE.codeHint} error={fieldErrors.code} value={values.code} onChange={(e) => set('code', e.target.value)} autoComplete="off" spellCheck={false} />
      <TextField ref={nameRef} id="warehouse-name" name="name" label={WAREHOUSE_PAGE.name} hint={WAREHOUSE_PAGE.nameHint} error={fieldErrors.name} value={values.name} onChange={(e) => set('name', e.target.value)} autoComplete="off" />
      <TextField
        ref={countryRef}
        id="warehouse-country"
        name="country_code"
        label={WAREHOUSE_PAGE.country}
        hint={WAREHOUSE_PAGE.countryHint}
        error={fieldErrors.country_code}
        value={values.country_code}
        // B-3 (a): upper-cased as typed, in the form's own onChange (TextField takes no transform).
        onChange={(e) => set('country_code', e.target.value.toUpperCase())}
        maxLength={2}
        autoComplete="off"
        spellCheck={false}
      />
      <div className={styles.actions}>
        <Button type="submit" variant="primary" loading={busy} unavailableReason={nothing ? WAREHOUSE_PAGE.saveNothing : undefined}>
          {WAREHOUSE_PAGE.save}
        </Button>
      </div>
    </form>
  );
}
