'use client';

import { useCallback, useId, useLayoutEffect, useMemo, useRef, useState, type MouseEvent } from 'react';
import { flushSync } from 'react-dom';
import Link from 'next/link';
import { DeleteDialog } from '@/components/records/DeleteDialog';
import { AddEntryForm, type ChoicesFocus } from '@/components/suppliers/AddEntryForm';
import { EntryForm } from '@/components/suppliers/EntryForm';
import { RegionError, Skeleton } from '@/components/states/PageStates';
import { Button } from '@/components/ui/Button';
import { COULD_NOT_REACH, NOT_REMOVED, WENT_WRONG } from '@/copy/headings';
import { unitLabel } from '@/copy/items';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { ADD_CATALOGUE_ITEM, CATALOGUE, EDIT_CATALOGUE_ITEM, ITEM_CHOICES, REMOVE_CATALOGUE_ITEM, SUPPLIER_FORBIDDEN } from '@/copy/suppliers';
import type { SupplierProduct } from '@/lib/api/suppliers';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type PageRequest, type ReadFailure } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { renderMoney } from '@/lib/format/money';
import { renderQuantity } from '@/lib/format/quantity';
import { loadCatalogue, loadItemChoices, type CatalogueLoad, type ItemChoice, type ItemChoicesLoad } from '@/lib/suppliers/load';
import type { DeleteResult } from '@/lib/results';
import { entryCreate, entryDelete, entrySave } from '@/lib/suppliers/wired';
import { ArchivableList, focusWasLost, type Column, type EmptyState, type Paging } from '../../ArchivableList';
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
   * Holds CreateSupplierProduct's AND ListItems' codes (from the contract; plan §R1-2): **Add to catalogue** on a live
   * supplier (S-5), in the header and the empty state.
   */
  canAdd: boolean;
  /** Holds DeleteSupplierProduct's codes (from the contract; plan §R1-2): each row's **Remove** on a live supplier (S-6). */
  canRemove: boolean;
  /** The page's CURRENT supplier's code (the Add dialog's title) and currency (its Unit cost label; M-9's comparison). */
  supplierCode: string;
  supplierCurrency: string;
  /**
   * The page's counted writer for its one status region (the section has none of its own; final RED M-5): it writes and
   * returns the write's number, whoever makes it.
   */
  announce: (text: string) => number;
  /** Writes `text` only while write number `gen` is still the region's last (a late correction never overwrites a later sentence). */
  replaceIfCurrent: (gen: number, text: string) => void;
  /**
   * An entry write was refused because the supplier is archived (C1 on a stale page, final RED M-3), or an Add's 201
   * answered in another currency than the dialog named (M-9, S-5): the page re-reads the supplier, stamped, and what it
   * shows follows from what it reads.
   */
  onParentStale: () => void;
  /**
   * S-7 D-2 (lesson 5): the number of Delete supplier presses on the page so far (default 0). Add to catalogue reads its
   * first page before it opens its dialog, so the page's Delete supplier can be pressed while that read is out: the later
   * press wins (suppliers-s5-red.md M-1, as a row's Edit or Remove) — Add stops loading at once and the late answer opens
   * nothing, whether the delete then goes ahead or is cancelled.
   */
  deletePresses?: number;
}

type Reread = { kind: 'ok'; entries: SupplierProduct[] } | { kind: 'superseded' } | { kind: ReadFailure };
const isStale = (k: Reread['kind']) => k === 'unreachable' || k === 'server-error' || k === 'not-found';
/** The first page of live items, no search (final RED M-3 step 1: every open, and every Change, reads it afresh). */
const FIRST_ITEMS: PageRequest = { q: '', cursor: null };
const focusFor = (l: ItemChoicesLoad): ChoicesFocus => (l.kind === 'ok' ? 'search' : l.kind === 'forbidden' ? 'cancel' : 'retry');
/** The id of the choice list's heading inside the Add dialog (the core's focus target after a Retry, FE-R3 H-2). */
const CHOICES_HEADING_ID = 'catalogue-item-choices-heading';

