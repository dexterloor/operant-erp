'use client';

import { useId, useLayoutEffect, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { flushSync } from 'react-dom';
import { RegionError } from '@/components/states/PageStates';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { SelectField } from '@/components/ui/SelectField';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, MIXED_FIELDS_TOO, NOT_ADDED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { unitPerSpoken, unitSuffixSpoken } from '@/copy/items';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { ADD_LINE, EDIT_LINE, PURCHASE_ORDER_PAGE, PURCHASING_FORBIDDEN } from '@/copy/purchasing';
import { PAGE_STATES } from '@/copy/shell';
import type { PurchaseOrder } from '@/lib/api/purchasing';
import { marksAnyField } from '@/lib/forms/fieldErrors';
import type { CatalogueChoicesLoad } from '@/lib/purchasing/load';
import { ADD_LINE_FIELDS, type AddLineAction, type AddLineField, type AddLineResult } from '@/lib/purchasing/results';
import type { FieldErrors } from '@/lib/results';
import styles from './Purchasing.module.css';

export interface AddLineRowProps {
  /** The served order (a live draft): its supplier's name, its currency (the cost's) and its number (D-2's sentence). */
  order: PurchaseOrder;
  /** The order's supplier's catalogue, read on the server for the first render (a failure shows the row's own Retry). */
  catalogue: CatalogueChoicesLoad;
  /** The row's Retry, and its one re-read after L1 / H-2: the catalogue over the browser transport. */
  reloadCatalogue: () => Promise<CatalogueChoicesLoad>;
  add: AddLineAction;
  /** `gateCode('CreatePurchaseOrderLine')`, for the lost-permission sentence's label (PURCHASING_FORBIDDEN). */
  addCode: string | null;
  /** `gateCode('ListSupplierProducts')`, for the lost-permission sentence when the row's catalogue read is answered 403. */
  catalogueViewCode: string | null;
  /** *Open {supplier}* for a GetSupplier holder (the page builds the link), else null: shown with `emptyCatalogue` only. */
  supplierLink: ReactNode | null;
  /** The table's column count: the row's single-cell states and its sentence row span it. */
  columns: number;
  /** Writes the page's status region (`after` / `afterListStale`, once per press, after the re-read). */
  announce: (text: string) => void;
  /** The lines section's heading: focus goes there when the row is gone after an answer (the lines could not be read). */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /**
   * A refused Add whose re-read found the order no longer an editable draft (deleted elsewhere: P8's sentence; approved or
   * cancelled elsewhere: UX D-2's `noLongerDraft`). The page is read-only by then (this row is gone), so it shows the
   * sentence under **Not added**.
   */
  onRefusedStale: (message: string) => void;
  /** The lines generation on screen: a refusal banner goes when a newer reading of the lines is shown (po-5-red.md L-1). */
  linesGen: number;
  /**
   * One line write at a time across the page (po-5-red.md L-3; po-5-red-2.md D-18 — the rule's ONE home): `begin()` is
   * false while a Delete, a Restore line or a line Save is in flight, and the press then sends nothing; `end()` after the
   * answer.
   */
  lineWrites: { begin: () => boolean; end: () => void };
}

interface Notice {
  gen: number;
  from: number;
  heading: string;
  body: ReactNode;
}
type Target = 'banner' | AddLineField;

/**
 * The lines table's new line row (purchase-orders-plan.md §2.3 "Add", Revision 1 R1-5 PO-5, Revision 1a per-row names;
 * final RED H-2; UX R1-B `ADD_LINE`, R1a-1 row 5; PO D-7), props-only. IA §4.1: an empty row at the bottom of the table,
 * named *New line*: **Item** — a select over the supplier's served catalogue entries in served order, `{sku} — {name}`,
 * no preselection; **Quantity** — the chosen entry's unit code beside it (spoken as the unit's plural, the shipped R1-C
 * suffix rule); **Unit** — the chosen entry's code, empty until one is chosen (PO-4b, po-4b-ux-unit.md); **Unit cost ({the
 * order's currency})** — `per {code}` beside it once an entry is chosen (PO-4b, the edit rows' suffix) — prefilled with the entry's served cost, untrimmed, only when the
 * entry's currency is the order's (D-7), otherwise empty and required; **Add line**. The row is a `form`-attribute form
 * (plan M-9): its `<form method="post">` sits in the last cell around Add line, and the three fields join it with the `form`
 * attribute, so Enter in Quantity or Unit cost sends this row and no other (G-F2 holds: the form posts, to its own page).
 *
 * Nothing is sent without an item (only its sentence is shown, Item focused) or with an empty quantity or cost (their
 * sentences, the first focused); the text is sent as typed. After `added` the row empties, its Item takes focus, and the
 * page's region says *"Line {n} added: {sku}."* — or, when the re-read failed, the same with *"…may be out of date."*: one
 * sentence, chosen after the re-read, never corrected later. A 422's sentences go beside their fields (the first focused);
 * H-2's violation shows `itemUnavailable` beside Item, never the server's words, and the catalogue is read again. L1 shows
 * the server's sentence under **Not added** and the catalogue is read again; a choice it no longer lists is cleared. Every
 * other refusal and failure shows under **Not added**, focused, the row kept (until a newer reading of the lines is shown); the
 * stale ones go to the page. A press while a Delete is in flight sends nothing (one line write at a time). An empty
 * catalogue shows `emptyCatalogue` (and *Open {supplier}*) instead of the fields; a failed read, the row's region error with
 * Retry (an `ok` Retry focuses Item; a 403 says which permission is missing, with no Retry). A 401 renders nothing.
 */
export function AddLineRow({ order, catalogue: firstCatalogue, reloadCatalogue, add, addCode, catalogueViewCode, supplierLink, columns, announce, fallbackFocusRef, onRefusedStale, linesGen, lineWrites }: AddLineRowProps) {
  const base = useId();
  const formId = `${base}-form`;
  const [catalogue, setCatalogue] = useState<{ gen: number; value: CatalogueChoicesLoad }>({ gen: 0, value: firstCatalogue });
  const [retrying, setRetrying] = useState(false);
  const [itemId, setItemId] = useState('');
  const [quantity, setQuantity] = useState('');
  const [unitCost, setUnitCost] = useState('');
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<AddLineField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: Target } | null>(null);
  const gen = useRef(0);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const itemRef = useRef<HTMLSelectElement>(null);
  const quantityRef = useRef<HTMLInputElement>(null);
  const costRef = useRef<HTMLInputElement>(null);
  // The current choice, for the re-read after a refusal (po-5-red.md L-2: compared with the choice when the answer arrives,
  // never the one from when Add was pressed). Written wherever the choice is.
  const chosenId = useRef('');
  const setChoice = (id: string) => {
    chosenId.current = id;
    setItemId(id);
  };
  // The lines generation on screen, read after an awaited write (its own re-read may have committed a newer one).
  const shownGen = useRef(linesGen);
  useLayoutEffect(() => {
    shownGen.current = linesGen;
  }, [linesGen]);

  // After the commit that renders the sentence or banner (focus never runs ahead of what it lands on).
  useLayoutEffect(() => {
    if (focusTarget === null) return;
    const to = focusTarget.to;
    (to === 'banner' ? bannerRef : to === 'inventory_item_id' ? itemRef : to === 'quantity' ? quantityRef : costRef).current?.focus();
  }, [focusTarget]);
  const focusTo = (to: Target) => {
    gen.current += 1;
    setFocusTarget({ gen: gen.current, to });
  };
  // Every banner clears the field sentences first, so an earlier 422's sentence never stays under a banner about something else.
  const show = (heading: string, body: ReactNode) => {
    setFieldErrors({});
    gen.current += 1;
    setNotice({ gen: gen.current, from: shownGen.current, heading, body });
    setFocusTarget({ gen: gen.current, to: 'banner' });
  };

  const c = catalogue.value;
  const entries = c.kind === 'ok' ? c.entries : [];
  const chosen = entries.find((e) => e.inventory_item.id === itemId) ?? null;
  const prefilled = chosen !== null && chosen.currency_code === order.currency_code;

  /** Choosing an entry (D-7): the cost starts at the entry's served cost when its currency is the order's, else empty. */
  const choose = (id: string) => {
    setChoice(id);
    const e = entries.find((x) => x.inventory_item.id === id);
    setUnitCost(e !== undefined && e.currency_code === order.currency_code ? e.unit_cost : '');
    setFieldErrors((cur) => {
      const next = { ...cur };
      delete next.inventory_item_id;
      delete next.unit_cost;
      return next;
    });
  };

  const readCatalogue = async (): Promise<CatalogueChoicesLoad> => {
    try {
      return await reloadCatalogue();
    } catch {
      return { kind: 'unreachable' };
    }
  };

  const retryCatalogue = async () => {
    setRetrying(true);
    const r = await readCatalogue();
    setRetrying(false);
    if (r.kind === 'session-ended') return;
    setCatalogue((cur) => ({ gen: cur.gen + 1, value: r }));
    // The pressed Retry leaves with its region; the select that replaces it takes focus (the PO-3 M-2 rule).
    if (r.kind === 'ok' && r.entries.length > 0) focusTo('inventory_item_id');
  };

  /**
   * After L1 or H-2 (plan R1-5 PO-5: "the catalogue re-reads"): one more read. An `ok` answer replaces the list and clears a
   * choice it no longer lists (its cost with it); any other answer keeps the list shown (nothing on screen becomes false).
   */
  const rereadAfterRefusal = async () => {
    const r = await readCatalogue();
    if (r.kind !== 'ok') return;
    setCatalogue((cur) => ({ gen: cur.gen + 1, value: r }));
    if (!r.entries.some((e) => e.inventory_item.id === chosenId.current)) {
      setChoice('');
      setUnitCost('');
    }
  };

  const settle = (r: AddLineResult, sku: string) => {
    switch (r.kind) {
      case 'added': {
        // FE-D1: commit the emptied row, put focus on its Item (else, when the row is gone because the lines could not be
        // read again, on the section's heading), then speak — once, the sentence the re-read decided.
        flushSync(() => {
          setChoice('');
          setQuantity('');
          setUnitCost('');
          setFieldErrors({});
          setNotice(null);
        });
        const target = itemRef.current;
        (target !== null && target.isConnected ? target : fallbackFocusRef.current)?.focus();
        announce(r.listStale ? ADD_LINE.afterListStale(r.line.line_number, r.line.inventory_item.sku) : ADD_LINE.after(r.line.line_number, r.line.inventory_item.sku));
        return;
      }
      case 'invalid': {
        const errors: FieldErrors<AddLineField> = r.itemUnavailable ? { ...r.fieldErrors, inventory_item_id: ADD_LINE.itemUnavailable } : r.fieldErrors;
        if (r.itemUnavailable) void rereadAfterRefusal();
        if (r.formErrors.length > 0) {
          show(
            NOT_ADDED,
            <>
              {r.formErrors.map((m, i) => <p key={i}>{m}</p>)}
              {marksAnyField(errors) ? <p key="mixed">{MIXED_FIELDS_TOO}</p> : null}
            </>,
          );
          setFieldErrors(errors);
          return;
        }
        setFieldErrors(errors);
        setNotice(null);
        const first = ADD_LINE_FIELDS.find((f) => errors[f] !== undefined);
        if (first) focusTo(first);
        return;
      }
      case 'not-listed':
        show(NOT_ADDED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        void rereadAfterRefusal();
        return;
      case 'refused-archived':
        onRefusedStale(r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'no-longer-draft':
        onRefusedStale(PURCHASE_ORDER_PAGE.noLongerDraft(r.order.order_number, r.order.status));
        return;
      case 'changed-since-loaded':
        show(NOT_ADDED, PURCHASE_ORDER_PAGE.changedSinceLoaded(order.order_number));
        return;
      case 'refused':
        show(NOT_ADDED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        const label = (addCode !== null ? PERMISSION_LABELS[addCode]?.label : undefined) ?? '';
        show(
          NOT_ADDED,
          <p>
            {PURCHASING_FORBIDDEN.before}
            <em>{label}</em>
            {PURCHASING_FORBIDDEN.after}
          </p>,
        );
        return;
      }
      case 'not-found':
        show(NOT_ADDED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, ADD_LINE.interrupted(sku));
        return;
      case 'server-error':
        show(WENT_WRONG, ADD_LINE.interrupted(sku));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    // po-5-red-2.md D-18 (closed at PO-4b): no `busy` early return here — one line write at a time has ONE home, the page's
    // synchronous `lineWrites.begin()` below, which every line write (Add, Delete, Restore, Save) passes. The Button's
    // `loading` stays as the shared UI rule. A second press while this Add is in flight re-runs the client checks over the
    // same text and is then refused by `begin()`.
    // Client refusals (UX R1-B; no request): with nothing chosen only the item's sentence (the S-5 rule); else the empty ones.
    if (chosen === null) {
      setNotice(null);
      setFieldErrors({ inventory_item_id: ADD_LINE.itemRequired });
      focusTo('inventory_item_id');
      return;
    }
    const missing: FieldErrors<AddLineField> = {};
    if (quantity === '') missing.quantity = ADD_LINE.quantityRequired;
    if (unitCost === '') missing.unit_cost = ADD_LINE.costRequired;
    const first = ADD_LINE_FIELDS.find((f) => missing[f] !== undefined);
    if (first !== undefined) {
      setNotice(null);
      setFieldErrors(missing);
      focusTo(first);
      return;
    }
    if (!lineWrites.begin()) return;
    // 30d2edb: the press empties the page's region, so a second identical sentence is a change and is given again.
    announce('');
    setBusy(true);
    let r: AddLineResult;
    try {
      r = await add({ inventory_item_id: chosen.inventory_item.id, quantity, unit_cost: unitCost });
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    lineWrites.end();
    settle(r, chosen.inventory_item.sku);
  };

  if (c.kind === 'session-ended') return null;
  if (c.kind !== 'ok' || c.entries.length === 0) {
    return (
      <tr aria-label={ADD_LINE.rowName}>
        <td colSpan={columns}>
          {c.kind === 'ok' ? (
            <>
              <p>{ADD_LINE.emptyCatalogue(order.supplier.name)}</p>
              {supplierLink !== null ? <p>{supplierLink}</p> : null}
            </>
          ) : c.kind === 'forbidden' ? (
            // The read was refused (the catalogue permission lost meanwhile): say so, with no Retry to repeat it.
            <p className={styles.muted}>
              {PURCHASING_FORBIDDEN.before}
              <em>{(catalogueViewCode !== null ? PERMISSION_LABELS[catalogueViewCode]?.label : undefined) ?? ''}</em>
              {PURCHASING_FORBIDDEN.after}
            </p>
          ) : (
            <RegionError key={catalogue.gen} heading={c.kind === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={ADD_LINE.catalogueLoadFailed(order.supplier.name)} retrying={retrying} autoFocus={catalogue.gen > 0} onRetry={() => void retryCatalogue()} />
          )}
        </td>
      </tr>
    );
  }

  const labels = new Map(entries.map((e) => [e.inventory_item.id, ADD_LINE.itemOption(e.inventory_item.sku, e.inventory_item.name)] as const));
  const unit = chosen?.unit_of_measure_code ?? null;
  return (
    <>
      <tr aria-label={ADD_LINE.rowName}>
        <td />
        <td>
          <SelectField
            ref={itemRef}
            id={`${base}-item`}
            name="inventory_item_id"
            label={ADD_LINE.item}
            accessibleName={ADD_LINE.itemName}
            form={formId}
            options={entries.map((e) => e.inventory_item.id)}
            labelOf={(id) => labels.get(id) ?? ''}
            prompt={ADD_LINE.itemPlaceholderOption}
            value={itemId}
            onChange={choose}
            hints={[]}
            error={fieldErrors.inventory_item_id}
          />
        </td>
        <td>
          <TextField
            ref={quantityRef}
            id={`${base}-quantity`}
            name="quantity"
            label={ADD_LINE.quantity}
            aria-label={ADD_LINE.quantityName}
            form={formId}
            hint={ADD_LINE.quantityHint}
            error={fieldErrors.quantity}
            value={quantity}
            onChange={(e) => setQuantity(e.target.value)}
            inputMode="decimal"
            autoComplete="off"
            spellCheck={false}
            suffix={unit !== null ? { shown: unit, spoken: unitSuffixSpoken(unit) } : undefined}
          />
        </td>
        {/* po-4b-ux-unit.md §2: the chosen entry's unit code, empty until an item is chosen (the Line cell's precedent). */}
        <td>{unit ?? ''}</td>
        <td>
          <TextField
            ref={costRef}
            id={`${base}-unit-cost`}
            name="unit_cost"
            label={ADD_LINE.unitCost(order.currency_code)}
            aria-label={ADD_LINE.unitCostName}
            form={formId}
            hint={ADD_LINE.unitCostHint(prefilled)}
            error={fieldErrors.unit_cost}
            value={unitCost}
            onChange={(e) => setUnitCost(e.target.value)}
            inputMode="decimal"
            autoComplete="off"
            spellCheck={false}
            // po-4b-ux-unit.md §2 (UX's recommendation, taken at PO-4b): the edit rows' `per {code}` suffix, from the chosen entry.
            suffix={unit !== null ? { shown: EDIT_LINE.perUnit(unit), spoken: `${unitPerSpoken(unit)}.` } : undefined}
          />
        </td>
        <td>
          <form id={formId} method="post" noValidate onSubmit={(e) => void onSubmit(e)}>
            <Button type="submit" size="sm" variant="primary" loading={busy}>
              {ADD_LINE.submit}
            </Button>
          </form>
        </td>
      </tr>
      {notice !== null && notice.from === linesGen ? (
        <tr>
          <td colSpan={columns}>
            <Banner key={notice.gen} tone="danger" heading={notice.heading} ref={bannerRef}>
              {typeof notice.body === 'string' ? <p>{notice.body}</p> : notice.body}
            </Banner>
          </td>
        </tr>
      ) : null}
    </>
  );
}
