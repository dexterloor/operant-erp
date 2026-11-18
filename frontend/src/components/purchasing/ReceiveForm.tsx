'use client';

import { useLayoutEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { SelectField } from '@/components/ui/SelectField';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, NOT_RECORDED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { unitSuffixSpoken } from '@/copy/items';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PURCHASING_FORBIDDEN, RECEIVE } from '@/copy/purchasing';
import { PAGE_STATES } from '@/copy/shell';
import { kindLabel } from '@/copy/warehouses';
import type { GoodsReceipt, PurchaseOrder, PurchaseOrderLine } from '@/lib/api/purchasing';
import { renderQuantity } from '@/lib/format/quantity';
import type { LocationChoicesLoad } from '@/lib/purchasing/load';
import { isNotReceived, type ReceiveAction, type ReceiveLineValues, type ReceiveResult, type ReceiveRowField } from '@/lib/purchasing/results';
import styles from './Purchasing.module.css';

export interface ReceiveFormProps {
  /** The order as the page shows it: its number, warehouse name (intro, dialog, arrival) and id (`key` of the page). */
  order: PurchaseOrder;
  /** The order's live lines as served; only those with something still to come are rows (S-10). */
  lines: readonly PurchaseOrderLine[];
  /** The order's warehouse's live locations (first render, over the server transport). Only an `ok` load reaches here. */
  locations: Extract<LocationChoicesLoad, { kind: 'ok' }>['locations'];
  /** Record receipt; never throws past here (a throw is `unreachable`). */
  record: ReceiveAction;
  /** After a location refusal (R2): the live locations again. A failed reading keeps what is shown. */
  reloadLocations: () => Promise<LocationChoicesLoad>;
  /** `gateCode('CreateGoodsReceipt')`, for B-12's permission label on a 403 (null when the contract names none). */
  createCode: string | null;
  /** After a 201: the page leaves the arrival sentence for the order page and goes there. The form stays busy. */
  onRecorded: (receipt: GoodsReceipt, order: PurchaseOrder | null, lineCount: number) => void;
  /**
   * R6 (or any refusal whose re-read shows an order nothing can be received on): the page shows its no-form state. `why` is
   * `'earlier-recorded'` when that refusal was an idempotency-key conflict (GR-2 H-1, d2): the page then says the earlier
   * attempt was recorded instead of `orderChanged`.
   */
  onOrderChanged: (order: PurchaseOrder, why: 'changed' | 'earlier-recorded') => void;
  /** R1's re-read order (receivable still): the page shows it (its intro). */
  onOrder: (order: PurchaseOrder) => void;
  /** gr-2-red.md M-1: a locations re-read (after R2) that lists none — the page shows its no-locations state. */
  onNoLocations: () => void;
}

interface Entry {
  quantity: string;
  location: string;
}
type RowErrors = Partial<Record<ReceiveRowField, string>>;
interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}
type Target = { gen: number; to: 'banner' } | { gen: number; to: 'field'; lineId: string; field: ReceiveRowField };

const EMPTY: Entry = { quantity: '', location: '' };
/**
 * S-10: a row only for a line with something still to come (the zero rule read on the served outstanding). The
 * `archived_at === null` clause is defence only (gr-2-red.md L-1): the live lines read serves no deleted line and an ordered
 * order's lines cannot be deleted, so no page test can drive it — a review check, not tested behaviour.
 */
const receivable = (lines: readonly PurchaseOrderLine[]) => lines.filter((l) => l.archived_at === null && !isNotReceived(l.quantity_outstanding));

/**
 * UX B-8 / R1-B `RECEIVE` (GR-2; plan §2.6, Revision 1 R1-5 GR-2; PO D-12 items 1–8). One row per order line with something
 * still to come, in served order: Line · Item · Ordered · Received so far · Still to come · **Receiving now** · **Location**.
 * Every Receiving-now box starts EMPTY (D-12 item 1) beside *Still to come*; no control fills one row or several (items 2, 8).
 * The line's unit is the served line's own `unit_of_measure_code` (BK-33), shown beside every quantity and as the box's
 * suffix — never the item's. Location is a select over the warehouse's live locations, `{code} — {kind}`, none preselected.
 *
 * Record receipt runs the person's own checks first, nothing sent: a row empty or only zeros is not received (M-7 / L-9,
 * `isNotReceived`); none left → `nothingToRecord` beside the first row; a received row with no location →
 * `locationRequired(n)` beside it (a location chosen on an empty row sends nothing, item 4). Then UX's confirm dialog with
 * the count (H-2; initial focus on Go back): no request until its confirm, Go back sends nothing. One request at a time,
 * under the action's held key (GR-2 H-1: the same key until a receipt is recorded or a press is refused as already recorded). The rows sent are remembered in request order (M-6): a 422 `/lines/{i}/…` sentence is
 * put beside the row sent at `i`, never the `i`-th row on screen; one that maps to no row shown is `lineErrorUnplaced(n)` in
 * the banner. R2 (a location sentence) also re-reads the locations. R1 → the server's sentence + `stalePrefill`, the lines
 * as re-read, every typed value kept by line id (X-15). R3 → `onHandTooLarge(n)` (final RED M-1), never the served detail.
 * Typed text is never dropped by a refusal or a failed reading. Focus moves before a sentence is spoken (the banner or the
 * field takes focus in the commit that renders it). Props only; branches on `kind`, never on a code (architecture.test.ts).
 */
