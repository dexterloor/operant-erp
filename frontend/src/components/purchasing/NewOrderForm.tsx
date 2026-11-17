'use client';

import { useId, useLayoutEffect, useMemo, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { flushSync } from 'react-dom';
import Link from 'next/link';
import { CircleAlert } from 'lucide-react';
import { RegionError } from '@/components/states/PageStates';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { SelectField } from '@/components/ui/SelectField';
import { COULD_NOT_REACH, MIXED_FIELDS_TOO, NOT_CREATED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { NEW_PURCHASE_ORDER, PURCHASING_FORBIDDEN, SUPPLIER_CHOICES } from '@/copy/purchasing';
import { PAGE_STATES } from '@/copy/shell';
import type { PurchaseOrder } from '@/lib/api/purchasing';
import { marksAnyField } from '@/lib/forms/fieldErrors';
import type { SupplierChoice, SupplierChoicesLoad, WarehouseChoicesLoad } from '@/lib/purchasing/load';
import { CREATE_ORDER_FIELDS, type CreateOrderAction, type CreateOrderField, type CreateOrderResult } from '@/lib/purchasing/results';
import type { FieldErrors } from '@/lib/results';
import styles from './Purchasing.module.css';

export interface NewOrderFormProps {
  create: CreateOrderAction;
  /** `gateCode('CreatePurchaseOrder')`, for the lost-permission sentence's label (PURCHASING_FORBIDDEN). */
  createCode: string | null;
  /** The live warehouses, read on the server for the first render (a failure shows the field's own Retry). */
  warehouses: WarehouseChoicesLoad;
  /** `gateCode('ListWarehouses')`, for the lost-permission sentence when the field's Retry is answered 403 (po-3-red.md L-2). */
  warehousesViewCode: string | null;
  /** The field's Retry: reads the live warehouses again over the browser transport (the page supplies it). */
  reloadWarehouses: () => Promise<WarehouseChoicesLoad>;
  /** Reads the first page of live suppliers for the choice dialog (suppliers-final-red.md M-3 step 1; the page supplies it). */
  readSuppliers: () => Promise<SupplierChoicesLoad>;
  /**
   * The paged supplier list for one read's answer, supplied by the page (props-only: a component imports nothing from
   * `app/`). Rendered inside the dialog, which is a sibling AFTER the form, never inside it, so neither Enter in its search
   * nor any submit event of the list can reach Create.
   */
  supplierList: (load: SupplierChoicesLoad, onChoose: (s: SupplierChoice) => void) => ReactNode;
  /** Called once per submit answered `created` (the page leaves the arrival message and navigates). */
  onCreated: (order: PurchaseOrder) => void;
}

interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}
/** Where a focus move goes: the banner, a field (its first sentence), or — after Choose — Deliver to (else Change). */
type Target = 'banner' | 'chosen' | CreateOrderField;
/** Where focus goes when the choice dialog opens on an answer (M-3 step 2): its search box, its Retry, or Go back. */
const focusFor = (l: SupplierChoicesLoad): 'search' | 'retry' | 'dismiss' => (l.kind === 'ok' ? 'search' : l.kind === 'forbidden' ? 'dismiss' : 'retry');

/**
 * `/purchase-orders/new`'s form (purchase-orders-plan.md §2.4 and Revision 0 §4 PO-3; UX B-2 `NEW_PURCHASE_ORDER`; PO
 * D-5 (a); b4-architecture.md Revision 10 R10-5), props-only. It creates a DRAFT, header first: no line table, and no
 * expected date while D-4 is unruled.
 *
 * **Supplier** is chosen in a dialog over a paged list of live suppliers (the suppliers S-5 shape, final RED M-3 steps
 * 1–5): **Choose a supplier** reads the list's first page (the button `loading`, no dialog yet), then opens the dialog on
 * whatever answered — the list (focus on its search box), the region error (focus on Retry) or the lost-permission sentence
 * (focus on Go back). Every open reads afresh. **Choose** closes the dialog, shows *"{code} — {name}"* with **Change**, and
 * moves focus to Deliver to; nothing is announced. **Change** clears the chosen supplier at once and opens the dialog by a
 * fresh read. The order's currency is the chosen supplier's served `currency_code`, stated as text and sent (D-5 (a)).
 * **Deliver to** is a select over the served live warehouses, `{code} — {name}`, served order, no preselection (R10-5); no
 * label is ever an id. A failed warehouse read shows the field's region error with Retry instead of the select.
 *
 * **Create purchase order** with nothing chosen shows `supplierRequired` / `warehouseRequired` beside their fields, focus on
 * the first in screen order, and sends nothing (every press: the focus moves again, so the sentence is read again). Every
 * submit is a new request with a new Idempotency-Key (the action mints it); the body is the two ids and the currency. A 422's
 * sentences show beside their fields (P4: the supplier's beside the chosen supplier, read with Change, which takes focus);
 * its others, a 409 (P2 the number race) and every other failure in the banner under **Not created**, the server's sentence
 * as sent; the form keeps what was chosen. A lost answer says the interrupted sentence (check the Draft list), never "try
 * again". A 201 keeps the form busy: the page is navigating to the new order (957853b). A 401 renders nothing.
 */
export function NewOrderForm({ create, createCode, warehouses: firstWarehouses, warehousesViewCode, reloadWarehouses, readSuppliers, supplierList, onCreated }: NewOrderFormProps) {
  const [chosen, setChosen] = useState<SupplierChoice | null>(null);
  const [warehouseId, setWarehouseId] = useState('');
  const [warehouses, setWarehouses] = useState<{ gen: number; value: WarehouseChoicesLoad }>({ gen: 0, value: firstWarehouses });
  const [retryingWarehouses, setRetryingWarehouses] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<CreateOrderField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: Target } | null>(null);
  // The choice dialog: `opening` while the press's first read is in flight (no dialog yet); `dialog` while it is open, on
  // that read's answer. Each read takes a number; an answer to an older one (superseded by a later press) is dropped.
  const [opening, setOpening] = useState(false);
  const [dialog, setDialog] = useState<{ gen: number; load: SupplierChoicesLoad } | null>(null);
  const reads = useRef(0);
  const gen = useRef(0);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const chooseRef = useRef<HTMLButtonElement>(null);
  const changeRef = useRef<HTMLButtonElement>(null);
  const selectRef = useRef<HTMLSelectElement>(null);
  const warehouseErrorRef = useRef<HTMLParagraphElement>(null);
  const choicesRef = useRef<HTMLDivElement>(null);
  const dismissRef = useRef<HTMLButtonElement>(null);
  const supplierHintId = useId();
  const supplierErrorId = useId();
  // The dialog's first focus, resolved from the DOM when the dialog asks for it (its content is portalled; AddEntryForm).
  const firstFocus = useRef<'search' | 'retry' | 'dismiss' | null>(null);
  const initialFocus = useMemo<RefObject<HTMLElement | null>>(
    () => ({
      get current() {
        const to = firstFocus.current;
        if (to === null) return null;
        if (to === 'dismiss') return dismissRef.current;
        return to === 'search' ? (choicesRef.current?.querySelector<HTMLElement>('input[type="search"]') ?? null) : (choicesRef.current?.querySelector<HTMLElement>('button') ?? null);
      },
    }),
    [],
  );

  // Field focus moves only after the commit that renders the field's sentence and links it (the `LocationForm` rule).
  useLayoutEffect(() => {
    if (focusTarget === null) return;
    const to = focusTarget.to;
    if (to === 'banner') bannerRef.current?.focus();
    else if (to === 'chosen') (selectRef.current ?? changeRef.current)?.focus();
    else if (to === 'supplier_id') (changeRef.current ?? chooseRef.current)?.focus();
    else (selectRef.current ?? warehouseErrorRef.current)?.focus();
  }, [focusTarget]);
  const focusTo = (to: Target) => {
    gen.current += 1;
    setFocusTarget({ gen: gen.current, to });
  };
  // Every banner clears the field sentences first (step 4.1 RED L-3).
  const show = (heading: string, body: ReactNode) => {
    setFieldErrors({});
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
    setFocusTarget({ gen: gen.current, to: 'banner' });
  };

  /** Choose a supplier (M-3 step 1): read the first page, then open the dialog over whatever answered. */
  const openChoices = async () => {
    if (opening || dialog !== null) return;
    setOpening(true);
    const mine = ++reads.current;
    let r: SupplierChoicesLoad;
    try {
      r = await readSuppliers();
    } catch {
      r = { kind: 'unreachable' };
    }
    if (mine !== reads.current) return;
    setOpening(false);
    // A 401 is the caller's (the session-ended notice): no dialog.
    if (r.kind === 'session-ended') return;
    firstFocus.current = focusFor(r);
    setDialog({ gen: mine, load: r });
  };
  const closeChoices = () => {
    reads.current += 1;
    setDialog(null);
  };
  /** The supplier's sentence belongs to the choice it was given for: a new choice, or Change, drops it. */
  const dropSupplierError = (cur: FieldErrors<CreateOrderField>): FieldErrors<CreateOrderField> => {
    const next = { ...cur };
    delete next.supplier_id;
    return next;
  };
  // Choose (M-3 step 4): close the dialog and show the choice; focus moves to Deliver to (else Change) in the layout effect
  // of the commit that renders them. The dialog's own focus return runs later, on its timer, and keeps a focus the page
  // has already taken (`Dialog`'s close rule), so nothing returns it to the opener.
  const choose = (s: SupplierChoice) => {
    setDialog(null);
    setChosen(s);
    setFieldErrors(dropSupplierError);
    // A functional update, not `focusTo`: this handler is handed to the list during render, so it reads no ref.
    setFocusTarget((cur) => ({ gen: (cur?.gen ?? 0) + 1, to: 'chosen' }));
  };
  // Change (M-3 step 5): clear the chosen supplier at once, put focus on Choose a supplier (now loading), read afresh.
  const change = () => {
    flushSync(() => {
      setChosen(null);
      setNotice(null);
      setFieldErrors(dropSupplierError);
    });
    chooseRef.current?.focus();
    void openChoices();
  };

  const retryWarehouses = async () => {
    setRetryingWarehouses(true);
    let r: WarehouseChoicesLoad;
    try {
      r = await reloadWarehouses();
    } catch {
      r = { kind: 'unreachable' };
    }
    setRetryingWarehouses(false);
    if (r.kind === 'session-ended') return;
    setWarehouses((cur) => ({ gen: cur.gen + 1, value: r }));
    // po-3-red.md M-2: the pressed Retry leaves with its region; the select that replaces it takes focus (FE-R3 H-2's rule).
    if (r.kind === 'ok') focusTo('warehouse_id');
  };

  /**
   * po-3-red.md L-1 (UX R1b-3 (b)): after the server refused the chosen warehouse (P4, archived meanwhile), the field reads
   * the live list once more. An `ok` answer replaces the list and clears the choice unless the chosen id is still listed —
   * so the select never holds a value its list lacks (L-8's case stays unreachable on create, M11). Any other answer keeps
   * the list shown (nothing on screen becomes false, and focus does not move again); a 401 is the caller's.
   */
  const rereadWarehousesAfterRefusal = async () => {
    let r: WarehouseChoicesLoad;
    try {
      r = await reloadWarehouses();
    } catch {
      return;
    }
    if (r.kind !== 'ok') return;
    setWarehouses((cur) => ({ gen: cur.gen + 1, value: r }));
    setWarehouseId((cur) => (r.warehouses.some((x) => x.id === cur) ? cur : ''));
  };

  const settle = (r: CreateOrderResult) => {
    switch (r.kind) {
      case 'created':
        setFieldErrors({});
        setNotice(null);
        onCreated(r.order);
        return;
      case 'invalid': {
        const first = CREATE_ORDER_FIELDS.find((f) => r.fieldErrors[f] !== undefined);
        if (r.fieldErrors.warehouse_id !== undefined) void rereadWarehousesAfterRefusal();
        if (r.formErrors.length > 0) {
          show(
            NOT_CREATED,
            <>
              {r.formErrors.map((m, i) => <p key={i}>{m}</p>)}
              {marksAnyField(r.fieldErrors) ? <p key="mixed">{MIXED_FIELDS_TOO}</p> : null}
            </>,
          );
          setFieldErrors(r.fieldErrors);
          return;
        }
        setFieldErrors(r.fieldErrors);
        setNotice(null);
        if (first) focusTo(first);
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
            {PURCHASING_FORBIDDEN.before}
            <em>{label}</em>
            {PURCHASING_FORBIDDEN.after}
          </p>,
        );
        return;
      }
      case 'not-found':
        show(NOT_CREATED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, NEW_PURCHASE_ORDER.interrupted);
        return;
      case 'server-error':
        show(WENT_WRONG, NEW_PURCHASE_ORDER.interrupted);
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy) return;
    // The person's own unfinished input, caught on every press: nothing chosen. Nothing is sent.
    const missing: FieldErrors<CreateOrderField> = {};
    if (chosen === null) missing.supplier_id = NEW_PURCHASE_ORDER.supplierRequired;
    if (warehouseId === '') missing.warehouse_id = NEW_PURCHASE_ORDER.warehouseRequired;
    const first = CREATE_ORDER_FIELDS.find((f) => missing[f] !== undefined);
    if (chosen === null || first !== undefined) {
      setNotice(null);
      setFieldErrors(missing);
      focusTo(first ?? 'supplier_id');
      return;
    }
    setBusy(true);
    let r: CreateOrderResult;
    try {
      r = await create({ supplier_id: chosen.id, warehouse_id: warehouseId, currency_code: chosen.currency_code });
    } catch {
      r = { kind: 'unreachable' };
    }
    // A 201 keeps the form busy: the page is already navigating to the new order, and a press before it lands would send
    // a new key and create a second draft (suppliers-s2-red.md L-1). Every other answer gives the form back.
    if (r.kind !== 'created') setBusy(false);
    settle(r);
  };

  const supplierError = fieldErrors.supplier_id;
  const describedBy = [supplierHintId, supplierError ? supplierErrorId : null].filter(Boolean).join(' ');
  const w = warehouses.value;
  const labels = new Map(w.kind === 'ok' ? w.warehouses.map((x) => [x.id, NEW_PURCHASE_ORDER.warehouseOption(x.code, x.name)] as const) : []);

  return (
    <>
      <form method="post" noValidate className={styles.form} onSubmit={(e) => void onSubmit(e)}>
        {notice ? (
          <Banner key={notice.gen} tone="danger" heading={notice.heading} ref={bannerRef}>
            {typeof notice.body === 'string' ? <p>{notice.body}</p> : notice.body}
          </Banner>
        ) : null}
        <fieldset className={styles.choiceField}>
          <legend>{NEW_PURCHASE_ORDER.supplier}</legend>
          {chosen !== null ? (
            <>
              <p>{SUPPLIER_CHOICES.chosen(chosen.code, chosen.name)}</p>
              <Button ref={changeRef} size="sm" aria-label={SUPPLIER_CHOICES.changeName} aria-describedby={describedBy} onClick={change}>
                {SUPPLIER_CHOICES.change}
              </Button>
            </>
          ) : (
            <Button ref={chooseRef} size="sm" loading={opening} aria-describedby={describedBy} onClick={() => void openChoices()}>
              {SUPPLIER_CHOICES.open}
            </Button>
          )}
          <span id={supplierHintId} className={styles.hint}>
            {NEW_PURCHASE_ORDER.supplierHint}
          </span>
          {supplierError ? (
            <span id={supplierErrorId} className={styles.error}>
              <CircleAlert aria-hidden="true" width={16} height={16} strokeWidth={1.5} />
              <span>{supplierError}</span>
            </span>
          ) : null}
        </fieldset>
        <p className={styles.muted}>{chosen !== null ? NEW_PURCHASE_ORDER.currency(chosen.currency_code) : NEW_PURCHASE_ORDER.currencyBeforeSupplier}</p>
        {w.kind === 'ok' ? (
          <SelectField
            ref={selectRef}
            id="order-warehouse"
            name="warehouse_id"
            label={NEW_PURCHASE_ORDER.warehouse}
            options={w.warehouses.map((x) => x.id)}
            // R10-5 per final RED L-8: a label is never an id. On create every value is a listed one or the prompt's '': the
            // list changes only by the field's Retry (no select exists then) or after a warehouse P4, which clears the choice
            // when the new list lacks it (po-3-red.md L-1).
            labelOf={(id) => labels.get(id) ?? ''}
            value={warehouseId}
            onChange={(v) => {
              setWarehouseId(v);
              setFieldErrors((cur) => {
                const next = { ...cur };
                delete next.warehouse_id;
                return next;
              });
            }}
            hints={w.warehouses.length > 0 ? [NEW_PURCHASE_ORDER.warehouseHint] : [NEW_PURCHASE_ORDER.warehouseHint, NEW_PURCHASE_ORDER.noWarehouses]}
            error={fieldErrors.warehouse_id}
            prompt={NEW_PURCHASE_ORDER.warehousePrompt}
          />
        ) : w.kind === 'session-ended' ? null : w.kind === 'forbidden' ? (
          // po-3-red.md L-2: the read was refused (ListWarehouses' permission lost meanwhile): say so, with no Retry to repeat it.
          <div>
            <p className={styles.muted}>
              {PURCHASING_FORBIDDEN.before}
              <em>{(warehousesViewCode !== null ? PERMISSION_LABELS[warehousesViewCode]?.label : undefined) ?? ''}</em>
              {PURCHASING_FORBIDDEN.after}
            </p>
            {fieldErrors.warehouse_id ? (
              <p ref={warehouseErrorRef} tabIndex={-1} className={styles.error}>
                {fieldErrors.warehouse_id}
              </p>
            ) : null}
          </div>
        ) : (
          <div>
            <RegionError key={warehouses.gen} heading={w.kind === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={NEW_PURCHASE_ORDER.warehousesLoadFailed} retrying={retryingWarehouses} autoFocus={warehouses.gen > 0} onRetry={() => void retryWarehouses()} />
            {fieldErrors.warehouse_id ? (
              <p ref={warehouseErrorRef} tabIndex={-1} className={styles.error}>
                {fieldErrors.warehouse_id}
              </p>
            ) : null}
          </div>
        )}
        <div className={styles.actions}>
          <Button type="submit" variant="primary" loading={busy}>
            {NEW_PURCHASE_ORDER.submit}
          </Button>
          <Link href="/purchase-orders">{NEW_PURCHASE_ORDER.cancel}</Link>
        </div>
      </form>
      {/* A sibling AFTER the form (never inside it, in the DOM or the React tree): a submit event inside the dialog's list
          bubbles through React's tree, not the DOM's, so inside the form it would reach Create (M-3 step 3). */}
      {dialog !== null ? (
        <Dialog
          open
          onOpenChange={(open) => {
            if (!open) closeChoices();
          }}
          title={SUPPLIER_CHOICES.title}
          description={null}
          initialFocusRef={initialFocus}
          fallbackFocusRef={chooseRef}
        >
          <div ref={choicesRef} className={styles.choices}>
            {supplierList(dialog.load, choose)}
          </div>
          <Button ref={dismissRef} onClick={closeChoices}>
            {SUPPLIER_CHOICES.dismiss}
          </Button>
        </Dialog>
      ) : null}
    </>
  );
}