/**
 * The supplier's catalogue (suppliers-plan.md Revision 1 — FINAL §R1-4 S-3, S-4 over Revision 0 §2.3; suppliers-ux-copy.md
 * Revision 1 — FINAL S-3 `CATALOGUE`, S-4 `EDIT_CATALOGUE_ITEM`, as overridden by bk31-placement-confirm.md Revision 1 —
 * FINAL; suppliers-final-red.md M-1, M-2, M-3, M-5, M-6). A plain section (ARCH (a): no paged list core, no view control,
 * no sort): one table over the served entries in served order (supplier SKU), the whole catalogue in one answer (G-42).
 * Columns Supplier SKU · Item · Unit cost · Lead time · Minimum order · Unit (R1-C), and Actions for an
 * UpdateSupplierProduct or DeleteSupplierProduct holder on a live supplier. Unit cost through `renderMoney` (D1 by dropping only zeros, Revision 10; no unit in the cell);
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
 * Remove (S-6): the row's **Remove** opens the shared Delete dialog with the Remove copy and the heading *Not removed*.
 * Its press first cancels an Add read in flight, as Edit's does (suppliers-s5-red.md M-1, RED-2 L-2). On close (FE-D1):
 * commit the close, then focus — after a 204 the Catalogue heading (§R1-10), otherwise the row's Remove while it is still
 * connected, else the heading — then, after a 204, drop the row under a FRESH stamp (the S-4 RED L-1 rule for a body-less
 * answer: a re-read sent before the press, answered after it, cannot bring the row back), speak `after(sku)` through the
 * page's counted `announce`, and re-read. If that re-read fails, `afterListStale(sku)` replaces the sentence only through
 * `replaceIfCurrent`. No "restored" arm: a removed entry never returns under its id (UX B-9). After a C1 the page
 * re-reads the supplier (`onParentStale`). Like Edit's, an open Remove dialog stays open when the page turns archived.
 *
 * The count is spoken through the page's region ONLY when the first successful load happens in the browser (a Retry after a
 * failed first load); never on first paint, never on a re-read (M-2). The count is written after the focus move (FE-D1):
 * after a successful Retry the section's heading takes focus only when focus was lost (the Retry button is gone from under
 * it; the Locations twin), and the count is written in the same layout effect, after that — through `replaceIfCurrent`
 * against the Retry press's own (counted) clear, so a Save or Restore answered while the Retry was in flight keeps its
 * sentence (S-3 RED L-3). When the Retry brings an empty catalogue, the sentence spoken is `empty` (S-3 RED L-2).
 */