export function ReceiveForm({ order, lines: firstLines, locations: firstLocations, record, reloadLocations, createCode, onRecorded, onOrderChanged, onOrder, onNoLocations }: ReceiveFormProps) {
  const [lines, setLines] = useState<readonly PurchaseOrderLine[]>(firstLines);
  const [locations, setLocations] = useState(firstLocations);
  const [entries, setEntries] = useState<ReadonlyMap<string, Entry>>(new Map());
  const [errors, setErrors] = useState<ReadonlyMap<string, RowErrors>>(new Map());
  const [notice, setNotice] = useState<Notice | null>(null);
  const [confirming, setConfirming] = useState<ReceiveLineValues[] | null>(null);
  const [busy, setBusy] = useState(false);
  const busyRef = useRef(false);
  const [target, setTarget] = useState<Target | null>(null);
  const gen = useRef(0);
  const bannerRef = useRef<HTMLDivElement>(null);
  const submitRef = useRef<HTMLButtonElement>(null);
  const backRef = useRef<HTMLButtonElement>(null);
  const formRef = useRef<HTMLFormElement>(null);

  const rows = receivable(lines);
  const entryOf = (id: string) => entries.get(id) ?? EMPTY;

  // Focus moves in the commit that renders the sentence (the `LocationForm` rule), so the sentence is read where focus is.
  useLayoutEffect(() => {
    if (target === null) return;
    if (target.to === 'banner') {
      bannerRef.current?.focus();
      return;
    }
    const el = document.getElementById(`receive-${target.lineId}-${target.field === 'quantity' ? 'quantity' : 'location'}`);
    if (el !== null && el.isConnected) el.focus();
    else bannerRef.current?.focus();
  }, [target]);

  const focusTo = (t: Omit<Extract<Target, { to: 'field' }>, 'gen'> | { to: 'banner' }) => {
    gen.current += 1;
    setTarget({ gen: gen.current, ...t } as Target);
  };
  const show = (heading: string, body: ReactNode) => {
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
    focusTo({ to: 'banner' });
  };
  const type = (lineId: string, field: keyof Entry, value: string) => {
    setEntries((cur) => new Map(cur).set(lineId, { ...(cur.get(lineId) ?? EMPTY), [field]: value }));
    setErrors((cur) => {
      const e = cur.get(lineId);
      if (e === undefined) return cur;
      const next = new Map(cur);
      const f: ReceiveRowField = field === 'quantity' ? 'quantity' : 'storage_location_id';
      const rest = { ...e };
      delete rest[f];
      next.set(lineId, rest);
      return next;
    });
  };

  const onSubmit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (busyRef.current) return;
    const included = rows.filter((l) => !isNotReceived(entryOf(l.id).quantity));
    const first = rows[0];
    if (included.length === 0 || first === undefined) {
      setNotice(null);
      if (first === undefined) return;
      setErrors(new Map([[first.id, { quantity: RECEIVE.nothingToRecord }]]));
      focusTo({ to: 'field', lineId: first.id, field: 'quantity' });
      return;
    }
    const unplaced = included.filter((l) => entryOf(l.id).location === '');
    if (unplaced.length > 0) {
      setNotice(null);
      setErrors(new Map(unplaced.map((l) => [l.id, { storage_location_id: RECEIVE.locationRequired(l.line_number) }])));
      focusTo({ to: 'field', lineId: unplaced[0]!.id, field: 'storage_location_id' });
      return;
    }
    setConfirming(included.map((l) => ({ purchase_order_line_id: l.id, quantity: entryOf(l.id).quantity, storage_location_id: entryOf(l.id).location })));
  };

  const settle = async (sent: readonly ReceiveLineValues[], r: ReceiveResult) => {
    switch (r.kind) {
      case 'recorded':
        onRecorded(r.receipt, r.order, sent.length);
        return;
      case 'invalid': {
        const next = new Map<string, RowErrors>();
        const banner: string[] = [...r.formErrors];
        let firstField: { lineId: string; field: ReceiveRowField } | null = null;
        let locationRefused = false;
        for (const [index, fields] of r.rows) {
          // M-6: the request index names the row SENT at that position, never the row at that position on screen.
          const lineId = sent.find((_, i) => String(i) === index)?.purchase_order_line_id;
          const line = lineId === undefined ? undefined : rows.find((l) => l.id === lineId);
          for (const f of ['quantity', 'storage_location_id'] as const) {
            const m = fields[f];
            if (m === undefined || m === '') continue;
            if (f === 'storage_location_id') locationRefused = true;
            if (line === undefined) {
              const n = lines.find((l) => l.id === lineId)?.line_number;
              banner.push(n === undefined ? m : RECEIVE.lineErrorUnplaced(n, m));
              continue;
            }
            next.set(line.id, { ...next.get(line.id), [f]: m });
            firstField ??= { lineId: line.id, field: f };
          }
        }
        setErrors(next);
        if (locationRefused) {
          const fresh = await reloadLocations().catch(() => null);
          if (fresh !== null && fresh.kind === 'ok' && fresh.locations.length === 0) {
            // gr-2-red.md M-1: nothing left to choose — the page's no-locations state, never "Choose where line {n} goes".
            onNoLocations();
            return;
          }
          if (fresh !== null && fresh.kind === 'ok') {
            setLocations(fresh.locations);
            // A choice the re-read no longer lists (archived, or moved) cannot be sent again: it goes back to the prompt,
            // with the server's sentence beside it. Typed quantities are never touched.
            const listed = new Set(fresh.locations.map((l) => l.id));
            setEntries((cur) => new Map([...cur].map(([id, e]) => [id, listed.has(e.location) || e.location === '' ? e : { ...e, location: '' }] as const)));
          }
        }
        if (banner.length > 0) {
          show(NOT_RECORDED, banner.length === 1 ? banner[0] : banner.map((b, i) => <span key={i}>{b} </span>));
          return;
        }
        setNotice(null);
        if (firstField !== null) focusTo({ to: 'field', ...firstField });
        return;
      }
      case 'over-received':
        // X-15: the lines as re-read; typed values are kept by line id (the entries map is never rebuilt).
        setLines(r.lines);
        onOrder(r.order);
        setErrors(new Map());
        show(
          NOT_RECORDED,
          <>
            {r.message !== '' ? <span>{r.message} </span> : null}
            <span>{RECEIVE.stalePrefill}</span>
          </>,
        );
        return;
      case 'on-hand-too-large':
        show(NOT_RECORDED, r.lineNumber !== null ? RECEIVE.onHandTooLarge(r.lineNumber) : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'order-changed':
        onOrderChanged(r.order, 'changed');
        return;
      case 'earlier-recorded':
        // GR-2 H-1 (UX gr-2-h1-ux.md §1, §3): the X-15 path — lines as re-read, typed values kept by line id, focus to the
        // banner — with `earlierRecorded` alone, never the server's detail. Nothing receivable left → the page's no-form
        // state (d2); a failed re-read → the sentence with a reload clause (d3), never REFUSED_WITHOUT_MESSAGE.
        if (r.order === null) {
          show(NOT_RECORDED, RECEIVE.earlierRecordedReload);
          return;
        }
        if (r.lines === null) {
          onOrderChanged(r.order, 'earlier-recorded');
          return;
        }
        setLines(r.lines);
        onOrder(r.order);
        setErrors(new Map());
        show(NOT_RECORDED, RECEIVE.earlierRecorded);
        return;
      case 'contention':
      case 'refused':
        show(NOT_RECORDED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        const label = (createCode !== null ? PERMISSION_LABELS[createCode]?.label : undefined) ?? '';
        show(
          NOT_RECORDED,
          <>
            {PURCHASING_FORBIDDEN.before}
            <em>{label}</em>
            {PURCHASING_FORBIDDEN.after}
          </>,
        );
        return;
      }
      case 'not-found':
        show(NOT_RECORDED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, RECEIVE.interrupted);
        return;
      case 'server-error':
        show(WENT_WRONG, RECEIVE.interrupted);
        return;
      case 'session-ended':
        return;
    }
  };

  const confirm = async () => {
    if (busyRef.current || confirming === null) return;
    const sent = confirming;
    busyRef.current = true;
    setBusy(true);
    let r: ReceiveResult;
    try {
      r = await record(sent);
    } catch {
      r = { kind: 'unreachable' };
    }
    setConfirming(null);
    if (r.kind === 'recorded') {
      // A 201 keeps the form busy: the page is navigating to the order, and another press would record a second receipt.
      await settle(sent, r);
      return;
    }
    await settle(sent, r);
    busyRef.current = false;
    setBusy(false);
  };

  const options = locations.map((l) => l.id);
  const labels = new Map(locations.map((l) => [l.id, RECEIVE.locationKind(l.code, kindLabel(l.kind))] as const));

  return (
    <>
      <form ref={formRef} method="post" noValidate className={styles.receiveForm} onSubmit={onSubmit}>
        {notice ? (
          <Banner key={notice.gen} tone="danger" heading={notice.heading} ref={bannerRef}>
            <p>{notice.body}</p>
          </Banner>
        ) : null}
        <p className={styles.muted}>{RECEIVE.prefillNote}</p>
        <p className={styles.muted}>{RECEIVE.doneLinesNote}</p>
        <table className={styles.receiveTable}>
          <thead>
            <tr>
              <th scope="col">{RECEIVE.line}</th>
              <th scope="col">{RECEIVE.item}</th>
              <th scope="col">{RECEIVE.ordered}</th>
              <th scope="col">{RECEIVE.receivedSoFar}</th>
              <th scope="col">{RECEIVE.stillToCome}</th>
              <th scope="col">{RECEIVE.receivingNow}</th>
              <th scope="col">{RECEIVE.location}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((l) => {
              const n = l.line_number;
              const code = l.unit_of_measure_code;
              const entry = entryOf(l.id);
              const err = errors.get(l.id) ?? {};
              return (
                <tr key={l.id}>
                  <td>{n}</td>
                  <td>
                    {l.inventory_item.sku} — {l.inventory_item.name}
                  </td>
                  <td>{`${renderQuantity(l.quantity)} ${code}`}</td>
                  <td>{`${renderQuantity(l.quantity_received)} ${code}`}</td>
                  <td>{`${renderQuantity(l.quantity_outstanding)} ${code}`}</td>
                  <td>
                    <TextField
                      id={`receive-${l.id}-quantity`}
                      name="quantity"
                      label={RECEIVE.receivingNow}
                      aria-label={RECEIVE.receivingNowName(n)}
                      error={err.quantity}
                      value={entry.quantity}
                      onChange={(e) => type(l.id, 'quantity', e.target.value)}
                      inputMode="decimal"
                      autoComplete="off"
                      spellCheck={false}
                      // po-4b-ux-unit.md §2 (the R1-C field rule): the line's unit code shown, its plural read.
                      suffix={{ shown: code, spoken: unitSuffixSpoken(code) }}
                    />
                  </td>
                  <td>
                    <SelectField
                      id={`receive-${l.id}-location`}
                      name="storage_location_id"
                      label={RECEIVE.location}
                      accessibleName={RECEIVE.locationName(n)}
                      options={options}
                      // R10-5: a label is never an id. Every value is a listed id or the prompt's '': a choice a location re-read
                      // no longer lists is reset to the prompt (D-3), so `''` here is never shown for a chosen value.
                      labelOf={(id) => labels.get(id) ?? ''}
                      value={entry.location}
                      onChange={(v) => type(l.id, 'location', v)}
                      hints={[RECEIVE.locationHint(order.warehouse.name)]}
                      error={err.storage_location_id}
                      prompt={RECEIVE.locationPrompt}
                    />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
        <div className={styles.actions}>
          <Button ref={submitRef} type="submit" variant="primary" loading={busy}>
            {RECEIVE.submit}
          </Button>
        </div>
      </form>
      {confirming !== null ? (
        <Dialog
          open
          onOpenChange={(open) => {
            if (!open && !busyRef.current) setConfirming(null);
          }}
          title={RECEIVE.confirmTitle}
          description={<p>{RECEIVE.confirmBody(confirming.length, order.warehouse.name)}</p>}
          initialFocusRef={backRef}
          fallbackFocusRef={submitRef}
          busy={busy}
          refusal={null}
        >
          {busy ? null : (
            <Button ref={backRef} onClick={() => setConfirming(null)}>
              {RECEIVE.confirmDismiss}
            </Button>
          )}
          <Button variant="primary" loading={busy} onClick={() => void confirm()}>
            {RECEIVE.confirmConfirm}
          </Button>
        </Dialog>
      ) : null}
    </>
  );
}
