'use client';

import { useLayoutEffect, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { RegionError } from '@/components/states/PageStates';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { SelectField } from '@/components/ui/SelectField';
import { COULD_NOT_REACH, MIXED_FIELDS_TOO, NOT_SAVED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { NEW_PURCHASE_ORDER, PURCHASE_ORDER_PAGE, PURCHASING_FORBIDDEN } from '@/copy/purchasing';
import { PAGE_STATES } from '@/copy/shell';
import type { PurchaseOrder, UpdatePurchaseOrderInput } from '@/lib/api/purchasing';
import { changedFields, marksAnyField } from '@/lib/forms/fieldErrors';
import type { WarehouseChoicesLoad } from '@/lib/purchasing/load';
import { ORDER_FIELDS, type OrderField, type SaveOrderAction, type SaveOrderResult } from '@/lib/purchasing/results';
import type { FieldErrors } from '@/lib/results';
import styles from './Purchasing.module.css';

export interface OrderHeaderFormProps {
  /** The served order (`GetPurchaseOrder`), a live draft; a new one after a save's re-read resets the choice to it. */
  order: PurchaseOrder;
  /** The live warehouses, read on the server for the first render (a failure shows the field's own Retry). */
  warehouses: WarehouseChoicesLoad;
  /** The field's Retry, and its one re-read after a refused warehouse (P4): the live warehouses over the browser transport. */
  reloadWarehouses: () => Promise<WarehouseChoicesLoad>;
  save: SaveOrderAction;
  /** `gateCode('UpdatePurchaseOrder')`, for the lost-permission sentence's label (PURCHASING_FORBIDDEN). */
  editCode: string | null;
  /** `gateCode('ListWarehouses')`, for the lost-permission sentence when the field's read is answered 403. */
  warehousesViewCode: string | null;
  /** The supplier's name as the page shows it (a link for a GetSupplier holder): text here, never an input (PO D-6). */
  supplier: ReactNode;
  /**
   * The order's warehouse as the read-only page shows it (a link for a GetWarehouse holder): shown as text above the field's
   * own state whenever the warehouses could not be read, so where the order goes is never hidden (po-4a-red.md M-1).
   */
  deliverTo: ReactNode;
  /** Writes the page's status region (B-3 `saved`). */
  announce: (text: string) => void;
  /** The page's `<h1>`: focus goes there after a save. */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /** Called once per Save answered `saved`, after the message is written. */
  onSaved?: () => void;
  /**
   * Called once per Save press that will send a request, after every guard and just before the request (30d2edb): the page
   * empties its region, so a second identical "{number} was saved." is a change and is given again.
   */
  onSaveStarted?: () => void;
  /**
   * A refused Save whose re-read found the order no longer an editable draft (deleted elsewhere: P8's sentence; approved or
   * cancelled elsewhere: UX D-2's `noLongerDraft`). The page is already read-only (this form is gone), so it shows the
   * sentence under **Not saved**.
   */
  onRefusedStale: (message: string) => void;
}

interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}
type Target = 'banner' | 'h1' | OrderField;

/**
 * A draft purchase order's header editor (purchase-orders-plan.md §2.2 "Draft (editor)", Revision 1 R1-5 PO-4a; UX B-3 /
 * R1-B `PURCHASE_ORDER_PAGE`, R1a-1 row 4, Part D D-2; b4-architecture.md Revision 10 R10-5 as amended by final RED L-8),
 * props-only. **Supplier** is text with *"The supplier can't be changed."* (PO D-6: never an input; its final form naming
 * delete-and-raise-again arrives with Delete, PO-6). **Deliver to** is a select over the served LIVE warehouses,
 * `{code} — {name}`, in served order, holding the order's warehouse; a served warehouse the list lacks stays first,
 * labelled from the order's own reference (`warehouse.name` — the reference serves no code), never its id (L-8). A failed
 * read shows the field's region error with Retry instead (an `ok` Retry focuses the select; a 403 says which permission is
 * missing, with no Retry — po-3-red.md M-2 / L-2). **Currency** is text. There is no expected date (D-4 unruled).
 *
 * **Save** is `aria-disabled` with *Nothing to save.* while Deliver to holds the order's warehouse; the body holds only the
 * changed member (`changedFields`). After `saved` the page's region says *"{number} was saved."* and focus goes to the
 * `h1`. A 422's field sentence shows beside Deliver to (focused) and, for P4, the live list is read once more: an `ok`
 * answer replaces it and puts the order's own warehouse back when the refused one is gone (po-3-red.md L-1; any other
 * answer keeps the list). Its other sentences, a 409 shown as sent (P1), UX D-2's changed-since sentence, and every other
 * failure show in the banner under **Not saved**, focused, the choice kept. A refusal whose re-read found the order no
 * longer an editable draft goes to the page (`onRefusedStale`). A 401 renders nothing.
 */
export function OrderHeaderForm({ order, warehouses: firstWarehouses, reloadWarehouses, save, editCode, warehousesViewCode, supplier, deliverTo, announce, fallbackFocusRef, onSaved, onSaveStarted, onRefusedStale }: OrderHeaderFormProps) {
  const [loadedKey, setLoadedKey] = useState(order);
  const [warehouseId, setWarehouseId] = useState(order.warehouse.id);
  const [warehouses, setWarehouses] = useState<{ gen: number; value: WarehouseChoicesLoad }>({ gen: 0, value: firstWarehouses });
  const [retryingWarehouses, setRetryingWarehouses] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<OrderField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: Target } | null>(null);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const selectRef = useRef<HTMLSelectElement>(null);
  const warehouseErrorRef = useRef<HTMLParagraphElement>(null);
  const gen = useRef(0);

  // A new served order (the re-read after a save) is what the field now edits.
  if (loadedKey !== order) {
    setLoadedKey(order);
    setWarehouseId(order.warehouse.id);
  }

  // After the commit that renders the sentence or banner (focus never runs ahead of what it lands on).
  useLayoutEffect(() => {
    if (focusTarget === null) return;
    if (focusTarget.to === 'banner') bannerRef.current?.focus();
    else if (focusTarget.to === 'h1') fallbackFocusRef.current?.focus();
    else (selectRef.current ?? warehouseErrorRef.current)?.focus();
    // Once per target generation.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [focusTarget]);
  const focusTo = (to: Target) => {
    gen.current += 1;
    setFocusTarget({ gen: gen.current, to });
  };
  // Every banner clears the field sentences first, so an earlier 422's sentence never stays under a banner about something else.
  const show = (heading: string, body: ReactNode) => {
    setFieldErrors({});
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
    setFocusTarget({ gen: gen.current, to: 'banner' });
  };

  const changes = changedFields<UpdatePurchaseOrderInput, OrderField>(ORDER_FIELDS, { warehouse_id: order.warehouse.id }, { warehouse_id: warehouseId });
  const nothing = Object.keys(changes).length === 0;

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
    // The pressed Retry leaves with its region; the select that replaces it takes focus (po-3-red.md M-2).
    if (r.kind === 'ok') focusTo('warehouse_id');
  };

  /**
   * After the server refused the chosen warehouse (P4, archived meanwhile; po-3-red.md L-1): one more read of the live list.
   * An `ok` answer replaces the list and puts the order's own warehouse back unless the chosen one is still listed, so the
   * select never holds a refused value; any other answer keeps the list shown (nothing on screen becomes false).
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
    setWarehouseId((cur) => (r.warehouses.some((x) => x.id === cur) ? cur : order.warehouse.id));
  };

  const settle = (r: SaveOrderResult, number: string) => {
    switch (r.kind) {
      case 'saved':
        setFieldErrors({});
        setNotice(null);
        announce(PURCHASE_ORDER_PAGE.saved(r.order.order_number));
        focusTo('h1');
        onSaved?.();
        return;
      case 'invalid': {
        if (r.fieldErrors.warehouse_id !== undefined) void rereadWarehousesAfterRefusal();
        if (r.formErrors.length > 0) {
          show(
            NOT_SAVED,
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
        const first = ORDER_FIELDS.find((f) => r.fieldErrors[f] !== undefined);
        if (first) focusTo(first);
        return;
      }
      case 'refused-archived':
        onRefusedStale(r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'no-longer-draft':
        onRefusedStale(PURCHASE_ORDER_PAGE.noLongerDraft(r.order.order_number, r.order.status));
        return;
      case 'changed-since-loaded':
        show(NOT_SAVED, PURCHASE_ORDER_PAGE.changedSinceLoaded(number));
        return;
      case 'refused':
        show(NOT_SAVED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        const label = (editCode !== null ? PERMISSION_LABELS[editCode]?.label : undefined) ?? '';
        show(
          NOT_SAVED,
          <p>
            {PURCHASING_FORBIDDEN.before}
            <em>{label}</em>
            {PURCHASING_FORBIDDEN.after}
          </p>,
        );
        return;
      }
      case 'not-found':
        show(NOT_SAVED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, PURCHASE_ORDER_PAGE.interrupted(number));
        return;
      case 'server-error':
        show(WENT_WRONG, PURCHASE_ORDER_PAGE.interrupted(number));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busy || nothing) return;
    onSaveStarted?.();
    setBusy(true);
    let r: SaveOrderResult;
    try {
      r = await save(changes);
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    settle(r, order.order_number);
  };

  const w = warehouses.value;
  const labels = new Map(w.kind === 'ok' ? w.warehouses.map((x) => [x.id, NEW_PURCHASE_ORDER.warehouseOption(x.code, x.name)] as const) : []);
  // Final RED L-8: an id the list lacks is the order's own (the only value the select can hold besides listed ones), so its
  // label is the order's served reference — never the id.
  const labelOf = (id: string) => labels.get(id) ?? (id === order.warehouse.id ? order.warehouse.name : '');
  const fieldError = fieldErrors.warehouse_id;
  // po-4a-red.md L-3: the order's own warehouse stays an option while another is chosen, even when the list lacks it (L-8),
  // so choosing it back (Nothing to save.) never needs a reload.
  const listed = w.kind === 'ok' ? w.warehouses.map((x) => x.id) : [];
  const options = listed.includes(order.warehouse.id) ? listed : [order.warehouse.id, ...listed];
  const current = (
    <dl className={styles.readOnly}>
      <dt>{PURCHASE_ORDER_PAGE.deliverTo}</dt>
      <dd>{deliverTo}</dd>
    </dl>
  );

  return (
    <form method="post" noValidate className={styles.form} onSubmit={(e) => void onSubmit(e)}>
      {notice ? (
        <Banner key={notice.gen} tone="danger" heading={notice.heading} ref={bannerRef}>
          {typeof notice.body === 'string' ? <p>{notice.body}</p> : notice.body}
        </Banner>
      ) : null}
      <dl className={styles.readOnly}>
        <dt>{PURCHASE_ORDER_PAGE.supplier}</dt>
        <dd>{supplier}</dd>
        <dd>
          <p className={styles.hint}>{PURCHASE_ORDER_PAGE.supplierFixed}</p>
        </dd>
      </dl>
      {w.kind === 'ok' ? (
        <SelectField
          ref={selectRef}
          id="order-warehouse"
          name="warehouse_id"
          label={NEW_PURCHASE_ORDER.warehouse}
          options={options}
          labelOf={labelOf}
          value={warehouseId}
          onChange={(v) => {
            setWarehouseId(v);
            setFieldErrors((cur) => {
              const next = { ...cur };
              delete next.warehouse_id;
              return next;
            });
          }}
          hints={[NEW_PURCHASE_ORDER.warehouseHint]}
          error={fieldError}
        />
      ) : w.kind === 'session-ended' ? null : w.kind === 'forbidden' ? (
        // The read was refused (ListWarehouses' permission lost meanwhile): say so, with no Retry to repeat it.
        <div>
          {current}
          <p className={styles.muted}>
            {PURCHASING_FORBIDDEN.before}
            <em>{(warehousesViewCode !== null ? PERMISSION_LABELS[warehousesViewCode]?.label : undefined) ?? ''}</em>
            {PURCHASING_FORBIDDEN.after}
          </p>
        </div>
      ) : (
        <div>
          {current}
          <RegionError key={warehouses.gen} heading={w.kind === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={NEW_PURCHASE_ORDER.warehousesLoadFailed} retrying={retryingWarehouses} autoFocus={warehouses.gen > 0} onRetry={() => void retryWarehouses()} />
          {fieldError ? (
            <p ref={warehouseErrorRef} tabIndex={-1} className={styles.error}>
              {fieldError}
            </p>
          ) : null}
        </div>
      )}
      <dl className={styles.readOnly}>
        <dt>{PURCHASE_ORDER_PAGE.currency}</dt>
        <dd>{order.currency_code}</dd>
      </dl>
      <div className={styles.actions}>
        <Button type="submit" variant="primary" loading={busy} unavailableReason={nothing ? PURCHASE_ORDER_PAGE.saveNothing : undefined}>
          {PURCHASE_ORDER_PAGE.save}
        </Button>
      </div>
    </form>
  );
}
