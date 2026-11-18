'use client';

import { useId, useLayoutEffect, useRef, useState, type ReactNode, type RefObject } from 'react';
import { flushSync } from 'react-dom';
import Link from 'next/link';
import { RegionError } from '@/components/states/PageStates';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { COULD_NOT_REACH, NOT_DELETED, NOT_RESTORED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { DELETE_LINE, ORDER_LINES, PURCHASE_ORDER_PAGE, PURCHASING_FORBIDDEN, RESTORE_LINE } from '@/copy/purchasing';
import { PAGE_STATES } from '@/copy/shell';
import type { PurchaseOrderLine } from '@/lib/api/purchasing';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { renderMoney } from '@/lib/format/money';
import { renderQuantity } from '@/lib/format/quantity';
import { loadDeletedLines, loadLines, type LinesLoad } from '@/lib/purchasing/load';
import type { DeleteLineAction, DeleteLineResult, RestoreLineAction, RestoreLineResult } from '@/lib/purchasing/results';
import { focusWasLost } from '../../ArchivableList';
import { useApiCaller } from '../../BffProvider';
import listStyles from '../../Lists.module.css';

export interface LinesSectionProps {
  orderId: string;
  /** The order's number (UX D-2's changed-since sentence after a refused Delete). */
  orderNumber: string;
  /**
   * The page's committed lines and their generation (ListPurchaseOrderLines on first render, then each commit's). PO-5: the
   * section is NOT remounted per commit (the new line row keeps its typed text across another row's Delete); a newer
   * generation replaces what is shown, and the section's own Retry answer is shown only while it belongs to the
   * generation it was pressed under.
   */
  lines: { gen: number; value: LinesLoad };
  /** The order's served status: it decides the columns and the empty sentence (IA L-5; UX B-4). */
  status: string;
  /** The order's currency: the Unit cost heading's and every cost cell's (`renderMoney`). */
  currency: string;
  /** Holds GetItem's codes (ST-4c): the item cell is a link to `/items/[id]` only then. */
  canOpenItem: boolean;
  /** The page's one region writer. */
  announce: (text: string) => void;
  /** The section's heading (the page's ref, so the new line row can fall back to it). */
  headingRef?: RefObject<HTMLHeadingElement | null>;
  /**
   * PO-5 Add line, or null: the new line row (the page builds `components/purchasing/AddLineRow`, given the table's column
   * count) and `emptyNote`, shown under `empty` — so the note renders exactly when Add renders (final RED L-5). Given only on
   * a live draft: the page owns that rule, the section does not re-check it.
   */
  add?: { row: (columns: number) => ReactNode; emptyNote: string } | null;
  /** PO-5 Delete line (X-4: a direct row action), or null. Given only on a live draft (the page's rule, not re-checked here). */
  remove?: DeleteLineAction | null;
  /** `gateCode('DeletePurchaseOrderLine')`, for the lost-permission sentence's label. */
  deleteCode?: string | null;
  /**
   * A refused Delete whose re-read found the order no longer an editable draft: the page shows the sentence under **Not
   * deleted** (the section's actions are gone by then).
   */
  onRefusedStale?: (message: string) => void;
  /**
   * One line write at a time across the section (po-5-red.md L-3): `begin()` is false while another Add or Delete is in
   * flight, and the press then sends nothing; `end()` after its answer. The rule's one home (two Deletes included): the page.
   */
  lineWrites: { begin: () => boolean; end: () => void };
  /**
   * PO-9: the Deleted lines subsection and its Restore line, or null. Given only on a live draft (the page's rule, not
   * re-checked here) to a RestorePurchaseOrderLine holder. `lines` are the committed deleted lines and their generation (a
   * newer one replaces what is shown, and the subsection's own Retry answer is shown only while it belongs to the generation
   * it was pressed under). `ownOutcomeAfter(sentAsOf)`: the person's own Delete purchase order, Cancel order or Approve
   * answered after the Restore was sent — its answer then says, shows and focuses nothing. `onRefusedStale`: a refused
   * Restore whose re-read found the order no longer an editable draft (the page's banner, **Not restored**).
   */
  deleted?: {
    lines: { gen: number; value: LinesLoad };
    restore: RestoreLineAction;
    restoreCode: string | null;
    ownOutcomeAfter: (sentAsOf: number) => boolean;
    onRefusedStale: (message: string) => void;
  } | null;
}

/** A refusal banner, shown only while the lines generation it was raised under is the one on screen (po-5-red.md L-1). */
interface Notice {
  gen: number;
  from: number;
  heading: string;
  body: ReactNode;
}

type Q = 'quantity' | 'ordered' | 'received' | 'rest';

/**
 * The order's lines (purchase-orders-plan.md §2.2 "Lines (read)", §4 PO-1; UX B-4 / R1-B `ORDER_LINES`; the S-3
 * catalogue's plain-section shape, suppliers ARCH (a)): one table over the served LIVE lines in served order (line
 * number), read-only at PO-1. Columns: a draft — Line · Item · Quantity · Unit cost ({currency}); an ordered, partly
 * received or received order — Line · Item · Ordered · Received · Still to come · Unit cost; a cancelled one — the same,
 * with *Never received* for the served remainder (D-3: relabelled, the same served number, never recomputed). Every
 * quantity heading carries the interim *"(item's unit)"* until BK-33 serves the line's unit (QR-8); quantities are QR-2
 * trimmed by `renderQuantity`, costs rendered by `renderMoney` in the order's currency. The item cell is
 * `{sku} — {name}`, linked only for a GetItem holder.
 *
 * States: a failed first read → the section's own region error (not focused on first paint) with Retry; a successful
 * Retry focuses the section heading if focus was lost and then speaks the count (or the draft's empty sentence) in the
 * page's region — the count is spoken only after a load in the browser, never on first paint (M-2). A 403 → the
 * PURCHASING_FORBIDDEN sentence with the view label, in place of the rows. A partial answer (a non-null `next_cursor`,
 * never served today) → `partial(n)` above the rows. A draft with no live line → `empty`; any other order with none (only
 * a draft cancelled through the API can have none) shows no rows and no sentence (UX writes none; owed).
 *
 * PO-5 (plan §2.3; Revision 1a X-4, X-17, per-row names; UX R1-B `DELETE_LINE`, `ORDER_LINES.emptyNote`): on a live draft
 * the columns gain *Actions* when Add line or Delete line is offered. Add line is the table's last row (`add.row`); an empty
 * draft with Add shows `empty`, then `emptyNote`, then the table holding only that row. **Delete** on each live row (its
 * accessible name *Delete line {n}*) sends at once, no dialog: the press empties the page's region; after `deleted` focus
 * goes to the section heading, then *"Line {n} ({sku}) was deleted. It can be restored from Deleted lines."* is spoken (the
 * restore clause from PO-9). A refusal shows under **Not deleted** above the table, focused (the stale ones on the page's
 * banner) and goes when a newer reading of the lines is shown. One line write at a time across the section (`lineWrites`): a
 * Delete press while an Add or another Delete is in flight does nothing.
 *
 * PO-9 (plan §4 PO-9 with Revision 1a's names; UX R1-B `ORDER_LINES.deletedLines*`, `RESTORE_LINE`; R1a-1 row 12): on a live
 * draft, for a RestorePurchaseOrderLine holder, the section ends with the **Deleted lines** subsection (an h3): its note, then
 * *"No deleted lines."* or the served deleted lines (`?archived=true`) in served order, each row struck, with its own
 * **Restore** (accessible name *Restore line {n} ({sku})*: two deleted lines can share a number, po-9-red.md M-3), no dialog.
 * A Restore is a line write (`lineWrites`): the press empties the page's region; after `restored` the re-read (order, live
 * lines and deleted lines, one commit) is on screen — the line is back in the table above — focus goes to the section heading
 * (while it is on the page), then *"Line {n} ({sku}) was restored and counts in the total again."* (`already-restored`:
 * *"Line {n} ({sku}) was already restored."*; a re-read that failed: `afterListStale`, M-2). Delete line's sentence names
 * Deleted lines only while it is shown (L-2). A refusal shows under **Not
 * restored** above the table, focused (L8's sentence as sent; the row and its Restore stay), the stale ones on the page's
 * banner. An answer to a Restore sent before the person's own Delete purchase order, Cancel order or Approve outcome says,
 * shows and focuses nothing (`ownOutcomeAfter`). A failed deleted-lines read is the subsection's own region error with Retry.
 */
export function LinesSection({ orderId, orderNumber, lines, status, currency, canOpenItem, announce, headingRef, add = null, remove = null, deleteCode = null, onRefusedStale, lineWrites, deleted = null }: LinesSectionProps) {
  const caller = useApiCaller();
  const headingId = useId();
  const ownHeading = useRef<HTMLHeadingElement>(null);
  const heading = headingRef ?? ownHeading;
  // The section's own Retry answer, kept only while it belongs to the page's generation it was pressed under (derived: a
  // newer commit from the page simply wins, and a Retry answer arriving after it is not shown).
  const [retried, setRetried] = useState<{ from: number; gen: number; value: LinesLoad } | null>(null);
  const [retrying, setRetrying] = useState(false);
  const [deleting, setDeleting] = useState<string | null>(null);
  // PO-9: the line whose Restore is in flight, and the Deleted lines' own Retry answer (kept only while it belongs to the
  // committed deleted-lines generation it was pressed under, as the live lines' Retry is).
  const [restoring, setRestoring] = useState<string | null>(null);
  const [deletedRetried, setDeletedRetried] = useState<{ from: number; gen: number; value: LinesLoad } | null>(null);
  const [retryingDeleted, setRetryingDeleted] = useState(false);
  const deletedHeading = useRef<HTMLHeadingElement>(null);
  const deletedHeadingId = useId();
  // po-9-red.md L-2: whether Deleted lines is on screen, read after an awaited Delete (its own re-read may have changed it):
  // only then does the after-sentence name it.
  const deletedShown = useRef(deleted !== null);
  useLayoutEffect(() => {
    deletedShown.current = deleted !== null;
  }, [deleted]);
  const [notice, setNotice] = useState<Notice | null>(null);
  const noticeRef = useRef<HTMLHeadingElement>(null);
  const noticeGen = useRef(0);
  // The lines generation on screen, read after an awaited write (the write's own re-read may have committed a newer one).
  const shownGen = useRef(lines.gen);
  useLayoutEffect(() => {
    shownGen.current = lines.gen;
  }, [lines.gen]);
  const ownRetry = retried !== null && retried.from === lines.gen ? retried : null;
  const draft = status === 'draft';
  const rest = status === 'cancelled' ? ORDER_LINES.neverReceived : ORDER_LINES.stillToCome;
  const quantities: { key: Q; header: string }[] = draft
    ? [{ key: 'quantity', header: ORDER_LINES.quantity }]
    : [
        { key: 'ordered', header: ORDER_LINES.ordered },
        { key: 'received', header: ORDER_LINES.received },
        { key: 'rest', header: rest },
      ];
  const quantityOf = (l: PurchaseOrderLine, k: Q) => renderQuantity(k === 'received' ? l.quantity_received : k === 'rest' ? l.quantity_outstanding : l.quantity);
  // The page decides where Add and Delete are offered (a live draft only); this section never re-checks it, so the one rule
  // has one home (a second copy here masked the page's: mutation M29 stayed green with both).
  const actions = add !== null || remove !== null;
  const columns = 2 + quantities.length + 1 + (actions ? 1 : 0);

  // After the commit that renders the refusal (focus never runs ahead of what it lands on).
  const liveNotice = notice !== null && notice.from === lines.gen ? notice : null;
  const shownNotice = liveNotice?.gen ?? null;
  useLayoutEffect(() => {
    if (shownNotice !== null) noticeRef.current?.focus();
  }, [shownNotice]);
  const show = (h: string, body: ReactNode) => {
    noticeGen.current += 1;
    setNotice({ gen: noticeGen.current, from: shownGen.current, heading: h, body });
  };

  const retry = async () => {
    if (retrying) return;
    announce('');
    setRetrying(true);
    const from = lines.gen;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadLines(readOf(caller), orderId, d.signal);
    d.cancel();
    setRetrying(false);
    if (r.kind === 'session-ended') return;
    flushSync(() => {
      setRetried((cur) => ({ from, gen: (cur?.gen ?? 0) + 1, value: r }));
      // po-5-red-2.md L-4: an ok Retry is a newer reading of the lines, so a refusal raised before it describes nothing shown.
      if (r.kind === 'ok') setNotice(null);
    });
    // FE-D1: focus first (the Retry button is gone from under it, whatever the answer — po-1-red.md L-6), then speak. A
    // failure's own region error takes focus itself (autoFocus); a 403 announces nothing new.
    if (focusWasLost()) heading.current?.focus();
    if (r.kind !== 'ok') return;
    announce(r.lines.length === 0 && draft ? ORDER_LINES.empty : ORDER_LINES.count(r.lines.length));
  };

  const settleDelete = (r: DeleteLineResult, l: PurchaseOrderLine) => {
    switch (r.kind) {
      case 'deleted':
        // FE-D1: commit (the re-read is on screen already), focus the heading, then speak.
        flushSync(() => setNotice(null));
        heading.current?.focus();
        announce(DELETE_LINE.after(l.line_number, l.inventory_item.sku, deletedShown.current));
        return;
      case 'refused-archived':
        setNotice(null);
        onRefusedStale?.(r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'no-longer-draft':
        setNotice(null);
        onRefusedStale?.(PURCHASE_ORDER_PAGE.noLongerDraft(r.order.order_number, r.order.status));
        return;
      case 'changed-since-loaded':
        show(NOT_DELETED, PURCHASE_ORDER_PAGE.changedSinceLoaded(orderNumber));
        return;
      case 'contention':
      case 'refused':
        show(NOT_DELETED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        const label = (deleteCode !== null ? PERMISSION_LABELS[deleteCode]?.label : undefined) ?? '';
        show(
          NOT_DELETED,
          <p>
            {PURCHASING_FORBIDDEN.before}
            <em>{label}</em>
            {PURCHASING_FORBIDDEN.after}
          </p>,
        );
        return;
      }
      case 'not-found':
        show(NOT_DELETED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, DELETE_LINE.interrupted(l.line_number));
        return;
      case 'server-error':
        show(WENT_WRONG, DELETE_LINE.interrupted(l.line_number));
        return;
      case 'session-ended':
        return;
    }
  };

  const onDelete = async (l: PurchaseOrderLine) => {
    if (remove === null || !lineWrites.begin()) return;
    // 30d2edb: the press empties the page's region, so a second identical sentence is a change and is given again.
    announce('');
    setDeleting(l.id);
    let r: DeleteLineResult;
    try {
      r = await remove(l);
    } catch {
      r = { kind: 'unreachable' };
    }
    setDeleting(null);
    lineWrites.end();
    settleDelete(r, l);
  };

  // PO-9: the Deleted lines' own Retry (`loadDeletedLines`, `?archived=true`). FE-D1: commit, then focus the subsection's
  // heading when the Retry button took focus with it; nothing is spoken (no copy names a deleted-lines count; UX TO CONFIRM).
  const retryDeleted = async () => {
    if (deleted === null || retryingDeleted) return;
    setRetryingDeleted(true);
    const from = deleted.lines.gen;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadDeletedLines(readOf(caller), orderId, d.signal);
    d.cancel();
    setRetryingDeleted(false);
    if (r.kind === 'session-ended') return;
    flushSync(() => setDeletedRetried((cur) => ({ from, gen: (cur?.gen ?? 0) + 1, value: r })));
    if (focusWasLost()) deletedHeading.current?.focus();
  };

  const settleRestore = (r: RestoreLineResult, l: PurchaseOrderLine) => {
    switch (r.kind) {
      case 'restored':
      case 'already-restored': {
        // FE-D1: commit (the re-read is on screen already; the row's Restore went with it), focus the Lines heading — only
        // while it is on the page (a swap removes it) — then speak.
        flushSync(() => setNotice(null));
        const h = heading.current;
        if (h !== null && h.isConnected) h.focus();
        const sku = l.inventory_item.sku;
        announce(r.kind === 'already-restored' ? RESTORE_LINE.alreadyRestored(l.line_number, sku) : r.listStale ? RESTORE_LINE.afterListStale(l.line_number, sku) : RESTORE_LINE.after(l.line_number, sku));
        return;
      }
      case 'refused-archived':
        setNotice(null);
        deleted?.onRefusedStale(r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'no-longer-draft':
        setNotice(null);
        deleted?.onRefusedStale(PURCHASE_ORDER_PAGE.noLongerDraft(r.order.order_number, r.order.status));
        return;
      case 'changed-since-loaded':
        show(NOT_RESTORED, PURCHASE_ORDER_PAGE.changedSinceLoaded(orderNumber));
        return;
      case 'contention':
      case 'refused':
        show(NOT_RESTORED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        const code = deleted?.restoreCode ?? null;
        const label = (code !== null ? PERMISSION_LABELS[code]?.label : undefined) ?? '';
        show(
          NOT_RESTORED,
          <p>
            {PURCHASING_FORBIDDEN.before}
            <em>{label}</em>
            {PURCHASING_FORBIDDEN.after}
          </p>,
        );
        return;
      }
      case 'not-found':
        show(NOT_RESTORED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, RESTORE_LINE.interrupted(l.line_number, l.inventory_item.sku));
        return;
      case 'server-error':
        show(WENT_WRONG, RESTORE_LINE.interrupted(l.line_number, l.inventory_item.sku));
        return;
      case 'session-ended':
        return;
    }
  };

  // PO-9: Restore line, a row button with no dialog. It is one of the section's line writes (`lineWrites`, the PO-5 rule's one
  // home): while an Add, a Delete or another Restore is in flight the press sends nothing, and the page opens no Approve or
  // Delete purchase order meanwhile. The press empties the page's region (30d2edb).
  const onRestore = async (l: PurchaseOrderLine) => {
    if (deleted === null || !lineWrites.begin()) return;
    const own = deleted;
    announce('');
    setRestoring(l.id);
    let out: { result: RestoreLineResult; sentAsOf: number };
    try {
      out = await own.restore(l);
    } catch {
      // A throw is the request failing now, after every outcome already on the page: it is said.
      out = { result: { kind: 'unreachable' }, sentAsOf: Number.MAX_SAFE_INTEGER };
    }
    setRestoring(null);
    lineWrites.end();
    // The page's own-outcome floors: an answer to a Restore sent before the person's own Delete purchase order, Cancel order
    // or Approve says, shows and focuses nothing.
    if (own.ownOutcomeAfter(out.sentAsOf)) return;
    settleRestore(out.result, l);
  };

  const v = ownRetry !== null ? ownRetry.value : lines.value;
  const viewCode = gateCode('ListPurchaseOrderLines');

  const ok = v.kind === 'ok' ? v : null;
  // PO-9 (UX R1-B `ORDER_LINES.deletedLines*`, Rev 0 B-4 "h3, draft"): the served deleted lines in served order, each row
  // struck, with its own **Restore** (accessible name *Restore line {n}*). A failed read → its own region error with Retry.
  const deletedGen = deleted?.lines.gen ?? null;
  const ownDeletedRetry = deleted !== null && deletedRetried !== null && deletedRetried.from === deletedGen ? deletedRetried : null;
  const dv = deleted === null ? null : ownDeletedRetry !== null ? ownDeletedRetry.value : deleted.lines.value;
  // po-9-red.md M-1 (a), N-1, N-2 — the one home of the rule: a line the live table shows is never drawn under Deleted lines,
  // whichever reading the deleted list came from (a commit that read none, a reading whose two GETs another tab split, or this
  // subsection's own Retry). A live list that failed to load hides nothing.
  const liveIds = new Set(ok?.lines.map((l) => l.id) ?? []);
  const deletedRows = dv !== null && dv.kind === 'ok' ? dv.lines.filter((l) => !liveIds.has(l.id)) : [];
  return (
    <section aria-labelledby={headingId}>
      <h2 id={headingId} ref={heading} tabIndex={-1}>
        {ORDER_LINES.heading}
      </h2>
      {liveNotice ? (
        <Banner key={liveNotice.gen} tone="danger" heading={liveNotice.heading} ref={noticeRef}>
          {typeof liveNotice.body === 'string' ? <p>{liveNotice.body}</p> : liveNotice.body}
        </Banner>
      ) : null}
      {v.kind === 'forbidden' ? (
        <p>
          {PURCHASING_FORBIDDEN.before}
          {(viewCode !== null ? PERMISSION_LABELS[viewCode]?.label : undefined) ?? ''}
          {PURCHASING_FORBIDDEN.after}
        </p>
      ) : ok === null ? (
        <RegionError key={`lines-${lines.gen}-${ownRetry?.gen ?? 0}`} heading={v.kind === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={ORDER_LINES.loadFailed} retrying={retrying} onRetry={() => void retry()} autoFocus={ownRetry !== null} />
      ) : (
        <>
          {ok.lines.length === 0 && draft ? <p>{ORDER_LINES.empty}</p> : null}
          {ok.lines.length === 0 && add !== null ? <p>{add.emptyNote}</p> : null}
          {ok.lines.length > 0 && ok.nextCursor !== null ? <p>{ORDER_LINES.partial(ok.lines.length)}</p> : null}
          {ok.lines.length > 0 || add !== null ? (
            <table className={listStyles.table}>
              <thead>
                <tr>
                  <th scope="col">{ORDER_LINES.line}</th>
                  <th scope="col">{ORDER_LINES.item}</th>
                  {quantities.map((q) => (
                    <th key={q.key} scope="col">
                      {q.header}
                    </th>
                  ))}
                  <th scope="col">{ORDER_LINES.unitCost(currency)}</th>
                  {actions ? <th scope="col">{ORDER_LINES.actions}</th> : null}
                </tr>
              </thead>
              <tbody>
                {ok.lines.map((l) => {
                  const item = ORDER_LINES.itemCell(l.inventory_item.sku, l.inventory_item.name);
                  return (
                    <tr key={l.id}>
                      <td>{l.line_number}</td>
                      <td>{canOpenItem ? <Link href={`/items/${l.inventory_item.id}`}>{item}</Link> : item}</td>
                      {quantities.map((q) => (
                        <td key={q.key}>{quantityOf(l, q.key)}</td>
                      ))}
                      <td>{renderMoney(l.unit_cost, currency)}</td>
                      {actions ? (
                        <td>
                          {remove !== null ? (
                            <Button size="sm" variant="destructive" aria-label={DELETE_LINE.deleteName(l.line_number)} loading={deleting === l.id} onClick={() => void onDelete(l)}>
                              {DELETE_LINE.action}
                            </Button>
                          ) : null}
                        </td>
                      ) : null}
                    </tr>
                  );
                })}
                {add !== null ? add.row(columns) : null}
              </tbody>
            </table>
          ) : null}
          {draft && ok.lines.length > 0 ? <p className={listStyles.muted}>{ORDER_LINES.totalNote}</p> : null}
        </>
      )}
      {dv !== null ? (
        <div>
          <h3 ref={deletedHeading} id={deletedHeadingId} tabIndex={-1}>
            {ORDER_LINES.deletedLines}
          </h3>
          <p className={listStyles.muted}>{ORDER_LINES.deletedLinesNote}</p>
          {dv.kind !== 'ok' ? (
            <RegionError key={`deleted-${deletedGen}-${ownDeletedRetry?.gen ?? 0}`} heading={dv.kind === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={ORDER_LINES.deletedLinesLoadFailed} retrying={retryingDeleted} onRetry={() => void retryDeleted()} autoFocus={ownDeletedRetry !== null} />
          ) : deletedRows.length === 0 ? (
            <p>{ORDER_LINES.deletedLinesEmpty}</p>
          ) : (
            <table className={listStyles.table} aria-labelledby={deletedHeadingId}>
              <thead>
                <tr>
                  <th scope="col">{ORDER_LINES.line}</th>
                  <th scope="col">{ORDER_LINES.item}</th>
                  <th scope="col">{ORDER_LINES.quantity}</th>
                  <th scope="col">{ORDER_LINES.unitCost(currency)}</th>
                  <th scope="col">{ORDER_LINES.actions}</th>
                </tr>
              </thead>
              <tbody>
                {deletedRows.map((l) => {
                  const item = ORDER_LINES.itemCell(l.inventory_item.sku, l.inventory_item.name);
                  return (
                    <tr key={l.id} className={listStyles.struck}>
                      <td>{l.line_number}</td>
                      <td>{canOpenItem ? <Link href={`/items/${l.inventory_item.id}`}>{item}</Link> : item}</td>
                      <td>{renderQuantity(l.quantity)}</td>
                      <td>{renderMoney(l.unit_cost, currency)}</td>
                      <td>
                        <Button size="sm" aria-label={RESTORE_LINE.name(l.line_number, l.inventory_item.sku)} loading={restoring === l.id} onClick={() => void onRestore(l)}>
                          {RESTORE_LINE.action}
                        </Button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </div>
      ) : null}
    </section>
  );
}
