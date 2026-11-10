'use client';

import { useLayoutEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import Link from 'next/link';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { SelectField } from '@/components/ui/SelectField';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, NOT_CREATED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { ITEM_FORBIDDEN, ITEM_PAGE, NEW_ITEM, unitLabel } from '@/copy/items';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import type { Item } from '@/lib/api/items';
import { CREATE_ITEM_FIELDS, QUANTITY_FIELDS, type CreateItemAction, type CreateItemBody, type CreateItemField, type CreateItemResult } from '@/lib/items/results';
import type { FieldErrors } from '@/lib/results';
import styles from './Items.module.css';

export interface ItemCreateFormProps {
  create: CreateItemAction;
  /** `gateCode('CreateItem')`, for the lost-permission sentence's label (B-9). */
  createCode: string | null;
  /** The served units, in served order (`wireEnum`, read by the page). */
  units: readonly string[];
  /** Called once per submit answered `created` (the page leaves the arrival message and navigates). */
  onCreated: (item: Item) => void;
}

type Values = Record<CreateItemField, string>;
interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

const INITIAL: Values = { sku: '', name: '', unit_of_measure_code: '', reorder_point: '0', reorder_quantity: '0', safety_stock: '0' };

const NUMBER_LABELS: Record<(typeof QUANTITY_FIELDS)[number], { label: string; hint: string }> = {
  reorder_point: { label: ITEM_PAGE.reorderPoint, hint: ITEM_PAGE.reorderPointHint },
  reorder_quantity: { label: ITEM_PAGE.reorderQuantity, hint: ITEM_PAGE.reorderQuantityHint },
  safety_stock: { label: ITEM_PAGE.safetyStock, hint: ITEM_PAGE.safetyStockHint },
};

/**
 * `/items/new`'s form (items-ux-copy.md B-4 as amended by R1-1; items-i2-design.md Revision 1 — FINAL A-6), props-only.
 * SKU, Name, the Unit (a select over the served units: no preselection, its prompt an enabled first option) and the three
 * numbers, prefilled 0. All six are sent on every submit, exactly as typed (no trim, no case folding, no client format
 * rule), built by name from `CREATE_ITEM_FIELDS`, so `supplier_id` and `null` are never sent. Nothing chosen, or an
 * emptied number, is refused here on every press with no request; those sentences replace any earlier field sentences.
 * A 422's field sentences show beside their fields and its others in the banner; a 409 and every other failure in the
 * banner under **Not created**, the server's sentence as sent. A lost answer says B-4's interrupted sentence, never "try
 * again" (the create does not replay). Focus: after a field error to the first such field in screen order, once its
 * error is on screen; after a banner to its heading. A 401 renders nothing. Create item is never withdrawn.
 */
export function ItemCreateForm({ create, createCode, units, onCreated }: ItemCreateFormProps) {
  const [values, setValues] = useState<Values>(INITIAL);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<CreateItemField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: 'banner' | CreateItemField } | null>(null);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const skuRef = useRef<HTMLInputElement>(null);
  const nameRef = useRef<HTMLInputElement>(null);
  const unitRef = useRef<HTMLSelectElement>(null);
  const pointRef = useRef<HTMLInputElement>(null);
  const quantityRef = useRef<HTMLInputElement>(null);
  const safetyRef = useRef<HTMLInputElement>(null);
  const numberRefs = { reorder_point: pointRef, reorder_quantity: quantityRef, safety_stock: safetyRef };
  const gen = useRef(0);

  // After the commit that renders the error or banner (focus never runs ahead of what it lands on).
  useLayoutEffect(() => {
    if (focusTarget === null) return;
    if (focusTarget.to === 'banner') bannerRef.current?.focus();
    else ({ sku: skuRef, name: nameRef, unit_of_measure_code: unitRef, reorder_point: pointRef, reorder_quantity: quantityRef, safety_stock: safetyRef })[focusTarget.to].current?.focus();
  }, [focusTarget]);

  const focusField = (f: CreateItemField) => {
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

  const settle = (r: CreateItemResult, sku: string) => {
    switch (r.kind) {
      case 'created':
        setFieldErrors({});
        setNotice(null);
        onCreated(r.item);
        return;
      case 'invalid': {
        if (r.formErrors.length > 0) {
          // Index keys: two out-of-form violations may carry the same sentence, and the list is rebuilt per answer under a
          // new Banner key, so an index is stable here and a sentence key is not unique.
          show(NOT_CREATED, r.formErrors.map((m, i) => <p key={i}>{m}</p>));
          setFieldErrors(r.fieldErrors);
          return;
        }
        const first = CREATE_ITEM_FIELDS.find((f) => r.fieldErrors[f] !== undefined);
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
            {ITEM_FORBIDDEN.before}
            <em>{label}</em>
            {ITEM_FORBIDDEN.after}
          </p>,
        );
        return;
      }
      case 'not-found':
        show(NOT_CREATED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, NEW_ITEM.interrupted(sku));
        return;
      case 'server-error':
        show(WENT_WRONG, NEW_ITEM.interrupted(sku));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy) return;
    // R1-1 / R1-9: nothing chosen, or an emptied number, is the person's own unfinished input, caught on every press;
    // nothing is sent. Only those two checks: the server's format and blank rules are never copied here (ST-4f). They
    // REPLACE the field sentences wholesale (an earlier server sentence disappears) and clear the banner.
    const errors: FieldErrors<CreateItemField> = {};
    if (values.unit_of_measure_code === '') errors.unit_of_measure_code = NEW_ITEM.unitRequired;
    for (const f of QUANTITY_FIELDS) if (values[f] === '') errors[f] = ITEM_PAGE.numberRequired;
    const first = CREATE_ITEM_FIELDS.find((f) => errors[f] !== undefined);
    if (first !== undefined) {
      setNotice(null);
      setFieldErrors(errors);
      focusField(first);
      return;
    }
    // Built by name from the six fields, each exactly as typed: never a spread, never `supplier_id`, never `null`.
    const body = {} as CreateItemBody;
    for (const f of CREATE_ITEM_FIELDS) body[f] = values[f];
    setBusy(true);
    let r: CreateItemResult;
    try {
      r = await create(body);
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    settle(r, body.sku);
  };

  const set = (f: CreateItemField, v: string) => setValues((cur) => ({ ...cur, [f]: v }));

  return (
    <form method="post" noValidate className={styles.form} onSubmit={(e) => void onSubmit(e)}>
      {notice ? (
        <Banner key={notice.gen} tone="danger" heading={notice.heading} ref={bannerRef}>
          {typeof notice.body === 'string' ? <p>{notice.body}</p> : notice.body}
        </Banner>
      ) : null}
      <TextField ref={skuRef} id="new-item-sku" name="sku" label={ITEM_PAGE.sku} hint={ITEM_PAGE.skuHintFirst} error={fieldErrors.sku} value={values.sku} onChange={(e) => set('sku', e.target.value)} autoComplete="off" spellCheck={false} />
      <TextField ref={nameRef} id="new-item-name" name="name" label={ITEM_PAGE.name} error={fieldErrors.name} value={values.name} onChange={(e) => set('name', e.target.value)} autoComplete="off" />
      <SelectField
        ref={unitRef}
        id="new-item-unit"
        name="unit_of_measure_code"
        label={ITEM_PAGE.unit}
        options={units}
        labelOf={unitLabel}
        prompt={NEW_ITEM.unitPrompt}
        value={values.unit_of_measure_code}
        onChange={(v) => set('unit_of_measure_code', v)}
        hints={[]}
        error={fieldErrors.unit_of_measure_code}
      />
      {QUANTITY_FIELDS.map((f) => (
        <TextField
          key={f}
          ref={numberRefs[f]}
          id={`new-item-${f.replaceAll('_', '-')}`}
          name={f}
          label={NUMBER_LABELS[f].label}
          hint={NUMBER_LABELS[f].hint}
          error={fieldErrors[f]}
          value={values[f]}
          onChange={(e) => set(f, e.target.value)}
          // Never type="number" (locale-mangled, spinner, `e` notation): text, with a decimal keypad on touch.
          inputMode="decimal"
          autoComplete="off"
          spellCheck={false}
        />
      ))}
      <p className={styles.note}>{ITEM_PAGE.numbersNote}</p>
      <div className={styles.actions}>
        <Button type="submit" variant="primary" loading={busy}>
          {NEW_ITEM.submit}
        </Button>
        <Link href="/items">{NEW_ITEM.cancel}</Link>
      </div>
    </form>
  );
}
