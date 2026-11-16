'use client';

import { useCallback, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import Link from 'next/link';
import { EntryForm } from '@/components/suppliers/EntryForm';
import { RegionError } from '@/components/states/PageStates';
import { Button } from '@/components/ui/Button';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { CATALOGUE, EDIT_CATALOGUE_ITEM } from '@/copy/suppliers';
import type { SupplierProduct } from '@/lib/api/suppliers';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { renderMoney } from '@/lib/format/money';
import { renderQuantity } from '@/lib/format/quantity';
import { loadCatalogue, type CatalogueLoad } from '@/lib/suppliers/load';
import { entrySave } from '@/lib/suppliers/wired';
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
  /** Holds UpdateSupplierProduct's codes (from the contract): each row's **Edit** on a live supplier (S-4). */
  canEdit: boolean;
  /**
   * The page's counted writer for its one status region (the section has none of its own; final RED M-5): it writes and
   * returns the write's number, whoever makes it.
   */
  announce: (text: string) => number;
  /** Writes `text` only while write number `gen` is still the region's last (a late correction never overwrites a later sentence). */
  replaceIfCurrent: (gen: number, text: string) => void;
  /**
   * An entry write was refused because the supplier is archived (C1 on a stale page, final RED M-3): the page re-reads the
   * supplier, stamped, and the archived banner and note follow from what it reads.
   */
  onParentStale: () => void;
}

type Reread = { kind: 'ok'; entries: SupplierProduct[] } | { kind: 'superseded' } | { kind: ReadFailure };
const isStale = (k: Reread['kind']) => k === 'unreachable' || k === 'server-error' || k === 'not-found';

/**
 * The supplier's catalogue (suppliers-plan.md Revision 1 — FINAL §R1-4 S-3, S-4 over Revision 0 §2.3; suppliers-ux-copy.md
 * Revision 1 — FINAL S-3 `CATALOGUE`, S-4 `EDIT_CATALOGUE_ITEM`, as overridden by bk31-placement-confirm.md Revision 1 —
 * FINAL; suppliers-final-red.md M-1, M-2, M-3, M-5, M-6). A plain section (ARCH (a): no paged list core, no view control,
 * no sort): one table over the served entries in served order (supplier SKU), the whole catalogue in one answer (G-42).
 * Columns Supplier SKU · Item · Unit cost · Lead time · Minimum order · Unit (R1-C), and Actions only for an
 * UpdateSupplierProduct holder on a live supplier. Unit cost through `renderMoney` (the m2 interim, no unit in the cell);
 * Minimum order QR-2 trimmed with no suffix; the Unit cell is the entry's OWN served `unit_of_measure_code` (BK-31), read
 * from the row and from nothing else.
 *
 * States: an initial 403 → `denied`; a failed first read → the section's own region error (not focused on first paint,
 * ST-2a) with Retry; a partial answer (`next_cursor` non-null) → `partial(n)` above the rows, which stay; an empty answer →
 * `empty`; an archived supplier → the S-4 `archivedParent` note, following the page's current supplier, and no actions.
 *
 * Edit (S-4): the row's **Edit** opens `EntryForm`. On close (FE-D1): commit the close, put focus back on the row's Edit
 * (else the heading) HERE, before anything is spoken — the dialog's own focus return runs on a timer after it unmounts,
 * which is later than the sentence (S-4 RED L-3) — then, after a 200, commit the checked 200 body over the row under a
 * fresh stamp (S-4 RED L-1: a reopened Edit starts from what was saved, and an older re-read in flight is superseded),
 * speak `saved(sku)` through the page's counted `announce`, and re-read. If that re-read fails, `savedListStale(sku)`
 * replaces the sentence only through `replaceIfCurrent`, i.e. only while nothing has written the region since; the rows
 * (with the saved body) stay. After a C1 the page re-reads the supplier (`onParentStale`). Every read of this section is
 * stamped when sent (the page's stamp rule, final RED M-6): an answer older than what is on screen is dropped, so an earlier
 * re-read answered late never undoes a later one. Failures commit nothing. An open dialog stays open when the page turns
 * archived: its Save then meets C1 (the server's sentence, Close only), so nothing the person typed is dropped unannounced
 * (S-4 RED M-1).
 *
 * The count is spoken through the page's region ONLY when the first successful load happens in the browser (a Retry after a
 * failed first load); never on first paint, never on a re-read (M-2). The count is written after the focus move (FE-D1):
 * after a successful Retry the section's heading takes focus only when focus was lost (the Retry button is gone from under
 * it; the Locations twin), and the count is written in the same layout effect, after that — through `replaceIfCurrent`
 * against the Retry press's own (counted) clear, so a Save or Restore answered while the Retry was in flight keeps its
 * sentence (S-3 RED L-3). When the Retry brings an empty catalogue, the sentence spoken is `empty` (S-3 RED L-2).
 */
