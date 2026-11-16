'use client';

import { useLayoutEffect, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, MIXED_FIELDS_TOO, NOT_SAVED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { unitPerSpoken, unitSuffixSpoken } from '@/copy/items';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { EDIT_CATALOGUE_ITEM, SUPPLIER_FORBIDDEN } from '@/copy/suppliers';
import type { SupplierProduct, UpdateSupplierProductInput } from '@/lib/api/suppliers';
import { changedFields, marksAnyField } from '@/lib/forms/fieldErrors';
import { renderQuantity } from '@/lib/format/quantity';
import type { FieldErrors } from '@/lib/results';
import { ENTRY_FIELDS, type EntryField, type SaveEntryAction, type SaveEntryResult } from '@/lib/suppliers/results';
import styles from './Suppliers.module.css';

export interface EntryFormProps {
  /** The row being edited (there is no single-entry read: the section's row is the served state). */
  entry: SupplierProduct;
  save: SaveEntryAction;
  /** `gateCode('UpdateSupplierProduct')`, for the lost-permission sentence's label (B-10). */
  editCode: string | null;
  /**
   * Closes the dialog. `saved` is the 200 body; `reread` is true when what the section shows may be out of date (a save, an
   * interruption, a refusal); `parentStale` is true after a C1 whose re-read found the supplier archived: the page re-reads
   * the supplier (final RED M-3).
   */
  onClose: (outcome: { saved: SupplierProduct | null; reread: boolean; parentStale: boolean }) => void;
  /** Where focus goes on close when the row's Edit button is gone (the section's heading). */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /**
   * Called once per Save press that will send a request: after every guard, just before the request (FE-R6 commit A,
   * FE-R9). The page empties its status region here, through its counted writer.
   */
  onSaveStarted?: () => void;
}

/** Every field as the person types it (Lead time is text here and becomes a number only in `entrySave`, R9-2). */
type Values = Record<EntryField, string>;
interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

/** The fields as rendered in the row: the cost as served, the lead time's digits, the minimum order QR-2 trimmed. */
const valuesOf = (e: SupplierProduct): Values => ({
  supplier_sku: e.supplier_sku,
  unit_cost: e.unit_cost,
  lead_time_days: String(e.lead_time_days),
  minimum_order_quantity: renderQuantity(e.minimum_order_quantity),
});

/** The three number fields a person can empty, in screen order, with their client sentence (UX S-4; no request is sent). */
const REQUIRED: readonly { field: Exclude<EntryField, 'supplier_sku'>; sentence: string }[] = [
  { field: 'unit_cost', sentence: EDIT_CATALOGUE_ITEM.costRequired },
  { field: 'lead_time_days', sentence: EDIT_CATALOGUE_ITEM.leadTimeRequired },
  { field: 'minimum_order_quantity', sentence: EDIT_CATALOGUE_ITEM.minimumRequired },
];

/**
 * Edit a supplier catalogue entry (Suppliers S-4: suppliers-plan.md Revision 1 — FINAL §R1-4 S-4, the `LocationForm`
 * dialog shape; suppliers-ux-copy.md Revision 1 — FINAL S-4 as overridden by bk31-placement-confirm.md Revision 1 — FINAL),
 * props-only. Supplier SKU · Unit cost · Lead time · Minimum order, each starting at the value the row renders; the item
 * is shown as text (it cannot change on an entry). The unit is the ROW's served `unit_of_measure_code`, read from the entry
 * and from nothing else (FINAL (e) items 3–4): after Minimum order the code is shown and `unitSuffixSpoken` is read; after
 * Unit cost `per {code}` is shown and `unitPerSpoken` + "." is read (a catalogue cost is the price of ONE of the item's
 * units, bk31-cost-basis-po.md). The body is the changed members only (`changedFields`, compared exactly); Save is
 * `aria-disabled` with *Nothing to save.* while nothing changed. An emptied Unit cost, Lead time or Minimum order shows its
 * own sentence beside the field and sends nothing (H-2). A 422's field sentences show beside their fields, the first takes
 * focus; everything else shows in the dialog's banner as sent (ST-3). P1, a 403 and an interruption keep Save (P1 asks for
 * another press); a 404 (removed elsewhere) and a C1 whose re-read found the supplier archived leave **Close** only.
 * After any answer other than nothing at all, closing re-reads the section; after the C1 it also has the page re-read the
 * supplier. No unit is compared after a 200 (P-N5: while an entry exists its item's unit cannot change). A 401 renders
 * nothing (the caller navigates).
 */
export function EntryForm({ entry, save, editCode, onClose, fallbackFocusRef, onSaveStarted }: EntryFormProps) {
  const initial = valuesOf(entry);
  const [values, setValues] = useState<Values>(initial);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<EntryField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [unsure, setUnsure] = useState(false);
  // A refusal that leaves nothing to press: Save goes and Cancel becomes Close (the DeleteDialog `final` shape).
  const [final, setFinal] = useState<'not-found' | 'parent-archived' | null>(null);
  const skuRef = useRef<HTMLInputElement>(null);
  const costRef = useRef<HTMLInputElement>(null);
  const leadRef = useRef<HTMLInputElement>(null);
  const minimumRef = useRef<HTMLInputElement>(null);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const gen = useRef(0);
  // Field focus moves only after the commit that renders the field's sentence and links it (the `LocationForm` rule).
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: EntryField } | null>(null);
  useLayoutEffect(() => {
    if (focusTarget === null) return;
    ({ supplier_sku: skuRef, unit_cost: costRef, lead_time_days: leadRef, minimum_order_quantity: minimumRef })[focusTarget.to].current?.focus();
  }, [focusTarget]);
  const focusField = (to: EntryField) => {
    gen.current += 1;
    setFocusTarget({ gen: gen.current, to });
  };
  const changes = changedFields<UpdateSupplierProductInput, EntryField>(ENTRY_FIELDS, initial, values);
  const nothing = Object.keys(changes).length === 0;
  const code = entry.unit_of_measure_code;
  const sku = entry.inventory_item.sku;

  const show = (heading: string, body: ReactNode) => {
    setFieldErrors({});
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
  };

  const settle = (r: SaveEntryResult) => {
    switch (r.kind) {
      case 'saved':
        onClose({ saved: r.entry, reread: true, parentStale: false });
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
        const first = ENTRY_FIELDS.find((f) => r.fieldErrors[f] !== undefined);
        if (r.formErrors.length === 0 && first !== undefined) focusField(first);
        return;
      }
      case 'refused-archived':
        // C1 on a stale page (final RED M-3): nothing here can be changed until the supplier is restored.
        setUnsure(true);
        setFinal('parent-archived');
        show(NOT_SAVED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
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
            {SUPPLIER_FORBIDDEN.before}
            <em>{label}</em>
            {SUPPLIER_FORBIDDEN.after}
          </p>,
        );
        return;
      }
      case 'not-found':
        setUnsure(true);
        setFinal('not-found');
        show(NOT_SAVED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        setUnsure(true);
        show(COULD_NOT_REACH, EDIT_CATALOGUE_ITEM.interrupted(sku));
        return;
      case 'server-error':
        setUnsure(true);
        show(WENT_WRONG, EDIT_CATALOGUE_ITEM.interrupted(sku));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy || nothing || final !== null) return;
    // An emptied number field is the person's own unfinished input, caught on every press; nothing is sent and the page's
    // region is not emptied (the press will not send). Only the empty field: the server's shape rules are never copied.
    const empty = REQUIRED.filter((q) => values[q.field] === '');
    if (empty.length > 0) {
      setNotice(null);
      setFieldErrors(Object.fromEntries(empty.map((q) => [q.field, q.sentence])) as FieldErrors<EntryField>);
      focusField(empty[0]!.field);
      return;
    }
    onSaveStarted?.();
    setBusy(true);
    let r: SaveEntryResult;
    try {
      r = await save(entry.id, changes);
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    settle(r);
  };

  const close = () => onClose({ saved: null, reread: unsure, parentStale: final === 'parent-archived' });
  const set = (f: EntryField, v: string) => setValues((cur) => ({ ...cur, [f]: v }));

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) close();
      }}
      title={EDIT_CATALOGUE_ITEM.title(sku, entry.inventory_item.name)}
      description={<p>{EDIT_CATALOGUE_ITEM.itemLine(sku, entry.inventory_item.name)}</p>}
      initialFocusRef={skuRef}
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
        <TextField ref={skuRef} id="entry-supplier-sku" name="supplier_sku" label={EDIT_CATALOGUE_ITEM.supplierSku} hint={EDIT_CATALOGUE_ITEM.supplierSkuHint} error={fieldErrors.supplier_sku} value={values.supplier_sku} onChange={(e) => set('supplier_sku', e.target.value)} autoComplete="off" spellCheck={false} />
        <TextField
          ref={costRef}
          id="entry-unit-cost"
          name="unit_cost"
          label={EDIT_CATALOGUE_ITEM.unitCost(entry.currency_code)}
          hint={EDIT_CATALOGUE_ITEM.unitCostHint}
          // R1-D, per-unit branch: the price of ONE of the row's units — shown `per {code}`, read "per kilogram." / "each.".
          suffix={{ shown: EDIT_CATALOGUE_ITEM.perUnit(code), spoken: `${unitPerSpoken(code)}.` }}
          error={fieldErrors.unit_cost}
          value={values.unit_cost}
          onChange={(e) => set('unit_cost', e.target.value)}
          // Never type="number" (locale-mangled, spinner, `e` notation): text, with a decimal keypad on touch.
          inputMode="decimal"
          autoComplete="off"
          spellCheck={false}
        />
        <TextField ref={leadRef} id="entry-lead-time-days" name="lead_time_days" label={EDIT_CATALOGUE_ITEM.leadTime} hint={EDIT_CATALOGUE_ITEM.leadTimeHint} error={fieldErrors.lead_time_days} value={values.lead_time_days} onChange={(e) => set('lead_time_days', e.target.value)} inputMode="numeric" autoComplete="off" spellCheck={false} />
        <TextField
          ref={minimumRef}
          id="entry-minimum-order-quantity"
          name="minimum_order_quantity"
          label={EDIT_CATALOGUE_ITEM.minimumOrder}
          hint={EDIT_CATALOGUE_ITEM.minimumOrderHint}
          // R1-C: the row's unit code shown after the number, its plural name read (the S-3 suffix rule).
          suffix={{ shown: code, spoken: unitSuffixSpoken(code) }}
          error={fieldErrors.minimum_order_quantity}
          value={values.minimum_order_quantity}
          onChange={(e) => set('minimum_order_quantity', e.target.value)}
          inputMode="decimal"
          autoComplete="off"
          spellCheck={false}
        />
        <div className={styles.actions}>
          {busy ? null : <Button onClick={close}>{final !== null ? EDIT_CATALOGUE_ITEM.close : EDIT_CATALOGUE_ITEM.cancel}</Button>}
          {final !== null ? null : (
            <Button type="submit" variant="primary" loading={busy} unavailableReason={nothing ? EDIT_CATALOGUE_ITEM.saveNothing : undefined}>
              {EDIT_CATALOGUE_ITEM.save}
            </Button>
          )}
        </div>
      </form>
    </Dialog>
  );
}
