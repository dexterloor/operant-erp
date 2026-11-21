'use client';

import { useCallback, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { useRouter } from 'next/navigation';
import { DeleteDialog } from '@/components/records/DeleteDialog';
import { RestoreActions } from '@/components/records/RestoreActions';
import { RegionError } from '@/components/states/PageStates';
import { Button } from '@/components/ui/Button';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { WarehouseForm } from '@/components/warehouses/WarehouseForm';
import styles from '@/components/warehouses/Warehouses.module.css';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { DELETE_WAREHOUSE, RESTORE_WAREHOUSE, WAREHOUSE_FORBIDDEN, WAREHOUSE_PAGE } from '@/copy/warehouses';
import type { UpdateWarehouseInput, Warehouse } from '@/lib/api/warehouses';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { loadWarehouse, type LocationsLoad } from '@/lib/warehouses/load';
import { warehouseDelete, warehouseRestore, warehouseSave } from '@/lib/warehouses/wired';
import { useApiCaller, useViewerTimeZone } from '../../BffProvider';
import { useArrivalMessage } from '../../arrivalMessage';
import { useCommittedState } from '../../useCommittedState';
import { WarehouseNotFound, WarehousesDenied } from '../WarehouseStates';
import { LocationsSection } from './LocationsSection';
import listStyles from '../../Lists.module.css';

export interface WarehousePageProps {
  /** GetWarehouse on first render. */
  initial: Warehouse;
  /** Holds UpdateWarehouse's codes (from the contract): ST-4c. Without it the fields are plain text. */
  canEdit: boolean;
  /** Holds RestoreWarehouse's codes (from the contract): ST-4c. Without it an archived warehouse shows its banner, no button. */
  canRestore: boolean;
  /** Holds DeleteWarehouse's codes (from the contract): **Delete warehouse** on a live warehouse (§2 step 4.6). */
  canDelete?: boolean;
  /**
   * The Locations section (§2 step 4.4): its first read and its action gates, or `null` when the viewer lacks
   * ListStorageLocations' codes (A-20: absent and not fetched).
   */
  locations?: { initial: LocationsLoad; canEdit: boolean; canRestore: boolean; canCreate: boolean; canDelete?: boolean } | null;
}

type Cause = 'unreachable' | 'server-error';

/**
 * The warehouse page (warehouses-ux-copy.md B-3; IA L-4: the detail page is the editor). It holds the warehouse in
 * state and passes the re-read one to the form without a remount. A failed re-read → the region error (focus on its
 * heading), Retry re-reads; 403 → ST-4b; 404 → ST-5. An archived warehouse is read-only whatever the viewer holds (the
 * API refuses its update, W3), under the persistent ST-9b banner *"This warehouse was archived on {date}."* with **Restore warehouse** for a
 * `RestoreWarehouse` holder (§2 step 4.2, `components/records/RestoreActions`): a restore sets the re-read warehouse here, so the
 * page turns live without a remount. After a save's or restore's message is on screen the router cache is refreshed once,
 * so Back to the list shows the saved code and name, or the warehouse in the live list. Under the fields, the Locations
 * section (§2 step 4.4, `LocationsSection`) for a ListStorageLocations holder only; it is read-only while the warehouse is
 * archived and turns live with it. Its row-action messages are spoken in this page's region.
 */
export function WarehousePage({ initial, canEdit, canRestore, canDelete = false, locations = null }: WarehousePageProps) {
  const caller = useApiCaller();
  const router = useRouter();
  const timeZone = useViewerTimeZone();
  // FE-R8 location half (s3-fer8-final-red.md F-3): every write to this page's region goes through `announce`, which
  // counts it and returns the count. A late correction (`replaceIfCurrent`) is written only while nothing has written the
  // region since the write it corrects, by anyone: the Locations section, the warehouse form, Restore warehouse, Delete
  // warehouse, the arrival message. Decided by the count, never by the text: the same sentence can be written twice.
  const regionWrites = useRef(0);
  // fe-r8-location-red.md L-6: only the message and the raw writer are taken (no `region` object in scope), and the raw
  // writer is used only inside `announce` below.
  const { message: regionMessage, announce: writeRegion } = useLiveRegion();
  const announce = useCallback(
    (text: string) => {
      regionWrites.current += 1;
      writeRegion(text);
      return regionWrites.current;
    },
    [writeRegion],
  );
  const replaceIfCurrent = useCallback(
    (gen: number, text: string) => {
      if (regionWrites.current === gen) announce(text);
    },
    [announce],
  );
  const [warehouse, setCommitted] = useCommittedState(initial);
  const [swap, setSwap] = useState<'forbidden' | 'not-found' | null>(null);
  const [rereadError, setRereadError] = useState<{ gen: number; cause: Cause } | null>(null);
  const [retrying, setRetrying] = useState(false);
  const [deleting, setDeleting] = useState(false);
  // RED M-1: a 204 here is a fact that outranks a stale snapshot. If the re-read after it failed, the warehouse is still
  // treated as archived for the actions (no Delete warehouse, a read-only section) until a read says otherwise.
  const [deletedHere, setDeletedHere] = useState(false);
  // A commit that is not dropped is newer than that fact, so it clears it (a restore then turns it live). It is newer than
  // an earlier failed re-read too, so committing one also clears that region error (the items RED L-2, a shipped defect
  // here: "We couldn't load this warehouse again…" stayed over fresh data after a later successful write).
  // The stamp rule (items-i3-design.md Revision 1 — FINAL §0a): every commit is stamped with the moment its reading was
  // requested. A page-owned re-read (Retry, a dialog's Close) takes its stamp when its request is sent. A write (Save,
  // Restore, Delete) takes its stamp when it sends its re-read, after the write's answer has arrived. Its re-read commits
  // under that stamp. So does the write's own answer body, committed when that re-read fails. A commit or failure whose
  // stamp is older than what is on screen is dropped. A 204 raises what is on screen to the delete's re-read stamp, even
  // when that re-read fails, so no reading requested before it can undo the delete. A write's own answer body is also
  // dropped when a 204 arrived after that write was sent: it describes the warehouse before the delete. The 204 fact
  // (`deletedHere`) is cleared only by a commit that is not dropped. An older re-read landing first never drops a newer
  // one. A Save sent before a 204 of the person's own Delete also loses its "saved" sentence (the items page's UX M-3),
  // though its refresh stays, and its failed re-read's region error (swaps are kept); so does a Restore location sent
  // before it (its sentence, focus and notice; its view re-read stays). That `sentAsOf` is compared only with the 204
  // floor, never with the commit stamp: a Save's own re-read always commits above it. Known limit: when that re-read
  // fails, the write's answer body describes the moment of the write but carries the re-read's stamp, so it can
  // overwrite a Retry, sent in between, that read someone else's later change; the next reading corrects it. Known
  // limit: the 204 floor is never lowered, so after a Restore a Save or a Restore location sent before the delete is
  // still silenced (its sentence, body, failed re-read or notice); safe while such a write's 200 means it was processed
  // before the delete; a refused Restore location then leaves its row in the Archived view, unexplained.
  const stamps = useRef(0);
  const committedAsOf = useRef(0);
  const deletedAsOf = useRef(0);
  // The code last committed, for the "After" sentence (i3-c1 fix-RED L-4): the render that opened the dialog may be older
  // than a reading committed since (renamed elsewhere, or by a Save that landed while the delete was in flight).
  const committedCode = useRef(initial.code);
  // S-2 (items-i4-red.md L-4): whether the reading last committed is archived, for the delete's after-sentence; decided
  // from what was committed, never from the render the dialog was opened in. A 204 sets it too (the delete is a fact
  // even when its re-read fails), until a later commit says otherwise.
  const committedArchived = useRef(initial.archived_at !== null);
  const stamp = useCallback(() => ++stamps.current, []);
  const setWarehouse = useCallback(
    async (w: Warehouse, asOf: number, sentAsOf?: number) => {
      if (asOf < committedAsOf.current) return;
      if (sentAsOf !== undefined && sentAsOf < deletedAsOf.current) return;
      committedAsOf.current = asOf;
      committedCode.current = w.code;
      committedArchived.current = w.archived_at !== null;
      setDeletedHere(false);
      setRereadError(null);
      await setCommitted(w);
    },
    [setCommitted],
  );
  const h1 = useRef<HTMLHeadingElement>(null);
  const id = initial.id;
  // After a create (§2 step 4.3): the create page left *"{code} was created."* for this address (A-19 item 7, S-5).
  useArrivalMessage(`/warehouses/${id}`, announce);

  const failed = useCallback((f: ReadFailure, asOf?: number, sentAsOf?: number) => {
    if (asOf !== undefined && asOf < committedAsOf.current) return;
    if (f === 'session-ended') return;
    if (f === 'forbidden' || f === 'not-found') {
      setSwap(f);
      return;
    }
    // The item page's rule (po-6-red.md, spar on :384): a Save sent before a 204 of the person's own Delete does not put
    // its failed re-read over what the delete left; the delete's reading, sentence and focus stand. Compared only with
    // the 204 floor. Swaps are kept: they say what the viewer may see now.
    if (sentAsOf !== undefined && sentAsOf < deletedAsOf.current) return;
    // After the form's own after-save focus, so the region error's heading takes it.
    setTimeout(() => setRereadError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: f })), 0);
  }, []);

  // The wiring is built when an action runs, not during render: `setWarehouse` writes the stamp refs, which may only be
  // touched outside render (react-hooks/refs).
  const save = useCallback((changes: UpdateWarehouseInput) => warehouseSave({ caller, id, stamp, setWarehouse, onRereadFailed: failed })(changes), [caller, id, stamp, setWarehouse, failed]);
  const restore = useCallback(() => warehouseRestore({ caller, id, stamp, setWarehouse, onRereadFailed: failed })(), [caller, id, stamp, setWarehouse, failed]);
  // The 204 floor: raised before the delete's re-read is sent, so a reading requested earlier is dropped even when that
  // re-read fails.
  const onDeleted = useCallback((s: number) => {
    committedAsOf.current = Math.max(committedAsOf.current, s);
    deletedAsOf.current = s;
    committedArchived.current = true;
    setDeletedHere(true);
  }, []);
  const remove = useCallback(() => warehouseDelete({ caller, id, stamp, setWarehouse, onRereadFailed: failed, onDeleted })(), [caller, id, stamp, setWarehouse, failed, onDeleted]);
  const onSaved = useCallback(() => router.refresh(), [router]);
  // UX M-3, the items twin: a Save's sentence is not spoken over the person's own delete when the 204 came after it was sent.
  const announceSaved = useCallback(
    (text: string, sentAsOf: number) => {
      if (sentAsOf < deletedAsOf.current) return;
      announce(text);
    },
    [announce],
  );
  // The same rule for the Locations section's Restore location, which can still be out when Delete warehouse answers.
  const deletedAfter = useCallback((sentAsOf: number) => sentAsOf < deletedAsOf.current, []);

  // §2 step 4.6. After a 204 the page stays: the warehouse is archived, not gone, and `warehouseDelete` has already
  // committed the re-read warehouse: archived (the ST-9b banner and Restore warehouse), or live if someone restored it
  // in between (S-2). FE-D1: commit
  // the close, focus the h1 (the opener is gone with the live state), speak B-7's "After", refresh the router cache once
  // (Back to the list then shows it under Archived). Any other close after an answer re-reads the warehouse, so the page
  // under the dialog is the server's (an interrupted press may have committed; a W6 means it was deleted elsewhere).
  const closeDelete = async ({ deleted, reread }: { deleted: boolean; reread: boolean }) => {
    flushSync(() => setDeleting(false));
    if (deleted) {
      // No `setDeletedHere(true)` here: `onDeleted` already set it before the re-read, and only a commit that is not
      // dropped may clear it. A re-read that reads the warehouse live (restored in between) leaves the page live.
      h1.current?.focus();
      // S-2: a re-read that committed a live warehouse means it was restored in between.
      announce(committedArchived.current ? DELETE_WAREHOUSE.after(committedCode.current) : DELETE_WAREHOUSE.afterRestored(committedCode.current));
      router.refresh();
      return;
    }
    if (!reread) return;
    const mine = ++stamps.current;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadWarehouse(readOf(caller), id, d.signal);
    d.cancel();
    if (committedAsOf.current > mine) return;
    if (r.kind === 'ok') {
      await setWarehouse(r.warehouse, mine);
      // RED H-1: the re-read archived the page (W6 deleted elsewhere, or an interrupted press that committed), which
      // unmounts the Delete warehouse button the dialog had returned focus to: focus the h1, as the 204 path does.
      if (r.warehouse.archived_at !== null) {
        h1.current?.focus();
        router.refresh();
      }
    } else failed(r.kind, mine);
  };

  const retry = async () => {
    if (retrying) return;
    setRetrying(true);
    const mine = ++stamps.current;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadWarehouse(readOf(caller), id, d.signal);
    d.cancel();
    setRetrying(false);
    if (committedAsOf.current > mine) return;
    if (r.kind === 'ok') {
      await setWarehouse(r.warehouse, mine);
      setRereadError(null);
      h1.current?.focus();
    } else failed(r.kind, mine);
  };

  if (swap === 'forbidden') return <WarehousesDenied />;
  if (swap === 'not-found') return <WarehouseNotFound />;

  const archived = warehouse.archived_at !== null;
  const actionsArchived = archived || deletedHere;
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {WAREHOUSE_PAGE.title(warehouse.code, warehouse.name)}
        </h1>
        {canDelete && !actionsArchived ? (
          <Button variant="destructive" onClick={() => setDeleting(true)}>
            {DELETE_WAREHOUSE.action}
          </Button>
        ) : null}
      </header>
      {/* Mounted from first render, above every branch below, so it never arrives already speaking. */}
      <LiveRegion message={regionMessage} visuallyHidden />
      {rereadError ? (
        <RegionError key={rereadError.gen} heading={rereadError.cause === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={WAREHOUSE_PAGE.rereadFailed} retrying={retrying} onRetry={() => void retry()} />
      ) : null}
      {warehouse.archived_at !== null ? (
        <RestoreActions
          archivedAt={warehouse.archived_at}
          copy={{
            archivedBefore: WAREHOUSE_PAGE.archivedOn,
            archivedAfter: WAREHOUSE_PAGE.archivedAfter,
            action: RESTORE_WAREHOUSE.action,
            after: (r) => RESTORE_WAREHOUSE.after(r.warehouse.code),
            interrupted: RESTORE_WAREHOUSE.interrupted(warehouse.code),
            forbidden: WAREHOUSE_FORBIDDEN,
          }}
          canRestore={canRestore}
          restore={restore}
          restoreCode={gateCode('RestoreWarehouse')}
          timeZone={timeZone}
          announce={announce}
          fallbackFocusRef={h1}
          onRestored={onSaved}
        />
      ) : null}
      {canEdit && !actionsArchived ? (
        <WarehouseForm warehouse={warehouse} save={save} editCode={gateCode('UpdateWarehouse')} announce={announceSaved} fallbackFocusRef={h1} onSaved={onSaved} onSaveStarted={() => announce('')} />
      ) : (
        <dl className={styles.fields}>
          <dt>{WAREHOUSE_PAGE.code}</dt>
          <dd>{warehouse.code}</dd>
          <dt>{WAREHOUSE_PAGE.name}</dt>
          <dd>{warehouse.name}</dd>
          <dt>{WAREHOUSE_PAGE.country}</dt>
          <dd>{warehouse.country_code}</dd>
        </dl>
      )}
      {locations !== null ? (
        <div className={styles.locations}>
          <LocationsSection warehouseId={id} warehouseCode={warehouse.code} initial={locations.initial} warehouseArchived={actionsArchived} canEdit={locations.canEdit} canRestore={locations.canRestore} canCreate={locations.canCreate} canDelete={locations.canDelete ?? false} announce={announce} replaceIfCurrent={replaceIfCurrent} stamp={stamp} deletedAfter={deletedAfter} />
        </div>
      ) : null}
      {deleting ? (
        <DeleteDialog
          copy={{ title: DELETE_WAREHOUSE.title(warehouse.code, warehouse.name), body: DELETE_WAREHOUSE.body, confirm: DELETE_WAREHOUSE.confirm, cancel: DELETE_WAREHOUSE.cancel, close: DELETE_WAREHOUSE.close, interrupted: DELETE_WAREHOUSE.interrupted(warehouse.code), forbidden: WAREHOUSE_FORBIDDEN }}
          run={remove}
          onDeleteStarted={() => announce('')}
          deleteCode={gateCode('DeleteWarehouse')}
          onClose={(o) => void closeDelete(o)}
          fallbackFocusRef={h1}
        />
      ) : null}
    </section>
  );
}