export function CatalogueSection({ supplierId, initial, supplierArchived, canOpenItem, canEdit, canAdd, canRemove, supplierCode, supplierCurrency, announce, replaceIfCurrent, onParentStale, deletePresses = 0 }: CatalogueSectionProps) {
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
  const create = useMemo(() => entryCreate(caller, supplierId), [caller, supplierId]);
  const remove = useMemo(() => entryDelete(caller, supplierId), [caller, supplierId]);
  const mayEdit = canEdit && !supplierArchived;
  const mayAdd = canAdd && !supplierArchived;
  const mayRemove = canRemove && !supplierArchived;
  // Remove (S-6): the entry whose dialog is open, and whether its answer was a C1 (the supplier archived meanwhile).
  const [removing, setRemoving] = useState<SupplierProduct | null>(null);
  const removeParentStale = useRef(false);
  // Add (S-5): `opening` while the press's first items read is in flight (the button loading, no dialog yet, M-3 step 1);
  // `adding` while the dialog is open, with the choice list's answer (`null` while a Change's read is in flight). Each
  // read takes a number; an answer to an older one (a dialog since closed, an earlier Change) is dropped.
  const [opening, setOpening] = useState(false);
  const [adding, setAdding] = useState<{ gen: number; load: ItemChoicesLoad | null } | null>(null);
  // UX U-1: the choice list shows a state with no item to choose (`noneYet`, `allArchived`, `denied`) — the Add dialog
  // then offers no Add to catalogue while nothing is chosen. Reported by the state itself while it is on screen.
  const [nothingToChoose, setNothingToChoose] = useState(false);
  const choiceReads = useRef(0);
  // Whether a successful load has been on screen: the server's first read counts (first paint is not announced).
  const everLoaded = useRef(initial.kind === 'ok');
  // The sentence a successful Retry speaks and the number of the Retry press's clear, written by the layout effect after
  // the focus move (FE-D1), never before it.
  const pendingCount = useRef<{ gen: number; text: string } | null>(null);
  // The stamp rule: every read takes a number when it is sent; `committedAsOf` is the number of the reading on screen.
  const stamps = useRef(0);
  const committedAsOf = useRef(0);
  // D-2: a Delete supplier press stops a loading Add at once (adjusted during render, the `loadedKey` pattern) and, through
  // the ref read when the Add's read answers, drops that answer.
  const [seenDeletePresses, setSeenDeletePresses] = useState(deletePresses);
  if (seenDeletePresses !== deletePresses) {
    setSeenDeletePresses(deletePresses);
    setOpening(false);
  }
  const latestDeletePresses = useRef(deletePresses);
  useLayoutEffect(() => {
    latestDeletePresses.current = deletePresses;
  }, [deletePresses]);

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

  const readChoices = async (): Promise<ItemChoicesLoad> => {
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadItemChoices(readOf(caller), FIRST_ITEMS, d.signal);
    d.cancel();
    return r;
  };

  /**
   * Add to catalogue (M-3 step 1): read the first page, then open the dialog over whatever answered. A row's Edit pressed
   * while this read is in flight cancels it (one dialog at a time; suppliers-s5-red.md M-1).
   */
  const openAdd = async (ev: MouseEvent<HTMLButtonElement>) => {
    if (opening || adding !== null) return;
    opener.current = ev.currentTarget;
    setOpening(true);
    const mine = ++choiceReads.current;
    const pressesAtOpen = latestDeletePresses.current;
    const r = await readChoices();
    // Superseded (a row's Edit): whoever superseded this read owns `opening` now.
    if (mine !== choiceReads.current) return;
    // Superseded by the page's Delete supplier (D-2): it already stopped the loading; the answer opens nothing.
    if (latestDeletePresses.current !== pressesAtOpen) return;
    setOpening(false);
    // A 401 is the caller's (the session-ended notice): no dialog.
    if (r.kind === 'session-ended') return;
    setAdding({ gen: mine, load: r });
  };

  /** **Change** (M-3 step 5): the dialog has cleared its chosen item; the list is read afresh, inside the open dialog. */
  const changeItem = async () => {
    const mine = ++choiceReads.current;
    setAdding((cur) => (cur === null ? cur : { gen: mine, load: null }));
    const r = await readChoices();
    // Every close, open and row Edit moves `choiceReads`, so an answer whose number is still the latest belongs to THIS
    // open dialog (review plant P-6: closing is what makes a Change read in flight stale).
    if (mine !== choiceReads.current || r.kind === 'session-ended') return;
    setAdding({ gen: mine, load: r });
  };

  const closeAdd = async ({ created, chosen, currency, reread: again, parentStale }: { created: SupplierProduct | null; chosen: ItemChoice | null; currency: string; reread: boolean; parentStale: boolean }) => {
    // FE-D1: commit the close, put focus where it goes, then speak, then re-read. After a 201: the Catalogue heading
    // (§R1-10). Otherwise the button that opened the dialog, else — when it is gone (the page turned archived meanwhile,
    // both Add buttons unmount) — the heading, never the removed button. The close first makes any choice read in flight
    // stale (a Change's late answer would otherwise open the dialog again).
    choiceReads.current += 1;
    flushSync(() => setAdding(null));
    const back = opener.current;
    opener.current = null;
    (created !== null ? heading.current : back !== null && back.isConnected ? back : heading.current)?.focus();
    let said: number | null = null;
    let parts: { sku: string; currency: string | null; unitLabel: string | null } | null = null;
    if (created !== null) {
      // The 201 body is the server's new entry: commit it under a FRESH stamp (the S-4 RED L-1 rule), so a re-read in
      // flight from before this Add, answered after it, cannot remove it. It goes last until the re-read puts it in served
      // order (no client sort); if the re-read fails, the sentence says the list may be out of date.
      committedAsOf.current = ++stamps.current;
      flushSync(() => setLoad((cur) => (cur.value.kind === 'ok' ? { gen: cur.gen + 1, value: { ...cur.value, entries: [...cur.value.entries.filter((x) => x.id !== created.id), created] } } : cur)));
      // FINAL (e) item 10: the unit is compared with the CHOSEN item's (the one the dialog showed), never with a re-read's.
      // M-9: the currency with the one the dialog labelled when the request was sent.
      parts = {
        sku: created.inventory_item.sku,
        currency: created.currency_code !== currency ? created.currency_code : null,
        unitLabel: chosen !== null && created.unit_of_measure_code !== chosen.unit_of_measure_code ? unitLabel(created.unit_of_measure_code) : null,
      };
      said = announce(ADD_CATALOGUE_ITEM.afterComposed(parts.sku, { ...parts, stale: false }));
      // M-9: the supplier's currency changed meanwhile: the page re-reads the supplier. The unit case needs no supplier
      // re-read: the section's re-read shows the new unit in the Unit column.
      if (parts.currency !== null) onParentStale();
    }
    if (parentStale) onParentStale();
    if (!again) return;
    const r = await reread(false);
    // fe-r8-location-red L-1: what follows reads only this re-read's own answer, with nothing awaited between `reread()`
    // and the correction.
    if (parts !== null && said !== null && isStale(r.kind)) replaceIfCurrent(said, ADD_CATALOGUE_ITEM.afterComposed(parts.sku, { ...parts, stale: true }));
  };

  /** One Remove press: a C1 is shown as the shared dialog's refusal (Close only) and remembered for the close. */
  const runRemove = async (e: SupplierProduct): Promise<DeleteResult> => {
    const r = await remove(e.id);
    if (r.kind !== 'refused-archived') return r;
    removeParentStale.current = true;
    return { kind: 'refused', rule: r.rule, message: r.message };
  };

  const closeRemove = async (removed: SupplierProduct, { deleted, reread: again }: { deleted: boolean; reread: boolean }) => {
    // FE-D1: commit the close, put focus where it goes, then speak, then re-read. After a 204 the row (and its Remove)
    // goes, so the Catalogue heading (§R1-10); otherwise the row's Remove, else — when it is gone — the heading.
    flushSync(() => setRemoving(null));
    const back = opener.current;
    opener.current = null;
    (deleted ? heading.current : back !== null && back.isConnected ? back : heading.current)?.focus();
    const parentStale = removeParentStale.current;
    removeParentStale.current = false;
    let said: number | null = null;
    if (deleted) {
      // The 204 is the server's word that the entry is gone: drop the row under a FRESH stamp, so a re-read in flight
      // from before this Remove, answered after it, cannot bring it back.
      committedAsOf.current = ++stamps.current;
      flushSync(() => setLoad((cur) => (cur.value.kind === 'ok' ? { gen: cur.gen + 1, value: { ...cur.value, entries: cur.value.entries.filter((x) => x.id !== removed.id) } } : cur)));
      said = announce(REMOVE_CATALOGUE_ITEM.after(removed.inventory_item.sku));
    }
    if (parentStale) onParentStale();
    if (!again) return;
    const r = await reread(false);
    // fe-r8-location-red L-1: what follows reads only this re-read's own answer, with nothing awaited between `reread()`
    // and the correction.
    // The row (and its Remove, where focus went back) left with this answer (removed elsewhere, a 404): the heading.
    if (r.kind === 'ok' && !r.entries.some((x) => x.id === removed.id) && focusWasLost()) heading.current?.focus();
    if (said !== null && isStale(r.kind)) replaceIfCurrent(said, REMOVE_CATALOGUE_ITEM.afterListStale(removed.inventory_item.sku));
  };

  const addButton = (size?: 'sm') => (
    <Button size={size} loading={opening} onClick={(ev) => void openAdd(ev)}>
      {CATALOGUE.addItem}
    </Button>
  );

  const choicesFor = (load: ItemChoicesLoad, gen: number) =>
    function ChoiceArea(onChoose: (c: ItemChoice) => void) {
      return <ItemChoiceList key={gen} initial={load} onChoose={onChoose} onNothingToChoose={setNothingToChoose} />;
    };
  /** UX DV-8 FIX (ST-1): while a Change's read is in flight, the skeleton stands where the list will be. */
  const choicesLoading = () => <Skeleton rows={3} label={ITEM_CHOICES.loading} />;

  const v = load.value;
  return (
    <section aria-labelledby={headingId}>
      <h2 id={headingId} ref={heading} tabIndex={-1}>
        {CATALOGUE.heading}
      </h2>
      {mayAdd && v.kind !== 'forbidden' ? addButton() : null}
      {supplierArchived ? <p>{CATALOGUE.archivedParent}</p> : null}
      {v.kind === 'forbidden' ? (
        <p>{CATALOGUE.denied}</p>
      ) : v.kind !== 'ok' ? (
        // A not-found here is the supplier gone between the page's read and this one: the page's own re-reads say so.
        <RegionError key={`catalogue-${load.gen}`} heading={v.kind === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={CATALOGUE.loadFailed} retrying={retrying} onRetry={() => void retry()} autoFocus={load.gen > 0} />
      ) : v.entries.length === 0 ? (
        <>
          <p>{CATALOGUE.empty}</p>
          {mayAdd ? (
            <>
              <p>{CATALOGUE.emptyNote}</p>
              {addButton('sm')}
            </>
          ) : null}
        </>
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
                {mayEdit || mayRemove ? <th scope="col">{CATALOGUE.actions}</th> : null}
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
                    {mayEdit || mayRemove ? (
                      <td>
                        {mayEdit ? (
                        <Button
                          size="sm"
                          aria-label={`${CATALOGUE.edit} ${e.inventory_item.sku}`}
                          onClick={(ev) => {
                            // suppliers-s5-red.md M-1: the later press wins — an Add whose first read is still in flight
                            // is cancelled (its answer is dropped by `openAdd`'s check) and its button stops loading.
                            choiceReads.current += 1;
                            setOpening(false);
                            opener.current = ev.currentTarget;
                            setEditing(e);
                          }}
                        >
                          {CATALOGUE.edit}
                        </Button>
                        ) : null}
                        {mayRemove ? (
                          <Button
                            size="sm"
                            variant="destructive"
                            aria-label={`${CATALOGUE.remove} ${e.inventory_item.sku}`}
                            onClick={(ev) => {
                              // suppliers-s5-red.md M-1 / RED-2 L-2, as Edit's: the later press wins — an Add whose first read
                              // is still in flight is cancelled and its button stops loading.
                              choiceReads.current += 1;
                              setOpening(false);
                              opener.current = ev.currentTarget;
                              removeParentStale.current = false;
                              setRemoving(e);
                            }}
                          >
                            {CATALOGUE.remove}
                          </Button>
                        ) : null}
                      </td>
                    ) : null}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </>
      )}
      {/* An open Add dialog stays open when the page turns archived (the S-4 RED M-1 rule): its Add then meets C1. */}
      {adding !== null ? (
        <AddEntryForm
          supplierCode={supplierCode}
          currency={supplierCurrency}
          create={create}
          createCode={gateCode('CreateSupplierProduct')}
          choices={adding.load !== null ? choicesFor(adding.load, adding.gen) : choicesLoading}
          nothingToChoose={nothingToChoose}
          choicesShown={adding.load !== null ? { gen: adding.gen, focus: focusFor(adding.load) } : null}
          onChangeItem={() => void changeItem()}
          onClose={(o) => void closeAdd(o)}
          fallbackFocusRef={heading}
          onAddStarted={() => announce('')}
        />
      ) : null}
      {/* Rendered on its open state alone (never `&& mayRemove`): it stays open when the page turns archived (S-4 RED M-1). */}
      {removing !== null ? (
        <DeleteDialog
          key={removing.id}
          copy={{
            title: REMOVE_CATALOGUE_ITEM.title(removing.inventory_item.sku, removing.inventory_item.name),
            body: REMOVE_CATALOGUE_ITEM.body,
            confirm: REMOVE_CATALOGUE_ITEM.confirm,
            cancel: REMOVE_CATALOGUE_ITEM.cancel,
            close: REMOVE_CATALOGUE_ITEM.close,
            interrupted: REMOVE_CATALOGUE_ITEM.interrupted(removing.inventory_item.sku),
            forbidden: SUPPLIER_FORBIDDEN,
            refusedHeading: NOT_REMOVED,
          }}
          run={() => runRemove(removing)}
          deleteCode={gateCode('DeleteSupplierProduct')}
          onClose={(o) => void closeRemove(removing, o)}
          fallbackFocusRef={heading}
          onDeleteStarted={() => announce('')}
          // UX ux-s6-rulings.md §1 (S-6 RED L-2 (a)): an interrupted Remove leaves Close only.
          finalWhenInterrupted
        />
      ) : null}
      {editing !== null ? <EntryForm key={editing.id} entry={editing} save={save} offersAdd={canAdd} editCode={gateCode('UpdateSupplierProduct')} onClose={(o) => void closeEdit(editing, o)} fallbackFocusRef={heading} onSaveStarted={() => announce('')} /> : null}
    </section>
  );
}

/**
 * The Add dialog's item choice list (suppliers-plan.md Revision 1 — FINAL §R1-4 S-5; suppliers-final-red.md M-3, M-4;
 * UX S-5 `ITEM_CHOICES`): the paged list core over `loadItemChoices`, LIVE items only — no Archived control, no view
 * switch — under its own heading (*Choose an item*), with the archived note above the rows, columns SKU · Name · Unit ·
 * **Choose**, and its own status region (the core's, inside the dialog). Its first page is the answer the open (or a
 * Change) read, whatever its kind: ok → the rows; a failure → the core's region error with Retry; a 403 → `denied`. The
 * unit cell is `unitLabel(code)` (the one `copy/items` import here, judge ruling 2 as derived at S-5).
 */
/**
 * Renders nothing; while it is on screen the choice list is in a state with no item to choose, which it reports (UX U-1).
 * The report is made from a LAYOUT effect, never during render, and withdrawn when the state leaves the screen (its
 * cleanup is the flag's only reset; RED-2 L-1). A layout effect so the parent's re-render that hides Add runs before the
 * browser paints, never showing Add for one frame (RED-2 L-5). jsdom does not paint, so no test can pin the frame.
 */
function NothingToChoose({ report }: { report: (on: boolean) => void }) {
  useLayoutEffect(() => {
    report(true);
    return () => report(false);
  }, [report]);
  return null;
}

function ItemChoiceList({ initial, onChoose, onNothingToChoose }: { initial: ItemChoicesLoad; onChoose: (c: ItemChoice) => void; onNothingToChoose: (on: boolean) => void }) {
  const columns = (): Column<ItemChoice>[] => [
    { header: ITEM_CHOICES.sku, cell: (c) => c.sku },
    { header: ITEM_CHOICES.name, cell: (c) => c.name },
    { header: ITEM_CHOICES.unit, cell: (c) => unitLabel(c.unit_of_measure_code) },
    {
      header: ITEM_CHOICES.choose,
      cell: (c) => (
        <Button size="sm" aria-label={ITEM_CHOICES.chooseItem(c.sku)} onClick={() => onChoose(c)}>
          {ITEM_CHOICES.choose}
        </Button>
      ),
    },
  ];
  const empty: EmptyState = ({ q, tenantHasRows, clearSearch }) =>
    q !== ''
      ? {
          heading: ITEM_CHOICES.noMatch(q),
          // RED-2 L-3 (adopted, under UX U-1's principle; UX may strike): in a workspace with no item at all, clearing the
          // search returns to `noneYet`, so a search with no match leaves nothing to choose either.
          actions: (
            <>
              <Button size="sm" onClick={clearSearch}>
                {ITEM_CHOICES.clearSearch}
              </Button>
              {tenantHasRows === false ? <NothingToChoose report={onNothingToChoose} /> : null}
            </>
          ),
        }
      : // M-4: `noneYet` only when the workspace has no item at all; otherwise every item is archived. Either way there is
        // nothing to choose (UX U-1).
        { heading: tenantHasRows === true ? ITEM_CHOICES.allArchived : ITEM_CHOICES.noneYet, actions: <NothingToChoose report={onNothingToChoose} /> };
  const paging: Paging<ItemChoice> = {
    pager: () => ITEM_CHOICES.pager,
    noMore: () => ITEM_CHOICES.noMore,
    pageShown: (rows, q) => {
      const sentence = ITEM_CHOICES.pageShown(rows.length, rows[0]!.sku, rows[rows.length - 1]!.sku);
      return q !== '' ? `${sentence} ${ITEM_CHOICES.searched(q)}` : sentence;
    },
  };
  const viewCode = gateCode('ListItems');
  return (
    <ArchivableList<ItemChoice>
      paging={paging}
      initial={initial}
      load={(read, _archived, page, signal) => loadItemChoices(read, page, signal)}
      columns={columns}
      copy={{ heading: null, archived: '', loadFailed: ITEM_CHOICES.loadFailed, shown: (n, q) => ITEM_CHOICES.shown(n, q) }}
      headingId={CHOICES_HEADING_ID}
      header={() => (
        <h3 id={CHOICES_HEADING_ID} tabIndex={-1}>
          {ITEM_CHOICES.heading}
        </h3>
      )}
      search={{ label: ITEM_CHOICES.search, placeholder: ITEM_CHOICES.placeholder, hint: ITEM_CHOICES.hint }}
      notice={() => <p>{ITEM_CHOICES.archivedNote}</p>}
      empty={empty}
      archivedView={{ order: 'as-served', caption: '', rows: 'plain' }}
      denied={
        <p>
          {ITEM_CHOICES.denied.before}
          <em>{(viewCode !== null ? PERMISSION_LABELS[viewCode]?.label : undefined) ?? ''}</em>
          {ITEM_CHOICES.denied.after}
          <NothingToChoose report={onNothingToChoose} />
        </p>
      }
    />
  );
}
