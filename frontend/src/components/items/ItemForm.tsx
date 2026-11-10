'use client';

import { useLayoutEffect, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, NOT_SAVED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { ITEM_FORBIDDEN, ITEM_PAGE, ITEMS_LIST } from '@/copy/items';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import type { Item, UpdateItemInput } from '@/lib/api/items';
import { renderQuantity } from '@/lib/format/quantity';
import { changedFields } from '@/lib/forms/fieldErrors';
import { ITEM_FIELDS, QUANTITY_FIELDS, type ItemField, type SaveItemAction, type SaveItemResult } from '@/lib/items/results';
import type { FieldErrors } from '@/lib/results';
import styles from './Items.module.css';

export interface ItemFormProps {
  /** The served item (`GetItem`); a new one after a save's re-read resets the fields to it. */
  item: Item;
  save: SaveItemAction;
  /** `gateCode('UpdateItem')`, for the lost-permission sentence's label (B-9). */
  editCode: string | null;
  /** Writes the page's status region (B-3 "After save"). */
  announce: (text: string) => void;
  /** The page's `<h1>`: focus goes there after a save. */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /** Called once per Save answered `saved`, after the message is written. */
  onSaved?: () => void;
  /**
   * A refused Save whose re-read found the item archived (`refused-archived`): the page is already archived and read-only
   * (this form is gone), so the page shows the server's sentence under **Not saved**.
   */
  onRefusedArchived: (message: string) => void;
}

type Values = Required<Pick<UpdateItemInput, ItemField>>;
interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

/** The fields as the person first sees them: each number rendered by QR-2, so an untouched one equals its initial value. */
const valuesOf = (i: Item): Values => ({
  sku: i.sku,
  name: i.name,
  reorder_point: renderQuantity(i.reorder_point),
  reorder_quantity: renderQuantity(i.reorder_quantity),
  safety_stock: renderQuantity(i.safety_stock),
});

const NUMBER_LABELS: Record<(typeof QUANTITY_FIELDS)[number], { label: string; hint: string }> = {
  reorder_point: { label: ITEM_PAGE.reorderPoint, hint: ITEM_PAGE.reorderPointHint },
  reorder_quantity: { label: ITEM_PAGE.reorderQuantity, hint: ITEM_PAGE.reorderQuantityHint },
  safety_stock: { label: ITEM_PAGE.safetyStock, hint: ITEM_PAGE.safetyStockHint },
};

/**
 * The item page's editor (items-ux-copy.md B-3 as amended by R1-1, R1-2, R1-6, R1-9; Revision 8 R8-2), props-only. SKU,
 * Name and the three numbers are editable; the Unit and the Preferred supplier are plain text (R1-1: no unit editing
 * before I-4; S-B′: the supplier is never edited here), and neither is ever in the body. Each number starts as its QR-2
 * rendering and is sent exactly as typed (QR-5: echo; no trim, no client format rule); its unit code follows it, outside
 * the input (R1-2, F-4). An emptied number is caught here (R1-9) and no request is sent, on every press. Save is
 * `aria-disabled` with *Nothing to save.* while nothing differs. A 422's field sentences show beside their fields and its
 * others in the banner; a 409 and every other failure show in the banner, the server's sentence as sent. After `saved`
 * the region says *"{sku} was saved."* and focus goes to the `h1`; after a refusal to the banner heading; after `invalid`
 * (the server's, or the empty-number check) to the first field with an error, once that error is on screen.
 */
export function ItemForm({ item, save, editCode, announce, fallbackFocusRef, onSaved, onRefusedArchived }: ItemFormProps) {
  const [loadedKey, setLoadedKey] = useState(item);
  const [values, setValues] = useState<Values>(() => valuesOf(item));
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<ItemField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: 'banner' | 'h1' | ItemField } | null>(null);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const skuRef = useRef<HTMLInputElement>(null);
  const nameRef = useRef<HTMLInputElement>(null);
  const pointRef = useRef<HTMLInputElement>(null);
  const quantityRef = useRef<HTMLInputElement>(null);
  const safetyRef = useRef<HTMLInputElement>(null);
  const numberRefs = { reorder_point: pointRef, reorder_quantity: quantityRef, safety_stock: safetyRef };
  const gen = useRef(0);

  // A new served item (the re-read after a save) is what the fields now edit.
  if (loadedKey !== item) {
    setLoadedKey(item);
    setValues(valuesOf(item));
  }

  // After the commit that renders the error or banner (4.5 RED2 M-1: focus never runs ahead of what it lands on).
  useLayoutEffect(() => {
    if (focusTarget === null) return;
    if (focusTarget.to === 'banner') bannerRef.current?.focus();
    else if (focusTarget.to === 'h1') fallbackFocusRef.current?.focus();
    else ({ sku: skuRef, name: nameRef, reorder_point: pointRef, reorder_quantity: quantityRef, safety_stock: safetyRef })[focusTarget.to].current?.focus();
    // Once per target generation.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [focusTarget]);

  const initial = valuesOf(item);
  const changes = changedFields<UpdateItemInput, ItemField>(ITEM_FIELDS, initial, values);
  const nothing = Object.keys(changes).length === 0;

  const focusField = (f: ItemField) => {
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

  const settle = (r: SaveItemResult, sku: string) => {
    switch (r.kind) {
      case 'saved':
        setFieldErrors({});
        setNotice(null);
        announce(ITEM_PAGE.saved(r.item.sku));
        gen.current += 1;
        setFocusTarget({ gen: gen.current, to: 'h1' });
        onSaved?.();
        return;
      case 'invalid': {
        const first = ITEM_FIELDS.find((f) => r.fieldErrors[f] !== undefined);
        if (r.formErrors.length > 0) {
          show(NOT_SAVED, r.formErrors.map((m, i) => <p key={i}>{m}</p>));
          setFieldErrors(r.fieldErrors);
          return;
        }
        setFieldErrors(r.fieldErrors);
        setNotice(null);
        if (first) focusField(first);
        return;
      }
      case 'refused-archived':
        onRefusedArchived(r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'refused':
        show(NOT_SAVED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        const label = (editCode !== null ? PERMISSION_LABELS[editCode]?.label : undefined) ?? '';
        show(
          NOT_SAVED,
          <p>
            {ITEM_FORBIDDEN.before}
            <em>{label}</em>
            {ITEM_FORBIDDEN.after}
          </p>,
        );
        return;
      }
      case 'not-found':
        show(NOT_SAVED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, ITEM_PAGE.interrupted(sku));
        return;
      case 'server-error':
        show(WENT_WRONG, ITEM_PAGE.interrupted(sku));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy || nothing) return;
    // R1-9: an emptied number is the person's own unfinished input, caught on every press; nothing is sent. Only the
    // empty field: the server's format rule is never copied here (ST-4f).
    const emptied = QUANTITY_FIELDS.filter((f) => values[f] === '');
    if (emptied.length > 0) {
      setNotice(null);
      setFieldErrors(Object.fromEntries(emptied.map((f) => [f, ITEM_PAGE.numberRequired])));
      focusField(emptied[0]!);
      return;
    }
    setBusy(true);
    let r: SaveItemResult;
    try {
      r = await save(changes);
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    settle(r, item.sku);
  };

  const set = (f: ItemField, v: string) => setValues((cur) => ({ ...cur, [f]: v }));

  return (
    <form method="post" noValidate className={styles.form} onSubmit={(e) => void onSubmit(e)}>
      {notice ? (
        <Banner key={notice.gen} tone="danger" heading={notice.heading} ref={bannerRef}>
          {typeof notice.body === 'string' ? <p>{notice.body}</p> : notice.body}
        </Banner>
      ) : null}
      <TextField ref={skuRef} id="item-sku" name="sku" label={ITEM_PAGE.sku} hint={ITEM_PAGE.skuHint} error={fieldErrors.sku} value={values.sku} onChange={(e) => set('sku', e.target.value)} autoComplete="off" spellCheck={false} />
      <TextField ref={nameRef} id="item-name" name="name" label={ITEM_PAGE.name} hint={ITEM_PAGE.nameHint} error={fieldErrors.name} value={values.name} onChange={(e) => set('name', e.target.value)} autoComplete="off" />
      {/* R1-1 / R1-6: read-only text in edit too; never a control, never in the body. */}
      <dl className={styles.fields}>
        <dt>{ITEM_PAGE.unit}</dt>
        <dd>{item.unit_of_measure_code}</dd>
        <dt>{ITEM_PAGE.supplier}</dt>
        <dd>{item.supplier !== null ? item.supplier.name : ITEMS_LIST.noSupplier}</dd>
      </dl>
      {QUANTITY_FIELDS.map((f) => (
        <TextField
          key={f}
          ref={numberRefs[f]}
          id={`item-${f.replaceAll('_', '-')}`}
          name={f}
          label={NUMBER_LABELS[f].label}
          hint={NUMBER_LABELS[f].hint}
          error={fieldErrors[f]}
          // QR-8 / R1-2 / F-4: the saved unit's code after the number, outside the input.
          suffix={item.unit_of_measure_code}
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
        <Button type="submit" variant="primary" loading={busy} unavailableReason={nothing ? ITEM_PAGE.saveNothing : undefined}>
          {ITEM_PAGE.save}
        </Button>
      </div>
    </form>
  );
}
