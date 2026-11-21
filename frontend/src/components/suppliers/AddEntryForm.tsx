'use client';

import { useId, useLayoutEffect, useMemo, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { COULD_NOT_REACH, MIXED_FIELDS_TOO, NOT_ADDED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { ADD_CATALOGUE_ITEM, SUPPLIER_FORBIDDEN } from '@/copy/suppliers';
import type { SupplierProduct } from '@/lib/api/suppliers';
import { marksAnyField } from '@/lib/forms/fieldErrors';
import type { FieldErrors } from '@/lib/results';
import type { ItemChoice } from '@/lib/suppliers/load';
import { CREATE_ENTRY_FIELDS, type CreateEntryAction, type CreateEntryField, type CreateEntryResult, type EntryField } from '@/lib/suppliers/results';
import { ENTRY_REQUIRED, EntryFields } from './EntryForm';
import styles from './Suppliers.module.css';

/** Where focus goes when a choice list has just been put on screen (final RED M-3 step 2). */
export type ChoicesFocus = 'search' | 'retry' | 'cancel';

export interface AddEntryFormProps {
  /** The supplier's code, for the title (*Add an item to {code}'s catalogue*). */
  supplierCode: string;
  /** The supplier's currency, which the Unit cost label names (UX B-8: "the supplier's on Add"). */
  currency: string;
  create: CreateEntryAction;
  /** `gateCode('CreateSupplierProduct')`, for the lost-permission sentence's label (B-10). */
  createCode: string | null;
  /**
   * The paged item choice list, supplied by the section (props-only: a component imports nothing from `app/`; final RED
   * M-3 step 3). Rendered as a sibling BEFORE the `<form>`, never inside it, so Enter in its search submits nothing.
   * `null` while a Change's read is in flight.
   */
  choices: (onChoose: (c: ItemChoice) => void) => ReactNode;
  /**
   * The list the section has just put on screen, by answer: its search box (ok), its region error's Retry (a failure), or
   * Cancel (denied). `gen` changes with every read (the open, each Change). `null` while a read is in flight (the section
   * then shows the ST-1 skeleton in the choice area, UX DV-8 FIX).
   */
  choicesShown: { gen: number; focus: ChoicesFocus } | null;
  /**
   * The choice list shows a state with no item to choose (`noneYet`, `allArchived`, `denied`, and `noMatch` in a workspace
   * with no item at all, RED-2 L-3): while nothing is chosen the dialog then offers no Add to catalogue, only Cancel (UX
   * U-1, the DV-4 "nothing left to press" shape). Any other `noMatch`, a load failure and a Change read in flight keep Add
   * (Clear search, Retry, or a list are in reach).
   */
  nothingToChoose: boolean;
  /** **Change**: the chosen item is cleared here at once; the section reads the list afresh (step 1's read). */
  onChangeItem: () => void;
  /**
   * Closes the dialog. `created` is the 201 body; `chosen` is the item the request named and `currency` the currency the
   * dialog labelled when it was sent (the section compares the 201 with both, FINAL (e) item 10, M-9); `reread` is true
   * when what the section shows may be out of date; `parentStale` is true after a C1 whose re-read found the supplier
   * archived.
   */
  onClose: (outcome: { created: SupplierProduct | null; chosen: ItemChoice | null; currency: string; reread: boolean; parentStale: boolean }) => void;
  /** Where focus goes on close when the opener is gone (the section's heading). */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /**
   * Called once per Add press that will send a request: after every guard, just before the request (FE-R6 commit A,
   * FE-R9). The page empties its status region here, through its counted writer.
   */
  onAddStarted?: () => void;
}

type Values = Record<EntryField, string>;
interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}
const EMPTY: Values = { supplier_sku: '', unit_cost: '', lead_time_days: '', minimum_order_quantity: '' };

/**
 * Add to catalogue (Suppliers S-5: suppliers-plan.md Revision 1 — FINAL §R1-4 S-5 with suppliers-final-red.md M-3, M-4,
 * L-3; suppliers-ux-copy.md Revision 1 — FINAL S-5 as overridden by bk31-placement-confirm.md Revision 1 — FINAL), props-only.
 * Above the form, the choice area: the section's paged item list (SKU · Name · Unit · Choose), or — once an item is
 * chosen — *Item: {sku} — {name}* with **Change**. Choose unmounts the list and puts focus on Supplier SKU, saying nothing;
 * Change clears the chosen item at once and has the section read the list afresh (focus then on its search box). Below,
 * the four fields of Edit (`EntryFields`), all starting empty; the unit after Minimum order and after Unit cost is the
 * CHOSEN item's. Add to catalogue with no item chosen shows `itemRequired` (focus on it) and sends nothing; an empty Unit
 * cost, Lead time or Minimum order shows its sentence beside the field and sends nothing. Every submit is a new request
 * with a new Idempotency-Key (the action mints it). A 422's sentences show beside their fields (the item's beside the
 * chosen line, read with **Change**, which takes focus); every other refusal in the dialog's banner under **Not added**,
 * as sent; the dialog stays with Add (C2, P1, a 403, an interruption). A C1 whose re-read found the supplier archived, and
 * a 404, leave **Close** only. The dialog is `busy` (Escape and an outside press ignored) only while a request is in
 * flight, and then Cancel and Change are hidden (suppliers-s5-red.md M-2: the answer is shown against the item that was
 * sent): Escape closes it from any field, the search included (final RED L-3: Clear search empties the search). After any
 * answer other than nothing at all, closing re-reads the section. A 401 renders nothing (the caller navigates).
 */
export function AddEntryForm({ supplierCode, currency, create, createCode, choices, choicesShown, nothingToChoose, onChangeItem, onClose, fallbackFocusRef, onAddStarted }: AddEntryFormProps) {
  const [chosen, setChosen] = useState<ItemChoice | null>(null);
  const [values, setValues] = useState<Values>(EMPTY);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<CreateEntryField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [unsure, setUnsure] = useState(false);
  const [final, setFinal] = useState<'not-found' | 'parent-archived' | null>(null);
  const choiceArea = useRef<HTMLDivElement>(null);
  const cancelRef = useRef<HTMLButtonElement>(null);
  const changeRef = useRef<HTMLButtonElement>(null);
  const itemErrorRef = useRef<HTMLParagraphElement>(null);
  const skuRef = useRef<HTMLInputElement>(null);
  const costRef = useRef<HTMLInputElement>(null);
  const leadRef = useRef<HTMLInputElement>(null);
  const minimumRef = useRef<HTMLInputElement>(null);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  // The dialog's first focus (M-3 step 2), resolved from the DOM when the dialog asks for it (its open-focus event): the
  // dialog's content is portalled, so it is not in the document yet when this component's own first layout effect runs.
  const firstFocus = useRef<ChoicesFocus | null>(null);
  const initialFocus = useMemo<RefObject<HTMLElement | null>>(
    () => ({
      get current() {
        return firstFocus.current === null ? null : resolve(firstFocus.current);
      },
    }),
    // `resolve` reads refs only, at the moment the dialog asks.
    [],
  );
  const itemErrorId = useId();
  const gen = useRef(0);
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: CreateEntryField } | null>(null);

  /** The element `to` names inside the choice area (the core owns its search box and its Retry), or Cancel. */
  const resolve = (to: ChoicesFocus): HTMLElement | null =>
    to === 'cancel' ? cancelRef.current : to === 'search' ? (choiceArea.current?.querySelector<HTMLElement>('input[type="search"]') ?? null) : (choiceArea.current?.querySelector<HTMLElement>('button') ?? null);
  // The first list is focused by the dialog itself on open (so the dialog records the opener first); every later one
  // (after a Change) is focused here, after the commit that renders it.
  const firstShown = useRef(true);
  const shownGen = choicesShown?.gen ?? null;
  const shownFocus = choicesShown?.focus ?? null;
  useLayoutEffect(() => {
    if (shownGen === null || shownFocus === null) return;
    if (firstShown.current) {
      firstShown.current = false;
      firstFocus.current = shownFocus;
      return;
    }
    resolve(shownFocus)?.focus();
  }, [shownGen, shownFocus]);

  // Field focus moves only after the commit that renders the field's sentence and links it (the `LocationForm` rule).
  useLayoutEffect(() => {
    if (focusTarget === null) return;
    const to = focusTarget.to;
    if (to === 'inventory_item_id') (chosen !== null ? changeRef : itemErrorRef).current?.focus();
    else ({ supplier_sku: skuRef, unit_cost: costRef, lead_time_days: leadRef, minimum_order_quantity: minimumRef })[to].current?.focus();
    // Only a new target moves focus; the chosen item is read for the target, never as a trigger.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [focusTarget]);
  const focusField = (to: CreateEntryField) => {
    gen.current += 1;
    setFocusTarget({ gen: gen.current, to });
  };
  // Choose: focus to Supplier SKU after the commit that renders the form's fields beside the chosen line (M-3 step 4).
  const [chooseGen, setChooseGen] = useState(0);
  useLayoutEffect(() => {
    if (chooseGen > 0) skuRef.current?.focus();
  }, [chooseGen]);

  const show = (heading: string, body: ReactNode) => {
    setFieldErrors({});
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
  };

  const settle = (r: CreateEntryResult, sentWith: { chosen: ItemChoice; currency: string }) => {
    const sku = sentWith.chosen.sku;
    switch (r.kind) {
      case 'created':
        onClose({ created: r.entry, chosen: sentWith.chosen, currency: sentWith.currency, reread: true, parentStale: false });
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
        const first = CREATE_ENTRY_FIELDS.find((f) => r.fieldErrors[f] !== undefined);
        if (r.formErrors.length === 0 && first !== undefined) focusField(first);
        return;
      }
      case 'refused-archived':
        // C1 on a stale page: nothing here can be added until the supplier is restored.
        setUnsure(true);
        setFinal('parent-archived');
        show(NOT_ADDED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
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
        show(NOT_ADDED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        setUnsure(true);
        show(COULD_NOT_REACH, ADD_CATALOGUE_ITEM.interrupted(sku));
        return;
      case 'server-error':
        setUnsure(true);
        show(WENT_WRONG, ADD_CATALOGUE_ITEM.interrupted(sku));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy || final !== null) return;
    // The person's own unfinished input, caught on every press: no item chosen, or an empty number field. Nothing is sent
    // and the page's region is not emptied (the press will not send). The server's shape rules are never copied.
    const empty = ENTRY_REQUIRED.filter((q) => values[q.field] === '');
    if (chosen === null || empty.length > 0) {
      setNotice(null);
      // suppliers-s5-red.md L-1: with nothing chosen the fields are not shown, so only `itemRequired` is filed.
      const errors: FieldErrors<CreateEntryField> = chosen === null ? { inventory_item_id: ADD_CATALOGUE_ITEM.itemRequired } : Object.fromEntries(empty.map((q) => [q.field, q.sentence]));
      setFieldErrors(errors);
      focusField(chosen === null ? 'inventory_item_id' : empty[0]!.field);
      return;
    }
    const sentWith = { chosen, currency };
    onAddStarted?.();
    setBusy(true);
    let r: CreateEntryResult;
    try {
      r = await create({ inventory_item_id: chosen.id, ...values });
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    settle(r, sentWith);
  };

  const close = () => onClose({ created: null, chosen, currency, reread: unsure, parentStale: final === 'parent-archived' });
  const set = (f: EntryField, v: string) => setValues((cur) => ({ ...cur, [f]: v }));
  /** The item's sentence (itemRequired, or C3) belongs to the choice it was given for: a new choice, or Change, drops it. */
  const dropItemError = (cur: FieldErrors<CreateEntryField>): FieldErrors<CreateEntryField> => {
    const next = { ...cur };
    delete next.inventory_item_id;
    return next;
  };
  const choose = (c: ItemChoice) => {
    setChosen(c);
    setFieldErrors(dropItemError);
    setChooseGen((g) => g + 1);
  };
  const change = () => {
    setChosen(null);
    // suppliers-s5-red.md L-2: a refusal describes the item that was sent; once the item changes it describes nothing.
    setNotice(null);
    setFieldErrors(dropItemError);
    onChangeItem();
  };
  const itemError = fieldErrors.inventory_item_id;

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) close();
      }}
      title={ADD_CATALOGUE_ITEM.title(supplierCode)}
      description={null}
      initialFocusRef={initialFocus}
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
      {/* The choice area: a sibling BEFORE the form, never inside it (M-3 step 3: the list's search must not submit Add). */}
      <div ref={choiceArea} className={styles.choice}>
        {chosen !== null ? (
          <>
            <p>{ADD_CATALOGUE_ITEM.itemLine(chosen.sku, chosen.name)}</p>
            {itemError !== undefined ? (
              <p id={itemErrorId} className={styles.choiceError}>
                {itemError}
              </p>
            ) : null}
            {final !== null || busy ? null : (
              <Button ref={changeRef} size="sm" aria-label={ADD_CATALOGUE_ITEM.changeItem} aria-describedby={itemError !== undefined ? itemErrorId : undefined} onClick={change}>
                {ADD_CATALOGUE_ITEM.change}
              </Button>
            )}
          </>
        ) : (
          <>
            {itemError !== undefined ? (
              // No field to tie it to (the list is the core's): the sentence itself takes focus, so it is read.
              <p ref={itemErrorRef} tabIndex={-1} className={styles.choiceError}>
                {itemError}
              </p>
            ) : null}
            {choices(choose)}
          </>
        )}
      </div>
      <form method="post" noValidate className={styles.form} onSubmit={(e) => void onSubmit(e)}>
        {chosen !== null ? <EntryFields values={values} set={set} errors={fieldErrors} code={chosen.unit_of_measure_code} currency={currency} skuRef={skuRef} costRef={costRef} leadRef={leadRef} minimumRef={minimumRef} /> : null}
        <div className={styles.actions}>
          {busy ? null : (
            <Button ref={cancelRef} onClick={close}>
              {final !== null ? ADD_CATALOGUE_ITEM.close : ADD_CATALOGUE_ITEM.cancel}
            </Button>
          )}
          {final !== null || (chosen === null && nothingToChoose) ? null : (
            <Button type="submit" variant="primary" loading={busy}>
              {ADD_CATALOGUE_ITEM.submit}
            </Button>
          )}
        </div>
      </form>
    </Dialog>
  );
}