export function CatalogueSection({ supplierId, initial, supplierArchived, canOpenItem, canEdit, announce, replaceIfCurrent, onParentStale }: CatalogueSectionProps) {
  const caller = useApiCaller();
  const headingId = useId();
  const heading = useRef<HTMLHeadingElement>(null);
  const [load, setLoad] = useState<{ gen: number; value: CatalogueLoad }>({ gen: 0, value: initial });
  const [retrying, setRetrying] = useState(false);
  const [focusGen, setFocusGen] = useState(0);
  const [editing, setEditing] = useState<SupplierProduct | null>(null);
  // The Edit button that opened the dialog, so the close can put focus back on it before anything is spoken (FE-D1).
  const opener = useRef<HTMLElement | null>(null);
  const save = useMemo(() => entrySave(caller, supplierId), [caller, supplierId]);
  const mayEdit = canEdit && !supplierArchived;
  // Whether a successful load has been on screen: the server's first read counts (first paint is not announced).
  const everLoaded = useRef(initial.kind === 'ok');
  // The sentence a successful Retry speaks and the number of the Retry press's clear, written by the layout effect after
  // the focus move (FE-D1), never before it.
  const pendingCount = useRef<{ gen: number; text: string } | null>(null);
  // The stamp rule: every read takes a number when it is sent; `committedAsOf` is the number of the reading on screen.
  const stamps = useRef(0);
  const committedAsOf = useRef(0);

  useLayoutEffect(() => {
    if (focusGen === 0) return;
    if (focusWasLost()) heading.current?.focus();
    const p = pendingCount.current;
    if (p !== null) {
      replaceIfCurrent(p.gen, p.text);
      pendingCount.current = null;
    }
  }, [focusGen, replaceIfCurrent]);

  /**
   * One stamped read of the catalogue. An answer older than the reading on screen is `superseded` and commits nothing. A
   * good answer or a 403 commits (in this call, `flushSync`, so the caller sees the rows it read). Any other failure commits
   * only when `replaceRows` (the Retry's region error replacing the last one); a write's re-read keeps the rows on screen.
   */
  const reread = useCallback(
    async (replaceRows: boolean): Promise<Reread> => {
      const mine = ++stamps.current;
      const d = startDeadline(ACTION_DEADLINES_MS.reread);
      const r = await loadCatalogue(readOf(caller), supplierId, d.signal);
      d.cancel();
      if (mine < committedAsOf.current) return { kind: 'superseded' };
      // A 401 is the caller's (the session-ended notice); nothing here changes.
      if (r.kind === 'session-ended') return r;
      if (r.kind === 'ok' || r.kind === 'forbidden' || replaceRows) {
        if (r.kind === 'ok' || r.kind === 'forbidden') committedAsOf.current = mine;
        flushSync(() => setLoad((cur) => ({ gen: cur.gen + 1, value: r })));
      }
      return r.kind === 'ok' ? { kind: 'ok', entries: r.entries } : r;
    },
    [caller, supplierId],
  );

  const retry = async () => {
    if (retrying) return;
    // FE-R9 (`fe-r9-progress.md`): a press that may write the count empties the page's region at the press, after the
    // guard, before the request, through the page's counted writer.
    const cleared = !everLoaded.current ? announce('') : null;
    setRetrying(true);
    const r = await reread(true);
    setRetrying(false);
    if (r.kind !== 'ok') return;
    if (!everLoaded.current && cleared !== null) {
      everLoaded.current = true;
      // S-3 RED L-2 (UX TO CONFIRM): an empty catalogue speaks the empty state's own sentence, not "0 items …".
      pendingCount.current = { gen: cleared, text: r.entries.length === 0 ? CATALOGUE.empty : CATALOGUE.count(r.entries.length) };
    }
    setFocusGen((g) => g + 1);
  };

  const closeEdit = async (edited: SupplierProduct, { saved, reread: again, parentStale }: { saved: SupplierProduct | null; reread: boolean; parentStale: boolean }) => {
    // FE-D1: commit the close, put focus back on the row's Edit button (else the heading), then speak, then re-read.
    flushSync(() => setEditing(null));
    const back = opener.current;
    opener.current = null;
    (back !== null && back.isConnected ? back : heading.current)?.focus();
    if (saved !== null) {
      // S-4 RED L-1: the 200 body is the server's new state; commit it over the row under a fresh stamp.
      committedAsOf.current = ++stamps.current;
      flushSync(() => setLoad((cur) => (cur.value.kind === 'ok' ? { gen: cur.gen + 1, value: { ...cur.value, entries: cur.value.entries.map((x) => (x.id === saved.id ? saved : x)) } } : cur)));
    }
    const said = saved !== null ? announce(EDIT_CATALOGUE_ITEM.saved(saved.inventory_item.sku)) : null;
    if (parentStale) onParentStale();
    if (!again) return;
    const r = await reread(false);
    // fe-r8-location-red L-1: what follows reads only this re-read's own answer, with nothing awaited between `reread()`
    // and the correction.
    // The row (and its Edit, where the dialog returned focus) left with this answer (removed elsewhere): the heading.
    if (r.kind === 'ok' && !r.entries.some((e) => e.id === edited.id) && focusWasLost()) heading.current?.focus();
    if (saved !== null && said !== null && isStale(r.kind)) replaceIfCurrent(said, EDIT_CATALOGUE_ITEM.savedListStale(saved.inventory_item.sku));
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
                <th scope="col">{CATALOGUE.unit}</th>
                {mayEdit ? <th scope="col">{CATALOGUE.actions}</th> : null}
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
                    <td>{e.unit_of_measure_code}</td>
                    {mayEdit ? (
                      <td>
                        <Button
                          size="sm"
                          aria-label={`${CATALOGUE.edit} ${e.inventory_item.sku}`}
                          onClick={(ev) => {
                            opener.current = ev.currentTarget;
                            setEditing(e);
                          }}
                        >
                          {CATALOGUE.edit}
                        </Button>
                      </td>
                    ) : null}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </>
      )}
      {editing !== null ? <EntryForm key={editing.id} entry={editing} save={save} editCode={gateCode('UpdateSupplierProduct')} onClose={(o) => void closeEdit(editing, o)} fallbackFocusRef={heading} onSaveStarted={() => announce('')} /> : null}
    </section>
  );
}
