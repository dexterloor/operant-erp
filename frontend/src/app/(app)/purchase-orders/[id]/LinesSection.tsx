'use client';

import { useId, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import Link from 'next/link';
import { RegionError } from '@/components/states/PageStates';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { ORDER_LINES, PURCHASING_FORBIDDEN } from '@/copy/purchasing';
import type { PurchaseOrderLine } from '@/lib/api/purchasing';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { renderMoney } from '@/lib/format/money';
import { renderQuantity } from '@/lib/format/quantity';
import { loadLines, type LinesLoad } from '@/lib/purchasing/load';
import { focusWasLost } from '../../ArchivableList';
import { useApiCaller } from '../../BffProvider';
import listStyles from '../../Lists.module.css';

export interface LinesSectionProps {
  orderId: string;
  /** ListPurchaseOrderLines on first render (server). */
  initial: LinesLoad;
  /** The order's served status: it decides the columns and the empty sentence (IA L-5; UX B-4). */
  status: string;
  /** The order's currency: the Unit cost heading's and every cost cell's (`renderMoney`). */
  currency: string;
  /** Holds GetItem's codes (ST-4c): the item cell is a link to `/items/[id]` only then. */
  canOpenItem: boolean;
  /** The page's one region writer. */
  announce: (text: string) => void;
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
 */
export function LinesSection({ orderId, initial, status, currency, canOpenItem, announce }: LinesSectionProps) {
  const caller = useApiCaller();
  const headingId = useId();
  const heading = useRef<HTMLHeadingElement>(null);
  const [load, setLoad] = useState<{ gen: number; value: LinesLoad }>({ gen: 0, value: initial });
  const [retrying, setRetrying] = useState(false);
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

  const retry = async () => {
    if (retrying) return;
    announce('');
    setRetrying(true);
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadLines(readOf(caller), orderId, d.signal);
    d.cancel();
    setRetrying(false);
    if (r.kind === 'session-ended') return;
    flushSync(() => setLoad((cur) => ({ gen: cur.gen + 1, value: r })));
    // FE-D1: focus first (the Retry button is gone from under it, whatever the answer — po-1-red.md L-6), then speak. A
    // failure's own region error takes focus itself (autoFocus); a 403 announces nothing new.
    if (focusWasLost()) heading.current?.focus();
    if (r.kind !== 'ok') return;
    announce(r.lines.length === 0 && draft ? ORDER_LINES.empty : ORDER_LINES.count(r.lines.length));
  };

  const v = load.value;
  const viewCode = gateCode('ListPurchaseOrderLines');
  return (
    <section aria-labelledby={headingId}>
      <h2 id={headingId} ref={heading} tabIndex={-1}>
        {ORDER_LINES.heading}
      </h2>
      {v.kind === 'forbidden' ? (
        <p>
          {PURCHASING_FORBIDDEN.before}
          {(viewCode !== null ? PERMISSION_LABELS[viewCode]?.label : undefined) ?? ''}
          {PURCHASING_FORBIDDEN.after}
        </p>
      ) : v.kind !== 'ok' ? (
        <RegionError key={`lines-${load.gen}`} heading={v.kind === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={ORDER_LINES.loadFailed} retrying={retrying} onRetry={() => void retry()} autoFocus={load.gen > 0} />
      ) : v.lines.length === 0 ? (
        draft ? <p>{ORDER_LINES.empty}</p> : null
      ) : (
        <>
          {v.nextCursor !== null ? <p>{ORDER_LINES.partial(v.lines.length)}</p> : null}
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
              </tr>
            </thead>
            <tbody>
              {v.lines.map((l) => {
                const item = ORDER_LINES.itemCell(l.inventory_item.sku, l.inventory_item.name);
                return (
                  <tr key={l.id}>
                    <td>{l.line_number}</td>
                    <td>{canOpenItem ? <Link href={`/items/${l.inventory_item.id}`}>{item}</Link> : item}</td>
                    {quantities.map((q) => (
                      <td key={q.key}>{quantityOf(l, q.key)}</td>
                    ))}
                    <td>{renderMoney(l.unit_cost, currency)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          {draft ? <p className={listStyles.muted}>{ORDER_LINES.totalNote}</p> : null}
        </>
      )}
    </section>
  );
}
