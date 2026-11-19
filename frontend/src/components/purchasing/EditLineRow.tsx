'use client';

import { useId, useLayoutEffect, useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { flushSync } from 'react-dom';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, MIXED_FIELDS_TOO, NOT_SAVED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { unitPerSpoken, unitSuffixSpoken } from '@/copy/units';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { ADD_LINE, EDIT_LINE, PURCHASE_ORDER_PAGE, PURCHASING_FORBIDDEN } from '@/copy/purchasing';
import { PAGE_STATES } from '@/copy/shell';
import type { PurchaseOrderLine } from '@/lib/api/purchasing';
import { renderQuantity } from '@/lib/format/quantity';
import { marksAnyField } from '@/lib/forms/fieldErrors';
import { EDIT_LINE_FIELDS, type EditLineField, type LineChange, type SaveLineAction, type SaveLineResult } from '@/lib/purchasing/results';
import type { FieldErrors } from '@/lib/results';

export interface EditLineRowProps {
  /** The line as the committed lines reading serves it (a live line of a live draft). */
  line: PurchaseOrderLine;
  /** The stamp of the committed lines reading: the row's own Save answer stands over `line` only while it is newer. */
  linesAsOf: number;
  /** The lines generation on screen: the row's refusal goes when a newer reading of the lines is shown (po-5-red.md L-1). */
  linesGen: number;
  /** The order's number (UX D-2's changed-since sentence) and currency (the Unit cost label's). */
  orderNumber: string;
  currency: string;
  /** The Item cell's content (the section builds it: a link for a GetItem holder). */
  item: ReactNode;
  save: SaveLineAction;
  /** `gateCode('UpdatePurchaseOrderLine')`, for the lost-permission sentence's label. */
  editCode: string | null;
  /** The section's Delete for this row, or null: shown in the Actions cell, OUTSIDE the row's form. */
  deleteButton: ReactNode;
  /** The table's column count: the row's sentence row spans it. */
  columns: number;
  /** Writes the page's status region (`saved` / `savedListStale`, once per press, after the re-read). */
  announce: (text: string) => void;
  /** The lines section's heading: focus goes there when the row's Save is no longer on the page after an answer. */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /** A refusal whose re-read found the order no longer an editable draft (the page's banner, **Not saved**). */
  onRefusedStale: (message: string, sentAsOf: number) => void;
  /** The line is no longer live (deleted elsewhere, or gone): the row has left, so the section says it, under **Not saved**. */
  onGone: (message: string) => void;
  /** The person's own Delete purchase order, Cancel order or Approve answered after this Save was sent: say, show and focus nothing. */
  ownOutcomeAfter: (sentAsOf: number) => boolean;
  /** One line write at a time across the page (po-5-red-2.md D-18: the one home of the rule). */
  lineWrites: { begin: () => boolean; end: () => void };
  /**
   * po-4b-red.md M-1: the row's typed text, held by the section keyed by line id (so a failed lines reading, which unmounts
   * every row, never drops it), and its updater. `{}` = nothing typed: each box shows the newest served value.
   */
  typed: LineChange;
  setTyped: (update: (cur: LineChange) => LineChange) => void;
  /**
   * po-4b-red.md H-1 (PO-8 M-1 (b)'s rule extended to the lines): whether this row holds a change not yet saved — its boxes
   * against the served values it shows (never "touched") — and no Save of it is in flight; `false` on unmount. Reported from a
   * layout effect (never a passive one). The page puts Approve's reason on it.
   */
  onDirtyChange: (lineId: string, dirty: boolean) => void;
}

interface Notice {
  gen: number;
  from: number;
  heading: string;
  body: ReactNode;
}
type Target = 'banner' | EditLineField;

/**
 * A live line of a live draft, edited in its row (purchase-orders-plan.md Revision 1 R1-5 PO-4b over Revision 0 §2.3 / §4
 * PO-4's line half, D-9; Revision 1a per-row names; final RED M-6, L-11; UX R1a-1 row 10 `EDIT_LINE`; po-4b-ux-unit.md;
 * b4-architecture.md R10-6), props-only. Cells: Line · Item · **Quantity** (the served quantity QR-2 trimmed; the line's unit
 * code beside it, read as its plural) · **Unit** (the line's served code) · **Unit cost ({currency})** (the served cost
 * untrimmed; `per {code}` beside it, read "per kilogram." / "each.") · Actions (**Save line**, named *Save line {n}*, in the
 * row's `<form method="post">`; the section's Delete beside the form). The row is a `form`-attribute form: the two boxes join
 * the form in the last cell, so Enter in either sends this row and no other.
 *
 * Each box shows what was typed in it, or — untouched — the newest served value: the row's own Save answer (its 200 body)
 * while it is newer than the lines reading on screen, else that reading's line (M-6: the typed text is held by the section,
 * keyed by line id — po-4b-red.md M-1 — so it survives every re-read, a failed one included; a row an OK re-read removed drops
 * its text; a new row starts at its served values). While a box differs from its served value and no Save of the row is in
 * flight, the row tells the page (`onDirtyChange`), which makes Approve unavailable with a reason (po-4b-red.md H-1). Save line is
 * `aria-disabled` with *Nothing to save.* while neither box differs from it; the body holds only the changed members, as
 * typed; an emptied box gets Add's sentence and nothing is sent. One line write at a time (the page's `lineWrites`, D-18's one
 * home): a press while another line write is in flight sends nothing. The press empties the page's region (30d2edb).
 *
 * After `saved`: the boxes that still hold what was sent show the served values (text typed after the press stays), focus
 * stays on Save line (else the section heading), then the region says *"Line {n} was saved."* — or, when the header re-read
 * failed, `savedListStale`. A 422's sentences go beside their boxes (the first focused). P1, any other refusal as sent,
 * UX D-2's changed-since sentence and every failure show under **Not saved** in a row under this one, focused, the typed text
 * kept, until a newer reading of the lines is shown. A refusal that found the order no longer an editable draft goes to the
 * page; a line no longer live goes to the section (`onGone`). An answer to a Save sent before the person's own outcome says,
 * shows and focuses nothing. A 401 shows nothing.
 */
export function EditLineRow({ line, linesAsOf, linesGen, orderNumber, currency, item, save, editCode, deleteButton, columns, announce, fallbackFocusRef, onRefusedStale, onGone, ownOutcomeAfter, lineWrites, typed, setTyped, onDirtyChange }: EditLineRowProps) {
  const base = useId();
  const formId = `${base}-form`;
  const [saved, setSaved] = useState<{ line: PurchaseOrderLine; asOf: number } | null>(null);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<EditLineField>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const [focusTarget, setFocusTarget] = useState<{ gen: number; to: Target } | null>(null);
  const gen = useRef(0);
  const bannerRef = useRef<HTMLHeadingElement>(null);
  const quantityRef = useRef<HTMLInputElement>(null);
  const costRef = useRef<HTMLInputElement>(null);
  const saveRef = useRef<HTMLButtonElement>(null);
  // The lines generation on screen, read after an awaited Save (its own re-read may have committed a newer one).
  const shownGen = useRef(linesGen);
  useLayoutEffect(() => {
    shownGen.current = linesGen;
  }, [linesGen]);

  // After the commit that renders the sentence or banner (focus never runs ahead of what it lands on).
  useLayoutEffect(() => {
    if (focusTarget === null) return;
    const to = focusTarget.to;
    (to === 'banner' ? bannerRef : to === 'quantity' ? quantityRef : costRef).current?.focus();
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

  // The served line the row shows: its own Save's answer while that is newer than the committed lines reading.
  const current = saved !== null && saved.asOf > linesAsOf ? saved.line : line;
  const served: Record<EditLineField, string> = { quantity: renderQuantity(current.quantity), unit_cost: current.unit_cost };
  const value = (f: EditLineField) => typed[f] ?? served[f];
  const changes: LineChange = {};
  for (const f of EDIT_LINE_FIELDS) if (value(f) !== served[f]) changes[f] = value(f);
  const nothing = Object.keys(changes).length === 0;
  const code = current.unit_of_measure_code;
  const n = current.line_number;
  // H-1: the unsaved-change fact the page's Approve reads, after the commit that shows it (silent while its Save is in flight:
  // the page's one-write-at-a-time block refuses the press then, PO-8's rule).
  const dirty = !nothing && !busy;
  const lineId = line.id;
  useLayoutEffect(() => {
    onDirtyChange(lineId, dirty);
  }, [lineId, dirty, onDirtyChange]);
  useLayoutEffect(() => () => onDirtyChange(lineId, false), [lineId, onDirtyChange]);

  const type = (f: EditLineField, v: string) => {
    setTyped((cur) => ({ ...cur, [f]: v }));
    setFieldErrors((cur) => {
      const next = { ...cur };
      delete next[f];
      return next;
    });
  };

  const settle = (r: SaveLineResult, sent: LineChange) => {
    switch (r.kind) {
      case 'saved': {
        // FE-D1: commit (the served values replace what was sent; text typed after the press stays), focus, then speak.
        flushSync(() => {
          setSaved({ line: r.line, asOf: r.asOf });
          setTyped((cur) => {
            const next = { ...cur };
            for (const f of EDIT_LINE_FIELDS) if (sent[f] !== undefined && next[f] === sent[f]) delete next[f];
            return next;
          });
          setFieldErrors({});
          setNotice(null);
        });
        const target = saveRef.current;
        (target !== null && target.isConnected ? target : fallbackFocusRef.current)?.focus();
        announce(r.orderStale ? EDIT_LINE.savedListStale(r.line.line_number) : EDIT_LINE.saved(r.line.line_number));
        return;
      }
      case 'invalid': {
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
        const first = EDIT_LINE_FIELDS.find((f) => r.fieldErrors[f] !== undefined);
        if (first) focusTo(first);
        return;
      }
      case 'gone':
        onGone(r.message);
        return;
      case 'refused-archived':
      case 'no-longer-draft':
        return; // handled by the caller with the sentAsOf
      case 'changed-since-loaded':
        show(NOT_SAVED, PURCHASE_ORDER_PAGE.changedSinceLoaded(orderNumber));
        return;
      case 'contention':
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
        show(COULD_NOT_REACH, EDIT_LINE.interrupted(n));
        return;
      case 'server-error':
        show(WENT_WRONG, EDIT_LINE.interrupted(n));
        return;
      case 'session-ended':
        return;
    }
  };

  const onSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (nothing) return;
    // Client refusals (Add's sentences; no request): an emptied box.
    const missing: FieldErrors<EditLineField> = {};
    if (changes.quantity === '') missing.quantity = ADD_LINE.quantityRequired;
    if (changes.unit_cost === '') missing.unit_cost = ADD_LINE.costRequired;
    const first = EDIT_LINE_FIELDS.find((f) => missing[f] !== undefined);
    if (first !== undefined) {
      setNotice(null);
      setFieldErrors(missing);
      focusTo(first);
      return;
    }
    // D-18: the one home of one-line-write-at-a-time (a synchronous ref, no commit window).
    if (!lineWrites.begin()) return;
    // 30d2edb: the press empties the page's region, so a second identical sentence is a change and is given again.
    announce('');
    setBusy(true);
    const sent = changes;
    let out: { result: SaveLineResult; sentAsOf: number };
    try {
      out = await save(current, sent);
    } catch {
      // A throw is the request failing now, after every outcome already on the page: it is said.
      out = { result: { kind: 'unreachable' }, sentAsOf: Number.MAX_SAFE_INTEGER };
    }
    setBusy(false);
    lineWrites.end();
    // The page's own-outcome floors: an answer to a Save sent before the person's own outcome says, shows and focuses nothing.
    if (ownOutcomeAfter(out.sentAsOf)) return;
    const r = out.result;
    if (r.kind === 'refused-archived') onRefusedStale(r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE, out.sentAsOf);
    else if (r.kind === 'no-longer-draft') onRefusedStale(PURCHASE_ORDER_PAGE.noLongerDraft(r.order.order_number, r.order.status), out.sentAsOf);
    else settle(r, sent);
  };

  return (
    <>
      <tr>
        <td>{n}</td>
        <td>{item}</td>
        <td>
          <TextField
            ref={quantityRef}
            id={`${base}-quantity`}
            name="quantity"
            label={ADD_LINE.quantity}
            aria-label={EDIT_LINE.quantityName(n)}
            form={formId}
            hint={ADD_LINE.quantityHint}
            error={fieldErrors.quantity}
            value={value('quantity')}
            onChange={(e) => type('quantity', e.target.value)}
            inputMode="decimal"
            autoComplete="off"
            spellCheck={false}
            // po-4b-ux-unit.md §2 (the R1-C field rule): the line's unit code shown, its plural read.
            suffix={{ shown: code, spoken: unitSuffixSpoken(code) }}
          />
        </td>
        <td>{code}</td>
        <td>
          <TextField
            ref={costRef}
            id={`${base}-unit-cost`}
            name="unit_cost"
            label={ADD_LINE.unitCost(currency)}
            aria-label={EDIT_LINE.unitCostName(n)}
            form={formId}
            hint={ADD_LINE.unitCostHint(false)}
            error={fieldErrors.unit_cost}
            value={value('unit_cost')}
            onChange={(e) => type('unit_cost', e.target.value)}
            inputMode="decimal"
            autoComplete="off"
            spellCheck={false}
            // R1-D, per-unit branch: the price of ONE of the line's unit — shown `per {code}`, read "per kilogram." / "each.".
            suffix={{ shown: EDIT_LINE.perUnit(code), spoken: `${unitPerSpoken(code)}.` }}
          />
        </td>
        <td>
          <form id={formId} method="post" noValidate onSubmit={(e) => void onSubmit(e)}>
            <Button ref={saveRef} type="submit" size="sm" variant="primary" aria-label={EDIT_LINE.saveName(n)} loading={busy} unavailableReason={nothing && !busy ? PURCHASE_ORDER_PAGE.saveNothing : undefined}>
              {EDIT_LINE.save}
            </Button>
          </form>
          {deleteButton}
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
