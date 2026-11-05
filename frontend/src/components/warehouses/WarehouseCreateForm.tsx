'use client';

import { useLayoutEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import Link from 'next/link';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, NOT_CREATED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { NEW_WAREHOUSE, WAREHOUSE_FORBIDDEN, WAREHOUSE_PAGE } from '@/copy/warehouses';
import type { Warehouse } from '@/lib/api/warehouses';
import type { FieldErrors } from '@/lib/results';
import { CREATE_WAREHOUSE_FIELDS, type CreateWarehouseAction, type CreateWarehouseResult, type WarehouseField } from '@/lib/warehouses/results';
import styles from './Warehouses.module.css';

export interface WarehouseCreateFormProps {
  create: CreateWarehouseAction;
  /** `gateCode('CreateWarehouse')`, for the lost-permission sentence's label (B-8, L-9). */
  createCode: string | null;
  /** Called once per submit answered `created` (the page leaves the arrival message and navigates). */
  onCreated: (warehouse: Warehouse) => void;
}

type Values = Record<WarehouseField, string>;
interface Notice {
  gen: number;
  body: ReactNode;
  heading: string;
}

/**
 * `/warehouses/new`'s form (warehouses-ux-copy.md B-4; Revision 7 A-19), props-only. Code, Name and Country, all sent
 * as typed (Country upper-cased as typed, two letters). A 422's field sentences show beside their fields and its others
 * in the banner; a 409 and every other failure show in the banner under **Not created**, the server's sentence as sent
 * (W1, P1). A lost answer says B-4's interrupted sentence, never "try again" (the create does not replay). After
 * `invalid`, focus goes to the first field with an error; after a banner, to its heading. A 401 renders nothing.
 */
export function WarehouseCreateForm({ create, createCode, onCreated }: WarehouseCreateFormProps) {
  const [values, setValues] = useState<Values>({ code: '', name: '', country_code: '' });
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<WarehouseField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: 'banner' | WarehouseField } | null>(null);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const codeRef = useRef<HTMLInputElement>(null);
  const nameRef = useRef<HTMLInputElement>(null);
  const countryRef = useRef<HTMLInputElement>(null);
  const gen = useRef(0);

  useLayoutEffect(() => {
    if (focusTarget === null) return;
    if (focusTarget.to === 'banner') bannerRef.current?.focus();
    else ({ code: codeRef, name: nameRef, country_code: countryRef })[focusTarget.to].current?.focus();
  }, [focusTarget]);

  // Every banner clears the field sentences first (step 4.1 RED L-3).
  const show = (heading: string, body: ReactNode) => {
    setFieldErrors({});
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
    setFocusTarget({ gen: gen.current, to: 'banner' });
  };

  const settle = (r: CreateWarehouseResult, code: string) => {
    switch (r.kind) {
      case 'created':
        setFieldErrors({});
        setNotice(null);
        onCreated(r.warehouse);
        return;
      case 'invalid': {
        const first = CREATE_WAREHOUSE_FIELDS.find((f) => r.fieldErrors[f] !== undefined);
        if (r.formErrors.length > 0) {
          show(NOT_CREATED, r.formErrors.map((m) => <p key={m}>{m}</p>));
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
        show(NOT_CREATED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        const label = (createCode !== null ? PERMISSION_LABELS[createCode]?.label : undefined) ?? '';
        show(
          NOT_CREATED,
          <p>
            {WAREHOUSE_FORBIDDEN.before}
            <em>{label}</em>
            {WAREHOUSE_FORBIDDEN.after}
          </p>,
        );
        return;
      }
      case 'not-found':
        show(NOT_CREATED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, NEW_WAREHOUSE.interrupted(code));
        return;
      case 'server-error':
        show(WENT_WRONG, NEW_WAREHOUSE.interrupted(code));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy) return;
    setBusy(true);
    const sent = { ...values };
    let r: CreateWarehouseResult;
    try {
      r = await create(sent);
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    settle(r, sent.code);
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
      <TextField ref={nameRef} id="warehouse-name" name="name" label={WAREHOUSE_PAGE.name} error={fieldErrors.name} value={values.name} onChange={(e) => set('name', e.target.value)} autoComplete="off" />
      <TextField
        ref={countryRef}
        id="warehouse-country"
        name="country_code"
        label={WAREHOUSE_PAGE.country}
        hint={WAREHOUSE_PAGE.countryHint}
        error={fieldErrors.country_code}
        value={values.country_code}
        onChange={(e) => set('country_code', e.target.value.toUpperCase())}
        maxLength={2}
        autoComplete="off"
        spellCheck={false}
      />
      <div className={styles.actions}>
        <Button type="submit" variant="primary" loading={busy}>
          {NEW_WAREHOUSE.submit}
        </Button>
        <Link href="/warehouses">{NEW_WAREHOUSE.cancel}</Link>
      </div>
    </form>
  );
}
