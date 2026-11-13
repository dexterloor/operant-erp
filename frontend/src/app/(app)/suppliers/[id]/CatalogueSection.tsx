'use client';

import { useId, useLayoutEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { RegionError } from '@/components/states/PageStates';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { CATALOGUE } from '@/copy/suppliers';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf } from '@/lib/bff/reads';
import { renderMoney } from '@/lib/format/money';
import { renderQuantity } from '@/lib/format/quantity';
import { loadCatalogue, type CatalogueLoad } from '@/lib/suppliers/load';
import { focusWasLost } from '../../ArchivableList';
import { useApiCaller } from '../../BffProvider';
import listStyles from '../../Lists.module.css';

export interface CatalogueSectionProps {
  supplierId: string;
  /** `ListSupplierProducts` on first render, over the server transport (the page reads it only for its holder, A-20). */
  initial: CatalogueLoad;
  /** The page's CURRENT supplier is archived (never the first render's value): a Restore removes the note, same mount. */
  supplierArchived: boolean;
  /** Holds GetItem's codes (from the contract): the item cell is a link to its page only then. */
  canOpenItem: boolean;
  /** The page's one status region (the section has none of its own). */
  announce: (text: string) => void;
  /** Empties that region (FE-R9: a press that may write to it empties it first, after its guards). */
  clearRegion: () => void;
}

/**
 * The supplier's catalogue (suppliers-plan.md Revision 1 — FINAL §R1-4 S-3 over Revision 0 §2.3; suppliers-ux-copy.md
 * Revision 1 — FINAL S-3 `CATALOGUE`; suppliers-final-red.md M-1, M-2), read-only for everyone in S-3: no Actions column,
 * no Add, no Remove. A plain section (ARCH (a): no paged list core, no view control, no sort): one table over the served
 * entries in served order (supplier SKU), the whole catalogue in one answer (G-42). Unit cost through `renderMoney` (the
 * m2 interim); Minimum order QR-2 trimmed with no unit (served (BK-31) but not shown until S-4 (M-8 interim); the column header says which unit).
 *
 * States: an initial 403 → `denied`; a failed first read → the section's own region error (not focused on first paint,
 * ST-2a) with Retry; a partial answer (`next_cursor` non-null) → `partial(n)` above the rows, which stay; an empty answer →
 * `empty`; an archived supplier → the S-3 `archivedParent` note, following the page's current supplier. The count is
 * spoken through the page's region ONLY when the first successful load happens in the browser (a Retry after a failed
 * first load); never on first paint, never on a re-read (M-2). The count is written after the focus move (FE-D1): after
 * a successful Retry the section's heading takes focus only when focus was lost (the Retry button is gone from under it;
 * the Locations twin), and the count is written in the same layout effect, after that. When the Retry brings an empty
 * catalogue, the sentence spoken is `empty`, not "0 items in this catalogue." (S-3 RED L-2, UX TO CONFIRM). A Retry press
 * that may write the count first empties the page's region (FE-R9). The section's only read is Retry, which ignores presses while one is in flight, so
 * no two of its reads overlap and no stamp is needed until S-4 adds the write counter (§R1-4 S-4). Known limit until
 * S-4's write counter: a Save or Restore answered while a catalogue Retry is in flight can have its sentence replaced by
 * the count. S-4 routes the count through `replaceIfCurrent` (S-4 test 8 gains this case).
 */
export function CatalogueSection({ supplierId, initial, supplierArchived, canOpenItem, announce, clearRegion }: CatalogueSectionProps) {
  const caller = useApiCaller();
  const headingId = useId();
  const heading = useRef<HTMLHeadingElement>(null);
  const [load, setLoad] = useState<{ gen: number; value: CatalogueLoad }>({ gen: 0, value: initial });
  const [retrying, setRetrying] = useState(false);
  const [focusGen, setFocusGen] = useState(0);
  // Whether a successful load has been on screen: the server's first read counts (first paint is not announced).
  const everLoaded = useRef(initial.kind === 'ok');
  // The sentence a successful Retry speaks, written by the layout effect after the focus move (FE-D1), never before it.
  const pendingCount = useRef<string | null>(null);

  useLayoutEffect(() => {
    if (focusGen === 0) return;
    if (focusWasLost()) heading.current?.focus();
    if (pendingCount.current !== null) {
      announce(pendingCount.current);
      pendingCount.current = null;
    }
  }, [focusGen, announce]);

  const retry = async () => {
    if (retrying) return;
    // FE-R9 (`fe-r9-progress.md`): a press that may write the count empties the page's region at the press, after the
    // guard, before the request.
    if (!everLoaded.current) clearRegion();
    setRetrying(true);
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadCatalogue(readOf(caller), supplierId, d.signal);
    d.cancel();
    setRetrying(false);
    // A 401 is the caller's (the session-ended notice); nothing here changes.
    if (r.kind === 'session-ended') return;
    setLoad((cur) => ({ gen: cur.gen + 1, value: r }));
    if (r.kind !== 'ok') return;
    if (!everLoaded.current) {
      everLoaded.current = true;
      // S-3 RED L-2 (UX TO CONFIRM): an empty catalogue speaks the empty state's own sentence, not "0 items …".
      pendingCount.current = r.entries.length === 0 ? CATALOGUE.empty : CATALOGUE.count(r.entries.length);
    }
    setFocusGen((g) => g + 1);
  };

  const v = load.value;
  return (
    <section aria-labelledby={headingId}>
      <h2 id={headingId} ref={heading} tabIndex={-1}>
        {CATALOGUE.heading}
      </h2>
      {supplierArchived ? <p>{CATALOGUE.archivedParent}</p> : null}
      {v.kind === 'forbidden' ? (
        <p>{CATALOGUE.denied}</p>
      ) : v.kind !== 'ok' ? (
        // A not-found here is the supplier gone between the page's read and this one: the page's own re-reads say so.
        <RegionError key={`catalogue-${load.gen}`} heading={v.kind === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={CATALOGUE.loadFailed} retrying={retrying} onRetry={() => void retry()} autoFocus={load.gen > 0} />
      ) : v.entries.length === 0 ? (
        <p>{CATALOGUE.empty}</p>
      ) : (
        <>
          {v.nextCursor !== null ? <p>{CATALOGUE.partial(v.entries.length)}</p> : null}
          <table className={listStyles.table}>
            <thead>
              <tr>
                <th scope="col">{CATALOGUE.supplierSku}</th>
                <th scope="col">{CATALOGUE.item}</th>
                <th scope="col">{CATALOGUE.unitCost}</th>
                <th scope="col">{CATALOGUE.leadTime}</th>
                <th scope="col">{CATALOGUE.minimumOrder}</th>
              </tr>
            </thead>
            <tbody>
              {v.entries.map((e) => {
                const item = CATALOGUE.itemCell(e.inventory_item.sku, e.inventory_item.name);
                return (
                  <tr key={e.id}>
                    <td>{e.supplier_sku}</td>
                    <td>{canOpenItem ? <Link href={`/items/${e.inventory_item.id}`}>{item}</Link> : item}</td>
                    <td>{renderMoney(e.unit_cost, e.currency_code)}</td>
                    <td>{CATALOGUE.days(e.lead_time_days)}</td>
                    <td>{renderQuantity(e.minimum_order_quantity)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </>
      )}
    </section>
  );
}
