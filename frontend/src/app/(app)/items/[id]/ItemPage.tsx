'use client';

import { useCallback, useLayoutEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { useRouter } from 'next/navigation';
import { ItemForm } from '@/components/items/ItemForm';
import styles from '@/components/items/Items.module.css';
import { DeleteDialog } from '@/components/records/DeleteDialog';
import { RestoreActions } from '@/components/records/RestoreActions';
import { RegionError } from '@/components/states/PageStates';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { COULD_NOT_REACH, NOT_SAVED, WENT_WRONG } from '@/copy/headings';
import { DELETE_ITEM, ITEM_FORBIDDEN, ITEM_PAGE, ITEMS_LIST, RESTORE_ITEM } from '@/copy/items';
import type { Item } from '@/lib/api/items';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { renderQuantity } from '@/lib/format/quantity';
import { loadItem } from '@/lib/items/load';
import type { ItemChange } from '@/lib/items/results';
import { itemDelete, itemRestore, itemSave } from '@/lib/items/wired';
import { useArrivalMessage } from '../../arrivalMessage';
import { useApiCaller, useViewerTimeZone } from '../../BffProvider';
import { useCommittedState } from '../../useCommittedState';
import { ItemNotFound, ItemsDenied } from '../ItemStates';
import listStyles from '../../Lists.module.css';

export interface ItemPageProps {
  /** GetItem on first render. */
  initial: Item;
  /** Holds UpdateItem's codes (from the contract): ST-4c. Without it the values are plain text. */
  canEdit: boolean;
  /** Holds RestoreItem's codes (from the contract): ST-4c. Without it an archived item shows its banner, no button. */
  canRestore: boolean;
  /** Holds DeleteItem's codes (from the contract): **Delete item** on a live item (items I-3). */
  canDelete?: boolean;
}

type Cause = 'unreachable' | 'server-error';

/** A number as the page shows it read-only: QR-2's rendering, then the item's unit code (R1-2, F-4). */
const withUnit = (served: string, unit: string) => `${renderQuantity(served)} ${unit}`;

/**
 * The item page (items-ux-copy.md B-3, B-5; IA L-4: the detail page is the editor; Revision 8 R8-2). It holds the item in
 * state and passes the re-read one to the form without a remount. Editable (SKU, Name, the three numbers) for an
 * UpdateItem holder on a live item; the same values as plain text otherwise. An archived item is read-only whatever the
 * viewer holds, under the ST-9 banner *"This item was archived on {date}."* with **Restore item** for a RestoreItem holder
 * (`components/records/RestoreActions`): a restore sets the re-read item here, so the page turns live without a remount.
 * A refused Save whose re-read finds the item archived (UX-I2 on a stale page) turns the page archived and shows the
 * server's sentence under **Not saved**, its heading focused. A failed re-read → the region error (focus on its heading),
 * Retry re-reads; 403 → ST-4b; 404 → ST-5. No stock, movements or links to them (G-47); no History (plan §2e). **Delete
 * item** (items I-3, the warehouse step-4.6 shape) on a live item for a DeleteItem holder: the shared danger dialog, its
 * stock line when the committed item's `holds_stock` is true (the only place the page shows it, I3-4); after a 204 the
 * page stays and turns archived. After a save's, restore's or delete's message is on screen the router cache is refreshed
 * once.
 */
export function ItemPage({ initial, canEdit, canRestore, canDelete = false }: ItemPageProps) {
  const caller = useApiCaller();
  const router = useRouter();
  const timeZone = useViewerTimeZone();
  const region = useLiveRegion();
  const [item, setItem] = useCommittedState(initial);
  const [swap, setSwap] = useState<'forbidden' | 'not-found' | null>(null);
  const [rereadError, setRereadError] = useState<{ gen: number; cause: Cause } | null>(null);
  const [retrying, setRetrying] = useState(false);
  const [staleRefusal, setStaleRefusal] = useState<{ gen: number; message: string } | null>(null);
  const [deleting, setDeleting] = useState(false);
  // 4.6 RED M-1: a 204 here is a fact that outranks a stale snapshot. If the re-read after it failed, the item is still
  // treated as archived for the actions (no Delete item, no form) until a commit that is not dropped says otherwise.
  const [deletedHere, setDeletedHere] = useState(false);
  const staleHeading = useRef<HTMLHeadingElement>(null);
  const h1 = useRef<HTMLHeadingElement>(null);
  const id = initial.id;
  // B-4 "After": the create page left "{sku} was created." for this address; spoken once, after RouteFocus has focused the h1.
  useArrivalMessage(`/items/${id}`, region.announce);

  // L-2: a commit that is not dropped is newer than an earlier failed re-read, so committing one clears that error.
  // The stamp rule (items-i3-design.md Revision 1 — FINAL §0a): every commit is stamped with the moment its reading was
  // requested. A page-owned re-read (Retry, a dialog's Close) takes its stamp when its request is sent. A write (Save,
  // Restore, Delete) takes its stamp when it sends its re-read, after the write's answer has arrived. Its re-read commits
  // under that stamp. So does the write's own answer body, committed when that re-read fails. A commit or failure whose
  // stamp is older than what is on screen is dropped. A 204 raises what is on screen to the delete's re-read stamp, even
  // when that re-read fails, so no reading requested before it can undo the delete. A write's own answer body is also
  // dropped when a 204 arrived after that write was sent: it describes the item before the delete. The 204 fact
  // (`deletedHere`) is cleared only by a commit that is not dropped. An older re-read landing first never drops a newer
  // one. Known limit: when that re-read fails, the write's answer body describes the moment of the write but carries the
  // re-read's stamp, so it can overwrite a Retry, sent in between, that read someone else's later change; the next
  // reading corrects it.
  const stamps = useRef(0);
  const committedAsOf = useRef(0);
  const deletedAsOf = useRef(0);
  const committedSku = useRef(initial.sku);
  const stamp = useCallback(() => ++stamps.current, []);
  const failed = useCallback((f: ReadFailure, asOf?: number) => {
    if (asOf !== undefined && asOf < committedAsOf.current) return;
    if (f === 'session-ended') return;
    if (f === 'forbidden' || f === 'not-found') {
      setSwap(f);
      return;
    }
    // After the form's own after-save focus, so the region error's heading takes it.
    setTimeout(() => setRereadError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: f })), 0);
  }, []);

  const commit = useCallback(
    async (i: Item, asOf: number, sentAsOf?: number) => {
      if (asOf < committedAsOf.current) return;
      if (sentAsOf !== undefined && sentAsOf < deletedAsOf.current) return;
      committedAsOf.current = asOf;
      committedSku.current = i.sku;
      setDeletedHere(false);
      setRereadError(null);
      // Live by any path (a restore, an already-live re-read, a Retry): the stale Save's sentence describes nothing on screen.
      if (i.archived_at === null) setStaleRefusal(null);
      await setItem(i);
    },
    [setItem],
  );
  // The wiring is built when an action runs, not during render: `commit` writes the stamp refs, which may only be
  // touched outside render (react-hooks/refs).
  const save = useCallback((changes: ItemChange) => itemSave({ caller, id, stamp, setItem: commit, onRereadFailed: failed })(changes), [caller, id, stamp, commit, failed]);
  const restore = useCallback(() => itemRestore({ caller, id, stamp, setItem: commit, onRereadFailed: failed })(), [caller, id, stamp, commit, failed]);
  // The 204 floor: raised before the delete's re-read is sent, so a reading requested earlier is dropped even when that
  // re-read fails. Without Math (the QR-2 backstop reads this file).
  const onDeleted = useCallback((s: number) => {
    if (s > committedAsOf.current) committedAsOf.current = s;
    deletedAsOf.current = s;
    setDeletedHere(true);
  }, []);
  const remove = useCallback(() => itemDelete({ caller, id, stamp, setItem: commit, onRereadFailed: failed, onDeleted })(), [caller, id, stamp, commit, failed, onDeleted]);
  const onSaved = useCallback(() => router.refresh(), [router]);
  const onRefusedArchived = useCallback((message: string) => setStaleRefusal((cur) => ({ gen: (cur?.gen ?? 0) + 1, message })), []);
  // A restore turns the page live: the stale Save's sentence no longer describes what is on screen.
  const onRestored = useCallback(() => {
    setStaleRefusal(null);
    router.refresh();
  }, [router]);

  // After the commit that renders the banner (focus never runs ahead of what it lands on).
  const staleGen = staleRefusal?.gen ?? null;
  useLayoutEffect(() => {
    if (staleGen !== null) staleHeading.current?.focus();
  }, [staleGen]);

  // A commit that turns the page archived unmounts the form (and its Save) under the person's focus: when focus is then
  // lost (on <body> or a removed element), it moves to the h1. Effects that focus something themselves run after this one.
  const archivedNow = item.archived_at !== null;
  useLayoutEffect(() => {
    if (!archivedNow) return;
    const a = document.activeElement;
    if (a === null || a === document.body || !a.isConnected) h1.current?.focus();
  }, [archivedNow]);

  // Items I-3 (the WarehousePage.closeDelete shape, FE-D1). After a 204 the page stays: the item is archived, not gone,
  // and `itemDelete` has already committed (or reported) the re-read, so the ST-9 banner and Restore item are on screen.
  // Commit the close, focus the h1 (the opener is gone with the live state), speak "After" with the SKU last committed
  // (a Save may have renamed it after Delete was pressed; i3-c1 RED L-2), refresh the router cache once. The deleted
  // branch does not set `deletedHere`: `onDeleted` already did, and only a commit that is not dropped may clear it (a
  // re-read that reads the item live, restored in between, must leave the page live; i3-c1 RED L-3). Any other close after an answer re-reads the item: an archived re-read is committed (an interrupted press that
  // committed, I3 deleted elsewhere) and the h1 takes focus; a live one changes nothing -- not committed, so the typed
  // edits stay, and the region error is left as it is (M-5 as corrected by the final RED L-4; `itemSave`'s refusal rule).
  const closeDelete = async ({ deleted, reread }: { deleted: boolean; reread: boolean }) => {
    flushSync(() => setDeleting(false));
    if (deleted) {
      h1.current?.focus();
      region.announce(DELETE_ITEM.after(committedSku.current));
      router.refresh();
      return;
    }
    if (!reread) return;
    const mine = ++stamps.current;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadItem(readOf(caller), id, d.signal);
    d.cancel();
    if (committedAsOf.current > mine) return;
    if (r.kind === 'ok') {
      if (r.item.archived_at === null) return;
      await commit(r.item, mine);
      h1.current?.focus();
      router.refresh();
    } else failed(r.kind, mine);
  };

  const retry = async () => {
    if (retrying) return;
    setRetrying(true);
    const mine = ++stamps.current;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadItem(readOf(caller), id, d.signal);
    d.cancel();
    setRetrying(false);
    if (committedAsOf.current > mine) return;
    if (r.kind === 'ok') {
      await commit(r.item, mine);
      setRereadError(null);
      h1.current?.focus();
    } else failed(r.kind, mine);
  };

  if (swap === 'forbidden') return <ItemsDenied />;
  if (swap === 'not-found') return <ItemNotFound />;

  const archived = item.archived_at !== null;
  const actionsArchived = archived || deletedHere;
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {ITEM_PAGE.title(item.sku, item.name)}
        </h1>
        {canDelete && !actionsArchived ? (
          <Button variant="destructive" onClick={() => setDeleting(true)}>
            {DELETE_ITEM.action}
          </Button>
        ) : null}
      </header>
      {/* Mounted from first render, above every branch below, so it never arrives already speaking. */}
      <LiveRegion message={region.message} visuallyHidden />
      {rereadError ? (
        <RegionError key={`reread-${rereadError.gen}`} heading={rereadError.cause === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={ITEM_PAGE.rereadFailed} retrying={retrying} onRetry={() => void retry()} />
      ) : null}
      {staleRefusal && archived ? (
        <Banner key={`stale-${staleRefusal.gen}`} tone="danger" live="none" heading={NOT_SAVED} ref={staleHeading}>
          <p>{staleRefusal.message}</p>
        </Banner>
      ) : null}
      {item.archived_at !== null ? (
        <RestoreActions
          archivedAt={item.archived_at}
          copy={{
            archivedBefore: ITEM_PAGE.archivedOn,
            archivedAfter: ITEM_PAGE.archivedAfter,
            action: RESTORE_ITEM.action,
            after: (r) => RESTORE_ITEM.after(r.item.sku),
            alreadyLive: (r) => RESTORE_ITEM.alreadyRestored(r.item.sku),
            interrupted: RESTORE_ITEM.interrupted(item.sku),
            forbidden: ITEM_FORBIDDEN,
          }}
          canRestore={canRestore}
          restore={restore}
          restoreCode={gateCode('RestoreItem')}
          timeZone={timeZone}
          announce={region.announce}
          fallbackFocusRef={h1}
          onRestored={onRestored}
        />
      ) : null}
      {canEdit && !actionsArchived ? (
        <ItemForm item={item} save={save} editCode={gateCode('UpdateItem')} announce={region.announce} fallbackFocusRef={h1} onSaved={onSaved} onRefusedArchived={onRefusedArchived} />
      ) : (
        <dl className={styles.fields}>
          <dt>{ITEM_PAGE.sku}</dt>
          <dd>{item.sku}</dd>
          <dt>{ITEM_PAGE.name}</dt>
          <dd>{item.name}</dd>
          <dt>{ITEM_PAGE.unit}</dt>
          <dd>{item.unit_of_measure_code}</dd>
          <dt>{ITEM_PAGE.supplier}</dt>
          <dd>{item.supplier !== null ? item.supplier.name : ITEMS_LIST.noSupplier}</dd>
          <dt>{ITEM_PAGE.reorderPoint}</dt>
          <dd>{withUnit(item.reorder_point, item.unit_of_measure_code)}</dd>
          <dt>{ITEM_PAGE.reorderQuantity}</dt>
          <dd>{withUnit(item.reorder_quantity, item.unit_of_measure_code)}</dd>
          <dt>{ITEM_PAGE.safetyStock}</dt>
          <dd>{withUnit(item.safety_stock, item.unit_of_measure_code)}</dd>
        </dl>
      )}
      {deleting ? (
        <DeleteDialog
          copy={{
            title: DELETE_ITEM.title(item.sku, item.name),
            // Per committed reading (§5.3): a commit while the dialog is open moves the line with it.
            warning: item.holds_stock ? DELETE_ITEM.holdsStock : undefined,
            body: DELETE_ITEM.body,
            confirm: DELETE_ITEM.confirm,
            cancel: DELETE_ITEM.cancel,
            close: DELETE_ITEM.close,
            interrupted: DELETE_ITEM.interrupted(item.sku),
            forbidden: ITEM_FORBIDDEN,
          }}
          run={remove}
          deleteCode={gateCode('DeleteItem')}
          onClose={(o) => void closeDelete(o)}
          fallbackFocusRef={h1}
        />
      ) : null}
    </section>
  );
}
